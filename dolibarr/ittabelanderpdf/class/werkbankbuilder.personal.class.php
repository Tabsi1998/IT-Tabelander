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

require_once __DIR__.'/werkbankbuilder.unterlage.class.php';

/**
 * A company document made for one customer: the article with placeholders
 * and input fields filled in, "Stand" of the template and the customer in
 * the line under the title, signature lines for both sides at the end
 * (with the images once signed on the device).
 */
class WerkbankBuilderPersonal extends WerkbankBuilderUnterlage
{
	/** @var Societe */
	private $customer;

	/** @var array<string,array<string,string>> */
	private $signed;

	/**
	 * @param DoliDB                             $db       Database
	 * @param object                             $record   Template version, answer already filled in
	 * @param Translate                          $langs    Output language
	 * @param int|null                           $stand    "Stand" of the template
	 * @param Societe                            $customer Customer
	 * @param array<string,array<string,string>> $signed   role => image, name, when
	 */
	public function __construct($db, $record, $langs, $stand, $customer, array $signed = array())
	{
		parent::__construct($db, $record, $langs, $stand);
		$this->customer = $customer;
		$this->signed = $signed;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build()
	{
		$langs = $this->langs;
		$doc = parent::build();
		$doc['meta'] .= ' · '.$langs->transnoentities('WerkbankGenFor', $this->customer->name);
		$doc['keywords'] .= ' '.$this->customer->name;
		$doc['sign_room'] = true;
		$labels = array(
			'technician' => $langs->transnoentities('WerkbankSignTechnician', $this->mysoc->name),
			'customer' => $langs->transnoentities('WerkbankGenSignCustomer'),
		);
		$lines = array();
		foreach ($labels as $role => $label) {
			$lines[$role] = isset($this->signed[$role]) ? array('label' => $label) + $this->signed[$role] : $label;
		}
		$doc['signatures'] = array('left' => $lines['technician'], 'right' => $lines['customer']);
		return $doc;
	}
}
