<?php

/**
 * @file
 * filedepot.class.php
 * Main class for the Filedepot module
 */



class filedepot {

  protected static $_instance;
  public $root_storage_path = '';
  public $tmp_storage_path = '';
  public $tmp_incoming_path = '';

  public $validReportingModes = array(
  'latestfiles',
  'notifications',
  'lockedfiles',
  'downloads',
  'flaggedfiles',
  'unread',
  'myfiles',
  'approvals',
  'incoming',
  'searchtags',
  'search');

  public $iconmap = array(
  'default' => 'none.gif',
  'favorite-on' => 'staron-16x16.gif',
  'favorite-off' => 'staroff-16x16.gif',
  'locked' => 'padlock.gif',
  'download' => 'download.png',
  'editfile' => 'editfile.png',
  'upload' => 'upload.png'
  );

  public $defOwnerRights = array();
  public $defRoleRights = array();

  public $maxDefaultRecords = 30;
  public $listingpadding = 20;   // Number of px to indent filelisting per folder level
  public $filedescriptionOffset = 50;
  public $shortdate = '%x';
  public $activeview = '';    // Active filtered view
  public $cid = 0;            // Active folder
  public $selectedTopLevelFolder = 0;
  public $recordCountPass1 = 2;
  public $recordCountPass2 = 10;
  public $folder_filenumoffset = 0;
  public $lastRenderedFiles = array();
  public $lastRenderedFolder = 0;
  public $allowableViewFolders = '';
  public $allowableViewFoldersSql = '';
  public $ajaxBackgroundMode = FALSE;
  private $upload_prefix_character_count = 18;
  private $download_chunk_rate =   8192;  //set to 8k download chunks
  public $ogenabled = FALSE;
  public $paddingsize = 5;  // Number of pixels to indent each folder level

  public $notificationTypes = array(
  1   => 'New File Added',
  2   => 'New File Approved',
  3   => 'New File Declined',
  4   => 'File Changed',
  5   => 'Broadcast'
  );



  protected function __construct() {  # Singleton Pattern: we don't permit an explicit call of the constructor!
    global $user;

    $this->tmp_storage_path  =  drupal_realpath('public://') . '/filedepot/';
    $this->tmp_incoming_path  = drupal_realpath('public://') . '/filedepot/incoming/';
    $this->root_storage_path = variable_get('filedepot_storage_path', str_replace('\\', '/', getcwd()) . '/filedepot_private/');

    $this->root_storage_path = 'private://filedepot/';

    /* @TODO: Need to add logic that will only be executed once to test
     * that the private filesystem has been setup and the filedepot folders
     * for the repository have been created - we can get the $private path.
    */
    $private = variable_get('file_private_path','');

    $this->recordCountPass1 = variable_get('filedepot_pass1_recordcount', 2);
    $this->recordCountPass2 = variable_get('filedepot_pass2_recordcount', 10);

    $iconsettings = unserialize(variable_get('filedepot_extension_data', ''));
    if (!empty($iconsettings)) {
      $this->iconmap = array_merge($this->iconmap, $iconsettings);
    }
    $defOwnerRights = variable_get('filedepot_extension_data', '');
    if (!empty($defOwnerRights)) {
      $this->defOwnerRights = unserialize($defOwnerRights);
    }
    else {
      $defOwnerRights = array('view');
    }
    $permsdata = variable_get('filedepot_default_perms_data', '');
    if (!empty($permsdata)) {
      $permsdata = unserialize($permsdata);
    }
    else {
      $permsdata = array('authenticated user' => array('view', 'upload'));
    }
    if (isset($permsdata['owner']) AND count($permsdata['owner'] > 0)) {
      $this->defOwnerRights = $permsdata['owner'];
    }
    else {
      $this->defOwnerRights = array('view', 'admin');
    }
    if (isset($permsdata['owner'])) {
      unset($permsdata['owner']); // It has now been assigned to defOwnerRights variable
    }

    $this->defRoleRights = $permsdata;

    if (module_exists('og') AND module_exists('og_access')) {
      $this->ogenabled = TRUE;
    }

    if (user_is_logged_in()) {

      // This cached setting will really only benefit when there are many thousand access records like portal23
      // User setting (all users) is cleared each time a folder permission is updated.
      // But this library is also included for all AJAX requests

      $data = db_query("SELECT allowable_view_folders FROM {filedepot_usersettings} WHERE uid=:uid", array('uid' => $user->uid))->fetchField();
      if (empty($data)) {
        $this->allowableViewFolders = $this->getAllowableCategories('view', FALSE);
        $data = serialize($this->allowableViewFolders);
        if (db_query("SELECT count(uid) FROM {filedepot_usersettings} WHERE uid=:uid", array('uid'=>$user->uid))->fetchField() == 0) {
          /* Has a problem handling serialized data - we couldn't unserialize the data afterwards.
          * The problem is the pre-constructed SQL statement. When we use the function "udate_sql($sql)",
          * we construct the SQL statement without using any argument. A serialized data normally contains curly brackets.
          * When you call update_sql($sql), it then hands your pre-constructed $sql to the function db_query($sql).
          * Inside the function db_query(), it replace the curly bracket with table prefix blindly,
          * even the curly bracket inside data string are converted.
          * And thus you will not be able to unserialize the data from the table anymore.
          * To get around this, instead of calling update_sql, call db_query($sql, $args).
          * Put all the variables to be inserted into the table into the argument list.
          * This way db_query will only convert the curly bracket surrounding the table name.
          */
          db_query("INSERT INTO {filedepot_usersettings} (uid, allowable_view_folders, notify_newfile, notify_changedfile, allow_broadcasts) VALUES (:uid, :view, :newfile, :changed, :broadcasts)", array(
            'uid' => $user->uid,
            'view' => $data,
            ':newfile' => variable_get('filedepot_default_notify_newfile', 0),
            ':changed' => variable_get('filedepot_default_notify_filechange', 0),
            ':broadcasts' => variable_get('filedepot_default_allow_broadcasts', 0),
            ));
        }
        else {
          db_query("UPDATE {filedepot_usersettings} set allowable_view_folders=:view WHERE uid=:uid", array(
            'view' => $data,
            'uid' => $user->uid));
        }
      }
      else {
        $this->allowableViewFolders = unserialize($data);
      }
      $this->allowableViewFoldersSql = implode(',', $this->allowableViewFolders);  // Format to use for SQL statement - test for allowable categories

    }
    else {
      $this->allowableViewFolders = $this->getAllowableCategories('view', FALSE);
      $this->allowableViewFoldersSql = implode(',', $this->allowableViewFolders);  // Format to use for SQL statement - test for allowable categories
    }

  }

  protected function __clone() {      # we don't permit cloning the singleton
  }

  public static function getInstance() {
    if ( self::$_instance === NULL) {
      self::$_instance = new self();
    }
    return self::$_instance;
  }

  /* Function to check if passed in file extension and mimetype are in the allowed list
  * @param        string          $ext            File extension to test
  * @param        string          $mimetype       Mimetype to test if allowed for extension
  * @return       Boolean                         Returns TRUE or FALSE and depends on the filter mode setting
  */
  function checkFilter($filename,$mimetype) {
    $ext = end(explode(".", $filename));
    $filterdata = unserialize(variable_get('filedepot_filetype_filterdata', ''));
    if (is_array($filterdata) AND !empty($filterdata)) {
      if (array_key_exists($mimetype, $filterdata) AND is_array($filterdata[$mimetype])) {
        if (in_array($ext, $filterdata[$mimetype])) {
          // Match found - Mimetype and extension match defined settings
          if (variable_get('filedepot_filter_mode',FILEDEPOT_FILTER_INCLUDEMODE) == FILEDEPOT_FILTER_INCLUDEMODE) {
            return TRUE;
          }
          else {
            RETURN FALSE;
          }
        }
      }
    }
    // If we get here, no match found. Return depends on the filtering mode
    if (variable_get('filedepot_filter_mode',FILEDEPOT_FILTER_EXCLUDEMODE) == FILEDEPOT_FILTER_EXCLUDEMODE) {
      return TRUE;
    }
    else {
      return FALSE;
    }

  }

  /* Function to check if user has a particular right or permission to a folder
  *  Checks the access table for the user and any groups they belong to
  *  Takes either a single Right or an array of Rights
  *
  * @param        string          $cid            Category to check user access for
  * @param        string|array    $rights         Rights to check (admin,view,upload,upload_dir,upload_ver,approval)
  * @param        Boolean         $adminOverRide  Set to FALSE, to ignore if user is in the Admin group and check for absolute perms
  * @return       Boolean                         Returns TRUE if user has one of the requested access rights else FALSE
  */
  function checkPermission($cid, $rights, $userid=0, $adminOverRide=TRUE) {
    global $user;

    if (intval($cid) < 1) {
      return FALSE;
    }

    // If user is an admin - they should have access to all rights on all categories
    if ($userid == 0) {
      if(empty($user->uid) OR $user->uid == 0 ) {
        $uid = 0;
      }
      else {
        $uid = $user->uid;
      }
    }
    else {
      $uid = $userid;
    }
    if ($adminOverRide AND user_access('administer filedepot', $user)) {
      return TRUE;
    }
    else {
      // Check user access records
      $sql = "SELECT view,upload,upload_direct,upload_ver,approval,admin from {filedepot_access} WHERE catid=:cid AND permtype='user' AND permid=:uid";
      $query = db_query($sql, array('cid' => $cid, 'uid' => $uid));
      while ($rec = $query->fetchAssoc()) {
        list ($view, $upload, $upload_dir, $upload_ver, $approval, $admin) = array_values($rec);
        if (is_array($rights)) {
          foreach ($rights as $key) {      // Field name above needs to match access right name
            if ($$key == 1)  {
              return TRUE;
            }
          }
        }
        elseif ($$rights == 1) {
          return TRUE;
        }
      }

      if ($this->ogenabled) {
        // Retrieve all the Organic Groups this user is a member of
        $sql = "SELECT node.nid AS nid FROM {node} node LEFT JOIN {og_uid} og_uid ON node.nid = og_uid.nid "
             . "INNER JOIN {users} users ON node.uid = users.uid "
             . "WHERE (node.status <> 0) AND (og_uid.uid = :uid) ";
        $groupquery = db_query($sql, array('uid' => $uid));
        while ($grouprec = $groupquery->fetchAssoc()) {
          $sql = "SELECT view,upload,upload_direct,upload_ver,approval,admin from {filedepot_access} WHERE catid=:cid AND permtype='group' AND permid=:uid";
          $query = db_query($sql, array('cid' => $cid, 'uid' => $grouprec['nid']));
          while ($rec = $query->fetchAssoc()) {
            list ($view, $upload, $upload_dir, $upload_ver, $approval, $admin) = array_values($rec);
            if (is_array($rights)) {
              foreach ($rights as $key) {      // Field name above needs to match access right name
                if ($$key == 1)  {
                  return TRUE;
                }
              }
            }
            elseif ($$rights == 1) {
              return TRUE;
            }
          }
        }
      }


      // For each role that the user is a member of - check if they have the right
      foreach ($user->roles as $rid => $role) {
        $sql = "SELECT view,upload,upload_direct,upload_ver,approval,admin from {filedepot_access} WHERE catid=:cid AND permtype='role' AND permid=:uid";
        $query = db_query($sql, array('cid' => $cid, 'uid' => $rid));
        while ($rec = $query->fetchAssoc()) {
          list ($view, $upload, $upload_dir, $upload_ver, $approval, $admin) = array_values($rec);
          if (is_array($rights)) {
            // If any of the required permissions set - return TRUE
            foreach ($rights as $key) {
              if ($$key == 1)  {      // Field name above needs to match access right name
                return TRUE;
              }
            }
          }
          elseif ($$rights == 1) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
  * Return list of repository categories user has permission to access to be used in SQL statements
  *
  * @param   mixed   array or string    - permission(s) you want to test for
  * @param   boolean return format      - if FALSE, return an array
  * @return  mixed - comma separated list of categories, or array
  */
  function getAllowableCategories ($perm='view', $returnstring=TRUE) {
    global $user;

    $categories = array();
    $sql = "SELECT distinct catid FROM {filedepot_access} ";
    $query = db_query($sql);
    while ($A = $query->fetchAssoc()) {
      if ($this->checkPermission($A['catid'], $perm, $user->uid)) {
        $categories[] = $A['catid'];
      }
    }
    if ($returnstring AND count($categories) > 0) {
      $retval = implode(',', $categories);
    }
    else {
      $retval = $categories;
    }

    return $retval;
  }


  /* Function to return and array of subcategories - used to generate a list of folders
   * under the OG Root Group assigned folder.
   *
   *  Recursive function calls itself building the list
   *
   * @param        array      $list        Array of categories
   * @param        string     $cid         Category to lookup
   * @param        string     $perms       Permissions to check if user has access to catgegory
   * @param        boolean    $override    Set to TRUE only if you don't want to test for permissions
   */
  public function getRecursiveCatIDs(&$list, $cid, $perms, $override=false) {
    $filedepot = filedepot_filedepot();
    $query = db_query("SELECT cid FROM {filedepot_categories} WHERE PID=:cid ORDER BY cid", array('cid' => $cid));
    while ( $A = $query->fetchAssoc()) {
      // Check and see if this category has any sub categories - where a category record has this cid as it's parent
      if (db_query("SELECT count(pid) FROM {filedepot_categories} WHERE pid=:cid", array('cid'=>$A['cid']))->fetchField() > 0) {
        if ($override === TRUE OR $this->checkPermission($A['cid'], $perms)) {
          array_push($list, $A['cid']);
          $this->getRecursiveCatIDs($list, $A['cid'], $perms, $override);
        }
      }
      else {
        if ($override === TRUE OR $this->checkPermission($A['cid'], $perms)) {
          array_push($list, $A['cid']);
        }
      }
    }
    return $list;
  }


  public function updatePerms($id, $accessrights, $users='', $groups='', $roles='') {

    if ($users != ''  AND !is_array($users))  $users  = array($users);
    if ($groups != '' AND !is_array($groups)) $groups = array($groups);
    if ($roles != ''  AND !is_array($roles))  $roles  = array($roles);

    if (!empty($accessrights)) {
      if (in_array('view', $accessrights)) {
        $view = 1;
      }
      else {
        $view = 0;
      }
      if (in_array('upload', $accessrights)) {
        $upload = 1;
      }
      else {
        $upload = 0;
      }
      if (in_array('approval', $accessrights)) {
        $approval = 1;
      }
      else {
        $approval = 0;
      }
      if (in_array('upload_dir', $accessrights)) {
        $direct = 1;
      }
      else {
        $direct = 0;
      }
      if (in_array('admin', $accessrights)) {
        $admin = 1;
      }
      else {
        $admin = 0;
      }
      if (in_array('upload_ver', $accessrights)) {
        $versions = 1;
      }
      else {
        $versions = 0;
      }

      if (!empty($users)) {
        foreach($users as $uid ) {
          $uid = intval($uid);
          $query = db_query("SELECT accid FROM {filedepot_access} WHERE catid=:cid AND permtype='user' AND permid=:uid", array(
            'cid' => $id,
            'uid' => $uid));
          if ($query->fetchField() === FALSE) {
            $sql = "INSERT INTO {filedepot_access} "
            . "(catid,permid,permtype,view,upload,upload_direct,upload_ver,approval,admin) "
            . "VALUES (:id,:uid,'user',:view,:upload,:direct,:versions,:approval,:admin)";
            db_query($sql, array(
              'id' => $id,
              'uid' => $uid,
              'view' => $view,
              'upload' => $upload,
              'direct' => $direct,
              'versions' => $versions,
              'approval' => $approval,
              'admin' => $admin));
          }
          else {
            $sql = "UPDATE {filedepot_access} SET view=:view, upload=:upload, "
            . "upload_direct=:direct, upload_ver=:versions, approval=:approval, "
            . "admin=:admin WHERE catid=:cid AND permtype='user' AND permid=:uid";
            db_query($sql, array(
              'view' => $view,
              'upload' => $upload,
              'direct' => $direct,
              'versions' => $versions,
              'approval' => $approval,
              'admin' => $admin,
              'id' => $id,
              'uid' => $uid));
          }
        }
      }

      if (!empty($groups)) {
        foreach($groups as $gid ) {
          $gid = intval($gid);
          $query = db_query("SELECT accid FROM {filedepot_access} WHERE catid=:cid AND permtype='group' AND permid=:uid", array(
            'id' => $id,
            'uid' => $gid));
          if ($query->fetchField() === FALSE) {
            $sql = "INSERT INTO {filedepot_access} "
            . "(catid,permid,permtype,view,upload,upload_direct,upload_ver,approval,admin) "
            . "VALUES (:id,:uid,'group',:view,:upload,:direct,:versions,:approval,:admin)";
            db_query($sql, array(
              'id' => $id,
              'uid' => $uid,
              'view' => $view,
              'upload' => $upload,
              'direct' => $direct,
              'versions' => $versions,
              'approval' => $approval,
              'admin' => $admin));
          }
          else {
            $sql = "UPDATE {filedepot_access} SET view=:view, upload=:upload, "
            . "upload_direct=:direct, upload_ver=:version, approval=:approval, "
            . "admin=:admin WHERE catid=:cid AND permtype='group' AND permid=:uid";
            db_query($sql, array(
              'view' => $view,
              'upload' => $upload,
              'direct' => $direct,
              'version' => $versions,
              'approval' => $approval,
              'admin' => $admin,
              'cid' => $id,
              'uid' => $gid));
          }
        }
      }

      if (!empty($roles)) {
        foreach($roles as $rid ) {
          $rid = intval($rid);
          $query = db_query("SELECT accid FROM {filedepot_access} WHERE catid=:cid AND permtype='role' AND permid=:uid", array(
            'cid' => $id,
            'uid' => $rid));
          if ($query->fetchField() === FALSE) {
            $sql = "INSERT INTO {filedepot_access} "
            . "(catid,permid,permtype,view,upload,upload_direct,upload_ver,approval,admin) "
            . "VALUES (:id,:uid,'role',:view,:upload,:direct,:version,:approval,:admin)";
            db_query($sql, array(
              'id' => $id,
              'uid' => $rid,
              'view' => $view,
              'upload' => $upload,
              'direct' => $direct,
              'version' => $versions,
              'approval' => $approval,
              'admin' => $admin));
          }
          else {
            $sql = "UPDATE {filedepot_access} SET view=:view, upload=:upload, "
            . "upload_direct=:direct, upload_ver=:version, approval=:approval, "
            . "admin=:admin WHERE catid=:cid AND permtype='role' AND permid=:uid";
            db_query($sql, array(
              'view' => $view,
              'upload' => $upload,
              'direct' => $direct,
              'version' => $versions,
              'approval' => $approval,
              'admin' => $admin,
              'cid' => $id,
              'uid' => $rid));
          }
        }
      }

      /* May need to review this - and clear only those users that have been updated later.
      But determining the users in updated groups and sorting out duplicates from the individual user perms
      and only updating them may take more processing then simply clearing all.
      The users setting will be updated the next time they use the application - public/filedepot/library.php
      Distributing the load to update the cached setting.
      This cached setting will really only benefit when there are many thousand access records like portal23
      */
      db_query("UPDATE {filedepot_usersettings} set allowable_view_folders = ''");

      return TRUE;

    }
    else {
      return FALSE;
    }

  }

  public function createFolder($node) {
    global $user;

    if ($node->parentfolder == 0 AND !user_access('administer filedepot')) {
      return FALSE;
    }

    if ($node->parentfolder > 0 AND $this->checkPermission($node->parentfolder,'admin') === FALSE) {
      return FALSE;
    }

    //if (variable_get('filedepot_content_type_initialized', FALSE) === FALSE) {
    //  require_once './' . drupal_get_path('module', 'filedepot') .  '/setup_content_type.inc';
    //  filedepot_setup_folder_content_type();
    //  variable_set('filedepot_content_type_initialized',TRUE);
    //} else {
    //  watchdog('filedepot','Content type initialized');
    //}

    if (@is_dir($this->tmp_storage_path) === FALSE) {
      @mkdir($this->tmp_storage_path, FILEDEPOT_CHMOD_DIRS);
    }

    if (@is_dir($this->tmp_incoming_path) === FALSE) {
      @mkdir($this->tmp_incoming_path, FILEDEPOT_CHMOD_DIRS);
    }

    db_query("UPDATE {node} set promote = 0 WHERE nid = :nid", array(
      'nid' => $node->nid));

    $query = db_query("SELECT max(folderorder) FROM {filedepot_categories} WHERE pid=:pid", array('pid'=>$node->parentfolder));
    $maxorder = $query->fetchField() + 10;

    db_query("INSERT INTO {filedepot_categories} (pid,name,description,folderorder,nid,vid) VALUES (:pfolder,:title,:folder,:maxorder,:nid,:vid)",
      array(
        'pfolder' => $node->parentfolder,
        'title' => $node->title,
        'folder' => $node->filedepot_folder_desc[LANGUAGE_NONE][0]['value'],
        'maxorder' => $maxorder,
        'nid' => $node->nid,
        'vid' => $node->vid));

    // Need to clear the cached user folder permissions
    db_query("UPDATE {filedepot_usersettings} set allowable_view_folders = ''");

    // Retrieve the folder id (category id) for the new folder
    $cid = db_query("SELECT cid FROM {filedepot_categories} WHERE nid=:nid",
      array('nid' => $node->nid))->fetchField();
    if ($cid > 0 AND $this->createStorageFolder($cid)) {
      $this->cid = $cid;
      $catpid = db_query("SELECT pid FROM {filedepot_categories} WHERE cid=:cid",
        array('cid' => $cid))->fetchField();
      if (isset($node->inherit) AND $node->inherit == 1 AND $catpid > 0) {
        // Retrieve parent User access records - for each record create a new one for this category
        $sql = "SELECT permid,view,upload,upload_direct,upload_ver,approval,admin FROM {filedepot_access} "
        . "WHERE permtype='user' AND permid > 0 AND catid=:cid";
        $q1 = db_query($sql, array('cid' => $catpid));
        foreach($q1 as $rec) {
          $sql = "INSERT INTO {filedepot_access} "
          . "(catid,permtype,permid,view,upload,upload_direct,upload_ver,approval,admin) VALUES "
          . "(:cid,'user',:uid,:view,:upload,:direct,:version,:approval,:admin)";
          db_query($sql, array(
            'cid' => $cid,
            'uid' => $rec->permid,
            'view' => $rec->view,
            'upload' => $rec->upload,
            'direct' => $rec->upload_direct,
            'version' => $rec->upload_ver,
            'approval' => $rec->approval,
            'admin' => $rec->admin));
        }
        // Retrieve parent Role Access records - for each record create a new one for this category
        $sql = "SELECT permid,view,upload,upload_direct,upload_ver,approval,admin "
        . "FROM {filedepot_access} WHERE permtype='role' AND permid > 0 AND catid=:cid";
        $q2 = db_query($sql, array('cid' => $catpid));
        foreach($q2 as $rec) {
          $sql = "INSERT INTO {filedepot_access} "
          . "(catid,permtype,permid,view,upload,upload_direct,upload_ver,approval,admin) VALUES "
          . "(:cid,'role',:uid,:view,:upload,:direct,:version,:approval,:admin)";
          db_query($sql, array(
            'cid' => $cid,
            'uid' => $rec->permid,
            'view' => $rec->view,
            'upload' => $rec->upload,
            'direct' => $rec->upload_direct,
            'version' => $rec->upload_ver,
            'approval' => $rec->approval,
            'admin' => $rec->admin));
        }

        // Retrieve parent Group Access records - for each record create a new one for this category
        $sql = "SELECT permid,view,upload,upload_direct,upload_ver,approval,admin "
        . "FROM {filedepot_access} WHERE permtype='group' AND permid > 0 AND catid=:cid";
        $q3 = db_query($sql, array('cid' => $catpid));
        foreach($q3 as $rec) {
          $sql = "INSERT INTO {filedepot_access} "
          . "(catid,permtype,permid,view,upload,upload_direct,upload_ver,approval,admin) VALUES "
          . "(:cid,'groupd',:uid,:view,:upload,:direct,:version,:approval,:admin)";
          db_query($sql,
            array(
            'cid' => $cid,
            'uid' => $rec->permid,
            'view' => $rec->view,
            'upload' => $rec->upload,
            'direct' => $rec->upload_direct,
            'version' => $rec->upload_ver,
            'approval' => $rec->approval,
            'admin' => $rec->admin));
        }


      }
      else {
        // Create default permissions record for the user that created the category
        $this->updatePerms($cid, $this->defOwnerRights, $user->uid);
        if (is_array($this->defRoleRights) AND count($this->defRoleRights) > 0) {
          foreach ($this->defRoleRights as $role => $perms) {
            $rid = db_query("SELECT rid FROM {role} WHERE name=:role",
              array('role' => $role))->fetchField();
            if ($rid and $rid > 0) {
              $this->updatePerms($cid, $perms,'','',array($rid));
            }
          }
        }
      }
      return TRUE;
    }
    else {
      return FALSE;
    }
  }


   public function createStorageFolder($cid) {
    if (@is_dir($this->root_storage_path) === FALSE) {
      watchdog('filedepot',"Storage Directory does not exist ({$this->root_storage_path}), attempting to create now");
      $res = @mkdir($this->root_storage_path,FILEDEPOT_CHMOD_DIRS);
      if ($res === FALSE) {
        watchdog('fildepot',"Failed - check the folder path is correct and valid");
      }
      else {
        watchdog('filedepot',"Success, Root Storage director created");
      }
    }
    $path = $this->root_storage_path . $cid;
    if (@is_dir($path)) {
      @chmod($path, FILEDEPOT_CHMOD_DIRS);
      if ($fh = fopen($path . '/.htaccess', 'w')) {
        fwrite($fh, "deny from all\n");
        fclose($fh);
      }
      if ($fh = fopen("$path/submissions" . '/.htaccess', 'w')) {
        fwrite($fh, "deny from all\n");
        fclose($fh);
      }
      return TRUE;
    }
    else {
      $oldumask = umask(0);
      $res1 = @mkdir($path, FILEDEPOT_CHMOD_DIRS);
      $res2 = @mkdir("{$path}/submissions", FILEDEPOT_CHMOD_DIRS);
      umask($oldumask);
      if ($res1 === FALSE OR $res2 === FALSE) {
        watchdog('fildepot',"Failed to create server directory $path or $path/submissions");
        RETURN FALSE;
      }
      else {
        if ($fh = fopen($path . '/.htaccess', 'w')) {
          fwrite($fh, "deny from all\n");
          fclose($fh);
        }
        if ($fh = fopen("$path/submissions" . '/.htaccess', 'w')) {
          fwrite($fh, "deny from all\n");
          fclose($fh);
        }
        return TRUE;
     }
    }
  }


  public function deleteFolder($filedepot_folder_id) {
    /* Test for valid folder and admin permission one more time
     * We are going to override the permission test in the function filedepot_getRecursiveCatIDs()
     * and return all subfolders in case hidden folders exist for this user.
     * If this user has admin permission for parent -- then they should be able to delete it
     * and any subfolders.
     */

    if ($filedepot_folder_id > 0 AND $this->checkPermission($filedepot_folder_id, 'admin')) {
      // Need to delete all files in the folder
      /* Build an array of all linked categories under this category the user has admin access to */
      $list = array();
      array_push($list, $filedepot_folder_id);

      // Passing in permission check over-ride as noted above to filedepot_getRecursiveCatIDs()
      $list = $this->getRecursiveCatIDs ($list, $filedepot_folder_id, 'admin',TRUE);
      foreach ($list as $cid) {
        //watchdog('filedepot', "deleteFolder - processing cid: $cid");

        // Drupal will remove the file attachments automatically when folder node is deleted even if file usage is > 1
        $query = db_query("SELECT drupal_fid FROM {filedepot_files} WHERE cid=:cid", array(':cid' => $cid));
        while ($A = $query->fetchAssoc())  {
          $file = file_load($A['drupal_fid']);
          file_usage_delete($file,'filedepot');
          //file_delete($file);
        }

        $subfolder_nid = db_query("SELECT nid FROM {filedepot_categories} WHERE cid=:cid",
          array(':cid' => $cid))->fetchField();
        db_query("DELETE FROM {filedepot_categories} WHERE cid=:cid", array('cid' => $cid));
        db_query("DELETE FROM {filedepot_access} WHERE catid=:cid", array('cid' => $cid));
        db_query("DELETE FROM {filedepot_recentfolders} WHERE cid=:cid", array('cid' => $cid));
        db_query("DELETE FROM {filedepot_notifications} WHERE cid=:cid", array('cid' => $cid));
        db_query("DELETE FROM {filedepot_filesubmissions} WHERE cid=:cid", array('cid' => $cid));

        // Call the drupal node delete now for the subfolder node
        //watchdog('filedepot',"Calling node_delete for node id: {$subfolder_nid}");
        node_delete($subfolder_nid);

        // Remove the physical directory
        $uri = $this->root_storage_path . $cid;
        if (file_exists($uri)) {
          $ret = @drupal_rmdir("{$uri}/.htaccess");
          $ret = @drupal_rmdir("{$uri}/submissions/.htaccess");
          $ret = @drupal_rmdir("{$uri}/submissions");
          $ret = @drupal_rmdir($uri);
        }

      }
      return TRUE;
    }
    else {
      return FALSE;
    }

  }

  public function getFileIcon($fname) {
    $ext = end(explode(".", $fname));
    if (array_key_exists($ext, $this->iconmap)) {
      $icon = $this->iconmap[$ext];
    }
    else {
      $icon = $this->iconmap['default'];
    }
    return $icon;
  }



  /* Delete the file and any versions */
  public function deleteFile($fid) {
    $query = db_query("SELECT cid,drupal_fid FROM {filedepot_files} WHERE fid=:fid", array(':fid' => $fid));
    list ($cid,$dfid) = array_values($query->fetchAssoc());
    if ($this->checkPermission($cid, 'admin')) {
      $file = file_load($dfid);

      // Drupal is not updating the node when the file (attachment) is deleted
      // Need to cycle thru the attachments and remove it then save the node
      $nid = db_query("SELECT nid FROM {filedepot_categories} WHERE cid=:cid",
        array('cid' => $cid))->fetchField();
      $foldernode = node_load($nid);
      foreach ($foldernode->filedepot_folder_file[LANGUAGE_NONE] as $delta => $attachment) {
        if ($attachment['fid'] == $dfid) {
          unset($foldernode->filedepot_folder_file[LANGUAGE_NONE][$delta]);
          node_save($foldernode);
          break;
        }
      }
      file_usage_delete($file,'filedepot');
      file_delete($file);
      return TRUE;
    } else {
      return FALSE;
    }
  }

  public function deleteSubmission($id) {
    $query = db_query("SELECT cid,drupal_fid,tempname,fname,notify FROM {filedepot_filesubmissions} WHERE id=:id",
      array('id' => $id));
    list ($cid, $drupal_fid, $tempname, $fname, $notify) = array_values($query->fetchAssoc());
    if (!empty($tempname) AND file_exists("{$this->root_storage_path}{$cid}/submissions/$tempname")) {
      @unlink("{$this->root_storage_path}{$cid}/submissions/$tempname");

      // Send out notification of submission being deleted to user - before we delete the record as it's needed to create notification message
      if ($notify == 1) filedepot_sendNotification($id, FILEDEPOT_NOTIFY_REJECT);

      db_query("DELETE FROM {filedepot_filesubmissions} WHERE id=:id",
        array('id' => $id));
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  public function moveFile($fid, $newcid) {
    global $user;

    $filemoved = FALSE;
    if ($newcid > 0) {
      $query = db_query("SELECT fname,cid,drupal_fid,version,submitter FROM {filedepot_files} WHERE fid=:fid", array('fid' => $fid));
      list ($fname, $orginalCid, $dfid, $curVersion, $submitter) = array_values($query->fetchAssoc());
      if ($submitter == $user->uid OR $this->checkPermission($newcid, 'admin')) {
        if ($newcid !== intval($orginalCid)) {
          // Check if there is more then 1 reference to this file in this category

          /*
          if (db_query("SELECT fid from {filedepot_files} WHERE cid=:cid AND fname=:fname", array(
            'cid' => $originalCid,
            'fname' => $fname))->fetchField() > 1) {
            watchdog('filedepot', 'Checking for duplicate file - @folder, @name > Yes',
            array('@folder' => $orginalCid, '@name' => $fname));
            $dupfile_inuse = TRUE;
          }
          else {
            watchdog('filedepot', 'Checking for duplicate file - @folder, @name > No',
            array('@folder' => $orginalCid, '@name' => $fname));
            $dupfile_inuse = FALSE;
          }
          */

          /* Need to move the file */
          $query2 = db_query("SELECT fname FROM {filedepot_fileversions} WHERE fid=:fid", array('fid' => $fid));
          while ($A = $query2->fetchAssoc()) {
            $fname = stripslashes($A['fname']);
            $sourcefile = $this->root_storage_path . "{$orginalCid}/{$fname}";

            $private_destination = "private://filedepot/{$newcid}/";

            // Best to call file_prepare_directory() - even if you believe directory exists
            file_prepare_directory($private_destination, FILE_CREATE_DIRECTORY);

            $file = file_load($dfid);
            $private_uri = $private_destination . $file->filename;
            $file = file_move($file, $private_uri, FILE_EXISTS_RENAME);
            $file->display = 1;
            list($scheme, $target) = explode('://', $file->uri, 2);
            $moved_filename = str_replace("filedepot/{$newcid}/",'',$target);
            if ($moved_filename != $fname) {
               db_update('filedepot_fileversions')
                          ->fields(array('fname' => $moved_filename))
                          ->condition('fid', $fid)
                          ->execute();
           }

            // Check the new file name as it will be renamed if a duplicate exists in this directory

            // Update folder node - add the file as an attachment
            /* @TODO D7: Going to have to revisit this when file has versions.
             * Multiple versions do not appear in the native interface
             * I only want to attach the most recent version to the native folderr.
            */

            $source_folder_nid = db_query("SELECT nid FROM {filedepot_categories} WHERE cid=:cid",  array( ':cid' => $orginalCid))->fetchField();
            $node = node_load($source_folder_nid);
            // Remove the moved file now from the source folder
            foreach ($node->filedepot_folder_file[LANGUAGE_NONE] as $delta => $attachment) {
              if ($attachment['fid'] == $file->fid) {
                unset($node->filedepot_folder_file[LANGUAGE_NONE][$delta]);
                node_save($node);
                break;
              }
            }

            // Add the moved file to the target folder
            // Doing node_save changes the file status to permanent in the file_managed table
            $target_folder_nid = db_query("SELECT nid FROM {filedepot_categories} WHERE cid=:cid",  array( ':cid' => $newcid))->fetchField();
            $node = node_load($target_folder_nid);
            $node->filedepot_folder_file[LANGUAGE_NONE][] = (array)$file;//the name of the field that requires the files
            node_save($node);

            // Need to clear the cache as the node will still have the original file name
            field_cache_clear();
            db_update('filedepot_files')
                        ->fields(array('cid' => $newcid))
                        ->condition('fid', $fid)
                        ->execute();
          }
          $filemoved = TRUE;
        }
      }
      else {
        watchdog('filedepot', 'User (@user) does not have access to move file(@fid): @name to category: @newcid',
        array('@user' => $user->name, '@fid' => $fid, '@name' => $fname, '@newcid' => $newcid));
      }
    }
    return $filemoved;
  }


  // D7 version so far not using this function as we are not using ajax now for this
  // Using ctools modal dialog and form submit handler functions
  public function xsaveFile( $file, $validators = array() ) {
    global $user;
    $nexcloud =  filedepot_nexcloud();

    // Check for allowable file type.
    if (!$this->checkFilter($file->name, $file->type)) {
      $message = t('The file %name could not be uploaded. Mimetype %mimetype or extension not permitted.', array('%name' => $file->name, '%mimetype' => $file->type ));
      drupal_set_message($message, 'error');
      watchdog('filedepot', 'The file %name could not be uploaded. Mimetype %mimetype or extension not permitted.', array('%name' => $file->name, '%mimetype' => $file->type ));
      return FALSE;
    }

    if ($file->folder > 0 AND file_exists($this->tmp_storage_path) AND is_writable($this->tmp_storage_path)) {

      //if (is_array($nodefile) AND $nodefile['fid'] > 0) {
        // Need to populate the file field and attach the file to the folder node
        //$nodefile['list'] = 1;
        //$nodefile['data'] = serialize(array('description' => $file->description));
        //$nodefile['realname'] = $file->name;
        //$nodefile['moderated'] = $file->moderated;

        if ($file->moderated) {
          // Generate random file name for newly submitted file to hide it until approved
          $charset = "abcdefghijklmnopqrstuvwxyz";
          for ($i=0; $i<12; $i++) $random_name .= $charset[(mt_rand(0, (drupal_strlen($charset)-1)))];
          $ext = end(explode(".", $file->name));
          $random_name .= '.' . $ext;
          $file['moderated_tmpname'] = $random_name;
        }
        else {
          $file['moderated'] = FALSE;
        }

        $node = node_load($file->nid);
        $content_type = content_types($node->type);

        $node->filedepot_folder_file[] = $file;
        node_save($node);

        // After file has been saved and moved to the private filedepot folder via the HOOK_node_api function
        // Check and see what the final filename and use that to update the filedepot tables
        $rec = db_query("SELECT filename,filepath,filemime from {files} WHERE fid=:fid", array('fid'=>$nodefile['fid']))->fetchObject();
        $file->name = $rec->filename;
        $dest = $rec->filepath;
        $ext = end(explode(".", $file->name));

        // fix http://drupal.org/node/803694
        // seems that SWF (Flash) may always set the Content-Type to 'application/octet-stream'
        // no matter what.  Check the type and see if this has happened.
        // $file->type should have the MIME type guessed by Drupal in this instance.
        if ($rec->filemime == 'application/octet-stream') {
            db_query("UPDATE {files} SET filemime = :mime WHERE fid = :fid", array(
              'fid' => $file->type,
              'mime' => $nodefile['fid']));
        }

        if ($file->moderated) {   // Save record in submission table and set status to 0 -- not online
          $sql =  "INSERT INTO {filedepot_filesubmissions} "
          . "(cid, fname, tempname, title, description, cckfid, version_note, size, mimetype, extension, submitter, date, tags, notify) "
          . "VALUES (:folder,:realname,:tmpname,:title,:desc,:fid,:note,:size,:type,:ext,:uid,:time,:tags,:notify)";
          db_query($sql, array(
            'folder' => $file->folder,
            'realname' => $nodefile['realname'],
            'tmpname' => $nodefile['moderated_tmpname'],
            'title' => $file->title,
            'desc' => $file->description,
            'fid' => $nodefile['fid'],
            'note' => $file->vernote,
            'size' => $file->size,
            'type' => $file->type,
            'ext' => $ext,
            'uid' => $user->uid,
            'time' => time(),
            'tags' => $file->tags,
            'notify' => $_POST['notify'] ));

          // Get id for the new file record
          $args = array('folder' => $file->folder, 'uid' => $user->uid);
          $id = db_query("SELECT id FROM {filedepot_filesubmissions} WHERE cid=:folder AND submitter=:uid ORDER BY id DESC", $args, 0, 1)->fetchField();
          filedepot_sendNotification($id, FILEDEPOT_NOTIFY_ADMIN);

        }
        else {
          // Create filedepot record for file and set status of file to 1 - online
          $sql = "INSERT INTO {filedepot_files} (cid,fname,title,description,version,cckfid,size,mimetype,extension,submitter,status,date) "
          . "VALUES (:folder,:name,:title,:desc,1,:fid,:size,:type,:ext,:uid,1,:time)";
          db_query($sql, array (
            'folder' => $file->folder,
            'name' => $file->name,
            'title' => $file->title,
            'desc' => $file->description,
            'fid' => $nodefile['fid'],
            'size' => $file->size,
            'type' => $file->type,
            'ext' => $ext,
            'uid' => $user->uid,
            'time' => time()));

          // Get fileid for the new file record
          $args = array('cid' => $file->folder, 'uid' => $user->uid);
          $fid = db_query("SELECT fid FROM {filedepot_files} WHERE cid=:cid AND submitter=:uid ORDER BY fid DESC", $args, 0, 1)->fetchField();

          db_query("INSERT INTO {filedepot_fileversions} (fid,cckfid,fname,version,notes,size,date,uid,status)
          VALUES (:fid,:nodefile,:name,'1',:notes,:size,:time,:uid,1)", array(
            'fid' => $fid,
            'nodefile' => $nodefile['fid'],
            'name' => $file->name,
            'notes' => $file->vernote,
            'size' => $file->size,
            'time' => time(),
            'uid' => $user->uid));

          if (!empty($file->tags) AND $this->checkPermission($file->folder, 'view', 0, FALSE)) {
            $nexcloud->update_tags($fid, $file->tags);
          }
          // Send out email notifications of new file added to all users subscribed
          if ($_POST['notify'] == 1) {
            filedepot_sendNotification($fid, FILEDEPOT_NOTIFY_NEWFILE);
          }

          // Update related folders last_modified_date
          $workspaceParentFolder = filedepot_getTopLevelParent($file->folder);
          filedepot_updateFolderLastModified($workspaceParentFolder);

        }

        return TRUE;
      /*
      }
      else {
        drupal_set_message('Error saving file - move file failed');
        return FALSE;
      }
      */

    }
    else {
      drupal_set_message('Error saving file - directory does not exist or not writeable');
      return FALSE;
    }

  }


  public function saveVersion( $file, $validators = array() ) {
    global $conf, $user;
    $nexcloud =  filedepot_nexcloud();

    // Check for allowable file type.
    if (!$this->checkFilter($file->name, $file->type)) {
      $message = t('The file %name could not be uploaded. Mimetype %mimetype or extension not permitted.', array('%name' => $file->name, '%mimetype' => $file->type ));
      drupal_set_message($message, 'error');
      watchdog('filedepot', 'The file %name could not be uploaded. Mimetype %mimetype or extension not permitted.', array('%name' => $file->name, '%mimetype' => $file->type ));
      return FALSE;
    }

    if ($file->folder > 0 AND file_exists($this->tmp_storage_path) AND is_writable($this->tmp_storage_path)) {
      /* Tried to use the file_save_upload but was getting a PHP error in CCK but field_file_save_upload worked
      * $nodefileObj = file_save_upload($file->tmp_name,array(), $this->tmp_storage_path);
      */
      $nodefile = field_file_save_file($file->tmp_name, array(), $this->tmp_storage_path);

      $filedepot_private_directory_path = $this->root_storage_path . $file->folder;

      // Need to trick the file API to accept the private directory or the file_move() will fail
      $conf['file_directory_path'] = $filedepot_private_directory_path;

      $dest = rtrim($filedepot_private_directory_path, '\\/') .'/'. $file->name;
      $src = $nodefile['filepath'];

      // After a successful file_move, $src will be the set to the new filename including path
      // In case of a duplicate file in the destination directory,
      // the variable $src will be updated with the resulting appended incremental number
      // Refer to the drupal file_move API
      if (file_move($src, $dest, FILE_EXISTS_RENAME)) {
        // update db with the filename and full name including directory after the successful move
        $filename = basename($src);

        db_query("UPDATE {files} SET filename = :fname, filepath = :fpath WHERE fid = :fid", array(
          'fname' => $filename,
          'fpath' => $src,
          'fid' => $nodefile['fid']));

        $query = db_query("SELECT cid,fname,version,cckfid FROM {filedepot_files} WHERE fid=:fid", array(
          'fid' => $file->fid));
        list($cid, $fname, $curVersion, $cckfid) = array_values($query->fetchAssoc());

        $field = content_fields('filedepot_folder_file', 'filedepot_folder');
        $db_info = content_database_info($field);
        db_query("UPDATE " . $db_info['table'] . " SET filedepot_folder_file_fid = :fid WHERE filedepot_folder_file_fid = :old_fid",
          array(
            'fid' => $nodefile['fid'],
            'old_fid' => $cckfid));

        if ($curVersion < 1) $curVersion = 1;
        $newVersion = $curVersion + 1;

        $sql = "INSERT INTO {filedepot_fileversions} (fid, cckfid, fname, version, notes, size, date, uid, status) "
        . "VALUES (:fid,:nodefile,:fname,:version,:note,:size,:time,:uid,1)";
        db_query($sql, array(
          'fid' => $file->fid,
          'nodefile' => $nodefile['fid'],
          'fname' => $filename,
          'version' => $newVersion,
          'note' => $file->vernote,
          'size' => $file->size,
          'time' => time(),
          'uid' => $user->uid));
        $sql  = "UPDATE {filedepot_files} SET fname=:fname,version=:version,size=:size,date=:date,cckfid=:cckfid WHERE fid=:fid";
        db_query($sql, array(
          'fname' => $filename,
          'version' => $newVersion,
          'size' => $file->size,
          'date' => time(),
          'cckfid' => $nodefile['fid'],
          'fid' => $file->fid));

        // Update tags for this file
        if (!empty($file->tags) AND $this->checkPermission($file->folder, 'view', 0, FALSE)) {
          $nexcloud->update_tags($file->fid, $file->tags);
        }

        // Send out email notifications of new file added to all users subscribed
        if ($_POST['notify'] == 1) {
          filedepot_sendNotification($file->fid);
        }

        return TRUE;

      }

    }

  }


  public function deleteVersion($fid, $version) {
    $q1 = db_query("SELECT cid,version FROM {filedepot_files} WHERE fid=:fid", array('fid' => $fid));
    list ($cid, $curVersion) = array_values($q1->fetchAssoc());
    $q2 = db_query("SELECT fname,cckfid FROM {filedepot_fileversions} WHERE fid=:fid AND version=:version",
      array(
        'fid' => $fid,
        'version' => $version));
    list ($fname, $cckfid) = array_values($q2->fetchAssoc());
    if ($cid > 0 AND !empty($fname) AND $cckfid > 0) {
      db_query("DELETE FROM {filedepot_fileversions} WHERE fid=:fid AND version=:version",
        array(
          'fid' => $fid,
          'versuib' => $version));
      // Need to check there are no other repository entries in this category for the same filename
      if (db_query("SELECT count(fid) FROM {filedepot_files} WHERE cid=:cid and fname=:fname",
        array('cid' => $cid, 'fname' => $fname))->fetchField() > 1) {
        watchdog('filedepot', 'Delete file(@fid), version: @version, File: @fname. Other references found - not deleted.',
        array('@fid' => $fid, '@version' => $version, '@fname' => $fname));
      }
      else {
        if (!empty($fname) AND file_exists("{$this->root_storage_path}{$cid}/{$fname}")) {
          @unlink("{$this->root_storage_path}{$cid}/{$fname}");
        }
        watchdog('filedepot', 'Delete file(@fid), version: @version, File: @fname. Single reference - file deleted.',
        array('@fid' => $fid, '@version' => $version, '@fname' => $fname));
      }
      // If there is at least 1 more version record on file then I may need to update current version
      if (db_query("SELECT count(fid) FROM {filedepot_fileversions} WHERE fid=:fid", array('fid' => $fid))->fetchField() > 0) {
        if ($version == $curVersion) {
          // Retrieve most current version on record
          $q3 = db_query("SELECT fname,version,date FROM {filedepot_fileversions} WHERE fid=:fid ORDER BY version DESC",
            array('fid' => $fid), 0, 1);
          list ($fname, $version, $date) = array_values($q3->fetchAssoc());
          db_query("UPDATE {$filedepot_files} SET fname=:fname,version=:version, date=:time WHERE fid=:fid",
            array(
              'fname' => $fname,
              'version' => $version,
              'time' => time(),
              'fid' => $fid));
        }
      }
      else {
        watchdog('filedepot', 'Delete File final version for fid(@fid), Main file records deleted.',
        array('@fid' => $fid, '@version' => $version, '@fname' => $fname));
        db_query("DELETE FROM {filedepot_files} WHERE fid=:fid", array('fid' => $fid));
      }
      return TRUE;

    }
    else {
      return FALSE;
    }

  }

  public function approveFileSubmission($id) {
    $nexcloud =  filedepot_nexcloud();

    $query = db_query("SELECT * FROM {filedepot_filesubmissions} WHERE id=:fid", array('fid' => $id));
    $rec = $query->fetchObject();
    $data = array();
    // @TODO: Check if there have been multiple submission requests for the same file and thus have same new version #
    if ($rec->version == 1) {
      $curfile = "{$this->root_storage_path}{$rec->cid}/submissions/{$rec->tempname}";
      $newfile = "{$this->root_storage_path}{$rec->cid}/{$rec->fname}";
      $rename = @rename($curfile, $newfile);

      // Need to update the filename path in the drupal files table
      db_query("UPDATE {files} SET filename=:fname, filepath=:fpath, filemime=:mime WHERE fid=:fid",
        array(
          'fname' => $rec->fname,
          'fpath' => $newfile,
          'mine' => $rec->mimetype,
          'fid' => $rec->cckfid));

      $sql = "INSERT INTO {filedepot_files} (cid,fname,title,description,version,cckfid,size,mimetype,submitter,status,date,version_ctl,extension) "
      . "VALUES (:cid,:fname,:title,:desc,1,:cckfid,:size,:mimetype,:uid,1,:time,:version,:extension)";
      db_query($sql,
        array(
          'cid' => $rec->cid,
          'fname' => $rec->fname,
          'title' => $rec->title,
          'desc' => $rec->description,
          'cckfid' => $rec->cckfid,
          'size' => $rec->size,
          'mimetype' => $rec->mimetype,
          'uid' => $rec->submitter,
          'time' => time(),
          'version' => $rec->version_ctl,
          'extension' => $rec->extension));
      // Get fileid for the new file record
      $args = array('cid' => $rec->cid, 'uid' => $rec->submitter);
      $newfid = db_query("SELECT fid FROM {filedepot_files} WHERE cid=:cid AND submitter=:uid ORDER BY fid DESC", $args, 0, 1)->fetchField();

      db_query("INSERT INTO {filedepot_fileversions} (fid,cckfid,fname,version,notes,size,date,uid,status)
      VALUES (:newfid,:cckfid,:fname,'1',:versionnote,:size,:time,:uid,1)",
        array(
          'newfid' => $newfid,
          'cckfid' => $rec->cckfid,
          'fname' => $rec->fname,
          'versionnote' => $rec->version_note,
          'size' => $rec->size,
          'time' => time(),
          'uid' => $rec->submitter));

      if (!empty($rec->tags) AND $this->checkPermission($rec->cid, 'view', 0, FALSE)) {
        $nexcloud->update_tags($fid, $rec->tags);
      }

    }
    else {
      // Need to rename the current versioned file
      $curfile = "{$this->root_storage_path}{$rec->cid}/submissions/{$rec->tempname}";
      $newfile = "{$this->root_storage_path}{$rec->cid}/{$rec->fname}";
      $rename = @rename($curfile, $newfile);
      db_query("INSERT INTO {filedepot_fileversions} (fid,cckfid,fname,version,notes,size,date,uid,status)
      VALUES (:newfid,:cckfid,:fname,'1',:versionnote,:size,:time,:uid,1)",
        array(
          'newfid' => $newfid,
          'cckfid' => $rec->cckfid,
          'fname' => $rec->fname,
          'versionnote' => $rec->version_note,
          'size' => $rec->size,
          'time' => time(),
          'uid' => $rec->submitter));

      db_query("UPDATE {filedepot_files} SET fname=:fname,version=:version, date=:time WHERE fid=:fid",
        array(
          'fname' => $rec->fname,
          'version' => $rc->version,
          'time' => time(),
          'fid' => $rec->fid));
      $newfid = $fid;
    }

    if ($newfid > 0) {
      if ($rec->notify == 1) {
        filedepot_sendNotification($newfid, FILEDEPOT_NOTIFY_APPROVED);
      }
      db_query("DELETE FROM {filedepot_filesubmissions} WHERE id=:fid", array('fid' => $id));

      // Send out notifications of update to all subscribed users
      filedepot_sendNotification($newfid, FILEDEPOT_NOTIFY_NEWFILE);

      // Update related folders last_modified_date
      $workspaceParentFolder = filedepot_getTopLevelParent($rec->cid);
      filedepot_updateFolderLastModified($workspaceParentFolder);

      return TRUE;

    }
    else {
      return FALSE;
    }

  }

  function clientUploadFile($fileArray, $username='', $password='') {
    $outputInformation = '';

    // Check for allowable file type.
    if (!$this->checkFilter($_FILES['file']['name'], $_FILES['file']['type'])) {
      $message = t('The file %name could not be uploaded. Mimetype %mimetype or extension not permitted.', array('%name' => $_FILES['file']['name'], '%mimetype' => $_FILES['file']['type'] ));
      watchdog('filedepot', 'The file %name could not be uploaded. Mimetype %mimetype or extension not permitted.', array('%name' => $_FILES['file']['name'], '%mimetype' => $_FILES['file']['type'] ));
      return FALSE;
    }

    watchdog('filedepot', 'Processing client upload of file @file', array('@file' => "{$_FILES['file']['name']}"));

    // Need to setup $_FILES the way Drupal field_file_save_file wants it
    $_FILES['files'] = $_FILES['file'];
    $filename = $_FILES['files']['name'];
    $filesize = intval($_FILES['files']['size']);
    $uid = intval(db_query("SELECT uid FROM {users} WHERE name = :name AND pass = :pass",
      array(
        'name' => $_POST['username'],
        'pass' => $_POST['password'])))->fetchField();

    //format is ....{t..token...}.extension if its an actual upload
    $matchesArray = array();
    preg_match_all("|{[^}]+t}|", $filename, $matchesArray);

    // Client could be uploading a file that has been downloaded with a unique token in the filename
    // If the token matches for this filename then replace the file - this is the download for editing feature
    // Check that $matchesArray[0][0] contains valid data - should contain the token.

    if ($matchesArray[0][0] != '' && isset($matchesArray[0][0])) {
      $token = str_replace("{", "", $matchesArray[0][0]);
      $token = str_replace("t}", "", $token);
      watchdog('filedepot', 'Processing a edit file upload - token:@token - uid:@uid', array('@token' => $token, '@uid' => $uid));
      $fid = db_query("SELECT fid FROM {filedepot_export_queue} WHERE token = :token",
        array('token' => $token))->fetchField();

      // Using the fid and token, we align this to the export table and ensure this is a valid upload!
      $res = db_query("SELECT id,orig_filename,extension,timestamp,fid FROM {filedepot_export_queue} WHERE token=:token",
        array('token' => $token));
      $A = $res->fetchObject();
      if ($A->fid > 0) {
        $cid = db_query("SELECT cid FROM {filedepot_files} WHERE fid=:fid",
          array( 'fid' => $A->fid))->fetchField();
        watchdog('filedepot', 'rename @fromfile to @tofile', array('@fromfile' => "{$fileArray['tmp_name']}", '@tofile' => "{$this->root_storage_path}/{$cid}/{$A->orig_filename}"));
        // Update the repository with the new file - PHP/Windows will not rename a file if it exists
        // Rename is atomic and fast vs copy and unlink as there is a chance someone may be trying to download the file
        if (@rename($fileArray['tmp_name'], "{$this->root_storage_path}{$cid}/{$A->orig_filename}") == FALSE) {
          @copy($fileArray['tmp_name'], "{$this->root_storage_path}{$cid}/{$A->orig_filename}");
          @unlink($fileArray['tmp_name']);
        }
        // Update information in the repository
        db_query("UPDATE {filedepot_files} SET status='1', status_changedby_uid=:uid WHERE fid=:fid",
          array('uid' => $uid, 'fid' => $fid));

      }
      else {
        watchdog('filedepot', 'Save file to the import queue');
        // Save file via Drupal file API to the temporary incoming folder
        $nodefile = field_file_save_file($_FILES['files']['tmp_name'], array(), $this->tmp_incoming_path);
        if (is_array($nodefile) AND $nodefile['fid'] > 0) {
          // Update the incoming queue.
          $mimetype = $_FILES['files']['type'];
          $tempfilename=substr($filename, $this->upload_prefix_character_count);
          $description = "Uploaded by {$_POST['username']} on " . date("F j, Y, g:i a") . ', via the Filedepot desktop agent';
          $sql  = "INSERT INTO {filedepot_import_queue} (orig_filename,queue_filename,timestamp,uid,cckfid,size,mimetype,description ) ";
          $sql .= "values (:tmpfname,:fname,:time,:uid,:fid,:size,:mime,:desc)";
          db_query($sql,
            array(
              'tmpfname' => $tempfilename,
              'fname' => $filename,
              'time' => time(),
              'uid' => $uid,
              'fid' => $nodefile['fid'],
              'size' => $filesize,
              'mime' => $mimetype,
              'desc' => $description));
          $outputInformation .=  ("File: {$filename} has been updated...\n" );
        }
        else {
            watchdog('filedepot', 'Client error 9001 uploading file @file', array('@file' => "$filename"));
        }
      }

    }
    else {
      // Save file via Drupal file API to the temporary incoming folder
      $nodefile = field_file_save_file($_FILES['files']['tmp_name'], array(), $this->tmp_incoming_path);
      if (is_array($nodefile) AND $nodefile['fid'] > 0) {
        // Update the incoming queue.
        $tempfilename=substr($filename, $this->upload_prefix_character_count);
        $description = "Uploaded by {$_POST['username']} on " . date("F j, Y, g:i a") . ', via the Filedepot desktop agent';
        $sql  = "INSERT INTO {filedepot_import_queue} (orig_filename,queue_filename,timestamp,uid,cckfid,size,mimetype,description ) ";
        $sql .= "values (:tmpfname,:fname,:time,:uid,:fid,:size,:mime,:desc)";
        db_query($sql,
          array(
            'tmpfname' => $tempfilename,
            'fname' => $filename,
            'time' => time(),
            'uid' => $uid,
            'fid' => $nodefile['fid'],
            'size' => $filesize,
            'mime' => $mimetype,
            'desc' => $description));
        $outputInformation .=  ("File: {$filename} has been added to incoming queue...\n" );
      }
      else {
        watchdog('filedepot', 'Client error 9002 uploading file @file', array('@file' => "$filename"));
      }
    }
    return $outputInformation;

  }

  /* Move a file from the incoming Queue area to a repository category */
  public function moveIncomingFile($id, $newcid) {
    global $user;

    $filemoved = FALSE;
    $nid = db_query("SELECT nid FROM {filedepot_categories} WHERE cid=:cid",
      array(
        'cid' => $newcid))->fetchField();
    if ($newcid > 0 AND $nid > 0) {
      $sql = "SELECT a.orig_filename,a.queue_filename,a.timestamp,a.uid,a.cckfid,a.size,a.mimetype,a.description,a.version_note,b.filename,b.filepath "
      . "FROM {filedepot_import_queue} a  LEFT JOIN {files} b on b.fid=a.cckfid WHERE id=:id";
      $query = db_query($sql, array('id' => $id));
      $file = $query->fetchObject();
      $sourcefile = $this->tmp_incoming_path . $file->filename;
      $targetfile = $this->root_storage_path . "{$newcid}/{$file->orig_filename}";

      if (!empty($file->queue_filename) AND !empty($file->orig_filename) AND file_exists($sourcefile)) {
        if ($submitter == $user->uid OR $this->checkPermission($newcid, 'admin')) {

          // Need to populate the CCK fields for the filefield field - so node_save will update the CCK Data and HOOK_nodeapi will move the file
          $nodefile['filepath'] = $file->filepath;
          $nodefile['fid'] = (int) $file->cckfid;
          $nodefile['status'] = 1;
          $nodefile['list'] = 1;
          $nodefile['data'] = serialize(array('description' => $file->description));
          $nodefile['realname'] = $file->orig_filename;
          $nodefile['moderated'] = FALSE;
          $nodefile['incoming'] = TRUE;

          $node = node_load(array('nid' => $nid));
          $content_type = content_types($node->type);

          $nodefileObj = new stdClass();
          $nodefileObj->fid = $file->cckfid;   // file_set_status API expects an object but just needs fid
          file_set_status($nodefileObj, 1);
          $node->filedepot_folder_file[] = $nodefile;
          node_save($node);

          // After file has been saved and moved to the private filedepot folder via the HOOK_node_api function
          // Check and see what the final filename and use that to update the filedepot tables
          $rec = db_query("SELECT filename,filepath from {files} WHERE fid=:fid",
            array(
              'fid' => $file->cckfid))->fetchObject();
          $file->filename = $rec->filename;
          $dest = $rec->filepath;
          $ext = end(explode(".", $file->name));

          // Create filedepot record for file and set status of file to 1 - online
          $sql = "INSERT INTO {filedepot_files} (cid,fname,title,description,version,cckfid,size,mimetype,extension,submitter,status,date) "
          . "VALUES (:newcid,:fname,:orig_fname,:desc,1,:cckfid,:size,:mime,:ext,:uid,1,:time)";
          db_query($sql,
            array(
              'newcid' => $newcid,
              'fname' => $file->filename,
              'orig_fname' => $file->orig_filename,
              'desc' => $file->description,
              'cckfid' => $file->cckfid,
              'size' => $file->size,
              'mime' => $file->mimetype,
              'ext' => $ext,
              'uid' => $user->uid,
              'time' => time() ));

          // Get fileid for the new file record
          $args = array('cid' => $newcid, 'uid' => $user->uid);
          $fid = db_query("SELECT fid FROM {filedepot_files} WHERE cid=:cid AND submitter=:uid ORDER BY fid DESC", $args, 0, 1)->fetchField();

          db_query("INSERT INTO {filedepot_fileversions} (fid,cckfid,fname,version,notes,size,date,uid,status)
          VALUES (:fid,:cckfid,:fname,'1',:vnote,:size,:time,:uid,1)",
            array(
              'fid' => $fid,
              'cckfid' => $file->cckfid,
              'fname' => $file->filename,
              'vnote' => $file->version_note,
              'size' => $file->size,
              'time' => time(),
              'uid' => $user->uid));

          db_query("DELETE FROM {filedepot_import_queue} WHERE id = :id", array('id' => $id));

          // Update related folders last_modified_date
          $workspaceParentFolder = filedepot_getTopLevelParent($newcid);
          filedepot_updateFolderLastModified($workspaceParentFolder);
          content_clear_type_cache();
          $filemoved = TRUE;

        }
        else {
          watchdog('filedepot', 'User @user does not have access to move file(@fid): @fname to category: @newcid',
          array('@user' => $user->name, '@fid' => $fid, '@fname' => $fname, '@newcid' => $newcid));
        }

      }
      else {
        $GLOBALS['filedepot_errmsg'] = "Error moving file - source file $gname missing";
        watchdog('filedepot', 'Filedepot: @errmsg', array('@errmsg' => $GLOBALS['filedepot_errmsg']));
      }

    }
    else {
      $GLOBALS['filedepot_errmsg'] = "Invalid Destination Folder";
      watchdog('filedepot', 'Filedepot: @errmsg', array('@errmsg' => $GLOBALS['filedepot_errmsg']));
    }

    return $filemoved;
  }

}

