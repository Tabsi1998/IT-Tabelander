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

/**
 * Contract (Vertrag) for ongoing services: minimum term and notice period
 * from the module's extra fields, start, planned end and billing interval
 * per service line.
 */
class WerkbankBuilderContrat extends WerkbankBuilder
{
	/**
	 * @return string
	 */
	public function outputFile()
	{
		return $this->fileIn(getMultidirOutput($this->object));
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build()
	{
		$object = $this->object;
		$langs = $this->langs;
		$doc = $this->base($langs->transnoentities('WerkbankContract'), 'CUSTOMER', 'CONTRACT_DRAFT_WATERMARK', 'CONTRACT_FREE_TEXT');
		$doc['subtitle'] = (string) $object->ref_customer;

		if (empty($object->array_options)) {
			$object->fetch_optionals();
		}
		$info = array(
			array($langs->transnoentities('WerkbankContractNo'), $object->ref),
			array($langs->transnoentities('WerkbankDate'), $this->date($object->date_contrat)),
		);
		$info = $this->withThirdpartyCode($info);
		$minimum = isset($object->array_options['options_ittab_mindestlaufzeit']) ? (int) $object->array_options['options_ittab_mindestlaufzeit'] : 0;
		if ($minimum > 0) {
			$info[] = array($langs->transnoentities('WerkbankMinimumTerm'), $langs->transnoentities($minimum === 1 ? 'WerkbankMonth' : 'WerkbankMonths', $minimum));
		}
		$notice = isset($object->array_options['options_ittab_kuendigungsfrist']) ? trim((string) $object->array_options['options_ittab_kuendigungsfrist']) : '';
		if ($notice !== '') {
			$info[] = array($langs->transnoentities('WerkbankNoticePeriod'), $notice);
		}
		$doc['info'] = $info;

		$doc['columns'] = $this->priceColumns();
		$sums = array();
		foreach ($object->lines as $line) {
			$row = $this->lineText($line) + array('cells' => array());
			$interval = $this->interval($line);
			$meta = array();
			if (!empty($line->date_start)) {
				$meta[] = $langs->transnoentities('WerkbankStart').' '.$this->date($line->date_start);
			}
			if (!empty($line->date_end)) {
				$meta[] = $langs->transnoentities('WerkbankPlannedEnd').' '.$this->date($line->date_end);
			}
			if ($interval !== '') {
				$meta[] = $langs->transnoentities('WerkbankBilling').' '.$interval;
			}
			$row['meta'] = implode(' · ', $meta);
			$row['cells']['qty'] = array('text' => $this->quantity($line->qty), 'unit' => $this->unit($line));
			$row['cells']['price'] = array('text' => $this->unitPrice($line->subprice));
			if ((float) $line->remise_percent > 0) {
				$row['cells']['price']['note'] = $langs->transnoentities('WerkbankDiscount', price2num($line->remise_percent).' %');
			}
			$row['cells']['total'] = array('text' => $this->money($line->total_ht), 'bold' => true);
			$sums[$interval] = (isset($sums[$interval]) ? $sums[$interval] : 0) + (float) $line->total_ttc;
			$doc['rows'][] = $row;
		}

		// One box when all services bill alike ("Gesamt monatlich"); with
		// mixed intervals a sum per interval and no grand total.
		$totals = $this->taxTotals($langs->transnoentities('WerkbankTotal'));
		if (count($sums) === 1) {
			$interval = (string) key($sums);
			if ($interval !== '') {
				$totals['grand_label'] = $langs->transnoentities('WerkbankTotal').' '.$interval.' '.$this->currency();
			}
		} elseif (count($sums) > 1) {
			$totals['rows'] = array();
			foreach ($sums as $interval => $sum) {
				$totals['rows'][] = array($interval !== '' ? ucfirst($interval) : $langs->transnoentities('WerkbankOnce'), $this->money($sum));
			}
			$totals['grand'] = null;
		}
		$doc['totals'] = $totals;
		$doc['signatures'] = array(
			'left' => $langs->transnoentities('WerkbankSignFor', $this->mysoc->name),
			'right' => $langs->transnoentities('WerkbankSignCustomer'),
		);
		return $doc;
	}

	/**
	 * Billing interval from the service's duration ("monatlich", "alle 3 Monate"), or ''.
	 *
	 * @param ContratLigne $line Contract line
	 * @return string
	 */
	private function interval($line)
	{
		static $products = array();
		if (empty($line->fk_product)) {
			return '';
		}
		if (!isset($products[$line->fk_product])) {
			$product = new Product($this->db);
			$products[$line->fk_product] = $product->fetch($line->fk_product) > 0 ? $product : null;
		}
		$product = $products[$line->fk_product];
		if (!$product || empty($product->duration_value) || empty($product->duration_unit)) {
			return '';
		}
		$value = (int) $product->duration_value;
		$unit = strtolower((string) $product->duration_unit);
		if (!in_array($unit, array('h', 'd', 'w', 'm', 'y'), true)) {
			return '';
		}
		if ($value === 1) {
			return $this->langs->transnoentities('WerkbankEvery1'.$unit);
		}
		return $this->langs->transnoentities('WerkbankEveryN'.$unit, $value);
	}
}
