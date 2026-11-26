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

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Company documents per customer: an e-mail with Unterlagen attached marks
 * them "sent"; a signed proposal accepts the documents that went with it;
 * activating a contract line (hosting, maintenance) warns about or stops at
 * required documents still missing (ITTABPDF_DOCS_CONTRACT_CHECK).
 */
class InterfaceITTabPdfDocs extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'other';
		$this->description = 'Notes which company documents a customer got, accepted or still needs.';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'pdf';
	}

	/**
	 * @param string       $action Trigger code
	 * @param CommonObject $object Object
	 * @param User         $user   User
	 * @param Translate    $langs  Language
	 * @param Conf         $conf   Configuration
	 * @return int                 0 nothing, 1 noted, -1 refused
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('ittabelanderpdf')) {
			return 0;
		}
		if (substr($action, -11) === '_SENTBYMAIL' && !empty($object->attachedfiles['paths'])) {
			return $this->sent($object, $user);
		}
		if ($action === 'PROPAL_CLOSE_SIGNED') {
			dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
			$docs = new WerkbankDocs($this->db);
			return $docs->proposalSigned($object, $user) > 0 ? 1 : 0;
		}
		if ($action === 'LINECONTRACT_ACTIVATE') {
			return $this->contractLine($object, $langs);
		}
		return 0;
	}

	/**
	 * Unterlagen attached to an e-mail: "sent" for the customer of the document.
	 *
	 * @param CommonObject $object Document with attachedfiles
	 * @param User         $user   Sender
	 * @return int
	 */
	private function sent($object, $user)
	{
		dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
		$slugs = WerkbankUnterlagen::slugsIn((array) $object->attachedfiles['paths']);
		if (!$slugs) {
			return 0;
		}
		$socid = $object->element === 'societe' ? (int) $object->id : (int) (!empty($object->socid) ? $object->socid : (!empty($object->fk_soc) ? $object->fk_soc : 0));
		$docs = new WerkbankDocs($this->db);
		foreach ($slugs as $slug) {
			$docs->record($socid, $slug, 'sent', 'mail', $user, array('element' => $object->element, 'fk_object' => (int) $object->id,
				'note' => !empty($object->email_to) ? (string) $object->email_to : ''));
		}
		return 1;
	}

	/**
	 * A contract line becomes active: required documents of rules that
	 * match its product must be done, else warn or refuse.
	 *
	 * @param ContratLigne $line  Line
	 * @param Translate    $langs Language
	 * @return int
	 */
	private function contractLine($line, $langs)
	{
		$mode = getDolGlobalString('ITTABPDF_DOCS_CONTRACT_CHECK', 'warn');
		if ($mode === 'off' || empty($line->fk_product)) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
		$contract = new Contrat($this->db);
		$product = new Product($this->db);
		if ($contract->fetch((int) $line->fk_contrat) <= 0 || $product->fetch((int) $line->fk_product) <= 0) {
			return 0;
		}
		$contract->fetch_thirdparty();
		$docs = new WerkbankDocs($this->db);
		$missing = array();
		foreach ($docs->status($contract->thirdparty) as $entry) {
			$rule = $entry['rule'];
			if ((int) $rule->required && trim((string) $rule->products) !== '' && WerkbankDocs::matches((string) $product->ref, (string) $rule->products) && $entry['state'] !== 'done') {
				$missing[] = (string) $rule->label;
			}
		}
		if (!$missing) {
			return 0;
		}
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		$text = $langs->transnoentities('WerkbankDocsContractMissing', $product->ref, implode(', ', $missing));
		if ($mode === 'block') {
			$this->errors[] = $text;
			return -1;
		}
		setEventMessages($text, null, 'warnings');
		return 0;
	}
}
