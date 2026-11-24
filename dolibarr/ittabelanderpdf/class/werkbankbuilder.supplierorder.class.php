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
 * Purchase order to a supplier (Bestellung), with the supplier's references.
 */
class WerkbankBuilderSupplierOrder extends WerkbankBuilder
{
	/**
	 * @return string
	 */
	public function outputFile()
	{
		global $conf;
		return $this->fileIn($conf->fournisseur->commande->dir_output);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build()
	{
		$object = $this->object;
		$langs = $this->langs;
		$doc = $this->base($langs->transnoentities('WerkbankPurchaseOrder'), 'CUSTOMER', '', 'SUPPLIER_ORDER_FREE_TEXT');
		$info = array(
			array($langs->transnoentities('WerkbankPurchaseOrderNo'), $object->ref),
			array($langs->transnoentities('WerkbankDate'), $this->date(!empty($object->date_commande) ? $object->date_commande : $object->date)),
		);
		if (!empty($object->delivery_date)) {
			$info[] = array($langs->transnoentities('WerkbankWishedDelivery'), $this->date($object->delivery_date));
		}
		$info = $this->withThirdpartyCode($info, true);
		if (!empty($object->ref_supplier)) {
			$info[] = array($langs->transnoentities('WerkbankYourRef'), $object->ref_supplier);
		}
		$doc['info'] = $info;
		$doc['desc_title'] = $langs->transnoentities('WerkbankColArticle');
		$doc['columns'] = $this->priceColumns();
		$doc['rows'] = $this->priceRows(1.0, true);
		$doc['totals'] = $this->taxTotals($langs->transnoentities('WerkbankTotal'), 1.0, false);
		$payment = $this->paymentText();
		if ($payment !== '') {
			$doc['left'][] = array('cap' => $langs->transnoentities('WerkbankPayment'), 'text' => $payment);
		}
		$doc['left'][] = array('cap' => $langs->transnoentities('WerkbankDeliverTo'), 'text' => $this->mysoc->name."\n".trim((string) $this->mysoc->address)."\n".trim($this->mysoc->zip.' '.$this->mysoc->town));
		return $doc;
	}
}
