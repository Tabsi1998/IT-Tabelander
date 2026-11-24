<?php
/*
 * IT-Tabelander PDF - Werkbank document layout for Dolibarr
 * Copyright (C) 2026 Fabian Tabelander
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Setup page: texts of the Werkbank PDFs and the automatic e-mails.
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/ittabelanderpdf/class/werkbankmailtemplates.class.php');

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
/** @var Conf $conf */

$langs->loadLangs(array('admin', 'mails', 'ittabelanderpdf@ittabelanderpdf'));
if (!$user->admin) {
	accessforbidden();
}

$texts = array(
	'ITTABPDF_ACCEPT_TEXT' => 'WerkbankSetupAcceptText',
	'ITTABPDF_ACCEPT_TEXT_NOPORTAL' => 'WerkbankSetupAcceptTextNoPortal',
	'ITTABPDF_TAX_NOTICE' => 'WerkbankSetupTaxNotice',
);
$switches = array(
	'ITTABPDF_AUTOSEND_PROPAL' => 'WerkbankEvent_propal_validated',
	'ITTABPDF_AUTOSEND_INVOICE' => 'WerkbankEvent_invoice_validated',
	'ITTABPDF_AUTOSEND_PAID' => 'WerkbankEvent_invoice_paid',
);
$values = array(
	'ITTABPDF_AUTOSEND_DELAY' => 'WerkbankSetupDelay',
	'ITTABPDF_AUTOSEND_BCC' => 'WerkbankSetupBcc',
	'ITTABPDF_PORTAL_URL' => 'WerkbankSetupPortal',
);
// Template choice per automatic e-mail: key => type_template.
$choices = array('propal' => 'propal_send', 'invoice' => 'facture_send', 'deposit' => 'facture_send', 'creditnote' => 'facture_send', 'paid' => 'facture_send');

$action = GETPOST('action', 'aZ09');
if ($action === 'save') {
	$error = 0;
	foreach (array_keys($texts) as $const) {
		$error += dolibarr_set_const($db, $const, GETPOST($const, 'restricthtml'), 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	}
	$error += dolibarr_set_const($db, 'ITTABPDF_PORTAL_ENABLED', GETPOST('ITTABPDF_PORTAL_ENABLED') ? '1' : '0', 'yesno', 0, '', $conf->entity) > 0 ? 0 : 1;
	foreach (array_keys($switches) as $const) {
		$error += dolibarr_set_const($db, $const, GETPOST($const) ? '1' : '0', 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	}
	$error += dolibarr_set_const($db, 'ITTABPDF_AUTOSEND_DELAY', (string) max(0, GETPOSTINT('ITTABPDF_AUTOSEND_DELAY')), 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	$bcc = trim(GETPOST('ITTABPDF_AUTOSEND_BCC', 'alphanohtml'));
	if ($bcc !== '' && !isValidEmail($bcc)) {
		setEventMessages($langs->trans('ErrorBadEMail', $bcc), null, 'errors');
		$error++;
	} else {
		$error += dolibarr_set_const($db, 'ITTABPDF_AUTOSEND_BCC', $bcc, 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	}
	$error += dolibarr_set_const($db, 'ITTABPDF_PORTAL_URL', trim(GETPOST('ITTABPDF_PORTAL_URL', 'alphanohtml')), 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	foreach (array_keys($choices) as $key) {
		foreach (array('SIE', 'DU') as $form) {
			$const = 'ITTABPDF_TPL_'.strtoupper($key).'_'.$form;
			$error += dolibarr_set_const($db, $const, (string) GETPOSTINT($const), 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
		}
	}
	setEventMessages($langs->trans($error ? 'Error' : 'SetupSaved'), null, $error ? 'errors' : 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/**
 * The active templates of one type as a select, 0 = the module's own.
 *
 * @param DoliDB $db    Database
 * @param string $name  Field name
 * @param string $type  type_template
 * @param int    $value Selected row id
 * @return string
 */
function ittabpdf_template_select($db, $name, $type, $value)
{
	global $conf, $langs;
	$html = '<select name="'.$name.'" class="minwidth300"><option value="0">'.dol_escape_htmltag($langs->trans('WerkbankSetupTemplateDefault')).'</option>';
	$sql = "SELECT rowid, label FROM ".MAIN_DB_PREFIX."c_email_templates WHERE type_template = '".$db->escape($type)."' AND active = 1"
		." AND entity IN (0, ".((int) $conf->entity).") ORDER BY position, label";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$html .= '<option value="'.((int) $obj->rowid).'"'.((int) $obj->rowid === (int) $value ? ' selected' : '').'>'.dol_escape_htmltag($obj->label).'</option>';
	}
	return $html.'</select>';
}

llxHeader('', $langs->trans('WerkbankSetup'));
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('WerkbankSetup'), $linkback, 'title_setup');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankSetupPdf').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('WerkbankSetupPortalEnabled').'</td><td>';
print '<input type="checkbox" name="ITTABPDF_PORTAL_ENABLED" value="1"'.(getDolGlobalInt('ITTABPDF_PORTAL_ENABLED') ? ' checked' : '').'> '.$langs->trans('WerkbankSetupPortalEnabledHelp');
print '</td></tr>';
foreach ($texts as $const => $label) {
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($label).'</td><td>';
	print '<textarea name="'.$const.'" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag(getDolGlobalString($const)).'</textarea>';
	print '</td></tr>';
}
print '</table></div>';
print '<div class="opacitymedium">'.$langs->trans('WerkbankSetupModels').'</div><br>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankAutoTitle').'</td><td>'.$langs->trans('Value').'</td></tr>';
foreach ($switches as $const => $label) {
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($label).'</td><td>';
	print '<input type="checkbox" name="'.$const.'" value="1"'.(getDolGlobalInt($const) ? ' checked' : '').'> '.$langs->trans('WerkbankSetupSendAuto');
	print '</td></tr>';
}
print '<tr class="oddeven"><td>'.$langs->trans('WerkbankSetupDelay').'</td><td><input type="number" min="0" name="ITTABPDF_AUTOSEND_DELAY" class="width75" value="'.getDolGlobalInt('ITTABPDF_AUTOSEND_DELAY', 5).'"> '.$langs->trans('Minutes').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('WerkbankSetupBcc').'</td><td><input type="text" name="ITTABPDF_AUTOSEND_BCC" class="minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('ITTABPDF_AUTOSEND_BCC')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('WerkbankSetupPortal').'</td><td><input type="text" name="ITTABPDF_PORTAL_URL" class="minwidth400" value="'.dol_escape_htmltag(getDolGlobalString('ITTABPDF_PORTAL_URL', 'https://it.tabelander.co.at/kundenbereich')).'"></td></tr>';
foreach ($choices as $key => $type) {
	foreach (array('SIE' => 'Sie', 'DU' => 'Du') as $form => $formLabel) {
		$const = 'ITTABPDF_TPL_'.strtoupper($key).'_'.$form;
		print '<tr class="oddeven"><td>'.$langs->trans('WerkbankSetupTemplate', $langs->transnoentities('WerkbankTpl_'.$key), $formLabel).'</td><td>';
		print ittabpdf_template_select($db, $const, $type, getDolGlobalInt($const));
		print '</td></tr>';
	}
}
print '</table></div>';
print '<div class="opacitymedium">'.$langs->trans('WerkbankSetupAutoHelp').'</div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

llxFooter();
$db->close();
