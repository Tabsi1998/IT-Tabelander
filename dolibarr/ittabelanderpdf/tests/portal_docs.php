<?php
/*
 * The customer area's documents (REST kunden/{socid}/unterlagen), in the
 * disposable Dolibarr of scripts/dev_dolibarr.py (after "erinnerung"):
 *
 *   php portal_docs.php enable  API users like the website's (only "Unterlagen lesen") and one without it; prints their keys
 *   php portal_docs.php run     the firm with AGB to confirm and a document made for it to sign; prints ids
 *   php portal_docs.php check   after the web part: proof in the history and the agenda, the customer's
 *                               signature and address, counter signature on the device, prints checks
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

$slug = 'portal-test-vereinbarung';
$firmId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'office@musterfirma.example'");
$privateId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'max@mustermann.example'");

if ($stage === 'enable') {
	// The website's user: an API key and the right to read the documents, nothing else.
	$keys = array();
	foreach (array('portalweb' => true, 'portalohne' => false) as $login => $right) {
		$apiUser = new User($db);
		if ($apiUser->fetch(0, $login) <= 0) {
			$apiUser = new User($db);
			$apiUser->login = $login;
			$apiUser->lastname = 'Website '.$login;
			$apiUser->email = $login.'@example.invalid';
			if ($apiUser->create($admin) <= 0) {
				rt_fail('user '.$login.': '.$apiUser->error);
			}
		}
		rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."user_rights WHERE fk_user = ".((int) $apiUser->id));
		if ($right) {
			$apiUser->addrights(75623003);
		}
		$apiUser->api_key = bin2hex(random_bytes(16));
		$apiUser->update($admin);
		$keys[$login] = $apiUser->api_key;
	}
	echo json_encode(array('enable' => 'ok', 'apikey' => $keys['portalweb'], 'apikey_without' => $keys['portalohne'])), "\n";
	exit(0);
}

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankdoc.class.php');
dol_include_once('/ittabelanderpdf/class/werkbanksignature.class.php');
$docs = new WerkbankDocs($db);
$firm = new Societe($db);
$firm->fetch($firmId);
$ruleId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."ittabpdf_docrule WHERE slug = '".$slug."'");
$agbRule = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."ittabpdf_docrule WHERE slug = 'agb-unternehmer' AND fk_soc IS NULL");

if ($stage === 'run') {
	// A document made per customer, to sign (like AVV and Fernwartung), for companies.
	$store = new WerkbankDoc($db);
	$existing = $store->bySlug($slug);
	$docId = $store->save($existing ? (int) $existing->rowid : 0, array('slug' => $slug, 'title' => 'Portal-Testvereinbarung', 'kind' => 'customer', 'public' => 0,
		'text' => '<h2>1. Gegenstand</h2><p>Vereinbarung mit __ITTAB_KUNDE_NAME__.</p>', 'position' => 98), $admin);
	if (!$existing || (int) $existing->version === 0) {
		$store->release($docId, 'Test', $admin);
	}
	$ruleId = $docs->saveRule($ruleId, array('label' => 'Portal-Testvereinbarung unterschrieben', 'slug' => $slug, 'action' => 'sign', 'customer_type' => 'company',
		'products' => '', 'fk_category' => 0, 'required' => 1, 'renew' => 1, 'position' => 98, 'active' => 1)) ?: $ruleId;
	// The firm starts clean: AGB to confirm, the agreement generated for it.
	rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE fk_soc = ".$firmId." AND slug IN ('agb-unternehmer', '".$slug."')");
	rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docrule WHERE fk_soc = ".$firmId." AND slug = 'agb-unternehmer'");
	$generated = $docs->generate($firm, $docs->rule($ruleId), array(), null, null, $admin);
	if ($generated <= 0) {
		rt_fail('generate: '.$docs->error);
	}
	// Something done for the private customer, whose file the firm must never get.
	rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE fk_soc = ".$privateId." AND slug = 'nutzungsbedingungen'");
	$documents = $docs->documents();
	echo json_encode(array('firm' => $firmId, 'private' => $privateId, 'slug' => $slug, 'rule' => $ruleId, 'generated' => $generated,
		'agb_stand' => $documents['agb-unternehmer']['stand'], 'stand' => $documents[$slug]['stand'])), "\n";
	exit(0);
}
if ($stage !== 'check') {
	rt_fail('unknown stage '.$stage);
}

$checks = array();
$check = function ($name, $ok, $detail = '') use (&$checks) {
	$checks[] = array('check' => $name, 'ok' => (bool) $ok, 'detail' => $detail);
};
$entryOf = function ($wanted) use ($docs, $firm) {
	foreach ($docs->status($firm) as $entry) {
		if ($entry['rule']->slug === $wanted) {
			return $entry;
		}
	}
	return null;
};

// Confirmation: the proof in the history, done for the current Stand, in the agenda.
$agb = $entryOf('agb-unternehmer');
$done = $agb ? $agb['done'] : null;
$check('confirmed AGB are done, from the customer area', $agb && $agb['state'] === 'done' && $done && $done->source === 'portal' && $done->state === 'signed', $agb ? $agb['state'] : 'none');
$check('the proof names name, e-mail, IP and Stand', $done && strpos((string) $done->note, 'Maria Muster <office@musterfirma.example>') !== false
	&& strpos((string) $done->note, 'IP 203.0.113.7') !== false && strpos((string) $done->note, 'Stand ') !== false
	&& (string) $done->version === (string) $agb['stand'], $done ? (string) $done->note : '');
$check('the API user is noted as who', $done && $done->login === 'portalweb', $done ? (string) $done->login : '');
$agenda = $done ? $db->fetch_object($db->query("SELECT label, note FROM ".MAIN_DB_PREFIX."actioncomm WHERE id = ".((int) $done->fk_actioncomm))) : null;
$check('the confirmation is in the agenda of the customer', $agenda && strpos((string) $agenda->note, 'im Kundenbereich') !== false && strpos((string) $agenda->note, 'Maria Muster') !== false,
	$agenda ? $agenda->label.' / '.$agenda->note : 'no agenda event');

// Signature: begun by the customer, waits for the counter signature.
$deal = $entryOf($slug);
$signing = $deal ? $deal['signing'] : null;
$check('the signed agreement waits for the counter signature', $deal && $deal['state'] === 'signing' && $signing && $signing->source === 'portal' && $signing->element === 'docgen',
	$deal ? $deal['state'] : 'none');
$signature = new WerkbankSignature($db);
$loaded = $signing ? $signature->load('doc', (int) $signing->rowid) : null;
$signed = $loaded ? $signature->signatures('doc', (int) $signing->rowid, $loaded) : array();
$check('only the customer signed so far, with the name typed', isset($signed['customer']) && !isset($signed['technician']) && $signed['customer']->name === 'Maria Muster');
$ip = $signing ? rt_value($db, "SELECT ip FROM ".MAIN_DB_PREFIX."ittabpdf_signature WHERE kind = 'doc' AND fk_object = ".((int) $signing->rowid)." AND role = 'customer'") : '';
$check('the signature keeps the customer\'s address, not the website\'s', $ip === '198.51.100.24', (string) $ip);
$check('the signing notes the proof', $signing && strpos((string) $signing->note, 'Im Kundenbereich unterschrieben: Maria Muster <office@musterfirma.example>, IP 198.51.100.24') === 0,
	$signing ? (string) $signing->note : '');
$pdf = $loaded ? WerkbankSignature::pdfPath('doc', $loaded) : '';
$check('the PDF carries the customer\'s signature', $pdf !== '' && is_file($pdf) && preg_match_all('#/Subtype\s*/Image#', (string) file_get_contents($pdf)) >= 1, $pdf);
$open = array_filter($docs->openItems(50), function ($item) {
	return $item['kind'] === 'signing' && $item['what'] === 'Portal-Testvereinbarung';
});
$check('the widget "Offen" lists it for the counter signature', count($open) === 1);

// Fabian signs on the device: done, signed file with the customer.
if ($signing) {
	$drawn = function () {
		$image = imagecreatetruecolor(300, 90);
		imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
		imagesetthickness($image, 4);
		imageline($image, 10, 70, 290, 20, imagecolorallocate($image, 27, 51, 72));
		ob_start();
		imagepng($image);
		return rtrim(base64_encode((string) ob_get_clean()), '=');
	};
	$signature->save('doc', (int) $signing->rowid, 'technician', 'Fabian Tabelander', $drawn(), $admin);
}
$deal = $entryOf($slug);
$check('after the counter signature the agreement is done', $deal && $deal['state'] === 'done' && $deal['done'] && strpos((string) $deal['done']->filename, $slug.'-unterschrieben-') === 0,
	$deal ? $deal['state'] : 'none');
$images = $pdf !== '' && is_file($pdf) ? preg_match_all('#/Subtype\s*/Image#', (string) file_get_contents($pdf)) : 0;
$check('the signed PDF carries both signatures', $images >= 2, (string) $images);
@mkdir('/tmp/ittpdf-out', 0777, true);
if ($pdf !== '' && is_file($pdf)) {
	copy($pdf, '/tmp/ittpdf-out/kundenbereich-unterschrieben.pdf');
}

$failed = array_values(array_filter($checks, function ($entry) {
	return !$entry['ok'];
}));
echo json_encode(array('checks' => $checks, 'failed' => count($failed))), "\n";
exit(0);
