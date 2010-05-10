<?php
// $Id$
/**
  * @file
  * folderperms.tpl.php
  */
?>  

<div style="margin:2px;border:1px solid #CCC">
<form name="frmFolderPerms" method="POST">
<input type="hidden" name="op" value="">

<table width="724" border="0" cellspacing="0" cellpadding="2" style="margin:0px;">
  <tr bgcolor="#BBBECE">
    <td width="1%">&nbsp;</td>
    <td width="15%">&nbsp;<strong><?php print $LANG_selectusers ?></strong></td>
    <td width="5%">&nbsp;</td>
    <td width="15%">&nbsp;<strong><?php print $LANG_selectroles ?></strong></td>
    <td width="1%">&nbsp;</td>
    <td colspan="4" width="60%" align="center">&nbsp;<strong><?php print $LANG_accessrights ?></strong></td>
  </tr>
  <tr><td colspan="10"><img src="" height="5"></td></tr>
  <tr>
    <td>&nbsp;</td>
    <td rowspan="3"><select name="selusers[]" multiple size=10 class="form-select" style="width:150px;"><?php print $user_options ?></select></td>
    <td rowspan="3">&nbsp;</td>
    <td rowspan="3"><select name="selroles[]" multiple size=10 class="form-select" style="width:150px;"><?php print $role_options ?></select></td>
    <td>&nbsp;</td>
    <td>
      <input type="checkbox" name="cb_access[]" value="view" id="feature1"></td>
    <td><label for="feature1"><?php print $LANG_viewcategory ?></label></td>
    <td><input type="checkbox" name="cb_access[]" value="upload"  id="feature2"></td>
    <td><label for="feature2"><?php print $LANG_uploadapproval ?></label></td>
  </tr>
  <tr>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
    <td><input type="checkbox" name="cb_access[]" value="approval" id="feature3"> </td>
    <td><label for="feature3"><?php print $LANG_uploadadmin ?></label></td>
    <td><input type="checkbox" name="cb_access[]" value="upload_direct" id="feature4"></td>
    <td><label for="feature4"><?php print $LANG_uploaddirect ?></label></td>
  </tr>
  <tr>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
    <td><input type="checkbox" name="cb_access[]" value="admin" id="feature5"></td>
    <td><label for="feature5"><?php print $LANG_categoryadmin ?></label></td>
    <td><input type="checkbox" name="cb_access[]" value="upload_ver" id="feature6"></td>
    <td><label for="feature6"><?php print $LANG_uploadversions ?></label></td>
  </tr>
  <tr>
    <td colspan="9" style="padding-left:450px;padding-top:10px;">
      <input type="button" name="submit" class="form-submit" value="<?php print t('Submit'); ?>" onclick="makeAJAXUpdateFolderPerms(this.form);">
      <span style="padding-left:10px;"><input id="folderperms_cancel" type="button" class="form-submit" value="<?php print t('Close'); ?> "></span>
      <input type="hidden" name="catid" value="<?php print $catid ?>"></td>
  </tr>
</table>

<table border="0" cellpadding="5" cellspacing="1" width="724" style="margin-top:10px;">
  <tr>
    <td colspan="9" width="100%" style="font-weight:bold;background-color:#BBBECE;font-size:2;vertical-align:top;padding:2px;"><?php print $LANG_userrecords ?></td>
  </tr>
  <tr style="font-weight:bold;background-color:#ECE9D8;text-align:center;vertical-align:top;">
    <td align="left"><?php print $LANG_user ?></td>
    <td><?php print $LANG_view ?></td>
    <td><?php print $LANG_uploadwithapproval ?></td>
    <td><?php print $LANG_directupload ?></td>
    <td><?php print $LANG_uploadversions ?></td>
    <td><?php print $LANG_uploadadmin ?></td>
    <td><?php print $LANG_admin ?></td>
    <td><?php print $LANG_action ?></td>
  </tr>
  <?php print $user_perm_records ?>
</table>
<table border="0" cellpadding="5" cellspacing="1" width="724" style="margin-top:20px;margin-bottom:10px;">
  <tr>
    <td colspan="9" width="100%" style="font-weight:bold;background-color:#BBBECE;font-size:2;vertical-align:top;padding:2px;"><?php print $LANG_rolerecords ?></td>
  </tr>
  <tr style="font-weight:bold;background-color:#ECE9D8;text-align:center;vertical-align:top;">
    <td align="left"><?php print $LANG_user ?></td>
    <td><?php print $LANG_view ?></td>
    <td><?php print $LANG_uploadwithapproval ?></td>
    <td><?php print $LANG_directupload ?></td>
    <td><?php print $LANG_uploadversions ?></td>
    <td><?php print $LANG_uploadadmin ?></td>
    <td><?php print $LANG_admin ?></td>
    <td><?php print $LANG_action ?></td>
  </tr>
  <?php print $role_perm_records ?>
</table>
</form>
</div>
