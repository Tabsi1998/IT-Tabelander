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
 * Sign on the device and send: the test report of a closed QS run
 * (kind=qs&id=<run>) or the device intake slip of a ticket (kind=intake&id=<ticket>).
 * Technician and customer sign with a finger or pen, with their name in block
 * letters; each signature lands in the PDF at once. Then the PDF goes to the
 * customer after the user saw and confirmed the address.
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
dol_include_once('/ittabelanderpdf/class/werkbanksignature.class.php');

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

$langs->loadLangs(array('interventions', 'ticket', 'mails', 'ittabelanderpdf@ittabelanderpdf'));
$kind = GETPOST('kind', 'aZ09');
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
if ($user->socid > 0 || !in_array($kind, array('qs', 'intake', 'doc'), true)) {
	accessforbidden();
}
if ($kind === 'qs' && (!$user->hasRight('ittabelanderpdf', 'qs', 'run') || !$user->hasRight('ficheinter', 'lire'))) {
	accessforbidden();
}
if ($kind === 'intake' && !$user->hasRight('ticket', 'write')) {
	accessforbidden();
}
if ($kind === 'doc' && !$user->hasRight('societe', 'creer')) {
	accessforbidden();
}
$signature = new WerkbankSignature($db);
$loaded = $signature->load($kind, $id);
if (!$loaded) {
	accessforbidden();
}
$self = dol_buildpath('/ittabelanderpdf/sign.php', 1).'?kind='.$kind.'&id='.$id;
$backs = array('qs' => dol_buildpath('/ittabelanderpdf/qs.php', 1).'?id='.$id, 'intake' => DOL_URL_ROOT.'/ticket/card.php?id='.$id,
	'doc' => dol_buildpath('/ittabelanderpdf/docs.php', 1).'?socid='.$loaded['socid']);
$back = $backs[$kind];
if ($kind === 'qs' && (int) $loaded['run']->status !== 1) {
	setEventMessages($langs->trans('WerkbankSignCloseFirst'), null, 'warnings');
	header('Location: '.$back);
	exit;
}

if ($action === 'send') {
	$to = trim(GETPOST('sendto', 'alphawithlgt'));
	if ($signature->send($kind, $id, $to, $user) > 0) {
		setEventMessages($langs->trans('WerkbankSignSent', $to), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('WerkbankSignSendFailed', $langs->transnoentities($signature->error)), null, 'errors');
	}
	header('Location: '.$self.'#ittabsig-send');
	exit;
}
// The PDF exists before anyone signs (intake slip not written yet, or an old QS).
$pdf = WerkbankSignature::pdfPath($kind, $loaded);
if (!is_file($pdf)) {
	$signature->write($kind, $id, $loaded);
}

$object = $loaded['object'];
$roles = WerkbankSignature::roles($kind, $loaded);
$signed = $signature->signatures($kind, $id, $loaded);
$recipient = WerkbankSignature::recipient($kind, $loaded);
$document = $kind === 'doc' ? $loaded['ref'] : $langs->trans($kind === 'qs' ? 'WerkbankQsTitle' : 'WerkbankIntakeTitle').' '.$loaded['ref'];

llxHeader('', $langs->trans('WerkbankSignTitle'), '', '', 0, 0, array('/ittabelanderpdf/js/sign.js'), array('/ittabelanderpdf/css/qs.css'), '', 'mod-ittabelanderpdf page-sign');

$customerName = is_object($object->thirdparty) && !empty($object->thirdparty->name) ? ' – '.$object->thirdparty->name : '';
print load_fiche_titre($langs->trans('WerkbankSignTitle').': '.dol_escape_htmltag($document.$customerName), '<a href="'.dol_escape_htmltag($back).'">'.$langs->trans('WerkbankSignBack').'</a>', 'signature');
if (is_file($pdf)) {
	list($modulepart, $file) = WerkbankSignature::documentLink($kind, $pdf);
	print '<div class="tabsAction"><a class="butAction" href="'.DOL_URL_ROOT.'/document.php?modulepart='.$modulepart.'&amp;file='.urlencode($file).'">'.$langs->trans('WerkbankSignOpenPdf').'</a></div>';
}

$labels = array(
	'technician' => $langs->trans('WerkbankSignRoleTechnician', $GLOBALS['mysoc']->name),
	'customer' => $langs->trans('WerkbankSignRoleCustomer'),
);
$defaults = array('technician' => $user->getFullName($langs), 'customer' => '');
if (isset($object->thirdparty) && is_object($object->thirdparty) && !empty($object->thirdparty->id) && (int) $object->thirdparty->typent_id
	&& dol_getIdFromCode($db, (int) $object->thirdparty->typent_id, 'c_typent', 'id', 'code') === 'TE_PRIVATE') {
	$defaults['customer'] = (string) $object->thirdparty->name;
}

print '<div id="ittabsig" data-endpoint="'.dol_escape_htmltag(dol_buildpath('/ittabelanderpdf/ajax/sign.php', 1)).'" data-token="'.dol_escape_htmltag(currentToken()).'"'
	.' data-kind="'.$kind.'" data-id="'.$id.'" data-empty="'.dol_escape_htmltag($langs->transnoentities('WerkbankSignEmpty')).'"'
	.' data-noname="'.dol_escape_htmltag($langs->transnoentities('WerkbankSignNameMissing')).'" data-saving="'.dol_escape_htmltag($langs->transnoentities('WerkbankSignSaving')).'"'
	.' data-failed="'.dol_escape_htmltag($langs->transnoentities('WerkbankQsSaveFailed')).'">';
foreach ($roles as $role) {
	$row = isset($signed[$role]) ? $signed[$role] : null;
	print '<div class="ittabsig-card'.($row ? ' is-signed' : '').'" data-role="'.$role.'">';
	print load_fiche_titre(dol_escape_htmltag($labels[$role]), '', '');
	print '<table class="border centpercent tableforfield">';
	if ($row) {
		list($modulepart, $file) = WerkbankSignature::documentLink($kind, $row->path);
		print '<tr><td class="titlefield">'.$langs->trans('WerkbankSignTitle').'</td><td><img class="ittabsig-image" src="'.DOL_URL_ROOT.'/viewimage.php?modulepart='.$modulepart.'&amp;file='.urlencode($file).'&amp;cache='.dol_filemtime($row->path).'" alt="'.dol_escape_htmltag($langs->trans('WerkbankSignImageOf', $row->name)).'">'
			.'<br>'.dol_escape_htmltag($row->name).' · '.dol_print_date($db->jdate($row->date_sign), 'dayhour', 'tzuser').'</td></tr>';
	}
	print '<tr><td class="titlefield">'.$langs->trans($row ? 'WerkbankSignAgain' : 'WerkbankSignHere').'</td><td><div class="ittabsig-pad"><canvas aria-label="'.dol_escape_htmltag($langs->trans('WerkbankSignHere')).'"></canvas></div></td></tr>';
	print '<tr><td>'.$langs->trans('WerkbankSignName').'</td><td><input type="text" class="minwidth300 ittabsig-name" autocomplete="name" value="'.dol_escape_htmltag($row ? $row->name : $defaults[$role]).'" placeholder="'.dol_escape_htmltag($langs->trans('WerkbankSignNameHint')).'"></td></tr>';
	print '</table>';
	print '<div class="center"><button type="button" class="button button-cancel ittabsig-clear">'.$langs->trans('WerkbankSignClear').'</button> ';
	print '<button type="button" class="button ittabsig-save">'.$langs->trans('WerkbankSignSave').'</button></div>';
	print '<div class="center opacitymedium ittabsig-status" aria-live="polite"></div>';
	print '</div>';
}
print '</div>';

// Sending: only with every signature, and only to an address the user sees.
$complete = count(array_intersect($roles, array_keys($signed))) === count($roles);
print load_fiche_titre($langs->trans('WerkbankSignSendTitle'), '', 'email');
print '<form method="POST" action="'.$self.'" id="ittabsig-send">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="send">';
if ($kind === 'qs' && !empty($loaded['run']->date_sent)) {
	print '<div class="info ittabsig-sent">'.$langs->trans('WerkbankSignSentOn', dol_print_date($db->jdate($loaded['run']->date_sent), 'dayhour', 'tzuser'), dol_escape_htmltag((string) $loaded['run']->sent_to)).'</div>';
}
if (!$complete) {
	print '<div class="warning">'.$langs->trans('WerkbankSignSendAfter').'</div>';
}
print '<table class="border centpercent tableforfield"><tr><td class="titlefield">'.$langs->trans('WerkbankSignSendTo').'</td><td>';
print '<input type="text" inputmode="email" name="sendto" class="minwidth300" value="'.dol_escape_htmltag($recipient).'"'.($complete ? '' : ' disabled').'>';
print '<div class="opacitymedium small">'.$langs->trans('WerkbankSignSendHelp', dol_escape_htmltag(getDolGlobalString('ITTABPDF_AUTOSEND_BCC'))).'</div></td></tr></table>';
print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('WerkbankSignSendNow')).'"'.($complete ? '' : ' disabled').'></div>';
print '</form>';

llxFooter();
$db->close();
