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
 * A product or service changes (price, label, for sale, category): the
 * company documents with price placeholders get their PDF written again,
 * "Stand" today (ITTABPDF_PRICELIST_AUTO, on by default).
 */
class InterfaceITTabPdfPrices extends DolibarrTriggers
{
	const ACTIONS = array('PRODUCT_CREATE', 'PRODUCT_MODIFY', 'PRODUCT_PRICE_MODIFY', 'PRODUCT_DELETE');

	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'other';
		$this->description = 'Writes the price list PDFs again when a product changes.';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'pdf';
	}

	/**
	 * @param string       $action Trigger code
	 * @param CommonObject $object Object
	 * @param User         $user   User
	 * @param Translate    $langs  Language
	 * @param Conf         $conf   Configuration
	 * @return int                 0 nothing done, else PDFs written (never refuses)
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('ittabelanderpdf') || !getDolGlobalInt('ITTABPDF_PRICELIST_AUTO', 1)) {
			return 0;
		}
		// A product put into or taken out of a category moves it between sections.
		$category = in_array($action, array('CATEGORY_LINK', 'CATEGORY_UNLINK'), true) && isset($object->type)
			&& in_array((string) $object->type, array('0', 'product'), true);
		if (!in_array($action, self::ACTIONS, true) && !$category) {
			return 0;
		}
		dol_include_once('/ittabelanderpdf/class/werkbankpricelist.class.php');
		$written = WerkbankPricelist::refresh($this->db, $user);
		return $written > 0 ? $written : 0;
	}
}
