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

/**
 * Werkbank layout for sales order confirmations. The work happens in WerkbankModel; see the
 * note there on why the class is declared only once per process.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/commande/modules_commande.php';
require_once dirname(__DIR__, 4).'/class/werkbankmodel.trait.php';

if (!class_exists('pdf_werkbank', false)) {
	/**
	 * Werkbank document model.
	 */
	class pdf_werkbank extends ModelePDFCommandes
	{
		use WerkbankModel;
	}
}
