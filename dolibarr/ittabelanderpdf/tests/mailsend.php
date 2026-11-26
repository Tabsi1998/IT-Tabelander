<?php
/*
 * Sending company documents and adding them by hand, in the disposable
 * Dolibarr of scripts/dev_dolibarr.py (after "gen"):
 *
 *   php mailsend.php run   documents by hand (rule of one customer), generating without a rule,
 *                          states, removing; what may be sent; the text of the e-mail; prints ids
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

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankdoc.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankdocmail.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankmailtemplates.class.php');

$checks = array();
$check = function ($name, $ok, $detail = '') use (&$checks) {
	$checks[] = array('check' => $name, 'ok' => (bool) $ok, 'detail' => $detail);
};
$firmId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'office@musterfirma.example'");
$privateId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'max@mustermann.example'");
$firm = new Societe($db);
$firm->fetch($firmId);
$private = new Societe($db);
$private->fetch($privateId);
$docs = new WerkbankDocs($db);
$store = new WerkbankDoc($db);
$unterlagen = new WerkbankUnterlagen($db);

// 0. What may go to customers.
$internal = $store->bySlug('preisliste-intern-test');
$internalId = $store->save($internal ? (int) $internal->rowid : 0, array('slug' => 'preisliste-intern-test', 'title' => 'Interner Baustein', 'kind' => 'internal', 'public' => 0,
	'sendable' => 0, 'text' => '<p>Nur intern.</p>', 'position' => 99), $admin);
$store->release($internalId, 'Test', $admin);
$files = $unterlagen->files();
$check('a document not to be sent is never offered', !isset($files['preisliste-intern-test.pdf']) && isset($files['agb-unternehmer.pdf']), implode(', ', array_keys($files)));
$template = $store->bySlug('gen-test-vereinbarung');
$check('a template per customer has no general PDF and is not offered', $template && $template->kind === 'customer'
	&& !is_file(WerkbankUnterlagen::dir().'/gen-test-vereinbarung.pdf') && !isset($files['gen-test-vereinbarung.pdf']));
// A blank PDF from earlier (when it was another kind) goes on the next activation.
dol_copy(WerkbankUnterlagen::dir().'/agb-unternehmer.pdf', WerkbankUnterlagen::dir().'/gen-test-vereinbarung.pdf', '0', 1);
$check('an old blank PDF of a template per customer is removed', $store->removeBlanks($admin) === 1 && !is_file(WerkbankUnterlagen::dir().'/gen-test-vereinbarung.pdf'));
$qs = $store->bySlug('qs-checklisten');
$check('the shipped text blocks are marked not to be sent (import)', $qs && (int) $qs->sendable === 0, $qs ? (string) $qs->sendable : 'missing');

// 1. A document by hand: a line of its own, state like the others, removable.
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docrule WHERE fk_soc IN (".$firmId.", ".$privateId.")");
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE fk_soc = ".$privateId." AND slug IN ('preisliste', 'gen-test-vereinbarung', 'agb-unternehmer')");
$state = function ($soc, $slug) use ($docs) {
	foreach ($docs->status($soc) as $entry) {
		if ($entry['rule']->slug === $slug) {
			return array($entry['state'], $entry['rule']);
		}
	}
	return array('none', null);
};
$check('no rule asks the private person for the AGB for companies', $state($private, 'agb-unternehmer')[0] === 'none');
$ruleId = $docs->addManual($private, 'agb-unternehmer');
list($now, $rule) = $state($private, 'agb-unternehmer');
$check('added by hand: a line for this customer only', $ruleId > 0 && $now === 'missing' && (int) $rule->fk_soc === $privateId && $rule->action === 'accept'
	&& $state($firm, 'agb-unternehmer')[1] && empty($state($firm, 'agb-unternehmer')[1]->fk_soc));
$check('adding it again keeps one line', $docs->addManual($private, 'agb-unternehmer') === $ruleId);
$check('the setup does not list it among the rules', !in_array($ruleId, array_map(function ($r) {
	return (int) $r->rowid;
}, $docs->rules()), true));
$docs->record($privateId, 'agb-unternehmer', 'sent', 'mail', $admin, array('fk_rule' => $ruleId));
$check('sending it marks the line sent', $state($private, 'agb-unternehmer')[0] === 'sent');
$docs->record($privateId, 'agb-unternehmer', 'checked', 'hand', $admin, array('fk_rule' => $ruleId));
$check('ticking it marks it done', $state($private, 'agb-unternehmer')[0] === 'done');
$check('removing takes the line away, the history stays', $docs->removeManual($ruleId, $privateId) === 1 && $state($private, 'agb-unternehmer')[0] === 'none'
	&& (int) rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE fk_soc = ".$privateId." AND slug = 'agb-unternehmer'") >= 2);
$check('a line of another customer cannot be removed', $docs->removeManual((int) $state($firm, 'auftragsverarbeitung')[1]->rowid, $privateId) === 0);

// 2. A template per customer without a rule: generated for the private person.
$genRule = $docs->addManual($private, 'gen-test-vereinbarung');
$check('a template per customer by hand asks for both signatures', $genRule > 0 && $docs->rule($genRule)->action === 'sign');
$event = $docs->generate($private, $docs->rule($genRule), array('datenkategorien' => array('Kundendaten'), 'ansprech' => 'Max Mustermann'), null, null, $admin);
$check('generated without a rule of the setup', $event > 0 && $state($private, 'gen-test-vereinbarung')[0] === 'generated', $docs->error);

// 3. The text of the e-mail: one, several, Sie, Du, what to do.
$generated = $docs->event($event);
$paths = array(WerkbankUnterlagen::dir().'/agb-unternehmer.pdf');
$one = WerkbankDocMail::substitutions($db, $firmId, $paths);
$check('one document: its title in the subject', $one['__ITTAB_UNTERLAGEN_BETREFF__'] === 'AGB für Unternehmer von '.$mysoc->name, $one['__ITTAB_UNTERLAGEN_BETREFF__']);
$check('one document: listed with Stand and what to do (Sie)', strpos($one['__ITTAB_UNTERLAGEN_LISTE__'], 'AGB für Unternehmer') !== false
	&& strpos($one['__ITTAB_UNTERLAGEN_LISTE__'], 'Stand ') !== false && strpos($one['__ITTAB_UNTERLAGEN_LISTE__'], 'bestätigen Sie') !== false
	&& strpos($one['__ITTAB_UNTERLAGEN_EINLEITUNG__'], 'diese Unterlage') !== false, strip_tags($one['__ITTAB_UNTERLAGEN_LISTE__']));
$several = WerkbankDocMail::substitutions($db, $privateId, array($paths[0], WerkbankSignature::thirdpartyDir($privateId).'/'.$generated->filename));
$check('several documents: plural subject and opening (Du)', $several['__ITTAB_UNTERLAGEN_BETREFF_DU__'] === 'Deine Unterlagen von '.$mysoc->name
	&& strpos($several['__ITTAB_UNTERLAGEN_EINLEITUNG_DU__'], 'folgende Unterlagen') !== false, $several['__ITTAB_UNTERLAGEN_BETREFF_DU__']);
$check('the generated one says it is filled in and asks to sign', strpos($several['__ITTAB_UNTERLAGEN_LISTE_DU__'], 'für dich ausgefüllt') !== false
	&& strpos($several['__ITTAB_UNTERLAGEN_LISTE_DU__'], 'unterschreib') !== false, strip_tags($several['__ITTAB_UNTERLAGEN_LISTE_DU__']));
$check('the text passes the WAF', !preg_match('/<style|<svg|=\s*["\']?data:|\son[a-z]+\s*=/i', implode('', $several)));

// A template with a required field and no values yet for the company: the mail form asks to fill in first.
$must = $store->bySlug('gen-test-pflicht');
$mustId = $store->save($must ? (int) $must->rowid : 0, array('slug' => 'gen-test-pflicht', 'title' => 'Pflichtfeld-Test', 'kind' => 'customer', 'public' => 0,
	'text' => '<p>Für __ITTAB_KUNDE_NAME__: [[FELD:geraet*|Gerät|Text]]</p>', 'position' => 99), $admin);
$store->release($mustId, 'Test', $admin);
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE slug = 'gen-test-pflicht'");

// 4. Templates: "Unterlagen zusenden" and "Unterschriebene Unterlage", Sie and Du.
foreach (array('docsend', 'unterlage', 'thirdparty') as $key) {
	foreach (array('sie', 'du') as $form) {
		$check('template '.WerkbankMailTemplates::label($key, $form).' is installed', WerkbankMailTemplates::find($db, $key, $form, $conf->entity) > 0);
	}
}
$content = (string) rt_value($db, "SELECT content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE rowid = ".WerkbankMailTemplates::find($db, 'docsend', 'sie', $conf->entity));
$check('"Unterlagen zusenden" has one closing and no own signature, no facts box', substr_count($content, 'Mit freundlichen Grüßen') === 1
	&& strpos($content, '__USER_SIGNATURE__') === false && strpos($content, '__SENDEREMAIL_SIGNATURE__') === false && strpos($content, '__ITTAB_FAKTEN__') === false
	&& strpos($content, '__ITTAB_GUTENTAG__') !== false);

$failed = array_values(array_filter($checks, function ($entry) {
	return !$entry['ok'];
}));
echo json_encode(array('checks' => $checks, 'failed' => count($failed), 'firm' => $firmId, 'private' => $privateId, 'event' => $event, 'genrule' => $genRule,
	'contract' => (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."contrat WHERE fk_soc = ".$firmId." AND statut = 1 ORDER BY rowid DESC"),
	'propal' => (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."propal WHERE fk_soc = ".$firmId." ORDER BY rowid DESC"),
	'template' => (int) $template->rowid,
	'contact' => (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."socpeople WHERE lastname = 'Muster' AND fk_soc = ".$firmId))), "\n";
exit(0);
