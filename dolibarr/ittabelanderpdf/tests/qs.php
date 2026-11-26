<?php
/*
 * Workshop: QS checklists, test report and device intake slip, in the
 * disposable Dolibarr of scripts/dev_dolibarr.py (after "fixtures"):
 *
 *   php qs.php enable   tickets, this module once more (tables, checklists, rights, ticket fields)
 *   php qs.php run      checklists, runs, required points, close, trigger, test report,
 *                       work report with the report behind it, intake slip; prints checks and ids
 *
 * Never run against a real Dolibarr.
 */

require __DIR__.'/bootstrap.php';

$stage = $argv[1] ?? '';
$admin = rt_admin($db);
$user = $admin;
$GLOBALS['user'] = $admin;
$langs->setDefaultLang('de_DE');
$langs->loadLangs(array('main', 'ittabelanderpdf@ittabelanderpdf'));

if ($stage === 'enable') {
	rt_modules(array('modFicheinter', 'modTicket'));
	unActivateModule('modITTabelanderPDF');
	// activateModule() skips a module whose constant this process still holds.
	unset($conf->global->MAIN_MODULE_ITTABELANDERPDF);
	rt_modules(array('modITTabelanderPDF'));
	echo json_encode(array('enable' => 'ok')), "\n";
	exit(0);
}
if ($stage !== 'run') {
	rt_fail('unknown stage '.$stage);
}

require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/ittabelanderpdf/class/werkbankqs.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankintake.class.php');

$checks = array();
$check = function ($name, $ok, $detail = '') use (&$checks) {
	$checks[] = array('check' => $name, 'ok' => (bool) $ok, 'detail' => $detail);
};
$pages = function ($path) {
	return is_file($path) ? preg_match_all('#/Type\s*/Page[^s]#', (string) file_get_contents($path)) : 0;
};
$out = '/tmp/ittpdf-out';
@mkdir($out, 0777, true);

$qs = new WerkbankQs($db);
$types = $qs->types();
$byCode = array();
foreach ($types as $type) {
	$byCode[$type->code] = $type;
}
$check('shipped checklists are installed', count(array_intersect(array_keys(WerkbankQs::defaults()), array_keys($byCode))) === count(WerkbankQs::defaults()), implode(',', array_keys($byCode)));
$check('installing again changes nothing', $qs->installDefaults(1) === 0 && count($qs->types()) === count($types));
$check('admin holds the new rights', $admin->hasRight('ittabelanderpdf', 'qs', 'run') && $admin->hasRight('ittabelanderpdf', 'qs', 'manage'));
foreach (array('ittab_geraet_art', 'ittab_zugang', 'ittab_sicherung', 'ittab_fehler') as $field) {
	$check('ticket field '.$field, (bool) rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."extrafields WHERE name = '".$field."' AND elementtype = 'ticket'"));
}

$firm = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'office@musterfirma.example'");
$private = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'max@mustermann.example'");
$intervention = function ($socid, $text) use ($db, $admin) {
	$fichinter = new Fichinter($db);
	$fichinter->socid = $socid;
	$fichinter->user_author_id = $admin->id;
	$fichinter->description = $text;
	$fichinter->model_pdf = 'werkbank';
	if ($fichinter->create($admin) <= 0) {
		rt_fail('intervention: '.$fichinter->error);
	}
	$fichinter->addline($admin, $fichinter->id, 'Reinigung und Stresstest', dol_now(), 5400);
	$fichinter->fetch($fichinter->id);
	$fichinter->setValid($admin);
	$fichinter->fetch($fichinter->id);
	$fichinter->fetch_thirdparty();
	return $fichinter;
};
$tick = function ($runId, $skipLabel = '') use ($qs, $admin) {
	$values = array('°C' => '71', '%' => '86', 'MHz' => '6000', 's' => '1,2');
	foreach ($qs->results($runId) as $result) {
		if ($result->label === $skipLabel) {
			continue;
		}
		$value = $result->kind === 'value' ? ($values[$result->unit] ?? '1') : ($result->kind === 'text' ? 'SN 5CD1234XYZ' : '');
		$qs->save($runId, (int) $result->rowid, $result->kind === 'check' ? WerkbankQs::DONE : WerkbankQs::OPEN, $value, '', $admin);
	}
};

// 1. A run copies the active points; later changes of the checklist do not touch it.
$fichinter = $intervention($firm, 'Notebook lüftet laut, bitte reinigen');
$repair = $byCode['REPARATUR'];
$runId = $qs->start($fichinter, (int) $repair->rowid, $admin);
$results = $qs->results($runId);
$check('run copies the active points', $runId > 0 && count($results) === count($qs->items((int) $repair->rowid, true)), count($results).' points');
$first = $qs->items((int) $repair->rowid)[0];
$qs->saveItem((int) $first->rowid, (int) $repair->rowid, array('position' => $first->position, 'label' => 'Geändert', 'kind' => $first->kind,
	'unit' => $first->unit, 'required' => $first->required, 'help' => '', 'active' => 1));
$check('changing the checklist keeps the run as it was', $qs->results($runId)[0]->label === $first->label);
$qs->saveItem((int) $first->rowid, (int) $repair->rowid, array('position' => $first->position, 'label' => $first->label, 'kind' => $first->kind,
	'unit' => $first->unit, 'required' => $first->required, 'help' => '', 'active' => 1));

// 2. Required points block, "does not apply" counts, notes and values land in the report.
$tick($runId, 'Datenträger: SMART-Werte geprüft');
$check('closing with a required point open is refused', $qs->close($runId, $fichinter, $admin) === -2 && strpos($qs->error, 'SMART') !== false, $qs->error);
$smart = null;
foreach ($qs->results($runId) as $result) {
	if ($result->label === 'Datenträger: SMART-Werte geprüft') {
		$smart = $result;
	}
}
$qs->save($runId, (int) $smart->rowid, WerkbankQs::SKIPPED, '', 'Nur eMMC verbaut, keine SMART-Werte', $admin);
$counts = $qs->counts($runId);
$check('skipped required point counts as done', $counts['required_open'] === 0 && $counts['done'] >= $counts['total'] - 4, json_encode($counts));
$closed = $qs->close($runId, $fichinter, $admin, 'Lüfter getauscht, läuft leise.');
$run = $qs->run($runId);
$pdf = WerkbankQs::pdfPath($fichinter, $run);
$check('closing writes the test report into the intervention', $closed === 1 && (int) $run->status === 1 && is_file($pdf) && $run->filename === basename($pdf), $pdf);
$check('a closed run cannot be changed', $qs->save($runId, (int) $smart->rowid, WerkbankQs::DONE, '', '', $admin) < 0);
copy($pdf, $out.'/pruefprotokoll-1.pdf');

// 3. Closing the intervention: blocked by an open QS, then it closes the QS itself.
$second = $intervention($private, 'Controller-Umbau mit Paddles');
$controller = $byCode['CONTROLLER'];
$secondRun = $qs->start($second, (int) $controller->rowid, $admin);
$result = $second->setClose($admin);
$check('intervention close is blocked by open required points', $result < 0 && strpos(implode(' ', (array) $second->errors).$second->error, 'Controller-Umbau') !== false,
	$second->error.' '.implode(' ', (array) $second->errors));
$tick($secondRun);
$second->fetch($second->id);
$result = $second->setClose($admin);
$check('intervention close closes the finished QS with its report', $result > 0 && (int) $qs->run($secondRun)->status === 1 && is_file(WerkbankQs::pdfPath($second, $qs->run($secondRun))),
	(string) $result.' '.$second->error);

// 4. Work report with the test report behind it.
rt_const($db, 'ITTABPDF_QS_APPEND_WORKREPORT', '1');
$fichinter->fetch($fichinter->id);
$fichinter->generateDocument('werkbank', $langs);
$report = $conf->ficheinter->dir_output.'/'.dol_sanitizeFileName($fichinter->ref).'/'.dol_sanitizeFileName($fichinter->ref).'.pdf';
$check('work report carries the test report', $pages($report) === 1 + $pages($pdf), $pages($report).' pages');
copy($report, $out.'/arbeitsbericht-mit-pruefprotokoll.pdf');
rt_const($db, 'ITTABPDF_QS_APPEND_WORKREPORT', '0');
$fichinter->generateDocument('werkbank', $langs);

// 5. Device intake slip from a ticket, with and without third party.
$ticket = new Ticket($db);
$ticket->ref = $ticket->getDefaultRef();
$ticket->track_id = bin2hex(random_bytes(8));
$ticket->subject = 'Notebook startet nicht';
$ticket->message = 'Hinweis: intern, nur für den Inhaber.';
$ticket->type_code = 'ISSUE';
$ticket->category_code = 'OTHER';
$ticket->severity_code = 'NORMAL';
$ticket->fk_soc = $private;
$ticket->context = array('disableticketemail' => 1);
$ticket->array_options = array('options_ittab_geraet_art' => 'notebook', 'options_ittab_geraet_modell' => 'Lenovo ThinkPad T14 Gen 3',
	'options_ittab_geraet_sn' => 'PF3XYZ12', 'options_ittab_zubehoer' => 'Netzteil, Tasche', 'options_ittab_zustand' => 'Kratzer am Deckel, Scharnier links locker',
	'options_ittab_fehler' => 'Startet nicht, Ladelampe blinkt dreimal orange.', 'options_ittab_zugang' => '1', 'options_ittab_sicherung' => '2');
if ($ticket->create($admin) <= 0) {
	rt_fail('ticket: '.$ticket->error);
}
$ticket->fetch($ticket->id);
$intake = new WerkbankIntake($db);
$slip = $intake->write($ticket);
$check('intake slip lands in the ticket documents', $slip !== '' && is_file($slip) && strpos($slip, '/ticket/') !== false, $slip.' '.$intake->error);
copy($slip, $out.'/geraeteannahme-1.pdf');
$web = new Ticket($db);
$web->ref = $web->getDefaultRef();
$web->track_id = bin2hex(random_bytes(8));
$web->subject = 'PC nach Wunsch';
$web->message = 'Anfrage von der Website';
$web->type_code = 'ISSUE';
$web->category_code = 'OTHER';
$web->severity_code = 'NORMAL';
$web->origin_email = 'anna@beispiel.example';
$web->context = array('disableticketemail' => 1);
$web->create($admin);
$web->fetch($web->id);
$blank = $intake->write($web);
$check('intake slip without third party: fields to fill in by hand', $blank !== '' && is_file($blank), $intake->error);
copy($blank, $out.'/geraeteannahme-2-leer.pdf');

// 6. A user without the QS rights, for the web part.
$login = 'werkstatt-ohne-qs';
$plain = new User($db);
if ($plain->fetch(0, $login) <= 0) {
	$plain->login = $login;
	$plain->lastname = 'Ohne QS';
	$plain->admin = 0;
	if ($plain->create($admin) <= 0) {
		rt_fail('user: '.$plain->error);
	}
}
$word = bin2hex(random_bytes(8));
$plain->setPassword($admin, $word);
$plain->addrights(0, 'ficheinter');

// An open run with every kind of point for the web part.
$third = $intervention($firm, 'PC-Bau Gaming');
$openRun = $qs->start($third, (int) $byCode['PCBAU']->rowid, $admin);

$failed = array_values(array_filter($checks, function ($entry) {
	return !$entry['ok'];
}));
echo json_encode(array('checks' => $checks, 'failed' => count($failed), 'socid' => $firm, 'fichinter' => (int) $third->id, 'open_run' => $openRun,
	'closed_run' => $runId, 'closed_file' => dol_sanitizeFileName($fichinter->ref).'/'.basename($pdf), 'ticket' => $ticket->track_id,
	'plain_login' => $login, 'plain_word' => $word)), "\n";
exit(0);
