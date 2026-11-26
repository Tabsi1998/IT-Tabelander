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
require_once __DIR__.'/werkbankunterlagen.class.php';

/**
 * Confirmation sheet for a company document signed on the device: customer,
 * document with its "Stand", the sentence that is confirmed (accept, sign,
 * receive), the signatures; the document itself follows as its own pages.
 */
class WerkbankBuilderDocSign extends WerkbankBuilder
{
	/** @var object */
	private $rule;

	/** @var object */
	private $event;

	/** @var array<string,array<string,string>> */
	private $signed;

	/**
	 * @param DoliDB                             $db     Database
	 * @param Societe                            $object Customer
	 * @param Translate                          $langs  Output language
	 * @param object                             $rule   Rule (label, slug, action)
	 * @param object                             $event  The "signing" event
	 * @param array<string,array<string,string>> $signed role => image, name, when
	 */
	public function __construct($db, $object, $langs, $rule, $event, array $signed = array())
	{
		parent::__construct($db, $object, $langs);
		$this->rule = $rule;
		$this->event = $event;
		$this->signed = $signed;
	}

	/**
	 * @return string
	 */
	public function outputFile()
	{
		return '';
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build()
	{
		$langs = $this->langs;
		$soc = $this->object;
		$unterlagen = new WerkbankUnterlagen($this->db);
		$documents = $unterlagen->documents();
		$slug = (string) $this->rule->slug;
		$title = isset($documents[$slug]) ? (string) $documents[$slug]['title'] : (string) $this->rule->label;
		$version = (string) $this->event->version;
		$stand = $version !== '' ? dol_print_date(dol_stringtotime($version.' 00:00:00'), 'day', false, $langs) : '';

		$heading = $langs->transnoentities('WerkbankDocSignTitle');
		$doc = $this->sheet($heading, $heading.' · '.$title);
		$doc['pdf_title'] = $title.' – '.$heading;
		$doc['keywords'] = $slug.' '.$soc->name;
		$doc['sign_room'] = true;
		$doc['meta'] = $title.($stand !== '' ? ' · '.$langs->transnoentities('WerkbankDocsStand', $stand) : '');
		$address = trim(str_replace("\n", ', ', trim((string) $soc->address)).', '.trim($soc->zip.' '.$soc->town), ', ');
		$statement = $langs->transnoentities('WerkbankDocSignSays_'.$this->rule->action, $title, $stand !== '' ? $stand : '–');
		$doc['blocks'] = array(
			array('type' => 'section', 'cap' => $langs->transnoentities('WerkbankIntakeCustomer')),
			array('type' => 'fields', 'rows' => array(
				array($langs->transnoentities('WerkbankIntakeName'), (string) $soc->name),
				array($langs->transnoentities('WerkbankCustomerCode'), (string) $soc->code_client),
				array($langs->transnoentities('WerkbankIntakeAddress'), $address, 'wide'),
			)),
			array('type' => 'section', 'cap' => $langs->transnoentities('WerkbankDocSignDocument')),
			array('type' => 'fields', 'rows' => array(
				array($langs->transnoentities('WerkbankDocSignName'), $title),
				array($langs->transnoentities('WerkbankDocSignStand'), $stand),
			)),
			array('type' => 'notices', 'rows' => array($statement)),
		);
		$labels = array(
			'technician' => $langs->transnoentities('WerkbankSignTechnician', $this->mysoc->name),
			'customer' => $langs->transnoentities('WerkbankDocSignCustomer'),
		);
		$roles = $this->rule->action === 'sign' ? array('technician', 'customer') : array('customer');
		$lines = array();
		foreach ($roles as $role) {
			$lines[$role] = isset($this->signed[$role]) ? array('label' => $labels[$role]) + $this->signed[$role] : $labels[$role];
		}
		$doc['signatures'] = count($lines) === 2 ? array('left' => $lines['technician'], 'right' => $lines['customer']) : array('right' => $lines['customer']);
		$file = WerkbankUnterlagen::dir().'/'.$slug.'.pdf';
		$doc['append'] = is_readable($file) ? array($file) : array();
		return $doc;
	}
}
