<?php
/*
 * The module's history in Dolibarr's agenda, in the disposable Dolibarr of
 * scripts/dev_dolibarr.py (after "versand"; QS and intake from "sign"):
 *
 *   php agenda.php run   event type, document events with third party and object, no second
 *                        event for an e-mail, QS closed, intake signed, backfill once, switch off
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
rt_modules(array('modAgenda'));
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankdoc.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankagenda.class.php');

$checks = array();
$check = function ($name, $ok, $detail = '') use (&$checks) {
	$checks[] = array('check' => $name, 'ok' => (bool) $ok, 'detail' => $detail);
};
$count = function ($where) use ($db) {
	return (int) rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."actioncomm WHERE ".$where);
};
rt_const($db, 'ITTABPDF_AGENDA', '1');
$check('event type "Unterlage" exists', WerkbankAgenda::ensureType($db) === 1
	&& rt_value($db, "SELECT code FROM ".MAIN_DB_PREFIX."c_actioncomm WHERE code = 'AC_ITTAB_DOC'") === 'AC_ITTAB_DOC');

$firmId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'office@musterfirma.example'");
$firm = new Societe($db);
$firm->fetch($firmId);
$docs = new WerkbankDocs($db);

// 1. What happened so far comes into the agenda once (on activation).
$agenda = new WerkbankAgenda($db);
$agenda->backfill();
$before = $count("code LIKE 'AC_ITTAB_%'");
$check('backfill writes the history once', $agenda->backfill() === 0 && $before > 0, (string) $before);
$check('every row of the history has its event (except e-mails)',
	!rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE fk_actioncomm IS NULL AND state IN ('generated','signed','uploaded','checked','added','removed','revoked','outdated')"));
$check('no event of the module for an e-mail (Dolibarr has its own)',
	!rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE state = 'sent' AND source = 'mail' AND fk_actioncomm IS NOT NULL"));
$check('a closed QS run is in the agenda of the intervention', $count("code = 'AC_ITTAB_QS_CLOSED' AND elementtype = 'fichinter'") > 0);
$check('a signed intake slip is in the agenda of the ticket', $count("code = 'AC_ITTAB_INTAKE_SIGNED' AND elementtype = 'ticket'") > 0);

// 2. New events: with the third party, the title and the object.
$ruleId = $docs->addManual($firm, 'agb-unternehmer');
$added = $db->fetch_object($db->query("SELECT a.* FROM ".MAIN_DB_PREFIX."actioncomm AS a INNER JOIN ".MAIN_DB_PREFIX."ittabpdf_docevent AS e ON e.fk_actioncomm = a.id"
	." WHERE e.fk_soc = ".$firmId." AND e.state = 'added' ORDER BY e.rowid DESC"));
$check('adding a document: event with the third party and a title', $added && (int) $added->fk_soc === $firmId && strpos((string) $added->label, 'Unterlage hinzugefügt: AGB für Unternehmer') === 0
	&& (int) $added->percent === -1, $added ? $added->label : '');
$contractId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."contrat WHERE fk_soc = ".$firmId." AND statut = 1 ORDER BY rowid DESC");
$event = $docs->record($firmId, 'agb-unternehmer', 'checked', 'hand', $admin, array('fk_rule' => $ruleId, 'element' => 'contrat', 'fk_object' => $contractId, 'note' => 'Am Tresen'));
$checked = $db->fetch_object($db->query("SELECT a.* FROM ".MAIN_DB_PREFIX."actioncomm AS a INNER JOIN ".MAIN_DB_PREFIX."ittabpdf_docevent AS e ON e.fk_actioncomm = a.id WHERE e.rowid = ".((int) $event)));
$check('ticking with a contract: event with the contract and the note', $checked && $checked->elementtype === 'contract' && (int) $checked->fk_element === $contractId
	&& strpos((string) $checked->note, 'Am Tresen') !== false && $checked->code === 'AC_ITTAB_DOC_CHECKED', $checked ? $checked->elementtype.' '.$checked->fk_element : '');
$mails = $count("fk_soc = ".$firmId." AND code = 'AC_ITTAB_DOC_SENT'");
$docs->record($firmId, 'agb-unternehmer', 'sent', 'mail', $admin, array('fk_rule' => $ruleId));
$check('sent by e-mail: no second event', $count("fk_soc = ".$firmId." AND code = 'AC_ITTAB_DOC_SENT'") === $mails);
$docs->removeManual($ruleId, $firmId);
$check('removing: event "entfernt"', $count("fk_soc = ".$firmId." AND code = 'AC_ITTAB_DOC_REMOVED'") > 0);

// 3. A new version of a renewing document: customers who had confirmed it are asked again.
$store = new WerkbankDoc($db);
$agb = $store->bySlug('agb-unternehmer');
$store->save((int) $agb->rowid, array('slug' => $agb->slug, 'title' => $agb->title, 'kind' => $agb->kind, 'public' => $agb->public,
	'text' => (string) $agb->text.'<p>Neu.</p>', 'position' => $agb->position), $admin);
rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."ittabpdf_docevent SET version = '2020-01-01' WHERE slug = 'agb-unternehmer' AND state IN ('signed', 'checked')");
$outdated = $count("code = 'AC_ITTAB_DOC_OUTDATED'");
$store->release((int) $agb->rowid, 'Test', $admin);
$check('new version: "Neue Fassung nötig" in the agenda', $count("code = 'AC_ITTAB_DOC_OUTDATED'") > $outdated);

// 4. Switched off: nothing new.
rt_const($db, 'ITTABPDF_AGENDA', '0');
$all = $count("code LIKE 'AC_ITTAB_%'");
$docs->record($firmId, 'agb-unternehmer', 'checked', 'hand', $admin);
$check('switched off: no event', $count("code LIKE 'AC_ITTAB_%'") === $all);
rt_const($db, 'ITTABPDF_AGENDA', '1');

$check('the tab counts the open required documents', $docs->openCount($firmId) === count($docs->missing($firm)));

$failed = array_values(array_filter($checks, function ($entry) {
	return !$entry['ok'];
}));
echo json_encode(array('checks' => $checks, 'failed' => count($failed), 'firm' => $firmId, 'contract' => $contractId)), "\n";
exit(0);
