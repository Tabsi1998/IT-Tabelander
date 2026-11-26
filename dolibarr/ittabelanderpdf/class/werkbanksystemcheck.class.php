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
 * The module's system check: what has to be set up in Dolibarr for
 * everything to work, each with its state, what it means and where to fix
 * it. Reads only, changes nothing.
 */
class WerkbankSystemCheck
{
	/** Rights the website's API user needs: module, permission, sub-permission, what for. */
	const API_RIGHTS = array(
		array('societe', 'lire', '', 'WerkbankCheckRightCustomers'),
		array('propal', 'lire', '', 'WerkbankCheckRightProposals'),
		array('facture', 'lire', '', 'WerkbankCheckRightInvoices'),
		array('ticket', 'read', '', 'WerkbankCheckRightTickets'),
		array('ittabelanderpdf', 'unterlagen', 'read', 'WerkbankCheckRightDocuments'),
		array('ecm', 'read', '', 'WerkbankCheckRightEcm'),
	);

	/** Legal texts the website shows. */
	const LEGAL = array('impressum-ergaenzung', 'datenschutz', 'nutzungsbedingungen', 'widerruf', 'agb-unternehmer');

	/** @var DoliDB */
	private $db;

	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * All checks.
	 *
	 * @return array<int,array{key:string,state:string,title:string,detail:string,link:string}>
	 */
	public function run()
	{
		return array_merge($this->apiUser(), array($this->cron(), $this->logo()), $this->templates(), $this->legal(), $this->rules(),
			array($this->pricelist(), $this->collector(), $this->captcha()));
	}

	/**
	 * @param string $key    Key
	 * @param string $state  ok, warn, error
	 * @param string $title  Title
	 * @param string $detail What it means / what to do
	 * @param string $link   Where to fix it
	 * @return array<string,string>
	 */
	private function item($key, $state, $title, $detail, $link = '')
	{
		return array('key' => $key, 'state' => $state, 'title' => $title, 'detail' => $detail, 'link' => $link);
	}

	/**
	 * Active users without admin rights that have an API key: who could be
	 * the website's user.
	 *
	 * @return array<int,string> id => login
	 */
	public function apiUsers()
	{
		$resql = $this->db->query("SELECT rowid, login FROM ".MAIN_DB_PREFIX."user WHERE api_key IS NOT NULL AND api_key <> '' AND statut = 1 AND admin = 0"
			." AND entity IN (0, ".((int) $GLOBALS['conf']->entity).") ORDER BY login");
		$users = array();
		while ($resql && ($row = $this->db->fetch_object($resql))) {
			$users[(int) $row->rowid] = (string) $row->login;
		}
		return $users;
	}

	/**
	 * The website's user: the one chosen (ITTABPDF_WEBSITE_USER), else the
	 * only API user, else the only API user with the right for the customer
	 * area's documents. 0 when it is not clear (a maintenance user with an
	 * API key of its own, for example).
	 *
	 * @return int User id, 0 none or not clear
	 */
	public function websiteUser()
	{
		$users = $this->apiUsers();
		$chosen = getDolGlobalInt('ITTABPDF_WEBSITE_USER');
		if ($chosen > 0) {
			return isset($users[$chosen]) ? $chosen : 0;
		}
		if (count($users) === 1) {
			return (int) key($users);
		}
		$portal = array();
		foreach (array_keys($users) as $id) {
			$api = new User($this->db);
			if ($api->fetch($id) > 0) {
				$api->loadRights();
				if ($api->hasRight('ittabelanderpdf', 'unterlagen', 'read')) {
					$portal[] = $id;
				}
			}
		}
		return count($portal) === 1 ? $portal[0] : 0;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function apiUser()
	{
		global $langs;
		$users = $this->apiUsers();
		if (!$users) {
			return array($this->item('api', 'warn', $langs->trans('WerkbankCheckApiUser'), $langs->trans('WerkbankCheckApiUserNone'), DOL_URL_ROOT.'/user/list.php'));
		}
		$id = $this->websiteUser();
		if ($id <= 0) {
			$chosen = getDolGlobalInt('ITTABPDF_WEBSITE_USER');
			return array($this->item('api', 'warn', $langs->trans('WerkbankCheckApiUser'),
				$langs->trans($chosen > 0 ? 'WerkbankCheckApiUserGone' : 'WerkbankCheckApiUserChoose', dol_escape_htmltag(implode(', ', $users))), '#websiteuser'));
		}
		$api = new User($this->db);
		$api->fetch($id);
		$api->loadRights();
		$missing = array();
		foreach (self::API_RIGHTS as $right) {
			if (!isModEnabled($right[0] === 'facture' ? 'invoice' : ($right[0] === 'propal' ? 'propal' : $right[0]))) {
				continue;
			}
			if (!$api->hasRight($right[0], $right[1], $right[2])) {
				$missing[] = $langs->transnoentitiesnoconv($right[3]);
			}
		}
		return array($this->item('api', $missing ? 'warn' : 'ok', $langs->trans('WerkbankCheckApiUserName', $api->login),
			$missing ? $langs->trans('WerkbankCheckApiMissing', implode(', ', $missing)) : $langs->trans('WerkbankCheckApiOk'),
			DOL_URL_ROOT.'/user/perms.php?id='.$id));
	}

	/**
	 * @return array<string,string>
	 */
	private function cron()
	{
		global $langs;
		$resql = $this->db->query("SELECT rowid, status, datelastrun FROM ".MAIN_DB_PREFIX."cronjob WHERE objectname = 'WerkbankMailQueue' AND methodename = 'runCron'");
		$row = $resql ? $this->db->fetch_object($resql) : null;
		$link = DOL_URL_ROOT.'/cron/list.php';
		if (!isModEnabled('cron') || !$row || !(int) $row->status) {
			return $this->item('cron', 'error', $langs->trans('WerkbankCheckCron'), $langs->trans('WerkbankCheckCronOff'), $link);
		}
		$last = $this->db->jdate($row->datelastrun);
		if (!$last || $last < dol_now() - 3600) {
			return $this->item('cron', 'warn', $langs->trans('WerkbankCheckCron'), $langs->trans('WerkbankCheckCronStale', $last ? dol_print_date($last, 'dayhour') : '–'), $link);
		}
		return $this->item('cron', 'ok', $langs->trans('WerkbankCheckCron'), $langs->trans('WerkbankCheckCronOk', dol_print_date($last, 'dayhour')), $link);
	}

	/**
	 * @return array<string,string>
	 */
	private function logo()
	{
		global $langs;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
		require_once __DIR__.'/werkbankmaildata.class.php';
		$own = trim(getDolGlobalString('ITTABPDF_MAIL_LOGO_URL'));
		$url = $own ?: dol_buildpath('/ittabelanderpdf/public/maillogo.php', 2);
		$link = '<a href="'.dol_escape_htmltag($url).'" target="_blank" rel="noopener">'.dol_escape_htmltag($url).'</a>';
		// Here first: the module's own logo (public/maillogo.php serves exactly this file).
		if ($own === '') {
			$file = WerkbankMailData::logoFile();
			if ($file === '' || strpos((string) file_get_contents($file, false, null, 0, 4), "\x89PNG") !== 0) {
				return $this->item('logo', 'warn', $langs->trans('WerkbankCheckLogo'), $langs->trans('WerkbankCheckLogoNoFile'), DOL_URL_ROOT.'/admin/company.php');
			}
		}
		// From outside: a server often does not reach its own public address
		// (hairpin NAT), so a failed call from here is only a hint, no error.
		$answer = getURLContent($url, 'GET', '', 1, array(), array('http', 'https'), 2, 5);
		$ok = isset($answer['http_code']) && (int) $answer['http_code'] === 200 && strpos((string) ($answer['content'] ?? ''), "\x89PNG") === 0;
		return $this->item('logo', 'ok', $langs->trans('WerkbankCheckLogo'), $langs->trans($ok ? 'WerkbankCheckLogoOk' : 'WerkbankCheckLogoHint', $link), $url);
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function templates()
	{
		global $langs, $conf;
		require_once __DIR__.'/werkbankmailtemplates.class.php';
		$state = WerkbankMailTemplates::upgrade($this->db, (int) $conf->entity, false, true);
		$link = dol_buildpath('/ittabelanderpdf/admin/setup.php', 1);
		if (!is_array($state)) {
			return array($this->item('templates', 'error', $langs->trans('WerkbankCheckTemplates'), $this->db->lasterror(), $link));
		}
		if ($state['updated'] > 0 || $state['edited'] > 0) {
			return array($this->item('templates', 'warn', $langs->trans('WerkbankCheckTemplates'), $langs->trans('WerkbankCheckTemplatesOld', $state['updated'], $state['edited']), $link));
		}
		return array($this->item('templates', 'ok', $langs->trans('WerkbankCheckTemplates'), $langs->trans('WerkbankCheckTemplatesOk', $state['current']), $link));
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function legal()
	{
		global $langs;
		require_once __DIR__.'/werkbankdoc.class.php';
		$store = new WerkbankDoc($this->db);
		$missing = array();
		foreach (self::LEGAL as $slug) {
			$doc = $store->bySlug($slug);
			if (!$doc || (int) $doc->version === 0 || !(int) $doc->public) {
				$missing[] = $slug;
			}
		}
		return array($this->item('legal', $missing ? 'warn' : 'ok', $langs->trans('WerkbankCheckLegal'),
			$missing ? $langs->trans('WerkbankCheckLegalMissing', implode(', ', $missing)) : $langs->trans('WerkbankCheckLegalOk'),
			dol_buildpath('/ittabelanderpdf/unterlagen_list.php', 1)));
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function rules()
	{
		global $langs;
		require_once __DIR__.'/werkbankdocs.class.php';
		$docs = new WerkbankDocs($this->db);
		$documents = $docs->documents();
		$bad = array();
		foreach ($docs->rules(true) as $rule) {
			$document = isset($documents[$rule->slug]) ? $documents[$rule->slug] : null;
			if (!$document) {
				$bad[] = $rule->label.' ('.$rule->slug.': '.$langs->transnoentities('WerkbankCheckRuleNoDoc').')';
			} elseif ($document['kind'] !== 'customer' && !$document['sendable']) {
				$bad[] = $rule->label.' ('.$langs->transnoentities('WerkbankCheckRuleNotSendable').')';
			}
		}
		return array($this->item('rules', $bad ? 'warn' : 'ok', $langs->trans('WerkbankCheckRules'),
			$bad ? implode('<br>', array_map('dol_escape_htmltag', $bad)) : $langs->trans('WerkbankCheckRulesOk'),
			dol_buildpath('/ittabelanderpdf/admin/docrules.php', 1)));
	}

	/**
	 * @return array<string,string>
	 */
	private function pricelist()
	{
		global $langs;
		require_once __DIR__.'/werkbankpricelist.class.php';
		require_once __DIR__.'/werkbankdoc.class.php';
		$store = new WerkbankDoc($this->db);
		$uses = false;
		foreach ($store->all(array('released' => 1)) as $doc) {
			$record = $store->asRecord($doc);
			if ($record && strpos((string) $record->answer, WerkbankPricelist::PLACEHOLDER) !== false) {
				$uses = true;
			}
		}
		$chosen = array_intersect(WerkbankPricelist::chosen(), array_keys(WerkbankPricelist::categories($this->db)));
		$link = dol_buildpath('/ittabelanderpdf/admin/setup.php', 1).'#pricelist';
		if ($uses && !$chosen) {
			return $this->item('pricelist', 'warn', $langs->trans('WerkbankCheckPricelist'), $langs->trans('WerkbankCheckPricelistNone'), $link);
		}
		return $this->item('pricelist', 'ok', $langs->trans('WerkbankCheckPricelist'),
			$uses ? $langs->trans('WerkbankCheckPricelistOk', count($chosen)) : $langs->trans('WerkbankCheckPricelistUnused'), $link);
	}

	/**
	 * @return array<string,string>
	 */
	private function collector()
	{
		global $langs;
		$active = 0;
		if (isModEnabled('emailcollector')) {
			$resql = $this->db->query("SELECT COUNT(*) AS n FROM ".MAIN_DB_PREFIX."emailcollector_emailcollector WHERE status = 1");
			$row = $resql ? $this->db->fetch_object($resql) : null;
			$active = $row ? (int) $row->n : 0;
		}
		return $this->item('collector', $active ? 'ok' : 'warn', $langs->trans('WerkbankCheckCollector'),
			$langs->trans($active ? 'WerkbankCheckCollectorOk' : 'WerkbankCheckCollectorNone', $active), DOL_URL_ROOT.'/admin/emailcollector_list.php');
	}

	/**
	 * @return array<string,string>
	 */
	private function captcha()
	{
		global $langs;
		$on = getDolGlobalInt('MAIN_SECURITY_ENABLECAPTCHA');
		return $this->item('captcha', $on ? 'ok' : 'warn', $langs->trans('WerkbankCheckCaptcha'),
			$langs->trans($on ? 'WerkbankCheckCaptchaOk' : 'WerkbankCheckCaptchaOff'), DOL_URL_ROOT.'/admin/security_other.php');
	}
}
