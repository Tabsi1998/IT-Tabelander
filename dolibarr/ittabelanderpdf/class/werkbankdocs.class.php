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

require_once __DIR__.'/werkbankunterlagen.class.php';

/**
 * Which company documents a customer needs (rules in the module setup) and
 * what happened with each: sent, signed on the device, accepted with a
 * proposal, uploaded as a signed scan, ticked by hand. The status of a
 * customer is computed from the rules and the events every time; nothing is
 * cached, so a new rule or a new version of a document shows at once.
 */
class WerkbankDocs
{
	/** What the customer does with a document. */
	const ACTIONS = array('accept', 'sign', 'handover');

	/** When a rule applies. */
	const SCOPES = array('all', 'company', 'private', 'contract', 'category');

	/** Events that count as done. */
	const DONE = array('signed', 'uploaded', 'checked');

	/** @var DoliDB */
	private $db;

	/** @var string */
	public $error = '';

	/** @var array<string,array<string,mixed>>|null slug => title, stand */
	private $documents = null;

	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	// ------------------------------------------------------------ rules

	/**
	 * The rules the module ships, written only when an entity has none:
	 * label, slug, action, scope, scope value, required, renew.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	public static function defaults()
	{
		$hosting = 'DL-HOST*,DL-WART*';
		return array(
			array('AGB für Unternehmer akzeptiert', 'agb-unternehmer', 'accept', 'company', '', 1, 1),
			array('Nutzungsbedingungen akzeptiert', 'nutzungsbedingungen', 'accept', 'private', '', 1, 1),
			array('Auftragsverarbeitung (AVV) unterschrieben', 'auftragsverarbeitung', 'sign', 'contract', $hosting, 1, 1),
			array('Leistungsbeschreibung Hosting/Wartung übergeben', 'leistungsbeschreibung-hosting-wartung', 'handover', 'contract', $hosting, 1, 0),
			array('Einverständnis Fernwartung unterschrieben', 'fernwartung-einverstaendnis', 'sign', 'category', '', 1, 1),
		);
	}

	/**
	 * @param int $entity Entity
	 * @return int Rules written, -1 on error
	 */
	public function installDefaults($entity)
	{
		$resql = $this->db->query("SELECT COUNT(*) AS found FROM ".MAIN_DB_PREFIX."ittabpdf_docrule WHERE entity = ".((int) $entity));
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ((int) $obj->found > 0) {
			return 0;
		}
		// "Fernwartung" as a customer tag, when there is one; else the rule waits switched off.
		$category = 0;
		$found = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."categorie WHERE type = 2 AND label = 'Fernwartung' AND entity IN (".getEntity('category').")");
		if ($found && ($row = $this->db->fetch_object($found))) {
			$category = (int) $row->rowid;
		}
		foreach (self::defaults() as $index => $rule) {
			$values = array('label' => $rule[0], 'slug' => $rule[1], 'action' => $rule[2], 'scope' => $rule[3], 'scope_value' => $rule[4],
				'required' => $rule[5], 'renew' => $rule[6], 'position' => ($index + 1) * 10, 'active' => 1);
			if ($rule[3] === 'category') {
				$values['scope_value'] = $category ? (string) $category : '';
				$values['active'] = $category ? 1 : 0;
			}
			if ($this->saveRule(0, $values, $entity) < 0) {
				return -1;
			}
		}
		return count(self::defaults());
	}

	/**
	 * @param bool $activeOnly Only active rules
	 * @return array<int,object>
	 */
	public function rules($activeOnly = false)
	{
		global $conf;
		return $this->rows("SELECT * FROM ".MAIN_DB_PREFIX."ittabpdf_docrule WHERE entity = ".((int) $conf->entity)
			.($activeOnly ? " AND active = 1" : "")." ORDER BY position, rowid");
	}

	/**
	 * @param int $id Rule
	 * @return object|null
	 */
	public function rule($id)
	{
		global $conf;
		$rows = $this->rows("SELECT * FROM ".MAIN_DB_PREFIX."ittabpdf_docrule WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
		return $rows ? $rows[0] : null;
	}

	/**
	 * Insert ($id 0) or update a rule.
	 *
	 * @param int                 $id     Row id or 0
	 * @param array<string,mixed> $values label, slug, action, scope, scope_value, required, renew, position, active
	 * @param int|null            $entity Entity, null = current
	 * @return int                        Row id, -1 on error
	 */
	public function saveRule($id, array $values, $entity = null)
	{
		global $conf;
		$entity = $entity === null ? (int) $conf->entity : (int) $entity;
		$label = trim((string) $values['label']);
		$slug = WerkbankUnterlagen::slugify(preg_replace('/\.pdf$/i', '', (string) $values['slug']));
		if ($label === '' || $slug === '') {
			$this->error = 'ErrorFieldRequired';
			return -1;
		}
		$fields = array(
			'label' => "'".$this->db->escape(dol_trunc($label, 128, 'right', 'UTF-8', 1))."'",
			'slug' => "'".$this->db->escape($slug)."'",
			'action' => "'".$this->db->escape(in_array($values['action'], self::ACTIONS, true) ? $values['action'] : 'accept')."'",
			'scope' => "'".$this->db->escape(in_array($values['scope'], self::SCOPES, true) ? $values['scope'] : 'all')."'",
			'scope_value' => "'".$this->db->escape(dol_trunc(trim((string) $values['scope_value']), 255, 'right', 'UTF-8', 1))."'",
			'required' => empty($values['required']) ? 0 : 1,
			'renew' => empty($values['renew']) ? 0 : 1,
			'position' => (int) $values['position'],
			'active' => empty($values['active']) ? 0 : 1,
		);
		if ($id > 0) {
			$set = array();
			foreach ($fields as $column => $literal) {
				$set[] = $column." = ".$literal;
			}
			$ok = $this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_docrule SET ".implode(', ', $set)." WHERE rowid = ".((int) $id)." AND entity = ".$entity);
			return $ok ? (int) $id : $this->fail();
		}
		$fields = array('entity' => $entity) + $fields;
		$ok = $this->db->query("INSERT INTO ".MAIN_DB_PREFIX."ittabpdf_docrule (".implode(', ', array_keys($fields)).") VALUES (".implode(', ', $fields).")");
		return $ok ? (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'ittabpdf_docrule') : $this->fail();
	}

	// ------------------------------------------------------------ who needs what

	/**
	 * Does the rule apply to this customer?
	 *
	 * @param object  $rule Rule
	 * @param Societe $soc  Customer
	 * @return bool
	 */
	public function applies($rule, $soc)
	{
		switch ($rule->scope) {
			case 'all':
				return true;
			case 'company':
				return !self::isPrivate($this->db, $soc);
			case 'private':
				return self::isPrivate($this->db, $soc);
			case 'contract':
				return (bool) $this->contractMatches((int) $soc->id, (string) $rule->scope_value);
			case 'category':
				$category = (int) $rule->scope_value;
				if ($category <= 0) {
					return false;
				}
				$resql = $this->db->query("SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_societe WHERE fk_soc = ".((int) $soc->id)." AND fk_categorie = ".$category);
				return $resql && $this->db->num_rows($resql) > 0;
		}
		return false;
	}

	/**
	 * Third party type "Privatperson" (TE_PRIVATE).
	 *
	 * @param DoliDB  $db  Database
	 * @param Societe $soc Third party
	 * @return bool
	 */
	public static function isPrivate($db, $soc)
	{
		$code = isset($soc->typent_code) ? (string) $soc->typent_code : '';
		if ($code === '' && !empty($soc->typent_id)) {
			$code = (string) dol_getIdFromCode($db, (int) $soc->typent_id, 'c_typent', 'id', 'code');
		}
		return $code === 'TE_PRIVATE';
	}

	/**
	 * Products of open contract lines of a validated contract that match the
	 * patterns ("DL-HOST*,DL-WART*"), by product ref.
	 *
	 * @param int    $socid    Customer
	 * @param string $patterns Comma separated, * and ? allowed
	 * @return string[]        Matching refs
	 */
	public function contractMatches($socid, $patterns)
	{
		$found = array();
		$sql = "SELECT DISTINCT p.ref FROM ".MAIN_DB_PREFIX."contratdet AS d"
			." INNER JOIN ".MAIN_DB_PREFIX."contrat AS c ON c.rowid = d.fk_contrat"
			." INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = d.fk_product"
			." WHERE c.fk_soc = ".((int) $socid)." AND c.statut = 1 AND d.statut IN (0, 4) AND c.entity IN (".getEntity('contract').")";
		foreach ($this->rows($sql) as $row) {
			if (self::matches((string) $row->ref, $patterns)) {
				$found[] = (string) $row->ref;
			}
		}
		return $found;
	}

	/**
	 * @param string $ref      Product ref
	 * @param string $patterns Comma separated patterns
	 * @return bool
	 */
	public static function matches($ref, $patterns)
	{
		foreach (array_filter(array_map('trim', explode(',', $patterns))) as $pattern) {
			$regex = '/^'.str_replace(array('\*', '\?'), array('.*', '.'), preg_quote($pattern, '/')).'$/i';
			if (preg_match($regex, $ref)) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------ status

	/**
	 * @return array<string,array<string,mixed>> slug => title, stand
	 */
	public function documents()
	{
		if ($this->documents === null) {
			$unterlagen = new WerkbankUnterlagen($this->db);
			$this->documents = $unterlagen->documents();
		}
		return $this->documents;
	}

	/**
	 * Every event of a customer, the last one written first (a tick may be
	 * dated back, the order of events still counts).
	 *
	 * @param int $socid Customer
	 * @return array<int,object>
	 */
	public function events($socid)
	{
		global $conf;
		return $this->rows("SELECT e.*, u.firstname, u.lastname, u.login FROM ".MAIN_DB_PREFIX."ittabpdf_docevent AS e"
			." LEFT JOIN ".MAIN_DB_PREFIX."user AS u ON u.rowid = e.fk_user"
			." WHERE e.fk_soc = ".((int) $socid)." AND e.entity = ".((int) $conf->entity)." ORDER BY e.rowid DESC");
	}

	/**
	 * The documents this customer needs or may get, with their state:
	 * missing, sent, signing, done, outdated (done for an older version of a
	 * rule that asks again for a new one).
	 *
	 * @param Societe $soc Customer
	 * @return array<int,array<string,mixed>> rule, state, done (event), sent (event), signing (event), title, stand
	 */
	public function status($soc)
	{
		$documents = $this->documents();
		$events = $this->events((int) $soc->id);
		$list = array();
		foreach ($this->rules(true) as $rule) {
			if (!$this->applies($rule, $soc)) {
				continue;
			}
			$stand = isset($documents[$rule->slug]) ? (string) $documents[$rule->slug]['stand'] : '';
			$entry = array('rule' => $rule, 'state' => 'missing', 'done' => null, 'sent' => null, 'signing' => null,
				'title' => isset($documents[$rule->slug]) ? $documents[$rule->slug]['title'] : $rule->slug, 'stand' => $stand,
				'exists' => is_file(WerkbankUnterlagen::dir().'/'.$rule->slug.'.pdf'));
			foreach ($events as $event) {
				if ($event->slug !== $rule->slug) {
					continue;
				}
				// A revocation ends the history that counts.
				if ($event->state === 'revoked') {
					break;
				}
				if (!$entry['done'] && in_array($event->state, self::DONE, true)) {
					$entry['done'] = $event;
				} elseif (!$entry['sent'] && $event->state === 'sent') {
					$entry['sent'] = $event;
				} elseif (!$entry['signing'] && $event->state === 'signing') {
					$entry['signing'] = $event;
				}
			}
			if ($entry['done']) {
				$old = (int) $rule->renew && $stand !== '' && (string) $entry['done']->version !== '' && (string) $entry['done']->version < $stand;
				$entry['state'] = $old ? 'outdated' : 'done';
			} elseif ($entry['signing']) {
				$entry['state'] = 'signing';
			} elseif ($entry['sent']) {
				$entry['state'] = 'sent';
			}
			$list[] = $entry;
		}
		return $list;
	}

	/**
	 * Labels of the required documents not done (missing, sent, outdated).
	 *
	 * @param Societe $soc Customer
	 * @return string[]
	 */
	public function missing($soc)
	{
		$labels = array();
		foreach ($this->status($soc) as $entry) {
			if ((int) $entry['rule']->required && $entry['state'] !== 'done') {
				$labels[] = (string) $entry['rule']->label;
			}
		}
		return $labels;
	}

	/**
	 * Customers with required documents not done, for the start page.
	 *
	 * @param int $limit At most
	 * @return array<int,array{soc:Societe,missing:string[]}>
	 */
	public function customersMissing($limit = 15)
	{
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$list = array();
		$rows = $this->rows("SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE client IN (1, 3) AND status = 1 AND entity IN (".getEntity('societe').") ORDER BY nom");
		foreach ($rows as $row) {
			$soc = new Societe($this->db);
			if ($soc->fetch((int) $row->rowid) <= 0) {
				continue;
			}
			$missing = $this->missing($soc);
			if ($missing) {
				$list[] = array('soc' => $soc, 'missing' => $missing);
				if (count($list) >= $limit) {
					break;
				}
			}
		}
		return $list;
	}

	// ------------------------------------------------------------ events

	/**
	 * Note what happened with a document at a customer.
	 *
	 * @param int                 $socid  Customer
	 * @param string              $slug   Document
	 * @param string              $state  sent, signing, signed, uploaded, checked, revoked
	 * @param string              $source mail, device, proposal, portal, upload, hand
	 * @param User|null           $user   Who
	 * @param array<string,mixed> $extra  element, fk_object, filename, note, date, version, fk_rule
	 * @return int                        Event id, -1 on error
	 */
	public function record($socid, $slug, $state, $source, $user, array $extra = array())
	{
		global $conf;
		if ($socid <= 0 || $slug === '') {
			return 0;
		}
		$documents = $this->documents();
		$version = isset($extra['version']) ? (string) $extra['version'] : (isset($documents[$slug]) ? (string) $documents[$slug]['stand'] : '');
		$date = !empty($extra['date']) ? (int) $extra['date'] : dol_now();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."ittabpdf_docevent (entity, fk_soc, slug, fk_rule, state, source, version, date_event, element, fk_object, filename, note, fk_user)"
			." VALUES (".((int) $conf->entity).", ".((int) $socid).", '".$this->db->escape($slug)."', ".(!empty($extra['fk_rule']) ? (int) $extra['fk_rule'] : "NULL").","
			." '".$this->db->escape($state)."', '".$this->db->escape($source)."', '".$this->db->escape(substr($version, 0, 10))."', '".$this->db->idate($date)."',"
			." ".(!empty($extra['element']) ? "'".$this->db->escape((string) $extra['element'])."'" : "NULL").", ".(!empty($extra['fk_object']) ? (int) $extra['fk_object'] : "NULL").","
			." ".(!empty($extra['filename']) ? "'".$this->db->escape((string) $extra['filename'])."'" : "NULL").","
			." ".(isset($extra['note']) && trim((string) $extra['note']) !== '' ? "'".$this->db->escape(trim((string) $extra['note']))."'" : "NULL").","
			." ".(is_object($user) ? (int) $user->id : "NULL").")";
		return $this->db->query($sql) ? (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'ittabpdf_docevent') : $this->fail();
	}

	/**
	 * @param int $id Event
	 * @return object|null Event of the current entity
	 */
	public function event($id)
	{
		global $conf;
		$rows = $this->rows("SELECT * FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
		return $rows ? $rows[0] : null;
	}

	/**
	 * A signature on the device is complete: the "signing" event becomes "signed".
	 *
	 * @param int    $id       Event
	 * @param string $filename Signed PDF in the third party's documents
	 * @return int             1 OK, -1 error
	 */
	public function markSigned($id, $filename)
	{
		return $this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_docevent SET state = 'signed', date_event = '".$this->db->idate(dol_now())."',"
			." filename = '".$this->db->escape($filename)."' WHERE rowid = ".((int) $id)." AND state = 'signing'") ? 1 : $this->fail();
	}

	/**
	 * The document was given with a proposal (attached to its e-mail or
	 * appended to its PDF): signing the proposal accepts it.
	 *
	 * @param Propal $propal Proposal now signed
	 * @param User   $user   Who signed it in Dolibarr (the website's API user for the customer area)
	 * @return int           Documents marked
	 */
	public function proposalSigned($propal, $user)
	{
		$slugs = array();
		foreach ($this->rows("SELECT DISTINCT slug FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE element = 'propal' AND fk_object = ".((int) $propal->id)." AND state = 'sent'") as $row) {
			$slugs[] = (string) $row->slug;
		}
		foreach (array_filter(array_map('trim', explode(',', getDolGlobalString('ITTABPDF_APPEND_PROPAL')))) as $name) {
			$slugs[] = preg_replace('/\.pdf$/i', '', basename($name));
		}
		$portal = strpos((string) $propal->note_private, 'Kundenbereich') !== false;
		$marked = 0;
		foreach (array_unique($slugs) as $slug) {
			if ($this->record((int) $propal->socid, $slug, 'signed', $portal ? 'portal' : 'proposal', $user,
				array('element' => 'propal', 'fk_object' => (int) $propal->id, 'note' => $propal->ref)) > 0) {
				$marked++;
			}
		}
		return $marked;
	}

	// ------------------------------------------------------------ helpers

	/**
	 * @param string $sql Query
	 * @return array<int,object>
	 */
	private function rows($sql)
	{
		$list = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$list[] = $obj;
		}
		return $list;
	}

	/**
	 * @return int -1 with the database error
	 */
	private function fail()
	{
		$this->error = $this->db->lasterror();
		return -1;
	}
}
