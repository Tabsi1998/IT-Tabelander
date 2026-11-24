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
 * Setup page: the texts the Werkbank PDFs print.
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

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
/** @var Conf $conf */

$langs->loadLangs(array('admin', 'ittabelanderpdf@ittabelanderpdf'));
if (!$user->admin) {
	accessforbidden();
}

$texts = array(
	'ITTABPDF_ACCEPT_TEXT' => 'WerkbankSetupAcceptText',
	'ITTABPDF_TAX_NOTICE' => 'WerkbankSetupTaxNotice',
);

$action = GETPOST('action', 'aZ09');
if ($action === 'save') {
	$error = 0;
	foreach ($texts as $const => $label) {
		if (dolibarr_set_const($db, $const, GETPOST($const, 'restricthtml'), 'chaine', 0, '', $conf->entity) <= 0) {
			$error++;
		}
	}
	setEventMessages($langs->trans($error ? 'Error' : 'SetupSaved'), null, $error ? 'errors' : 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', $langs->trans('WerkbankSetup'));
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('WerkbankSetup'), $linkback, 'title_setup');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankSetupPdf').'</td><td>'.$langs->trans('Value').'</td></tr>';
foreach ($texts as $const => $label) {
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($label).'</td><td>';
	print '<textarea name="'.$const.'" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag(getDolGlobalString($const)).'</textarea>';
	print '</td></tr>';
}
print '</table></div>';
print '<div class="opacitymedium">'.$langs->trans('WerkbankSetupModels').'</div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

llxFooter();
$db->close();
