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
 * Automatic e-mails: planned, sent, failed and cancelled, with cancel and send again.
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
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
dol_include_once('/ittabelanderpdf/class/werkbankmailqueue.class.php');

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
/** @var Conf $conf */

$langs->loadLangs(array('mails', 'ittabelanderpdf@ittabelanderpdf'));
if (!$user->hasRight('propal', 'lire') && !$user->hasRight('facture', 'lire')) {
	accessforbidden();
}

$queue = new WerkbankMailQueue($db);
$action = GETPOST('action', 'aZ09');
$status = GETPOST('status', 'aZ09');
$rowid = GETPOSTINT('queueid');

if (in_array($action, array('cancel', 'retry'), true) && $rowid > 0) {
	if (GETPOST('token', 'alphanohtml') !== currentToken()) {
		accessforbidden();
	}
	$row = $db->fetch_object($db->query("SELECT element FROM ".MAIN_DB_PREFIX."ittabpdf_mailqueue WHERE rowid = ".((int) $rowid)." AND entity = ".((int) $conf->entity)));
	$allowed = $row && ($row->element === 'propal' ? $user->hasRight('propal', 'creer') : $user->hasRight('facture', 'creer'));
	if (!$allowed) {
		accessforbidden();
	}
	$done = $action === 'cancel' ? $queue->cancel($rowid, $user) : $queue->retry($rowid, $user);
	setEventMessages($langs->trans($done ? ($action === 'cancel' ? 'WerkbankAutoCancelled' : 'WerkbankAutoRetried') : 'WerkbankAutoTooLate'), null, $done ? 'mesgs' : 'warnings');
	header('Location: '.$_SERVER['PHP_SELF'].($status !== '' ? '?status='.urlencode($status) : ''));
	exit;
}

llxHeader('', $langs->trans('WerkbankAutoTitle'));
print load_fiche_titre($langs->trans('WerkbankAutoTitle'), '', 'email');

$statuses = array('' => 'All', 'planned' => 'WerkbankStatus_planned', 'sent' => 'WerkbankStatus_sent', 'error' => 'WerkbankStatus_error', 'cancelled' => 'WerkbankStatus_cancelled');
print '<div class="tabsAction" style="text-align:left">';
foreach ($statuses as $key => $label) {
	print '<a class="'.($status === $key ? 'butActionRefused' : 'butAction').'" href="'.$_SERVER['PHP_SELF'].($key !== '' ? '?status='.$key : '').'">'.$langs->trans($label).'</a>';
}
print '</div>';

$sql = "SELECT rowid, element, fk_object, event, date_due, date_done, status, attempts, recipient, error FROM ".MAIN_DB_PREFIX."ittabpdf_mailqueue";
$sql .= " WHERE entity = ".((int) $conf->entity);
if ($status !== '' && isset($statuses[$status])) {
	$sql .= " AND status = '".$db->escape($status)."'";
}
$sql .= " ORDER BY date_due DESC LIMIT 200";
$resql = $db->query($sql);

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Document').'</td><td>'.$langs->trans('WerkbankAutoEvent').'</td><td>'.$langs->trans('WerkbankAutoDue').'</td>';
print '<td>'.$langs->trans('Status').'</td><td>'.$langs->trans('MailTo').'</td><td>'.$langs->trans('WerkbankAutoNote').'</td><td></td></tr>';
$found = 0;
while ($resql && ($obj = $db->fetch_object($resql))) {
	$found++;
	$doc = $obj->element === 'propal' ? new Propal($db) : new Facture($db);
	$link = $doc->fetch((int) $obj->fk_object) > 0 ? $doc->getNomUrl(1) : $obj->element.' #'.((int) $obj->fk_object);
	$base = $_SERVER['PHP_SELF'].'?queueid='.((int) $obj->rowid).'&token='.newToken().($status !== '' ? '&status='.urlencode($status) : '');
	$buttons = '';
	if ($obj->status === 'planned') {
		$buttons = '<a class="butActionDelete smallpaddingimp" href="'.$base.'&action=cancel">'.$langs->trans('WerkbankAutoCancel').'</a>';
	} elseif (in_array($obj->status, array('error', 'cancelled', 'sent'), true)) {
		$buttons = '<a class="butAction smallpaddingimp" href="'.$base.'&action=retry">'.$langs->trans('WerkbankAutoRetry').'</a>';
	}
	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.$link.'</td>';
	print '<td>'.dol_escape_htmltag($langs->trans('WerkbankEvent_'.$obj->event)).'</td>';
	print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->date_due), 'dayhour', 'tzuser').'</td>';
	print '<td>'.dol_escape_htmltag($langs->trans('WerkbankStatus_'.$obj->status)).'</td>';
	print '<td>'.dol_escape_htmltag((string) $obj->recipient).'</td>';
	print '<td>'.dol_escape_htmltag((string) $obj->error).'</td>';
	print '<td class="right nowraponall">'.$buttons.'</td>';
	print '</tr>';
}
if (!$found) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
