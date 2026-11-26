<?php
/*
 * Company documents per customer, in the disposable Dolibarr of
 * scripts/dev_dolibarr.py (after "unterlagen" and "sign"):
 *
 *   php docs.php enable   customer tag "Fernwartung", this module once more (tables, rules, widget)
 *   php docs.php run      rules, status, sent by e-mail, accepted with a proposal (customer area),
 *                         signed on the device, new version, reset, contract check; prints checks and ids
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

require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

if ($stage === 'enable') {
	rt_modules(array('modCategorie', 'modContrat'));
	if (!rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."categorie WHERE type = 2 AND label = 'Fernwartung'")) {
		$tag = new Categorie($db);
		$tag->label = 'Fernwartung';
		$tag->type = Categorie::TYPE_CUSTOMER;
		if ($tag->create($admin) <= 0) {
			rt_fail('tag: '.$tag->error);
		}
	}
	unActivateModule('modITTabelanderPDF');
	unset($conf->global->MAIN_MODULE_ITTABELANDERPDF);
	rt_modules(array('modITTabelanderPDF'));
	echo json_encode(array('enable' => 'ok')), "\n";
	exit(0);
}
if ($stage !== 'run') {
	rt_fail('unknown stage '.$stage);
}

require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
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
$docs = new WerkbankDocs($db);
$rules = array();
foreach ($docs->rules() as $rule) {
	$rules[$rule->slug] = $rule;
}
$check('shipped rules are installed', count(array_intersect(array('agb-unternehmer', 'nutzungsbedingungen', 'auftragsverarbeitung', 'leistungsbeschreibung-betreuung-wartung', 'fernwartung-einverstaendnis'), array_keys($rules))) === 5, implode(',', array_keys($rules)));
// Activated before the tag existed: the rule waits switched off; the owner picks the tag in the setup.
$consent = $rules['fernwartung-einverstaendnis'];
$check('Fernwartung rule waits for a customer tag', (int) $consent->active === 1 ? (int) $consent->fk_category > 0 : (int) $consent->fk_category === 0);
$tagId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."categorie WHERE type = 2 AND label = 'Fernwartung'");
$docs->saveRule((int) $consent->rowid, array('label' => $consent->label, 'slug' => $consent->slug, 'action' => $consent->action, 'customer_type' => '',
	'products' => '', 'fk_category' => $tagId, 'required' => 1, 'renew' => 1, 'position' => $consent->position, 'active' => 1));
$rules['fernwartung-einverstaendnis'] = $docs->rule((int) $consent->rowid);
$check('installing again adds nothing', $docs->installDefaults(1) === 0);

// The documents of the rules as released module documents, so their PDFs exist.
dol_include_once('/ittabelanderpdf/class/werkbankdoc.class.php');
$store = new WerkbankDoc($db);
foreach (array('auftragsverarbeitung' => 'Vereinbarung zur Auftragsverarbeitung', 'leistungsbeschreibung-betreuung-wartung' => 'Leistungsbeschreibung IT-Betreuung und Wartung',
	'leistungsbeschreibung-betreuung-wartung-privat' => 'Leistungsbeschreibung IT-Betreuung und Wartung (privat)', 'fernwartung-einverstaendnis' => 'Einverständnis Fernwartung') as $slug => $title) {
	$existing = $store->bySlug($slug);
	$id = $existing ? (int) $existing->rowid : $store->save(0, array('slug' => $slug, 'title' => $title, 'kind' => 'internal', 'public' => 0,
		'text' => '<h2>1. Gegenstand</h2><p>Text der Unterlage '.$title.'.</p>', 'position' => 0), $admin);
	// Imported drafts (scripts/import_unterlagen.py) are released for the test.
	if (!$existing || (int) $existing->version === 0) {
		$store->release($id, '', $admin);
	}
}
// docs/unterlagen makes AVV and Fernwartung templates per customer (no general PDF, generated per customer).
$avvDoc = $store->bySlug('auftragsverarbeitung');
$check('documents of the rules exist', is_file(WerkbankUnterlagen::dir().'/agb-unternehmer.pdf')
	&& ($avvDoc->kind === 'customer' ? !is_file(WerkbankUnterlagen::dir().'/auftragsverarbeitung.pdf') : is_file(WerkbankUnterlagen::dir().'/auftragsverarbeitung.pdf')), $avvDoc->kind);

$firmId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'office@musterfirma.example'");
$privateId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'max@mustermann.example'");
// A run starts clean for both customers, so it can run again.
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE fk_soc IN (".$firmId.", ".$privateId.")");
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."contratdet WHERE fk_contrat IN (SELECT rowid FROM ".MAIN_DB_PREFIX."contrat WHERE fk_soc IN (".$firmId.", ".$privateId."))");
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."contrat WHERE fk_soc IN (".$firmId.", ".$privateId.")");
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."categorie_societe WHERE fk_soc IN (".$firmId.", ".$privateId.")");
$firm = new Societe($db);
$firm->fetch($firmId);
$private = new Societe($db);
$private->fetch($privateId);
$state = function ($soc, $slug) use ($docs) {
	foreach ($docs->status($soc) as $entry) {
		if ($entry['rule']->slug === $slug) {
			return $entry['state'];
		}
	}
	return 'none';
};

// 1. Who needs what.
$check('company needs AGB Unternehmer, not Nutzungsbedingungen', $state($firm, 'agb-unternehmer') === 'missing' && $state($firm, 'nutzungsbedingungen') === 'none');
$check('private person needs Nutzungsbedingungen', $state($private, 'nutzungsbedingungen') === 'missing' && $state($private, 'agb-unternehmer') === 'none');
$check('no care contract, no AVV', $state($firm, 'auftragsverarbeitung') === 'none');
$product = new Product($db);
if ($product->fetch(0, 'DL-WART-S') <= 0) {
	$product->ref = 'DL-WART-S';
	$product->label = 'Wartung S';
	$product->type = Product::TYPE_SERVICE;
	$product->price = 15;
	$product->status = 1;
	$product->create($admin);
}
$contract = new Contrat($db);
$contract->socid = $firmId;
$contract->date_contrat = dol_now();
$contract->commercial_signature_id = $admin->id;
$contract->commercial_suivi_id = $admin->id;
$contract->create($admin);
$contract->addline('Wartung S', 15, 1, 0, 0, 0, $product->id, 0, dol_now(), dol_time_plus_duree(dol_now(), 1, 'y'));
$contract->validate($admin);
$check('care contract asks for AVV and Leistungsbeschreibung', $state($firm, 'auftragsverarbeitung') === 'missing' && $state($firm, 'leistungsbeschreibung-betreuung-wartung') === 'missing');
$tag = new Categorie($db);
$tag->fetch((int) $rules['fernwartung-einverstaendnis']->fk_category);
$tag->add_type($firm, Categorie::TYPE_CUSTOMER);
$check('tag Fernwartung asks for the consent', $state($firm, 'fernwartung-einverstaendnis') === 'missing');

// 2. Sent with a proposal by e-mail, then accepted in the customer area.
$propal = new Propal($db);
$propal->socid = $firmId;
$propal->date = dol_now();
$propal->duree_validite = 14;
$propal->create($admin);
$propal->addline('Einrichtung', 100, 1, 0);
$propal->valid($admin);
$propal->fetch($propal->id);
$propal->attachedfiles = array('paths' => array(WerkbankUnterlagen::dir().'/agb-unternehmer.pdf', '/tmp/irgendwas.pdf'), 'names' => array('agb-unternehmer.pdf', 'irgendwas.pdf'), 'mimes' => array());
$propal->call_trigger('PROPAL_SENTBYMAIL', $admin);
$check('e-mail with the AGB marks it sent', $state($firm, 'agb-unternehmer') === 'sent');
$propal->closeProposal($admin, Propal::STATUS_SIGNED, 'Im Kundenbereich der Website angenommen am 26.11.2026, bestätigt mit dem Namen „Maria Muster“.');
$done = null;
foreach ($docs->status($firm) as $entry) {
	if ($entry['rule']->slug === 'agb-unternehmer') {
		$done = $entry['done'];
	}
}
$check('accepting the proposal online accepts the AGB', $state($firm, 'agb-unternehmer') === 'done' && $done && $done->source === 'portal', $done ? $done->source : '');

// 3. AVV signed on the device by both: done, PDF with the confirmation sheet first.
$extra = array('fk_rule' => (int) $rules['auftragsverarbeitung']->rowid);
if ($avvDoc->kind === 'customer') {
	// A template per customer is generated first and signed on itself.
	dol_include_once('/ittabelanderpdf/class/werkbankfields.class.php');
	$answers = array();
	foreach (WerkbankFields::parse((string) $docs->template('auftragsverarbeitung')->answer) as $name => $field) {
		$answers[$name] = $field['kind'] === 'multi' ? ($field['options'] ? array($field['options'][0]) : array('Test')) : ($field['default'] !== '' ? $field['default'] : 'Maria Muster');
	}
	$generatedAvv = $docs->generate($firm, $rules['auftragsverarbeitung'], $answers, $contract, null, $admin);
	$check('AVV is generated for the customer first', $generatedAvv > 0, $docs->error);
	$extra += array('element' => 'docgen', 'fk_object' => $generatedAvv);
}
$event = $docs->record($firmId, 'auftragsverarbeitung', 'signing', 'device', $admin, $extra);
$signature = new WerkbankSignature($db);
$loaded = $signature->load('doc', $event);
$check('AVV needs both signatures', $loaded && WerkbankSignature::roles('doc', $loaded) === array('technician', 'customer'));
$signature->save('doc', $event, 'technician', 'Fabian Tabelander', $drawn(), $admin);
$check('one signature is not enough', $state($firm, 'auftragsverarbeitung') === 'signing');
$signature->save('doc', $event, 'customer', 'Maria Muster', $drawn(), $admin);
$pdf = WerkbankSignature::pdfPath('doc', $signature->load('doc', $event));
$pages = preg_match_all('#/Type\s*/Page[^s]#', (string) file_get_contents($pdf));
$check('AVV signed: done, signed document with the customer', $state($firm, 'auftragsverarbeitung') === 'done' && is_file($pdf) && $pages >= 2, $pdf.' '.$pages);
@mkdir('/tmp/ittpdf-out', 0777, true);
copy($pdf, '/tmp/ittpdf-out/avv-unterschrieben.pdf');
$check('receipt needs only the customer', WerkbankSignature::roles('doc', array('rule' => $rules['leistungsbeschreibung-betreuung-wartung'])) === array('customer'));

// 4. A new version asks again; a reset opens it.
rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."ittabpdf_docevent SET version = '2020-01-01' WHERE fk_soc = ".$firmId." AND slug = 'auftragsverarbeitung' AND state = 'signed'");
$check('older version of a renewing rule counts as outdated', $state($firm, 'auftragsverarbeitung') === 'outdated');
rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."ittabpdf_docevent SET version = '2020-01-01' WHERE fk_soc = ".$firmId." AND slug = 'leistungsbeschreibung-betreuung-wartung'");
$docs->record($firmId, 'leistungsbeschreibung-betreuung-wartung', 'checked', 'hand', $admin, array('version' => '2020-01-01'));
$check('older version of a rule without renewal stays done', $state($firm, 'leistungsbeschreibung-betreuung-wartung') === 'done');
$docs->record($firmId, 'leistungsbeschreibung-betreuung-wartung', 'revoked', 'hand', $admin);
$check('reset opens it again', $state($firm, 'leistungsbeschreibung-betreuung-wartung') === 'missing');

// 5. What shows on the cards and the start page.
$missing = $docs->missing($firm);
$check('missing list for the banner', in_array('Auftragsverarbeitung (AVV) unterschrieben', $missing, true) && in_array('Leistungsbeschreibung IT-Betreuung und Wartung übergeben', $missing, true), implode(', ', $missing));
$who = implode(' ', array_column(array_filter($docs->openItems(200), function ($item) {
	return $item['kind'] === 'missing';
}), 'who'));
$check('start page widget "Offen" lists the customers by name', strpos($who, 'socid='.$firmId.'"') !== false && strpos($who, 'socid='.$privateId.'"') !== false
	&& strpos($who, (string) $firm->name) !== false, $who);

// 6. Activating a maintenance line without AVV: warn, or refuse.
$maint = new Product($db);
if ($maint->fetch(0, 'DL-WART-M') <= 0) {
	$maint->ref = 'DL-WART-M';
	$maint->label = 'Wartung M';
	$maint->type = Product::TYPE_SERVICE;
	$maint->price = 30;
	$maint->status = 1;
	$maint->create($admin);
}
$second = new Contrat($db);
$second->socid = $privateId;
$second->date_contrat = dol_now();
$second->commercial_signature_id = $admin->id;
$second->commercial_suivi_id = $admin->id;
$second->create($admin);
$lineId = $second->addline('Wartung M', 30, 1, 0, 0, 0, $maint->id, 0, dol_now(), dol_time_plus_duree(dol_now(), 1, 'y'));
$second->validate($admin);
$second->fetch($second->id);
$second->fetch_lines();
rt_const($db, 'ITTABPDF_DOCS_CONTRACT_CHECK', 'block');
$blocked = $second->active_line($admin, $lineId, dol_now());
$check('activation without the private service description is refused when set to block', $blocked < 0 && strpos($second->error.implode(' ', (array) $second->errors), '(privat)') !== false, $second->error);
$check('AVV is for companies only (type AND product)', $state($private, 'auftragsverarbeitung') === 'none' && $state($private, 'leistungsbeschreibung-betreuung-wartung-privat') === 'missing');
rt_const($db, 'ITTABPDF_DOCS_CONTRACT_CHECK', 'warn');
$second->fetch($second->id);
$second->fetch_lines();
$check('with warn it goes through', $second->active_line($admin, $lineId, dol_now()) > 0, $second->error);

// 7. 1.8.2: renamed documents (hosting -> IT care): rules and settings follow, once the successor is released.
$oldSlug = 'leistungsbeschreibung-hosting-wartung';
$oldDoc = $store->bySlug($oldSlug);
$oldId = $store->save($oldDoc ? (int) $oldDoc->rowid : 0, array('slug' => $oldSlug, 'title' => 'Leistungsbeschreibung Hosting und Wartung', 'kind' => 'internal', 'public' => 0,
	'sendable' => 1, 'text' => '<p>Alt.</p>', 'position' => 0), $admin);
$store->release($oldId, '', $admin);
$legacy = $docs->saveRule(0, array('label' => 'Leistungsbeschreibung Hosting/Wartung übergeben', 'slug' => $oldSlug, 'action' => 'handover', 'customer_type' => 'company',
	'products' => 'DL-HOST*,DL-WART*', 'fk_category' => 0, 'required' => 1, 'renew' => 0, 'position' => 50, 'active' => 1));
rt_const($db, 'ITTABPDF_DOCS_CONTRACT_SEND_B2B', 'agb-unternehmer.pdf,'.$oldSlug.'.pdf');
$moved = $docs->migrateRenamed(1);
$rule = $docs->rule($legacy);
$check('renamed document: the rule follows with the new label and products', $moved >= 2 && $rule->slug === 'leistungsbeschreibung-betreuung-wartung'
	&& $rule->label === 'Leistungsbeschreibung IT-Betreuung und Wartung übergeben' && $rule->products === 'DL-WART*', $rule->slug.' '.$rule->label.' '.$rule->products);
$check('renamed document: the default attachments follow', getDolGlobalString('ITTABPDF_DOCS_CONTRACT_SEND_B2B') === 'agb-unternehmer.pdf,leistungsbeschreibung-betreuung-wartung.pdf',
	getDolGlobalString('ITTABPDF_DOCS_CONTRACT_SEND_B2B'));
$check('renamed document: the old one stays, not to be sent', ($old = $store->bySlug($oldSlug)) && (int) $old->sendable === 0);
$check('moving again changes nothing', $docs->migrateRenamed(1) === 0);
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docrule WHERE rowid = ".((int) $legacy));
rt_const($db, 'ITTABPDF_DOCS_CONTRACT_SEND_B2B', '');

$failed = array_values(array_filter($checks, function ($entry) {
	return !$entry['ok'];
}));
echo json_encode(array('checks' => $checks, 'failed' => count($failed), 'firm' => $firmId, 'private' => $privateId, 'propal' => (int) $propal->id,
	'contract' => (int) $contract->id, 'rule_receipt' => (int) $rules['leistungsbeschreibung-betreuung-wartung']->rowid,
	'rule_consent' => (int) $rules['fernwartung-einverstaendnis']->rowid)), "\n";
exit(0);
