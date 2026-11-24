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
 * Shipment (Lieferschein), without prices.
 */
class WerkbankBuilderExpedition extends WerkbankBuilder
{
	/**
	 * @return string
	 */
	public function outputFile()
	{
		global $conf;
		return $this->fileIn($conf->expedition->dir_output.'/sending');
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

		$doc = $this->base($langs->transnoentities('WerkbankShipment'), '', 'SHIPPING_DRAFT_WATERMARK', 'SHIPPING_FREE_TEXT');
		// Delivery address: the order's shipping contact, else the customer.
		list($doc['recipient_name'], $doc['recipient_address']) = $this->recipient('SHIPPING', $order);
		$doc['subtitle'] = $order && !empty($order->ref_client) ? (string) $order->ref_client : (string) $object->ref_customer;

		$info = array(
			array($langs->transnoentities('WerkbankShipmentNo'), $object->ref),
			array($langs->transnoentities('WerkbankDate'), $this->date(!empty($object->date_shipping) ? $object->date_shipping : $object->date_creation)),
		);
		if (!empty($object->date_delivery)) {
			$info[] = array($langs->transnoentities('WerkbankDeliveryDate'), $this->date($object->date_delivery));
		}
		$info = $this->withThirdpartyCode($info);
		if ($order) {
			$info[] = array($langs->transnoentities('WerkbankForOrder'), $order->ref);
		}
		$doc['info'] = $info;

		$doc['desc_title'] = $langs->transnoentities('WerkbankColArticle');
		$doc['columns'] = array(
			array('key' => 'ordered', 'title' => $langs->transnoentities('WerkbankColOrdered'), 'w' => 24.0),
			array('key' => 'shipped', 'title' => $langs->transnoentities('WerkbankColShipped'), 'w' => 26.0),
		);
		foreach ($object->lines as $line) {
			$row = $this->lineText($line) + array('meta' => '', 'cells' => array());
			$unit = $this->unit($line);
			$row['cells']['ordered'] = array('text' => $this->quantity($line->qty_asked), 'unit' => $unit);
			$row['cells']['shipped'] = array('text' => $this->quantity($line->qty_shipped), 'unit' => $unit, 'bold' => true);
			$doc['rows'][] = $row;
		}

		$shipping = array();
		if (!empty($object->shipping_method_id)) {
			$code = dol_getIdFromCode($this->db, (string) $object->shipping_method_id, 'c_shipment_mode', 'rowid', 'code');
			$label = dol_getIdFromCode($this->db, (string) $object->shipping_method_id, 'c_shipment_mode', 'rowid', 'libelle');
			$key = 'SendingMethod'.strtoupper((string) $code);
			$shipping[] = $langs->transnoentities($key) != $key ? $langs->transnoentities($key) : $label;
		}
		if (!empty($object->tracking_number)) {
			$shipping[] = $langs->transnoentities('WerkbankTracking').': '.$object->tracking_number;
			$object->getUrlTrackingStatus($object->tracking_number);
			$url = trim(dol_string_nohtmltag((string) $object->tracking_url));
			if ($url !== '' && $url !== trim((string) $object->tracking_number)) {
				$shipping[] = $url;
			}
		}
		if ($shipping) {
			$doc['left'][] = array('cap' => $langs->transnoentities('WerkbankShipping'), 'text' => implode("\n", $shipping));
		}
		$doc['signatures'] = array('right' => $langs->transnoentities('WerkbankSignReceived'));
		return $doc;
	}
}
