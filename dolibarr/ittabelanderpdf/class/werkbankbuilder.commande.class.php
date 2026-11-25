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
 * Sales order (Auftragsbestätigung).
 */
class WerkbankBuilderCommande extends WerkbankBuilder
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
		$doc = $this->base($langs->transnoentities('WerkbankOrder'), 'CUSTOMER', 'COMMANDE_DRAFT_WATERMARK', 'ORDER_FREE_TEXT');
		$doc['subtitle'] = (string) $object->ref_client;
		$info = array(
			array($langs->transnoentities('WerkbankOrderNo'), $object->ref),
			array($langs->transnoentities('WerkbankDate'), $this->date($object->date)),
		);
		if (!empty($object->delivery_date)) {
			$info[] = array($langs->transnoentities('WerkbankDeliveryDate'), $this->date($object->delivery_date));
		}
		$info = $this->withThirdpartyCode($info);
		$proposal = $this->linkedRef('propal');
		if ($proposal !== '') {
			$info[] = array($langs->transnoentities('WerkbankForProposal'), $proposal);
		}
		$doc['info'] = $info;
		$doc['columns'] = $this->priceColumns();
		$doc['rows'] = $this->priceRows();
		$doc['totals'] = $this->taxTotals($langs->transnoentities('WerkbankTotal'));
		$payment = $this->paymentText();
		if ($payment !== '') {
			$doc['left'][] = array('cap' => $langs->transnoentities('WerkbankPayment'), 'text' => $payment);
		}
		return $doc;
	}
}
