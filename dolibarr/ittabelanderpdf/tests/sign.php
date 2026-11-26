<?php
/*
 * Signatures on the device and sending, in the disposable Dolibarr of
 * scripts/dev_dolibarr.py (after "qs"):
 *
 *   php sign.php enable   this module once more (signature table, update_1.3.0.sql, templates)
 *   php sign.php run      signatures into test report and intake slip, refusals, copy with the
 *                         third party, sending with agenda entry; prints checks and ids
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
	unActivateModule('modITTabelanderPDF');
	unset($conf->global->MAIN_MODULE_ITTABELANDERPDF);
	rt_modules(array('modITTabelanderPDF'));
	// The agenda records e-mails of interventions and tickets (production has these on).
	rt_const($db, 'MAIN_AGENDA_ACTIONAUTO_FICHINTER_SENTBYMAIL', '1');
	rt_const($db, 'MAIN_AGENDA_ACTIONAUTO_TICKET_SENTBYMAIL', '1');
	echo json_encode(array('enable' => 'ok')), "\n";
	exit(0);
}
if ($stage !== 'run') {
	rt_fail('unknown stage '.$stage);
}

require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
dol_include_once('/ittabelanderpdf/class/werkbankqs.class.php');
dol_include_once('/ittabelanderpdf/class/werkbanksignature.class.php');

$checks = array();
$check = function ($name, $ok, $detail = '') use (&$checks) {
	$checks[] = array('check' => $name, 'ok' => (bool) $ok, 'detail' => $detail);
};
$images = function ($path) {
	return is_file($path) ? preg_match_all('#/Subtype\s*/Image#', (string) file_get_contents($path)) : 0;
};
// A drawn line as the signature, base64 without "=" padding like js/sign.js sends it.
$drawn = function ($seed) {
	$image = imagecreatetruecolor(420, 120);
	imagesavealpha($image, true);
	imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
	$ink = imagecolorallocate($image, 27, 51, 72);
	imagesetthickness($image, 4);
	for ($x = 10; $x < 400; $x += 20) {
		imageline($image, $x, 60 + (int) (30 * sin(($x + $seed) / 25)), $x + 20, 60 + (int) (30 * sin(($x + 20 + $seed) / 25)), $ink);
	}
	ob_start();
	imagepng($image);
	return rtrim(base64_encode((string) ob_get_clean()), '=');
};
$out = '/tmp/ittpdf-out';
@mkdir($out, 0777, true);
$templates = (int) rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf' AND label IN ('Prüfprotokoll (Sie)', 'Prüfprotokoll (Du)', 'Geräteannahme (Sie)', 'Geräteannahme (Du)')");
$check('templates Prüfprotokoll and Geräteannahme installed', $templates === 4, (string) $templates);
$check('update_1.3.0.sql added date_sent', rt_value($db, "SELECT COUNT(*) FROM information_schema.columns WHERE table_name = '".MAIN_DB_PREFIX."ittabpdf_qs_run' AND column_name = 'date_sent'") > 0);

// A finished on-site QS for the company, closed.
$qs = new WerkbankQs($db);
$type = null;
foreach ($qs->types() as $row) {
	if ($row->code === 'VORORT') {
		$type = $row;
	}
}
$firm = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'office@musterfirma.example'");
$fichinter = new Fichinter($db);
$fichinter->socid = $firm;
$fichinter->user_author_id = $admin->id;
$fichinter->description = 'Vor Ort: Drucker und WLAN';
$fichinter->create($admin);
$fichinter->fetch($fichinter->id);
$fichinter->fetch_thirdparty();
$runId = $qs->start($fichinter, (int) $type->rowid, $admin);
foreach ($qs->results($runId) as $result) {
	$qs->save($runId, (int) $result->rowid, $result->kind === 'check' ? 1 : 0, $result->kind === 'check' ? '' : 'Drucker neu eingebunden, WLAN-Kanal 36', '', $admin);
}
$signature = new WerkbankSignature($db);
$check('signing an open QS is refused', $signature->save('qs', $runId, 'customer', 'Maria Muster', $drawn(1), $admin) < 0 && $signature->error === 'WerkbankSignCloseFirst', $signature->error);
$qs->close($runId, $fichinter, $admin, 'Alles läuft.');
$run = $qs->run($runId);
$pdf = WerkbankQs::pdfPath($fichinter, $run);
$plain = $images($pdf);
$thirdpartyCopy = $conf->societe->multidir_output[1].'/'.$firm.'/'.basename($pdf);
$check('closing puts a copy with the third party', is_file($thirdpartyCopy), $thirdpartyCopy);

// Refusals: data: prefix, junk, unknown role, missing name.
$check('a data: URL is refused', $signature->save('qs', $runId, 'customer', 'Maria Muster', 'data:image/png;base64,'.$drawn(1), $admin) < 0 && $signature->error === 'WerkbankSignImageInvalid');
$check('something else than a PNG is refused', $signature->save('qs', $runId, 'customer', 'Maria Muster', rtrim(base64_encode('GIF89a not really'), '='), $admin) < 0);
$check('an unknown role is refused', $signature->save('qs', $runId, 'boss', 'X', $drawn(1), $admin) < 0 && $signature->error === 'WerkbankSignUnknownRole');
$check('a signature without name is refused', $signature->save('qs', $runId, 'customer', '  ', $drawn(1), $admin) < 0 && $signature->error === 'WerkbankSignNameMissing');

// Both sign; the report carries both images, names and times.
$check('technician signs', $signature->save('qs', $runId, 'technician', 'Fabian Tabelander', $drawn(3), $admin) === 1, $signature->error);
$check('customer signs', $signature->save('qs', $runId, 'customer', 'Maria Muster', $drawn(40), $admin) === 1, $signature->error);
// A PNG with transparency is two image objects in the PDF (picture and mask).
$signedImages = $images($pdf);
$check('test report carries both signatures', $signedImages >= $plain + 2, $plain.' -> '.$signedImages);
$check('signing again replaces, not adds', $signature->save('qs', $runId, 'customer', 'Maria Muster', $drawn(80), $admin) === 1
	&& (int) rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."ittabpdf_signature WHERE kind = 'qs' AND fk_object = ".$runId) === 2 && $images($pdf) === $signedImages);
$check('the copy with the third party is the signed one', md5_file($thirdpartyCopy) === md5_file($pdf));
copy($pdf, $out.'/pruefprotokoll-unterschrieben.pdf');

// Sending: recipient, template, BCC, agenda.
$loaded = $signature->load('qs', $runId);
$to = WerkbankSignature::recipient('qs', $loaded);
$check('recipient is the third party when no contact', strpos($to, 'office@musterfirma.example') !== false, $to);
$events = (int) rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."actioncomm WHERE elementtype = 'fichinter' AND fk_element = ".((int) $fichinter->id));
$check('test report is sent', $signature->send('qs', $runId, $to, $admin) === 1, $signature->error);
$check('send is noted on the run', (string) $qs->run($runId)->sent_to !== '' && !empty($qs->run($runId)->date_sent));
$check('agenda has the e-mail', (int) rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."actioncomm WHERE elementtype = 'fichinter' AND fk_element = ".((int) $fichinter->id)) === $events + 1);
$check('a wrong address is refused', $signature->send('qs', $runId, 'kein-mail', $admin) < 0);

// Intake slip: signed on the device, with the private customer.
$private = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'max@mustermann.example'");
$ticket = new Ticket($db);
$ticket->ref = $ticket->getDefaultRef();
$ticket->track_id = bin2hex(random_bytes(8));
$ticket->subject = 'Controller driftet';
$ticket->message = 'Linker Stick driftet.';
$ticket->type_code = 'ISSUE';
$ticket->category_code = 'OTHER';
$ticket->severity_code = 'NORMAL';
$ticket->fk_soc = $private;
$ticket->context = array('disableticketemail' => 1);
$ticket->array_options = array('options_ittab_geraet_art' => 'controller', 'options_ittab_geraet_modell' => 'DualSense Edge', 'options_ittab_zugang' => '2', 'options_ittab_sicherung' => '3');
$ticket->create($admin);
$check('intake: technician signs', $signature->save('intake', (int) $ticket->id, 'technician', 'Fabian Tabelander', $drawn(5), $admin) === 1, $signature->error);
$check('intake: customer signs', $signature->save('intake', (int) $ticket->id, 'customer', 'Max Mustermann', $drawn(60), $admin) === 1, $signature->error);
$loaded = $signature->load('intake', (int) $ticket->id);
$slip = WerkbankSignature::pdfPath('intake', $loaded);
$check('intake slip carries both signatures and lies with the customer', $images($slip) >= 5 && is_file($conf->societe->multidir_output[1].'/'.$private.'/'.basename($slip)), (string) $images($slip));
copy($slip, $out.'/geraeteannahme-unterschrieben.pdf');
$check('intake slip is sent', $signature->send('intake', (int) $ticket->id, WerkbankSignature::recipient('intake', $loaded), $admin) === 1, $signature->error);

// Reopening the QS drops its signatures.
$qs->reopen($runId);
$check('reopening drops the signatures', (int) rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."ittabpdf_signature WHERE kind = 'qs' AND fk_object = ".$runId) === 0);
$qs->close($runId, $fichinter, $admin);

$failed = array_values(array_filter($checks, function ($entry) {
	return !$entry['ok'];
}));
echo json_encode(array('checks' => $checks, 'failed' => count($failed), 'run' => $runId, 'ticket' => (int) $ticket->id, 'png' => $drawn(9))), "\n";
exit(0);
