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
 * Company documents and legal texts (Unterlagen) as the module's own object:
 * a working draft per document and released versions that never change.
 * Releasing writes the PDF into the Unterlagen folder of the ECM and keeps a
 * copy per version in its "Archiv" subfolder, so the version a customer got
 * can always be shown.
 */
class WerkbankDoc
{
	/** Kinds: legal text (may be public), internal document, template per customer. */
	const KINDS = array('legal', 'internal', 'customer');

	/** @var DoliDB */
	private $db;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	// ------------------------------------------------------------ reading

	/**
	 * @param array<string,mixed> $filter kind, public (1), released (1)
	 * @return array<int,object> Documents of the current entity
	 */
	public function all(array $filter = array())
	{
		global $conf;
		$where = array("entity = ".((int) $conf->entity));
		if (!empty($filter['kind'])) {
			$where[] = "kind = '".$this->db->escape((string) $filter['kind'])."'";
		}
		if (!empty($filter['public'])) {
			$where[] = "public = 1";
		}
		if (!empty($filter['released'])) {
			$where[] = "version > 0";
		}
		return $this->rows("SELECT * FROM ".MAIN_DB_PREFIX."ittabpdf_doc WHERE ".implode(' AND ', $where)." ORDER BY position, title");
	}

	/**
	 * @param int $id Document
	 * @return object|null
	 */
	public function get($id)
	{
		global $conf;
		$rows = $this->rows("SELECT * FROM ".MAIN_DB_PREFIX."ittabpdf_doc WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
		return $rows ? $rows[0] : null;
	}

	/**
	 * @param string $slug File name without .pdf
	 * @return object|null
	 */
	public function bySlug($slug)
	{
		global $conf;
		$rows = $this->rows("SELECT * FROM ".MAIN_DB_PREFIX."ittabpdf_doc WHERE slug = '".$this->db->escape((string) $slug)."' AND entity = ".((int) $conf->entity));
		return $rows ? $rows[0] : null;
	}

	/**
	 * Released versions, newest first.
	 *
	 * @param int $id Document
	 * @return array<int,object>
	 */
	public function versions($id)
	{
		return $this->rows("SELECT v.*, u.firstname, u.lastname, u.login FROM ".MAIN_DB_PREFIX."ittabpdf_docversion AS v"
			." LEFT JOIN ".MAIN_DB_PREFIX."user AS u ON u.rowid = v.fk_user WHERE v.fk_doc = ".((int) $id)." ORDER BY v.version DESC");
	}

	/**
	 * One released version (0 = the latest).
	 *
	 * @param int $id      Document
	 * @param int $version Version number
	 * @return object|null
	 */
	public function version($id, $version = 0)
	{
		$rows = $this->rows("SELECT * FROM ".MAIN_DB_PREFIX."ittabpdf_docversion WHERE fk_doc = ".((int) $id)
			.($version > 0 ? " AND version = ".((int) $version) : "")." ORDER BY version DESC");
		return $rows ? $rows[0] : null;
	}

	/**
	 * The latest released text of a document, in the shape the PDF builders
	 * take (question, answer, array_options with the file name, tms).
	 *
	 * @param object      $doc     Document
	 * @param object|null $version Version, null = latest
	 * @return object|null
	 */
	public function asRecord($doc, $version = null)
	{
		$version = $version ?: $this->version((int) $doc->rowid);
		if (!$version) {
			return null;
		}
		$record = new stdClass();
		$record->id = (int) $doc->rowid;
		$record->element = 'ittabpdf_doc';
		$record->table_element = 'ittabpdf_doc';
		$record->entity = (int) $doc->entity;
		$record->ref = (string) $doc->slug;
		$record->question = (string) $version->title;
		$record->answer = (string) $version->text;
		$record->status = 1;
		$record->tms = $this->db->jdate($version->date_release);
		$record->array_options = array('options_ittab_slug' => (string) $doc->slug);
		return $record;
	}

	// ------------------------------------------------------------ writing

	/**
	 * Create ($id 0) or change the draft of a document.
	 *
	 * @param int                 $id     Document or 0
	 * @param array<string,mixed> $values slug, title, kind, public, text, position
	 * @param User                $user   User
	 * @return int                        Id, -1 on error
	 */
	public function save($id, array $values, $user)
	{
		global $conf;
		$title = trim((string) $values['title']);
		$slug = WerkbankUnterlagen::slugify((string) (trim((string) $values['slug']) !== '' ? $values['slug'] : $title));
		if ($title === '' || $slug === '') {
			$this->error = 'ErrorFieldRequired';
			return -1;
		}
		$other = $this->bySlug($slug);
		if ($other && (int) $other->rowid !== (int) $id) {
			$this->error = 'WerkbankDocSlugTaken';
			return -1;
		}
		$kind = in_array($values['kind'], self::KINDS, true) ? $values['kind'] : 'internal';
		$fields = array(
			'slug' => "'".$this->db->escape($slug)."'",
			'title' => "'".$this->db->escape(dol_trunc($title, 255, 'right', 'UTF-8', 1))."'",
			'kind' => "'".$this->db->escape($kind)."'",
			// Only legal texts go to the website.
			'public' => $kind === 'legal' && !empty($values['public']) ? 1 : 0,
			'text' => "'".$this->db->escape((string) $values['text'])."'",
			'changed' => 1,
			'position' => (int) (isset($values['position']) ? $values['position'] : 0),
			'fk_user_modif' => (int) $user->id,
		);
		if ($id > 0) {
			$current = $this->get($id);
			if (!$current) {
				$this->error = 'WerkbankDocUnknown';
				return -1;
			}
			// Unchanged text and title after a release: still released as it is.
			$version = $this->version($id);
			if ($version && (string) $version->text === (string) $values['text'] && (string) $version->title === $title) {
				$fields['changed'] = 0;
			}
			$set = array();
			foreach ($fields as $column => $literal) {
				$set[] = $column." = ".$literal;
			}
			return $this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_doc SET ".implode(', ', $set)." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity))
				? (int) $id : $this->fail();
		}
		$fields = array('entity' => (int) $conf->entity, 'date_creation' => "'".$this->db->idate(dol_now())."'",
			'fk_km' => !empty($values['fk_km']) ? (int) $values['fk_km'] : 'NULL') + $fields;
		return $this->db->query("INSERT INTO ".MAIN_DB_PREFIX."ittabpdf_doc (".implode(', ', array_keys($fields)).") VALUES (".implode(', ', $fields).")")
			? (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'ittabpdf_doc') : $this->fail();
	}

	/**
	 * Release the draft as a new version: it never changes again. Legal texts
	 * and internal documents get their PDF in the Unterlagen folder and an
	 * archived copy; templates per customer only get the version.
	 *
	 * @param int      $id   Document
	 * @param string   $note What changed
	 * @param User     $user User
	 * @param int|null $date Date of the release ("Stand"), null = now
	 * @return int           Version number, -1 on error
	 */
	public function release($id, $note, $user, $date = null)
	{
		$doc = $this->get($id);
		if (!$doc) {
			$this->error = 'WerkbankDocUnknown';
			return -1;
		}
		if (trim(strip_tags((string) $doc->text)) === '') {
			$this->error = 'WerkbankDocEmpty';
			return -1;
		}
		$number = (int) $doc->version + 1;
		$date = $date ?: dol_now();
		$this->db->begin();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."ittabpdf_docversion (fk_doc, version, title, text, date_release, note, fk_user) VALUES ("
			.((int) $doc->rowid).", ".$number.", '".$this->db->escape((string) $doc->title)."', '".$this->db->escape((string) $doc->text)."',"
			." '".$this->db->idate($date)."', '".$this->db->escape(dol_trunc(trim((string) $note), 255, 'right', 'UTF-8', 1))."', ".((int) $user->id).")";
		if (!$this->db->query($sql)
			|| !$this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_doc SET version = ".$number.", changed = 0, date_release = '".$this->db->idate($date)."'"
				." WHERE rowid = ".((int) $doc->rowid))) {
			$this->db->rollback();
			return $this->fail();
		}
		$this->db->commit();
		if ($doc->kind !== 'customer') {
			$doc = $this->get($id);
			if ($this->writePdf($doc, $user) === '') {
				return -1;
			}
		}
		return $number;
	}

	/**
	 * PDF of the latest version into the Unterlagen folder (<slug>.pdf) and
	 * the archive (Archiv/<slug>-v<n>.pdf). Placeholders of the company and
	 * the prices are filled; with prices the "Stand" is today.
	 *
	 * @param object $doc     Document
	 * @param User   $user    User
	 * @param bool   $archive Also the archive copy (not when only prices changed)
	 * @return string         Path in the folder, '' on error
	 */
	public function writePdf($doc, $user, $archive = true)
	{
		$record = $this->asRecord($doc);
		if (!$record) {
			$this->error = 'WerkbankDocNotReleased';
			return '';
		}
		$stand = (int) $record->tms;
		// Placeholders of the company, the date and the prices (a document for
		// a customer is filled when it is generated for one).
		if ($doc->kind !== 'customer' && strpos((string) $record->answer, '__') !== false) {
			require_once __DIR__.'/werkbankvars.class.php';
			require_once __DIR__.'/werkbankpricelist.class.php';
			if (WerkbankPricelist::usesPrices($record->answer)) {
				$stand = dol_now();
			}
			$record->answer = WerkbankVars::fill((string) $record->answer, WerkbankVars::values($this->db, null));
		}
		$unterlagen = new WerkbankUnterlagen($this->db);
		$path = $unterlagen->generate($record, $user, $stand);
		if ($path === '') {
			$this->error = $unterlagen->error;
			return '';
		}
		// A file name changed since the last release: the old file goes, no duplicate stays.
		$unterlagen->removeOldFiles('ittabpdf_doc', (int) $doc->rowid, basename($path));
		if (!empty($doc->fk_km)) {
			$unterlagen->removeOldFiles('knowledgemanagement_knowledgerecord', (int) $doc->fk_km, basename($path));
		}
		if (!$archive) {
			return $path;
		}
		$archive = WerkbankUnterlagen::dir().'/Archiv';
		if ($unterlagen->ensureEcmDirectory($user, WerkbankUnterlagen::relativeDir().'/Archiv') > 0) {
			$name = $doc->slug.'-v'.((int) $doc->version).'.pdf';
			dol_copy($path, $archive.'/'.$name, '0', 1);
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_docversion SET filename = '".$this->db->escape($name)."'"
				." WHERE fk_doc = ".((int) $doc->rowid)." AND version = ".((int) $doc->version));
		}
		return $path;
	}

	// ------------------------------------------------------------ from the knowledge base

	/**
	 * Take over the articles of the knowledge base categories chosen for
	 * company documents (default "Rechtstexte", "Unterlagen") that are not
	 * here yet. Released articles become version 1 with the article's last
	 * change as "Stand"; drafts stay drafts. The articles are not touched.
	 *
	 * @param User $user User
	 * @return int       Documents taken over, -1 on error
	 */
	public function importKnowledge($user)
	{
		if (!isModEnabled('knowledgemanagement')) {
			return 0;
		}
		$unterlagen = new WerkbankUnterlagen($this->db);
		$categories = $unterlagen->categories();
		$taken = 0;
		foreach ($unterlagen->knowledgeArticles() as $article) {
			if ($this->rows("SELECT rowid FROM ".MAIN_DB_PREFIX."ittabpdf_doc WHERE fk_km = ".((int) $article['id']))) {
				continue;
			}
			$legal = in_array('Rechtstexte', array_map(function ($id) use ($categories) {
				return isset($categories[$id]) ? $categories[$id] : '';
			}, $article['categories']), true);
			$slug = $article['slug'];
			// A slug already used by a document made here: keep both, the article gets a suffix.
			if ($this->bySlug($slug)) {
				$slug = WerkbankUnterlagen::slugify($slug.'-'.$article['ref']);
			}
			$id = $this->save(0, array('slug' => $slug, 'title' => $article['title'], 'kind' => $legal ? 'legal' : 'internal', 'public' => $legal ? 1 : 0,
				'text' => $article['text'], 'position' => 0, 'fk_km' => $article['id']), $user);
			if ($id < 0) {
				return -1;
			}
			if ($article['status'] === 1 && $this->release($id, 'Aus dem Wissensmanagement übernommen ('.$article['ref'].')', $user, $article['changed'] ?: null) < 0) {
				return -1;
			}
			// PDFs the module wrote for this article under another name (no file name set back then).
			// Drafts keep whatever file exists: it may still be the one in use.
			if ($article['status'] === 1) {
				$unterlagen->removeOldFiles('knowledgemanagement_knowledgerecord', (int) $article['id'], $slug.'.pdf');
			}
			$taken++;
		}
		return $taken;
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
