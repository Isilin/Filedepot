<?php
  // $Id$

  /**
  * @file
  * nexcloud.class.php
  * Tag Cloud class for the fildepot module
  */

  class nexcloud {

    public $_tagwords;
    public $_tagitems;
    public $_tagmetrics;
    public $_filter;
    public $_newtags;
    public $_activetags;            // Active search tags - don't show in tag cloud
    public $_type;
    public $_fontmultiplier = 160;  // Used as a multiplier in displaycloud() function - Increase to see a wider range of font sizes
    public $_maxclouditems = 200;
    public $_allusers = 1;          // Role Id that includes all users (including anonymous)       

    function __construct() {
      global $user;

      if (db_result(db_query("SELECT COUNT(id) FROM {nextag_words}")) < 30) $this->_fontmultiplier = 100;

      if ($user->uid > 0) {
        $this->_uid = $user->uid;
      } 
      else {
        $this->_uid = 0;
      }
    }

    /* Function added so I could isolate out the filtering logic.
    * May not need now for Drupal - but keeping it for now
    * Returns filtered tagword in lowercase
    */
    private function filtertag($tag, $dbmode=FALSE) {
      if ($dbmode) {
        return drupal_strtolower(strip_tags($tag));
      } 
      else {
        return drupal_strtolower(strip_tags($tag));
      }
    }


    /* This function can be over-loaded or extended in a module specific class to return the item perms
    * We ony are concerned about view access
    * We only show tags with their relative metric (popularity) depending on users access - so tags in restricted folders do not appear.
    * If item is restricted to a group - then we need the assigned group id returned
    *
    * @param string $itemid  - id that identifies the plugin item, example fid for a file
    * @return array          - Return needs to be an associative array with the 3 permission related values
    *                          $A['group_id','perm_members','perm_anon');
    */
    function get_itemperms($fid) {
      global $user;

      $perms = array();
      $cid = db_result(db_query("SELECT cid FROM {filedepot_files} WHERE fid=%d", $fid));
      if ($cid > 0) {  
        $groupids = implode(',', array_keys($user->og_groups));
        if (!empty($groupids)) {        
          $sql = "SELECT permid from {filedepot_access} WHERE catid=%d AND permtype='group' AND view = 1 AND permid > 0 ";
          $sql .= "AND permid in ($groupids) ";
          $query = db_query($sql, $cid);
          if ($query) {
            while ($A = db_fetch_array($query)) {
              $perms['groups'][] = $A['permid'];
            }
          }
        }                 
          
        // Determine all the roles the active user has and test for view permission.
        $roleids = implode(',', array_keys($user->roles));
        $perms['roles'][] = $this->_allusers;
        if (!empty($roleids)) {
          $sql = "SELECT permid from {filedepot_access} WHERE catid=%d AND permtype='role' AND view = 1 AND permid > 0 ";
          $sql .= "AND permid in ($roleids) ";
          $query = db_query($sql, $cid);
          if ($query) {
            while ($A = db_fetch_array($query)) {
              $perms['roles'][] = $A['permid'];
            }            
          }
        }
      }
      return $perms;
    }

    // Return an array of tagids for the passed in comma separated list of tagwords
    private function get_tagids($tagwords) {

      if (!empty($tagwords)) {
        $tagwords = explode(',', $tagwords);
        // Build a comma separated list of tagwords that we can use in a SQL statements below
        $allTagWords = array();
        foreach ($tagwords as $word) {
          $tag = "'" . addslashes($word) . "'";
          $allTagWords[] = $tag;
        }
        $tagwords = implode(',', $allTagWords);  // build a comma separated list of words

        if (!empty($tagwords)) {
          $query = db_query("SELECT id FROM {nextag_words} where tagword in ($tagwords)");
          $tagids = array();
          while ($A = db_fetch_array($query)) {
            $tagids[] = $A['id'];
          }
          return $tagids;
        } 
        else {
          return FALSE;
        }
      } 
      else {
        return FALSE;
      }
    }

    /*
    * New item being tagged or new tag being added to an item. 
    * The usage count for this tagword will be incremented by 1 - regardless of the number of roles or groups that have access to item.
    * @param string $itemid  - Item id, used to get the access permissions
    * @param array $tagids   - array of tag id's
    */
    private function add_accessmetrics($itemid, $tagids) {

      // Test that a valid array of tag id's is passed in
      if (is_array($tagids) AND count($tagids) > 0) {
        // Test that a valid item record exist
        if (db_result(db_query("SELECT count(itemid) FROM {nextag_items} WHERE type='%s' AND itemid=%d", $this->_type, $itemid)) > 0) {
          // Get item permissions to determine what rights to use for tag metrics record
          $perms = $this->get_itemperms($itemid);

          // Add any new tags
          foreach ($tagids as $id) {
            if (!empty($id)) {
              // For each role or group with view access to this item - create or update the access metric record count.
              if(count($perms['groups']) > 0 OR count($perms['roles']) > 0) {
                db_query("UPDATE {nextag_words} SET metric=metric+1 WHERE id=%d", $id);   
                foreach ($perms['groups'] as $groupid) {
                  if ($groupid > 0) {
                    $sql = "SELECT count(tagid) FROM {nextag_metrics} WHERE tagid = %d AND type = '%s' AND groupid = %d";
                    if (db_result(db_query($sql, $id, $this->_type, $groupid)) > 0) {
                      $sql  = "UPDATE {nextag_metrics} set metric=metric+1, last_updated=%d "
                      . "WHERE tagid=%d AND type='%s' and groupid=%d";
                      db_query($sql, time(), $id, $this->_type, $groupid);                                                          
                    } 
                    else {
                      $sql  = "INSERT INTO {nextag_metrics} (tagid,type,groupid,metric,last_updated) "
                      . "VALUES (%d,'%s',%d,%d,%d)";
                      db_query($sql, $id, $this->_type, $groupid, 1, time());   
                    }
                  }
                }
                foreach ($perms['roles'] as $roleid) {
                  if ($roleid > 0) {
                    $sql = "SELECT count(tagid) FROM {nextag_metrics} WHERE tagid = %d AND type = '%s' AND roleid = %d";
                    if (db_result(db_query($sql, $id, $this->_type, $roleid)) > 0) {
                      $sql  = "UPDATE {nextag_metrics} set metric=metric+1, last_updated=%d "
                      . "WHERE tagid=%d AND type='%s' and roleid=%d";
                      db_query($sql, time(), $id, $this->_type, $roleid);                                                          
                    } 
                    else {
                      $sql  = "INSERT INTO {nextag_metrics} (tagid,type,roleid,metric,last_updated) "
                      . "VALUES (%d,'%s',%d,%d,%d)";
                      db_query($sql, $id, $this->_type, $roleid, 1, time());   
                    }
                  }
                }                
              }
            }
          }
        }
      }
    }


    /*
    * Method is called when removing a tag for an item.
    * Need to decrement it's metric and update related access records
    * @param string $itemid  - Item id, used to get the access permissions
    * @param array $tagids   - array of tag id's
    */
    private function remove_accessmetrics($itemid, $tagids) {

      // Test that a valid array of tag id's is passed in
      if (is_array($tagids) AND count($tagids) > 0) {
        // Test that a valid item record exist
        if (db_result(db_query("SELECT count(itemid) FROM {nextag_items} WHERE type='%s' AND itemid=%d", $this->_type, $itemid)) > 0) {            
          // Get item permissions to determine what rights to use for tag metrics record
          $perms = $this->get_itemperms($itemid);

          // Remove if required unused tag related records for this item
          foreach ($tagids as $id) {
            if (!empty($id)) {
              // For each role or group with view access to this item - decrement and if need delete the access metric record count.
              if(count($perms['groups']) > 0 OR count($perms['roles']) > 0) {
                db_query("UPDATE {nextag_words} SET metric=metric-1 WHERE id=%d", $id);
                db_query("DELETE FROM {nextag_words} WHERE id=%d and metric=1", $id);                     
                foreach ($perms['groups'] as $groupid) {
                  // Delete the tag metric access record if metric = 1 else decrement the metric count
                  db_query("DELETE FROM {nextag_metrics} WHERE tagid=%d AND type='%s' AND groupid=%d AND metric=1", $id, $this->_type, $groupid);
                  $sql  = "UPDATE {nextag_metrics} set metric=metric-1, last_updated=%d "
                  . "WHERE tagid=%d AND type='%s' and groupid=%d";
                  db_query($sql, time(), $id, $this->_type, $groupid);
                }
                foreach ($perms['roles'] as $roleid) {
                  // Delete the tag metric access record if metric = 1 else decrement the metric count
                  db_query("DELETE FROM {nextag_metrics} WHERE tagid=%d AND type='%s' AND roleid=%d AND metric=1", $id, $this->_type, $roleid);
                  $sql  = "UPDATE {nextag_metrics} set metric=metric-1, last_updated=%d "
                  . "WHERE tagid=%d AND type='%s' and roleid=%d";
                  db_query($sql, time(), $id, $this->_type, $roleid);
                }
              }
            }
          }
        }
      }
    }



    /* Update tag metrics for an existing item.
    * Should work for all modules - adding tags or updating tags
    * @param string $itemid    - Example file ID (fid) relates to itemid in the tagitems table
    * @param string $tagwords  - Single tagword or comma separated list of tagwords.
    *                            Tagwords can be unfilterd if passed in.
    *                            The set_newtags function will filter and prepare tags for DB insertion
    */
    public function update_tags($itemid, $tagwords='') {

      if (!empty($tagwords)) {
        $this->set_newtags($tagwords);
      }

      $perms = $this->get_itemperms($itemid);
      if (count($perms['groups']) > 0 OR count($perms['roles']) > 0) {
        if (!empty($this->_newtags)) {
          // If item record does not yet exist - create it.
          if (db_result(db_query("SELECT count(itemid) FROM {nextag_items} WHERE type='%s' AND itemid=%d", $this->_type, $itemid)) == 0) {                  
            db_query("INSERT INTO {nextag_items} (itemid,type) VALUES (%d,'%s')", $itemid, $this->_type);
          }
          // Need to build list of tagid's for these tag words and if tagword does not yet exist then add it
          $tagwords = explode(',', $this->_newtags);
          $tags = array();
          foreach ($tagwords as $word) {
            $word = trim(strip_tags($word));
            $id = db_result(db_query("SELECT id FROM {nextag_words} WHERE tagword='%s'", $word));
            if (empty($id)) {
              db_query("INSERT INTO {nextag_words} (tagword,metric,last_updated) VALUES ('%s',0,%d)", $word, time());
              $id = db_result(db_query("SELECT id FROM {nextag_words} WHERE tagword='%s'", $word));
            }
            $tags[] = $id;
          }

          // Retrieve the current assigned tags to compare against new tags
          $currentTags = db_result(db_query("SELECT tags FROM {nextag_items} WHERE type='%s' AND itemid=%d", $this->_type, $itemid));
          $currentTags = explode(',', $currentTags);

          $unusedTags = array_diff($currentTags, $tags);
          $newTags = array_diff($tags, $currentTags);

          $this->remove_accessmetrics($itemid, $unusedTags);
          $this->add_accessmetrics($itemid, $newTags);

          $tagids = implode(',', $tags);
          if ($currentTags != $tags) {
            db_query("UPDATE {nextag_items} SET tags = '%s' WHERE itemid=%d", $tagids, $itemid);
          }
          return TRUE;

        } 
        else {
          $this->clear_tags($itemid);
          return TRUE;
        }
      } 
      else {
        watchdog('filedepot', 'Attempted to add tags for file (@item) but no role based folder permission defined', array('@item' => $itemid));
        return FALSE;
      }
    }


    /* Clear the tags used for this item and update tag access metrics
    * Typically called when item is deleted
    * @param string $itemid    - Example Story ID (sid) relates to itemid in the tagitems table
    */
    public function clear_tags($itemid) {
      // Retrieve the current assigned tags - these are the tags to update
      $currentTags = db_result(db_query("SELECT tags FROM {nextag_items} WHERE type='%s' AND itemid=%d", $this->_type, $itemid));
      $currentTags = explode(',', $currentTags);
      $this->remove_accessmetrics($itemid, $currentTags);
      db_query("UPDATE {nextag_items} SET tags = '' WHERE itemid = %d", $itemid);
    }



    public function set_newtags($newtags) {
      $newtags = $this->filtertag($newtags);
      if (!empty($newtags)) {
        $this->_newtags = str_replace(array("\n", ';'), ',', $newtags);
      }
    }

    public function get_newtags($dbmode=TRUE) {
      if ($dbmode) {
        return $this->filtertag($this->_newtags, TRUE);
      } 
      else {
        return $this->_newtags;
      }
    }

    public function get_itemtags($itemid) {
      $tags = '';
      $tagids = db_result(db_query("SELECT tags FROM {nextag_items} WHERE type='%s' AND itemid=%d", $this->_type, $itemid));
      if (!empty($tagids)) {
        $query = db_query("SELECT tagword FROM {nextag_words} WHERE id IN ($tagids)");
        while ($A = db_fetch_array($query)) {
          $tagwords[] = $A['tagword'];
        }
        $tags = implode(',', $tagwords);
      }
      return $tags;
    }

    /* Search the defined tagwords across all modules for any tag words matching query
    * Typically would be used in a AJAX driven lookup to populate a dropdown list dynamically as user enters tags
    *
    * @param string $query  - tag words to search on. Can be a list but only the last word will be used for search
    * @return array         - Returns an array of matching tag words
    */
    public function get_matchingtags($query) {
      $matches = array();;
      $query = drupal_strtolower(strip_tags($query));
      // User may be looking for more then 1 tag - pull of the last word in the query to search against
      $tags = explode(',', $query);
      $lookup = addslashes(array_pop($tags));
      $sql = "SELECT tagword FROM {nextag_words} WHERE tagword REGEXP '^$lookup' ORDER BY metric DESC";
      $q = db_query($sql);
      while ($A = db_fetch_array($q)) {
        $matches[] = $A['tagword'];
      }
      return $matches;
    }

    /* Return an array of item id's matching tag query */
    public function search($query) {

      $query = addslashes($query);        
      $itemids = array();
      // Get a list of Tag ID's for the tag words in the query
      $sql = "SELECT id,tagword FROM {nextag_words} ";
      $asearchtags = explode(',', stripslashes($query));
      if (count($asearchtags) > 1) {
        $sql .= "WHERE ";
        $i = 1;
        foreach ($asearchtags as $tag) {
          $tag = addslashes($tag);
          if ($i > 1) {
            $sql .= "OR tagword = '$tag' ";
          } 
          else {
            $sql .= "tagword = '$tag' ";
          }
          $i++;
        }
      } 
      else {
        $sql .= "WHERE tagword = '$query' ";
      }

      $query = db_query($sql);
      $tagids = array();
      $sql = "SELECT itemid FROM {nextag_items} WHERE type='{$this->_type}' AND ";
      $i = 1;
      while ($A = db_fetch_array($query)) {
        $tagids[] = $A['id'];
        // REGEX - search for id that is the first id or has a leading comma
        //         must then have a trailing , or be the end of the field
        if ($i > 1) {
          $sql .= "AND tags REGEXP '(^|,){$A['id']}(,|$)' ";
        } 
        else {
          $sql .= "tags REGEXP '(^|,){$A['id']}(,|$)' ";
        }
        $i++;;
      }
      if (count($tagids) > 0) {
        $this->_activetags = implode(',', $tagids);
        $query = db_query($sql);
        while ($A = db_fetch_array($query)) {
          $itemids[] = $A['itemid'];
        }
        if (count($itemids) > 0) {
          return $itemids;
        } 
        else {
          return FALSE;
        }
      } 
      else {
        return FALSE;
      }
    }

  }


  class filedepotTagCloud  extends nexcloud {

    function __construct() {
      parent::__construct();
      $this->_type = 'filedepot';
    }

  }
