<?php
/*
 * Documents made for one customer, in the disposable Dolibarr of
 * scripts/dev_dolibarr.py (after "docs"):
 *
 *   php gen.php run   template with placeholders and input fields, required fields,
 *                     unknown placeholders, PDF with the customer and the contract,
 *                     status generated -> sent -> signed on the device; prints checks and ids
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
if ($stage !== 'run') {
	rt_fail('unknown stage '.$stage);
}

require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankdoc.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankfields.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankvars.class.php');
dol_include_once('/ittabelanderpdf/class/werkbanksignature.class.php');

$checks = array();
$check = function ($name, $ok, $detail = '') use (&$checks) {
	$checks[] = array('check' => $name, 'ok' => (bool) $ok, 'detail' => $detail);
};
$drawn = function () {
	$image = imagecreatetruecolor(300, 90);
	imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
	imagesetthickness($image, 4);
	imageline($image, 10, 70, 290, 20, imagecolorallocate($image, 27, 51, 72));
	ob_start();
	imagepng($image);
	return rtrim(base64_encode((string) ob_get_clean()), '=');
};
$waf = function ($html) {
	return !preg_match('/<style|<svg|=\s*["\']?data:|\son[a-z]+\s*=/i', (string) $html);
};

$slug = 'gen-test-vereinbarung';
$firmId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'office@musterfirma.example'");
$contractId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."contrat WHERE fk_soc = ".$firmId." AND statut = 1 ORDER BY rowid DESC");
if (!$contractId) {
	rt_fail('the docs stage leaves a validated contract for the firm');
}
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE slug = '".$slug."'");
rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."societe SET tva_intra = '' WHERE rowid = ".$firmId);
rt_const($db, 'ITTABPDF_GESCHAEFTSZEITEN', 'Mo bis Fr 8 bis 17 Uhr');
$firm = new Societe($db);
$firm->fetch($firmId);
$contract = new Contrat($db);
$contract->fetch($contractId);

// A customer template with placeholders and input fields, released.
$text = '<h2>1. Vertragspartner</h2><p>Auftraggeber: __ITTAB_KUNDE_NAME__</p><p>UID: __ITTAB_KUNDE_UID__</p>'
	.'<p>Anschrift: __ITTAB_KUNDE_ANSCHRIFT__</p><p>Vertrag __ITTAB_VERTRAG_REF__ vom __ITTAB_VERTRAG_DATUM__</p>'
	.'<p>Erreichbar: __ITTAB_GESCHAEFTSZEITEN__</p><p>Hosting S kostet __ITTAB_PREIS:DL-HOST-S__.</p>__ITTAB_LEISTUNGEN_VERTRAG__'
	.'<h2>2. Daten</h2><p>Datenkategorien: [[FELD:datenkategorien*|Datenkategorien|Auswahl|+Kundendaten;+Kontaktdaten;Mitarbeiterdaten]]</p>'
	.'<p>Ansprechperson: [[FELD:ansprech*|Ansprechperson beim Kunden|Text]]</p><p>Bemerkung: [[FELD:bemerkung|Bemerkung|Textfeld]]</p>'
	.'<p>Ausgestellt für __THIRDPARTY_NAME__ von __ITTAB_FIRMA_NAME__.</p>';
$store = new WerkbankDoc($db);
$existing = $store->bySlug($slug);
$docId = $store->save($existing ? (int) $existing->rowid : 0, array('slug' => $slug, 'title' => 'Testvereinbarung', 'kind' => 'customer', 'public' => 0,
	'text' => $text, 'position' => 99), $admin);
$store->release($docId, 'Test', $admin);
$check('customer template has no public PDF', !is_file(WerkbankUnterlagen::dir().'/'.$slug.'.pdf'));

$docs = new WerkbankDocs($db);
$ruleId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."ittabpdf_docrule WHERE slug = '".$slug."'");
$ruleId = $docs->saveRule($ruleId, array('label' => 'Testvereinbarung unterschrieben', 'slug' => $slug, 'action' => 'sign', 'customer_type' => 'company',
	'products' => '', 'fk_category' => 0, 'required' => 0, 'renew' => 0, 'position' => 99, 'active' => 1)) ?: $ruleId;
$rule = $docs->rule($ruleId);
$template = $docs->template($slug);
$fields = WerkbankFields::parse((string) $template->answer);
$check('input fields are read from the template', array_keys($fields) === array('datenkategorien', 'ansprech', 'bemerkung')
	&& $fields['datenkategorien']['selected'] === array('Kundendaten', 'Kontaktdaten') && $fields['ansprech']['required'], implode(',', array_keys($fields)));

// The form: rows of a Dolibarr table, the WAF lets it pass.
$inputs = WerkbankFields::inputs($fields, array());
$check('form has a row per field, defaults ticked', substr_count($inputs, '<tr>') === 3 && substr_count($inputs, ' checked') === 2 && strpos($inputs, 'fieldrequired') !== false);
$check('form and help contain nothing the WAF blocks', $waf($inputs) && $waf(WerkbankVars::helpTable()));
$check('help lists every placeholder', substr_count(WerkbankVars::helpTable(), '<code') === count(WerkbankVars::OWN) + count(WerkbankVars::DOLIBARR) + 1
	&& strpos(WerkbankVars::helpTable(), 'WerkbankVar_') === false);

// Required fields block.
$event = $docs->generate($firm, $rule, array('datenkategorien' => array('Kundendaten'), 'ansprech' => ''), $contract, null, $admin);
$check('a missing required field blocks', $event < 0 && strpos($docs->error, 'Ansprechperson beim Kunden') !== false, $docs->error);
$event = $docs->generate($firm, $rule, array('datenkategorien' => array(), 'ansprech' => 'Maria Muster'), $contract, null, $admin);
$check('an empty required choice blocks too', $event < 0 && strpos($docs->error, 'Datenkategorien') !== false, $docs->error);

// Placeholders: all replaced, empty lines gone, unknown ones reported.
$answers = array('datenkategorien' => array('Kundendaten', 'Gesundheitsdaten'), 'ansprech' => 'Maria Muster', 'bemerkung' => '');
$filled = $docs->fill($template, $fields, $answers, $firm, $contract, null);
$check('every placeholder is replaced', WerkbankVars::left($filled) === array() && strpos($filled, '[[FELD') === false && strpos($filled, '__') === false, implode(',', WerkbankVars::left($filled)));
$check('values of customer, contract, company, prices and fields', strpos($filled, (string) $firm->name) !== false && strpos($filled, (string) $contract->ref) !== false
	&& strpos($filled, 'Mo bis Fr 8 bis 17 Uhr') !== false && preg_match('/Hosting S kostet [0-9]+,[0-9]{2}/u', $filled) && strpos($filled, 'Kundendaten, Gesundheitsdaten') !== false
	&& strpos($filled, '<li>') !== false, strip_tags($filled));
$check('a line with an empty value disappears (no UID, no note)', strpos($filled, 'UID:') === false && strpos($filled, 'Bemerkung:') === false);
$wrong = clone $template;
$wrong->answer .= '<p>__ITTAB_PREIS:GIBTSNICHT__ und __ITTAB_KUNDE_NAMEN__</p>';
$left = WerkbankVars::left($docs->fill($wrong, $fields, $answers, $firm, $contract, null));
$check('unknown placeholders are reported', in_array('__ITTAB_PREIS:GIBTSNICHT__', $left, true) && in_array('__ITTAB_KUNDE_NAMEN__', $left, true), implode(',', $left));

// Generate: PDF with the customer and the contract, state "generated", values for next time.
$event = $docs->generate($firm, $rule, $answers, $contract, null, $admin);
$generated = $docs->event($event);
$file = $generated ? WerkbankSignature::thirdpartyDir($firmId).'/'.$generated->filename : '';
$check('generated: PDF with the customer', $event > 0 && is_file($file) && strpos((string) file_get_contents($file, false, null, 0, 8), '%PDF') === 0, $docs->error.' '.$file);
$check('a copy with the contract', $generated && is_file($conf->contrat->dir_output.'/'.dol_sanitizeFileName($contract->ref).'/'.$generated->filename));
$state = function () use ($docs, $firm, $ruleId) {
	foreach ($docs->status($firm) as $entry) {
		if ((int) $entry['rule']->rowid === $ruleId) {
			return $entry['state'];
		}
	}
	return 'none';
};
$check('state is "generated"', $state() === 'generated', $state());
$last = $docs->lastValues($firmId, $slug);
$check('the form is prefilled from last time', $last['fields']['ansprech'] === 'Maria Muster' && $last['contract'] === $contractId);
@mkdir('/tmp/ittpdf-out', 0777, true);
copy($file, '/tmp/ittpdf-out/vereinbarung-erzeugt.pdf');

// Sent by e-mail: the generated file counts as this document.
$check('the generated file is recognised as attachment', $docs->slugsForPaths($firmId, array($file)) === array($slug));
$firm->attachedfiles = array('paths' => array($file), 'names' => array(basename($file)), 'mimes' => array('application/pdf'));
$firm->call_trigger('COMPANY_SENTBYMAIL', $admin);
$check('sending it marks it sent', $state() === 'sent', $state());

// Signed on the device by both, on the generated document itself.
$signing = $docs->record($firmId, $slug, 'signing', 'device', $admin, array('fk_rule' => $ruleId, 'element' => 'docgen', 'fk_object' => $event));
$signature = new WerkbankSignature($db);
$loaded = $signature->load('doc', $signing);
$check('both sides sign a generated document', $loaded && WerkbankSignature::roles('doc', $loaded) === array('technician', 'customer'));
$signature->save('doc', $signing, 'technician', 'Fabian Tabelander', $drawn(), $admin);
$signature->save('doc', $signing, 'customer', 'Maria Muster', $drawn(), $admin);
$pdf = WerkbankSignature::pdfPath('doc', $signature->load('doc', $signing));
$images = preg_match_all('#/Subtype\s*/Image#', (string) file_get_contents($pdf));
$check('signed: done, the PDF carries both signatures', $state() === 'done' && is_file($pdf) && $images >= 2, $pdf.' '.$images);
copy($pdf, '/tmp/ittpdf-out/vereinbarung-unterschrieben.pdf');

$failed = array_values(array_filter($checks, function ($entry) {
	return !$entry['ok'];
}));
echo json_encode(array('checks' => $checks, 'failed' => count($failed), 'firm' => $firmId, 'rule' => $ruleId, 'contract' => $contractId, 'event' => $event)), "\n";
exit(0);
