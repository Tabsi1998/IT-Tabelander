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

llxHeader('<meta name="viewport" content="width=device-width, initial-scale=1">', $langs->trans('WerkbankDocsTab').' - '.$soc->name, '', '', 0, 0,
	array(), array('/ittabelanderpdf/css/qs.css'), '', 'mod-ittabelanderpdf page-docs');
print dol_get_fiche_head(societe_prepare_head($soc), 'ittabpdfdocs', $langs->trans('ThirdParty'), -1, 'company');
dol_banner_tab($soc, 'socid', '', ($user->socid ? 0 : 1), 'rowid', 'nom');
print dol_get_fiche_end();

$status = $docs->status($soc);
$required = array_filter($status, function ($entry) {
	return (int) $entry['rule']->required;
});
$open = array_filter($required, function ($entry) {
	return $entry['state'] !== 'done';
});
$states = array('missing' => 'WerkbankDocsStateMissing', 'sent' => 'WerkbankDocsStateSent', 'signing' => 'WerkbankDocsStateSigning',
	'done' => 'WerkbankDocsStateDone', 'outdated' => 'WerkbankDocsStateOutdated');

print '<div class="ittabqs ittabdocs">';
print '<div class="ittabdocs-summary'.($open ? ' is-open' : ' is-complete').'" role="status">';
print '<strong>'.($open ? $langs->trans('WerkbankDocsSummaryOpen', count($open)) : $langs->trans('WerkbankDocsSummaryDone')).'</strong>';
print '<span>'.$langs->trans('WerkbankDocsSummaryCount', count($status) - count(array_filter($status, function ($entry) {
	return $entry['state'] !== 'done';
})), count($status)).'</span></div>';

if (!$status) {
	print '<p class="ittabqs-empty">'.$langs->trans('WerkbankDocsNoRules').'</p>';
}
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
	return ' <a href="'.DOL_URL_ROOT.'/document.php?modulepart=societe&amp;file='.urlencode($socid.'/'.$filename).'">'.img_picto('', 'pdf', 'class="pictofixedwidth"').$langs->trans('WerkbankDocsOpenFile').'</a>';
};

print '<ul class="ittabdocs-list">';
foreach ($status as $entry) {
	$rule = $entry['rule'];
	$id = (int) $rule->rowid;
	$state = $entry['state'];
	print '<li class="ittabdocs-item state-'.$state.'" id="rule-'.$id.'">';
	print '<div class="ittabdocs-head"><span class="ittabdocs-mark" aria-hidden="true"></span><div>';
	print '<div class="ittabdocs-label">'.dol_escape_htmltag($rule->label).' <span class="ittabdocs-kind">'.$langs->trans((int) $rule->required ? 'WerkbankQsRequired' : 'WerkbankDocsOptional').'</span></div>';
	print '<div class="ittabdocs-doc">'.dol_escape_htmltag($entry['title']).($entry['stand'] !== '' ? ' · '.$langs->trans('WerkbankDocsStand', dol_print_date(dol_stringtotime($entry['stand'].' 00:00:00'), 'day')) : '').'</div>';
	print '</div><span class="ittabdocs-state">'.$langs->trans($states[$state]).'</span></div>';

	// What counts now, in one line: when, how, who, the file.
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
	if ($state === 'outdated') {
		$lines[] = '<strong>'.$langs->trans('WerkbankDocsOutdatedLine').'</strong>';
	}
	if ($lines) {
		print '<p class="ittabdocs-facts">'.implode('<br>', $lines).'</p>';
	}

	if ($write) {
		print '<div class="ittabdocs-actions">';
		$ready = $entry['exists'];
		if ($state !== 'done') {
			if ($entry['signing']) {
				print '<a class="ittabqs-primary" href="'.dol_buildpath('/ittabelanderpdf/sign.php', 1).'?kind=doc&amp;id='.((int) $entry['signing']->rowid).'">'.$langs->trans('WerkbankDocsContinueSign').'</a>';
			} elseif ($ready) {
				print '<form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="startsign"><input type="hidden" name="rule" value="'.$id.'">';
				print '<button type="submit" class="ittabqs-primary">'.$langs->trans($rule->action === 'handover' ? 'WerkbankDocsConfirmReceipt' : 'WerkbankDocsSignNow').'</button></form>';
			}
			if ($ready) {
				print '<a class="ittabqs-secondary" href="'.DOL_URL_ROOT.'/societe/card.php?socid='.$socid.'&amp;action=presend&amp;mode=init&amp;ittabpdf_preselect='.urlencode($rule->slug.'.pdf').'#formmail">'.$langs->trans('WerkbankDocsSend').'</a>';
			}
		}
		print '<details class="ittabdocs-more"><summary>'.$langs->trans('WerkbankDocsUploadOrTick').'</summary>';
		print '<form method="POST" action="'.$self.'" enctype="multipart/form-data" class="ittabdocs-form">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="upload"><input type="hidden" name="rule" value="'.$id.'">';
		print '<label class="ittabqs-label" for="docfile-'.$id.'">'.$langs->trans('WerkbankDocsUploadLabel').'</label>';
		print '<input type="file" id="docfile-'.$id.'" name="docfile" accept="application/pdf,image/jpeg,image/png" capture="environment" class="ittabqs-input">';
		print '<button type="submit" class="ittabqs-secondary">'.$langs->trans('WerkbankDocsUploadButton').'</button></form>';
		print '<form method="POST" action="'.$self.'" class="ittabdocs-form">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="check"><input type="hidden" name="rule" value="'.$id.'">';
		print '<label class="ittabqs-label" for="docdate-'.$id.'">'.$langs->trans('WerkbankDocsTickLabel').'</label>';
		print '<input type="date" id="docdate-'.$id.'" name="date" class="ittabqs-input" value="'.dol_print_date(dol_now(), '%Y-%m-%d').'">';
		print '<input type="text" name="note" class="ittabqs-input" placeholder="'.dol_escape_htmltag($langs->trans('WerkbankDocsTickNote')).'">';
		print '<button type="submit" class="ittabqs-secondary">'.$langs->trans('WerkbankDocsTickButton').'</button></form>';
		if ($state === 'done') {
			print '<form method="POST" action="'.$self.'" class="ittabdocs-form">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="revoke"><input type="hidden" name="rule" value="'.$id.'">';
			print '<input type="text" name="note" class="ittabqs-input" placeholder="'.dol_escape_htmltag($langs->trans('WerkbankDocsRevokeNote')).'">';
			print '<button type="submit" class="ittabqs-secondary">'.$langs->trans('WerkbankDocsRevoke').'</button></form>';
		}
		print '</details>';
		if (!$ready) {
			print '<p class="ittabqs-help">'.$langs->trans('WerkbankDocsNoFile', dol_escape_htmltag(WerkbankUnterlagen::relativeDir().'/'.$rule->slug.'.pdf')).'</p>';
		}
		print '</div>';
	}
	print '</li>';
}
print '</ul>';

// Everything that happened, newest first.
$events = $docs->events($socid);
if ($events) {
	print '<details class="ittabdocs-history"><summary>'.$langs->trans('WerkbankDocsHistory', count($events)).'</summary><ol>';
	foreach ($events as $event) {
		print '<li><span class="ittabdocs-when">'.dol_print_date($db->jdate($event->date_event), 'dayhour').'</span> '
			.dol_escape_htmltag($event->slug).' – '.$langs->trans('WerkbankDocsEvent_'.$event->state).' · '.dol_escape_htmltag($who($event))
			.((string) $event->note !== '' ? ' · '.dol_escape_htmltag($event->note) : '').$fileLink($event->filename).'</li>';
	}
	print '</ol></details>';
}
print '</div>';

llxFooter();
$db->close();
