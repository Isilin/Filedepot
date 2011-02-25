<?php

/**
 * @file
 * ajaxserver.php
 * Implementation of filedepot_ajax() - main ajax handler for the module
 */
function filedepot_dispatcher($action) {
  global $base_url, $base_path, $user, $filedepot, $nexcloud;
  include_once './' . drupal_get_path('module', 'filedepot') . '/lib-theme.php';   
  include_once './' . drupal_get_path('module', 'filedepot') . '/lib-ajaxserver.php';  
  include_once './' . drupal_get_path('module', 'filedepot') . '/lib-common.php';  

  timer_start($filedepot_timer);
  firelogmsg("AJAX Server code executing - action: $action");

  switch ($action) {

    case 'getfilelisting':
      $cid = intval($_POST['cid']);
      $reportmode = check_plain($_POST['reportmode']);
      //if (empty($reportmode)) {
      //  $filedepot->activeview = 'latestfiles';
      //} else {
        $filedepot->activeview = $reportmode;
      //}
      $filedepot->cid = $cid;
      $data = filedepotAjaxServer_getfilelisting();
      break;

    case 'getfolderlisting':
      $filedepot->ajaxBackgroundMode = TRUE;
      $cid = intval($_POST['cid']);
      $reportmode = check_plain($_POST['reportmode']);
      if ($cid > 0) {
        $filedepot->cid = $cid;
        $filedepot->activeview = $reportmode;
        $data = filedepotAjaxServer_getfilelisting();
        firelogmsg("Completed generating FileListing");
      } 
      else {
        $data = array('retcode' => 500);
      }
      break;

    case 'getleftnavigation':
      $data = filedepotAjaxServer_generateLeftSideNavigation();
      break;

    case 'getmorefiledata':
      /** Need to use XML instead of JSON format for return data.
        * It's taking up to 1500ms to interpret (eval) the JSON data into an object in the client code
        * Parsing the XML is about 10ms
        */
      
      $cid = intval($_POST['cid']);
      $level = intval($_POST['level']);
      $foldernumber = check_plain($_POST['foldernumber']);
      $filedepot->activeview = 'getmoredata';
      $filedepot->cid = $cid;            
      $filedepot->lastRenderedFolder = $cid;            
      $retval = '<result>';
      $retval .= '<retcode>200</retcode>';  
      $retval .= '<displayhtml>' . htmlspecialchars(nexdocsrv_generateFileListing($cid, $level, $foldernumber), ENT_QUOTES, 'utf-8') . '</displayhtml>';
      $retval .= '</result>';
      firelogmsg("Completed generating AJAX return data - cid: {$cid}");
      break;

    case 'getmorefolderdata':
      /* Need to use XML instead of JSON format for return data.
      It's taking up to 1500ms to interpret (eval) the JSON data into an object in the client code
      Parsing the XML is about 10ms
      */

      $cid = intval($_POST['cid']);
      $level = intval($_POST['level']);
      // Need to remove the last part of the passed in foldernumber as it's the incremental file number
      // Which we recalculate in template_preprocess_filelisting()
      $x = explode('.', check_plain($_POST['foldernumber']));
      $x2 = array_pop($x);
      $foldernumber = implode('.', $x);
      $filedepot->activeview = 'getmorefolderdata';
      $filedepot->cid = $cid;            
      $filedepot->lastRenderedFolder = $cid;        
      $retval = '<result>';
      $retval .= '<retcode>200</retcode>';
      $retval .= '<displayhtml>' . htmlspecialchars(nexdocsrv_generateFileListing($cid, $level, $foldernumber), ENT_QUOTES, 'utf-8') . '</displayhtml>';
      $retval .= '</result>';
      firelogmsg("Completed generating AJAX return data - cid: {$cid}");
      break;        


    case 'rendernewfilefolderoptions':
      $cid = intval($_POST['cid']);
      $data['displayhtml'] = theme('filedepot_newfiledialog_folderoptions', $cid);
      break;

    case 'rendernewfolderform':
      $cid = intval($_POST['cid']);
      $data['displayhtml'] = theme('filedepot_newfolderdialog', $cid);
      break;

    case 'createfolder':

      $node = (object) array(
      'uid' => $user->uid,
      'name' => $user->name,
      'type' => 'filedepot_folder',
      'title' => $_POST['catname'],
      'parentfolder' => intval($_POST['catparent']),
      'folderdesc'  => $_POST['catdesc'],
      'inherit'     => intval($_POST['catinherit'])
      );

      node_save($node);
      if ($node->nid) {
        $data['displaycid'] = $filedepot->cid;
        $data['retcode'] = 200;
      } 
      else {
        $data['errmsg'] = 'Error creating Folder';
        $data['retcode'] =  500;
      }
      break;

    case 'deletefolder':
      $data = array();
      $cid = intval($_POST['cid']);
      $query = db_query("SELECT cid,pid,nid FROM {filedepot_categories} WHERE cid=%d", $cid);
      $A = db_fetch_array($query);
      if ($cid > 0 AND $A['cid'] = $cid) {
        if ($filedepot->checkPermission($cid, 'admin')) {
          node_delete($A['nid']);
          $filedepot->cid = $A['pid'];
          // Set the new active directory to the parent folder
          $data['retcode'] =  200;
          $data['activefolder'] = theme('filedepot_activefolder');
          $data['displayhtml'] = filedepot_displayFolderListing($filedepot->cid);
          $data = filedepotAjaxServer_generateLeftSideNavigation($data);
        } 
        else {
          $data['retcode'] =  403;  // Forbidden
        }
      } 
      else {
        $data['retcode'] =  404;  // Not Found
      }
      break;

    case 'updatefolder':
      $data = filedepotAjaxServer_updateFolder();
      break;

    case 'setfolderorder':
      $cid = intval($_POST['cid']);
      $filedepot->cid = intval($_POST['listingcid']);
      if ($filedepot->checkPermission($cid, 'admin')) {
        // Check and see if any subfolders don't yet have a order value - if so correct
        $maxorder = 0;
        $pid = db_result(db_query("SELECT pid FROM {filedepot_categories} WHERE cid=%d", $cid));
        $maxquery = db_result(db_query_range("SELECT folderorder FROM {filedepot_categories} WHERE pid=%d ORDER BY folderorder ASC", array($pid), 0, 1));
        $next_folderorder = $maxorder + 10;
        $query = db_query("SELECT cid FROM {filedepot_categories} WHERE pid=%d AND folderorder = 0", $pid);
        while ($B = db_fetch_array($query))  {
          db_query("UPDATE {filedepot_categories} SET folderorder=%d WHERE cid=%d", $next_folderorder, $B['cid']);
          $next_folderorder += 10;
        }
        $itemquery = db_query("SELECT * FROM {filedepot_categories} WHERE cid=%d", $cid);
        $retval = 0;
        while ($A = db_fetch_array($itemquery)) {
          if ($_POST['direction'] == 'down') {
            $sql  = "SELECT folderorder FROM {filedepot_categories} WHERE pid=%d ";
            $sql .= "AND folderorder > %d ORDER BY folderorder ASC LIMIT 1";
            $nextorder = db_result(db_query($sql, $A['pid'], $A['folderorder']));
            if ($nextorder > $A['folderorder']) {
              $folderorder = $nextorder + 5;
            } 
            else {
              $folderorder = $A['folderorder'];
            }
            db_query("UPDATE {filedepot_categories} SET folderorder=%d WHERE cid=%d", $folderorder, $cid);
          } 
          elseif ($_POST['direction'] == 'up') {
            $sql  = "SELECT folderorder FROM {filedepot_categories} WHERE pid=%d ";
            $sql .= "AND folderorder < %d ORDER BY folderorder DESC LIMIT 1";
            $nextorder = db_result(db_query($sql, $A['pid'], $A['folderorder']));
            $folderorder = $nextorder - 5;
            if ($folderorder <= 0) $folderorder = 0;
            db_query("UPDATE {filedepot_categories} SET folderorder=%d WHERE cid=%d", $folderorder, $cid);
          }
        }

        /* Re-order any folders that may have just been moved */
        $query = db_query("SELECT cid,folderorder from {filedepot_categories} WHERE pid=%d ORDER BY folderorder", $pid);
        $folderorder = 10;
        $stepnumber = 10;
        while ($A = db_fetch_array($query)) {
          if ($folderorder != $A['folderOrder']) {
            db_query("UPDATE {filedepot_categories} SET folderorder=%d WHERE cid=%d", $folderorder, $A['cid']);
          }
          $folderorder += $stepnumber;
        }
        $data['retcode'] =  200;
        $data['displayhtml'] = filedepot_displayFolderListing($filedepot->cid);
      } 
      else {
        $data['retcode'] =  400;
      }
      break;

    case 'updatefoldersettings':

      $cid = intval($_POST['cid']);
      $notifyadd = intval($_POST['fileadded_notify']);
      $notifychange = intval($_POST['filechanged_notify']);
      if ($user->uid > 0 AND $cid >= 1) {
        // Update the personal folder notifications for user
        if (db_result(db_query("SELECT count(*) FROM {filedepot_notifications} WHERE cid=%d AND uid=%d", $cid, $user->uid)) == 0) {
          $sql  = "INSERT INTO {filedepot_notifications} (cid,cid_newfiles,cid_changes,uid,date) ";
          $sql .= "VALUES (%d,%d,%d,%d,%d)";
          db_query($sql, $cid, $notifyadd, $notifychange, $user->uid, time());
        } 
        else {
          $sql  = "UPDATE {filedepot_notifications} set cid_newfiles=%d, ";
          $sql .= "cid_changes=%d, date=%d ";
          $sql .= "WHERE uid=%d and cid=%d";
          db_query($sql, $notifyadd, $notifychange, time(), $user->uid, $cid);
        }
        $data['retcode'] = 200;
        $data['displayhtml'] = filedepot_displayFolderListing($filedepot->cid);   
      } 
      else {
        $data['retcode'] = 500;
      }

      break;           

    case 'loadfiledetails':
      $data = filedepotAjaxServer_loadFileDetails();
      break;

    case 'refreshfiledetails':
      $reportmode = check_plain($_POST['reportmode']);
      $fid = intval($_POST['id']);
      $cid = db_result(db_query("SELECT cid FROM {filedepot_files} WHERE fid=%d", $fid));
      if ($filedepot->checkPermission($cid, 'view')) {
        $data['retcode'] = 200;
        $data['fid'] = $fid;
        $data['displayhtml'] =  theme('filedepot_filedetail', $fid, $reportmode);        
      } 
      else {
        $data['retcode'] = 400;
        $data['error'] = t('Invalid access');
      }        
      break;

    case 'updatenote':
      $fid = intval($_POST['fid']);
      $version = intval($_POST['version']);
      $note = check_plain($_POST['note']);
      $reportmode = check_plain($_POST['reportmode']);      
      if ($fid > 0) {
        db_query("UPDATE {filedepot_fileversions} SET notes='%s' WHERE fid=%d and version=%d", $note, $fid, $version);
        $data['retcode'] = 200;
        $data['fid'] = $fid;
        $data['displayhtml'] = theme('filedepot_filedetail', $fid, $reportmode); 
      } 
      else {
        $data['retcode'] = 400;
      }
      break;            

    case 'getfolderperms':
      $cid = intval($_POST['cid']);
      if ($cid > 0) {
        $data['html'] = theme('filedepot_folderperms', $cid);
        $data['retcode'] = 200;
      } 
      else {
        $data['retcode'] = 404;
      }
      break;

    case 'delfolderperms':
      $id = intval($_GET['id']);
      if ($id > 0) {
        $cid = db_result(db_query("SELECT catid FROM {filedepot_access} WHERE accid=%d", $id));
        if ($filedepot->checkPermission($cid, 'admin')) {
          db_query("DELETE FROM {filedepot_access} WHERE accid=%d", $id);
          db_query("UPDATE {filedepot_usersettings} set allowable_view_folders = ''");
          $data['html'] = theme('filedepot_folderperms', $cid);
          $data['retcode'] = 200;
        } 
        else {
          $data['retcode'] = 403; // Forbidden
        }
      } 
      else {
        $data['retcode'] = 404; // Not Found
      }
      break;

    case 'addfolderperm':

      $cid = intval($_POST['catid']);
      if (!isset($_POST['cb_access'])) {
        $data['retcode'] = 204;  // No permission options selected - return 'No content' statuscode
      } 
      elseif ($filedepot->updatePerms(
      $cid,                          // Category ID
      $_POST['cb_access'],           // Array of permissions checked by user
      $_POST['selusers'],            // Array of site members
      $_POST['selroles'])            // Array of roles
      ) {
        $data['html'] = theme('filedepot_folderperms', $cid);
        $data['retcode'] = 200;
      } 
      else {
        $data['retcode'] = 403; // Forbidden
      }
      break;

    case 'savefile':
      drupal_get_messages('error', TRUE);  // Clear the message queue
      $filename  = $_POST['displayname'];
      $vernote  = $_POST['versionnote'];
      $tags  = $_POST['tags'];
      if (!isset($_POST['category']) AND isset($_POST['fid']) AND $_POST['fid'] > 0) {
        $cid = db_result(db_query("SELECT cid FROM {filedepot_files} WHERE fid=%d", $_POST['fid']));
      }
      else {
        $cid = intval($_POST['category']);         
      }
      $file = new stdClass();
      // Need to create an array format expected by the Drupal files.inc file_save_upload function
      // Designed to handle multiple file uploads - needs to be a multi-demensional array keyed on the tmp_name
      if ($cid > 0 AND is_array($_FILES['Filedata']) AND count($_FILES['Filedata']) > 0 AND isset($_FILES['Filedata']['tmp_name'])) {
        $file_exists = db_result(db_query("SELECT count(*) FROM {filedepot_files} WHERE cid=%d and fname='%s'", $cid, $_FILES['Filedata']['name']));
        if (variable_get('filedepot_allow_folder_duplicates', 1) == 0 AND $file_exists == 1) {
          $data['message'] = t('Duplicate File in this folder');
          $data['error'] = t('duplicate file');
          $data['retcode'] = 400;
        } 
        else {
          $keyname = trim($_FILES['Filedata']['tmp_name']);
          foreach ($_FILES['Filedata'] as $dataitem => $value) {
            $dataitem = drupal_strtolower(trim($dataitem));
            if (!empty($dataitem)) {
              if ($dataitem == 'size') {
                $value = intval($value);
              }
              $file->$dataitem = $value;
              $_FILES['files'][$dataitem][$keyname] = $value;
            }
          }
          $validators = array();
          $upload_direct = $filedepot->checkPermission($cid, 'upload_dir');
          $upload_moderated = $filedepot->checkPermission($cid, 'upload');
          $upload_new_versions = $filedepot->checkPermission($cid, 'upload_ver');         

          // Is this a new file or new version to an existing file
          if (intval($_POST['fid']) > 0 AND $upload_new_versions)  {    // Uploading a new version for an existing file record
            $fid = intval($_POST['fid']);
            $cid = db_result(db_query("SELECT cid FROM {filedepot_files} WHERE fid=%d", $fid));
            $file->moderated = FALSE;
            $file->folder = $cid;
            $file->fid = $fid;
            $file->vernote = $vernote;
            $file->tags = $tags;
            $validators = array();
            $file->nid = db_result(db_query("SELECT nid FROM {filedepot_categories} WHERE cid=%d", $cid));
            if ($filedepot->saveVersion($file, $validators)) {
              $data['message'] = '';
              $data['fid'] = $fid;
              $data['cid'] = $cid;
              $data['op'] = 'saveversion';
              $data['error'] = t('File successfully uploaded');
              $data['retcode'] = 200;
            } 
            else {
              $data['error'] = t('Error uploading File');
              $data['retcode'] = 500;
            }  

          } 
          elseif ($upload_direct OR $upload_moderated) {
            if (!$upload_direct AND $upload_moderated) {  // Admin's have all perms so test for users with upload moderated approval only
              $file->moderated = TRUE;
            } 
            else {       
              $file->moderated = FALSE;       
            }
            $file->title = $_POST['displayname'];
            $file->folder = intval($_POST['category']);
            $file->description = $_POST['description']; 
            $file->vernote = $vernote;
            $file->tags = $tags;               
            $file->nid = db_result(db_query("SELECT nid FROM {filedepot_categories} WHERE cid=%d", $file->folder));
            if ($filedepot->saveFile($file, $validators)) {
              if ($file->moderated) {
                $data['message'] = t('File has been submitted for approval before it will be added to folder listing') ;
              } 
              else {
                $data['message'] = '';
              }
              $data['cid'] = $cid;
              $data['op'] = 'savefile';
              $data['error'] = t('File successfully uploaded');
              $data['retcode'] = 200;
            } 
            else {
              $errors = drupal_get_messages('error');
              if (!empty($errors['error'][0])) {
                $data['message'] = $errors['error'][0];
              } 
              else {
                $data['error'] = t('Error uploading File');
              }
              $data['retcode'] = 500;
            }

          } 
          else {
            $data['error'] = t('Error uploading File - Insufficient Permissions');
            $data['retcode'] = 500;
          }
        }
      } 
      else {
        $data['error'] = t('Error uploading File');
        $data['retcode'] = 500;
      }
      break;

    case 'updatefile':
      $fid = intval($_POST['id']);
      $folder = intval($_POST['folder']);         
      $version = intval($_POST['version']);
      $filetitle  = $_POST['filetitle'];
      $description  = $_POST['description'];
      $vernote  = $_POST['version_note'];
      $approved  = check_plain($_POST['approved']);
      $tags  = $_POST['tags'];
      $data = array();
      $data['tagerror'] = '';        

      if ($_POST['cid'] == 'incoming' AND $fid > 0) {
          $filemoved = FALSE;
          $sql = "UPDATE {filedepot_import_queue} SET orig_filename='%s', description='%s',";
          $sql .= "version_note='%s' WHERE id=%d";
          db_query($sql, $filetitle, $description, $vernote, $fid);
          $data['retcode'] = 200;
          if ($folder > 0 AND $filedepot->moveIncomingFile($fid, $folder)) {
            $filemoved = TRUE;
            $filedepot->activeview = 'incoming';              
            $data = filedepotAjaxServer_generateLeftSideNavigation($data);
            $data['displayhtml'] = filedepot_displayFolderListing();             
          }

      }
      elseif ($fid > 0) {
        $filemoved = FALSE;
        if ($approved == 0) {
          $sql = "UPDATE {filedepot_filesubmissions} SET title='%s', description='%s',";
          $sql .= "version_note='%s', cid=%d, tags='%s' WHERE id=%d";
          db_query($sql, $filetitle, $description, $vernote, $folder, $tags, $fid);
          $data['cid'] = $folder;
          $data['tags'] = '';
        }
        else {
          $query = db_query("SELECT fname,cid,version FROM {filedepot_files} WHERE fid=%d", $fid);
          list ($fname, $cid, $current_version) = array_values(db_fetch_array($query));
          // Allow updating the category, title, description and image for the current version and primary file record
          if ($version == $current_version) {
            $newcid = $folder;
            db_query("UPDATE {filedepot_files} SET title='%s',description='%s',date=%d WHERE fid=%d", $filetitle, $description, time(), $fid);
            // Test if user has selected a different directory and if they have perms then move else return FALSE;
            $filemoved = $filedepot->moveFile($fid, $newcid);
            $data['cid'] = $newcid;
            unset($_POST['tags']);  // Format tags will check this to format tags in case we are doing a search which we are not in this case.
            $data['tags'] = filedepot_formatfiletags($tags);
          }
          db_query("UPDATE {filedepot_fileversions} SET notes='%s' WHERE fid=%d and version=%d", $vernote, $fid, $version);
          if ($filedepot->checkPermission($folder, 'view', 0, FALSE) AND !$nexcloud->update_tags($fid, $tags)) {
            $data['tagerror'] = t('Tags not added - Group view perms required');
          }          
        }
        $data['retcode'] = 200;
        $data['tagcloud'] = theme('filedepot_tagcloud');
      } 
      else {
        $data['retcode'] = 500;
        $data['errmsg'] = t('Invalid File');
      }
      $data['description'] = nl2br(filter_xss($description));
      $data['fid'] = $fid;
      $data['filename'] = filter_xss($filetitle);
      $data['filemoved'] = $filemoved;
      break;   

    case 'deletefile':
      $fid = intval($_POST['fid']);
      if ($user->uid > 0 AND $fid > 0) {
        $data = filedepotAjaxServer_deleteFile($fid);
      } 
      else {
        $data['retcode'] = 500;
      }
      break;

    case 'deletecheckedfiles':
      if ($user->uid > 0) {
        $data = filedepotAjaxServer_deleteCheckedFiles();
      } 
      else {
        $data['retcode'] = 500;
      }
      break;

    case 'deleteversion':
      $fid = intval($_POST['fid']);
      $version = intval($_POST['version']);
      $reportmode = check_plain($_POST['reportmode']);
      if ($fid > 0 AND $version > 0) {
        if ($filedepot->deleteVersion($fid, $version)) {
          $data['retcode'] = 200;
          $data['fid'] = $fid;
          $data['displayhtml'] = theme('filedepot_filedetail', $fid, $reportmode); 
        } 
        else {
          $data['retcode'] = 400;
        }        
      } 
      else {
        $data['retcode'] = 400;
      }
      break;      

    case 'togglefavorite':
      $id = intval($_POST['id']);
      if ($user->uid > 0 AND $id >= 1) {
        if (db_result(db_query("SELECT count(fid) FROM {filedepot_favorites} WHERE uid=%d AND fid=%d", $user->uid, $id)) > 0) {
          $data['favimgsrc'] =  base_path() . drupal_get_path('module', 'filedepot') . '/css/images/' . $filedepot->getFileIcon('favorite-off');
          db_query("DELETE FROM {filedepot_favorites} WHERE uid=%d AND fid=%d", $user->uid, $id);
        } 
        else {
          $data['favimgsrc'] =  base_path() . drupal_get_path('module', 'filedepot') . '/css/images/' . $filedepot->getFileIcon('favorite-on');
          db_query("INSERT INTO {filedepot_favorites} (uid,fid) VALUES (%d,%d)", $user->uid, $id);
        }
        $data['retcode'] = 200;
      } 
      else {
        $data['retcode'] = 400;
      }
      break;

    case 'markfavorite':
      if ($user->uid > 0 ) {
        $cid = intval($_POST['cid']);
        $reportmode = check_plain($_POST['reportmode']);
        $fileitems = check_plain($_POST['checkeditems']);
        $files = explode(',', $fileitems);
        $filedepot->cid = $cid;
        $filedepot->activeview = $reportmode;
        foreach ($files as $id) {
          if ($id > 0 AND db_result(db_query("SELECT COUNT(*) FROM {filedepot_favorites} WHERE uid=%d AND fid=%d", $user->uid, $id)) == 0) {
            db_query("INSERT INTO {filedepot_favorites} (uid,fid) VALUES (%d,%d)", $user->uid, $id);
          }
        }

        $data['retcode'] =  200;
        $data['displayhtml'] = filedepot_displayFolderListing($cid);
      }
      break;

    case 'clearfavorite':
      if ($user->uid > 0 ) {
        $cid = intval($_POST['cid']);
        $reportmode = check_plain($_POST['reportmode']);
        $fileitems = check_plain($_POST['checkeditems']);
        $files = explode(',', $fileitems);
        $filedepot->cid = $cid;
        $filedepot->activeview = $reportmode;
        foreach ($files as $id) {
          if ($id > 0 AND db_result(db_query("SELECT COUNT(*) FROM {filedepot_favorites} WHERE uid=%d AND fid=%d", $user->uid, $id)) == 1) {
            db_query("DELETE FROM {filedepot_favorites} WHERE uid=%d AND fid=%d", $user->uid, $id);
          }
        }
        $data['retcode'] =  200;
        $data['displayhtml'] = filedepot_displayFolderListing($cid);
      }
      break;

    case 'togglelock':
      $fid = intval($_POST['fid']);
      $data['error'] = '';
      $data['fid'] = $fid;
      $query = db_query("SELECT status FROM {filedepot_files} WHERE fid=%d", $fid);
      if ($query) {
        list($status) = array_values(db_fetch_array($query));
        if ($status == 1) {
          db_query("UPDATE {filedepot_files} SET status='2', status_changedby_uid=%d WHERE fid=%d", $user->uid, $fid);
          $stat_user = db_result(db_query("SELECT name FROM {users} WHERE uid=%d", $user->uid));
          $data['message'] =  'File Locked successfully';
          $data['locked_message'] = '* '. t('Locked by %name', array('%name' => $stat_user));
          $data['locked'] = TRUE;
        } 
        else {
          db_query("UPDATE {filedepot_files} SET status='1', status_changedby_uid=%d WHERE fid=%d", $user->uid, $fid);
          $data['message'] =  'File Un-Locked successfully';
          $data['locked'] = FALSE;
        }
      } 
      else {
        $data['error'] = t('Error locking file');
      }
      break;

    case 'movecheckedfiles':
      if ($user->uid > 0) {
        $data = filedepotAjaxServer_moveCheckedFiles();
      } 
      else {
        $data['retcode'] = 500;
      }
      break;


    case 'rendermoveform':
      $data['displayhtml'] = theme('filedepot_movefiles_form');
      break;
      
    case 'rendermoveincoming':
      $data['displayhtml'] = theme('filedepot_moveincoming_form');   
      break;      

    case 'togglesubscribe':
      $fid = intval($_POST['fid']);
      $cid = intval($_POST['cid']);

      $data['error'] = '';
      $data['fid'] = $fid;
      $ret = filedepotAjaxServer_updateFileSubscription($fid, 'toggle');
      if ($ret['retcode'] === TRUE) {
        $data['retcode'] = 200;
        if ($ret['subscribed'] === TRUE) {
          $data['subscribed'] = TRUE;
          $data['message'] = 'You will be notified of any new versions of this file';
          $data['notifyicon'] = "{$_CONF['site_url']}/filedepot3/images/email-green.gif";
          $data['notifymsg'] = 'Notification Enabled - Click to change';
        } 
        elseif ($ret['subscribed'] === FALSE) {
          $data['subscribed'] = FALSE;
          $data['message'] = 'You will not be notified of any new versions of this file';
          $data['notifyicon'] = "{$_CONF['site_url']}/filedepot3/images/email-regular.gif";
          $data['notifymsg'] = 'Notification Disabled - Click to change';
        }
      } 
      else {
        $data['error'] = t('Error accessing file record');
        $data['retcode'] = 404;
      }
      break;

    case 'updatenotificationsettings':
      if ($user->uid > 0) {
        if (db_result(db_query("SELECT count(uid) FROM {filedepot_usersettings} WHERE uid=%d", $user->uid)) == 0) {
          db_query("INSERT INTO {filedepot_usersettings} (uid) VALUES ( %d )", $user->uid);
        }
        $sql = "UPDATE {filedepot_usersettings} SET notify_newfile=%d,notify_changedfile=%d,allow_broadcasts=%d WHERE uid=%d";
        db_query($sql, $_POST['fileadded_notify'], $_POST['fileupdated_notify'], $_POST['admin_broadcasts'], $user->uid);
        $data['retcode'] = 200;
        $data['displayhtml'] = theme('filedepot_notifications');
      } 
      else {
        $data['retcode'] = 500;
      }
      break;

    case 'deletenotification':
      $id = intval($_POST['id']);
      if ($user->uid > 0 AND $id > 0) {
        db_query("DELETE FROM {filedepot_notifications} WHERE id=%d AND uid=%d", $id, $user->uid);
        $data['retcode'] = 200;
        $data['displayhtml'] = theme('filedepot_notifications');
      } 
      else {
        $data['retcode'] = 500;
      }
      break;

    case 'clearnotificationlog':
      db_query("DELETE FROM {filedepot_notificationlog} WHERE target_uid=%d", $user->uid);
      $data['retcode'] = 200;
      break;

    case 'multisubscribe':
      if ($user->uid > 0 ) {
        $reportmode = check_plain($_POST['reportmode']);
        $fileitems = check_plain($_POST['checkeditems']);
        $filedepot->cid = intval($_POST['cid']);
        $filedepot->activeview = check_plain($_POST['reportmode']);
        $files = explode(',', $fileitems);
        foreach ($files as $fid) {
          filedepotAjaxServer_updateFileSubscription($fid, 'add');
        }
        $folderitems = check_plain($_POST['checkedfolders']);
        $folders = explode(',', $folderitems);
        foreach ($folders as $cid) {
          if (db_result(db_query("SELECT count(id) FROM {filedepot_notifications} WHERE cid=%d AND uid=%d", $cid, $user->uid)) == 0) {
            $sql  = "INSERT INTO {filedepot_notifications} (cid,cid_newfiles,cid_changes,uid,date) ";
            $sql .= "VALUES (%d,1,1,%d,%d)";
            db_query($sql, $cid, $user->uid, time());
          }
        }
        $data['retcode'] =  200;
        $data = filedepotAjaxServer_generateLeftSideNavigation($data);
        $data['displayhtml'] = filedepot_displayFolderListing($filedepot->cid);
      } 
      else {
        $data['retcode'] = 500;
      }
      break;

    case 'autocompletetag':
      $matches = $nexcloud->get_matchingtags($_GET['query']);
      $retval = implode("\n", $matches);
      break;

    case 'refreshtagcloud':
      $data['retcode'] = 200;
      $data['tagcloud'] = theme('filedepot_tagcloud');
      break;

    case 'search':
      $query = check_plain($_POST['query']);
      if (!empty($query)) {
        $filedepot->activeview = 'search';
        $filedepot->cid = 0;        
        $data['retcode'] = 200;
        $data['displayhtml'] = filedepot_displaySearchListing($query);
        $data['header'] = theme('filedepot_header');
        $data['activefolder'] = theme('filedepot_activefolder');        
      } 
      else {
        $data['retcode'] = 400;
      }
      break;      

    case 'searchtags':
      $tags = stripslashes($_POST['tags']);
      $removetag = $_POST['removetag'];
      $current_search_tags = '';
      $filedepot->activeview = 'searchtags';
      $filedepot->cid = 0;
      if (!empty($tags)) {
        if (!empty($removetag)) {
          $removetag = stripslashes($removetag);
          $atags = explode(',', $tags);
          $key = array_search($removetag, $atags);
          if ($key !== FALSE) {
            unset($atags[$key]);
          }
          $tags = implode(',', $atags);
          $_POST['tags'] = $tags;
        } 
        else {
          $removetag = '';
        }
        if (!empty($tags)) {
          $data['searchtags'] = stripslashes($tags);
          $atags = explode(',', $tags);
          if (count($atags) >= 1) {
            foreach ($atags as $tag) {
              $tag = trim($tag);  // added to handle extra space thats added when removing a tag - thats between 2 other tags
              if (!empty($tag)) {
                $current_search_tags .= theme('filedepot_searchtag', addslashes($tag), check_plain($tag));
              }
            }
          }
          $data['retcode'] =  200;
          $data['currentsearchtags'] = $current_search_tags;
          $data['displayhtml'] = filedepot_displayTagSearchListing($tags);
          $data['tagcloud'] = theme('filedepot_tagcloud');
          $data['header'] = theme('filedepot_header');
          $data['activefolder'] = theme('filedepot_activefolder');
        } 
        else {
          unset($_POST['tags']);
          $filedepot->activeview = 'latestfiles';
          $data['retcode'] =  200;
          $data['currentsearchtags'] = '';
          $data['tagcloud'] = theme('filedepot_tagcloud');
          $data['displayhtml'] = filedepot_displayFolderListing($filedepot->cid);
          $data['header'] = theme('filedepot_header');
          $data['activefolder'] = theme('filedepot_activefolder');
        }
      } 
      else {
        $data['tagcloud'] = theme('filedepot_tagcloud');
        $data['retcode'] =  203;    // Partial Information
      }
      break;

    case 'approvefile':
      $id = intval($_POST['id']);
      if ($user->uid > 0 AND $filedepot->approveFileSubmission($id)) {
        $filedepot->cid = 0;
        $filedepot->activeview = 'approvals'; 
        $data = filedepotAjaxServer_getfilelisting();        
        $data = filedepotAjaxServer_generateLeftSideNavigation($data);
        $data['retcode'] = 200;
      } 
      else {
        $data['retcode'] = 400;
      }
      break;      

    case 'approvesubmissions':
      if ($user->uid > 0 ) {
        $reportmode = check_plain($_POST['reportmode']);
        $fileitems = check_plain($_POST['checkeditems']);
        $files = explode(',', $fileitems);
        $approved_files = 0;
        $filedepot->activeview = 'approvals';         
        foreach ($files as $id) {
          // Check if this is a valid submission record
          if ($id > 0 AND db_result(db_query("SELECT COUNT(*) FROM {filedepot_filesubmissions} WHERE id=%d", $id)) == 1) {
            // Verify that user has Admin Access to approve this file
            $cid = db_result(db_query("SELECT cid FROM {filedepot_filesubmissions} WHERE id=%d", $id));
            if ($cid > 0 AND $filedepot->checkPermission($cid, array('admin', 'approval'), 0, FALSE)) {
              if ($filedepot->approveFileSubmission($id)) {
                $approved_files++;
              }
            }
          }
        }
        if ($approved_files > 0) {
          $data['retcode'] =  200;
          $data = filedepotAjaxServer_generateLeftSideNavigation($data);
          $data['displayhtml'] = filedepot_displayFolderListing();                      
        } 
        else {
          $data['retcode'] =  400;
        }

      }
      break;
      
    case 'deletesubmissions':
      if ($user->uid > 0 ) {
        $reportmode = check_plain($_POST['reportmode']);
        $fileitems = check_plain($_POST['checkeditems']);
        $files = explode(',', $fileitems);            
        $deleted_files = 0;
        $filedepot->activeview = 'approvals';
        foreach ($files as $id) {          
          // Check if this is a valid submission record
          if ($id > 0 AND db_result(db_query("SELECT COUNT(*) FROM {filedepot_filesubmissions} WHERE id=%d", $id)) == 1) {   
            // Verify that user has Admin Access to approve this file
            $cid = db_result(db_query("SELECT cid FROM {filedepot_filesubmissions} WHERE id=%d", $id));
            if ($cid > 0 AND $filedepot->checkPermission($cid, array('admin', 'approval'), 0, FALSE)) {
              if ($filedepot->deleteSubmission($id)) {              
                $deleted_files++;
              }
            }
          }
        }
        if ($deleted_files > 0) {
          $data['retcode'] =  200;
          $data = filedepotAjaxServer_generateLeftSideNavigation($data);
          $data['displayhtml'] = filedepot_displayFolderListing();            
        } 
        else {
          $data['retcode'] =  400;
        }

      }
      break;
      
    case 'deleteincomingfile':
        $id = intval($_POST['id']);
        $message = '';
        $cckfid = db_result(db_query("SELECT cckfid FROM {filedepot_import_queue} WHERE id=%d", $id));
        if ( $cckfid > 0) {
            $filepath = db_result(db_query("SELECT filepath FROM {files} WHERE fid=%d", $cckfid));
            if (!empty($filepath) AND file_exists($filepath)) {
                @unlink($filepath);
            }
            db_query("DELETE FROM {files} WHERE fid=%d", $cckfid);
            db_query("DELETE FROM {filedepot_import_queue} WHERE id=%d", $id);
            $data['retcode'] = 200;
            $filedepot->activeview = 'incoming'; 
            $data = filedepotAjaxServer_generateLeftSideNavigation($data);
          $data['displayhtml'] = filedepot_displayFolderListing(); 
        } 
        else {
            $data['retcode'] = 500;
        }

        $retval = json_encode($data);
        break;
        
    case 'moveincomingfile':
      $newcid = intval($_POST['newcid']);   
      $id = intval($_POST['id']);
      $filedepot->activeview = 'incoming';
      $data = array();
      if ($newcid > 0 AND $id > 0 AND $filedepot->moveIncomingFile($id, $newcid)) {
        // Send out email notifications of new file added to all users subscribed  -  Get fileid for the new file record
        $args = array($newcid, $user->uid);
        $fid = db_result(db_query("SELECT fid FROM {filedepot_files} WHERE cid=%d AND submitter=%d ORDER BY fid DESC", $args, 0, 1));
        filedepot_sendNotification($fid, FILEDEPOT_NOTIFY_NEWFILE);
        $data['retcode'] =  200;
        $data = filedepotAjaxServer_generateLeftSideNavigation($data);
        $data['displayhtml'] = filedepot_displayFolderListing();  
      } 
      else {
        $data['retcode'] =  500; 
      }
      break;
    
    case 'broadcastalert':
      $data = array();
      if (variable_get('filedepot_notifications_enabled', 1) == 0) { 
        $data['retcode'] = 204;
      } 
      else {
        $fid = intval($_POST['fid']);
        $message = check_plain($_POST['message']);
        if (!empty($message) AND $fid > 0) {
          $data = filedepotAjaxServer_broadcastAlert($fid, $message);
        } 
        else {
          $data['retcode'] = 500;
        }
      }
      break;

  }

  if ($action != 'autocompletetag') {
    if ($action != 'getmorefiledata' AND $action != 'getmorefolderdata') {
      $retval = json_encode($data);
    }
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('content-type: application/xml', TRUE);
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n";
  }
  echo $retval;

}
