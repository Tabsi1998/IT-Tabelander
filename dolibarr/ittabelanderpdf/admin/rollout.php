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
 * Setup tab "Texte außerhalb des Moduls": the Mahnwesen templates and the
 * ticket introduction in this design, compared with what Dolibarr has, and
 * written there after confirmation (old values kept in a backup file).
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
dol_include_once('/ittabelanderpdf/class/werkbankrollout.class.php');

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
/** @var Societe $mysoc */

$langs->loadLangs(array('admin', 'ittabelanderpdf@ittabelanderpdf'));
if (!$user->admin) {
	accessforbidden();
}
$action = GETPOST('action', 'aZ09');
$self = $_SERVER['PHP_SELF'];

if ($action === 'confirm_rollout' && GETPOST('confirm', 'alpha') === 'yes') {
	$targets = array_filter(explode(',', GETPOST('targets', 'alphanohtml')));
	$result = WerkbankRollout::apply($db, $mysoc, $targets, $user);
	if (is_array($result)) {
		setEventMessages($langs->trans('WerkbankRolloutDone', $result['written'], $result['backup']), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$self);
	exit;
}

$plan = WerkbankRollout::plan($db, $mysoc);
$form = new Form($db);
llxHeader('', $langs->trans('WerkbankSetup'));
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('WerkbankSetup'), $linkback, 'title_setup');
print dol_get_fiche_head(ittabpdf_admin_prepare_head(), 'rollout', '', -1, '');

if ($action === 'askrollout') {
	$targets = array();
	foreach ($plan as $key => $entry) {
		if ($entry['found'] && $entry['current'] !== $entry['new'] && GETPOST('t_'.md5($key), 'int')) {
			$targets[] = $key;
		}
	}
	if ($targets) {
		print $form->formconfirm($self.'?targets='.urlencode(implode(',', $targets)), $langs->trans('WerkbankRolloutConfirmTitle'),
			$langs->trans('WerkbankRolloutConfirm', count($targets)), 'confirm_rollout', '', 'yes', 1);
	}
}

print '<div class="opacitymedium">'.$langs->trans('WerkbankRolloutHelp').'</div><br>';
print '<form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="askrollout">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td></td><td>'.$langs->trans('WerkbankRolloutTarget').'</td><td>'.$langs->trans('Status').'</td><td class="right">'.$langs->trans('WerkbankRolloutPreview').'</td></tr>';
foreach ($plan as $key => $entry) {
	$same = $entry['current'] === $entry['new'] && $entry['subject'] === $entry['newsubject'];
	$state = !$entry['found'] ? array('WerkbankRolloutMissing', 'status0') : ($same ? array('WerkbankRolloutSame', 'status4') : array('WerkbankRolloutDiffers', 'status1'));
	print '<tr class="oddeven"><td class="width25">'.($entry['found'] && !$same ? '<input type="checkbox" name="t_'.md5($key).'" value="1" checked>' : '').'</td>';
	print '<td>'.dol_escape_htmltag($entry['label']).($entry['newsubject'] !== '' ? '<br><span class="opacitymedium small">'.dol_escape_htmltag($entry['newsubject']).'</span>' : '').'</td>';
	print '<td>'.dolGetStatus($langs->trans($state[0]), '', '', $state[1], 5).'</td>';
	print '<td class="right">'.($entry['new'] !== '' ? '<a href="#preview-'.md5($key).'">'.$langs->trans('WerkbankRolloutPreview').'</a>' : '<span class="opacitymedium">'.$langs->trans('WerkbankRolloutEmpty').'</span>').'</td></tr>';
}
print '</table></div>';
print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('WerkbankRolloutApply')).'"></div></form>';

// Previews: the new text as it will look (placeholders of Mahnwesen stay visible).
foreach ($plan as $key => $entry) {
	if ($entry['new'] === '') {
		continue;
	}
	print '<a id="preview-'.md5($key).'"></a>'.load_fiche_titre(dol_escape_htmltag($entry['label']), '', '');
	print '<div class="border" style="padding: 12px; max-width: 640px; background: #fff;">'.dol_htmlwithnojs(str_replace(WerkbankRollout::MARKER, '<hr>', $entry['new']), 1, 'restricthtml').'</div>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
