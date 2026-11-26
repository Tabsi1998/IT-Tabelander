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
		$this->version = '1.0.8';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'pdf';

		$this->module_parts = array(
			// Plans the automatic e-mails on validation and payment.
			'triggers' => 1,
			'login' => 0,
			// The __ITTAB_*__ e-mail variables.
			'substitutions' => 1,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 1,
			'printing' => 0,
			'theme' => 0,
			// Banner "automatic e-mail planned / failed" on the cards.
			'hooks' => array('propalcard', 'invoicecard'),
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

		// Automatic sending starts switched off; the owner turns each event on.
		$this->const = array(
			0 => array('ITTABPDF_ACCEPT_TEXT', 'chaine', 'Online im Kundenbereich auf it.tabelander.co.at oder per E-Mail. Gearbeitet wird erst nach Zusage.', 'How a customer accepts a proposal (Werkbank PDF)', 0, 'current', 0),
			1 => array('ITTABPDF_TAX_NOTICE', 'chaine', 'Umsatzsteuerbefreit – Kleinunternehmer gem. § 6 Abs. 1 Z 27 UStG', 'Notice below the total when the company charges no VAT (Werkbank PDF)', 0, 'current', 0),
			2 => array('ITTABPDF_AUTOSEND_PROPAL', 'chaine', '0', 'Send a validated proposal automatically', 0, 'current', 0),
			3 => array('ITTABPDF_AUTOSEND_INVOICE', 'chaine', '0', 'Send a validated invoice automatically', 0, 'current', 0),
			4 => array('ITTABPDF_AUTOSEND_PAID', 'chaine', '0', 'Send a payment confirmation when an invoice is paid', 0, 'current', 0),
			5 => array('ITTABPDF_AUTOSEND_DELAY', 'chaine', '5', 'Minutes between the event and the automatic e-mail', 0, 'current', 0),
			6 => array('ITTABPDF_PORTAL_URL', 'chaine', 'https://it.tabelander.co.at/kundenbereich', 'Customer area of the website', 0, 'current', 0),
			// The website's customer area is not live yet: off until the owner switches it on.
			7 => array('ITTABPDF_PORTAL_ENABLED', 'yesno', '0', 'Customer area of the website is live', 0, 'current', 0),
			9 => array('ITTABPDF_MAIL_LOGO_DARK', 'chaine', 'IT-Tabelander/Bilder/Logo/IT-Tabelander Banner Hell Transparent.png', 'Logo for dark mode e-mails, path below documents/ecm', 0, 'current', 0),
			8 => array('ITTABPDF_ACCEPT_TEXT_NOPORTAL', 'chaine', 'Bitte kurz per E-Mail an __MYCOMPANY_EMAIL__ bestätigen. Gearbeitet wird erst nach Zusage.', 'How a customer accepts a proposal without the customer area (Werkbank PDF)', 0, 'current', 0),
		);

		// Every 5 minutes: send the automatic e-mails that are due.
		$this->cronjobs = array(
			0 => array(
				'label' => 'IT-Tabelander PDF: automatic e-mails',
				'jobtype' => 'method',
				'class' => '/ittabelanderpdf/class/werkbankmailqueue.class.php',
				'objectname' => 'WerkbankMailQueue',
				'method' => 'runCron',
				'parameters' => '',
				'comment' => 'Sends the planned proposal, invoice and payment e-mails once their delay is over.',
				'frequency' => 5,
				'unitfrequency' => 60,
				'status' => 1,
				'test' => 'isModEnabled("ittabelanderpdf")',
				'priority' => 50,
			),
		);

		$this->rights = array();
		$this->menu = array();
		$this->menu[0] = array(
			'fk_menu' => 'fk_mainmenu=billing',
			'type' => 'left',
			'titre' => 'WerkbankAutoTitle',
			'prefix' => img_picto('', 'email', 'class="pictofixedwidth"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'ittabpdf_queue',
			'url' => '/ittabelanderpdf/queue.php',
			'langs' => 'ittabelanderpdf@ittabelanderpdf',
			'position' => 1900,
			'enabled' => 'isModEnabled("ittabelanderpdf")',
			'perms' => '$user->hasRight("facture", "lire") || $user->hasRight("propal", "lire")',
			'target' => '',
			'user' => 0,
		);
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
		include_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		// Contracts for ongoing services: printed in the info block of the
		// contract PDF when filled, never with a default.
		// The third party's form of address for the automatic e-mails: 0 = Sie.
		$fields = array(
			array('ittab_mindestlaufzeit', 'WerkbankExtraMinimumTerm', 'int', 100, '10', 'contrat', ''),
			array('ittab_kuendigungsfrist', 'WerkbankExtraNoticePeriod', 'varchar', 101, '128', 'contrat', ''),
			array('ittab_du', 'WerkbankExtraDu', 'boolean', 100, '', 'societe', '0'),
		);
		foreach ($fields as $field) {
			$result = $extrafields->addExtraField($field[0], $field[1], $field[2], $field[3], $field[4], $field[5], 0, 0, $field[6], '', 1, '', '1', '', '', '', 'ittabelanderpdf@ittabelanderpdf', 'isModEnabled("ittabelanderpdf")');
			if ($result < 0) {
				$this->error = $extrafields->error;
				return -1;
			}
		}

		// Dolibarr 23: enabling Categories creates the category link tables only for
		// invoices and orders (modCategorie::init). On installs that never ran the
		// full migration, proposal/intervention/supplier-proposal cards then fail
		// with "Table llx_categorie_propal doesn't exist" (seen 24.11.2026). Load
		// Dolibarr's own table files for them; run_sql skips tables that exist.
		if (isModEnabled('categorie')) {
			foreach (array('propal' => 'propal', 'fichinter' => 'ficheinter', 'supplier_proposal' => 'supplier_proposal') as $suffix => $module) {
				if (isModEnabled($module)) {
					$this->_load_tables('/install/mysql/', $suffix);
				}
			}
		}

		if ($this->_load_tables('/ittabelanderpdf/sql/') < 0) {
			return -1;
		}
		dol_include_once('/ittabelanderpdf/class/werkbankmailtemplates.class.php');
		if (WerkbankMailTemplates::install($this->db, (int) $GLOBALS['conf']->entity) < 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		// Templates still holding an earlier default text get the current one;
		// edited ones stay, the setup page offers to replace them.
		$upgrade = WerkbankMailTemplates::upgrade($this->db, (int) $GLOBALS['conf']->entity);
		if ($upgrade === -1) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		// BCC of the automatic e-mails: the company address unless set.
		if (!getDolGlobalString('ITTABPDF_AUTOSEND_BCC') && !empty($GLOBALS['mysoc']->email)) {
			dolibarr_set_const($this->db, 'ITTABPDF_AUTOSEND_BCC', $GLOBALS['mysoc']->email, 'chaine', 0, '', $GLOBALS['conf']->entity);
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
