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
 * Tab "Unterlagen" of a third party: which company documents the customer
 * needs (rules of the module setup), what is done and what is missing, and
 * the way to finish each one: send, sign on the device, upload the signed
 * scan, or tick by hand. Every change is an event; nothing is overwritten.
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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
dol_include_once('/ittabelanderpdf/class/werkbanksignature.class.php');

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

$langs->loadLangs(array('companies', 'ittabelanderpdf@ittabelanderpdf'));
$socid = GETPOSTINT('socid');
$action = GETPOST('action', 'aZ09');
if ($user->socid > 0) {
	$socid = $user->socid;
}
if (!$user->hasRight('societe', 'lire')) {
	accessforbidden();
}
$soc = new Societe($db);
if ($socid <= 0 || $soc->fetch($socid) <= 0) {
	accessforbidden();
}
restrictedArea($user, 'societe', $socid, '&societe');
$write = $user->hasRight('societe', 'creer') && empty($user->socid);
$docs = new WerkbankDocs($db);
$self = dol_buildpath('/ittabelanderpdf/docs.php', 1).'?socid='.$socid;

/*
 * Actions
 */

$rule = GETPOSTINT('rule') > 0 ? $docs->rule(GETPOSTINT('rule')) : null;
if (in_array($action, array('startsign', 'upload', 'check', 'revoke'), true)) {
	if (!$write || !$rule) {
		accessforbidden();
	}
	if ($action === 'startsign') {
		$event = $docs->record($socid, (string) $rule->slug, 'signing', 'device', $user, array('fk_rule' => (int) $rule->rowid));
		if ($event > 0) {
			// The confirmation sheet exists before anyone signs.
			$signature = new WerkbankSignature($db);
			$loaded = $signature->load('doc', $event);
			if ($loaded) {
				$signature->write('doc', $event, $loaded);
			}
			header('Location: '.dol_buildpath('/ittabelanderpdf/sign.php', 1).'?kind=doc&id='.$event);
			exit;
		}
		setEventMessages($docs->error, null, 'errors');
	}
	if ($action === 'upload') {
		$file = isset($_FILES['docfile']) ? $_FILES['docfile'] : null;
		$extension = $file ? strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) : '';
		if (!$file || empty($file['tmp_name']) || !in_array($extension, array('pdf', 'jpg', 'jpeg', 'png'), true)) {
			setEventMessages($langs->trans('WerkbankDocsUploadType'), null, 'errors');
		} else {
			$dir = WerkbankSignature::thirdpartyDir($socid);
			dol_mkdir($dir);
			$name = $rule->slug.'-unterschrieben-'.dol_print_date(dol_now(), '%Y%m%d-%H%M%S').'.'.$extension;
			$moved = dol_move_uploaded_file($file['tmp_name'], $dir.'/'.$name, 0, 0, $file['error']);
			if (is_numeric($moved) && $moved > 0) {
				$docs->record($socid, (string) $rule->slug, 'uploaded', 'upload', $user, array('fk_rule' => (int) $rule->rowid, 'filename' => $name,
					'note' => GETPOST('note', 'alphanohtml')));
				setEventMessages($langs->trans('WerkbankDocsUploaded', $rule->label), null, 'mesgs');
			} else {
				setEventMessages($langs->trans('WerkbankDocsUploadFailed', $langs->transnoentities((string) $moved)), null, 'errors');
			}
		}
	}
	if ($action === 'check') {
		$date = dol_stringtotime(GETPOST('date', 'alphanohtml').' 12:00:00');
		$docs->record($socid, (string) $rule->slug, 'checked', 'hand', $user, array('fk_rule' => (int) $rule->rowid,
			'date' => $date > 0 && $date <= dol_now() + 86400 ? $date : dol_now(), 'note' => GETPOST('note', 'alphanohtml')));
		setEventMessages($langs->trans('WerkbankDocsChecked', $rule->label), null, 'mesgs');
	}
	if ($action === 'revoke') {
		$docs->record($socid, (string) $rule->slug, 'revoked', 'hand', $user, array('fk_rule' => (int) $rule->rowid, 'note' => GETPOST('note', 'alphanohtml')));
		setEventMessages($langs->trans('WerkbankDocsRevoked', $rule->label), null, 'warnings');
	}
	header('Location: '.$self.'#rule-'.((int) $rule->rowid));
	exit;
}

/*
 * View
 */

llxHeader('', $langs->trans('WerkbankDocsTab').' - '.$soc->name, '', '', 0, 0, array(), array(), '', 'mod-ittabelanderpdf page-docs');
print dol_get_fiche_head(societe_prepare_head($soc), 'ittabpdfdocs', $langs->trans('ThirdParty'), -1, 'company');
dol_banner_tab($soc, 'socid', '', ($user->socid ? 0 : 1), 'rowid', 'nom');
print dol_get_fiche_end();

$status = $docs->status($soc);
$open = array_filter($status, function ($entry) {
	return (int) $entry['rule']->required && $entry['state'] !== 'done';
});
$doneCount = count(array_filter($status, function ($entry) {
	return $entry['state'] === 'done';
}));
// Uploading, ticking and resetting each open a small form below the list (?action=…form&rule=…).
$formFor = in_array($action, array('uploadform', 'checkform', 'revokeform'), true) && $rule ? $action : '';

print '<div class="'.($open ? 'warning' : 'info').' ittabdocs-summary">'.($open ? $langs->trans('WerkbankDocsSummaryOpen', count($open)) : $langs->trans('WerkbankDocsSummaryDone'))
	.' · '.$langs->trans('WerkbankDocsSummaryCount', $doneCount, count($status)).'</div>';

$states = array('missing' => array('WerkbankDocsStateMissing', 'status8'), 'sent' => array('WerkbankDocsStateSent', 'status1'),
	'signing' => array('WerkbankDocsStateSigning', 'status3'), 'generated' => array('WerkbankDocsStateGenerated', 'status1'),
	'done' => array('WerkbankDocsStateDone', 'status4'), 'outdated' => array('WerkbankDocsStateOutdated', 'status8'));
$who = function ($event) {
	$name = trim($event->firstname.' '.$event->lastname);
	return $name !== '' ? $name : (string) $event->login;
};
$sources = array('mail' => 'WerkbankDocsSourceMail', 'device' => 'WerkbankDocsSourceDevice', 'proposal' => 'WerkbankDocsSourceProposal',
	'portal' => 'WerkbankDocsSourcePortal', 'upload' => 'WerkbankDocsSourceUpload', 'hand' => 'WerkbankDocsSourceHand');
$fileLink = function ($filename) use ($socid, $langs) {
	if ((string) $filename === '' || !is_file(WerkbankSignature::thirdpartyDir($socid).'/'.$filename)) {
		return '';
	}
	return ' <a href="'.DOL_URL_ROOT.'/document.php?modulepart=societe&amp;file='.urlencode($socid.'/'.$filename).'">'.img_picto($langs->trans('WerkbankDocsOpenFile'), 'pdf').'</a>';
};

print '<div class="div-table-responsive"><table class="noborder centpercent ittabdocs-list">';
print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankDocSignDocument').'</td><td class="center">'.$langs->trans('Status').'</td>'
	.'<td>'.$langs->trans('WerkbankDocsProof').'</td><td class="right">'.$langs->trans('Action').'</td></tr>';
if (!$status) {
	print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('WerkbankDocsNoRules').'</span></td></tr>';
}
foreach ($status as $entry) {
	$rule = $entry['rule'];
	$id = (int) $rule->rowid;
	$state = $entry['state'];
	print '<tr class="oddeven ittabdocs-item state-'.$state.'" id="rule-'.$id.'">';
	print '<td>'.img_picto('', 'pdf', 'class="pictofixedwidth"').dol_escape_htmltag($rule->label)
		.((int) $rule->required ? ' <span class="badge badge-status1">'.$langs->trans('WerkbankQsRequired').'</span>' : ' <span class="opacitymedium">('.$langs->trans('WerkbankDocsOptional').')</span>')
		.'<br><span class="opacitymedium small">'.dol_escape_htmltag($entry['title'])
		.($entry['stand'] !== '' ? ' · '.$langs->trans('WerkbankDocsStand', dol_print_date(dol_stringtotime($entry['stand'].' 00:00:00'), 'day')) : '').'</span></td>';
	print '<td class="center">'.dolGetStatus($langs->trans($states[$state][0]), '', '', $states[$state][1], 5).'</td>';

	// What counts now: when, how, who, the file.
	$lines = array();
	if ($entry['done']) {
		$event = $entry['done'];
		$lines[] = $langs->transnoentities('WerkbankDocsDoneLine_'.$event->state, dol_print_date($db->jdate($event->date_event), 'day'), $langs->transnoentities($sources[$event->source] ?? 'WerkbankDocsSourceHand'), dol_escape_htmltag($who($event)))
			.($event->version !== '' ? ' · '.$langs->trans('WerkbankDocsStand', dol_print_date(dol_stringtotime($event->version.' 00:00:00'), 'day')) : '')
			.((string) $event->note !== '' ? ' · '.dol_escape_htmltag($event->note) : '').$fileLink($event->filename);
	}
	if ($entry['sent'] && $state !== 'done') {
		$lines[] = $langs->transnoentities('WerkbankDocsSentLine', dol_print_date($db->jdate($entry['sent']->date_event), 'day'), dol_escape_htmltag($who($entry['sent'])));
	}
	if (!empty($entry['generated']) && $state !== 'done') {
		$lines[] = $langs->transnoentities('WerkbankDocsGeneratedLine', dol_print_date($db->jdate($entry['generated']->date_event), 'day')).$fileLink($entry['generated']->filename);
	}
	if ($state === 'outdated') {
		$lines[] = '<strong>'.$langs->trans('WerkbankDocsOutdatedLine').'</strong>';
	}
	if (!$entry['exists'] && $state !== 'done') {
		$lines[] = '<span class="opacitymedium">'.$langs->trans('WerkbankDocsNoFile', dol_escape_htmltag(WerkbankUnterlagen::relativeDir().'/'.$rule->slug.'.pdf')).'</span>';
	}
	print '<td class="small">'.implode('<br>', $lines).'</td>';

	print '<td class="right nowraponall">';
	if ($write) {
		if ($state !== 'done' && $entry['signing']) {
			print '<a class="butAction smallpaddingimp" href="'.dol_buildpath('/ittabelanderpdf/sign.php', 1).'?kind=doc&amp;id='.((int) $entry['signing']->rowid).'">'.$langs->trans('WerkbankDocsContinueSign').'</a>';
		} elseif ($state !== 'done' && $entry['exists']) {
			print '<form method="POST" action="'.$self.'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="startsign"><input type="hidden" name="rule" value="'.$id.'">';
			print '<input type="submit" class="butAction smallpaddingimp" value="'.dol_escape_htmltag($langs->trans($rule->action === 'handover' ? 'WerkbankDocsConfirmReceipt' : 'WerkbankDocsSignNow')).'"></form>';
		}
		if ($state !== 'done' && $entry['exists']) {
			print '<a class="butAction smallpaddingimp" href="'.DOL_URL_ROOT.'/societe/card.php?socid='.$socid.'&amp;action=presend&amp;mode=init&amp;ittabpdf_preselect='.urlencode($rule->slug.'.pdf').'#formmail">'.$langs->trans('WerkbankDocsSend').'</a>';
		}
		print '<a class="butAction smallpaddingimp" href="'.$self.'&amp;action=uploadform&amp;rule='.$id.'#docform">'.$langs->trans('WerkbankDocsUploadButton').'</a>';
		print '<a class="butAction smallpaddingimp" href="'.$self.'&amp;action=checkform&amp;rule='.$id.'#docform">'.$langs->trans('WerkbankDocsTickButton').'</a>';
		if ($state === 'done') {
			print '<a class="butActionDelete smallpaddingimp" href="'.$self.'&amp;action=revokeform&amp;rule='.$id.'#docform">'.$langs->trans('WerkbankDocsRevoke').'</a>';
		}
	}
	print '</td></tr>';
}
print '</table></div>';

// The form the chosen action needs, the way Dolibarr cards show them.
if ($write && $formFor) {
	print '<a id="docform"></a>'.load_fiche_titre(dol_escape_htmltag($rule->label), '', '');
	print '<form method="POST" action="'.$self.'"'.($formFor === 'uploadform' ? ' enctype="multipart/form-data"' : '').'>';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="rule" value="'.((int) $rule->rowid).'">';
	print '<input type="hidden" name="action" value="'.($formFor === 'uploadform' ? 'upload' : ($formFor === 'checkform' ? 'check' : 'revoke')).'">';
	print '<table class="border centpercent tableforfield">';
	if ($formFor === 'uploadform') {
		print '<tr><td class="titlefield fieldrequired">'.$langs->trans('WerkbankDocsUploadLabel').'</td><td><input type="file" name="docfile" accept="application/pdf,image/jpeg,image/png" capture="environment"></td></tr>';
	}
	if ($formFor === 'checkform') {
		print '<tr><td class="titlefield">'.$langs->trans('Date').'</td><td><input type="date" name="date" value="'.dol_print_date(dol_now(), '%Y-%m-%d').'"></td></tr>';
	}
	print '<tr><td class="titlefield">'.$langs->trans('Note').'</td><td><input type="text" name="note" class="minwidth300" placeholder="'.dol_escape_htmltag($langs->trans($formFor === 'revokeform' ? 'WerkbankDocsRevokeNote' : 'WerkbankDocsTickNote')).'"></td></tr>';
	print '</table><div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans($formFor === 'uploadform' ? 'WerkbankDocsUploadButton' : ($formFor === 'checkform' ? 'WerkbankDocsTickButton' : 'WerkbankDocsRevoke'))).'">'
		.' <a class="button button-cancel" href="'.$self.'">'.$langs->trans('Cancel').'</a></div></form>';
}

// Everything that happened, newest first.
$events = $docs->events($socid);
if ($events) {
	print load_fiche_titre($langs->trans('WerkbankDocsHistory', count($events)), '', '');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent ittabdocs-history">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('WerkbankDocSignDocument').'</td><td>'.$langs->trans('Event').'</td><td>'.$langs->trans('User').'</td><td>'.$langs->trans('Note').'</td></tr>';
	foreach ($events as $event) {
		print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($db->jdate($event->date_event), 'dayhour').'</td><td>'.dol_escape_htmltag($event->slug).'</td>'
			.'<td>'.$langs->trans('WerkbankDocsEvent_'.$event->state).$fileLink($event->filename).'</td><td>'.dol_escape_htmltag($who($event)).'</td><td>'.dol_escape_htmltag((string) $event->note).'</td></tr>';
	}
	print '</table></div>';
}

llxFooter();
$db->close();
