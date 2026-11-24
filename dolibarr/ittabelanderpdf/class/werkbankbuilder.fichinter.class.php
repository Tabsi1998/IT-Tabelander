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
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * Intervention: service order while it has no work entries, work report
 * (Arbeitsbericht) with dates, durations and the customer's signature once it has.
 */
class WerkbankBuilderFichinter extends WerkbankBuilder
{
	/**
	 * @return string
	 */
	public function outputFile()
	{
		global $conf;
		return $this->fileIn($conf->ficheinter->dir_output);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build()
	{
		$object = $this->object;
		$langs = $this->langs;
		$report = count($object->lines) > 0;
		$doc = $this->base($langs->transnoentities($report ? 'WerkbankWorkReport' : 'WerkbankServiceOrder'), 'CUSTOMER', 'FICHINTER_DRAFT_WATERMARK', 'FICHINTER_FREE_TEXT');
		$doc['subtitle'] = (string) $object->ref_client;

		$intro = trim((string) $object->description);
		if ($intro !== '') {
			$doc['intro'] = dol_htmlentitiesbr($intro).($doc['intro'] !== '' ? '<br><br>'.$doc['intro'] : '');
		}

		$dates = array();
		$total = 0;
		$doc['desc_title'] = $langs->transnoentities('WerkbankColActivity');
		$doc['columns'] = array(
			array('key' => 'date', 'title' => $langs->transnoentities('WerkbankDate'), 'w' => 30.0),
			array('key' => 'duration', 'title' => $langs->transnoentities('WerkbankColDuration'), 'w' => 22.0),
		);
		foreach ($object->lines as $line) {
			$row = $this->lineText($line) + array('meta' => '', 'cells' => array());
			if (!empty($line->datei)) {
				$dates[] = (int) $line->datei;
				$withTime = (int) dol_print_date($line->datei, '%H%M') !== 0;
				$row['cells']['date'] = array('text' => dol_print_date($line->datei, $withTime ? 'dayhour' : 'day', false, $langs, true));
			}
			$row['cells']['duration'] = array('text' => self::hours((int) $line->duration), 'unit' => 'h', 'bold' => true);
			$total += (int) $line->duration;
			$doc['rows'][] = $row;
		}

		$info = array(array($langs->transnoentities('WerkbankInterventionNo'), $object->ref));
		$info[] = array($langs->transnoentities('WerkbankDate'), $this->date(!empty($object->datev) ? $object->datev : $object->datec));
		if (count($dates) > 0) {
			$info[] = array($langs->transnoentities('WerkbankServiceDate'), $this->period(min($dates), max($dates)));
		}
		$info = $this->withThirdpartyCode($info);
		if (!empty($object->fk_contrat)) {
			require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
			$contract = new Contrat($this->db);
			if ($contract->fetch($object->fk_contrat) > 0) {
				$info[] = array($langs->transnoentities('WerkbankForContract'), $contract->ref);
			}
		}
		$doc['info'] = $info;

		if ($report) {
			$doc['totals'] = array('rows' => array(), 'grand_label' => $langs->transnoentities('WerkbankTotalDuration'), 'grand' => self::hours($total).' h', 'notice' => '');
			$doc['signatures'] = array(
				'left' => $langs->transnoentities('WerkbankSignTechnician', $this->mysoc->name),
				'right' => $langs->transnoentities('WerkbankSignCustomerConfirm'),
			);
		}
		return $doc;
	}

	/**
	 * Seconds as hours and minutes, "1:30".
	 *
	 * @param int $seconds Duration
	 * @return string
	 */
	private static function hours($seconds)
	{
		$minutes = (int) round($seconds / 60);
		return intdiv($minutes, 60).':'.sprintf('%02d', $minutes % 60);
	}
}
