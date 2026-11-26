<?php
/*
 * Open items and reminders, in the disposable Dolibarr of
 * scripts/dev_dolibarr.py (after "agenda"):
 *
 *   php remind.php run   widget items (missing, no reply for N days, signing, QS), reminder after
 *                        N days, at most M times, off by default, history and agenda
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

$checks = array();
$check = function ($name, $ok, $detail = '') use (&$checks) {
	$checks[] = array('check' => $name, 'ok' => (bool) $ok, 'detail' => $detail);
};
$firmId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'office@musterfirma.example'");
$firm = new Societe($db);
$firm->fetch($firmId);
$docs = new WerkbankDocs($db);

// A required document sent eleven days ago, nothing since.
$ruleId = $docs->addManual($firm, 'widerruf');
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE fk_soc = ".$firmId." AND slug = 'widerruf' AND state <> 'added'");
$sent = $docs->record($firmId, 'widerruf', 'sent', 'hand', $admin, array('fk_rule' => $ruleId, 'date' => dol_now() - 11 * 86400));
rt_const($db, 'ITTABPDF_REMIND_DAYS', '7');
rt_const($db, 'ITTABPDF_REMIND_MAX', '1');

$items = $docs->openItems(100);
$kinds = array_count_values(array_column($items, 'kind'));
$waiting = array_filter($items, function ($item) {
	return $item['kind'] === 'waiting' && $item['what'] !== '' && strpos($item['since'], '11') !== false;
});
$check('widget: a document without reply for 11 days', (bool) $waiting, json_encode($kinds));
$check('widget: missing documents are listed too', !empty($kinds['missing']), json_encode($kinds));

// Off by default: nothing goes out.
rt_const($db, 'ITTABPDF_REMIND', '0');
$check('reminders are off by default', $docs->remindDue($admin) === 0);
rt_const($db, 'ITTABPDF_REMIND', '1');
$first = $docs->remindDue($admin);
$check('after 7 days the reminder goes out once', $first >= 1, (string) $first);
$reminded = $db->fetch_object($db->query("SELECT * FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE fk_soc = ".$firmId." AND slug = 'widerruf' AND state = 'reminded' ORDER BY rowid DESC"));
$check('the reminder is in the history and the agenda', $reminded && (int) $reminded->fk_actioncomm > 0
	&& rt_value($db, "SELECT code FROM ".MAIN_DB_PREFIX."actioncomm WHERE id = ".((int) $reminded->fk_actioncomm)) === 'AC_ITTAB_DOC_REMINDED');
// Back in time again: the maximum (1) is reached, no second reminder.
rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."ittabpdf_docevent SET date_event = '".$db->idate(dol_now() - 9 * 86400)."' WHERE fk_soc = ".$firmId." AND slug = 'widerruf' AND state = 'sent'");
$check('at most M reminders', $docs->remindDue($admin) === 0);
rt_const($db, 'ITTABPDF_REMIND', '0');
$docs->removeManual($ruleId, $firmId);

$failed = array_values(array_filter($checks, function ($entry) {
	return !$entry['ok'];
}));
echo json_encode(array('checks' => $checks, 'failed' => count($failed), 'firm' => $firmId)), "\n";
exit(0);
