<?php



function filedepot_drupal_7_entities() {
  $content = array();
  $content['type'] = array(
    'name' => 'Filedepot Folder',
    'type' => 'filedepot_folder',
    'field_name' => 'field_filedepot_folder',
    'description' => 'Folder for storing documents',
    'title_label' => 'Folder Name',
    'body_label' => 'Folder Description',
    'bundle' => 'filedepot_folder',
    'min_word_count' => 0,
    'help' => '',
    'node_options' =>
    array(
      'status' => TRUE,
      'promote' => TRUE,
      'sticky' => FALSE,
      'revision' => FALSE,
    ),
    'old_type' => 'filedepot_folder',
    'orig_type' => 'filedepot_folder',
    'module' => 'filedepot',
    'custom' => FALSE,
    'modified' => FALSE,
    'locked' => FALSE,
    'reset' => 'Reset to defaults',
    'comment' => 2,
    'comment_default_mode' => 4,
    'comment_default_order' => 1,
    'comment_default_per_page' => 50,
    'comment_controls' => 3,
    'comment_anonymous' => 0,
    'comment_subject_field' => 1,
    'comment_preview' => 1,
    'comment_form_location' => 0,
  );
  $content['filedepot_field'] = array(
    'label' => 'Folder File',
    'field_name' => 'field_filedepot_file',
    'type' => 'filefield',
    'widget_type' => 'filefield_widget',
    'change' => 'Change basic information',
    'bundle' => 'filedepot_folder',
    'weight' => '31',
    'file_extensions' => '',
    'progress_indicator' => 'bar',
    'file_path' => '',
    'max_filesize_per_file' => '',
    'max_filesize_per_node' => '',
    'description' => '',
    'required' => 0,
    'multiple' => '1',
    'list_field' => '1',
    'list_default' => 1,
    'description_field' => '0',
    'op' => 'Save field settings',
    'module' => 'filefield',
    'widget_module' => 'filefield',
    'columns' =>
    array(
      'fid' =>
      array(
        'type' => 'int',
        'not null' => FALSE,
        'views' => TRUE,
      ),
      'list' =>
      array(
        'type' => 'int',
        'size' => 'tiny',
        'not null' => FALSE,
        'views' => TRUE,
      ),
      'data' =>
      array(
        'type' => 'text',
        'serialize' => TRUE,
        'views' => TRUE,
      ),
    ),
    'display_settings' =>
    array(
      'label' =>
      array(
        'format' => 'above',
        'exclude' => 0,
      ),
      'teaser' =>
      array(
        'format' => 'default',
        'exclude' => 0,
      ),
      'full' =>
      array(
        'format' => 'default',
        'exclude' => 0,
      ),
      4 =>
      array(
        'format' => 'default',
        'exclude' => 0,
      ),
    ),
  );
  $content['extra']  = array(
    'title' => '-5',
    'body_field' => '0',
    'field_name' => 'field_filedepot_extra',
    'bundle' => 'filedepot_folder',
    'revision_information' => '20',
    'comment_settings' => '30',
    'menu' => '-2',
  );

  return $content;
}


function filedepot_install_cck_filefield() {
  
  $content = filedepot_drupal_7_entities();
//  $type = node_type_set_defaults($content['type']);
//  node_type_save($type);
//  field_create_field($content['filedepot_field']);
//  field_create_field($content['extra']);

  watchdog('filedepot', 'Completed adding CCK filefield to the Folder Content Type');
}

