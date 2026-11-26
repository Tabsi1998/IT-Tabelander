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
 * Workshop setup: notices of the device intake slip, QS options, kinds of job
 * with their checklists (add, change, sort, switch off; nothing is deleted,
 * protocols keep their own copy).
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
dol_include_once('/ittabelanderpdf/lib/ittabelanderpdf.lib.php');
dol_include_once('/ittabelanderpdf/class/werkbankqs.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankbuilder.intake.class.php');

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
/** @var Conf $conf */

$langs->loadLangs(array('admin', 'ittabelanderpdf@ittabelanderpdf'));
$manage = $user->admin || $user->hasRight('ittabelanderpdf', 'qs', 'manage');
if (!$manage) {
	accessforbidden();
}
$qs = new WerkbankQs($db);
$action = GETPOST('action', 'aZ09');
$typeId = GETPOSTINT('type');
$self = $_SERVER['PHP_SELF'];

if ($action === 'save_options' && $user->admin) {
	$error = 0;
	foreach (array('ITTABPDF_INTAKE_NOTICES' => WerkbankBuilderIntake::DEFAULT_NOTICES, 'ITTABPDF_INTAKE_NOTICES_DU' => WerkbankBuilderIntake::DEFAULT_NOTICES_DU) as $const => $default) {
		// Empty = back to the shipped text.
		$notices = trim(GETPOST($const, 'restricthtml'));
		$error += dolibarr_set_const($db, $const, $notices !== '' ? $notices : $default, 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	}
	foreach (array('ITTABPDF_INTAKE_PASSWORD_FIELD', 'ITTABPDF_QS_BLOCK_CLOSE', 'ITTABPDF_QS_APPEND_WORKREPORT') as $const) {
		$error += dolibarr_set_const($db, $const, GETPOST($const) ? '1' : '0', 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	}
	setEventMessages($langs->trans($error ? 'Error' : 'SetupSaved'), null, $error ? 'errors' : 'mesgs');
	header('Location: '.$self);
	exit;
}
if ($action === 'save_types') {
	$error = 0;
	foreach ($qs->types() as $type) {
		$id = (int) $type->rowid;
		if (!GETPOSTISSET('label_'.$id)) {
			continue;
		}
		$signers = implode(',', array_keys(array_filter(array('technician' => GETPOST('signtech_'.$id), 'customer' => GETPOST('signcust_'.$id)))));
		$error += $qs->saveType($id, array('code' => $type->code, 'label' => GETPOST('label_'.$id, 'alphanohtml'), 'position' => GETPOSTINT('position_'.$id),
			'active' => GETPOST('active_'.$id), 'signers' => $signers, 'notice' => GETPOST('notice_'.$id, 'alphanohtml'))) > 0 ? 0 : 1;
	}
	$label = trim(GETPOST('label_new', 'alphanohtml'));
	if ($label !== '') {
		$code = trim(GETPOST('code_new', 'alphanohtml'));
		$error += $qs->saveType(0, array('code' => $code !== '' ? $code : dol_string_unaccent($label), 'label' => $label, 'position' => GETPOSTINT('position_new'),
			'active' => 1, 'signers' => implode(',', WerkbankQs::SIGNERS), 'notice' => '')) > 0 ? 0 : 1;
	}
	setEventMessages($error ? $langs->trans('WerkbankQsTypeError', $qs->error) : $langs->trans('SetupSaved'), null, $error ? 'errors' : 'mesgs');
	header('Location: '.$self.'#types');
	exit;
}
$type = $typeId > 0 ? $qs->type($typeId) : null;
if ($action === 'save_items' && $type) {
	$error = 0;
	foreach ($qs->items($typeId) as $item) {
		$id = (int) $item->rowid;
		if (!GETPOSTISSET('label_'.$id)) {
			continue;
		}
		$error += $qs->saveItem($id, $typeId, array('position' => GETPOSTINT('position_'.$id), 'label' => GETPOST('label_'.$id, 'alphanohtml'),
			'kind' => GETPOST('kind_'.$id, 'aZ09'), 'unit' => GETPOST('unit_'.$id, 'alphanohtml'), 'required' => GETPOST('required_'.$id),
			'help' => GETPOST('help_'.$id, 'alphanohtml'), 'active' => GETPOST('active_'.$id))) > 0 ? 0 : 1;
	}
	if (trim(GETPOST('label_new', 'alphanohtml')) !== '') {
		$error += $qs->saveItem(0, $typeId, array('position' => GETPOSTINT('position_new'), 'label' => GETPOST('label_new', 'alphanohtml'),
			'kind' => GETPOST('kind_new', 'aZ09'), 'unit' => GETPOST('unit_new', 'alphanohtml'), 'required' => GETPOST('required_new'),
			'help' => GETPOST('help_new', 'alphanohtml'), 'active' => 1)) > 0 ? 0 : 1;
	}
	setEventMessages($langs->trans($error ? 'Error' : 'SetupSaved'), null, $error ? 'errors' : 'mesgs');
	header('Location: '.$self.'?type='.$typeId);
	exit;
}

llxHeader('', $langs->trans('WerkbankSetup'));
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('WerkbankSetup'), $user->admin ? $linkback : '', 'title_setup');
print dol_get_fiche_head(ittabpdf_admin_prepare_head(), 'workshop', '', -1, '');

$kinds = array('check' => $langs->trans('WerkbankQsKindCheck'), 'value' => $langs->trans('WerkbankQsKindValue'), 'text' => $langs->trans('WerkbankQsKindText'));

if ($type) {
	// One checklist: its points.
	print load_fiche_titre($langs->trans('WerkbankQsItemsOf', $type->label), '<a href="'.$self.'#types">'.$langs->trans('Back').'</a>', '');
	print '<form method="POST" action="'.$self.'?type='.$typeId.'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save_items">';
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="width50">'.$langs->trans('Position').'</td><td>'.$langs->trans('WerkbankQsItemLabel').'</td><td>'.$langs->trans('Type').'</td>'
		.'<td class="width75">'.$langs->trans('Unit').'</td><td class="center">'.$langs->trans('WerkbankQsRequired').'</td><td>'.$langs->trans('WerkbankQsItemHelp').'</td><td class="center">'.$langs->trans('Active').'</td></tr>';
	$rows = $qs->items($typeId);
	$next = 10;
	foreach ($rows as $item) {
		$id = (int) $item->rowid;
		$next = max($next, (int) $item->position + 10);
		print '<tr class="oddeven'.((int) $item->active ? '' : ' opacitymedium').'">';
		print '<td><input type="number" name="position_'.$id.'" class="width50" value="'.((int) $item->position).'"></td>';
		print '<td><input type="text" name="label_'.$id.'" class="minwidth300 widthcentpercentminusx" value="'.dol_escape_htmltag($item->label).'"></td>';
		print '<td>'.Form::selectarray('kind_'.$id, $kinds, $item->kind).'</td>';
		print '<td><input type="text" name="unit_'.$id.'" class="width50" value="'.dol_escape_htmltag((string) $item->unit).'"></td>';
		print '<td class="center"><input type="checkbox" name="required_'.$id.'" value="1"'.((int) $item->required ? ' checked' : '').'></td>';
		print '<td><input type="text" name="help_'.$id.'" class="minwidth200" value="'.dol_escape_htmltag((string) $item->help).'"></td>';
		print '<td class="center"><input type="checkbox" name="active_'.$id.'" value="1"'.((int) $item->active ? ' checked' : '').'></td></tr>';
	}
	print '<tr class="oddeven"><td><input type="number" name="position_new" class="width50" value="'.$next.'"></td>';
	print '<td><input type="text" name="label_new" class="minwidth300 widthcentpercentminusx" placeholder="'.dol_escape_htmltag($langs->trans('WerkbankQsNewItem')).'"></td>';
	print '<td>'.Form::selectarray('kind_new', $kinds, 'check').'</td><td><input type="text" name="unit_new" class="width50"></td>';
	print '<td class="center"><input type="checkbox" name="required_new" value="1"></td><td><input type="text" name="help_new" class="minwidth200"></td><td></td></tr>';
	print '</table></div>';
	print '<div class="opacitymedium">'.$langs->trans('WerkbankQsItemsHelp').'</div>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div></form>';
} else {
	if ($user->admin) {
		print '<form method="POST" action="'.$self.'">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save_options">';
		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
		print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankIntakeTitle').'</td><td>'.$langs->trans('Value').'</td></tr>';
		foreach (array('ITTABPDF_INTAKE_NOTICES' => array('WerkbankIntakeNoticesSie', WerkbankBuilderIntake::DEFAULT_NOTICES),
			'ITTABPDF_INTAKE_NOTICES_DU' => array('WerkbankIntakeNoticesDu', WerkbankBuilderIntake::DEFAULT_NOTICES_DU)) as $const => $setting) {
			print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($setting[0]).'<div class="opacitymedium small">'.$langs->trans('WerkbankIntakeNoticesHelp').'</div></td><td>';
			print '<textarea name="'.$const.'" class="quatrevingtpercent" rows="8">'.dol_escape_htmltag(getDolGlobalString($const, $setting[1]), 0, 1).'</textarea></td></tr>';
		}
		print '<tr class="oddeven"><td>'.$langs->trans('WerkbankIntakePasswordField').'</td><td><input type="checkbox" name="ITTABPDF_INTAKE_PASSWORD_FIELD" value="1"'.(getDolGlobalInt('ITTABPDF_INTAKE_PASSWORD_FIELD', 1) ? ' checked' : '').'> '.$langs->trans('WerkbankIntakePasswordFieldHelp').'</td></tr>';
		print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankQsMenu').'</td><td></td></tr>';
		print '<tr class="oddeven"><td>'.$langs->trans('WerkbankQsBlockClose').'</td><td><input type="checkbox" name="ITTABPDF_QS_BLOCK_CLOSE" value="1"'.(getDolGlobalInt('ITTABPDF_QS_BLOCK_CLOSE', 1) ? ' checked' : '').'> '.$langs->trans('WerkbankQsBlockCloseHelp').'</td></tr>';
		print '<tr class="oddeven"><td>'.$langs->trans('WerkbankQsAppendReport').'</td><td><input type="checkbox" name="ITTABPDF_QS_APPEND_WORKREPORT" value="1"'.(getDolGlobalInt('ITTABPDF_QS_APPEND_WORKREPORT') ? ' checked' : '').'> '.$langs->trans('WerkbankQsAppendReportHelp').'</td></tr>';
		print '</table></div>';
		print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div></form><br>';
	}

	// Kinds of job.
	print '<a id="types"></a>'.load_fiche_titre($langs->trans('WerkbankQsTypes'), '', '');
	print '<form method="POST" action="'.$self.'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save_types">';
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="width50">'.$langs->trans('Position').'</td><td>'.$langs->trans('Label').'</td><td>'.$langs->trans('WerkbankQsCode').'</td>'
		.'<td>'.$langs->trans('WerkbankQsSigners').'</td><td>'.$langs->trans('WerkbankQsTypeNotice').'</td><td class="center">'.$langs->trans('Active').'</td><td class="right">'.$langs->trans('WerkbankQsPoints').'</td></tr>';
	$next = 10;
	foreach ($qs->types() as $row) {
		$id = (int) $row->rowid;
		$next = max($next, (int) $row->position + 10);
		$signers = explode(',', (string) $row->signers);
		print '<tr class="oddeven'.((int) $row->active ? '' : ' opacitymedium').'">';
		print '<td><input type="number" name="position_'.$id.'" class="width50" value="'.((int) $row->position).'"></td>';
		print '<td><input type="text" name="label_'.$id.'" class="minwidth200" value="'.dol_escape_htmltag($row->label).'"></td>';
		print '<td class="opacitymedium">'.dol_escape_htmltag($row->code).'</td>';
		print '<td class="nowraponall"><label><input type="checkbox" name="signtech_'.$id.'" value="1"'.(in_array('technician', $signers, true) ? ' checked' : '').'> '.$langs->trans('WerkbankQsSignerTechnician').'</label><br>';
		print '<label><input type="checkbox" name="signcust_'.$id.'" value="1"'.(in_array('customer', $signers, true) ? ' checked' : '').'> '.$langs->trans('WerkbankQsSignerCustomer').'</label></td>';
		print '<td><textarea name="notice_'.$id.'" rows="2" class="minwidth200">'.dol_escape_htmltag((string) $row->notice, 0, 1).'</textarea></td>';
		print '<td class="center"><input type="checkbox" name="active_'.$id.'" value="1"'.((int) $row->active ? ' checked' : '').'></td>';
		print '<td class="right nowraponall"><a href="'.$self.'?type='.$id.'">'.$langs->trans('WerkbankQsPointCount', count($qs->items($id, true))).' '.img_edit().'</a></td></tr>';
	}
	print '<tr class="oddeven"><td><input type="number" name="position_new" class="width50" value="'.$next.'"></td>';
	print '<td><input type="text" name="label_new" class="minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('WerkbankQsNewType')).'"></td>';
	print '<td><input type="text" name="code_new" class="width100" placeholder="'.dol_escape_htmltag($langs->trans('WerkbankQsCode')).'"></td><td colspan="4"></td></tr>';
	print '</table></div>';
	print '<div class="opacitymedium">'.$langs->trans('WerkbankQsTypesHelp').'</div>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div></form>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
