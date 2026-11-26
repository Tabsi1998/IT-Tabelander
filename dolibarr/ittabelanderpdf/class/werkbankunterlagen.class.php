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

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Company documents (Unterlagen): knowledge base articles of chosen
 * categories as Werkbank PDFs in one ECM folder, <slug>.pdf, ready to be
 * attached to e-mails or appended to a proposal and downloaded by the website.
 */
class WerkbankUnterlagen
{
	/** Default ECM folder below documents/ecm. */
	const DEFAULT_DIR = 'IT-Tabelander/Unterlagen';

	/** Categories that get a PDF when ITTABPDF_UNTERLAGEN_CATEGORIES is empty, by label. */
	const DEFAULT_CATEGORIES = array('Rechtstexte', 'Unterlagen');

	/** Extra field of the article that holds the file name. */
	const SLUG_FIELD = 'ittab_slug';

	/**
	 * Mail form contexts (FormMail param "models") with their setup label key.
	 */
	const MAIL_CONTEXTS = array(
		'propal_send' => 'WerkbankDocsCtxPropal',
		'order_send' => 'WerkbankDocsCtxOrder',
		'facture_send' => 'WerkbankDocsCtxInvoice',
		'contract' => 'WerkbankDocsCtxContract',
		'fichinter_send' => 'WerkbankDocsCtxFichinter',
		'shipping_send' => 'WerkbankDocsCtxShipping',
		'thirdparty' => 'WerkbankDocsCtxThirdparty',
	);

	/** Document types whose Werkbank PDF can carry a company document behind it. */
	const APPEND_TYPES = array('propal' => 'PROPAL', 'commande' => 'ORDER', 'facture' => 'INVOICE');

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

	// ------------------------------------------------------------ settings

	/**
	 * The ECM folder, relative to documents/ecm, without slashes at the ends.
	 *
	 * @return string
	 */
	public static function relativeDir()
	{
		$dir = trim(str_replace('\\', '/', getDolGlobalString('ITTABPDF_UNTERLAGEN_DIR', self::DEFAULT_DIR)), '/');
		// No way out of the ECM root.
		if ($dir === '' || preg_match('#(^|/)\.\.?(/|$)#', $dir)) {
			$dir = self::DEFAULT_DIR;
		}
		return $dir;
	}

	/**
	 * @return string Full path of the folder on disk
	 */
	public static function dir()
	{
		global $conf;
		$root = !empty($conf->ecm->dir_output) ? $conf->ecm->dir_output : DOL_DATA_ROOT.'/ecm';
		return $root.'/'.self::relativeDir();
	}

	/**
	 * Category ids whose articles get a PDF: the setting, else the
	 * knowledge base categories named like DEFAULT_CATEGORIES.
	 *
	 * @return int[]
	 */
	public function categoryIds()
	{
		$saved = getDolGlobalString('ITTABPDF_UNTERLAGEN_CATEGORIES');
		if ($saved !== '') {
			return array_values(array_filter(array_map('intval', explode(',', $saved))));
		}
		$ids = array();
		foreach ($this->categories() as $id => $label) {
			if (in_array($label, self::DEFAULT_CATEGORIES, true)) {
				$ids[] = (int) $id;
			}
		}
		return $ids;
	}

	/**
	 * All knowledge base categories, id => label.
	 *
	 * @return array<int,string>
	 */
	public function categories()
	{
		$list = array();
		// 13 = Categorie::TYPE_KNOWLEDGEMANAGEMENT in llx_categorie.type
		$sql = "SELECT rowid, label FROM ".MAIN_DB_PREFIX."categorie WHERE type = 13 AND entity IN (".getEntity('category').")"
			." ORDER BY label";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$list[(int) $obj->rowid] = (string) $obj->label;
		}
		return $list;
	}

	// ------------------------------------------------------------ articles

	/**
	 * A file name from a title: lower case, umlauts spelt out, hyphens.
	 *
	 * @param string $text Title
	 * @return string
	 */
	public static function slugify($text)
	{
		$text = strtr(dol_strtolower(trim((string) $text)), array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'));
		$text = dol_string_unaccent($text);
		$text = preg_replace('/[^a-z0-9]+/', '-', $text);
		return substr(trim((string) $text, '-'), 0, 64);
	}

	/**
	 * The article's file name without ".pdf": the extra field when it is a
	 * valid name, else made from the title.
	 *
	 * @param KnowledgeRecord $record Article
	 * @return string
	 */
	public static function slugOf($record)
	{
		$own = isset($record->array_options['options_'.self::SLUG_FIELD]) ? self::slugify($record->array_options['options_'.self::SLUG_FIELD]) : '';
		return $own !== '' ? $own : self::slugify((string) $record->question);
	}

	/**
	 * @param KnowledgeRecord $record Article
	 * @return bool                   It is in one of the chosen categories
	 */
	public function inCategories($record)
	{
		$wanted = isModEnabled('knowledgemanagement') ? $this->categoryIds() : array();
		if (!$wanted || empty($record->id)) {
			return false;
		}
		$sql = "SELECT COUNT(*) AS found FROM ".MAIN_DB_PREFIX."categorie_knowledgemanagement"
			." WHERE fk_knowledgemanagement = ".((int) $record->id)." AND fk_categorie IN (".implode(',', array_map('intval', $wanted)).")";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj && (int) $obj->found > 0;
	}

	/**
	 * Only released articles are published.
	 *
	 * @param KnowledgeRecord $record Article
	 * @return bool
	 */
	public static function isReleased($record)
	{
		return (int) $record->status === 1;
	}

	/**
	 * The slug of another article that already uses it, or 0.
	 *
	 * @param KnowledgeRecord $record Article
	 * @param string          $slug   File name
	 * @return int                    Id of the other article
	 */
	public function slugTakenBy($record, $slug)
	{
		foreach ($this->articles() as $article) {
			if ((int) $article['id'] !== (int) $record->id && $article['slug'] === $slug) {
				return (int) $article['id'];
			}
		}
		return 0;
	}

	/**
	 * Released articles of the chosen categories with their file name.
	 *
	 * @return array<int,array<string,mixed>> id, ref, title, slug, status
	 */
	public function articles()
	{
		$wanted = isModEnabled('knowledgemanagement') ? $this->categoryIds() : array();
		if (!$wanted) {
			return array();
		}
		$sql = "SELECT DISTINCT k.rowid, k.ref, k.question, k.status, e.".self::SLUG_FIELD." AS slug"
			." FROM ".MAIN_DB_PREFIX."knowledgemanagement_knowledgerecord AS k"
			." INNER JOIN ".MAIN_DB_PREFIX."categorie_knowledgemanagement AS c ON c.fk_knowledgemanagement = k.rowid"
			." LEFT JOIN ".MAIN_DB_PREFIX."knowledgemanagement_knowledgerecord_extrafields AS e ON e.fk_object = k.rowid"
			." WHERE c.fk_categorie IN (".implode(',', array_map('intval', $wanted)).")"
			." AND k.entity IN (".getEntity('knowledgemanagement').") ORDER BY k.question";
		$resql = $this->db->query($sql);
		$list = array();
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$slug = self::slugify((string) $obj->slug);
			$list[] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'title' => (string) $obj->question,
				'slug' => $slug !== '' ? $slug : self::slugify((string) $obj->question), 'status' => (int) $obj->status);
		}
		return $list;
	}

	// ------------------------------------------------------------ files

	/**
	 * The PDFs in the folder, file name => label (the article title when an
	 * article of the chosen categories has that name, else the file name).
	 *
	 * @return array<string,string>
	 */
	public function files()
	{
		$titles = array();
		foreach ($this->articles() as $article) {
			$titles[$article['slug'].'.pdf'] = $article['title'];
		}
		$list = array();
		foreach (dol_dir_list(self::dir(), 'files', 0, '\.pdf$', '', 'name', SORT_ASC) as $file) {
			$list[$file['name']] = isset($titles[$file['name']]) ? $titles[$file['name']] : $file['name'];
		}
		asort($list, SORT_NATURAL | SORT_FLAG_CASE);
		return $list;
	}

	/**
	 * Full path of one PDF of the folder, '' when the name is not one of them.
	 *
	 * @param string $name File name as listed by files()
	 * @return string
	 */
	public function path($name)
	{
		$name = basename((string) $name);
		$files = $this->files();
		return isset($files[$name]) ? self::dir().'/'.$name : '';
	}

	/**
	 * Settings that list file names (comma separated), as full paths of the
	 * files that exist.
	 *
	 * @param string $const Constant
	 * @return string[]
	 */
	public function pathsFrom($const)
	{
		$paths = array();
		foreach (array_filter(array_map('trim', explode(',', getDolGlobalString($const)))) as $name) {
			$path = $this->path($name);
			if ($path !== '') {
				$paths[] = $path;
			}
		}
		return $paths;
	}

	/**
	 * PDFs to put behind a document's Werkbank PDF: the company document
	 * chosen in the module setup for its type (ITTABPDF_APPEND_PROPAL, ...),
	 * else Dolibarr's own terms of sale (Setup → PDF, MAIN_PDF_ADD_TERMSOFSALE_*),
	 * which pdf_cyan and friends append the same way.
	 *
	 * @param CommonObject $object Document
	 * @return string[]            Full paths
	 */
	public function appendFor($object)
	{
		global $conf;
		$element = isset($object->element) ? (string) $object->element : '';
		if (!isset(self::APPEND_TYPES[$element]) || !empty($object->specimen)) {
			return array();
		}
		$key = self::APPEND_TYPES[$element];
		$own = $this->pathsFrom('ITTABPDF_APPEND_'.$key);
		if ($own) {
			return $own;
		}
		$name = getDolGlobalString('MAIN_INFO_'.$key.'_TERMSOFSALE');
		if (!getDolGlobalInt('MAIN_PDF_ADD_TERMSOFSALE_'.$key) || $name === '') {
			return array();
		}
		$module = array('PROPAL' => 'propal', 'ORDER' => 'order', 'INVOICE' => 'invoice');
		$settings = $conf->{$module[$key]};
		$entity = isset($object->entity) ? (int) $object->entity : (int) $conf->entity;
		$dir = !empty($settings->multidir_output[$entity]) ? $settings->multidir_output[$entity] : $settings->dir_output;
		$path = $dir.'/'.basename($name);
		return is_readable($path) ? array($path) : array();
	}

	/**
	 * Write the article's PDF into the folder, replacing the old one, and
	 * index it in the ECM like an uploaded file.
	 *
	 * @param KnowledgeRecord $record Article (fetched, with extra fields)
	 * @param User            $user   User
	 * @param int|null        $stand  "Stand" date; null = last change of the article
	 * @return string                 Full path, '' on error ($this->error)
	 */
	public function generate($record, $user, $stand = null)
	{
		global $langs;
		dol_include_once('/ittabelanderpdf/class/werkbankbuilder.unterlage.class.php');
		dol_include_once('/ittabelanderpdf/class/werkbankrenderer.class.php');
		$langs->loadLangs(array('main', 'ittabelanderpdf@ittabelanderpdf'));

		$slug = self::slugOf($record);
		if ($slug === '') {
			$this->error = $langs->transnoentities('WerkbankDocsNoSlug');
			return '';
		}
		$other = $this->slugTakenBy($record, $slug);
		if ($other) {
			$this->error = $langs->transnoentities('WerkbankDocsSlugTaken', $slug.'.pdf');
			return '';
		}
		if ($this->ensureEcmDirectory($user) < 0) {
			return '';
		}
		$target = self::dir().'/'.$slug.'.pdf';
		// Render beside the target, then replace: a failed run keeps the old file.
		$temp = self::dir().'/.'.$slug.'.'.getmypid().'.tmp';
		try {
			$builder = new WerkbankBuilderUnterlage($this->db, $record, $langs, $stand);
			$renderer = new WerkbankRenderer(pdf_getInstance(array(WerkbankRenderer::PAGE_W, WerkbankRenderer::PAGE_H)), $langs);
			$renderer->render($builder->build(), $temp);
		} catch (Throwable $exception) {
			dol_syslog('ittabelanderpdf: '.$exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine(), LOG_ERR);
			@unlink($temp);
			$this->error = $exception->getMessage();
			return '';
		}
		if (!@rename($temp, $target)) {
			@unlink($temp);
			$this->error = $langs->transnoentities('ErrorFailToCreateFile', $target);
			return '';
		}
		dolChmod($target);
		$this->index($target, $record, $user);
		return $target;
	}

	/**
	 * The folder exists on disk and as ECM directories, so it shows in
	 * Documents (manual section) like a folder made by hand.
	 *
	 * @param User $user User
	 * @return int       >0 OK, <0 error
	 */
	public function ensureEcmDirectory($user)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmdirectory.class.php';
		$parent = 0;
		foreach (explode('/', self::relativeDir()) as $label) {
			$label = dol_sanitizeFileName($label);
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."ecm_directories WHERE label = '".$this->db->escape($label)."'"
				." AND entity = ".((int) $conf->entity)." AND ".($parent ? "fk_parent = ".((int) $parent) : "(fk_parent IS NULL OR fk_parent = 0)");
			$resql = $this->db->query($sql);
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			if ($obj) {
				$parent = (int) $obj->rowid;
				continue;
			}
			$directory = new EcmDirectory($this->db);
			$directory->label = $label;
			$directory->fk_parent = $parent;
			$directory->description = '';
			$id = $directory->create($user);
			if ($id < 0) {
				$this->error = $directory->error;
				return -1;
			}
			$parent = (int) $id;
		}
		if (!is_dir(self::dir()) && dol_mkdir(self::dir()) < 0) {
			$this->error = 'ErrorFailedToCreateDir';
			return -1;
		}
		return 1;
	}

	/**
	 * One row in llx_ecm_files per file, refreshed on every generation.
	 *
	 * @param string          $path   Full path
	 * @param KnowledgeRecord $record Source article
	 * @param User            $user   User
	 * @return void
	 */
	private function index($path, $record, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
		$relative = preg_replace('/^'.preg_quote(DOL_DATA_ROOT.'/', '/').'/', '', $path);
		$file = new EcmFiles($this->db);
		if ($file->fetch(0, '', $relative) > 0) {
			$file->label = md5_file($path);
			$file->src_object_type = $record->table_element;
			$file->src_object_id = (int) $record->id;
			$file->update($user);
			return;
		}
		addFileIntoDatabaseIndex(dirname($path), basename($path), '', 'generated', 0, $record);
	}
}
