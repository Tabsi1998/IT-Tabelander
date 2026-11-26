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

	/** Customer type of a rule: any, companies, private persons. */
	const CUSTOMER_TYPES = array('', 'company', 'private');

	/** Events that count as done. */
	const DONE = array('signed', 'uploaded', 'checked');

	/** @var DoliDB */
	private $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

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
	 * label, slug, action, customer type, contract products, tag, required, renew.
	 * All conditions of a rule must hold; an empty one does not restrict.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	public static function defaults()
	{
		$hosting = 'DL-HOST*,DL-WART*';
		return array(
			array('AGB für Unternehmer akzeptiert', 'agb-unternehmer', 'accept', 'company', '', 'none', 1, 1),
			array('Nutzungsbedingungen akzeptiert', 'nutzungsbedingungen', 'accept', 'private', '', 'none', 1, 1),
			array('Auftragsverarbeitung (AVV) unterschrieben', 'auftragsverarbeitung', 'sign', 'company', $hosting, 'none', 1, 1),
			array('Leistungsbeschreibung Hosting/Wartung übergeben', 'leistungsbeschreibung-hosting-wartung', 'handover', 'company', $hosting, 'none', 1, 0),
			array('Leistungsbeschreibung Hosting/Wartung (privat) übergeben', 'leistungsbeschreibung-hosting-wartung-privat', 'handover', 'private', $hosting, 'none', 1, 0),
			array('Einverständnis Fernwartung unterschrieben', 'fernwartung-einverstaendnis', 'sign', '', '', 'Fernwartung', 1, 1),
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
			$values = array('label' => $rule[0], 'slug' => $rule[1], 'action' => $rule[2], 'customer_type' => $rule[3], 'products' => $rule[4],
				'fk_category' => 0, 'required' => $rule[6], 'renew' => $rule[7], 'position' => ($index + 1) * 10, 'active' => 1);
			if ($rule[5] !== 'none') {
				$values['fk_category'] = $category;
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
	 * @param array<string,mixed> $values label, slug, action, customer_type, products, fk_category, required, renew, position, active
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
			'customer_type' => "'".$this->db->escape(in_array((string) $values['customer_type'], self::CUSTOMER_TYPES, true) ? (string) $values['customer_type'] : '')."'",
			'products' => "'".$this->db->escape(dol_trunc(trim((string) $values['products']), 255, 'right', 'UTF-8', 1))."'",
			'fk_category' => (int) $values['fk_category'],
			'migrated' => 1,
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
		// Every condition that is set must hold (customer type AND product AND tag).
		if ($rule->customer_type === 'company' && self::isPrivate($this->db, $soc)) {
			return false;
		}
		if ($rule->customer_type === 'private' && !self::isPrivate($this->db, $soc)) {
			return false;
		}
		if (trim((string) $rule->products) !== '' && !$this->contractMatches((int) $soc->id, (string) $rule->products)) {
			return false;
		}
		if ((int) $rule->fk_category > 0) {
			$resql = $this->db->query("SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_societe WHERE fk_soc = ".((int) $soc->id)." AND fk_categorie = ".((int) $rule->fk_category));
			if (!$resql || $this->db->num_rows($resql) === 0) {
				return false;
			}
		}
		return true;
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
			$entry = array('rule' => $rule, 'state' => 'missing', 'done' => null, 'sent' => null, 'signing' => null, 'generated' => null, 'before' => array(),
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
				} elseif (!$entry['generated'] && $event->state === 'generated') {
					$entry['generated'] = $event;
				}
			}
			// Generated again after it was sent, signed or done: the new version counts,
			// what happened to an older one no longer does.
			if ($entry['generated']) {
				foreach (array('done', 'sent', 'signing') as $key) {
					if ($entry[$key] && (int) $entry[$key]->rowid < (int) $entry['generated']->rowid) {
						$entry['before'][$key] = $entry[$key];
						$entry[$key] = null;
					}
				}
			}
			if ($entry['done']) {
				$old = (int) $rule->renew && $stand !== '' && (string) $entry['done']->version !== '' && (string) $entry['done']->version < $stand;
				$entry['state'] = $old ? 'outdated' : 'done';
			} elseif ($entry['signing']) {
				$entry['state'] = 'signing';
			} elseif ($entry['sent']) {
				$entry['state'] = 'sent';
			} elseif ($entry['generated']) {
				$entry['state'] = 'generated';
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

	/**
	 * Document names of attachments: files of the Unterlagen folder, and
	 * documents generated for this customer (by their file name).
	 *
	 * @param int      $socid Customer
	 * @param string[] $paths Attached files
	 * @return string[]       Slugs
	 */
	public function slugsForPaths($socid, array $paths)
	{
		$slugs = WerkbankUnterlagen::slugsIn($paths);
		foreach ($paths as $path) {
			$rows = $this->rows("SELECT slug FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE fk_soc = ".((int) $socid)." AND state = 'generated'"
				." AND filename = '".$this->db->escape(basename((string) $path))."'");
			foreach ($rows as $row) {
				$slugs[] = (string) $row->slug;
			}
		}
		return array_values(array_unique($slugs));
	}

	// ------------------------------------------------------------ generated for a customer

	/**
	 * The latest released version of a document as a record the PDF
	 * builders take (question = title, answer = text).
	 *
	 * @param string $slug Document
	 * @return object|null
	 */
	public function template($slug)
	{
		require_once __DIR__.'/werkbankdoc.class.php';
		$store = new WerkbankDoc($this->db);
		$doc = $store->bySlug($slug);
		return $doc && (int) $doc->version > 0 ? $store->asRecord($doc) : null;
	}

	/**
	 * What was entered last time for this customer and document.
	 *
	 * @param int    $socid Customer
	 * @param string $slug  Document
	 * @return array{fields:array<string,mixed>,contract:int,contact:int}
	 */
	public function lastValues($socid, $slug)
	{
		$rows = $this->rows("SELECT data FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE fk_soc = ".((int) $socid)." AND slug = '".$this->db->escape($slug)."'"
			." AND state = 'generated' AND data IS NOT NULL ORDER BY rowid DESC");
		$data = $rows ? json_decode((string) $rows[0]->data, true) : null;
		return array('fields' => isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : array(),
			'contract' => isset($data['contract']) ? (int) $data['contract'] : 0, 'contact' => isset($data['contact']) ? (int) $data['contact'] : 0);
	}

	/**
	 * Validated contracts of the customer, newest first; those with a line
	 * matching the patterns first.
	 *
	 * @param int    $socid    Customer
	 * @param string $patterns Product refs of the rule (contract scope), may be ''
	 * @return array<int,string> id => label
	 */
	public function contracts($socid, $patterns = '')
	{
		$list = array();
		$first = array();
		foreach ($this->rows("SELECT c.rowid, c.ref, c.date_contrat FROM ".MAIN_DB_PREFIX."contrat AS c WHERE c.fk_soc = ".((int) $socid)." AND c.statut = 1"
			." AND c.entity IN (".getEntity('contract').") ORDER BY c.date_contrat DESC, c.rowid DESC") as $row) {
			$refs = array();
			foreach ($this->rows("SELECT p.ref FROM ".MAIN_DB_PREFIX."contratdet AS d INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = d.fk_product"
				." WHERE d.fk_contrat = ".((int) $row->rowid)." AND d.statut IN (0, 4)") as $line) {
				$refs[] = (string) $line->ref;
			}
			$label = $row->ref.($refs ? ' ('.implode(', ', $refs).')' : '');
			$match = $patterns !== '' && array_filter($refs, function ($ref) use ($patterns) {
				return self::matches($ref, $patterns);
			});
			if ($match) {
				$first[(int) $row->rowid] = $label;
			} else {
				$list[(int) $row->rowid] = $label;
			}
		}
		return $first + $list;
	}

	/**
	 * Make the document for a customer: input fields and placeholders
	 * filled, PDF with the customer and in the contract's documents, event
	 * "generated" with the answers for next time.
	 *
	 * @param Societe              $soc      Customer
	 * @param object               $rule     Rule (slug)
	 * @param array<string,mixed>  $answers  Input field values
	 * @param Contrat|null         $contract Contract or null
	 * @param Contact|null         $contact  Contact or null
	 * @param User                 $user     User
	 * @return int                           Event id, -1 error ($this->error, $this->errors with the details)
	 */
	public function generate($soc, $rule, array $answers, $contract, $contact, $user)
	{
		global $langs;
		$this->errors = array();
		$record = $this->template((string) $rule->slug);
		if (!$record) {
			$this->error = $langs->transnoentities('WerkbankGenNoTemplate', $rule->slug);
			return -1;
		}
		require_once __DIR__.'/werkbankfields.class.php';
		require_once __DIR__.'/werkbankvars.class.php';
		$fields = WerkbankFields::parse((string) $record->answer);
		$missing = WerkbankFields::missing($fields, $answers);
		if ($missing) {
			$this->error = $langs->transnoentities('WerkbankGenFieldsMissing', implode(', ', $missing));
			return -1;
		}
		$filled = $this->fill($record, $fields, $answers, $soc, $contract, $contact);
		$left = WerkbankVars::left($filled);
		if ($left) {
			$this->error = $langs->transnoentities('WerkbankGenPlaceholdersLeft', implode(', ', $left));
			return -1;
		}
		$data = json_encode(array('fields' => $answers, 'contract' => $contract ? (int) $contract->id : 0, 'contact' => $contact ? (int) $contact->id : 0));
		$event = $this->record((int) $soc->id, (string) $rule->slug, 'generated', 'hand', $user, array('fk_rule' => (int) $rule->rowid,
			'element' => $contract ? 'contrat' : '', 'fk_object' => $contract ? (int) $contract->id : 0));
		if ($event <= 0) {
			return -1;
		}
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_docevent SET data = '".$this->db->escape((string) $data)."' WHERE rowid = ".((int) $event));
		$path = $this->writeGenerated($soc, $rule, $this->event($event));
		if ($path === '') {
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE rowid = ".((int) $event));
			return -1;
		}
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_docevent SET filename = '".$this->db->escape(basename($path))."' WHERE rowid = ".((int) $event));
		return $event;
	}

	/**
	 * The article's text for this customer.
	 *
	 * @param object              $record   Template (WerkbankDoc::asRecord)
	 * @param array               $fields   Input fields
	 * @param array<string,mixed> $answers  Their values
	 * @param Societe             $soc      Customer
	 * @param Contrat|null        $contract Contract
	 * @param Contact|null        $contact  Contact
	 * @return string
	 */
	public function fill($record, array $fields, array $answers, $soc, $contract, $contact)
	{
		require_once __DIR__.'/werkbankfields.class.php';
		require_once __DIR__.'/werkbankvars.class.php';
		$values = WerkbankVars::values($this->db, $soc, $contract, $contact);
		$values['__ITTAB_FELD_LEER__'] = '';
		return WerkbankVars::fill(WerkbankFields::apply((string) $record->answer, $fields, $answers), $values);
	}

	/**
	 * Write (again) the PDF of a "generated" event: to its own file, or to
	 * $target with signatures (signed on the device).
	 *
	 * @param Societe                            $soc    Customer
	 * @param object                             $rule   Rule
	 * @param object                             $event  The "generated" event (data holds answers, contract, contact)
	 * @param array<string,array<string,string>> $signed role => image, name, when
	 * @param string                             $target Path, '' = <slug>-<date>-<event>.pdf with the customer
	 * @return string                                    Path, '' on error
	 */
	public function writeGenerated($soc, $rule, $event, array $signed = array(), $target = '')
	{
		global $langs, $conf;
		require_once __DIR__.'/werkbankfields.class.php';
		require_once __DIR__.'/werkbankbuilder.personal.class.php';
		require_once __DIR__.'/werkbankrenderer.class.php';
		require_once __DIR__.'/werkbanksignature.class.php';
		require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		$langs->loadLangs(array('main', 'companies', 'ittabelanderpdf@ittabelanderpdf'));
		$record = $this->template((string) $rule->slug);
		if (!$record) {
			$this->error = $langs->transnoentities('WerkbankGenNoTemplate', $rule->slug);
			return '';
		}
		$data = json_decode((string) $event->data, true);
		$answers = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : array();
		$contract = null;
		if (!empty($data['contract'])) {
			$contract = new Contrat($this->db);
			if ($contract->fetch((int) $data['contract']) <= 0) {
				$contract = null;
			}
		}
		$contact = null;
		if (!empty($data['contact'])) {
			$contact = new Contact($this->db);
			if ($contact->fetch((int) $data['contact']) <= 0) {
				$contact = null;
			}
		}
		$record->answer = $this->fill($record, WerkbankFields::parse((string) $record->answer), $answers, $soc, $contract, $contact);
		if ($target === '') {
			$target = WerkbankSignature::thirdpartyDir((int) $soc->id).'/'.$rule->slug.'-'.dol_print_date($this->db->jdate($event->date_event), '%Y%m%d').'-'.((int) $event->rowid).'.pdf';
		}
		if (!is_dir(dirname($target)) && dol_mkdir(dirname($target)) < 0) {
			$this->error = 'ErrorCanNotCreateDir';
			return '';
		}
		$stand = (string) $event->version !== '' ? dol_stringtotime($event->version.' 00:00:00') : null;
		try {
			$builder = new WerkbankBuilderPersonal($this->db, $record, $langs, $stand, $soc, $signed);
			$renderer = new WerkbankRenderer(pdf_getInstance(array(WerkbankRenderer::PAGE_W, WerkbankRenderer::PAGE_H)), $langs);
			$renderer->render($builder->build(), $target);
		} catch (Throwable $exception) {
			dol_syslog('ittabelanderpdf: '.$exception->getMessage(), LOG_ERR);
			$this->error = $exception->getMessage();
			return '';
		}
		dolChmod($target);
		// A copy with the contract, when it belongs to one.
		if ($contract) {
			$dir = $conf->contrat->dir_output.'/'.dol_sanitizeFileName($contract->ref);
			if (is_dir($dir) || dol_mkdir($dir) >= 0) {
				dol_copy($target, $dir.'/'.basename($target), '0', 1);
			}
		}
		return $target;
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
