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
 * The values behind the module's __ITTAB_*__ e-mail variables: greeting,
 * the facts box of a document, bank transfer and shipment blocks.
 */
class WerkbankMailData
{
	const LABEL = 'padding:5px 0;font-family:Arial, Helvetica, sans-serif;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#4A5B6E;';
	const VALUE = 'padding:5px 0;font-family:Arial, Helvetica, sans-serif;font-size:15px;font-weight:bold;color:#1B3348;';
	const TEXT = 'margin:0 0 10px 0;font-family:Arial, Helvetica, sans-serif;font-size:14px;line-height:1.55;color:#3B4D63;';

	/**
	 * The person a document is addressed to: its contact of the usual type,
	 * else nothing (the company itself).
	 *
	 * @param CommonObject $object Document or third party
	 * @return Contact|null
	 */
	public static function contact($object)
	{
		if (!is_object($object) || !method_exists($object, 'getIdContact')) {
			return null;
		}
		$source = $object;
		$types = array($object->element === 'facture' ? 'BILLING' : 'CUSTOMER');
		if ($object->element === 'shipping' && method_exists($object, 'fetch_origin')) {
			$object->fetch_origin();
			if (is_object($object->origin_object)) {
				$source = $object->origin_object;
				$types = array('SHIPPING', 'CUSTOMER');
			}
		}
		if (in_array($object->element, array('supplier_proposal'), true)) {
			$types = array('SERVICE', 'CUSTOMER');
		}
		foreach ($types as $type) {
			$ids = $source->getIdContact('external', $type);
			if (count($ids) > 0) {
				require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
				$contact = new Contact($source->db);
				if ($contact->fetch($ids[0]) > 0) {
					return $contact;
				}
			}
		}
		return null;
	}

	/**
	 * "Hallo Maria," and "Guten Tag Frau Muster," for the document's person.
	 *
	 * @param CommonObject $object Document or third party
	 * @return string[]            array(du, sie)
	 */
	public static function greetings($object)
	{
		$contact = self::contact($object);
		if ($contact) {
			$first = trim((string) $contact->firstname);
			$last = trim((string) $contact->lastname);
			$du = $first !== '' ? 'Hallo '.$first.',' : 'Hallo,';
			$civility = strtoupper((string) $contact->civility_code);
			if ($civility === 'MR' && $last !== '') {
				$sie = 'Guten Tag Herr '.$last.',';
			} elseif (in_array($civility, array('MME', 'MLE'), true) && $last !== '') {
				$sie = 'Guten Tag Frau '.$last.',';
			} else {
				$sie = 'Guten Tag '.trim($first.' '.$last).',';
			}
			return array($du, $sie === 'Guten Tag ,' ? 'Guten Tag,' : $sie);
		}
		$thirdparty = ($object instanceof Societe) ? $object : (isset($object->thirdparty) && is_object($object->thirdparty) ? $object->thirdparty : null);
		// A private person without a contact: "Max Mustermann" is the person.
		if ($thirdparty && ((string) $thirdparty->typent_code === 'TE_PRIVATE' || (int) $thirdparty->typent_id === 8)) {
			$name = trim((string) $thirdparty->name);
			$parts = preg_split('/\s+/', $name);
			return array('Hallo '.$parts[0].',', 'Guten Tag '.$name.',');
		}
		return array('Hallo,', 'Guten Tag,');
	}

	/**
	 * The company logo for e-mails as a hosted image on white: ITTABPDF_MAIL_LOGO_URL
	 * if set, otherwise the module's public endpoint public/maillogo.php, which
	 * serves the logo of the company setup as an opaque PNG on white (images
	 * are not inverted by forced dark modes). Inline images (data: -> cid:)
	 * broke with Dolibarr's SMTPS library on mobile clients (1.0.1). Without a
	 * usable logo: the word mark.
	 *
	 * @return string HTML
	 */
	public static function logoHtml()
	{
		global $mysoc;
		static $html = null;
		if ($html !== null) {
			return $html;
		}
		$name = (string) $mysoc->name;
		$html = $name === 'IT-Tabelander' ? '<span style="color:#FE5A23;">IT</span>&#8209;TABELANDER' : dol_escape_htmltag(dol_strtoupper($name));
		$url = trim(getDolGlobalString('ITTABPDF_MAIL_LOGO_URL'));
		$shown = 240;
		$shownHeight = 0;
		if ($url === '') {
			$file = self::logoFile();
			$root = dol_buildpath('/ittabelanderpdf/public/maillogo.php', 2);
			if ($file === '' || !preg_match('#^https?://#', $root)) {
				return $html;
			}
			$size = @getimagesize($file);
			$shown = (int) ceil($size[0] / 2);
			$shownHeight = (int) ceil($size[1] / 2);
			// The version changes with the logo, so caches load the new one.
			$url = $root.'?v='.substr(md5_file($file), 0, 8);
		}
		$html = '<img src="'.dol_escape_htmltag($url).'" width="'.$shown.'"'.($shownHeight > 0 ? ' height="'.$shownHeight.'"' : '')
			.' alt="'.dol_escape_htmltag($name).'" style="display:block;border:0;outline:none;text-decoration:none;width:'.$shown.'px;max-width:100%;height:auto;background:#ffffff;">';
		return $html;
	}

	/**
	 * The logo of the company setup as an opaque PNG on white, at most 480 px
	 * wide including 16 px of white around it (shown at half size). Built once
	 * per logo file and kept in the documents folder.
	 *
	 * @return string Path of the PNG, '' without a usable logo
	 */
	public static function logoFile()
	{
		global $conf, $mysoc;
		$candidates = array();
		if (!empty($mysoc->logo)) {
			$candidates[] = $conf->mycompany->dir_output.'/logos/'.$mysoc->logo;
		}
		if (!empty($mysoc->logo_small)) {
			$candidates[] = $conf->mycompany->dir_output.'/logos/thumbs/'.$mysoc->logo_small;
		}
		$source = '';
		foreach ($candidates as $candidate) {
			if (is_readable($candidate) && is_file($candidate)) {
				$source = $candidate;
				break;
			}
		}
		if ($source === '' || !function_exists('imagecreatefromstring')) {
			return '';
		}
		$dir = DOL_DATA_ROOT.'/ittabelanderpdf';
		$cache = $dir.'/maillogo-'.md5($source.'|'.filemtime($source).'|2').'.png';
		if (is_readable($cache)) {
			return $cache;
		}
		$image = @imagecreatefromstring((string) file_get_contents($source));
		if (!$image) {
			return '';
		}
		$pad = 16;
		$width = imagesx($image);
		$height = imagesy($image);
		$inner = min(480 - 2 * $pad, $width);
		$innerHeight = (int) round($height * $inner / $width);
		$canvas = imagecreatetruecolor($inner + 2 * $pad, $innerHeight + 2 * $pad);
		imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
		imagealphablending($canvas, true);
		imagecopyresampled($canvas, $image, $pad, $pad, 0, 0, $inner, $innerHeight, $width, $height);
		dol_mkdir($dir);
		return imagepng($canvas, $cache, 9) ? $cache : '';
	}

	/**
	 * A phone number as its own tel: link in a neutral colour, so mail apps
	 * do not turn it into a blue underlined link of their own.
	 *
	 * @param string $phone Phone number as stored
	 * @return string HTML, '' without a number
	 */
	public static function phoneLink($phone)
	{
		$phone = trim((string) $phone);
		if ($phone === '') {
			return '';
		}
		$dial = preg_replace('/[^\d+]/', '', $phone);
		return '<a href="tel:'.dol_escape_htmltag($dial).'" style="color:#4A5B6E;text-decoration:none;white-space:nowrap;">'.dol_escape_htmltag(WerkbankBuilder::phone($phone)).'</a>';
	}

	/**
	 * Keeps mail apps from detecting an address: an invisible joiner after
	 * each number group.
	 *
	 * @param string $html Escaped text
	 * @return string
	 */
	public static function noAutoLink($html)
	{
		return (string) preg_replace('/(\d+)/', '$1&zwnj;', $html);
	}

	/**
	 * The light facts box around table rows: 2 px orange line on the left.
	 *
	 * @param string $rows Table rows
	 * @return string
	 */
	public static function box($rows)
	{
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F7F9FB" style="max-width:600px;background:#F7F9FB;border-left:2px solid #FE5A23;margin:4px 0 16px 0;">'
			.'<tr><td bgcolor="#F7F9FB" style="padding:10px 16px;background:#F7F9FB;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'.$rows.'</table></td></tr></table>';
	}

	/**
	 * Where the customer finds the document in the website's customer area.
	 *
	 * @param CommonObject $object Document
	 * @return string
	 */
	public static function portalUrl($object)
	{
		$base = rtrim(getDolGlobalString('ITTABPDF_PORTAL_URL', 'https://it.tabelander.co.at/kundenbereich'), '/');
		if (!is_object($object) || empty($object->id)) {
			return $base;
		}
		if ($object->element === 'propal') {
			return $base.'/angebote/'.((int) $object->id);
		}
		if ($object->element === 'facture') {
			return $base.'/rechnungen/'.((int) $object->id);
		}
		return $base;
	}

	/**
	 * What is still to pay on an invoice.
	 *
	 * @param Facture $invoice Invoice
	 * @return float
	 */
	public static function openAmount($invoice)
	{
		if (!empty($invoice->paye)) {
			return 0.0;
		}
		$paid = (float) $invoice->getSommePaiement() + (float) $invoice->getSumDepositsUsed() + (float) $invoice->getSumCreditNotesUsed();
		return (float) price2num((float) $invoice->total_ttc - $paid, 'MT');
	}

	/**
	 * @param float     $amount Amount
	 * @param Translate $langs  Language
	 * @return string           "62,70 €"
	 */
	public static function money($amount, $langs)
	{
		global $conf;
		return price(price2num($amount, 'MT'), 0, $langs, 0, 2, 2, $conf->currency);
	}

	/**
	 * The facts box: number, amount, dates - only what the document has.
	 *
	 * @param CommonObject $object Document
	 * @param Translate    $langs  Language
	 * @return string              HTML, '' for anything else
	 */
	public static function facts($object, $langs)
	{
		$langs->loadLangs(array('ittabelanderpdf@ittabelanderpdf'));
		$rows = array();
		$amount = null;
		switch ($object->element) {
			case 'propal':
				$rows[] = array('WerkbankProposalNo', $object->ref);
				$amount = array('WerkbankTotal', (float) $object->total_ttc);
				if (!empty($object->fin_validite)) {
					$rows[] = array('WerkbankValidUntil', dol_print_date($object->fin_validite, 'day', false, $langs));
				}
				break;
			case 'facture':
				$type = (int) $object->type;
				if ($type === Facture::TYPE_CREDIT_NOTE) {
					$rows[] = array('WerkbankCreditNoteNo', $object->ref);
					$amount = array('WerkbankCreditAmount', abs((float) $object->total_ttc));
					if (!empty($object->fk_facture_source)) {
						$source = new Facture($object->db);
						if ($source->fetch($object->fk_facture_source) > 0) {
							$rows[] = array('WerkbankForInvoice', $source->ref);
						}
					}
					break;
				}
				$rows[] = array($type === Facture::TYPE_DEPOSIT ? 'WerkbankDepositInvoiceNo' : 'WerkbankInvoiceNo', $object->ref);
				if (!empty($object->paye)) {
					$amount = array('WerkbankPaidAmount', (float) $object->total_ttc);
				} else {
					$open = self::openAmount($object);
					$amount = array(abs($open - (float) $object->total_ttc) > 0.005 ? 'WerkbankOpen' : 'WerkbankToPay', $open);
					if (!empty($object->date_lim_reglement)) {
						$rows[] = array('WerkbankDueDate', dol_print_date($object->date_lim_reglement, 'day', false, $langs));
					}
				}
				break;
			case 'commande':
				$rows[] = array('WerkbankOrderNo', $object->ref);
				$amount = array('WerkbankTotal', (float) $object->total_ttc);
				if (!empty($object->delivery_date)) {
					$rows[] = array('WerkbankDeliveryDate', dol_print_date($object->delivery_date, 'day', false, $langs));
				}
				break;
			case 'shipping':
				$rows[] = array('WerkbankShipmentNo', $object->ref);
				if (is_object($object->origin_object) || (method_exists($object, 'fetch_origin') && $object->fetch_origin() >= 0 && is_object($object->origin_object))) {
					$rows[] = array('WerkbankForOrder', $object->origin_object->ref);
				}
				if (!empty($object->date_delivery)) {
					$rows[] = array('WerkbankDeliveryDate', dol_print_date($object->date_delivery, 'day', false, $langs));
				}
				break;
			case 'fichinter':
				$rows[] = array('WerkbankInterventionNo', $object->ref);
				if ((int) $object->duration > 0) {
					$minutes = (int) round($object->duration / 60);
					$rows[] = array('WerkbankTotalDuration', intdiv($minutes, 60).':'.sprintf('%02d', $minutes % 60).' h');
				}
				break;
			case 'contrat':
				$rows[] = array('WerkbankContractNo', $object->ref);
				if (empty($object->array_options)) {
					$object->fetch_optionals();
				}
				$minimum = (int) ($object->array_options['options_ittab_mindestlaufzeit'] ?? 0);
				if ($minimum > 0) {
					$rows[] = array('WerkbankMinimumTerm', $langs->transnoentities($minimum === 1 ? 'WerkbankMonth' : 'WerkbankMonths', $minimum));
				}
				$notice = trim((string) ($object->array_options['options_ittab_kuendigungsfrist'] ?? ''));
				if ($notice !== '') {
					$rows[] = array('WerkbankNoticePeriod', $notice);
				}
				break;
			case 'supplier_proposal':
				$rows[] = array('WerkbankRequestNo', $object->ref);
				break;
			case 'order_supplier':
				$rows[] = array('WerkbankPurchaseOrderNo', $object->ref);
				if (!empty($object->delivery_date)) {
					$rows[] = array('WerkbankWishedDelivery', dol_print_date($object->delivery_date, 'day', false, $langs));
				}
				break;
			default:
				return '';
		}
		$html = '';
		foreach ($rows as $index => $row) {
			$html .= '<tr><td style="'.self::LABEL.'">'.dol_escape_htmltag($langs->transnoentities($row[0])).'</td>'
				.'<td align="right" style="'.self::VALUE.'">'.dol_escape_htmltag((string) $row[1]).'</td></tr>';
			if ($index === 0 && $amount !== null) {
				$html .= '<tr><td style="'.self::LABEL.'">'.dol_escape_htmltag($langs->transnoentities($amount[0])).'</td>'
					.'<td align="right" style="'.self::VALUE.'font-size:20px;">'.dol_escape_htmltag(self::money($amount[1], $langs)).'</td></tr>';
			}
		}
		return self::box($html);
	}

	/**
	 * Bank details for an open invoice, '' when nothing is to pay or no IBAN is set.
	 *
	 * @param CommonObject $object Document
	 * @param Translate    $langs  Language
	 * @return string              HTML
	 */
	public static function transfer($object, $langs)
	{
		global $mysoc;
		if ($object->element !== 'facture' || (int) $object->type === Facture::TYPE_CREDIT_NOTE || (int) $object->status === Facture::STATUS_DRAFT || self::openAmount($object) <= 0) {
			return '';
		}
		$id = !empty($object->fk_account) ? (int) $object->fk_account : getDolGlobalInt('FACTURE_RIB_NUMBER');
		if ($id <= 0) {
			return '';
		}
		$bank = new Account($object->db);
		if ($bank->fetch($id) <= 0 || empty($bank->iban)) {
			return '';
		}
		$owner = !empty($bank->owner_name) ? $bank->owner_name : (!empty($bank->proprio) ? $bank->proprio : $mysoc->name);
		$lines = array(
			$langs->transnoentities('WerkbankAccountOwner').': '.$owner,
			'IBAN: '.WerkbankBuilder::iban($bank->iban),
		);
		if (!empty($bank->bic)) {
			$lines[] = 'BIC: '.$bank->bic;
		}
		$lines[] = $langs->transnoentities('WerkbankReference').': '.$object->ref;
		$html = '';
		foreach ($lines as $line) {
			$html .= dol_escape_htmltag($line).'<br>';
		}
		return '<p style="'.self::LABEL.'margin:0 0 4px 0;padding:0;">'.dol_escape_htmltag($langs->transnoentities('WerkbankTransfer')).'</p>'
			.'<p style="'.self::TEXT.'">'.$html.'</p>';
	}

	/**
	 * Carrier and tracking link of a shipment, '' without a tracking number.
	 *
	 * @param CommonObject $object Document
	 * @param Translate    $langs  Language
	 * @return string              HTML
	 */
	public static function shipment($object, $langs)
	{
		if ($object->element !== 'shipping' || empty($object->tracking_number)) {
			return '';
		}
		$object->getUrlTrackingStatus($object->tracking_number);
		$number = dol_escape_htmltag((string) $object->tracking_number);
		$url = '';
		if (preg_match('/href="([^"]+)"/', (string) $object->tracking_url, $match)) {
			$url = $match[1];
		} elseif (preg_match('#^https?://#', trim((string) $object->tracking_url))) {
			$url = trim((string) $object->tracking_url);
		}
		$value = $url !== '' ? '<a href="'.dol_escape_htmltag($url).'" style="color:#FE5A23;">'.$number.'</a>' : $number;
		return '<p style="'.self::TEXT.'">'.dol_escape_htmltag($langs->transnoentities('WerkbankTracking')).': '.$value.'</p>';
	}
}
