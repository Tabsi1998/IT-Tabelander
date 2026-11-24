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
 * Invoice, down payment invoice, replacement invoice and credit note.
 */
class WerkbankBuilderFacture extends WerkbankBuilder
{
	/**
	 * @return string
	 */
	public function outputFile()
	{
		global $conf;
		$entity = isset($this->object->entity) ? $this->object->entity : $conf->entity;
		return $this->fileIn($conf->facture->multidir_output[$entity]);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build()
	{
		$object = $this->object;
		$langs = $this->langs;
		$credit = ((int) $object->type === Facture::TYPE_CREDIT_NOTE);
		$titles = array(
			Facture::TYPE_CREDIT_NOTE => 'WerkbankCreditNote',
			Facture::TYPE_DEPOSIT => 'WerkbankDepositInvoice',
			Facture::TYPE_REPLACEMENT => 'WerkbankReplacementInvoice',
		);
		$title = $langs->transnoentities(isset($titles[(int) $object->type]) ? $titles[(int) $object->type] : 'WerkbankInvoice');
		$doc = $this->base($title, 'BILLING', 'FACTURE_DRAFT_WATERMARK', 'INVOICE_FREE_TEXT');
		$doc['subtitle'] = (string) $object->ref_client;

		$source = '';
		if (!empty($object->fk_facture_source)) {
			$original = new Facture($this->db);
			if ($original->fetch($object->fk_facture_source) > 0) {
				$source = $original->ref;
			}
		}
		$info = array(
			array($langs->transnoentities($credit ? 'WerkbankCreditNoteNo' : 'WerkbankInvoiceNo'), $object->ref),
			array($langs->transnoentities('WerkbankDate'), $this->date($object->date)),
		);
		if ($credit) {
			if ($source !== '') {
				$info[] = array($langs->transnoentities('WerkbankForInvoice'), $source);
			}
		} else {
			$info[] = array($langs->transnoentities('WerkbankServiceDate'), $this->serviceDate());
			$info[] = array($langs->transnoentities('WerkbankDueDate'), $this->date($object->date_lim_reglement));
		}
		$info = $this->withThirdpartyCode($info);
		if ((int) $object->type === Facture::TYPE_REPLACEMENT && $source !== '') {
			$info[] = array($langs->transnoentities('WerkbankReplaces'), $source);
		}
		$proposal = $this->linkedRef('propal');
		if ($proposal !== '') {
			$info[] = array($langs->transnoentities('WerkbankForProposal'), $proposal);
		}
		$doc['info'] = $info;

		$sign = $credit ? -1.0 : 1.0;
		$doc['columns'] = $this->priceColumns();
		$doc['rows'] = $this->priceRows($sign);
		$doc['totals'] = $this->taxTotals($langs->transnoentities('WerkbankToPay'), $sign);

		if ($credit) {
			$doc['totals']['grand_label'] = $langs->transnoentities($this->creditState()).' '.$this->currency();
			return $doc;
		}

		$open = $this->openAmount($doc['totals']);
		$bank = $this->bankAccount();
		// No bank details and no QR code on a draft: nobody should pay it yet.
		if ($open > 0 && (int) $object->status !== Facture::STATUS_DRAFT && $bank && !empty($bank->iban)) {
			$owner = !empty($bank->owner_name) ? $bank->owner_name : (!empty($bank->proprio) ? $bank->proprio : $this->mysoc->name);
			$doc['left'][] = array('cap' => $langs->transnoentities('WerkbankTransfer'), 'text' => implode("\n", array(
				$owner,
				'IBAN '.self::iban($bank->iban).(!empty($bank->bic) ? ' · BIC '.$bank->bic : ''),
				$langs->transnoentities('WerkbankReference').': '.$object->ref,
			)));
			$doc['qr'] = array('payload' => $this->epc($bank, $owner, $open), 'text' => $langs->transnoentities('WerkbankQrText'));
		} else {
			$payment = $this->paymentText();
			if ($payment !== '') {
				$doc['left'][] = array('cap' => $langs->transnoentities('WerkbankPayment'), 'text' => $payment);
			}
		}
		return $doc;
	}

	/**
	 * Date of delivery or service period (§ 11 UStG): the tax point date,
	 * else the span of the line service dates, else "as invoice date".
	 *
	 * @return string
	 */
	private function serviceDate()
	{
		$object = $this->object;
		if (!empty($object->date_pointoftax)) {
			return $this->date($object->date_pointoftax);
		}
		$dates = array();
		foreach ($object->lines as $line) {
			foreach (array('date_start', 'date_end') as $field) {
				if (!empty($line->$field)) {
					$dates[] = (int) $line->$field;
				}
			}
		}
		if ($dates) {
			return $this->period(min($dates), max($dates));
		}
		return $this->langs->transnoentities('WerkbankAsInvoiceDate');
	}

	/**
	 * Adds "already paid" rows when money came in and returns what is open.
	 *
	 * @param array<string,mixed> $totals Totals, changed in place
	 * @return float
	 */
	private function openAmount(array &$totals)
	{
		$object = $this->object;
		$langs = $this->langs;
		$total = (float) $object->total_ttc;
		$paid = (float) $object->getSommePaiement() + (float) $object->getSumDepositsUsed();
		$credits = (float) $object->getSumCreditNotesUsed();
		$open = (float) price2num($total - $paid - $credits, 'MT');
		if (!empty($object->paye)) {
			$open = 0.0;
		}
		if ($paid > 0 || $credits > 0) {
			$totals['rows'][] = array($langs->transnoentities('WerkbankInvoiceAmount'), $this->money($total));
			if ($paid > 0) {
				$totals['rows'][] = array($langs->transnoentities('WerkbankAlreadyPaid'), $this->money(-$paid));
			}
			if ($credits > 0) {
				$totals['rows'][] = array($langs->transnoentities('WerkbankCreditsUsed'), $this->money(-$credits));
			}
			$totals['grand_label'] = $langs->transnoentities('WerkbankOpen').' '.$this->currency();
			$totals['grand'] = $this->money(max(0, $open));
		}
		return $open;
	}

	/**
	 * How a credit note was settled: refunded by payment, used as a
	 * discount, or still open.
	 *
	 * @return string Language key
	 */
	private function creditState()
	{
		$object = $this->object;
		$refunded = abs((float) $object->getSommePaiement());
		if ($refunded > 0 && $refunded >= abs((float) $object->total_ttc) - 0.005) {
			return 'WerkbankRefunded';
		}
		if (!empty($object->paye)) {
			return 'WerkbankCredited';
		}
		return 'WerkbankCreditAmount';
	}

	/**
	 * EPC QR code text (GiroCode, version 002) for the open amount.
	 *
	 * @param Account $bank   Account
	 * @param string  $owner  Account holder
	 * @param float   $amount Open amount
	 * @return string
	 */
	private function epc($bank, $owner, $amount)
	{
		return implode("\n", array(
			'BCD', '002', '1', 'SCT',
			strtoupper(trim((string) $bank->bic)),
			dol_trunc((string) $owner, 70, 'right', 'UTF-8', 1),
			strtoupper(preg_replace('/\s+/', '', (string) $bank->iban)),
			'EUR'.number_format($amount, 2, '.', ''),
			'',
			'',
			dol_trunc((string) $this->object->ref, 140, 'right', 'UTF-8', 1),
		));
	}
}
