<?php
// $Id$
/**
  * @file
  * activefolder.tpl.php
  */
?>
<div>
  <!-- DIV active if in a selected category view -->
  <div style="float:left;width:100%;padding-left:5px;margin-top:5px;display:<?php print $show_activefolder ?>;">
    <div class="floatleft" style="font-size:9pt;height:26px;padding-bottom:5px;display:<?php print $show_breadcrumbs ?>;"><?php print $folder_breadcrumb_links ?></div>
    <div style="float:right;width:50%"><?php print $ajaxstatus; print $ajaxactivity ?></div>    
    <div class="clearboth"></div>
    <div class="floatleft" style="padding-left:2px;padding-bottom:10px;vertical-align:top;">
      <span style="padding-left:10px;"><img src="<?php print $layout_url ?>/css/images/allfolders-16x16.png"></span>
    </div>
    <div id="activefolder_area" class="floatleft" style="padding-left:10px;padding-top:0px;">
       <?php print $active_folder_admin ?>
    </div>
    <div class="clearboth"></div>
  </div>
  <!-- DIV active if in Report Mode -->
  <div id="reportheadercontainer" style="display:<?php print $show_reportmodeheader ?>">
    <div class="pluginReportTitle floatleft" style="width:270;padding-left:2px;padding-bottom:0px;vertical-align:top;">
      <div style="float:left;padding-left:60px;white-space:nowrap;"><?php print $report_heading ?></div>
    </div>
  </div>         
</div> 