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
 * The module's history in Dolibarr's agenda: company documents (generated,
 * sent by hand, signed, uploaded, ticked, added, removed, reset, new version
 * needed), QS test reports closed and intake slips signed become events of
 * the type "Unterlage" (AC_ITTAB_DOC) with the third party and, where there
 * is one, the object (contract, proposal, intervention, ticket). They show
 * in the tab "Ereignisse/Agenda" of both.
 *
 * Mails are not logged here: Dolibarr writes its own "sent by e-mail"
 * event. Every source row keeps the id of its event (fk_actioncomm), so a
 * row is logged once, also when the history is written afterwards
 * (backfill()). Setting ITTABPDF_AGENDA (default on).
 */
class WerkbankAgenda
{
	const TYPE = 'AC_ITTAB_DOC';

	/** Row id in the dictionary of event types (module number based, like the rights). */
	const TYPE_ID = 75623101;

	/** States of company documents that become an event, with their verb. */
	const STATES = array('generated' => 'WerkbankAgendaGenerated', 'sent' => 'WerkbankAgendaSent', 'signed' => 'WerkbankAgendaSigned',
		'uploaded' => 'WerkbankAgendaUploaded', 'checked' => 'WerkbankAgendaChecked', 'added' => 'WerkbankAgendaAdded', 'removed' => 'WerkbankAgendaRemoved',
		'revoked' => 'WerkbankAgendaRevoked', 'outdated' => 'WerkbankAgendaOutdated', 'reminded' => 'WerkbankAgendaReminded');

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

	/**
	 * @return bool The history goes into the agenda
	 */
	public static function enabled()
	{
		return isModEnabled('agenda') && getDolGlobalInt('ITTABPDF_AGENDA', 1);
	}

	/**
	 * The event type "Unterlage" in Dolibarr's dictionary, once.
	 *
	 * Without a module: CActionComm::liste_array() leaves out every type of
	 * kind "systemauto" that names a module, and the timeline of the third
	 * party (messaging.php, with AGENDA_USE_EVENT_TYPE) then shows the bare
	 * code "AC_ITTAB_DOC". 2.0.0 wrote 'ittabelanderpdf' there; it is cleared.
	 *
	 * @param DoliDB $db Database
	 * @return int       1 there, -1 error
	 */
	public static function ensureType($db)
	{
		$resql = $db->query("SELECT id, module FROM ".MAIN_DB_PREFIX."c_actioncomm WHERE code = '".self::TYPE."'");
		$row = $resql ? $db->fetch_object($resql) : null;
		if ($row) {
			if ((string) $row->module !== '') {
				$db->query("UPDATE ".MAIN_DB_PREFIX."c_actioncomm SET module = NULL WHERE code = '".self::TYPE."'");
			}
			return 1;
		}
		return $db->query("INSERT INTO ".MAIN_DB_PREFIX."c_actioncomm (id, code, type, libelle, module, active, position)"
			." VALUES (".self::TYPE_ID.", '".self::TYPE."', 'systemauto', 'Unterlage', NULL, 1, 60)") ? 1 : -1;
	}

	/**
	 * Log one row of the documents' history (ittabpdf_docevent).
	 *
	 * @param object    $event Row
	 * @param User|null $user  Who, else the row's user
	 * @return int             Event id, 0 when it is not logged, -1 error
	 */
	public function logDocEvent($event, $user = null)
	{
		global $langs;
		if (!self::enabled() || !$event || !empty($event->fk_actioncomm) || !isset(self::STATES[$event->state])) {
			return 0;
		}
		// Dolibarr logs the e-mail itself ("sent by e-mail"): no second event.
		if ($event->state === 'sent' && $event->source === 'mail') {
			return 0;
		}
		$langs->loadLangs(array('ittabelanderpdf@ittabelanderpdf'));
		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
		require_once __DIR__.'/werkbankdocs.class.php';
		$docs = new WerkbankDocs($this->db);
		$documents = $docs->documents();
		$title = isset($documents[$event->slug]) ? $documents[$event->slug]['title'] : $event->slug;
		// The date always as TT.MM.JJJJ: the REST API and the cron job may run with another language.
		$stand = (string) $event->version !== '' ? ' ('.$langs->transnoentities('WerkbankDocsStand', dol_print_date(dol_stringtotime($event->version.' 00:00:00'), '%d.%m.%Y')).')' : '';
		$label = $langs->transnoentities(self::STATES[$event->state]).': '.$title.$stand;
		// The object it belongs to; a document generated for a contract counts for the contract.
		list($element, $objectId) = $this->objectOf($docs, $event);
		$lines = array();
		$lines[] = $langs->transnoentities('WerkbankAgendaSource').': '.$langs->transnoentities('WerkbankDocsSource'.ucfirst((string) $event->source));
		if ((string) $event->filename !== '') {
			$lines[] = $langs->transnoentities('WerkbankAgendaFile').': '.$event->filename;
		}
		if ((string) $event->note !== '') {
			$lines[] = $langs->transnoentities('Note').': '.$event->note;
		}
		$id = $this->create(dol_trunc($label, 250, 'right', 'UTF-8', 1), implode("\n", $lines), $this->db->jdate($event->date_event), (int) $event->fk_soc,
			$element, $objectId, 'AC_ITTAB_DOC_'.strtoupper($event->state), $user ?: $this->userOf((int) $event->fk_user));
		if ($id > 0) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_docevent SET fk_actioncomm = ".((int) $id)." WHERE rowid = ".((int) $event->rowid));
		}
		return $id;
	}

	/**
	 * A QS run was closed with its test report.
	 *
	 * @param object    $run  Row of ittabpdf_qs_run
	 * @param User|null $user Who closed it
	 * @return int            Event id, 0 not logged, -1 error
	 */
	public function logQs($run, $user = null)
	{
		global $langs;
		if (!self::enabled() || !$run || !empty($run->fk_actioncomm) || (int) $run->status !== 1) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
		$langs->loadLangs(array('ittabelanderpdf@ittabelanderpdf'));
		$fichinter = new Fichinter($this->db);
		if ($fichinter->fetch((int) $run->fk_fichinter) <= 0) {
			return 0;
		}
		$label = $langs->transnoentities('WerkbankAgendaQsClosed', (string) $run->type_label, (string) $fichinter->ref);
		$note = (string) $run->filename !== '' ? $langs->transnoentities('WerkbankAgendaFile').': '.$run->filename : '';
		$id = $this->create($label, $note, $this->db->jdate($run->date_close), (int) $fichinter->socid, 'fichinter', (int) $fichinter->id, 'AC_ITTAB_QS_CLOSED',
			$user ?: $this->userOf((int) $run->fk_user_close));
		if ($id > 0) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_qs_run SET fk_actioncomm = ".((int) $id)." WHERE rowid = ".((int) $run->rowid));
		}
		return $id;
	}

	/**
	 * The intake slip of a ticket was signed by everyone (once per ticket).
	 *
	 * @param Ticket    $ticket Ticket
	 * @param int       $when   Time of the last signature
	 * @param User|null $user   Who
	 * @return int              Event id, 0 not logged, -1 error
	 */
	public function logIntake($ticket, $when, $user = null)
	{
		global $langs;
		if (!self::enabled() || !$ticket || $this->exists('AC_ITTAB_INTAKE_SIGNED', 'ticket', (int) $ticket->id)) {
			return 0;
		}
		$langs->loadLangs(array('ittabelanderpdf@ittabelanderpdf'));
		return $this->create($langs->transnoentities('WerkbankAgendaIntakeSigned', (string) $ticket->ref), '', $when, (int) $ticket->fk_soc, 'ticket', (int) $ticket->id,
			'AC_ITTAB_INTAKE_SIGNED', $user);
	}

	/**
	 * Write the history so far into the agenda (on activation, idempotent):
	 * document rows and closed QS runs without event, signed intake slips
	 * without event.
	 *
	 * @return int Events written
	 */
	public function backfill()
	{
		if (!self::enabled()) {
			return 0;
		}
		$count = 0;
		$resql = $this->db->query("SELECT * FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE fk_actioncomm IS NULL AND state IN ('".implode("','", array_keys(self::STATES))."') ORDER BY rowid");
		$rows = array();
		while ($resql && ($row = $this->db->fetch_object($resql))) {
			$rows[] = $row;
		}
		foreach ($rows as $row) {
			$count += $this->logDocEvent($row) > 0 ? 1 : 0;
		}
		$resql = $this->db->query("SELECT * FROM ".MAIN_DB_PREFIX."ittabpdf_qs_run WHERE fk_actioncomm IS NULL AND status = 1 ORDER BY rowid");
		$rows = array();
		while ($resql && ($row = $this->db->fetch_object($resql))) {
			$rows[] = $row;
		}
		foreach ($rows as $row) {
			$count += $this->logQs($row) > 0 ? 1 : 0;
		}
		// Intake slips: both signatures there.
		require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
		$resql = $this->db->query("SELECT fk_object, MAX(date_sign) AS last_sign, MAX(fk_user) AS fk_user FROM ".MAIN_DB_PREFIX."ittabpdf_signature"
			." WHERE kind = 'intake' GROUP BY fk_object HAVING COUNT(DISTINCT role) >= 2");
		$rows = array();
		while ($resql && ($row = $this->db->fetch_object($resql))) {
			$rows[] = $row;
		}
		foreach ($rows as $row) {
			$ticket = new Ticket($this->db);
			if ($ticket->fetch((int) $row->fk_object) > 0) {
				$count += $this->logIntake($ticket, $this->db->jdate($row->last_sign), $this->userOf((int) $row->fk_user)) > 0 ? 1 : 0;
			}
		}
		return $count;
	}

	/**
	 * Titles of 2.0.0 written with the date as MM/DD/YYYY ("Stand 11/26/2026",
	 * "As of 11/26/2026") get TT.MM.JJJJ (on activation, idempotent).
	 *
	 * @return int Events corrected, -1 error
	 */
	public function fixDates()
	{
		$resql = $this->db->query("SELECT id, label FROM ".MAIN_DB_PREFIX."actioncomm WHERE code LIKE 'AC_ITTAB_DOC_%' AND label LIKE '%/%/%'");
		if (!$resql) {
			return -1;
		}
		$rows = array();
		while ($row = $this->db->fetch_object($resql)) {
			$rows[] = $row;
		}
		$count = 0;
		foreach ($rows as $row) {
			$label = preg_replace('#\(((?:Stand|As of) )(\d{2})/(\d{2})/(\d{4})\)#', '(${1}${3}.${2}.${4})', (string) $row->label);
			if ($label !== null && $label !== $row->label) {
				$count += $this->db->query("UPDATE ".MAIN_DB_PREFIX."actioncomm SET label = '".$this->db->escape($label)."' WHERE id = ".((int) $row->id)) ? 1 : 0;
			}
		}
		return $count;
	}

	/**
	 * The element an event of the documents belongs to.
	 *
	 * @param WerkbankDocs $docs  Documents
	 * @param object       $event Row
	 * @return array{0:string,1:int} element type and id, ('', 0) for the third party alone
	 */
	private function objectOf($docs, $event)
	{
		$element = (string) $event->element;
		$id = (int) $event->fk_object;
		if ($element === 'docgen' && $id > 0) {
			$generated = $docs->event($id);
			$element = $generated ? (string) $generated->element : '';
			$id = $generated ? (int) $generated->fk_object : 0;
		}
		$known = array('contrat' => 'contract', 'propal' => 'propal', 'fichinter' => 'fichinter', 'ticket' => 'ticket', 'commande' => 'order', 'facture' => 'invoice');
		return isset($known[$element]) && $id > 0 ? array($known[$element], $id) : array('', 0);
	}

	/**
	 * @param string $code       Event code
	 * @param string $element    Element type
	 * @param int    $id         Element id
	 * @return bool              An event of this code exists for the object
	 */
	private function exists($code, $element, $id)
	{
		$resql = $this->db->query("SELECT id FROM ".MAIN_DB_PREFIX."actioncomm WHERE code = '".$this->db->escape($code)."' AND elementtype = '".$this->db->escape($element)."'"
			." AND fk_element = ".((int) $id));
		return $resql && $this->db->num_rows($resql) > 0;
	}

	/**
	 * @param int $id User id
	 * @return User|null
	 */
	private function userOf($id)
	{
		global $user;
		if ($id > 0) {
			$found = new User($this->db);
			if ($found->fetch($id) > 0) {
				return $found;
			}
		}
		return is_object($user) && !empty($user->id) ? $user : null;
	}

	/**
	 * One finished event of type "Unterlage".
	 *
	 * @param string    $label    Title
	 * @param string    $note     Details (plain text)
	 * @param int       $date     When
	 * @param int       $socid    Third party
	 * @param string    $element  Object type ('' none)
	 * @param int       $objectId Object id
	 * @param string    $code     Code of this kind of event
	 * @param User|null $user     Who
	 * @return int                Event id, -1 error
	 */
	private function create($label, $note, $date, $socid, $element, $objectId, $code, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		if (self::ensureType($this->db) < 0 || !$user) {
			return -1;
		}
		$event = new ActionComm($this->db);
		$event->type_code = self::TYPE;
		$event->code = $code;
		$event->label = $label;
		$event->note_private = $note;
		$event->datep = $date ?: dol_now();
		$event->datef = $event->datep;
		$event->fulldayevent = 0;
		$event->percentage = -1;
		$event->socid = $socid;
		$event->authorid = (int) $user->id;
		$event->userownerid = (int) $user->id;
		if ($element !== '' && $objectId > 0) {
			$event->elementtype = $element;
			$event->fk_element = $objectId;
		}
		$id = $event->create($user, 1);
		if ($id <= 0) {
			$this->error = $event->error;
			dol_syslog('ittabelanderpdf: agenda event failed: '.$event->error, LOG_WARNING);
			return -1;
		}
		return (int) $id;
	}
}
