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

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module descriptor for IT-Tabelander PDF (Werkbank document layout).
 */
class modITTabelanderPDF extends DolibarrModules
{
	/**
	 * Document model types this module ships pdf_werkbank for (llx_document_model.type).
	 */
	const MODEL_TYPES = array('propal', 'invoice', 'order', 'shipping', 'ficheinter', 'contract', 'supplier_proposal', 'order_supplier', 'reception');

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		// Custom module id, not distributed publicly (> 500000, no reservation).
		// dolibarr-mahnwesen uses 756220.
		$this->numero = 756230;
		$this->rights_class = 'ittabelanderpdf';
		$this->family = 'other';
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleITTabelanderPDFDesc';
		$this->descriptionlong = 'ModuleITTabelanderPDFDescLong';
		$this->editor_name = 'IT-Tabelander';
		$this->editor_url = 'https://it.tabelander.co.at';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'pdf';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 1,
			'printing' => 0,
			'theme' => 0,
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@ittabelanderpdf');
		$this->hidden = false;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('ittabelanderpdf@ittabelanderpdf');
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(21, 0);

		$this->const = array(
			0 => array('ITTABPDF_ACCEPT_TEXT', 'chaine', 'Online im Kundenbereich auf it.tabelander.co.at oder per E-Mail. Gearbeitet wird erst nach Zusage.', 'How a customer accepts a proposal (Werkbank PDF)', 0, 'current', 0),
			1 => array('ITTABPDF_TAX_NOTICE', 'chaine', 'Umsatzsteuerbefreit – Kleinunternehmer gem. § 6 Abs. 1 Z 27 UStG', 'Notice below the total when the company charges no VAT (Werkbank PDF)', 0, 'current', 0),
		);

		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * Activation: constants, extra fields, and the Werkbank models switched
	 * on (not made the default; that stays a choice in each setup page).
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int<-1,1>      1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		// Contracts for ongoing services: printed in the info block of the
		// contract PDF when filled, never with a default.
		$fields = array(
			array('ittab_mindestlaufzeit', 'WerkbankExtraMinimumTerm', 'int', 100, '10', 'contrat'),
			array('ittab_kuendigungsfrist', 'WerkbankExtraNoticePeriod', 'varchar', 101, '128', 'contrat'),
		);
		foreach ($fields as $field) {
			$result = $extrafields->addExtraField($field[0], $field[1], $field[2], $field[3], $field[4], $field[5], 0, 0, '', '', 1, '', '1', '', '', '', 'ittabelanderpdf@ittabelanderpdf', 'isModEnabled("ittabelanderpdf")');
			if ($result < 0) {
				$this->error = $extrafields->error;
				return -1;
			}
		}

		$sql = array();
		foreach (self::MODEL_TYPES as $type) {
			$sql[] = "DELETE FROM ".MAIN_DB_PREFIX."document_model WHERE nom = 'werkbank' AND type = '".$this->db->escape($type)."' AND entity = ".((int) $GLOBALS['conf']->entity);
			$sql[] = "INSERT INTO ".MAIN_DB_PREFIX."document_model (nom, type, entity, libelle) VALUES ('werkbank', '".$this->db->escape($type)."', ".((int) $GLOBALS['conf']->entity).", 'werkbank')";
		}
		return $this->_init($sql, $options);
	}

	/**
	 * Deactivation keeps the models registered: documents already set to
	 * werkbank would otherwise fall back silently.
	 *
	 * @param string $options Options when disabling module
	 * @return int<-1,1>
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
