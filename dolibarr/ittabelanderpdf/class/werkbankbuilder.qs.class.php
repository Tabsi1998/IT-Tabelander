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

require_once __DIR__.'/werkbankbuilder.class.php';
require_once __DIR__.'/werkbankqs.class.php';

/**
 * Test report (Prüfprotokoll) of a QS run on an intervention: address and
 * info block like the work report, every point with its tick, value, note,
 * who and when, the closing remark and the signatures.
 */
class WerkbankBuilderQs extends WerkbankBuilder
{
	/** @var object */
	private $run;

	/** @var array<int,object> */
	private $results;

	/** @var array<string,array<string,string>> */
	private $signed;

	/**
	 * @param DoliDB                              $db      Database
	 * @param Fichinter                           $object  Intervention
	 * @param Translate                           $langs   Output language
	 * @param object                              $run     QS run
	 * @param array<int,object>                   $results Its points
	 * @param array<string,array<string,string>>  $signed  role => image, name, when (signed on the device)
	 */
	public function __construct($db, $object, $langs, $run, array $results, array $signed = array())
	{
		parent::__construct($db, $object, $langs);
		$this->run = $run;
		$this->results = $results;
		$this->signed = $signed;
	}

	/**
	 * @return string
	 */
	public function outputFile()
	{
		return WerkbankQs::pdfPath($this->object, $this->run);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build()
	{
		$langs = $this->langs;
		$object = $this->object;
		$run = $this->run;
		$title = $langs->transnoentities('WerkbankQsTitle');
		$doc = $this->base($title, 'CUSTOMER', '', '');
		$doc['subtitle'] = (string) $run->type_label;
		$doc['short_title'] = $title.' '.$object->ref;
		$doc['pdf_title'] = $object->ref.' '.$title;
		$doc['intro'] = '';
		$doc['freetext'] = '';

		$closed = !empty($run->date_close) ? $this->db->jdate($run->date_close) : dol_now();
		$info = array(
			array($langs->transnoentities('WerkbankInterventionNo'), $object->ref),
			array($langs->transnoentities('WerkbankQsDate'), dol_print_date($closed, 'dayhour', 'tzuser', $langs)),
			array($langs->transnoentities('WerkbankQsKind'), (string) $run->type_label),
		);
		$closer = $this->userName((int) $run->fk_user_close);
		if ($closer !== '') {
			$info[] = array($langs->transnoentities('WerkbankQsCheckedBy'), $closer);
		}
		$doc['info'] = $this->withThirdpartyCode($info);

		$done = 0;
		$rows = array();
		foreach ($this->results as $result) {
			$done += WerkbankQs::isDone($result) ? 1 : 0;
			$value = trim((string) $result->value);
			if ($value !== '' && $result->kind === 'value' && trim((string) $result->unit) !== '') {
				$value .= ' '.trim((string) $result->unit);
			}
			$meta = array();
			$who = trim($result->firstname.' '.$result->lastname);
			if ($who === '' && !empty($result->login)) {
				$who = $result->login;
			}
			if ((int) $result->checked === WerkbankQs::SKIPPED) {
				$meta[] = $langs->transnoentities('WerkbankQsSkipped');
			}
			if ($who !== '' || !empty($result->date_check)) {
				$meta[] = implode(' · ', array_filter(array($who, !empty($result->date_check) ? dol_print_date($this->db->jdate($result->date_check), 'dayhour', 'tzuser', $langs) : '')));
			}
			$state = (int) $result->checked;
			if ($result->kind !== 'check' && $state !== WerkbankQs::SKIPPED) {
				$state = $value !== '' ? WerkbankQs::DONE : WerkbankQs::OPEN;
			}
			// Free text is long: below the label, not in the value column.
			$note = trim((string) $result->note);
			if ($result->kind === 'text' && $value !== '') {
				$note = $value.($note !== '' ? "\n".$note : '');
				$value = '';
			}
			$rows[] = array('state' => $state, 'label' => (string) $result->label, 'value' => $value, 'note' => $note,
				'meta' => implode(' · ', $meta), 'required' => (int) $result->required);
		}
		$doc['meta'] = $langs->transnoentities('WerkbankQsSummary', $done, count($this->results));
		$doc['blocks'] = array(
			array('type' => 'section', 'cap' => $langs->transnoentities('WerkbankQsPoints')),
			array('type' => 'checks', 'rows' => $rows),
		);
		$notes = array();
		if (trim((string) $run->note) !== '') {
			$notes[] = trim((string) $run->note);
		}
		if (trim((string) $run->notice) !== '') {
			// The same placeholders as company documents (WerkbankVars).
			require_once __DIR__.'/werkbankvars.class.php';
			$notes[] = trim(make_substitutions((string) $run->notice, WerkbankVars::values($this->db, $object->thirdparty)));
		}
		if ($notes) {
			$doc['blocks'][] = array('type' => 'section', 'cap' => $langs->transnoentities('WerkbankQsRemarks'));
			$doc['blocks'][] = array('type' => 'notices', 'rows' => $notes);
		}
		$doc['signatures'] = $this->signatures((string) $run->signers);
		return $doc;
	}

	/**
	 * The signature lines the kind of job asks for, with the image and name
	 * when it was signed on the device.
	 *
	 * @param string $signers Comma separated roles
	 * @return array<string,mixed>
	 */
	private function signatures($signers)
	{
		$langs = $this->langs;
		$roles = array_values(array_intersect(WerkbankQs::SIGNERS, explode(',', $signers)));
		$labels = array(
			'technician' => $langs->transnoentities('WerkbankSignTechnician', $this->mysoc->name),
			'customer' => $langs->transnoentities('WerkbankSignCustomerConfirm'),
		);
		$lines = array();
		foreach ($roles as $role) {
			$lines[$role] = isset($this->signed[$role]) ? array('label' => $labels[$role]) + $this->signed[$role] : $labels[$role];
		}
		if (isset($lines['technician']) && isset($lines['customer'])) {
			return array('left' => $lines['technician'], 'right' => $lines['customer']);
		}
		return $lines ? array('right' => reset($lines)) : array();
	}

	/**
	 * @param int $id User id
	 * @return string Full name, '' when unknown
	 */
	private function userName($id)
	{
		if ($id <= 0) {
			return '';
		}
		$person = new User($this->db);
		return $person->fetch($id) > 0 ? $person->getFullName($this->langs) : '';
	}
}
