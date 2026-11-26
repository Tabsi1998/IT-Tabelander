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
 * Any released document can be added to the customer by hand ("Weitere
 * Unterlage", also from a contract, a proposal or the document card): it
 * becomes a line of its own, removable again.
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

$langs->loadLangs(array('companies', 'contracts', 'propal', 'ittabelanderpdf@ittabelanderpdf'));
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
// A rule added by hand belongs to one customer only.
if ($rule && !empty($rule->fk_soc) && (int) $rule->fk_soc !== $socid) {
	$rule = null;
}
// Contract or proposal of this customer the document is made for (from their cards).
$fromContract = GETPOSTINT('contract');
$fromPropal = 0;
if (GETPOSTINT('propal') > 0) {
	require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
	$propal = new Propal($db);
	$fromPropal = $propal->fetch(GETPOSTINT('propal')) > 0 && (int) $propal->socid === $socid ? (int) $propal->id : 0;
}
$back = ($fromContract > 0 ? '&contract='.$fromContract : '').($fromPropal > 0 ? '&propal='.$fromPropal : '');
// Links of the "more" menu carry the token; Dolibarr checks it for POST only.
if (in_array($action, array('startsign', 'removemanual'), true) && $_SERVER['REQUEST_METHOD'] !== 'POST' && GETPOST('token', 'alpha') !== currentToken()) {
	accessforbidden();
}
if ($action === 'addmanual') {
	if (!$write) {
		accessforbidden();
	}
	$slug = GETPOST('slug', 'aZ09arobase');
	$added = $docs->addManual($soc, (string) $slug, GETPOST('doaction', 'aZ09'), GETPOSTISSET('required') ? GETPOSTINT('required') : 1);
	if ($added > 0) {
		$documents = $docs->documents();
		setEventMessages($langs->trans('WerkbankDocsManualAdded', $documents[$slug]['title']), null, 'mesgs');
		// A template per customer goes on to its form right away.
		$next = $documents[$slug]['kind'] === 'customer' ? '&action=generateform&rule='.$added.$back.'#docform' : '#rule-'.$added;
		header('Location: '.$self.$next);
		exit;
	}
	setEventMessages($langs->trans('WerkbankDocsManualUnknown'), null, 'errors');
	$action = 'pickdoc';
}
if ($action === 'removemanual') {
	if (!$write || !$rule || empty($rule->fk_soc)) {
		accessforbidden();
	}
	if ($docs->removeManual((int) $rule->rowid, $socid) > 0) {
		setEventMessages($langs->trans('WerkbankDocsManualRemoved', $rule->label), null, 'mesgs');
	}
	header('Location: '.$self);
	exit;
}
if ($action === 'generate') {
	if (!$write || !$rule) {
		accessforbidden();
	}
	require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
	require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
	dol_include_once('/ittabelanderpdf/class/werkbankfields.class.php');
	$template = $docs->template((string) $rule->slug);
	$answers = $template ? WerkbankFields::posted(WerkbankFields::parse((string) $template->answer)) : array();
	$contract = null;
	if (GETPOSTINT('contract') > 0) {
		$contract = new Contrat($db);
		if ($contract->fetch(GETPOSTINT('contract')) <= 0 || (int) $contract->socid !== $socid) {
			$contract = null;
		}
	}
	$contact = null;
	if (GETPOSTINT('contact') > 0) {
		$contact = new Contact($db);
		if ($contact->fetch(GETPOSTINT('contact')) <= 0 || (int) $contact->socid !== $socid) {
			$contact = null;
		}
	}
	$event = $docs->generate($soc, $rule, $answers, $contract, $contact, $user, $fromPropal > 0 ? $propal : null);
	if ($event > 0) {
		setEventMessages($langs->trans('WerkbankGenDone', $rule->label), null, 'mesgs');
		// Came from a mail form: back there with the document attached.
		$mailback = GETPOST('mailback', 'alphanohtml');
		if ($mailback !== '' && strpos($mailback, DOL_URL_ROOT.'/') === 0 && strpos($mailback, '//') === false) {
			list($path, $anchor) = array_pad(explode('#', $mailback, 2), 2, '');
			header('Location: '.$path.(strpos($path, '?') === false ? '?' : '&').'ittabpdf_preselect_event='.((int) $event).'#'.($anchor !== '' ? $anchor : 'formmail'));
			exit;
		}
		header('Location: '.$self.'#rule-'.((int) $rule->rowid));
		exit;
	}
	// Back to the form with what was entered.
	setEventMessages($docs->error, null, 'errors');
	$action = 'generateform';
	$posted = array('fields' => $answers, 'contract' => $contract ? (int) $contract->id : 0, 'contact' => $contact ? (int) $contact->id : 0, 'propal' => $fromPropal);
}
if (in_array($action, array('startsign', 'upload', 'check', 'revoke'), true)) {
	if (!$write || !$rule) {
		accessforbidden();
	}
	if ($action === 'startsign') {
		// A document generated for the customer is signed on itself.
		$generated = null;
		foreach ($docs->status($soc) as $entry) {
			if ((int) $entry['rule']->rowid === (int) $rule->rowid && $entry['state'] === 'generated') {
				$generated = $entry['generated'];
			}
		}
		$event = $docs->record($socid, (string) $rule->slug, 'signing', 'device', $user, array('fk_rule' => (int) $rule->rowid,
			'element' => $generated ? 'docgen' : '', 'fk_object' => $generated ? (int) $generated->rowid : 0));
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

$form = new Form($db);
llxHeader('', $langs->trans('WerkbankDocsTab').' - '.$soc->name, '', '', 0, 0, array(), array('/ittabelanderpdf/css/docs.css'), '', 'mod-ittabelanderpdf page-docs');
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
$formFor = in_array($action, array('uploadform', 'checkform', 'revokeform', 'generateform'), true) && $rule ? $action : '';
$documents = $docs->documents();

// Progress first: how much is done, what is open.
$percent = $status ? (int) round(100 * $doneCount / count($status)) : 100;
print '<div class="'.($open ? 'warning' : 'info').' ittabdocs-summary">'
	.'<div class="ittabdocs-progress" title="'.$percent.' %"><div class="ittabdocs-progress-bar" style="width:'.$percent.'%"></div></div>'
	.'<strong>'.$langs->trans('WerkbankDocsProgress', $doneCount, count($status)).'</strong>'
	.($open ? ' · '.$langs->trans('WerkbankDocsSummaryOpen', count($open)) : ' · '.$langs->trans('WerkbankDocsSummaryDone')).'</div>';

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
	.'<td>'.$langs->trans('WerkbankDocsProof').'</td><td class="right">'.$langs->trans('WerkbankDocsActionsCol').'</td></tr>';
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
		.(!empty($rule->fk_soc) ? ' <span class="badge badge-status0">'.$langs->trans('WerkbankDocsManualBadge').'</span>' : '')
		.'<br><span class="opacitymedium small">'.dol_escape_htmltag($entry['title'])
		.($entry['stand'] !== '' ? ' · '.$langs->trans('WerkbankDocsStand', dol_print_date(dol_stringtotime($entry['stand'].' 00:00:00'), 'day')) : '').'</span></td>';
	print '<td class="center" data-label="'.dol_escape_htmltag($langs->trans('Status')).'">'.dolGetStatus($langs->trans($states[$state][0]), '', '', $states[$state][1], 5).'</td>';

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
	// A template per customer has no general PDF: it is generated for the customer.
	$isTemplate = isset($documents[$rule->slug]) && $documents[$rule->slug]['kind'] === 'customer';
	if ($isTemplate && empty($entry['generated']) && $state !== 'done') {
		$lines[] = '<span class="opacitymedium">'.$langs->trans('WerkbankDocsGenerateFirst').'</span>';
	} elseif (!$isTemplate && !$entry['exists'] && $state !== 'done') {
		$lines[] = '<span class="opacitymedium">'.$langs->trans('WerkbankDocsNoFile', dol_escape_htmltag(WerkbankUnterlagen::relativeDir().'/'.$rule->slug.'.pdf')).'</span>';
	}
	print '<td class="small ittabdocs-proof">'.implode('<br>', $lines).'</td>';

	// The main action as a button, the others as small links with an icon next to it,
	// wrapping on narrow screens; no menus.
	print '<td class="right ittabdocs-actions-cell">';
	if ($write) {
		$template = isset($documents[$rule->slug]) && $documents[$rule->slug]['kind'] === 'customer';
		$open = $state !== 'done';
		$sendUrl = '';
		if ($open && !empty($entry['generated'])) {
			$sendUrl = DOL_URL_ROOT.'/societe/card.php?socid='.$socid.'&action=presend&mode=init&ittabpdf_preselect_event='.((int) $entry['generated']->rowid).'#formmail';
		} elseif ($open && $entry['exists'] && !$template) {
			$sendUrl = DOL_URL_ROOT.'/societe/card.php?socid='.$socid.'&action=presend&mode=init&ittabpdf_preselect='.urlencode($rule->slug.'.pdf').'#formmail';
		}
		$signable = $open && !$entry['signing'] && ($entry['exists'] && !$template || !empty($entry['generated']));
		$signShort = $rule->action === 'handover' ? 'WerkbankDocsActionReceiptShort' : 'WerkbankDocsActionSignShort';
		$signUrl = $self.'&action=startsign&rule='.$id.'&token='.newToken();
		// label key, url, picto, main
		$actions = array();
		if ($open && $entry['signing']) {
			$actions[] = array('WerkbankDocsActionContinueShort', dol_buildpath('/ittabelanderpdf/sign.php', 1).'?kind=doc&id='.((int) $entry['signing']->rowid), 'fa-signature');
		}
		// Not generated yet: generating is the next step; generated: it moves to the end.
		if ($template && empty($entry['generated'])) {
			$actions[] = array('WerkbankDocsActionGenerateShort', $self.'&action=generateform&rule='.$id.'#docform', 'fa-file-signature');
		}
		if ($signable && $rule->action === 'sign') {
			$actions[] = array($signShort, $signUrl, 'fa-signature');
		}
		if ($sendUrl !== '') {
			$actions[] = array('WerkbankDocsActionSendShort', $sendUrl, 'fa-paper-plane');
		}
		if ($signable && $rule->action !== 'sign') {
			$actions[] = array($signShort, $signUrl, 'fa-signature');
		}
		$actions[] = array('WerkbankDocsActionUploadShort', $self.'&action=uploadform&rule='.$id.'#docform', 'fa-upload');
		if ($template && !empty($entry['generated'])) {
			$actions[] = array('WerkbankDocsActionGenerateAgainShort', $self.'&action=generateform&rule='.$id.'#docform', 'fa-file-signature');
		}
		$actions[] = array('WerkbankDocsActionTickShort', $self.'&action=checkform&rule='.$id.'#docform', 'fa-check');
		if (!$open) {
			$actions[] = array('WerkbankDocsActionRevokeShort', $self.'&action=revokeform&rule='.$id.'#docform', 'fa-undo');
		}
		if (!empty($rule->fk_soc)) {
			$actions[] = array('WerkbankDocsActionRemoveShort', $self.'&action=removemanual&rule='.$id.'&token='.newToken(), 'fa-trash');
		}
		// The first one is what to do next, unless the document is done.
		print '<div class="ittabdocs-actions">';
		foreach ($actions as $index => $act) {
			$main = $index === 0 && $open;
			print '<a class="'.($main ? 'butAction smallpaddingimp' : 'ittabdocs-action').'" href="'.dol_escape_htmltag($act[1]).'">'
				.($main ? '' : '<span class="fa '.$act[2].' paddingright"></span>').$langs->trans($act[0]).'</a>';
		}
		print '</div>';
	}
	print '</td></tr>';
}
print '</table></div>';

// Generate for this customer: contract, contact and the template's input fields.
if ($write && $formFor === 'generateform') {
	dol_include_once('/ittabelanderpdf/class/werkbankfields.class.php');
	dol_include_once('/ittabelanderpdf/class/werkbankvars.class.php');
	$template = $docs->template((string) $rule->slug);
	print '<a id="docform"></a>'.load_fiche_titre($langs->trans('WerkbankGenTitle', dol_escape_htmltag($rule->label)), '', '');
	if (!$template) {
		print '<div class="warning">'.$langs->trans('WerkbankGenNoTemplate', dol_escape_htmltag($rule->slug)).'</div>';
	} else {
		$last = isset($posted) ? $posted : $docs->lastValues($socid, (string) $rule->slug);
		$fields = WerkbankFields::parse((string) $template->answer);
		$contracts = $docs->contracts($socid, (string) $rule->products);
		$contacts = array(0 => '');
		foreach ($soc->contact_array_objects() as $person) {
			$contacts[(int) $person->id] = $person->getFullName($langs).($person->poste ? ' ('.$person->poste.')' : '');
		}
		print '<form method="POST" action="'.$self.'">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="generate"><input type="hidden" name="rule" value="'.((int) $rule->rowid).'">';
		if ($fromPropal > 0) {
			print '<input type="hidden" name="propal" value="'.$fromPropal.'">';
		}
		if (GETPOST('mailback', 'alphanohtml') !== '') {
			print '<input type="hidden" name="mailback" value="'.dol_escape_htmltag(GETPOST('mailback', 'alphanohtml')).'">';
		}
		$chosenContract = $fromContract > 0 && isset($contracts[$fromContract]) ? $fromContract : ($last['contract'] ?: (int) key($contracts));
		print '<table class="border centpercent tableforfield">';
		if ($fromPropal > 0) {
			print '<tr><td class="titlefield">'.$langs->trans('Proposal').'</td><td>'.$propal->getNomUrl(1).' <span class="opacitymedium">'.$langs->trans('WerkbankDocsManualCopyTo').'</span></td></tr>';
		}
		print '<tr><td class="titlefield">'.$langs->trans('Contract').'</td><td>'.$form->selectarray('contract', array(0 => '') + $contracts, $chosenContract, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
		print '<tr><td>'.$langs->trans('Contact').'</td><td>'.$form->selectarray('contact', $contacts, $last['contact'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
		print WerkbankFields::inputs($fields, $last['fields']);
		print '</table>';
		print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('WerkbankGenButton')).'"> <a class="button button-cancel" href="'.$self.'">'.$langs->trans('Cancel').'</a></div>';
		print '<div class="center opacitymedium">'.$langs->trans('WerkbankGenHelp').'</div></form>';
	}
}

// The form the chosen action needs, the way Dolibarr cards show them.
if ($write && $formFor && $formFor !== 'generateform') {
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

// Add any released document to this customer by hand.
if ($write) {
	$documents = isset($documents) ? $documents : $docs->documents();
	$kinds = array('legal' => 'WerkbankDocKind_legal', 'internal' => 'WerkbankDocKind_internal', 'customer' => 'WerkbankDocKind_customer');
	$choices = array();
	foreach ($documents as $slug => $document) {
		if ($document['kind'] !== 'customer' && empty($document['sendable'])) {
			continue;
		}
		$choices[$slug] = $document['title'].' ('.$langs->trans($kinds[$document['kind']] ?? 'WerkbankDocKind_internal').')';
	}
	print '<a id="docadd"></a>'.load_fiche_titre($langs->trans('WerkbankDocsManualTitle'), '', 'add');
	if (!$choices) {
		print '<div class="opacitymedium">'.$langs->trans('WerkbankDocsManualNone').'</div>';
	} else {
		print '<form method="POST" action="'.$self.'#docadd">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="addmanual">';
		if ($fromContract > 0) {
			print '<input type="hidden" name="contract" value="'.$fromContract.'">';
		}
		if ($fromPropal > 0) {
			print '<input type="hidden" name="propal" value="'.$fromPropal.'">';
		}
		print '<table class="border centpercent tableforfield">';
		print '<tr><td class="titlefield fieldrequired">'.$langs->trans('WerkbankDocSignDocument').'</td><td>'.$form->selectarray('slug', $choices, GETPOST('slug', 'aZ09arobase'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
		print '<tr><td>'.$langs->trans('WerkbankDocsManualAction').'</td><td>'.$form->selectarray('doaction', array(
			'' => $langs->trans('WerkbankDocsManualActionAuto'), 'accept' => $langs->trans('WerkbankDocsAction_accept'),
			'sign' => $langs->trans('WerkbankDocsAction_sign'), 'handover' => $langs->trans('WerkbankDocsAction_handover')), '', 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
		print '<tr><td>'.$langs->trans('WerkbankQsRequired').'</td><td><input type="hidden" name="required" value="0"><input type="checkbox" name="required" value="1" checked> '
			.'<span class="opacitymedium">'.$langs->trans('WerkbankDocsManualRequiredHelp').'</span></td></tr>';
		print '</table>';
		print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('WerkbankDocsManualAdd')).'"></div>';
		print '<div class="center opacitymedium">'.$langs->trans('WerkbankDocsManualHelp').'</div></form>';
	}
}

// Everything that happened, newest first.
$events = $docs->events($socid);
if ($events) {
	print load_fiche_titre($langs->trans('WerkbankDocsHistory', count($events)), '', '');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent ittabdocs-history">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('WerkbankDocSignDocument').'</td><td>'.$langs->trans('Event').'</td><td>'.$langs->trans('User').'</td><td>'.$langs->trans('Note').'</td></tr>';
	foreach ($events as $event) {
		$title = isset($documents[$event->slug]) ? $documents[$event->slug]['title'] : $event->slug;
		print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($db->jdate($event->date_event), 'dayhour').'</td><td>'.dol_escape_htmltag($title).'</td>'
			.'<td>'.$langs->trans('WerkbankDocsEvent_'.$event->state).$fileLink($event->filename).'</td><td>'.dol_escape_htmltag($who($event)).'</td><td>'.dol_escape_htmltag((string) $event->note).'</td></tr>';
	}
	print '</table></div>';
}

llxFooter();
$db->close();
