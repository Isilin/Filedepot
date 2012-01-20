<?php

/**
 * @file
 * lib-common.php
 * Common library of functions for the applications
 */



function firelogmsg($message) {
  global $firephp, $mytimer;
  $exectime = timer_read('filedepot_timer');
  if (function_exists('dfb')) {
    dfb("$message - time:$exectime");
  }
}


/**
 * Returns a formatted listbox of categories user has access
 * First checks for View access so that delegated admin can be just for sub-categories
 * @param        string|array        $perms        Single perm 'admin' or array of permissions as required by $filedepot->checkPermission()
 * @param        int                 $selected     Will make this item the selected item in the listbox
 * @param        string              $id           Parent category to start at and then recursively check
 * @param        string              $level        Used by this function as it calls itself to control the indent formatting
 * @return       string                            Return an array of folder options
 */
function filedepot_recursiveAccessArray($perms, $id = 0, $level = 1) {
  $filedepot = filedepot_filedepot();
  $options_tree = array();
  $query = db_query("SELECT cid,pid,name FROM {filedepot_categories} WHERE pid=:pid ORDER BY cid", 
    array(':pid' => $id));
  while ($A = $query->fetchAssoc()) {
    list($cid, $pid, $name) = array_values($A);
    $indent = ' ';
    // Check if user has access to this category

    if ($filedepot->checkPermission($cid, 'view')) {
      // Check and see if this category has any sub categories - where a category record has this cid as it's parent

      $tempcid = db_query("SELECT cid FROM {filedepot_categories} WHERE pid=:cid", array(':cid' => $cid))->fetchField();
      if ($tempcid > 0) {
        if ($level > 1) {
          for ($i = 2; $i <= $level; $i++) {
            $indent .= "--";
          }
          $indent .= ' ';
        }
        if ($filedepot->checkPermission($cid, $perms)) {
          if ($indent != '') {
            $name = " $name";
          }
          $options_tree[$cid] = $indent . $name;
          $options_tree += filedepot_recursiveAccessArray($perms, $cid, $level + 1);
        }
        else {
          // Need to check for any folders with admin even subfolders of parents that user does not have access

          $options_tree += filedepot_recursiveAccessArray($perms, $cid, $level + 1);
        }

      }
      else {
        if ($level > 1) {
          for ($i = 2; $i <= $level; $i++) {
            $indent .= "--";
          }
          $indent .= ' ';
        }
        if ($filedepot->checkPermission($cid, $perms)) {
          if ($indent != '') {
            $name = " $name";
          }
          $options_tree[$cid] = $indent . $name;
        }
      }
    }
  }
  return $options_tree;
}


/**
 * Returns a formatted listbox of categories user has access
 * First checks for View access so that delegated admin can be just for sub-categories
 *
 * @param        string|array        $perms        Single perm 'admin' or array of permissions as required by $filedepot->checkPermission()
 * @param        int                 $selected     Will make this item the selected item in the listbox
 * @param        string              $id           Parent category to start at and then recursively check
 * @param        string              $level        Used by this function as it calls itself to control the indent formatting
 * @param        boolean             $addRootOpt   Add the 'Top Level Folder' option, when appropriate.  Defaults to @c TRUE.
 * @return       string                            Return a formatted HTML Select listbox of categories
 */
function filedepot_recursiveAccessOptions($perms, $selected = '', $id = '0', $level = '1', $addRootOpt = TRUE) {
  $filedepot = filedepot_filedepot();
  $selectlist = '';
  if ($addRootOpt AND $level == 1 AND user_access('administer filedepot')) {
    $selectlist = '<option value="0">' . t('Top Level Folder') . '</option>' . LB;
  }
  $query = db_query("SELECT cid,pid,name FROM {filedepot_categories} WHERE pid=:cid ORDER BY cid", array(':cid' => $id));
  while ($A = $query->fetchAssoc()) {
    list($cid, $pid, $name) = array_values($A);
    $name = filter_xss($name);
    $indent = ' ';
    // Check if user has access to this category

    if ($filedepot->checkPermission($cid, 'view')) {
      // Check and see if this category has any sub categories - where a category record has this cid as it's parent

      $tempcid = db_query("SELECT cid FROM {filedepot_categories} WHERE pid=:cid", array(':cid' => $cid))->fetchField();
      if ($tempcid > 0) {
        if ($level > 1) {
          for ($i = 2; $i <= $level; $i++) {
            $indent .= "--";
          }
          $indent .= ' ';
        }
        if ($filedepot->checkPermission($cid, $perms)) {
          if ($indent != '') {
            $name = " $name";
          }
          $selectlist .= '<option value="' . $cid;
          if ($cid == $selected) {
            $selectlist .= '" selected="selected">' . $indent . $name . '</option>' . LB;
          }
          else {
            $selectlist .= '">' . $indent . $name . '</option>' . LB;
          }
          $selectlist .= filedepot_recursiveAccessOptions($perms, $selected, $cid, $level + 1, $addRootOpt);
        }
        else {
          // Need to check for any folders with admin even subfolders of parents that user does not have access

          $selectlist .= filedepot_recursiveAccessOptions($perms, $selected, $cid, $level + 1, $addRootOpt);
        }

      }
      else {
        if ($level > 1) {
          for ($i = 2; $i <= $level; $i++) {
            $indent .= "--";
          }
          $indent .= ' ';
        }
        if ($filedepot->checkPermission($cid, $perms)) {
          if ($indent != '') {
            $name = " $name";
          }
          $selectlist .= '<option value="' . $cid;
          if ($cid == $selected) {
            $selectlist .= '" selected="selected">' . $indent . $name . '</option>' . LB;
          }
          else {
            $selectlist .= '">' . $indent . $name . '</option>' . LB;
          }
        }
      }
    }
  }
  return $selectlist;
}


/* Recursive Function to navigate down folder structure
 * and determine most recent file data and set last_modified_date for each subfolder
 * Called after a file is added or moved to keep folder data in sync.
 */
function filedepot_updateFolderLastModified($id) {
  $last_modified_parentdate = 0;
  if (db_query("SELECT cid FROM {filedepot_categories} WHERE cid=:cid", array(':cid' => $id))->fetchField() > 0) {
    $q1 = db_query("SELECT cid FROM {filedepot_categories} WHERE pid=:cid ORDER BY folderorder ASC", array(':cid' => $id));
    while ($A = $q1->fetchAssoc()) {
      $last_modified_date = 0;
      $q2 = db_query_range("SELECT date FROM {filedepot_files} WHERE cid=:cid ORDER BY date DESC", 
        0, 1, array(':cid' => $A['cid']));
      $B = $q2->fetchAssoc();
      if ($B['date'] > $last_modified_date) {
        $last_modified_date = $B['date'];
      }
      if (db_query("SELECT pid FROM {filedepot_categories} WHERE cid=:cid", array(':cid' => $A['cid']))->fetchField() > 0) {
        $latestdate = filedepot_updateFolderLastModified($A['cid']);
        if ($latestdate > $last_modified_date) {
          $last_modified_date = $latestdate;
        }
      }
      db_query("UPDATE {filedepot_categories} SET last_modified_date=:time WHERE cid=:cid", 
        array(':time' => $last_modified_date, ':cid' => $A['cid']));
      if ($last_modified_date > $last_modified_parentdate) {
        $last_modified_parentdate = $last_modified_date;
      }
    }
    db_query("UPDATE {filedepot_categories} SET last_modified_date=:time WHERE cid=:cid", 
      array(':time' => $last_modified_parentdate, ':cid' => $id));
  }
  $q4 = db_query("SELECT date FROM {filedepot_files} WHERE cid=:cid ORDER BY date DESC", array(':cid' => $id));
  $C = $q4->fetchAssoc();
  if ($C['date'] > $last_modified_parentdate) {
    $last_modified_parentdate = $C['date'];
  }
  db_query("UPDATE {filedepot_categories} SET last_modified_date=:time WHERE cid=:cid", 
    array(':time' => $last_modified_parentdate, ':cid' => $id));

  return $last_modified_parentdate;
}


/* Return the toplevel parent folder id for a subfolder */
function filedepot_getTopLevelParent($cid) {
  $pid = db_query("SELECT pid FROM {filedepot_categories} WHERE cid=:cid", array(':cid' => $cid))->fetchField();
  if ($pid == 0) {
    return $cid;
  }
  else {
    $cid = filedepot_getTopLevelParent($pid);
  }
  return $cid;
}



function filedepot_formatfiletags($tags) {
  $retval = '';
  if (!empty($tags)) {
    $atags = explode(',', $tags);
    $asearchtags = explode(',', stripslashes($_POST['tags']));
    foreach ($atags as $tag) {
      $tag = trim($tag); // added to handle extra space thats added when removing a tag - thats between 2 other tags

      if (!empty($tag)) {
        if (in_array($tag, $asearchtags)) {
          $retval .= theme('filedepot_taglinkoff', array('label' => check_plain($tag)));
        }
        else {
          $retval .= theme('filedepot_taglinkon', array('searchtag' => addslashes($tag), 'label' => check_plain($tag)));
        }
      }
    }
  }
  return $retval;

}



function filedepot_formatFileSize($size) {
  $size = intval($size);
  if ($size / 1000000 > 1) {
    $size = round($size / 1000000, 2) . " MB";
  }
  elseif ($size / 1000 > 1) {
    $size = round($size / 1000, 2) . " KB";
  }
  else {
    $size = round($size, 2) . " Bytes";
  }
  return $size;
}

function filedepot_getSubmissionCnt() {
  $filedepot = filedepot_filedepot();
  // Determine if this user has any submitted files that they can approve

  $query = db_query("SELECT cid from {filedepot_filesubmissions}");
  $submissions = 0;
  while ($A = $query->fetchAssoc()) {
    if ($filedepot->checkPermission($A['cid'], 'approval')) {
      $submissions++;
    }
  }
  return $submissions;
}


function filedepot_getUserOptions() {
  $retval = '';
  $query = db_query("SELECT u.uid, u.name,u.status FROM {users} u WHERE u.status = 1 ORDER BY name");
  while ($u = $query->fetchObject()) {
    $retval .= '<option value = "' . $u->uid . '">' . $u->name . '</option>';
  }
  return $retval;
}

function filedepot_getRoleOptions() {
  $retval = '';
  $query = db_query("SELECT r.rid, r.name FROM {role} r ");
  while ($r = $query->fetchObject()) {
    $retval .= '<option value = "' . $r->rid . '">' . $r->name . '</option>';
  }
  return $retval;
}

function filedepot_getGroupOptions() {
  $retval = '';
  $groups = og_all_groups_options();
  foreach ($groups as $grpid => $grpname) {
    $retval .= '<option value="' . $grpid . '">' . $grpname . '</option>';
  }
  return $retval;
}


/**
 * Send out notifications to all users that have subscribed to this file or file category
 * Will check user preferences for notification if Messenger Plugin is installed
 * @param        string      $id        Key used to retrieve details depending on message type
 * @param        string      $type      Message type ->
 *                                       (1) FILEDEPOT_NOTIFY_NEWFILE,
 *                                       (2) FILEDEPOT_NOTIFY_APPROVED,
 *                                       (3) FILEDEPOT_NOTIFY_REJECT,
 *                                       (4) FILEDEPOT_NOTIFY_ADMIN
 * @return       Boolean     Returns TRUE if atleast 1 message was sent out
 */
function filedepot_sendNotification($id, $type = 1) {
  return TRUE;
  global $user;
  $filedepot = filedepot_filedepot();

  /* If notifications have been disabled via the module admin settings - return TRUE */
  if (variable_get('filedepot_notifications_enabled', 1) == 0) {
    return TRUE;
  }

  $target_users = array();
  $message = array();
  $message['headers'] = array('From' => variable_get('site_mail', ''));
  $messagetext = '';
  $messagetext2ary = array();
  switch ( $type ) {
    case FILEDEPOT_NOTIFY_NEWFILE: // New File added where $id = file id. Send to all subscribed users
      $sql = "SELECT file.fid,file.fname,file.cid,file.submitter,category.name FROM "
      . "{filedepot_files} file, {filedepot_categories} category "
      . "WHERE file.cid=category.cid and file.fid=:fid";
      $query = db_query($sql, array(':fid' => $id));
      list($fid, $fname, $cid, $submitter, $catname) = array_values($query->fetchAssoc());
      $link = url('filedepot', array('query' => drupal_query_string_encode(array('cid' => $cid, 'fid' => $fid)), 'absolute' => true));
      $message['subject'] = variable_get('site_name', '') . ' - ' . t('New Document Management Update');
      $messagetext2ary = array(
        '!file' => $fname,
        '!bp' => '<p>',
        '!ep' => '</p>',
        '!folder' => $catname,
        '!link' => url($link, array('absolute' => TRUE)),
      );
      break;

    case FILEDEPOT_NOTIFY_APPROVED: // File submission being approved by admin where $id = file id. Send only to user
      $sql = "SELECT file.fid,file.fname,file.cid,file.submitter,category.name FROM {filedepot_files} file, "
      . "{filedepot_categories} category WHERE file.cid=category.cid and file.fid=:fid";
      $query = db_query($sql, array(':fid' => $id));
      list($fid, $fname, $cid, $submitter, $catname) = array_values($query->fetchAssoc());
      // Just need to create this SQL record for this user - to fake out logic below

      $target_users[] = $submitter;
      $link = url('filedepot', array('query' => drupal_query_string_encode(array('cid' => $cid, 'fid' => $fid)), 'absolute' => true));
      $message['subject'] = variable_get('site_name', '') . ' - ' . t('New File Submission Approved');
      $messagetext = t('Site member %@name: your file in folder: !folder', 
      array('!folder' => $catname)) . '<p>';
      $messagetext .= t('The file: !filename has been approved and can be accessed !link', 
      array('!filename' => $fname, '!link' => $link)) . '</p><p>';
      $messagetext .= t('You are receiving this because you requested to be notified.') . '</p><p>';
      $messagetext .= t('Thank You') . '</p>';
      break;

    case FILEDEPOT_NOTIFY_REJECT: // File submission being declined by admin where $id = new submission record id. Send only to user
      $fname = db_query("SELECT fname FROM {filedepot_filesubmissions} WHERE id=:fid", array(':fid' => $id))->fetchField();
      $submitter = db_query("SELECT submitter FROM {filedepot_filesubmissions} WHERE id=:fid", array(':fid' => $id))->fetchField();
      // Just need to create this SQL record for this user - to fake out logic below

      $target_users[] = $submitter;
      $message['subject'] = variable_get('site_name', '') . ' - ' . t('New File Submission Cancelled');
      $messagetext = t('Your recent file submission: !filename, was not accepted', 
      array('!filename' => $fname)) . '<p>';
      $messagetext .= t('Thank You') . '</p>';
      break;

    case FILEDEPOT_NOTIFY_ADMIN: // New File Submission in queue awaiting approval
      $sql = "SELECT file.fname,file.cid,file.submitter,category.name FROM {filedepot_filesubmissions} file , "
      . "{filedepot_categories} category WHERE file.cid=category.cid and file.id=:fid";
      $query = db_query($sql, array(':fid' => $id));
      list($fname, $cid, $submitter, $catname) = array_values($query->fetchAssoc());
      $submitter_name = db_query("SELECT name FROM {users} WHERE uid=:uid", array(':uid' => $submitter))->fetchField();
      $message['subject'] = variable_get('site_name', '') . ' - ' . t('New File Submission requires Approval');
      $messagetext2ary = array(
        '!filename' => $fname,
        '!bp' => '<p>',
        '!ep' => '</p>',
        '!folder' => $catname,
      );
      break;
  }

  if ($type == FILEDEPOT_NOTIFY_NEWFILE ) {
    if (variable_get('filedepot_default_notify_newfile', 0) == 1) { // Site default to notify all users on new files
      $query_users = db_query("SELECT uid FROM {users} WHERE uid > 0 AND status = 1");
      while ( $A = $query_users->fetchObject()) {
        if ($filedepot->checkPermission($cid, 'view', $A->uid)) {
          $personal_exception = FALSE;
          if (db_query("SELECT uid FROM {filedepot_usersettings} WHERE uid=:uid AND notify_newfile=0", array(':uid' => $A->uid))->fetchField() == $A->uid) {
            $personal_setting = FALSE; // User preference record exists and set to not be notified

          }
          else {
            $personal_setting = TRUE; // Either record does not exist or user preference is to be notified

          }
          // Check if user has any notification exceptions set for this folder

          if (db_query("SELECT count(*) FROM {filedepot_notifications} WHERE cid=:cid AND uid=:uid AND cid_newfiles=0", array(':cid' => $cid, ':uid' => $A->uid))->fetchField() > 0) {
            $personal_exception = TRUE;
          }
          // Only want to notify users that don't have setting disabled or exception record

          if ($personal_setting == TRUE AND $personal_exception == FALSE AND $A->uid != $submitter) {
            $target_users[] = $A->uid;
          }
        }
      }

    }
    else {
      $sql = "SELECT a.uid FROM {filedepot_usersettings} a LEFT JOIN {users} b on b.uid=a.uid WHERE a.notify_newfile = 1 and b.status=1";
      $query_users = db_query($sql);
      while ( $A = $query_users->fetchObject()) {
        if ($filedepot->checkPermission($cid, 'view', $A->uid)) {
          $personal_exception = FALSE;
          if (db_query("SELECT ignore_filechanges FROM {filedepot_notifications} WHERE fid=:fid and uid=:uid", array(':fid' => $id, ':uid' => $A->uid))->fetchField() == 1) {
            $personal_exception = TRUE;
          }
          // Only want to notify users that have notifications enabled but don't have an exception record

          if ($personal_exception === FALSE) {
            $target_users[] = $A->uid;
          }
        }
      }
    }
  }
  elseif ($type == FILEDEPOT_NOTIFY_ADMIN) {
    $query_users = db_query("SELECT uid FROM {users} WHERE uid > 0 AND status = 1");
    while ( $A = $query_users->fetchObject()) {
      if ($filedepot->checkPermission($cid, 'approval', $A->uid)) {
        $personal_exception = FALSE;
        if (db_query("SELECT uid FROM {filedepot_usersettings} WHERE uid=:uid AND notify_newfile=0", array(':fid' => $A->uid))->fetchField() == $A->uid) {
          $personal_setting = FALSE; // User preference record exists and set to not be notified

        }
        else {
          $personal_setting = TRUE; // Either record does not exist or user preference is to be notified

        }
        // Check if user has any notification exceptions set for this folder

        if (db_query("SELECT count(*) FROM {filedepot_notifications} WHERE cid=:cid AND uid=:uid AND cid_newfiles=0", array(':cid' => $cid, ':uid' => $A->uid))->fetchField() > 0) {
          $personal_exception = TRUE;
        }
        // Only want to notify users that don't have setting disabled or exception record

        if ($personal_setting == TRUE AND $personal_exception == FALSE AND $A->uid != $submitter) {
          $target_users[] = $A->uid;
        }
      }
    }
  }

  $messagetext = drupal_html_to_text($messagetext);

  if (is_array($target_users) AND count($target_users) > 0) {

    if ($type == FILEDEPOT_NOTIFY_APPROVED OR $type == FILEDEPOT_NOTIFY_REJECT ) { // Only send this type of notification to user that submitted the file
      $query = db_query("SELECT name,mail FROM {users} WHERE uid=:uid", array(':uid' => $submitter));
      $rec = $query->fetchObject();
      if ($type == FILEDEPOT_NOTIFY_APPROVED) {
        $messagetext = sprintf($messagetext, $rec->name);
      }

      $message['body'] = t('Hello @username', array('@username' => $rec->name)) . ",\n\n";
      $message['body'] .= $messagetext;
      $message['body'] .= variable_get('site_name', '') . "\n";
      $message['to'] = $rec->mail;
      drupal_mail_send($message);
      $sql = "INSERT INTO {filedepot_notificationlog} (target_uid,submitter_uid,notification_type,fid,cid,datetime) "
      . "VALUES (:tuid,:uid,:type,:id,:cid,:time )";
      db_query($sql, array(':tuid' => $submitter, ':uid' => $submitter, ':type' => $type, ':id' => $id, ':cid' => $cid, ':time' => time()));
      return TRUE;
    }
    elseif ($type == FILEDEPOT_NOTIFY_NEWFILE OR $type == FILEDEPOT_NOTIFY_ADMIN) {
      $name = db_query("SELECT name FROM {users} WHERE uid=:uid", array(':uid' => $submitter))->fetchField();
      $messagetext2ary['@@name'] = $name;

      switch ( $type ) {
        case FILEDEPOT_NOTIFY_NEWFILE:
          $messagetext = t('Site member @@name has submitted a new file (!file)!bp Folder: !folder !ep!bp The file can be accessed at !link !ep!bp You are receiving this because you requested to be notified of updates.!ep!bp Thank You !ep', 
                       $messagetext2ary);
          break;
        case FILEDEPOT_NOTIFY_ADMIN:
          $messagetext = t('Site member @@name has submitted a new file !filename for folder !folder that requires approval !bp Thank You !ep', $messagetext2ary);
          break;
        default:
          break;
      }
      $messagetext = drupal_html_to_text($messagetext);
      $message['body'] = $messagetext . variable_get('site_name', '') . "\n";

      // Sort the array so that we can check for duplicate user notification records

      sort($target_users);
      reset($target_users);
      $lastuser = '';
      $distribution = array();

      /* Send out Notifications to all users on distribution using Bcc - Blind copy to hide the distribution
       * To send to complete distribution as one email and not loop thru distribution sending individual emails
       */
      foreach ($target_users as $target_uid) {
        if ($target_uid != $lastuser) {
          $query = db_query("SELECT name,mail FROM {users} WHERE uid=:uid", array(':uid' => $target_uid));
          $rec = $query->fetchObject();
          if (!empty($rec->mail)) {
            $distribution[] = $rec->mail;
            $sql = "INSERT INTO {filedepot_notificationlog} (target_uid,submitter_uid,notification_type,fid,cid,datetime) "
            . "VALUES (:tuid,:uid,:type,:id,:cid,:time )";
            db_query($sql, array(':tuid' => $target_uid, ':uid' => $submitter, ':type' => $type, ':id' => $id, ':cid' => $cid, ':time' => time()));
          }
          $lastuser = $target_uid;
        }
      }
      if (count($distribtion >= 1)) {
        $message['to'] = 'Filedepot Distribution';
        $message['headers']['Bcc'] = implode(',', $distribution);
        drupal_mail_send($message);
        return TRUE;
      }
      else {
        return FALSE;
      }

    }
    else {
      return FALSE;
    }

  }
  else {
    return FALSE;
  }
}



function filedepot_delTree($dir) {
  $files = glob( $dir . '*', GLOB_MARK );
  foreach ( $files as $file ) {
    if ( drupal_substr( $file, -1 ) == '/' ) {
      filedepot_delTree( $file );
    }
    else {
      @unlink( $file );
    }
  }
  if (is_dir($dir)) {
    @rmdir( $dir );
  }
}

