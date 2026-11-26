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
	const LABEL = 'font-family:Arial, Helvetica, sans-serif;font-size:12px;line-height:26px;letter-spacing:1px;text-transform:uppercase;color:#4A5B6E;';
	const VALUE = 'float:right;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:26px;font-weight:bold;color:#1B3348;';
	const TEXT = 'margin:0 0 16px 0;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:1.6;color:#3B4D63;';

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
		$thirdparty = ($object instanceof Societe) ? $object : (isset($object->thirdparty) && is_object($object->thirdparty) ? $object->thirdparty : null);
		return self::greetingsFor(self::contact($object), $thirdparty);
	}

	/**
	 * Greetings (Du, Sie) for a contact, else for the third party.
	 *
	 * @param Contact|null $contact    Contact or null
	 * @param Societe|null $thirdparty Third party or null
	 * @return string[]                array(Du, Sie)
	 */
	public static function greetingsFor($contact, $thirdparty)
	{
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
		// A private person without a contact: "Max Mustermann" is the person.
		if ($thirdparty && ((string) $thirdparty->typent_code === 'TE_PRIVATE' || (int) $thirdparty->typent_id === 8)) {
			$name = trim((string) $thirdparty->name);
			$parts = preg_split('/\s+/', $name);
			return array('Hallo '.$parts[0].',', 'Guten Tag '.$name.',');
		}
		return array('Hallo,', 'Guten Tag,');
	}

	/**
	 * The company logo for e-mails as hosted images from public/maillogo.php
	 * (or ITTABPDF_MAIL_LOGO_URL): the light logo, an opaque PNG on white with
	 * an even margin, so a mail app that darkens the mail by force shows it as
	 * a white badge. The automatic send adds the dark logo, hidden inline and
	 * shown only by the dark-mode style of wrapDocument(); hand-sent mails
	 * carry no style, so they get the light logo alone. Without a usable
	 * logo: the word mark.
	 *
	 * @param bool $auto For the automatic send (light and dark logo)
	 * @return string HTML
	 */
	public static function logoHtml($auto = false)
	{
		global $mysoc;
		static $cache = array();
		$key = $auto ? 'auto' : 'manual';
		if (isset($cache[$key])) {
			return $cache[$key];
		}
		$name = (string) $mysoc->name;
		$wordmark = $name === 'IT-Tabelander' ? '<span style="color:#FE5A23;">IT</span>&#8209;TABELANDER' : dol_escape_htmltag(dol_strtoupper($name));
		$wordmark = '<span style="font-family:Arial, Helvetica, sans-serif;font-size:21px;font-weight:bold;letter-spacing:1px;color:#1B3348;">'.$wordmark.'</span>';
		$light = self::logoImage('light');
		if ($light === '') {
			return $cache[$key] = $wordmark;
		}
		if (!$auto) {
			return $cache[$key] = $light;
		}
		// Two images side by side (no blocks: the logo stands in a paragraph of the signature).
		$dark = self::logoImage('dark', 'ittab-logo-dark', 'display:none;max-height:0;overflow:hidden;mso-hide:all;');
		return $cache[$key] = str_replace('<img ', '<img class="ittab-logo-light" ', $light).$dark;
	}

	/**
	 * One logo as <img>, '' when there is none.
	 *
	 * @param string $variant 'light' or 'dark'
	 * @return string
	 */
	private static function logoImage($variant, $class = '', $hide = '')
	{
		global $mysoc;
		$url = trim(getDolGlobalString($variant === 'dark' ? 'ITTABPDF_MAIL_LOGO_DARK_URL' : 'ITTABPDF_MAIL_LOGO_URL'));
		// 180 px wide in the signature, as in the owner's own.
		$shown = 180;
		$shownHeight = 0;
		if ($url === '') {
			$file = self::logoFile($variant);
			$root = dol_buildpath('/ittabelanderpdf/public/maillogo.php', 2);
			if ($file === '' || !preg_match('#^https?://#', $root)) {
				return '';
			}
			$size = @getimagesize($file);
			$shownHeight = !empty($size[0]) ? (int) round($size[1] * $shown / $size[0]) : 0;
			// The version changes with the logo, so caches load the new one.
			$url = $root.'?'.($variant === 'dark' ? 'variant=dark&' : '').'v='.substr(md5_file($file), 0, 8);
		}
		return '<img'.($class !== '' ? ' class="'.$class.'"' : '').' src="'.dol_escape_htmltag($url).'" width="'.$shown.'"'.($shownHeight > 0 ? ' height="'.$shownHeight.'"' : '')
			.' alt="'.dol_escape_htmltag((string) $mysoc->name).'" style="'.$hide.'border:0;display:'.($hide !== '' ? 'none' : 'block').';height:auto;max-width:100%;width:'.$shown.'px" />';
	}

	/**
	 * A logo as PNG for e-mails, at most 480 px wide including 16 px of margin
	 * (shown at half size), built once per source file and kept in the
	 * documents folder.
	 * - light: the logo of the company setup, opaque on white;
	 * - dark: ITTABPDF_MAIL_LOGO_DARK (path below DOL_DATA_ROOT/ecm, default
	 *   "IT-Tabelander/Bilder/Logo/IT-Tabelander Banner Hell Transparent.png"),
	 *   opaque on the dark page colour #0D1823. Without that file the dark
	 *   variant is the light logo on white.
	 *
	 * @param string $variant 'light' or 'dark'
	 * @return string Path of the PNG, '' without a usable logo
	 */
	public static function logoFile($variant = 'light')
	{
		global $conf, $mysoc;
		$candidates = array();
		$background = array(255, 255, 255);
		if ($variant === 'dark') {
			$relative = getDolGlobalString('ITTABPDF_MAIL_LOGO_DARK', 'IT-Tabelander/Bilder/Logo/IT-Tabelander Banner Hell Transparent.png');
			$file = DOL_DATA_ROOT.'/ecm/'.ltrim($relative, '/');
			if (strpos($relative, '..') === false && is_readable($file) && is_file($file)) {
				$candidates[] = $file;
				$background = array(13, 24, 35);
			}
		}
		if (!$candidates) {
			if (!empty($mysoc->logo)) {
				$candidates[] = $conf->mycompany->dir_output.'/logos/'.$mysoc->logo;
			}
			if (!empty($mysoc->logo_small)) {
				$candidates[] = $conf->mycompany->dir_output.'/logos/thumbs/'.$mysoc->logo_small;
			}
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
		$cache = $dir.'/maillogo-'.md5($source.'|'.filemtime($source).'|'.implode(',', $background).'|3').'.png';
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
		imagefill($canvas, 0, 0, imagecolorallocate($canvas, $background[0], $background[1], $background[2]));
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
	 * The facts box: a light tint and a 2 px orange line on the left, no
	 * frame. Plain blocks, no table.
	 *
	 * @param string $rows Rows from factRow()
	 * @return string
	 */
	public static function box($rows)
	{
		return '<div style="margin:8px 0 24px 0;padding:12px 16px;background:#F7F9FB;border-left:2px solid #FE5A23;">'.$rows.'</div>';
	}

	/**
	 * One row of the facts box: label left, value right (floated).
	 *
	 * @param string $label Label (HTML)
	 * @param string $value Value (HTML)
	 * @param bool   $big   Show the value large (amounts)
	 * @return string
	 */
	public static function factRow($label, $value, $big = false)
	{
		return '<div style="overflow:hidden;padding:2px 0;">'
			.'<span style="'.self::VALUE.($big ? 'font-size:22px;' : '').'">'.$value.'</span>'
			.'<span style="'.self::LABEL.'">'.$label.'</span></div>';
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
		// The customer area's pages per document (website, #100/#108).
		$paths = array(
			'propal' => 'angebote',
			'facture' => 'rechnungen',
			'commande' => 'belege/auftraege',
			'shipping' => 'belege/lieferscheine',
			'fichinter' => 'belege/arbeitsberichte',
			'contrat' => 'belege/vertraege',
			'ticket' => 'anfragen',
		);
		if (isset($paths[$object->element])) {
			return $base.'/'.$paths[$object->element].'/'.((int) $object->id);
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
			case 'ticket':
				$rows[] = array('WerkbankIntakeRequest', $object->ref);
				if (!empty($object->subject)) {
					$rows[] = array('WerkbankIntakeSubject', $object->subject);
				}
				break;
			default:
				return '';
		}
		$html = '';
		foreach ($rows as $index => $row) {
			$html .= self::factRow(dol_escape_htmltag($langs->transnoentities($row[0])), dol_escape_htmltag((string) $row[1]));
			if ($index === 0 && $amount !== null) {
				$html .= self::factRow(dol_escape_htmltag($langs->transnoentities($amount[0])), dol_escape_htmltag(self::money($amount[1], $langs)), true);
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
		return '<p style="margin:0 0 2px 0;'.self::LABEL.'">'.dol_escape_htmltag($langs->transnoentities('WerkbankTransfer')).'</p>'
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
		$value = $url !== '' ? '<a href="'.dol_escape_htmltag($url).'" style="color:#FE5A23;font-weight:bold;">'.$number.'</a>' : $number;
		return '<p style="'.self::TEXT.'">'.dol_escape_htmltag($langs->transnoentities('WerkbankTracking')).': '.$value.'</p>';
	}
}
