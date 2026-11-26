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
 * QS checklists: kinds of job (Auftragsart) with their points, kept in the
 * module setup, and QS runs on an intervention (Serviceauftrag). A run copies
 * the points when it starts, so later changes of the checklist never touch a
 * protocol that exists. Closing a run writes the test report (Prüfprotokoll).
 */
class WerkbankQs
{
	/** Kinds of point: tick, measured value with unit, free text. */
	const KINDS = array('check', 'value', 'text');

	/** Who signs a protocol (2b); order is the order on the PDF. */
	const SIGNERS = array('technician', 'customer');

	/** State of a point: open, done, does not apply. */
	const OPEN = 0;
	const DONE = 1;
	const SKIPPED = 2;

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

	// ------------------------------------------------------------ defaults

	/**
	 * The checklists the module ships: code => label, points as
	 * array(label, kind, unit, required). Only written when there is none.
	 *
	 * @return array<string,array{0:string,1:array<int,array{0:string,1:string,2:string,3:int}>}>
	 */
	public static function defaults()
	{
		$stress = array('Stresstest 30 Minuten (CPU und GPU) ohne Absturz', 'check', '', 1);
		$cpu = array('CPU-Temperatur unter Last', 'value', '°C', 1);
		return array(
			'REPARATUR' => array('Reparatur PC/Notebook', array(
				array('Fehler laut Auftrag nachgestellt und behoben', 'check', '', 1),
				array('Seriennummer des Geräts', 'text', '', 1),
				array('Sichtprüfung: Gehäuse, Anschlüsse, Lüfter', 'check', '', 0),
				$stress,
				$cpu,
				array('GPU-Temperatur unter Last', 'value', '°C', 0),
				array('Datenträger: SMART-Werte geprüft', 'check', '', 1),
				array('Akku-Zustand (Notebook)', 'value', '%', 0),
				array('Treiber und Windows-Updates aktuell', 'check', '', 0),
				array('Innenreinigung, Staub entfernt', 'check', '', 0),
				array('Alle Schrauben und Abdeckungen montiert', 'check', '', 1),
				array('Daten des Kunden unverändert vorhanden', 'check', '', 1),
			)),
			'AUFRUESTUNG' => array('Aufrüstung', array(
				array('Neue Teile werden in BIOS und Windows erkannt', 'check', '', 1),
				array('Seriennummern der neuen Teile', 'text', '', 1),
				array('BIOS/Firmware aktuell', 'check', '', 0),
				array('Treiber der neuen Teile installiert', 'check', '', 1),
				$stress,
				$cpu,
				array('Kabelmanagement sauber', 'check', '', 0),
				array('Alte Teile: übergeben oder entsorgt', 'text', '', 0),
			)),
			'PCBAU' => array('PC-Bau', array(
				array('Alle Komponenten laut Auftrag verbaut', 'check', '', 1),
				array('Seriennummern (Mainboard, CPU, GPU, Netzteil)', 'text', '', 1),
				array('BIOS aktuell, XMP/EXPO aktiv', 'check', '', 1),
				array('RAM-Takt laut Windows', 'value', 'MHz', 0),
				array('Windows installiert und aktiviert', 'check', '', 1),
				array('Treiber und Updates installiert', 'check', '', 1),
				array('Stresstest 1 Stunde (CPU und GPU) ohne Absturz', 'check', '', 1),
				$cpu,
				array('GPU-Temperatur unter Last', 'value', '°C', 1),
				array('Speichertest ohne Fehler', 'check', '', 0),
				array('Lüfterkurve eingestellt, Lautstärke geprüft', 'check', '', 0),
				array('Kabelmanagement sauber', 'check', '', 1),
				array('Gehäuse ohne Kratzer, Seitenteile fest', 'check', '', 0),
				array('Zubehör und Kartons vollständig', 'check', '', 0),
			)),
			'CONTROLLER' => array('Controller-Umbau', array(
				array('Alle Tasten getestet', 'check', '', 1),
				array('Sticks: Drift und Totzone geprüft', 'check', '', 1),
				array('Sticks kalibriert', 'check', '', 1),
				array('Trigger und Vibration getestet', 'check', '', 1),
				array('Paddles und Zusatztasten getestet', 'check', '', 0),
				array('Verbindung per Kabel und Bluetooth', 'check', '', 1),
				array('Akku lädt', 'check', '', 0),
				array('Gehäuse geschlossen, Schrauben fest', 'check', '', 1),
				array('Seriennummer', 'text', '', 0),
			)),
			'WINDOWS' => array('Windows-Neuinstallation und Datenübernahme', array(
				array('Datensicherung vor Beginn erstellt', 'check', '', 1),
				array('Windows installiert und aktiviert', 'check', '', 1),
				array('Geräte-Manager ohne Fehler', 'check', '', 1),
				array('Updates installiert', 'check', '', 1),
				array('Daten übernommen und stichprobenartig geprüft', 'check', '', 1),
				array('Benutzerkonten', 'text', '', 0),
				array('Programme laut Auftrag installiert', 'check', '', 0),
				array('Mail- und Browserdaten übernommen', 'check', '', 0),
				array('Sicherung: beim Kunden oder gelöscht', 'text', '', 1),
			)),
			'WEBSEITE' => array('Webseite-Übergabe', array(
				array('Alle Seiten erreichbar, keine toten Links', 'check', '', 1),
				array('Auf Handy und Tablet geprüft', 'check', '', 1),
				array('SSL aktiv, Weiterleitung auf https', 'check', '', 1),
				array('Impressum und Datenschutz vorhanden', 'check', '', 1),
				array('Kontaktformular getestet', 'check', '', 1),
				array('Ladezeit Startseite', 'value', 's', 0),
				array('Zugänge übergeben', 'check', '', 1),
				array('Sicherung eingerichtet', 'check', '', 0),
				array('Sitemap und Search Console', 'check', '', 0),
			)),
			'VORORT' => array('Einsatz vor Ort', array(
				array('Anliegen mit dem Kunden besprochen', 'check', '', 1),
				array('Erledigte Arbeiten', 'text', '', 1),
				array('Funktion gemeinsam mit dem Kunden geprüft', 'check', '', 1),
				array('Geänderte Zugänge übergeben', 'check', '', 0),
				array('Arbeitsplatz aufgeräumt', 'check', '', 0),
				array('Offene Punkte', 'text', '', 0),
			)),
		);
	}

	/**
	 * Write the shipped checklists when the entity has none yet (first
	 * activation). An owner who deleted or changed them keeps his.
	 *
	 * @param int $entity Entity
	 * @return int        Number of checklists written, -1 on error
	 */
	public function installDefaults($entity)
	{
		$sql = "SELECT COUNT(*) AS found FROM ".MAIN_DB_PREFIX."ittabpdf_qs_type WHERE entity = ".((int) $entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ((int) $obj->found > 0) {
			return 0;
		}
		$position = 0;
		foreach (self::defaults() as $code => $definition) {
			$position += 10;
			$type = $this->saveType(0, array('code' => $code, 'label' => $definition[0], 'position' => $position, 'active' => 1,
				'signers' => implode(',', self::SIGNERS), 'notice' => ''), $entity);
			if ($type < 0) {
				return -1;
			}
			foreach ($definition[1] as $index => $item) {
				if ($this->saveItem(0, $type, array('position' => ($index + 1) * 10, 'label' => $item[0], 'kind' => $item[1],
					'unit' => $item[2], 'required' => $item[3], 'help' => '', 'active' => 1), $entity) < 0) {
					return -1;
				}
			}
		}
		return count(self::defaults());
	}

	// ------------------------------------------------------------ checklists

	/**
	 * @param bool $activeOnly Only active ones
	 * @return array<int,object> rowid, code, label, position, active, signers, notice
	 */
	public function types($activeOnly = false)
	{
		global $conf;
		$sql = "SELECT rowid, code, label, position, active, signers, notice FROM ".MAIN_DB_PREFIX."ittabpdf_qs_type"
			." WHERE entity = ".((int) $conf->entity).($activeOnly ? " AND active = 1" : "")." ORDER BY position, label";
		return $this->rows($sql);
	}

	/**
	 * @param int $id Type id
	 * @return object|null
	 */
	public function type($id)
	{
		global $conf;
		$rows = $this->rows("SELECT rowid, code, label, position, active, signers, notice FROM ".MAIN_DB_PREFIX."ittabpdf_qs_type"
			." WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity));
		return $rows ? $rows[0] : null;
	}

	/**
	 * @param int  $typeId     Type
	 * @param bool $activeOnly Only active points
	 * @return array<int,object>
	 */
	public function items($typeId, $activeOnly = false)
	{
		return $this->rows("SELECT rowid, fk_type, position, label, kind, unit, required, help, active FROM ".MAIN_DB_PREFIX."ittabpdf_qs_item"
			." WHERE fk_type = ".((int) $typeId).($activeOnly ? " AND active = 1" : "")." ORDER BY position, rowid");
	}

	/**
	 * Insert ($id 0) or update a kind of job.
	 *
	 * @param int                 $id     Row id or 0
	 * @param array<string,mixed> $values code, label, position, active, signers, notice
	 * @param int|null            $entity Entity, null = current
	 * @return int                        Row id, -1 on error
	 */
	public function saveType($id, array $values, $entity = null)
	{
		global $conf;
		$entity = $entity === null ? (int) $conf->entity : (int) $entity;
		$code = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '', (string) $values['code']));
		$label = trim((string) $values['label']);
		if ($code === '' || $label === '') {
			$this->error = 'ErrorFieldRequired';
			return -1;
		}
		$signers = implode(',', array_values(array_intersect(self::SIGNERS, array_map('trim', explode(',', (string) $values['signers'])))));
		$notice = trim((string) $values['notice']);
		$fields = array(
			'code' => "'".$this->db->escape(substr($code, 0, 32))."'",
			'label' => "'".$this->db->escape(dol_trunc($label, 128, 'right', 'UTF-8', 1))."'",
			'position' => (int) $values['position'],
			'active' => empty($values['active']) ? 0 : 1,
			'signers' => "'".$this->db->escape($signers)."'",
			'notice' => $notice === '' ? "NULL" : "'".$this->db->escape($notice)."'",
		);
		return $this->write('ittabpdf_qs_type', $id, $fields, $entity, " AND entity = ".$entity);
	}

	/**
	 * Insert ($id 0) or update a point of a checklist.
	 *
	 * @param int                 $id     Row id or 0
	 * @param int                 $typeId Type
	 * @param array<string,mixed> $values position, label, kind, unit, required, help, active
	 * @param int|null            $entity Entity, null = current
	 * @return int                        Row id, -1 on error
	 */
	public function saveItem($id, $typeId, array $values, $entity = null)
	{
		global $conf;
		$entity = $entity === null ? (int) $conf->entity : (int) $entity;
		$label = trim((string) $values['label']);
		if ($label === '') {
			$this->error = 'ErrorFieldRequired';
			return -1;
		}
		$kind = in_array($values['kind'], self::KINDS, true) ? $values['kind'] : 'check';
		$fields = array(
			'fk_type' => (int) $typeId,
			'position' => (int) $values['position'],
			'label' => "'".$this->db->escape(dol_trunc($label, 255, 'right', 'UTF-8', 1))."'",
			'kind' => "'".$this->db->escape($kind)."'",
			'unit' => "'".$this->db->escape(dol_trunc(trim((string) $values['unit']), 16, 'right', 'UTF-8', 1))."'",
			'required' => empty($values['required']) ? 0 : 1,
			'help' => "'".$this->db->escape(dol_trunc(trim((string) $values['help']), 255, 'right', 'UTF-8', 1))."'",
			'active' => empty($values['active']) ? 0 : 1,
		);
		return $this->write('ittabpdf_qs_item', $id, $fields, $entity, " AND fk_type = ".((int) $typeId));
	}

	// ------------------------------------------------------------ runs

	/**
	 * @param int $fichinterId Intervention
	 * @return array<int,object> Runs, newest first, with counts done/total/required open
	 */
	public function runsFor($fichinterId)
	{
		global $conf;
		$runs = $this->rows("SELECT rowid, fk_fichinter, fk_type, type_label, status, filename, date_creation, date_close FROM ".MAIN_DB_PREFIX."ittabpdf_qs_run"
			." WHERE fk_fichinter = ".((int) $fichinterId)." AND entity = ".((int) $conf->entity)." ORDER BY rowid DESC");
		foreach ($runs as $run) {
			$run->counts = $this->counts((int) $run->rowid);
		}
		return $runs;
	}

	/**
	 * @param int $runId Run
	 * @return array{total:int,done:int,required_open:int}
	 */
	public function counts($runId)
	{
		$counts = array('total' => 0, 'done' => 0, 'required_open' => 0);
		foreach ($this->results($runId) as $result) {
			$counts['total']++;
			if (self::isDone($result)) {
				$counts['done']++;
			} elseif ((int) $result->required) {
				$counts['required_open']++;
			}
		}
		return $counts;
	}

	/**
	 * A point counts as done when ticked or skipped (check) or filled (value, text).
	 *
	 * @param object $result Result row
	 * @return bool
	 */
	public static function isDone($result)
	{
		if ((int) $result->checked === self::SKIPPED) {
			return true;
		}
		if ($result->kind === 'check') {
			return (int) $result->checked === self::DONE;
		}
		return trim((string) $result->value) !== '';
	}

	/**
	 * Start a QS for an intervention with the active points of a checklist.
	 *
	 * @param Fichinter $fichinter Intervention
	 * @param int       $typeId    Kind of job
	 * @param User      $user      User
	 * @return int                 Run id, -1 on error
	 */
	public function start($fichinter, $typeId, $user)
	{
		global $conf;
		$type = $this->type($typeId);
		if (!$type || !(int) $type->active) {
			$this->error = 'WerkbankQsUnknownType';
			return -1;
		}
		$this->db->begin();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."ittabpdf_qs_run (entity, fk_fichinter, fk_type, type_label, notice, signers, status, fk_user_creat, date_creation)"
			." VALUES (".((int) $conf->entity).", ".((int) $fichinter->id).", ".((int) $type->rowid).", '".$this->db->escape($type->label)."',"
			." ".($type->notice === null ? "NULL" : "'".$this->db->escape($type->notice)."'").", '".$this->db->escape((string) $type->signers)."', 0,"
			." ".((int) $user->id).", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			return $this->fail();
		}
		$runId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'ittabpdf_qs_run');
		foreach ($this->items((int) $type->rowid, true) as $item) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."ittabpdf_qs_result (fk_run, fk_item, position, label, kind, unit, required, help)"
				." VALUES (".$runId.", ".((int) $item->rowid).", ".((int) $item->position).", '".$this->db->escape($item->label)."',"
				." '".$this->db->escape($item->kind)."', '".$this->db->escape((string) $item->unit)."', ".((int) $item->required).", '".$this->db->escape((string) $item->help)."')";
			if (!$this->db->query($sql)) {
				$this->db->rollback();
				return $this->fail();
			}
		}
		$this->db->commit();
		return $runId;
	}

	/**
	 * @param int $runId Run
	 * @return object|null Run row of the current entity
	 */
	public function run($runId)
	{
		global $conf;
		$rows = $this->rows("SELECT * FROM ".MAIN_DB_PREFIX."ittabpdf_qs_run WHERE rowid = ".((int) $runId)." AND entity = ".((int) $conf->entity));
		return $rows ? $rows[0] : null;
	}

	/**
	 * @param int $runId Run
	 * @return array<int,object> Points with the name of who ticked them
	 */
	public function results($runId)
	{
		return $this->rows("SELECT r.*, u.firstname, u.lastname, u.login FROM ".MAIN_DB_PREFIX."ittabpdf_qs_result AS r"
			." LEFT JOIN ".MAIN_DB_PREFIX."user AS u ON u.rowid = r.fk_user WHERE r.fk_run = ".((int) $runId)." ORDER BY r.position, r.rowid");
	}

	/**
	 * Save one point of an open run.
	 *
	 * @param int    $runId    Run
	 * @param int    $resultId Point
	 * @param int    $checked  OPEN, DONE or SKIPPED
	 * @param string $value    Value or text
	 * @param string $note     Note
	 * @param User   $user     User
	 * @return int             1 saved, -1 error
	 */
	public function save($runId, $resultId, $checked, $value, $note, $user)
	{
		$run = $this->run($runId);
		if (!$run || (int) $run->status !== 0) {
			$this->error = 'WerkbankQsClosed';
			return -1;
		}
		$checked = in_array((int) $checked, array(self::OPEN, self::DONE, self::SKIPPED), true) ? (int) $checked : self::OPEN;
		$value = dol_trunc(trim((string) $value), 255, 'right', 'UTF-8', 1);
		$note = trim((string) $note);
		$touched = $checked !== self::OPEN || $value !== '' || $note !== '';
		$sql = "UPDATE ".MAIN_DB_PREFIX."ittabpdf_qs_result SET checked = ".$checked.", value = '".$this->db->escape($value)."',"
			." note = ".($note === '' ? "NULL" : "'".$this->db->escape($note)."'").","
			." fk_user = ".($touched ? (int) $user->id : "NULL").", date_check = ".($touched ? "'".$this->db->idate(dol_now())."'" : "NULL")
			." WHERE rowid = ".((int) $resultId)." AND fk_run = ".((int) $runId);
		if (!$this->db->query($sql)) {
			return $this->fail();
		}
		return 1;
	}

	/**
	 * Required points that are not done, by label.
	 *
	 * @param int $runId Run
	 * @return string[]
	 */
	public function requiredOpen($runId)
	{
		$open = array();
		foreach ($this->results($runId) as $result) {
			if ((int) $result->required && !self::isDone($result)) {
				$open[] = (string) $result->label;
			}
		}
		return $open;
	}

	/**
	 * Close a run: refused while required points are open, else the test
	 * report is written into the intervention's documents.
	 *
	 * @param int       $runId     Run
	 * @param Fichinter $fichinter Its intervention (fetched, with thirdparty)
	 * @param User      $user      User
	 * @param string    $note      Closing remark printed on the protocol
	 * @return int                 1 closed, -1 error ($this->error), -2 required points open
	 */
	public function close($runId, $fichinter, $user, $note = '')
	{
		$run = $this->run($runId);
		if (!$run || (int) $run->fk_fichinter !== (int) $fichinter->id) {
			$this->error = 'WerkbankQsUnknownRun';
			return -1;
		}
		if ((int) $run->status !== 0) {
			return 1;
		}
		$open = $this->requiredOpen($runId);
		if ($open) {
			$this->error = implode(', ', $open);
			return -2;
		}
		$this->db->begin();
		$sql = "UPDATE ".MAIN_DB_PREFIX."ittabpdf_qs_run SET status = 1, fk_user_close = ".((int) $user->id).", date_close = '".$this->db->idate(dol_now())."',"
			." note = ".(trim($note) === '' ? "note" : "'".$this->db->escape(trim($note))."'")." WHERE rowid = ".((int) $runId)." AND status = 0";
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			return $this->fail();
		}
		$file = $this->writePdf($this->run($runId), $fichinter);
		if ($file === '') {
			$this->db->rollback();
			return -1;
		}
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_qs_run SET filename = '".$this->db->escape(basename($file))."' WHERE rowid = ".((int) $runId));
		$this->db->commit();
		// The closed run in the agenda of the customer and the intervention.
		require_once __DIR__.'/werkbankagenda.class.php';
		$agenda = new WerkbankAgenda($this->db);
		$agenda->logQs($this->run($runId), $user);
		return 1;
	}

	/**
	 * Open a closed run again (the old PDF stays until the next close; its
	 * signatures are dropped).
	 *
	 * @param int $runId Run
	 * @return int       1 OK, -1 error
	 */
	public function reopen($runId)
	{
		global $conf;
		// What was signed may change: the signatures go, the run is signed again after closing.
		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_signature WHERE kind = 'qs' AND fk_object = ".((int) $runId)." AND entity = ".((int) $conf->entity));
		return $this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_qs_run SET status = 0, date_sent = NULL, sent_to = NULL WHERE rowid = ".((int) $runId)) ? 1 : $this->fail();
	}

	/**
	 * Where the protocol of a run lies: the intervention's folder,
	 * <REF>-pruefprotokoll-<run>.pdf.
	 *
	 * @param Fichinter $fichinter Intervention
	 * @param object    $run       Run
	 * @return string
	 */
	public static function pdfPath($fichinter, $run)
	{
		global $conf;
		$ref = dol_sanitizeFileName($fichinter->ref);
		return $conf->ficheinter->dir_output.'/'.$ref.'/'.$ref.'-pruefprotokoll-'.((int) $run->rowid).'.pdf';
	}

	/**
	 * Test reports of the closed runs of an intervention, oldest first
	 * (ITTABPDF_QS_APPEND_WORKREPORT puts them behind the work report).
	 *
	 * @param Fichinter $fichinter Intervention
	 * @return string[]            Full paths of the files that exist
	 */
	public function protocolFiles($fichinter)
	{
		$files = array();
		foreach (array_reverse($this->runsFor((int) $fichinter->id)) as $run) {
			$path = self::pdfPath($fichinter, $run);
			if ((int) $run->status === 1 && is_readable($path)) {
				$files[] = $path;
			}
		}
		return $files;
	}

	/**
	 * The test report as a Werkbank PDF.
	 *
	 * @param object                             $run       Run
	 * @param Fichinter                          $fichinter Intervention
	 * @param array<string,array<string,string>> $signed    role => image, name, when (WerkbankSignature::forPdf)
	 * @return string                                       Path, '' on error
	 */
	public function writePdf($run, $fichinter, array $signed = array())
	{
		global $langs;
		require_once __DIR__.'/werkbankbuilder.qs.class.php';
		require_once __DIR__.'/werkbankrenderer.class.php';
		$langs->loadLangs(array('main', 'interventions', 'ittabelanderpdf@ittabelanderpdf'));
		if (empty($fichinter->thirdparty) && method_exists($fichinter, 'fetch_thirdparty')) {
			$fichinter->fetch_thirdparty();
		}
		$target = self::pdfPath($fichinter, $run);
		if (!is_dir(dirname($target)) && dol_mkdir(dirname($target)) < 0) {
			$this->error = 'ErrorCanNotCreateDir';
			return '';
		}
		try {
			$builder = new WerkbankBuilderQs($this->db, $fichinter, $langs, $run, $this->results((int) $run->rowid), $signed);
			$renderer = new WerkbankRenderer(pdf_getInstance(array(WerkbankRenderer::PAGE_W, WerkbankRenderer::PAGE_H)), $langs);
			$renderer->render($builder->build(), $target);
		} catch (Throwable $exception) {
			dol_syslog('ittabelanderpdf: '.$exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine(), LOG_ERR);
			$this->error = $exception->getMessage();
			return '';
		}
		dolChmod($target);
		// The customer's card shows the report too.
		require_once __DIR__.'/werkbanksignature.class.php';
		WerkbankSignature::copyToThirdparty($target, (int) $fichinter->socid);
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
	 * Insert ($id 0) or update one row from SQL-ready values.
	 *
	 * @param string             $table  Table without prefix
	 * @param int                $id     Row id or 0
	 * @param array<string,mixed> $fields Column => SQL literal
	 * @param int                $entity Entity for a new row
	 * @param string             $where  Extra condition for the update
	 * @return int                       Row id, -1 on error
	 */
	private function write($table, $id, array $fields, $entity, $where)
	{
		if ($id > 0) {
			$set = array();
			foreach ($fields as $column => $literal) {
				$set[] = $column." = ".$literal;
			}
			$ok = $this->db->query("UPDATE ".MAIN_DB_PREFIX.$table." SET ".implode(', ', $set)." WHERE rowid = ".((int) $id).$where);
			return $ok ? (int) $id : $this->fail();
		}
		$fields = array('entity' => (int) $entity) + $fields;
		$ok = $this->db->query("INSERT INTO ".MAIN_DB_PREFIX.$table." (".implode(', ', array_keys($fields)).") VALUES (".implode(', ', $fields).")");
		return $ok ? (int) $this->db->last_insert_id(MAIN_DB_PREFIX.$table) : $this->fail();
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
