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
 * Setup tab "Systemcheck": what the module needs in Dolibarr, each with its
 * state, what it means and where to fix it (WerkbankSystemCheck).
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
dol_include_once('/ittabelanderpdf/class/werkbanksystemcheck.class.php');

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

$langs->loadLangs(array('admin', 'ittabelanderpdf@ittabelanderpdf'));
if (!$user->admin) {
	accessforbidden();
}

$check = new WerkbankSystemCheck($db);
$items = $check->run();
$counts = array_count_values(array_column($items, 'state'));

llxHeader('', $langs->trans('WerkbankSetup'));
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('WerkbankSetup'), $linkback, 'title_setup');
print dol_get_fiche_head(ittabpdf_admin_prepare_head(), 'check', '', -1, '');

$problems = ($counts['error'] ?? 0) + ($counts['warn'] ?? 0);
print '<div class="'.($problems ? 'warning' : 'info').'">'.($problems ? $langs->trans('WerkbankCheckSummaryOpen', $problems, count($items)) : $langs->trans('WerkbankCheckSummaryOk', count($items))).'</div>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankCheckWhat').'</td><td class="center">'.$langs->trans('Status').'</td><td>'.$langs->trans('Description').'</td><td></td></tr>';
$states = array('ok' => array('WerkbankCheckStateOk', 'status4'), 'warn' => array('WerkbankCheckStateWarn', 'status1'), 'error' => array('WerkbankCheckStateError', 'status8'));
// Problems first.
usort($items, function ($a, $b) {
	$order = array('error' => 0, 'warn' => 1, 'ok' => 2);
	return $order[$a['state']] <=> $order[$b['state']];
});
foreach ($items as $item) {
	print '<tr class="oddeven" id="check-'.dol_escape_htmltag($item['key']).'"><td>'.dol_escape_htmltag($item['title']).'</td>';
	print '<td class="center">'.dolGetStatus($langs->trans($states[$item['state']][0]), '', '', $states[$item['state']][1], 5).'</td>';
	print '<td class="small">'.$item['detail'].'</td>';
	print '<td class="right nowraponall">'.($item['link'] !== '' ? '<a href="'.dol_escape_htmltag($item['link']).'">'.img_picto('', 'fa-external-link-alt', 'class="paddingright"').$langs->trans($item['state'] === 'ok' ? 'WerkbankHelpGo' : 'WerkbankCheckGo').'</a>' : '').'</td></tr>';
}
print '</table></div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
