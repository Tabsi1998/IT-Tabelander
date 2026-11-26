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
 * QS on a phone or the workshop tablet, in four steps:
 *   qs.php                  choose the customer
 *   qs.php?socid=N          choose an open intervention or start one
 *   qs.php?fichinter=N      the intervention's QS runs, start one by kind of job
 *   qs.php?id=N             tick the points (saved one by one), close the QS
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
require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/fichinter.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
dol_include_once('/ittabelanderpdf/class/werkbankqs.class.php');

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
/** @var Conf $conf */

$langs->loadLangs(array('interventions', 'companies', 'ittabelanderpdf@ittabelanderpdf'));
if (!isModEnabled('ficheinter') || !$user->hasRight('ittabelanderpdf', 'qs', 'run') || !$user->hasRight('ficheinter', 'lire')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$runId = GETPOSTINT('id');
$fichinterId = GETPOSTINT('fichinter');
$socid = GETPOSTINT('socid');
if ($user->socid > 0) {
	accessforbidden();
}
$qs = new WerkbankQs($db);
$self = dol_buildpath('/ittabelanderpdf/qs.php', 1);

$run = $runId > 0 ? $qs->run($runId) : null;
if ($runId > 0 && !$run) {
	accessforbidden();
}
if ($run) {
	$fichinterId = (int) $run->fk_fichinter;
}
$fichinter = null;
if ($fichinterId > 0) {
	$fichinter = new Fichinter($db);
	if ($fichinter->fetch($fichinterId) <= 0) {
		accessforbidden();
	}
	$fichinter->fetch_thirdparty();
	$socid = (int) $fichinter->socid;
}
$thirdparty = null;
if ($socid > 0) {
	$thirdparty = new Societe($db);
	if ($thirdparty->fetch($socid) <= 0) {
		accessforbidden();
	}
}

/*
 * Actions
 */

if ($action === 'create_fichinter' && $thirdparty && $user->hasRight('ficheinter', 'creer')) {
	$new = new Fichinter($db);
	$new->socid = $thirdparty->id;
	$new->user_author_id = $user->id;
	$new->description = trim(GETPOST('description', 'restricthtml'));
	$new->duration = 0;
	$new->model_pdf = getDolGlobalString('FICHEINTER_ADDON_PDF');
	$created = $new->create($user);
	if ($created > 0) {
		header('Location: '.$self.'?fichinter='.((int) $created));
		exit;
	}
	setEventMessages($new->error, $new->errors, 'errors');
	$action = '';
}
if ($action === 'start' && $fichinter) {
	$started = $qs->start($fichinter, GETPOSTINT('type'), $user);
	if ($started > 0) {
		header('Location: '.$self.'?id='.$started);
		exit;
	}
	setEventMessages($langs->trans($qs->error), null, 'errors');
	$action = '';
}
// Without JavaScript the whole list comes in one post.
if ($action === 'save' && $run) {
	foreach ($qs->results((int) $run->rowid) as $result) {
		$id = (int) $result->rowid;
		$checked = GETPOSTINT('skip_'.$id) ? WerkbankQs::SKIPPED : (GETPOSTINT('checked_'.$id) ? WerkbankQs::DONE : WerkbankQs::OPEN);
		$qs->save((int) $run->rowid, $id, $checked, GETPOST('value_'.$id, 'alphanohtml'), GETPOST('note_'.$id, 'alphanohtml'), $user);
	}
	setEventMessages($langs->trans('WerkbankQsSaved'), null, 'mesgs');
	header('Location: '.$self.'?id='.((int) $run->rowid));
	exit;
}
if ($action === 'close' && $run && $fichinter) {
	$closed = $qs->close((int) $run->rowid, $fichinter, $user, GETPOST('note', 'alphanohtml'));
	if ($closed === -2) {
		setEventMessages($langs->trans('WerkbankQsRequiredOpen', $qs->error), null, 'errors');
		header('Location: '.$self.'?id='.((int) $run->rowid).'&missing=1#ittabqs-close');
		exit;
	}
	if ($closed < 0) {
		setEventMessages($langs->trans('WerkbankQsCloseFailed', $langs->transnoentities($qs->error)), null, 'errors');
	} else {
		setEventMessages($langs->trans('WerkbankQsClosed'), null, 'mesgs');
		// Next step on the same device: sign, then send.
		if (trim((string) $run->signers) !== '') {
			header('Location: '.dol_buildpath('/ittabelanderpdf/sign.php', 1).'?kind=qs&id='.((int) $run->rowid));
			exit;
		}
	}
	header('Location: '.$self.'?id='.((int) $run->rowid));
	exit;
}
if ($action === 'reopen' && $run && $user->hasRight('ittabelanderpdf', 'qs', 'manage')) {
	$qs->reopen((int) $run->rowid);
	header('Location: '.$self.'?id='.((int) $run->rowid));
	exit;
}

/*
 * View
 */

$title = $langs->trans('WerkbankQsMenu');
llxHeader('', $title, '', '', 0, 0, array('/ittabelanderpdf/js/qs.js'), array('/ittabelanderpdf/css/qs.css'), '', 'mod-ittabelanderpdf page-qs');

if ($fichinter) {
	$head = fichinter_prepare_head($fichinter);
	print dol_get_fiche_head($head, 'ittabpdfqs', $langs->trans('InterventionCard'), -1, 'intervention');
	$linkback = '<a href="'.DOL_URL_ROOT.'/fichinter/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
	dol_banner_tab($fichinter, 'ref', $linkback, 1, 'ref', 'ref', '');
	print dol_get_fiche_end();
} elseif ($thirdparty) {
	$head = societe_prepare_head($thirdparty);
	print dol_get_fiche_head($head, 'ittabpdfqs', $langs->trans('ThirdParty'), -1, 'company');
	dol_banner_tab($thirdparty, 'socid', '', ($user->socid ? 0 : 1), 'rowid', 'nom');
	print dol_get_fiche_end();
}

if ($run) {
	ittabqs_show_run($db, $qs, $run, $fichinter, $self);
} elseif ($fichinter) {
	ittabqs_show_fichinter($qs, $fichinter, $self);
} elseif ($thirdparty) {
	ittabqs_show_customer($db, $thirdparty, $self);
} else {
	ittabqs_show_start($db, $self);
}

llxFooter();
$db->close();


/**
 * Step 1: the customer, searchable.
 *
 * @param DoliDB $db   Database
 * @param string $self URL of this page
 * @return void
 */
function ittabqs_show_start($db, $self)
{
	global $langs, $form;
	if (!is_object($form)) {
		$form = new Form($db);
	}
	print load_fiche_titre($langs->trans('WerkbankQsMenu'), '', 'intervention');
	print '<form method="GET" action="'.$self.'">';
	print '<table class="border centpercent tableforfield"><tr><td class="titlefield">'.$langs->trans('WerkbankQsChooseCustomer').'</td><td>';
	print $form->select_company('', 'socid', '((s.client:IN:1,2,3) AND (s.status:=:1))', 'SelectThirdParty', 0, 0, array(), 0, 'minwidth300');
	print ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('WerkbankQsNext')).'">';
	print '</td></tr></table></form>';
}

/**
 * Step 2: open interventions of the customer, or a new one.
 *
 * @param DoliDB  $db         Database
 * @param Societe $thirdparty Customer
 * @param string  $self       URL of this page
 * @return void
 */
function ittabqs_show_customer($db, $thirdparty, $self)
{
	global $langs, $user;
	$sql = "SELECT rowid, ref, description, fk_statut, datec FROM ".MAIN_DB_PREFIX."fichinter"
		." WHERE fk_soc = ".((int) $thirdparty->id)." AND entity IN (".getEntity('intervention').") AND fk_statut IN (0, 1)"
		." ORDER BY datec DESC";
	$resql = $db->query($db->plimit($sql, 20));
	print load_fiche_titre($langs->trans('WerkbankQsOpenInterventions'), '', '');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Description').'</td><td></td></tr>';
	$count = 0;
	while ($resql && ($row = $db->fetch_object($resql))) {
		$count++;
		print '<tr class="oddeven"><td class="nowraponall"><a href="'.$self.'?fichinter='.((int) $row->rowid).'">'.img_picto('', 'intervention', 'class="pictofixedwidth"').dol_escape_htmltag($row->ref).'</a></td>';
		print '<td>'.dol_print_date($db->jdate($row->datec), 'day').'</td>';
		print '<td class="tdoverflowmax300">'.dol_escape_htmltag(dol_trunc(dol_string_nohtmltag((string) $row->description), 90)).'</td>';
		print '<td class="right"><a class="butAction smallpaddingimp" href="'.$self.'?fichinter='.((int) $row->rowid).'">'.$langs->trans('WerkbankQsNext').'</a></td></tr>';
	}
	if (!$count) {
		print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('WerkbankQsNoOpenIntervention').'</span></td></tr>';
	}
	print '</table></div>';
	if ($user->hasRight('ficheinter', 'creer')) {
		print load_fiche_titre($langs->trans('WerkbankQsNewIntervention'), '', '');
		print '<form method="POST" action="'.$self.'?socid='.((int) $thirdparty->id).'">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="create_fichinter">';
		print '<table class="border centpercent tableforfield"><tr><td class="titlefield">'.$langs->trans('Description').'</td><td>';
		print '<textarea name="description" rows="2" class="quatrevingtpercent" placeholder="'.dol_escape_htmltag($langs->trans('WerkbankQsNewInterventionHint')).'"></textarea></td></tr></table>';
		print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('WerkbankQsCreateIntervention')).'"></div></form>';
	}
}

/**
 * Step 3: QS runs of the intervention and the kinds of job to start one.
 *
 * @param WerkbankQs $qs        QS
 * @param Fichinter  $fichinter Intervention
 * @param string     $self      URL of this page
 * @return void
 */
function ittabqs_show_fichinter($qs, $fichinter, $self)
{
	global $langs;
	$runs = $qs->runsFor((int) $fichinter->id);
	if ($runs) {
		print load_fiche_titre($langs->trans('WerkbankQsRuns'), '', '');
		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
		print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankQsKind').'</td><td class="center">'.$langs->trans('Status').'</td><td class="right">'.$langs->trans('WerkbankQsPoints').'</td></tr>';
		foreach ($runs as $run) {
			$closed = (int) $run->status === 1;
			print '<tr class="oddeven"><td><a href="'.$self.'?id='.((int) $run->rowid).'">'.dol_escape_htmltag($run->type_label).'</a></td>';
			print '<td class="center">'.dolGetStatus($langs->trans($closed ? 'WerkbankQsStateClosed' : 'WerkbankQsStateOpen'), '', '', $closed ? 'status4' : 'status1', 5).'</td>';
			print '<td class="right">'.$langs->trans('WerkbankQsProgress', $run->counts['done'], $run->counts['total']).'</td></tr>';
		}
		print '</table></div>';
	}
	$types = $qs->types(true);
	print load_fiche_titre($langs->trans('WerkbankQsStart'), '', '');
	if (!$types) {
		print '<div class="opacitymedium">'.$langs->trans('WerkbankQsNoTypes').'</div>';
		return;
	}
	print '<form method="POST" action="'.$self.'?fichinter='.((int) $fichinter->id).'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="start">';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankQsKind').'</td><td class="right">'.$langs->trans('WerkbankQsPoints').'</td><td></td></tr>';
	foreach ($types as $type) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($type->label).'</td><td class="right">'.count($qs->items((int) $type->rowid, true)).'</td>';
		print '<td class="right"><button type="submit" name="type" value="'.((int) $type->rowid).'" class="button smallpaddingimp ittabqs-kind">'.$langs->trans('WerkbankQsStartButton').'</button></td></tr>';
	}
	print '</table></div></form>';
}

/**
 * Step 4: the checklist. Each change is saved at once (js/qs.js); the
 * button below the list saves everything for browsers without JavaScript.
 *
 * @param DoliDB     $db        Database
 * @param WerkbankQs $qs        QS
 * @param object     $run       Run
 * @param Fichinter  $fichinter Intervention
 * @param string     $self      URL of this page
 * @return void
 */
function ittabqs_show_run($db, $qs, $run, $fichinter, $self)
{
	global $langs, $user;
	$closed = (int) $run->status === 1;
	$results = $qs->results((int) $run->rowid);
	$counts = $qs->counts((int) $run->rowid);
	$missing = GETPOSTINT('missing') === 1;

	print load_fiche_titre(dol_escape_htmltag($run->type_label), '', '');
	print '<div class="ittabqs-status" id="ittabqs-status"><span class="ittabqs-status-text" data-done-label="'.dol_escape_htmltag($langs->transnoentities('WerkbankQsProgress', '%d', '%t')).'"'
		.' data-required-label="'.dol_escape_htmltag($langs->transnoentities('WerkbankQsRequiredLeft', '%r')).'">'
		.$langs->trans('WerkbankQsProgress', $counts['done'], $counts['total'])
		.($counts['required_open'] ? ' · <strong>'.$langs->trans('WerkbankQsRequiredLeft', $counts['required_open']).'</strong>' : '').'</span></div>';

	if ($closed) {
		$file = $run->filename ? dol_sanitizeFileName($fichinter->ref).'/'.$run->filename : '';
		print '<div class="info ittabqs-done">'.$langs->trans('WerkbankQsClosedOn', dol_print_date($db->jdate($run->date_close), 'dayhour', 'tzuser'));
		if (!empty($run->date_sent)) {
			print ' '.$langs->trans('WerkbankSignSentOn', dol_print_date($db->jdate($run->date_sent), 'dayhour', 'tzuser'), dol_escape_htmltag((string) $run->sent_to));
		}
		print '</div><div class="tabsAction">';
		if ($file !== '' && is_file($GLOBALS['conf']->ficheinter->dir_output.'/'.$file)) {
			print '<a class="butAction" href="'.DOL_URL_ROOT.'/document.php?modulepart=ficheinter&amp;file='.urlencode($file).'">'.$langs->trans('WerkbankQsOpenPdf').'</a>';
		}
		print '<a class="butAction" href="'.dol_buildpath('/ittabelanderpdf/sign.php', 1).'?kind=qs&amp;id='.((int) $run->rowid).'">'.$langs->trans('WerkbankSignAndSend').'</a>';
		if ($user->hasRight('ittabelanderpdf', 'qs', 'manage')) {
			print '<form method="POST" action="'.$self.'?id='.((int) $run->rowid).'" class="inline-block"><input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="reopen"><input type="submit" class="butActionDelete" value="'.dol_escape_htmltag($langs->trans('WerkbankQsReopen')).'"></form>';
		}
		print '</div>';
	}

	print '<form method="POST" action="'.$self.'?id='.((int) $run->rowid).'" id="ittabqs-form"'
		.' data-endpoint="'.dol_escape_htmltag(dol_buildpath('/ittabelanderpdf/ajax/qs.php', 1)).'" data-token="'.dol_escape_htmltag(currentToken()).'"'
		.' data-run="'.((int) $run->rowid).'" data-saved="'.dol_escape_htmltag($langs->transnoentities('WerkbankQsSavedShort')).'"'
		.' data-failed="'.dol_escape_htmltag($langs->transnoentities('WerkbankQsSaveFailed')).'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
	print '<div class="div-table-responsive"><table class="noborder centpercent ittabqs-list">';
	print '<tr class="liste_titre"><td class="width25"></td><td>'.$langs->trans('WerkbankQsItemLabel').'</td><td>'.$langs->trans('Value').'</td>'
		.'<td class="center">'.$langs->trans('WerkbankQsSkip').'</td><td>'.$langs->trans('WerkbankQsNote').'</td></tr>';
	foreach ($results as $result) {
		$id = (int) $result->rowid;
		$done = WerkbankQs::isDone($result);
		$skipped = (int) $result->checked === WerkbankQs::SKIPPED;
		$classes = array('oddeven', 'ittabqs-item', 'kind-'.$result->kind);
		if ($done) {
			$classes[] = 'is-done';
		}
		if ($skipped) {
			$classes[] = 'is-skipped';
		}
		if ($missing && (int) $result->required && !$done) {
			$classes[] = 'is-missing';
		}
		$disabled = $closed ? ' disabled' : '';
		$who = trim($result->firstname.' '.$result->lastname);
		$meta = $result->date_check ? dol_escape_htmltag(($who !== '' ? $who : $result->login).' · '.dol_print_date($db->jdate($result->date_check), 'dayhour', 'tzuser')) : '';
		print '<tr class="'.implode(' ', $classes).'" data-id="'.$id.'" data-kind="'.dol_escape_htmltag($result->kind).'" data-required="'.((int) $result->required).'">';
		print '<td class="center">';
		if ($result->kind === 'check') {
			print '<input type="checkbox" id="ittabqs-check-'.$id.'" name="checked_'.$id.'" value="1" class="ittabqs-check"'.((int) $result->checked === WerkbankQs::DONE ? ' checked' : '').$disabled.'>';
		}
		print '</td><td><label for="'.($result->kind === 'check' ? 'ittabqs-check-' : 'ittabqs-value-').$id.'">'.dol_escape_htmltag($result->label).'</label>'
			.((int) $result->required ? ' <span class="badge badge-status1">'.$langs->trans('WerkbankQsRequired').'</span>' : '');
		if ((string) $result->help !== '') {
			print '<br><span class="opacitymedium small">'.dol_escape_htmltag($result->help).'</span>';
		}
		print '<br><span class="opacitymedium small ittabqs-meta">'.$meta.'</span></td><td>';
		if ($result->kind === 'value') {
			print '<input type="text" inputmode="decimal" autocomplete="off" id="ittabqs-value-'.$id.'" name="value_'.$id.'" class="width75" value="'.dol_escape_htmltag((string) $result->value).'"'.$disabled.'> '.dol_escape_htmltag((string) $result->unit);
		} elseif ($result->kind === 'text') {
			print '<textarea id="ittabqs-value-'.$id.'" name="value_'.$id.'" rows="2" class="minwidth200"'.$disabled.'>'.dol_escape_htmltag((string) $result->value, 0, 1).'</textarea>';
		}
		print '</td><td class="center"><input type="checkbox" name="skip_'.$id.'" value="1"'.($skipped ? ' checked' : '').$disabled.' aria-label="'.dol_escape_htmltag($langs->trans('WerkbankQsSkip').': '.$result->label).'"></td>';
		print '<td><input type="text" name="note_'.$id.'" class="minwidth150" value="'.dol_escape_htmltag((string) $result->note).'"'.$disabled.' aria-label="'.dol_escape_htmltag($langs->trans('WerkbankQsNote').': '.$result->label).'"></td></tr>';
	}
	print '</table></div>';
	if (!$closed) {
		print '<noscript><div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('WerkbankQsSaveAll')).'"></div></noscript>';
	}
	print '</form>';

	if (!$closed) {
		print '<form method="POST" action="'.$self.'?id='.((int) $run->rowid).'" id="ittabqs-close">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="close">';
		print '<table class="border centpercent tableforfield"><tr><td class="titlefield">'.$langs->trans('WerkbankQsClosingNote').'</td><td>';
		print '<textarea name="note" rows="2" class="quatrevingtpercent">'.dol_escape_htmltag((string) $run->note, 0, 1).'</textarea></td></tr></table>';
		print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('WerkbankQsClose')).'"></div>';
		print '<div class="center opacitymedium">'.$langs->trans('WerkbankQsCloseHelp').'</div>';
		print '</form>';
	}
}
