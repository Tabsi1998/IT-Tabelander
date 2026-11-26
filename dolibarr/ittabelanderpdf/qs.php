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
llxHeader('<meta name="viewport" content="width=device-width, initial-scale=1">', $title, '', '', 0, 0,
	array('/ittabelanderpdf/js/qs.js'), array('/ittabelanderpdf/css/qs.css'), '', 'mod-ittabelanderpdf page-qs');

if ($fichinter) {
	$head = fichinter_prepare_head($fichinter);
	print dol_get_fiche_head($head, 'ittabpdfqs', $langs->trans('InterventionCard'), -1, 'intervention');
} elseif ($thirdparty) {
	$head = societe_prepare_head($thirdparty);
	print dol_get_fiche_head($head, 'ittabpdfqs', $langs->trans('ThirdParty'), -1, 'company');
}

print '<div class="ittabqs">';

// Where we are: customer › intervention › kind of job, each a way back.
$crumbs = array('<a href="'.$self.'">'.$langs->trans('WerkbankQsAllCustomers').'</a>');
if ($thirdparty) {
	$crumbs[] = '<a href="'.$self.'?socid='.((int) $thirdparty->id).'">'.dol_escape_htmltag($thirdparty->name).'</a>';
}
if ($fichinter) {
	$crumbs[] = '<a href="'.$self.'?fichinter='.((int) $fichinter->id).'">'.dol_escape_htmltag($fichinter->ref).'</a>';
}
if ($run) {
	$crumbs[] = '<span>'.dol_escape_htmltag($run->type_label).'</span>';
}
print '<nav class="ittabqs-crumbs" aria-label="'.dol_escape_htmltag($langs->trans('WerkbankQsMenu')).'">'.implode('<span class="ittabqs-sep" aria-hidden="true">›</span>', $crumbs).'</nav>';

if ($run) {
	ittabqs_show_run($db, $qs, $run, $fichinter, $self);
} elseif ($fichinter) {
	ittabqs_show_fichinter($qs, $fichinter, $self);
} elseif ($thirdparty) {
	ittabqs_show_customer($db, $thirdparty, $self);
} else {
	ittabqs_show_start($db, $self);
}

print '</div>';
if ($fichinter || $thirdparty) {
	print dol_get_fiche_end();
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
	print '<h1 class="ittabqs-title">'.$langs->trans('WerkbankQsChooseCustomer').'</h1>';
	print '<form method="GET" action="'.$self.'" class="ittabqs-panel">';
	print '<label class="ittabqs-label" for="socid">'.$langs->trans('ThirdParty').'</label>';
	print $form->select_company('', 'socid', '((s.client:IN:1,2,3) AND (s.status:=:1))', 'SelectThirdParty', 0, 0, array(), 0, 'ittabqs-wide');
	print '<button type="submit" class="ittabqs-primary">'.$langs->trans('WerkbankQsNext').'</button>';
	print '</form>';
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
	print '<h1 class="ittabqs-title">'.dol_escape_htmltag($thirdparty->name).'</h1>';
	$sql = "SELECT rowid, ref, description, fk_statut, datec FROM ".MAIN_DB_PREFIX."fichinter"
		." WHERE fk_soc = ".((int) $thirdparty->id)." AND entity IN (".getEntity('intervention').") AND fk_statut IN (0, 1)"
		." ORDER BY datec DESC";
	$resql = $db->query($db->plimit($sql, 20));
	$rows = array();
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$rows[] = $obj;
	}
	print '<h2 class="ittabqs-subtitle">'.$langs->trans('WerkbankQsOpenInterventions').'</h2>';
	if (!$rows) {
		print '<p class="ittabqs-empty">'.$langs->trans('WerkbankQsNoOpenIntervention').'</p>';
	} else {
		print '<ul class="ittabqs-choices">';
		foreach ($rows as $row) {
			$description = dol_trunc(dol_string_nohtmltag((string) $row->description), 90);
			print '<li><a class="ittabqs-choice" href="'.$self.'?fichinter='.((int) $row->rowid).'">';
			print '<span class="ittabqs-choice-main">'.dol_escape_htmltag($row->ref).'</span>';
			print '<span class="ittabqs-choice-sub">'.dol_print_date($db->jdate($row->datec), 'day').($description !== '' ? ' · '.dol_escape_htmltag($description) : '').'</span>';
			print '</a></li>';
		}
		print '</ul>';
	}
	if ($user->hasRight('ficheinter', 'creer')) {
		print '<form method="POST" action="'.$self.'?socid='.((int) $thirdparty->id).'" class="ittabqs-panel">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="create_fichinter">';
		print '<label class="ittabqs-label" for="ittabqs-description">'.$langs->trans('WerkbankQsNewIntervention').'</label>';
		print '<textarea id="ittabqs-description" name="description" rows="2" class="ittabqs-input" placeholder="'.dol_escape_htmltag($langs->trans('WerkbankQsNewInterventionHint')).'"></textarea>';
		print '<button type="submit" class="ittabqs-primary">'.$langs->trans('WerkbankQsCreateIntervention').'</button>';
		print '</form>';
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
	print '<h1 class="ittabqs-title">'.dol_escape_htmltag($fichinter->ref).' <span class="ittabqs-title-sub">'.dol_escape_htmltag($fichinter->thirdparty->name).'</span></h1>';
	$runs = $qs->runsFor((int) $fichinter->id);
	if ($runs) {
		print '<h2 class="ittabqs-subtitle">'.$langs->trans('WerkbankQsRuns').'</h2><ul class="ittabqs-choices">';
		foreach ($runs as $run) {
			$closed = (int) $run->status === 1;
			print '<li><a class="ittabqs-choice'.($closed ? ' is-closed' : '').'" href="'.$self.'?id='.((int) $run->rowid).'">';
			print '<span class="ittabqs-choice-main">'.dol_escape_htmltag($run->type_label).'</span>';
			print '<span class="ittabqs-choice-sub">'.($closed ? $langs->trans('WerkbankQsStateClosed') : $langs->trans('WerkbankQsStateOpen'))
				.' · '.$langs->trans('WerkbankQsProgress', $run->counts['done'], $run->counts['total']).'</span>';
			print '</a></li>';
		}
		print '</ul>';
	}
	$types = $qs->types(true);
	print '<h2 class="ittabqs-subtitle">'.$langs->trans('WerkbankQsStart').'</h2>';
	if (!$types) {
		print '<p class="ittabqs-empty">'.$langs->trans('WerkbankQsNoTypes').'</p>';
		return;
	}
	print '<form method="POST" action="'.$self.'?fichinter='.((int) $fichinter->id).'" class="ittabqs-kinds">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="start">';
	foreach ($types as $type) {
		$count = count($qs->items((int) $type->rowid, true));
		print '<button type="submit" name="type" value="'.((int) $type->rowid).'" class="ittabqs-kind">';
		print '<span class="ittabqs-choice-main">'.dol_escape_htmltag($type->label).'</span>';
		print '<span class="ittabqs-choice-sub">'.$langs->trans('WerkbankQsPointCount', $count).'</span></button>';
	}
	print '</form>';
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

	print '<h1 class="ittabqs-title">'.dol_escape_htmltag($run->type_label).' <span class="ittabqs-title-sub">'.dol_escape_htmltag($fichinter->thirdparty->name).'</span></h1>';
	print '<div class="ittabqs-status" id="ittabqs-status" aria-live="polite" data-total="'.$counts['total'].'">';
	print '<div class="ittabqs-meter" role="progressbar" aria-valuemin="0" aria-valuemax="'.$counts['total'].'" aria-valuenow="'.$counts['done'].'">'
		.'<span style="width:'.($counts['total'] ? round(100 * $counts['done'] / $counts['total']) : 0).'%"></span></div>';
	print '<span class="ittabqs-status-text" data-done-label="'.dol_escape_htmltag($langs->transnoentities('WerkbankQsProgress', '%d', '%t')).'"'
		.' data-required-label="'.dol_escape_htmltag($langs->transnoentities('WerkbankQsRequiredLeft', '%r')).'">'
		.$langs->trans('WerkbankQsProgress', $counts['done'], $counts['total'])
		.($counts['required_open'] ? ' · <strong>'.$langs->trans('WerkbankQsRequiredLeft', $counts['required_open']).'</strong>' : '').'</span>';
	print '</div>';

	if ($closed) {
		$file = $run->filename ? dol_sanitizeFileName($fichinter->ref).'/'.$run->filename : '';
		print '<div class="ittabqs-done">';
		print '<p>'.$langs->trans('WerkbankQsClosedOn', dol_print_date($db->jdate($run->date_close), 'dayhour', 'tzuser')).'</p>';
		if ($file !== '' && is_file($GLOBALS['conf']->ficheinter->dir_output.'/'.$file)) {
			print '<a class="ittabqs-primary" href="'.DOL_URL_ROOT.'/document.php?modulepart=ficheinter&amp;file='.urlencode($file).'">'.img_picto('', 'pdf', 'class="pictofixedwidth"').$langs->trans('WerkbankQsOpenPdf').'</a>';
		}
		if ($user->hasRight('ittabelanderpdf', 'qs', 'manage')) {
			print '<form method="POST" action="'.$self.'?id='.((int) $run->rowid).'"><input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="reopen"><button type="submit" class="ittabqs-secondary">'.$langs->trans('WerkbankQsReopen').'</button></form>';
		}
		print '</div>';
	}

	print '<form method="POST" action="'.$self.'?id='.((int) $run->rowid).'" id="ittabqs-form" class="ittabqs-form"'
		.' data-endpoint="'.dol_escape_htmltag(dol_buildpath('/ittabelanderpdf/ajax/qs.php', 1)).'" data-token="'.dol_escape_htmltag(currentToken()).'"'
		.' data-run="'.((int) $run->rowid).'" data-saved="'.dol_escape_htmltag($langs->transnoentities('WerkbankQsSavedShort')).'"'
		.' data-failed="'.dol_escape_htmltag($langs->transnoentities('WerkbankQsSaveFailed')).'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
	print '<ol class="ittabqs-list">';
	foreach ($results as $result) {
		$id = (int) $result->rowid;
		$done = WerkbankQs::isDone($result);
		$skipped = (int) $result->checked === WerkbankQs::SKIPPED;
		$classes = array('ittabqs-item', 'kind-'.$result->kind);
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
		print '<li class="'.implode(' ', $classes).'" data-id="'.$id.'" data-kind="'.dol_escape_htmltag($result->kind).'" data-required="'.((int) $result->required).'">';
		$label = '<span class="ittabqs-item-label">'.dol_escape_htmltag($result->label)
			.((int) $result->required ? ' <span class="ittabqs-required">'.$langs->trans('WerkbankQsRequired').'</span>' : '').'</span>';
		if ($result->kind === 'check') {
			print '<label class="ittabqs-tick"><input type="checkbox" name="checked_'.$id.'" value="1"'.((int) $result->checked === WerkbankQs::DONE ? ' checked' : '').$disabled.'>'
				.'<span class="ittabqs-box" aria-hidden="true"></span>'.$label.'</label>';
		} else {
			print '<label class="ittabqs-field" for="ittabqs-value-'.$id.'">'.$label.'</label>';
			if ($result->kind === 'value') {
				print '<span class="ittabqs-value"><input type="text" inputmode="decimal" autocomplete="off" id="ittabqs-value-'.$id.'" name="value_'.$id.'" class="ittabqs-input" value="'.dol_escape_htmltag((string) $result->value).'"'.$disabled.'>'
					.($result->unit !== '' ? '<span class="ittabqs-unit">'.dol_escape_htmltag($result->unit).'</span>' : '').'</span>';
			} else {
				print '<textarea id="ittabqs-value-'.$id.'" name="value_'.$id.'" rows="2" class="ittabqs-input"'.$disabled.'>'.dol_escape_htmltag((string) $result->value, 0, 1).'</textarea>';
			}
		}
		if ((string) $result->help !== '') {
			print '<p class="ittabqs-help">'.dol_escape_htmltag($result->help).'</p>';
		}
		print '<div class="ittabqs-extra">';
		print '<label class="ittabqs-pill"><input type="checkbox" name="skip_'.$id.'" value="1"'.($skipped ? ' checked' : '').$disabled.'><span>'.$langs->trans('WerkbankQsSkip').'</span></label>';
		$note = (string) $result->note;
		print '<details class="ittabqs-note"'.($note !== '' ? ' open' : '').'><summary>'.$langs->trans('WerkbankQsNote').'</summary>';
		print '<textarea name="note_'.$id.'" rows="2" class="ittabqs-input" aria-label="'.dol_escape_htmltag($langs->trans('WerkbankQsNote').': '.$result->label).'"'.$disabled.'>'.dol_escape_htmltag($note, 0, 1).'</textarea></details>';
		print '<span class="ittabqs-meta" aria-live="polite">'.$meta.'</span>';
		print '</div></li>';
	}
	print '</ol>';
	if (!$closed) {
		print '<noscript><button type="submit" class="ittabqs-secondary">'.$langs->trans('WerkbankQsSaveAll').'</button></noscript>';
	}
	print '</form>';

	if (!$closed) {
		print '<form method="POST" action="'.$self.'?id='.((int) $run->rowid).'" class="ittabqs-panel ittabqs-close" id="ittabqs-close">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="close">';
		print '<label class="ittabqs-label" for="ittabqs-closing-note">'.$langs->trans('WerkbankQsClosingNote').'</label>';
		print '<textarea id="ittabqs-closing-note" name="note" rows="2" class="ittabqs-input">'.dol_escape_htmltag((string) $run->note, 0, 1).'</textarea>';
		print '<button type="submit" class="ittabqs-primary">'.$langs->trans('WerkbankQsClose').'</button>';
		print '<p class="ittabqs-help">'.$langs->trans('WerkbankQsCloseHelp').'</p>';
		print '</form>';
	}
}
