<?php
// $Id$
/**
  * @file
  * toolbar_form.tpl.php
  */
?>

<div style="float:left;width:120px;padding-left:0px;">
  <form name="frmtoolbar" action="<?php print $base_url ?>/filedepot/download.php" method="post" style="margin:0px;">
    <input type="hidden" name="checkeditems" value="">
    <input type="hidden" name="checkedfolders" value="">
    <input type="hidden" name="cid" value="<?php print $current_category ?>">
    <input type="hidden" name="newcid" value="">
    <input type="hidden" name="reportmode" value="<?php print $report_option ?>">
    <div class="floatleft" style="padding-top:5px;">
      <select id="multiaction" class="disabled_element" name="multiaction" style="width:120px;" onChange="if (checkMultiAction(this.value)) submit(); postSubmitMultiactionResetIfNeed(this.value);" disabled="disabled"></select>
    </div>
  </form>
</div>
