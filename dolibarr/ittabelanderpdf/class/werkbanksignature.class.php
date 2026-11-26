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
require_once __DIR__.'/werkbankqs.class.php';

/**
 * Signatures on the device for the test report (kind "qs", a QS run) and the
 * device intake slip (kind "intake", a ticket): the image in the object's
 * documents (folder signatures/), name and time in llx_ittabpdf_signature,
 * the PDF written again with them, a copy with the third party, and the
 * e-mail to the customer after the user confirmed the address.
 */
class WerkbankSignature
{
	/** Largest PNG accepted (bytes, decoded). */
	const MAX_BYTES = 600000;

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

	// ------------------------------------------------------------ documents

	/**
	 * The object a signature belongs to, loaded: for "qs" the run with its
	 * intervention, for "intake" the ticket.
	 *
	 * @param string $kind qs or intake
	 * @param int    $id   Run or ticket id
	 * @return array{object:CommonObject,run:?object,socid:int,ref:string}|null
	 */
	public function load($kind, $id)
	{
		if ($kind === 'qs') {
			require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
			$qs = new WerkbankQs($this->db);
			$run = $qs->run($id);
			if (!$run) {
				return null;
			}
			$fichinter = new Fichinter($this->db);
			if ($fichinter->fetch((int) $run->fk_fichinter) <= 0) {
				return null;
			}
			$fichinter->fetch_thirdparty();
			return array('object' => $fichinter, 'run' => $run, 'socid' => (int) $fichinter->socid, 'ref' => (string) $fichinter->ref);
		}
		if ($kind === 'doc') {
			// A company document a customer signs or confirms: the "signing" event of WerkbankDocs.
			require_once __DIR__.'/werkbankdocs.class.php';
			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			$docs = new WerkbankDocs($this->db);
			$event = $docs->event($id);
			$rule = $event ? $docs->rule((int) $event->fk_rule) : null;
			if (!$event || !$rule || !in_array($event->state, array('signing', 'signed'), true)) {
				return null;
			}
			$soc = new Societe($this->db);
			if ($soc->fetch((int) $event->fk_soc) <= 0) {
				return null;
			}
			$soc->thirdparty = $soc;
			return array('object' => $soc, 'run' => $event, 'rule' => $rule, 'socid' => (int) $soc->id, 'ref' => (string) $rule->label);
		}
		if ($kind === 'intake') {
			require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
			$ticket = new Ticket($this->db);
			if ($ticket->fetch($id) <= 0) {
				return null;
			}
			if (!empty($ticket->fk_soc)) {
				$ticket->fetch_thirdparty();
			}
			return array('object' => $ticket, 'run' => null, 'socid' => (int) $ticket->fk_soc, 'ref' => (string) $ticket->ref);
		}
		return null;
	}

	/**
	 * Roles that sign this document: the kind of job decides for a QS, the
	 * intake slip always has both.
	 *
	 * @param string $kind   qs or intake
	 * @param array  $loaded From load()
	 * @return string[]
	 */
	public static function roles($kind, array $loaded)
	{
		if ($kind === 'qs') {
			return array_values(array_intersect(WerkbankQs::SIGNERS, explode(',', (string) $loaded['run']->signers)));
		}
		if ($kind === 'doc') {
			// A contract (AVV, remote maintenance) both sign; accepting or receiving only the customer.
			return $loaded['rule']->action === 'sign' ? WerkbankQs::SIGNERS : array('customer');
		}
		return WerkbankQs::SIGNERS;
	}

	/**
	 * The PDF this signature goes into.
	 *
	 * @param string $kind   qs or intake
	 * @param array  $loaded From load()
	 * @return string
	 */
	public static function pdfPath($kind, array $loaded)
	{
		if ($kind === 'qs') {
			return WerkbankQs::pdfPath($loaded['object'], $loaded['run']);
		}
		if ($kind === 'doc') {
			return self::thirdpartyDir((int) $loaded['socid']).'/'.$loaded['rule']->slug.'-unterschrieben-'.((int) $loaded['run']->rowid).'.pdf';
		}
		require_once __DIR__.'/werkbankbuilder.intake.class.php';
		return WerkbankBuilderIntake::pathFor($loaded['object']);
	}

	/**
	 * Relative path for document.php (modulepart, file).
	 *
	 * @param string $kind qs or intake
	 * @param string $path Full path below the module's documents
	 * @return array{0:string,1:string}
	 */
	public static function documentLink($kind, $path)
	{
		global $conf;
		$modulepart = $kind === 'qs' ? 'ficheinter' : ($kind === 'doc' ? 'societe' : 'ticket');
		$root = $kind === 'qs' ? $conf->ficheinter->dir_output : ($kind === 'doc' ? self::thirdpartyDir(0) : $conf->ticket->dir_output);
		return array($modulepart, ltrim(substr($path, strlen($root)), '/'));
	}

	/**
	 * Documents folder of the third parties, or of one.
	 *
	 * @param int $socid Third party, 0 = the root
	 * @return string
	 */
	public static function thirdpartyDir($socid)
	{
		global $conf;
		$root = !empty($conf->societe->multidir_output[$conf->entity]) ? $conf->societe->multidir_output[$conf->entity] : $conf->societe->dir_output;
		return $socid > 0 ? $root.'/'.((int) $socid) : $root;
	}

	/**
	 * @param string $kind   qs or intake
	 * @param array  $loaded From load()
	 * @return string Folder of the signature images
	 */
	private static function folder($kind, array $loaded)
	{
		return dirname(self::pdfPath($kind, $loaded)).'/signatures';
	}

	// ------------------------------------------------------------ signatures

	/**
	 * Saved signatures, role => row (name, date_sign, filename, path).
	 *
	 * @param string $kind   qs or intake
	 * @param int    $id     Run or ticket id
	 * @param array  $loaded From load()
	 * @return array<string,object>
	 */
	public function signatures($kind, $id, array $loaded)
	{
		global $conf;
		$list = array();
		$resql = $this->db->query("SELECT role, name, date_sign, filename FROM ".MAIN_DB_PREFIX."ittabpdf_signature"
			." WHERE kind = '".$this->db->escape($kind)."' AND fk_object = ".((int) $id)." AND entity = ".((int) $conf->entity));
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$obj->path = self::folder($kind, $loaded).'/'.$obj->filename;
			if (is_readable($obj->path)) {
				$list[$obj->role] = $obj;
			}
		}
		return $list;
	}

	/**
	 * Signatures in the form the PDF builders take: role => image, name, when.
	 *
	 * @param string $kind   qs or intake
	 * @param int    $id     Run or ticket id
	 * @param array  $loaded From load()
	 * @return array<string,array<string,string>>
	 */
	public function forPdf($kind, $id, array $loaded)
	{
		global $langs;
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		$signed = array();
		foreach ($this->signatures($kind, $id, $loaded) as $role => $row) {
			$signed[$role] = array('image' => $row->path, 'name' => (string) $row->name,
				'when' => $langs->transnoentities('WerkbankSignedOn', dol_print_date($this->db->jdate($row->date_sign), 'dayhour', 'tzuser')));
		}
		return $signed;
	}

	/**
	 * Store one signature and write the PDF again with it.
	 *
	 * The image comes as base64 without the "data:" prefix and without "="
	 * padding: Dolibarr's WAF refuses "=data:" and "on…=" in any POST.
	 *
	 * @param string $kind   qs or intake
	 * @param int    $id     Run or ticket id
	 * @param string $role   technician or customer
	 * @param string $name   Name in block letters
	 * @param string $base64 PNG as base64
	 * @param User   $user   User holding the device
	 * @return int           1 saved, -1 error ($this->error is a translation key or text)
	 */
	public function save($kind, $id, $role, $name, $base64, $user)
	{
		global $conf;
		$loaded = $this->load($kind, $id);
		if (!$loaded) {
			$this->error = 'WerkbankSignUnknown';
			return -1;
		}
		if (!in_array($role, self::roles($kind, $loaded), true)) {
			$this->error = 'WerkbankSignUnknownRole';
			return -1;
		}
		if ($kind === 'qs' && (int) $loaded['run']->status !== 1) {
			$this->error = 'WerkbankSignCloseFirst';
			return -1;
		}
		$name = dol_trunc(trim(dol_string_nohtmltag((string) $name)), 128, 'right', 'UTF-8', 1);
		if ($name === '') {
			$this->error = 'WerkbankSignNameMissing';
			return -1;
		}
		$png = self::decode($base64);
		if ($png === '') {
			$this->error = 'WerkbankSignImageInvalid';
			return -1;
		}
		$folder = self::folder($kind, $loaded);
		if (!is_dir($folder) && dol_mkdir($folder) < 0) {
			$this->error = 'ErrorCanNotCreateDir';
			return -1;
		}
		$file = $kind.'-'.((int) $id).'-'.$role.'.png';
		if (file_put_contents($folder.'/'.$file, $png) === false) {
			$this->error = 'ErrorFailToCreateFile';
			return -1;
		}
		dolChmod($folder.'/'.$file);
		$this->db->begin();
		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_signature WHERE kind = '".$this->db->escape($kind)."' AND fk_object = ".((int) $id)
			." AND role = '".$this->db->escape($role)."' AND entity = ".((int) $conf->entity));
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."ittabpdf_signature (entity, kind, fk_object, role, name, date_sign, filename, ip, fk_user)"
			." VALUES (".((int) $conf->entity).", '".$this->db->escape($kind)."', ".((int) $id).", '".$this->db->escape($role)."', '".$this->db->escape($name)."',"
			." '".$this->db->idate(dol_now())."', '".$this->db->escape($file)."', '".$this->db->escape(substr(getUserRemoteIP(), 0, 64))."', ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->db->commit();
		return $this->write($kind, $id, $loaded) === '' ? -1 : 1;
	}

	/**
	 * A PNG from base64 (padding optional), '' when it is not a sane image.
	 *
	 * @param string $base64 Encoded image
	 * @return string        Bytes
	 */
	public static function decode($base64)
	{
		$base64 = preg_replace('/\s+/', '', (string) $base64);
		if ($base64 === '' || !preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $base64) || strlen($base64) > ceil(self::MAX_BYTES * 4 / 3) + 4) {
			return '';
		}
		$base64 = rtrim($base64, '=');
		$base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
		$png = base64_decode($base64, true);
		if ($png === false || strncmp($png, "\x89PNG\r\n\x1a\n", 8) !== 0) {
			return '';
		}
		$size = @getimagesizefromstring($png);
		if (!$size || $size[2] !== IMAGETYPE_PNG || $size[0] < 20 || $size[1] < 10 || $size[0] > 4000 || $size[1] > 4000) {
			return '';
		}
		return $png;
	}

	/**
	 * The PDF again with the signatures given so far, and its copy in the
	 * third party's documents.
	 *
	 * @param string $kind   qs or intake
	 * @param int    $id     Run or ticket id
	 * @param array  $loaded From load()
	 * @return string        Path, '' on error
	 */
	public function write($kind, $id, array $loaded)
	{
		$signed = $this->forPdf($kind, $id, $loaded);
		if ($kind === 'qs') {
			$qs = new WerkbankQs($this->db);
			$path = $qs->writePdf($loaded['run'], $loaded['object'], $signed);
			$this->error = $qs->error;
		} elseif ($kind === 'doc') {
			$path = $this->writeDoc($loaded, $signed);
			// Complete: the customer's document counts as signed.
			if ($path !== '' && count(array_intersect(self::roles($kind, $loaded), array_keys($signed))) === count(self::roles($kind, $loaded))) {
				require_once __DIR__.'/werkbankdocs.class.php';
				$docs = new WerkbankDocs($this->db);
				$docs->markSigned((int) $loaded['run']->rowid, basename($path));
			}
			return $path;
		} else {
			require_once __DIR__.'/werkbankintake.class.php';
			$intake = new WerkbankIntake($this->db);
			$path = $intake->write($loaded['object'], $signed);
			$this->error = $intake->error;
		}
		if ($path !== '') {
			self::copyToThirdparty($path, $loaded['socid']);
		}
		return $path;
	}

	/**
	 * The confirmation sheet with the signatures, the document itself behind it.
	 *
	 * @param array                              $loaded From load()
	 * @param array<string,array<string,string>> $signed role => image, name, when
	 * @return string                                    Path, '' on error
	 */
	private function writeDoc(array $loaded, array $signed)
	{
		global $langs;
		require_once __DIR__.'/werkbankbuilder.docsign.class.php';
		require_once __DIR__.'/werkbankrenderer.class.php';
		$langs->loadLangs(array('main', 'companies', 'ittabelanderpdf@ittabelanderpdf'));
		$target = self::pdfPath('doc', $loaded);
		if (!is_dir(dirname($target)) && dol_mkdir(dirname($target)) < 0) {
			$this->error = 'ErrorCanNotCreateDir';
			return '';
		}
		try {
			$builder = new WerkbankBuilderDocSign($this->db, $loaded['object'], $langs, $loaded['rule'], $loaded['run'], $signed);
			$renderer = new WerkbankRenderer(pdf_getInstance(array(WerkbankRenderer::PAGE_W, WerkbankRenderer::PAGE_H)), $langs);
			$renderer->render($builder->build(), $target);
		} catch (Throwable $exception) {
			dol_syslog('ittabelanderpdf: '.$exception->getMessage(), LOG_ERR);
			$this->error = $exception->getMessage();
			return '';
		}
		dolChmod($target);
		return $target;
	}

	/**
	 * A copy in the third party's documents, so the customer's card shows it too.
	 *
	 * @param string $path  PDF
	 * @param int    $socid Third party, 0 = none
	 * @return void
	 */
	public static function copyToThirdparty($path, $socid)
	{
		global $conf;
		if ($socid <= 0 || !is_readable($path) || dirname($path) === self::thirdpartyDir($socid)) {
			return;
		}
		$dir = self::thirdpartyDir($socid);
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			return;
		}
		dol_copy($path, $dir.'/'.basename($path), '0', 1);
	}

	// ------------------------------------------------------------ sending

	/**
	 * Who gets the PDF: the contact of the document, else the third party,
	 * else (ticket from the website) the address the request came from.
	 *
	 * @param string $kind   qs or intake
	 * @param array  $loaded From load()
	 * @return string        "Name <mail>", '' when nobody
	 */
	public static function recipient($kind, array $loaded)
	{
		global $langs;
		dol_include_once('/ittabelanderpdf/class/werkbankmaildata.class.php');
		$object = $loaded['object'];
		$contact = getDolGlobalString('ITTABPDF_SIGN_RECIPIENT', 'contact') === 'contact' ? WerkbankMailData::contact($object) : null;
		if ($contact && isValidEmail($contact->email)) {
			return $contact->getFullName($langs).' <'.$contact->email.'>';
		}
		$thirdparty = isset($object->thirdparty) && is_object($object->thirdparty) ? $object->thirdparty : null;
		if ($thirdparty && isValidEmail($thirdparty->email)) {
			return $thirdparty->name.' <'.$thirdparty->email.'>';
		}
		if ($kind === 'intake' && isValidEmail((string) $object->origin_email)) {
			return (string) $object->origin_email;
		}
		return '';
	}

	/**
	 * Send the signed PDF with the module's template (Prüfprotokoll or
	 * Geräteannahme, Sie/Du), BCC and agenda entry like every other send.
	 *
	 * @param string $kind qs or intake
	 * @param int    $id   Run or ticket id
	 * @param string $to   Address the user confirmed
	 * @param User   $user Sender
	 * @return int         1 sent, -1 error
	 */
	public function send($kind, $id, $to, $user)
	{
		dol_include_once('/ittabelanderpdf/class/werkbankmailqueue.class.php');
		$loaded = $this->load($kind, $id);
		if (!$loaded) {
			$this->error = 'WerkbankSignUnknown';
			return -1;
		}
		$path = self::pdfPath($kind, $loaded);
		if (!is_readable($path)) {
			$this->error = 'WerkbankSignNoPdf';
			return -1;
		}
		$queue = new WerkbankMailQueue($this->db);
		$keys = array('qs' => array('qsreport', 'FICHINTER_SENTBYMAIL'), 'intake' => array('intake', 'TICKET_SENTBYMAIL'), 'doc' => array('unterlage', 'COMPANY_SENTBYMAIL'));
		$result = $queue->send($loaded['object'], $keys[$kind][0], $user, false, $keys[$kind][1], '', array($path), trim((string) $to));
		if (!$result['ok']) {
			$this->error = $result['error'];
			return -1;
		}
		if ($kind === 'qs') {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_qs_run SET date_sent = '".$this->db->idate(dol_now())."',"
				." sent_to = '".$this->db->escape(dol_trunc($result['to'], 255, 'right', 'UTF-8', 1))."' WHERE rowid = ".((int) $id));
		}
		return 1;
	}
}
