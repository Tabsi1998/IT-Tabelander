<?php
/*
 * System check and the texts outside the module, in the disposable
 * Dolibarr of scripts/dev_dolibarr.py (after "erinnerung"):
 *
 *   php betrieb.php run   system check items, rollout plan and apply with backup
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
dol_include_once('/ittabelanderpdf/class/werkbanksystemcheck.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankrollout.class.php');

$checks = array();
$check = function ($name, $ok, $detail = '') use (&$checks) {
	$checks[] = array('check' => $name, 'ok' => (bool) $ok, 'detail' => $detail);
};

// 1. System check: every item, with a state and a way to fix it.
$system = new WerkbankSystemCheck($db);
$items = $system->run();
$keys = array_column($items, 'key');
$check('system check covers rights, cron, logo, templates, legal texts, rules, price list, collector, captcha',
	(bool) preg_grep('/^api/', $keys) && !array_diff(array('cron', 'logo', 'templates', 'legal', 'rules', 'pricelist', 'collector', 'captcha'), $keys), implode(',', $keys));
$check('every item has a state, an explanation and a link', !array_filter($items, function ($item) {
	return !in_array($item['state'], array('ok', 'warn', 'error'), true) || $item['detail'] === '' || $item['link'] === '';
}));
$api = array_values(array_filter($items, function ($item) {
	return strpos($item['key'], 'api') === 0;
}));
// Without a website user of its own (here: none without admin rights) the check says so; with one, it names the missing rights.
$check('the website user is checked', $api && in_array($api[0]['state'], array('ok', 'warn'), true) && $api[0]['detail'] !== '', $api ? $api[0]['detail'] : '');
// The website user (2.0.1): a maintenance user with an API key is no website user.
$apiItem = function () use ($system) {
	$found = array_values(array_filter($system->run(), function ($item) {
		return strpos($item['key'], 'api') === 0;
	}));
	return $found ? $found[0] : array('state' => '', 'title' => '', 'detail' => '');
};
rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."user SET api_key = NULL WHERE admin = 0");
$made = array();
foreach (array('checkweb' => true, 'checkwartung' => false) as $login => $right) {
	$apiUser = new User($db);
	if ($apiUser->fetch(0, $login) <= 0) {
		$apiUser = new User($db);
		$apiUser->login = $login;
		$apiUser->lastname = 'Check '.$login;
		$apiUser->email = $login.'@example.invalid';
		if ($apiUser->create($admin) <= 0) {
			rt_fail('user '.$login.': '.$apiUser->error);
		}
	}
	rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."user_rights WHERE fk_user = ".((int) $apiUser->id));
	if ($right) {
		$apiUser->addrights(75623003);
	}
	rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."user SET api_key = 'checkkey".$login."' WHERE rowid = ".((int) $apiUser->id));
	$made[$login] = $apiUser;
}
rt_const($db, 'ITTABPDF_WEBSITE_USER', '0');
$item = $apiItem();
$check('the website user is the one API user with the customer area right, not the maintenance user',
	$system->websiteUser() === (int) $made['checkweb']->id && strpos($item['title'], 'checkweb') !== false && strpos($item['title'].$item['detail'], 'checkwartung') === false, $item['title']);
$made['checkwartung']->addrights(75623003);
$item = $apiItem();
$check('two candidates: a hint to choose, no user listed as the website user', $system->websiteUser() === 0 && $item['state'] === 'warn'
	&& strpos($item['detail'], 'checkweb') !== false && strpos($item['title'], 'checkweb') === false, $item['detail']);
rt_const($db, 'ITTABPDF_WEBSITE_USER', (string) $made['checkweb']->id);
$item = $apiItem();
$check('the chosen website user is checked', $system->websiteUser() === (int) $made['checkweb']->id && strpos($item['title'], 'checkweb') !== false, $item['title']);
rt_const($db, 'ITTABPDF_WEBSITE_USER', '0');
rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."user SET api_key = NULL WHERE login IN ('checkweb', 'checkwartung')");
// The mail logo: the file is there; the public address cannot be reached from inside the container (like hairpin NAT): a hint, no warning.
$logo = array_values(array_filter($items, function ($item) {
	return $item['key'] === 'logo';
}));
$check('mail logo: there locally, the public address only as a hint', $logo && $logo[0]['state'] === 'ok' && strpos($logo[0]['detail'], 'maillogo.php') !== false, $logo ? $logo[0]['detail'] : '');
// A rule pointing to a document that does not exist is reported.
dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
$docs = new WerkbankDocs($db);
$bad = $docs->saveRule(0, array('label' => 'Gibt es nicht', 'slug' => 'gibt-es-nicht', 'action' => 'accept', 'customer_type' => '', 'products' => '', 'fk_category' => 0,
	'required' => 0, 'renew' => 0, 'position' => 99, 'active' => 1));
$rules = array_values(array_filter($system->run(), function ($item) {
	return $item['key'] === 'rules';
}));
$check('a rule without its document is reported', $rules && $rules[0]['state'] === 'warn' && strpos($rules[0]['detail'], 'Gibt es nicht') !== false);
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docrule WHERE rowid = ".((int) $bad));

// 2. Texts outside the module: plan, apply with backup, nothing for templates that do not exist.
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."c_email_templates WHERE label LIKE 'Mahnwesen - %'");
rt_exec($db, "INSERT INTO ".MAIN_DB_PREFIX."c_email_templates (entity, module, type_template, label, active, topic, content) VALUES (1, 'mahnwesen', 'facture_send', 'Mahnwesen - 1. Mahnung', 1, 'Alt', '<p>Alter Text</p>')");
rt_const($db, 'TICKET_MESSAGE_MAIL_INTRO', 'Alte Einleitung');
$plan = WerkbankRollout::plan($db, $mysoc);
$check('plan: the Mahnwesen template differs, missing ones are marked', $plan['tpl:mahnung-1']['found'] === 1 && $plan['tpl:mahnung-1']['current'] !== $plan['tpl:mahnung-1']['new']
	&& $plan['tpl:mahnung-2']['found'] === 0);
$check('the new Mahnwesen text keeps the marker and the plugin placeholders', strpos($plan['tpl:mahnung-1']['new'], WerkbankRollout::MARKER) !== false
	&& strpos($plan['tpl:mahnung-1']['new'], '{DUNNING_TOTAL}') !== false && strpos($plan['tpl:mahnung-1']['new'], '__ITTAB_LOGO__') > strpos($plan['tpl:mahnung-1']['new'], WerkbankRollout::MARKER));
$result = WerkbankRollout::apply($db, $mysoc, array('tpl:mahnung-1', 'tpl:mahnung-2', 'const:TICKET_MESSAGE_MAIL_INTRO'), $admin);
$backup = is_array($result) ? $conf->ittabelanderpdf->dir_output.'/rollout/'.$result['backup'] : '';
$saved = $backup !== '' && is_file($backup) ? json_decode((string) file_get_contents($backup), true) : array();
$check('apply: two texts written, the old ones in the backup', is_array($result) && $result['written'] === 2 && isset($saved['tpl:mahnung-1'])
	&& $saved['tpl:mahnung-1']['content'] === '<p>Alter Text</p>' && $saved['const:TICKET_MESSAGE_MAIL_INTRO']['content'] === 'Alte Einleitung', json_encode($result));
$check('the template and the introduction carry the new text', strpos((string) rt_value($db, "SELECT content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE label = 'Mahnwesen - 1. Mahnung'"), WerkbankRollout::MARKER) !== false
	&& strpos(getDolGlobalString('TICKET_MESSAGE_MAIL_INTRO'), 'Guten Tag,') !== false);
$check('afterwards it is up to date', WerkbankRollout::plan($db, $mysoc)['tpl:mahnung-1']['current'] === WerkbankRollout::plan($db, $mysoc)['tpl:mahnung-1']['new']);
if (!function_exists('testSqlAndScriptInject')) {
	require_once DOL_DOCUMENT_ROOT.'/waf.inc.php';
}
$check('the texts pass the WAF', !testSqlAndScriptInject($plan['tpl:mahnung-1']['new'], 0) && !testSqlAndScriptInject(WerkbankRollout::ticketIntro(), 0));
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."c_email_templates WHERE label LIKE 'Mahnwesen - %'");

$failed = array_values(array_filter($checks, function ($entry) {
	return !$entry['ok'];
}));
echo json_encode(array('checks' => $checks, 'failed' => count($failed))), "\n";
exit(0);
