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

require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

/**
 * Turns one Dolibarr object into the plain description WerkbankRenderer
 * draws. The base class holds everything documents share (company data,
 * address, lines, totals, free text); a subclass per document type only
 * chooses fields and texts.
 */
abstract class WerkbankBuilder
{
	/** @var DoliDB */
	protected $db;

	/** @var CommonObject */
	protected $object;

	/** @var Translate */
	protected $langs;

	/** @var Societe */
	protected $mysoc;

	/** @var Account|null|false  false = not looked up yet */
	private $bank = false;

	/**
	 * @param DoliDB       $db     Database
	 * @param CommonObject $object Document
	 * @param Translate    $langs  Output language
	 */
	public function __construct($db, $object, $langs)
	{
		global $mysoc;
		$this->db = $db;
		$this->object = $object;
		$this->langs = $langs;
		$this->mysoc = $mysoc;
	}

	/**
	 * Full path of the PDF to write (folder created by the caller).
	 *
	 * @return string
	 */
	abstract public function outputFile();

	/**
	 * The document description; see WerkbankRenderer.
	 *
	 * @return array<string,mixed>
	 */
	abstract public function build();

	/**
	 * Default folder layout: <dir>/<REF>/<REF>.pdf, SPECIMEN.pdf for previews.
	 *
	 * @param string $dir Output root of the module
	 * @return string
	 */
	protected function fileIn($dir)
	{
		if (!empty($this->object->specimen)) {
			return $dir.'/SPECIMEN.pdf';
		}
		$ref = dol_sanitizeFileName($this->object->ref);
		return $dir.'/'.$ref.'/'.$ref.'.pdf';
	}

	// ------------------------------------------------------------ shared parts

	/**
	 * Everything that is the same on every document, plus the recipient.
	 *
	 * @param string      $title         "Angebot" (the point is added by the renderer)
	 * @param string      $contactType   Contact type code for the address (CUSTOMER, BILLING, ...)
	 * @param string      $watermarkConst Draft watermark constant
	 * @param string      $freeTextConst Free text constant
	 * @return array<string,mixed>
	 */
	protected function base($title, $contactType, $watermarkConst, $freeTextConst)
	{
		global $user;
		$object = $this->object;
		$mysoc = $this->mysoc;
		$doc = array(
			'title' => $title,
			'subtitle' => '',
			'short_title' => $title.' '.$object->ref,
			'pdf_title' => $object->ref,
			'pdf_subject' => $title,
			'author' => is_object($user) ? $user->getFullName($this->langs) : '',
			'keywords' => $object->ref.' '.$title.(is_object($object->thirdparty) ? ' '.$object->thirdparty->name : ''),
			'logo' => $this->logoPath(),
			'company_name' => (string) $mysoc->name,
			'head_lines' => $this->headLines(),
			'sender_line' => $this->senderLine(),
			'info' => array(),
			'intro' => $this->notePublic(),
			'desc_title' => $this->langs->transnoentities('WerkbankColService'),
			'columns' => array(),
			'rows' => array(),
			'left' => array(),
			'qr' => null,
			'totals' => null,
			'freetext' => $this->freeText($freeTextConst),
			'signatures' => array(),
			'esign' => false,
			'pagesign' => false,
			'footer' => $this->footer(),
			'watermark' => '',
		);
		if ((int) $object->status === 0 && $watermarkConst !== '' && getDolGlobalString($watermarkConst)) {
			$doc['watermark'] = getDolGlobalString($watermarkConst);
		}
		list($doc['recipient_name'], $doc['recipient_address']) = $this->recipient($contactType);
		return $doc;
	}

	/**
	 * The company logo from the setup, full size for a sharp print, the
	 * small thumbnail when only that exists; '' without a logo.
	 *
	 * @return string
	 */
	protected function logoPath()
	{
		global $conf;
		if (getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO') || empty($this->mysoc->logo)) {
			return '';
		}
		$entity = isset($this->object->entity) ? $this->object->entity : $conf->entity;
		$dir = !empty($conf->mycompany->multidir_output[$entity]) ? $conf->mycompany->multidir_output[$entity] : $conf->mycompany->dir_output;
		foreach (array($dir.'/logos/'.$this->mysoc->logo, $dir.'/logos/thumbs/'.$this->mysoc->logo_small) as $candidate) {
			if (is_readable($candidate) && is_file($candidate)) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Manager, mobile (or phone), e-mail, web - right of the logo.
	 *
	 * @return string[]
	 */
	protected function headLines()
	{
		$mysoc = $this->mysoc;
		return array(
			(string) $mysoc->managers,
			$this->phone(!empty($mysoc->phone_mobile) ? $mysoc->phone_mobile : $mysoc->phone),
			(string) $mysoc->email,
			self::bareUrl((string) $mysoc->url),
		);
	}

	/**
	 * "+436766039945" -> "+43 676 6039945" for Austrian numbers, otherwise as entered.
	 *
	 * @param string $phone Phone number
	 * @return string
	 */
	public static function phone($phone)
	{
		$digits = preg_replace('/[^\d+]/', '', (string) $phone);
		if (preg_match('/^\+43(6\d\d)(\d+)$/', $digits, $match)) {
			return '+43 '.$match[1].' '.$match[2];
		}
		if (preg_match('/^\+43(\d{1,4})(\d{4,})$/', $digits, $match) && strlen((string) $phone) === strlen($digits)) {
			return '+43 '.$match[1].' '.$match[2];
		}
		return (string) $phone;
	}

	/**
	 * @param string $url URL
	 * @return string     Without scheme and trailing slash
	 */
	public static function bareUrl($url)
	{
		return rtrim((string) preg_replace('#^https?://#i', '', trim($url)), '/');
	}

	/**
	 * "IT-Tabelander · Spridrichstraße 17 · 6410 Telfs"
	 *
	 * @return string
	 */
	protected function senderLine()
	{
		$mysoc = $this->mysoc;
		$parts = array($mysoc->name, str_replace("\n", ', ', trim((string) $mysoc->address)), trim($mysoc->zip.' '.$mysoc->town));
		return implode(' · ', array_filter($parts, 'strlen'));
	}

	/**
	 * Name and address lines of the recipient through Dolibarr's own
	 * pdf_build_address(); a contact of the document becomes "z. H. ...".
	 *
	 * @param string            $contactType Contact type code
	 * @param CommonObject|null $source      Object holding the contacts (a shipment's order), default the document
	 * @return string[]                      array(name, address)
	 */
	protected function recipient($contactType, $source = null)
	{
		$object = $this->object;
		$source = is_object($source) ? $source : $object;
		$thirdparty = $object->thirdparty;
		$usecontact = false;
		if ($contactType !== '' && method_exists($source, 'getIdContact')) {
			$ids = $source->getIdContact('external', $contactType);
			if (count($ids) > 0 && $source->fetch_contact($ids[0]) > 0) {
				$usecontact = true;
			}
		}
		$contact = $usecontact ? $source->contact : '';
		$named = $thirdparty;
		if ($usecontact && $contact->socid != $thirdparty->id && (!isset($GLOBALS['conf']->global->MAIN_USE_COMPANY_NAME_OF_CONTACT) || getDolGlobalString('MAIN_USE_COMPANY_NAME_OF_CONTACT'))) {
			$named = $contact;
		}
		$name = pdfBuildThirdpartyName($named, $this->langs);
		$address = pdf_build_address($this->langs, $this->mysoc, $thirdparty, $contact, $usecontact ? 1 : 0, 'target', $object);
		$address = trim((string) $address);
		if ($usecontact) {
			$lines = explode("\n", $address);
			$lines[0] = $this->langs->transnoentities('WerkbankAttention').' '.$lines[0];
			$address = implode("\n", $lines);
		}
		if ($name === '' && $address !== '') {
			$lines = explode("\n", $address);
			$name = array_shift($lines);
			$address = implode("\n", $lines);
		}
		return array($name, $address);
	}

	/**
	 * The four footer columns.
	 *
	 * @return array<int,string[]>
	 */
	protected function footer()
	{
		$mysoc = $this->mysoc;
		$address = trim(str_replace("\n", ', ', trim((string) $mysoc->address)).', '.trim($mysoc->zip.' '.$mysoc->town), ', ');
		$ids = array();
		if (!empty($mysoc->tva_intra)) {
			$ids[] = $this->langs->transnoentities('WerkbankVatId').' '.$mysoc->tva_intra;
		}
		foreach (array('idprof4', 'idprof6') as $field) {
			if (!empty($mysoc->$field)) {
				$ids[] = $mysoc->$field;
			}
		}
		$legal = array();
		if ($ids) {
			$legal[] = implode(' · ', $ids);
		}
		if (!empty($mysoc->idprof2)) {
			$legal[] = $this->langs->transnoentities('WerkbankCourt', $mysoc->idprof2);
		}
		$bank = $this->bankAccount();
		if ($bank && !empty($bank->iban)) {
			$legal[] = 'IBAN '.self::iban($bank->iban).(!empty($bank->bic) ? ' · BIC '.$bank->bic : '');
		}
		return array(
			array_values(array_filter(array((string) $mysoc->name, (string) $mysoc->managers, $address), 'strlen')),
			array_values(array_filter(array((string) $mysoc->email, $this->phone(!empty($mysoc->phone_mobile) ? $mysoc->phone_mobile : $mysoc->phone), self::bareUrl((string) $mysoc->url)), 'strlen')),
			$legal,
		);
	}

	/**
	 * The bank account invoices name (FACTURE_RIB_NUMBER, or the one of the
	 * document), or null.
	 *
	 * @return Account|null
	 */
	protected function bankAccount()
	{
		if ($this->bank !== false) {
			return $this->bank;
		}
		$this->bank = null;
		$id = !empty($this->object->fk_account) && $this->object->element === 'facture' ? (int) $this->object->fk_account : getDolGlobalInt('FACTURE_RIB_NUMBER');
		if ($id > 0) {
			$account = new Account($this->db);
			if ($account->fetch($id) > 0) {
				$this->bank = $account;
			}
		}
		return $this->bank;
	}

	/**
	 * @param string $iban IBAN in any spacing
	 * @return string      In groups of four
	 */
	public static function iban($iban)
	{
		return trim(chunk_split(strtoupper(preg_replace('/\s+/', '', (string) $iban)), 4, ' '));
	}

	/**
	 * Dolibarr's substitution variables, as cyan and sponge apply them.
	 *
	 * @param string $text Text with __VARIABLES__
	 * @return string
	 */
	protected function substitute($text)
	{
		$substitutions = pdf_getSubstitutionArray($this->langs, null, $this->object);
		$substitutions['__FROM_NAME__'] = $this->mysoc->name;
		$substitutions['__FROM_EMAIL__'] = $this->mysoc->email;
		complete_substitutions_array($substitutions, $this->langs, $this->object);
		return make_substitutions($text, $substitutions, $this->langs);
	}

	/**
	 * The public note as HTML, '' when empty.
	 *
	 * @return string
	 */
	protected function notePublic()
	{
		$note = trim((string) $this->object->note_public);
		if ($note === '') {
			return '';
		}
		$note = convertBackOfficeMediasLinksToPublicLinks($this->substitute($note));
		return dol_htmlentitiesbr($note);
	}

	/**
	 * The free text of the document type (PROPOSAL_FREE_TEXT, ...) as HTML.
	 *
	 * @param string $const Constant name
	 * @return string
	 */
	protected function freeText($const)
	{
		if ($const === '' || !getDolGlobalString($const)) {
			return '';
		}
		$text = $this->substitute(getDolGlobalString($const));
		$text = preg_replace('/(<img.*src=")[^\"]*viewimage\.php[^\"]*modulepart=medias[^\"]*file=([^\"]*)("[^\/]*\/>)/', '\1file:/'.DOL_DATA_ROOT.'/medias/\2\3', $text);
		return dol_htmlentitiesbr($text);
	}

	// ------------------------------------------------------------ formats

	/**
	 * @param float $amount Amount
	 * @return string       "1.234,50"
	 */
	protected function money($amount)
	{
		$amount = (float) price2num($amount, 'MT');
		// A real minus sign and a thin space instead of the hyphen.
		return ($amount < 0 ? "\u{2212}\u{2009}" : '').price(abs($amount), 0, $this->langs, 0, 2, 2);
	}

	/**
	 * @param float $amount Unit price
	 * @return string       At least two decimals, more when the price has them
	 */
	protected function unitPrice($amount)
	{
		return price(price2num($amount, 'MU'), 0, $this->langs, 0, 2, -1);
	}

	/**
	 * @param float $qty Quantity
	 * @return string    "1", "0,5"
	 */
	protected function quantity($qty)
	{
		return price((float) $qty, 0, $this->langs, 0, 0, -1);
	}

	/**
	 * @param int|string $timestamp Date
	 * @return string               "24.11.2026" or ''
	 */
	protected function date($timestamp)
	{
		return empty($timestamp) ? '' : dol_print_date($timestamp, 'day', false, $this->langs, true);
	}

	/**
	 * "01.11.–30.11.2026", "24.11.2026" or ''.
	 *
	 * @param int|string $from Start
	 * @param int|string $to   End
	 * @return string
	 */
	protected function period($from, $to)
	{
		if (empty($from) && empty($to)) {
			return '';
		}
		if (empty($from) || empty($to) || dol_print_date($from, '%Y%m%d') === dol_print_date($to, '%Y%m%d')) {
			return $this->date(empty($from) ? $to : $from);
		}
		$start = dol_print_date($from, '%Y') === dol_print_date($to, '%Y') ? dol_print_date($from, '%d.%m.') : $this->date($from);
		return $start.'–'.$this->date($to);
	}

	/**
	 * @return string "EUR"
	 */
	protected function currency()
	{
		global $conf;
		// All amounts are printed in the company currency (no foreign currency columns).
		return $conf->currency;
	}

	// ------------------------------------------------------------ lines

	/**
	 * Columns of a priced table: quantity, unit price, total.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function priceColumns()
	{
		return array(
			array('key' => 'qty', 'title' => $this->langs->transnoentities('WerkbankColQty'), 'w' => 22.0),
			array('key' => 'price', 'title' => $this->langs->transnoentities('WerkbankColPrice'), 'w' => 24.0),
			array('key' => 'total', 'title' => $this->langs->transnoentities('WerkbankColTotal'), 'w' => 26.0),
		);
	}

	/**
	 * Reference, label and description of one line. A free line has no
	 * label of its own: its first text line becomes the label.
	 *
	 * @param object $line         Document line
	 * @param bool   $supplierRef  Use the supplier's reference
	 * @return array{ref:string,label:string,desc:string}
	 */
	protected function lineText($line, $supplierRef = false)
	{
		$ref = '';
		if (!empty($line->fk_product)) {
			if ($supplierRef && !empty($line->ref_supplier)) {
				$ref = $line->ref_supplier;
			} elseif ($supplierRef && !empty($line->ref_fourn)) {
				$ref = $line->ref_fourn;
			} else {
				$ref = !empty($line->product_ref) ? $line->product_ref : (isset($line->ref) ? (string) $line->ref : '');
			}
		}
		$label = !empty($line->label) ? $line->label : (!empty($line->product_label) ? $line->product_label : '');
		$desc = !empty($line->desc) ? $line->desc : (!empty($line->description) ? $line->description : '');
		if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DESC') && $label !== '') {
			$desc = '';
		}
		if (trim(dol_string_nohtmltag($desc)) === trim((string) $label)) {
			$desc = '';
		}
		if ($label === '' && $desc !== '') {
			$plain = trim(dol_string_nohtmltag(dol_htmlentitiesbr_decode((string) $desc), 0));
			$parts = preg_split('/\R/u', $plain, 2);
			$label = trim($parts[0]);
			$desc = isset($parts[1]) ? dol_htmlentitiesbr(trim($parts[1])) : '';
		} elseif ($desc !== '') {
			$desc = dol_htmlentitiesbr($desc);
		}
		return array('ref' => (string) $ref, 'label' => (string) $label, 'desc' => (string) $desc);
	}

	/**
	 * The short unit of a line when units are on ("Stk", "h").
	 *
	 * @param object $line Document line
	 * @return string
	 */
	protected function unit($line)
	{
		if (!getDolGlobalInt('PRODUCT_USE_UNITS') || empty($line->fk_unit) || !method_exists($line, 'getLabelOfUnit')) {
			return '';
		}
		return (string) $line->getLabelOfUnit('short', $this->langs, 1);
	}

	/**
	 * Rows with quantity, unit price (discount below it) and line total.
	 *
	 * @param float $sign        -1 shows a credit note with positive amounts
	 * @param bool  $supplierRef Supplier references instead of ours
	 * @return array<int,array<string,mixed>>
	 */
	protected function priceRows($sign = 1.0, $supplierRef = false)
	{
		$rows = array();
		foreach ($this->object->lines as $line) {
			$text = $this->lineText($line, $supplierRef);
			$row = $text + array('meta' => $this->lineMeta($line), 'cells' => array());
			if ((int) $line->product_type === 9 || (!empty($line->special_code) && defined('SUBTOTALS_SPECIAL_CODE') && $line->special_code == SUBTOTALS_SPECIAL_CODE)) {
				$rows[] = $row;
				continue;
			}
			$row['cells']['qty'] = array('text' => $this->quantity($line->qty), 'unit' => $this->unit($line));
			$row['cells']['price'] = array('text' => $this->unitPrice($sign * $line->subprice));
			if ((float) $line->remise_percent > 0) {
				$row['cells']['price']['note'] = $this->langs->transnoentities('WerkbankDiscount', price2num($line->remise_percent).' %');
			}
			$row['cells']['total'] = array('text' => $this->money($sign * $line->total_ht), 'bold' => true);
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Small text below a line: the service period when the line has one.
	 *
	 * @param object $line Document line
	 * @return string
	 */
	protected function lineMeta($line)
	{
		$from = isset($line->date_start) ? $line->date_start : '';
		$to = isset($line->date_end) ? $line->date_end : '';
		$period = $this->period($from, $to);
		if ($period === '') {
			return '';
		}
		$single = empty($from) || empty($to) || dol_print_date($from, '%Y%m%d') === dol_print_date($to, '%Y%m%d');
		return $this->langs->transnoentities($single ? 'WerkbankOnDate' : 'WerkbankPeriod').' '.$period;
	}

	// ------------------------------------------------------------ totals

	/**
	 * Net, VAT per rate and gross when VAT is charged; only the gross in the
	 * navy box and the small-business notice when the company charges none.
	 *
	 * @param string $grandLabel Caption of the box without currency
	 * @param float  $sign       -1 for credit notes
	 * @param bool   $sales      A sales document (the notice belongs only there)
	 * @return array<string,mixed>
	 */
	protected function taxTotals($grandLabel, $sign = 1.0, $sales = true)
	{
		$object = $this->object;
		$totals = array('rows' => array(), 'grand_label' => $grandLabel.' '.$this->currency(), 'grand' => $this->money($sign * $object->total_ttc), 'notice' => '');
		$vatFree = empty($this->mysoc->tva_assuj) && abs((float) $object->total_tva) < 0.005;
		if ($vatFree) {
			if ($sales) {
				$totals['notice'] = getDolGlobalString('ITTABPDF_TAX_NOTICE', $this->langs->transnoentities('WerkbankTaxNoticeDefault'));
			}
			return $totals;
		}
		$totals['rows'][] = array($this->langs->transnoentities('WerkbankNet'), $this->money($sign * $object->total_ht));
		$rates = array();
		foreach ($object->lines as $line) {
			$rate = (string) price2num($line->tva_tx);
			$rates[$rate] = (isset($rates[$rate]) ? $rates[$rate] : 0) + (float) $line->total_tva;
		}
		ksort($rates, SORT_NUMERIC);
		foreach ($rates as $rate => $amount) {
			if ((float) $rate == 0.0 && abs($amount) < 0.005) {
				continue;
			}
			$totals['rows'][] = array($this->langs->transnoentities('WerkbankVat', price($rate, 0, $this->langs, 0, 0, -1).' %'), $this->money($sign * $amount));
		}
		return $totals;
	}

	/**
	 * Payment term and method in one sentence-ish line, or ''.
	 *
	 * @return string
	 */
	protected function paymentText()
	{
		$object = $this->object;
		$langs = $this->langs;
		$parts = array();
		if (!empty($object->cond_reglement_code)) {
			$key = 'PaymentCondition'.$object->cond_reglement_code;
			$label = $langs->transnoentities($key) != $key ? $langs->transnoentities($key) : (!empty($object->cond_reglement_doc) ? $object->cond_reglement_doc : $object->cond_reglement_label);
			$label = str_replace('\n', "\n", (string) $label);
			if (!empty($object->deposit_percent)) {
				$label = str_replace('__DEPOSIT_PERCENT__', $object->deposit_percent, $label);
			}
			$parts[] = $label;
		}
		if (!empty($object->mode_reglement_code)) {
			$key = 'PaymentType'.$object->mode_reglement_code;
			$parts[] = $langs->transnoentities($key) != $key ? $langs->transnoentities($key) : (string) $object->mode_reglement;
		}
		return $parts ? implode(', ', $parts).'.' : '';
	}

	/**
	 * Reference of the first linked object of a type ('propal', 'commande', ...), or ''.
	 *
	 * @param string $type Element type
	 * @return string
	 */
	protected function linkedRef($type)
	{
		$object = $this->object;
		if (empty($object->linkedObjects)) {
			$object->fetchObjectLinked();
		}
		if (!empty($object->linkedObjects[$type])) {
			$linked = reset($object->linkedObjects[$type]);
			return (string) $linked->ref;
		}
		return '';
	}

	/**
	 * Customer code of the third party as an info row, or nothing.
	 *
	 * @param array<int,string[]> $info Info rows
	 * @param bool                $supplier Supplier code instead
	 * @return array<int,string[]>
	 */
	protected function withThirdpartyCode(array $info, $supplier = false)
	{
		$thirdparty = $this->object->thirdparty;
		$code = $supplier ? $thirdparty->code_fournisseur : $thirdparty->code_client;
		if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_CODE') && !empty($code)) {
			$info[] = array($this->langs->transnoentities($supplier ? 'WerkbankSupplierCode' : 'WerkbankCustomerCode'), $code);
		}
		return $info;
	}
}
