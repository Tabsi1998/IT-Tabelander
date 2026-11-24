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
 * Proposal (Angebot).
 */
class WerkbankBuilderPropal extends WerkbankBuilder
{
	/**
	 * @return string
	 */
	public function outputFile()
	{
		global $conf;
		$entity = isset($this->object->entity) ? $this->object->entity : $conf->entity;
		return $this->fileIn($conf->propal->multidir_output[$entity]);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build()
	{
		$object = $this->object;
		$langs = $this->langs;
		$doc = $this->base($langs->transnoentities('WerkbankProposal'), 'CUSTOMER', 'PROPALE_DRAFT_WATERMARK', 'PROPOSAL_FREE_TEXT');
		$doc['subtitle'] = (string) $object->ref_client;
		$doc['info'] = $this->withThirdpartyCode(array(
			array($langs->transnoentities('WerkbankProposalNo'), $object->ref),
			array($langs->transnoentities('WerkbankDate'), $this->date($object->date)),
			array($langs->transnoentities('WerkbankValidUntil'), $this->date($object->fin_validite)),
		));
		$doc['columns'] = $this->priceColumns();
		$doc['rows'] = $this->priceRows();
		$doc['totals'] = $this->taxTotals($langs->transnoentities('WerkbankTotal'));

		$payment = $this->paymentText();
		if ($payment !== '') {
			$doc['left'][] = array('cap' => $langs->transnoentities('WerkbankPayment'), 'text' => $payment);
		}
		$accept = getDolGlobalString('ITTABPDF_ACCEPT_TEXT', $langs->transnoentities('WerkbankAcceptDefault'));
		if ($accept !== '') {
			$doc['left'][] = array('cap' => $langs->transnoentities('WerkbankAccept'), 'text' => $accept);
		}
		if (!getDolGlobalString('PROPAL_DISABLE_SIGNATURE')) {
			$doc['signatures'] = array('right' => $langs->transnoentities('WerkbankSignCustomer'));
			$doc['esign'] = (bool) getDolGlobalString('MAIN_PDF_PROPAL_USE_ELECTRONIC_SIGNING');
			$doc['pagesign'] = true;
		}
		return $doc;
	}
}
