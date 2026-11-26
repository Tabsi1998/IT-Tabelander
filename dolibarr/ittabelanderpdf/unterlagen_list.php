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
 * List of company documents and legal texts (Unterlagen): kind, public,
 * version and "Stand", whether the draft has unreleased changes, the PDF.
 */

$res = 0;
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
dol_include_once('/ittabelanderpdf/class/werkbankdoc.class.php');

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

$langs->loadLangs(array('ittabelanderpdf@ittabelanderpdf'));
if (!$user->hasRight('ittabelanderpdf', 'unterlagen', 'read') || !empty($user->socid)) {
	accessforbidden();
}
$store = new WerkbankDoc($db);
$kind = GETPOST('kind', 'aZ09');
$docs = $store->all(in_array($kind, WerkbankDoc::KINDS, true) ? array('kind' => $kind) : array());
$card = dol_buildpath('/ittabelanderpdf/unterlage_card.php', 1);

llxHeader('', $langs->trans('WerkbankDocListTitle'), '', '', 0, 0, array(), array(), '', 'mod-ittabelanderpdf page-unterlagen-list');
$new = $user->hasRight('ittabelanderpdf', 'unterlagen', 'write') ? dolGetButtonTitle($langs->trans('WerkbankDocNew'), '', 'fa fa-plus-circle', $card.'?action=create') : '';
print load_fiche_titre($langs->trans('WerkbankDocListTitle'), $new, 'pdf');

// Filter by kind, as tabs of buttons.
print '<div class="tabsElem marginbottomonly">';
foreach (array('' => 'All', 'legal' => 'WerkbankDocKind_legal', 'internal' => 'WerkbankDocKind_internal', 'customer' => 'WerkbankDocKind_customer') as $key => $label) {
	print '<a class="'.($kind === $key ? 'butActionRefused' : 'butAction').' smallpaddingimp" href="'.$_SERVER['PHP_SELF'].($key !== '' ? '?kind='.$key : '').'">'.$langs->trans($label).'</a>';
}
print '</div>';

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankDocTitle').'</td><td>'.$langs->trans('WerkbankDocsFile').'</td><td>'.$langs->trans('WerkbankDocKind').'</td>'
	.'<td class="center">'.$langs->trans('WerkbankDocPublic').'</td><td class="center">'.$langs->trans('WerkbankDocVersion').'</td><td class="center">'.$langs->trans('Status').'</td></tr>';
if (!$docs) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('WerkbankDocNone').'</span></td></tr>';
}
foreach ($docs as $doc) {
	$file = WerkbankUnterlagen::dir().'/'.$doc->slug.'.pdf';
	$pdf = $doc->kind !== 'customer' && is_file($file)
		? '<a href="'.DOL_URL_ROOT.'/document.php?modulepart=ecm&amp;file='.urlencode(WerkbankUnterlagen::relativeDir().'/'.$doc->slug.'.pdf').'">'.img_picto('', 'pdf', 'class="pictofixedwidth"').dol_escape_htmltag($doc->slug).'.pdf</a>'
		: '<span class="opacitymedium">'.dol_escape_htmltag($doc->slug).'</span>';
	$state = (int) $doc->version === 0 ? array('WerkbankDocStateDraft', 'status0') : ((int) $doc->changed ? array('WerkbankDocStateChanged', 'status1') : array('WerkbankDocStateReleased', 'status4'));
	print '<tr class="oddeven">';
	print '<td><a href="'.$card.'?id='.((int) $doc->rowid).'">'.img_picto('', 'pdf', 'class="pictofixedwidth"').dol_escape_htmltag($doc->title).'</a></td>';
	print '<td>'.$pdf.'</td>';
	print '<td>'.$langs->trans('WerkbankDocKind_'.$doc->kind).(!(int) $doc->sendable && $doc->kind !== 'customer' ? ' <span class="badge badge-status0">'.$langs->trans('WerkbankDocNotSendable').'</span>' : '').'</td>';
	print '<td class="center">'.((int) $doc->public ? img_picto($langs->trans('Yes'), 'globe') : '').'</td>';
	print '<td class="center nowraponall">'.((int) $doc->version > 0 ? 'v'.((int) $doc->version).' · '.dol_print_date($db->jdate($doc->date_release), 'day') : '–').'</td>';
	print '<td class="center">'.dolGetStatus($langs->trans($state[0]), $langs->trans($state[0]), '', $state[1], 5).'</td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();
