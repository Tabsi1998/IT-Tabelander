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
 * Price request to a supplier (Preisanfrage): quantities, no prices.
 */
class WerkbankBuilderSupplierProposal extends WerkbankBuilder
{
	/**
	 * @return string
	 */
	public function outputFile()
	{
		global $conf;
		return $this->fileIn($conf->supplier_proposal->dir_output);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build()
	{
		$object = $this->object;
		$langs = $this->langs;
		$doc = $this->base($langs->transnoentities('WerkbankPriceRequest'), 'SERVICE', 'SUPPLIER_PROPOSAL_DRAFT_WATERMARK', 'SUPPLIER_PROPOSAL_FREE_TEXT');
		$info = array(
			array($langs->transnoentities('WerkbankRequestNo'), $object->ref),
			array($langs->transnoentities('WerkbankDate'), $this->date(!empty($object->date_validation) ? $object->date_validation : $object->date_creation)),
		);
		if (!empty($object->delivery_date)) {
			$info[] = array($langs->transnoentities('WerkbankWishedDelivery'), $this->date($object->delivery_date));
		}
		$doc['info'] = $this->withThirdpartyCode($info, true);
		$doc['desc_title'] = $langs->transnoentities('WerkbankColArticle');
		$doc['columns'] = array(array('key' => 'qty', 'title' => $langs->transnoentities('WerkbankColQty'), 'w' => 26.0));
		foreach ($object->lines as $line) {
			$row = $this->lineText($line, true) + array('meta' => '', 'cells' => array());
			$row['cells']['qty'] = array('text' => $this->quantity($line->qty), 'unit' => $this->unit($line), 'bold' => true);
			$doc['rows'][] = $row;
		}
		$doc['left'][] = array('cap' => $langs->transnoentities('WerkbankAnswer'), 'text' => $langs->transnoentities('WerkbankAnswerText', $this->mysoc->email));
		return $doc;
	}
}
