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
 * Plans the automatic e-mails. It never sends: the cron job does that after
 * the delay, so a mistake can still be cancelled on the card.
 */
class InterfaceITTabPdfAutoSend extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'other';
		$this->description = 'Plans the delayed automatic e-mails of IT-Tabelander PDF.';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'email';
	}

	/**
	 * @param string       $action Trigger code
	 * @param CommonObject $object Object
	 * @param User         $user   User
	 * @param Translate    $langs  Language
	 * @param Conf         $conf   Configuration
	 * @return int                 0 nothing done, 1 planned, -1 error
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('ittabelanderpdf')) {
			return 0;
		}
		$events = array('PROPAL_VALIDATE' => 'propal_validated', 'BILL_VALIDATE' => 'invoice_validated', 'BILL_PAYED' => 'invoice_paid');
		if (!isset($events[$action])) {
			return 0;
		}
		// A refunded credit note is "paid" too, but gets no payment confirmation.
		if ($action === 'BILL_PAYED' && (int) $object->type === Facture::TYPE_CREDIT_NOTE) {
			return 0;
		}
		dol_include_once('/ittabelanderpdf/class/werkbankmailqueue.class.php');
		$queue = new WerkbankMailQueue($this->db);
		$result = $queue->plan($object, $events[$action]);
		if ($result < 0) {
			// Never block a validation or a payment because the plan failed.
			dol_syslog('ittabelanderpdf: could not plan '.$events[$action].' for '.$object->element.' '.$object->id.': '.$queue->error, LOG_ERR);
			return 0;
		}
		return $result;
	}
}
