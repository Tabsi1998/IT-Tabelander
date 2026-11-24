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
 * Goods receipt (Wareneingang), an internal record: supplier, quantities, check line.
 */
class WerkbankBuilderReception extends WerkbankBuilder
{
	/**
	 * @return string
	 */
	public function outputFile()
	{
		global $conf;
		return $this->fileIn($conf->reception->dir_output);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build()
	{
		$object = $this->object;
		$langs = $this->langs;
		$object->fetch_origin();
		$order = is_object($object->origin_object) ? $object->origin_object : null;
		$doc = $this->base($langs->transnoentities('WerkbankReception'), '', 'RECEPTION_DRAFT_WATERMARK', 'RECEPTION_FREE_TEXT');
		$info = array(
			array($langs->transnoentities('WerkbankReceptionNo'), $object->ref),
			array($langs->transnoentities('WerkbankDate'), $this->date(!empty($object->date_reception) ? $object->date_reception : $object->date_creation)),
		);
		if ($order) {
			$info[] = array($langs->transnoentities('WerkbankForPurchaseOrder'), $order->ref);
		}
		if (!empty($object->ref_supplier)) {
			$info[] = array($langs->transnoentities('WerkbankSupplierDeliveryNote'), $object->ref_supplier);
		}
		$doc['info'] = $this->withThirdpartyCode($info, true);
		$doc['desc_title'] = $langs->transnoentities('WerkbankColArticle');
		$doc['columns'] = array(
			array('key' => 'ordered', 'title' => $langs->transnoentities('WerkbankColOrdered'), 'w' => 24.0),
			array('key' => 'received', 'title' => $langs->transnoentities('WerkbankColReceived'), 'w' => 26.0),
		);
		foreach ($object->lines as $line) {
			if (empty($line->product) && !empty($line->fk_product)) {
				$line->product = new Product($this->db);
				$line->product->fetch($line->fk_product);
			}
			$product = !empty($line->product) ? $line->product : null;
			$row = array(
				'ref' => $product ? (string) $product->ref : '',
				'label' => $product ? (string) $product->label : '',
				'desc' => !empty($line->comment) ? dol_htmlentitiesbr($line->comment) : '',
				'meta' => !empty($line->batch) ? $langs->transnoentities('WerkbankBatch').' '.$line->batch : '',
				'cells' => array(),
			);
			if (isset($line->qty_asked)) {
				$row['cells']['ordered'] = array('text' => $this->quantity($line->qty_asked));
			}
			$row['cells']['received'] = array('text' => $this->quantity($line->qty), 'bold' => true);
			$doc['rows'][] = $row;
		}
		$doc['signatures'] = array('right' => $langs->transnoentities('WerkbankSignChecked'));
		return $doc;
	}
}
