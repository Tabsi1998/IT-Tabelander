<?php
/*
 * Test data and sample PDFs for the disposable Dolibarr of scripts/dev_dolibarr.py.
 *
 *   php fixtures.php base     modules and constants (own process: Dolibarr reads
 *                             the module folders only at start)
 *   php fixtures.php setup    company like production, bank account, customers,
 *                             products and every sample document
 *   php fixtures.php render   build every sample document with werkbank into /tmp/ittpdf-out
 *   php fixtures.php const NAME VALUE   set one constant (to try a variant)
 *
 * Never run against a real Dolibarr: it rewrites the company setup.
 */

require __DIR__.'/bootstrap.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

const RT_STATE = '/tmp/ittpdf-docs.json';
const RT_OUT = '/tmp/ittpdf-out';

$stage = $argv[1] ?? '';
$admin = rt_admin($db);
$user = $admin;
$GLOBALS['user'] = $admin;
$langs->setDefaultLang('de_DE');
$langs->loadLangs(array('main', 'bills', 'propal', 'companies'));

/** Timestamp of a date at noon, server time. */
function rt_day($text)
{
	list($year, $month, $day) = array_map('intval', explode('-', $text));
	return dol_mktime(12, 0, 0, $month, $day, $year);
}

/** A product or service with a unit. */
function rt_product($db, $admin, $ref, $label, $price, $desc = '', $unit = 'P', $type = 1, array $extra = array())
{
	$product = new Product($db);
	$product->ref = $ref;
	$product->label = $label;
	$product->description = $desc;
	$product->type = $type;
	$product->price = $price;
	$product->price_base_type = 'HT';
	$product->tva_tx = 0;
	$product->status = 1;
	$product->status_buy = 1;
	$product->fk_unit = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_units WHERE code = '".$db->escape($unit)."'");
	foreach ($extra as $field => $value) {
		$product->$field = $value;
	}
	if ($product->create($admin) <= 0) {
		rt_fail('product '.$ref.': '.$product->error.' '.implode(',', $product->errors));
	}
	return $product;
}

/** Add product lines to a proposal or an invoice: array(product|null, qty, discount, desc, date_start, date_end). */
function rt_lines($object, array $lines)
{
	foreach ($lines as $line) {
		list($product, $qty) = $line;
		$discount = $line[2] ?? 0;
		$desc = $line[3] ?? ($product ? $product->description : '');
		$start = $line[4] ?? '';
		$end = $line[5] ?? '';
		$price = $product ? $product->price : ($line[6] ?? 0);
		$fkProduct = $product ? $product->id : 0;
		$type = $product ? $product->type : 1;
		$unit = $product ? $product->fk_unit : null;
		if ($object instanceof Propal) {
			$result = $object->addline($desc, $price, $qty, 0, 0, 0, $fkProduct, $discount, 'HT', 0, 0, $type, -1, 0, 0, 0, 0, '', $start, $end, array(), $unit);
		} else {
			$result = $object->addline($desc, $price, $qty, 0, 0, 0, $fkProduct, $discount, $start, $end, 0, 0, 0, 'HT', 0, $type, -1, 0, '', 0, 0, null, 0, '', array(), 100, 0, $unit);
		}
		if ($result <= 0) {
			rt_fail('line on '.$object->element.': '.$object->error.' '.implode(',', (array) $object->errors));
		}
	}
}

/** A validated (or draft) invoice. */
function rt_invoice($db, $admin, $socid, array $fields, array $lines, $validate = true)
{
	$invoice = new Facture($db);
	$invoice->socid = $socid;
	$invoice->type = Facture::TYPE_STANDARD;
	$invoice->date = rt_day('2026-11-24');
	$invoice->cond_reglement_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_payment_term WHERE code = '30D'");
	$invoice->mode_reglement_id = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = 'VIR'");
	foreach ($fields as $field => $value) {
		$invoice->$field = $value;
	}
	if ($invoice->create($admin) <= 0) {
		rt_fail('invoice: '.$invoice->error.' '.implode(',', (array) $invoice->errors));
	}
	rt_lines($invoice, $lines);
	if ($validate && $invoice->validate($admin) <= 0) {
		rt_fail('validate invoice: '.$invoice->error.' '.implode(',', (array) $invoice->errors));
	}
	$invoice->fetch($invoice->id);
	return $invoice;
}

/** A payment of $amount on an invoice into the test account. */
function rt_pay($db, $admin, $invoice, $amount, $accountId, $date)
{
	$payment = new Paiement($db);
	$payment->datepaye = rt_day($date);
	$payment->amounts = array($invoice->id => $amount);
	$payment->paiementid = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = 'VIR'");
	$payment->paiementcode = 'VIR';
	if ($payment->create($admin, 1) <= 0) {
		rt_fail('payment: '.$payment->error);
	}
	if ($payment->addPaymentToBank($admin, 'payment', '(CustomerInvoicePayment)', $accountId, '', '') <= 0) {
		rt_fail('payment to bank: '.$payment->error);
	}
}

if ($stage === 'base') {
	rt_const($db, 'MAIN_LANG_DEFAULT', 'de_DE');
	rt_const($db, 'MAIN_MONNAIE', 'EUR');
	rt_modules(array('modSociete', 'modPropale', 'modFacture', 'modBanque', 'modProduct', 'modService', 'modStock',
		'modCommande', 'modExpedition', 'modFicheinter', 'modContrat', 'modFournisseur', 'modSupplierProposal', 'modReception',
		'modAgenda', 'modCron', 'modITTabelanderPDF'));
	// Every e-mail goes to Mailpit.
	rt_const($db, 'MAIN_MAIL_SENDMODE', 'smtps');
	rt_const($db, 'MAIN_MAIL_SMTP_SERVER', 'mail');
	rt_const($db, 'MAIN_MAIL_SMTP_PORT', '1025');
	rt_const($db, 'MAIN_MAIL_EMAIL_TLS', '0');
	rt_const($db, 'MAIN_MAIL_EMAIL_STARTTLS', '0');
	rt_const($db, 'MAIN_DISABLE_ALL_MAILS', '0');
	rt_const($db, 'MAIN_MAIL_EMAIL_FROM', 'office@tabelander.co.at');
	// Werkbank as the default, as the owner sets it after activation.
	foreach (array('PROPALE_ADDON_PDF', 'FACTURE_ADDON_PDF', 'COMMANDE_ADDON_PDF', 'EXPEDITION_ADDON_PDF', 'FICHEINTER_ADDON_PDF',
		'CONTRACT_ADDON_PDF', 'SUPPLIER_PROPOSAL_ADDON_PDF', 'COMMANDE_SUPPLIER_ADDON_PDF', 'RECEPTION_ADDON_PDF') as $const) {
		rt_const($db, $const, 'werkbank');
	}
	rt_const($db, 'STOCK_ALLOW_NEGATIVE_TRANSFER', '1');
	echo json_encode(array('base' => 'ok')), "\n";
	exit(0);
}

if ($stage === 'setup') {
	// One transaction: a failed run leaves nothing behind and can simply run again.
	$db->begin();

	// Company as in production (erp.tabelander.co.at), but a sample IBAN.
	rt_const($db, 'MAIN_INFO_SOCIETE_NOM', 'IT-Tabelander');
	rt_const($db, 'MAIN_INFO_SOCIETE_ADDRESS', 'Spridrichstraße 17');
	rt_const($db, 'MAIN_INFO_SOCIETE_ZIP', '6410');
	rt_const($db, 'MAIN_INFO_SOCIETE_TOWN', 'Telfs');
	rt_const($db, 'MAIN_INFO_SOCIETE_COUNTRY', '41:AT:Österreich');
	rt_const($db, 'MAIN_INFO_SOCIETE_MANAGERS', 'Fabian Tabelander');
	rt_const($db, 'MAIN_INFO_SOCIETE_MOBILE', '+436766039945');
	rt_const($db, 'MAIN_INFO_SOCIETE_MAIL', 'office@tabelander.co.at');
	rt_const($db, 'MAIN_INFO_SOCIETE_WEB', 'https://it.tabelander.co.at');
	rt_const($db, 'MAIN_INFO_SIRET', 'Innsbruck');
	rt_const($db, 'MAIN_INFO_RCS', 'GISA-Zahl: 39417879');
	rt_const($db, 'MAIN_INFO_PROFID6', 'GLN: 9110039041378');
	rt_const($db, 'FACTURE_TVAOPTION', '0');
	rt_const($db, 'PRODUCT_USE_UNITS', '1');
	rt_const($db, 'INVOICE_POINTOFTAX_DATE', '1');
	rt_const($db, 'FACTURE_DRAFT_WATERMARK', 'ENTWURF');
	rt_const($db, 'PROPALE_DRAFT_WATERMARK', 'ENTWURF');
	rt_const($db, 'MAIN_PDF_PROPAL_USE_ELECTRONIC_SIGNING', '1');
	rt_const($db, 'INVOICE_FREE_TEXT', 'Umsatzsteuerbefreit – Kleinunternehmer gem. § 6 Abs. 1 Z 27 UStG. Fragen zur Rechnung: __FROM_EMAIL__');

	// Units as the owner keeps them: "Stk" for pieces and a kilometre unit.
	rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."c_units SET short_label = 'Stk' WHERE code = 'P'");
	if (!rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_units WHERE code = 'KM'")) {
		rt_exec($db, "INSERT INTO ".MAIN_DB_PREFIX."c_units (code, sortorder, scale, label, short_label, unit_type, active) VALUES ('KM', 205, 3, 'Kilometer', 'km', 'size', 1)");
	}

	// Logo, with Dolibarr's own thumbnails.
	$logoDir = $conf->mycompany->dir_output.'/logos';
	dol_mkdir($logoDir.'/thumbs');
	copy(__DIR__.'/logo.png', $logoDir.'/it-tabelander.png');
	vignette($logoDir.'/it-tabelander.png', 160, 120, '_small', 50, 'thumbs');
	vignette($logoDir.'/it-tabelander.png', 64, 64, '_mini', 50, 'thumbs');
	rt_const($db, 'MAIN_INFO_SOCIETE_LOGO', 'it-tabelander.png');
	rt_const($db, 'MAIN_INFO_SOCIETE_LOGO_SMALL', 'it-tabelander_small.png');
	rt_const($db, 'MAIN_INFO_SOCIETE_LOGO_MINI', 'it-tabelander_mini.png');

	$account = new Account($db);
	$account->ref = 'GIRO';
	$account->label = 'Geschäftskonto';
	$account->courant = Account::TYPE_CURRENT;
	$account->bank = 'Beispielbank';
	$account->iban = 'AT611904300234573201';
	$account->bic = 'BKAUATWW';
	$account->owner_name = 'Fabian Tabelander';
	$account->proprio = 'Fabian Tabelander';
	$account->currency_code = 'EUR';
	$account->country_id = 41;
	$account->date_solde = dol_now();
	if ($account->create($admin) <= 0) {
		rt_fail('bank account: '.$account->error);
	}
	rt_const($db, 'FACTURE_RIB_NUMBER', $account->id);

	$firm = new Societe($db);
	$firm->name = 'Musterfirma GmbH';
	$firm->client = 1;
	$firm->code_client = 'CU2611-00006';
	$firm->address = 'Hauptstraße 12';
	$firm->zip = '6020';
	$firm->town = 'Innsbruck';
	$firm->country_id = 41;
	$firm->email = 'office@musterfirma.example';
	if ($firm->create($admin) <= 0) {
		rt_fail('customer: '.$firm->error);
	}
	$contact = new Contact($db);
	$contact->socid = $firm->id;
	$contact->firstname = 'Maria';
	$contact->lastname = 'Muster';
	$contact->civility_code = 'MME';
	$contact->email = 'maria.muster@musterfirma.example';
	if ($contact->create($admin) <= 0) {
		rt_fail('contact: '.$contact->error);
	}
	$private = new Societe($db);
	$private->name = 'Max Mustermann';
	$private->client = 1;
	$private->code_client = 'CU2611-00007';
	$private->address = 'Untermarktstraße 5';
	$private->zip = '6410';
	$private->town = 'Telfs';
	$private->country_id = 41;
	$private->email = 'max@mustermann.example';
	$private->typent_id = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_typent WHERE code = 'TE_PRIVATE'");
	// Max is addressed with "Du" (module extra field).
	$private->array_options = array('options_ittab_du' => 1);
	if ($private->create($admin) <= 0) {
		rt_fail('customer: '.$private->error);
	}

	$p = array();
	$p['ssd'] = rt_product($db, $admin, 'DL-HW-SSD', 'SSD-Upgrade inkl. Systemumzug', 49.90, 'Einbau der neuen SSD und Umzug des bestehenden Systems mit allen Programmen und Daten. SSD wird separat verrechnet.');
	$p['mat'] = rt_product($db, $admin, 'MATERIAL', 'Samsung SSD 990 PRO, 1 TB NVMe', 124.90, 'Modell MZ-V9P1T0BW, PCIe 4.0', 'P', 0);
	$p['win'] = rt_product($db, $admin, 'DL-PC-WIN', 'Windows-Neuinstallation inkl. Treiber und Updates', 69.90, 'Neuinstallation, aktuelle Treiber und Updates, Grundeinrichtung (Benutzerkonto, Browser, Sicherheit). Windows-Lizenz nicht enthalten.');
	$p['daten'] = rt_product($db, $admin, 'DL-PC-DATEN', 'Datenübernahme vom Altgerät', 49.90, 'Sicherung und Übertragung der persönlichen Daten auf das neu installierte System.');
	$p['std'] = rt_product($db, $admin, 'DL-STD-WERK', 'Werkstattstunde (Hardware, Controller)', 45.00, 'Arbeitszeit, die nicht in einer Pauschale enthalten ist.', 'H');
	$p['km'] = rt_product($db, $admin, 'DL-ANFAHRT-KM', 'Kilometergeld je weiterem km', 0.50, '', 'KM');
	$p['akku'] = rt_product($db, $admin, 'DL-PS5-AKKU', 'Akkuwechsel PS5 Controller inkl. Funktionstest', 24.90, 'Austausch des Controller-Akkus inkl. Öffnen, Zusammenbau, Lade- und Funktionstest.');
	$p['akkumat'] = rt_product($db, $admin, 'MAT-PS5-AKKU-2600', 'Akku-Upgrade PS5 DualSense – 2600 mAh', 29.90, '', 'P', 0);
	$p['versand'] = rt_product($db, $admin, 'DL-VERSAND', 'Rückversand innerhalb Österreichs', 7.90);
	$p['wartung'] = rt_product($db, $admin, 'DL-WARTUNG-M', 'IT-Betreuung Kleinbüro, monatlich', 89.00, 'Updates, Datensicherung prüfen, Fernwartung bis 1 Stunde im Monat.', 'P', 1, array('duration_value' => 1, 'duration_unit' => 'm'));
	$p['hosting'] = rt_product($db, $admin, 'DL-HOSTING-J', 'Webhosting mit Domain, jährlich', 120.00, 'Speicher 10 GB, SSL-Zertifikat, tägliche Sicherung.', 'P', 1, array('duration_value' => 1, 'duration_unit' => 'y'));

	$term14 = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_payment_term WHERE code = '30D'");
	$vir = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = 'VIR'");
	$docs = array();

	// (1) The proposal of the approved design.
	$prop = new Propal($db);
	$prop->socid = $firm->id;
	$prop->date = rt_day('2026-11-24');
	$prop->duree_validite = 14;
	$prop->cond_reglement_id = $term14;
	$prop->mode_reglement_id = $vir;
	$prop->ref_client = 'PC-Reparatur nach Überspannung';
	$prop->note_public = 'Bei der Überprüfung wurde ein Überspannungsschaden festgestellt. Die folgenden Arbeiten bringen das System wieder in einen sauberen, getesteten Zustand.';
	if ($prop->create($admin) <= 0) {
		rt_fail('proposal: '.$prop->error);
	}
	rt_lines($prop, array(array($p['ssd'], 1), array($p['mat'], 1), array($p['win'], 1), array($p['daten'], 1), array($p['std'], 0.5), array($p['km'], 14)));
	$prop->add_contact($contact->id, 'CUSTOMER', 'external');
	$prop->valid($admin);
	$docs['angebot-1-wie-entwurf'] = array('propal', $prop->id);
	$proposalOne = $prop;

	// (2) Many lines over several pages, a discount and a free line.
	$prop = new Propal($db);
	$prop->socid = $firm->id;
	$prop->date = rt_day('2026-11-24');
	$prop->duree_validite = 30;
	$prop->cond_reglement_id = $term14;
	$prop->mode_reglement_id = $vir;
	$prop->ref_client = 'Neuausstattung Büro, 8 Arbeitsplätze';
	$prop->note_public = "Wie besprochen: acht Arbeitsplätze, ein kleiner Server für Dateien und Sicherung, das Netzwerk dazu.\nAlles wird vor der Lieferung bei uns aufgesetzt und getestet.";
	if ($prop->create($admin) <= 0) {
		rt_fail('proposal: '.$prop->error);
	}
	$lines = array();
	$cycle = array('ssd', 'mat', 'win', 'daten', 'std', 'akku', 'versand');
	for ($i = 0; $i < 23; $i++) {
		$key = $cycle[$i % count($cycle)];
		$lines[] = array($p[$key], 1 + ($i % 3), $i === 4 ? 10 : 0);
	}
	rt_lines($prop, $lines);
	rt_lines($prop, array(array(null, 1, 0, "Pauschale Projektleitung\nAbstimmung, Planung und Übergabe an einem Termin vor Ort.", '', '', 150.00)));
	rt_lines($prop, array(array($p['km'], 28, 5)));
	$prop->add_contact($contact->id, 'CUSTOMER', 'external');
	$prop->valid($admin);
	$docs['angebot-2-mehrseitig'] = array('propal', $prop->id);

	// (3) Invoice with QR code, linked to proposal 1; service date = invoice date.
	$inv = rt_invoice($db, $admin, $private->id, array('note_public' => 'Vielen Dank für den Auftrag. Bitte den Betrag unter Angabe der Rechnungsnummer überweisen.'),
		array(array($p['akku'], 1), array($p['akkumat'], 1), array($p['versand'], 1)));
	$inv->add_object_linked('propal', $proposalOne->id);
	$docs['rechnung-1-qr'] = array('facture', $inv->id);
	$invoiceOne = $inv;

	// (4) Partly paid, service period from the line dates.
	$inv = rt_invoice($db, $admin, $firm->id, array('ref_client' => 'Betreuung November'), array(
		array($p['wartung'], 1, 0, '', rt_day('2026-11-01'), rt_day('2026-11-30')),
		array($p['std'], 2.5, 0, 'Einrichtung neuer Drucker im Empfang.', rt_day('2026-11-12'), rt_day('2026-11-12')),
	));
	rt_pay($db, $admin, $inv, 100.00, $account->id, '2026-11-26');
	$docs['rechnung-2-teilzahlung'] = array('facture', $inv->id);

	// (5) Down payment invoice with a tax point date.
	$inv = rt_invoice($db, $admin, $firm->id, array('type' => Facture::TYPE_DEPOSIT, 'date_pointoftax' => rt_day('2026-11-20'), 'ref_client' => 'Neuausstattung Büro'),
		array(array(null, 1, 0, "Anzahlung 30 %\nZum Angebot Neuausstattung Büro, 8 Arbeitsplätze.", '', '', 900.00)));
	$docs['rechnung-3-anzahlung'] = array('facture', $inv->id);

	// (6) Credit note for invoice 3, open.
	$credit = rt_invoice($db, $admin, $private->id, array('type' => Facture::TYPE_CREDIT_NOTE, 'fk_facture_source' => $invoiceOne->id, 'note_public' => 'Der Rückversand entfällt, der Controller wurde abgeholt.'),
		array(array($p['versand'], 1)));
	$docs['gutschrift-1-offen'] = array('facture', $credit->id);

	// (7) Credit note refunded by bank transfer.
	$credit = rt_invoice($db, $admin, $firm->id, array('type' => Facture::TYPE_CREDIT_NOTE, 'fk_facture_source' => $invoiceOne->id),
		array(array($p['std'], 0.5, 0, 'Zu viel verrechnete Arbeitszeit.')));
	rt_pay($db, $admin, $credit, -22.50, $account->id, '2026-11-27');
	$credit->setPaid($admin);
	$docs['gutschrift-2-erstattet'] = array('facture', $credit->id);

	// (8) Replacement invoice for a wrong invoice.
	$wrong = rt_invoice($db, $admin, $private->id, array(), array(array($p['akku'], 2)));
	$repl = rt_invoice($db, $admin, $private->id, array('type' => Facture::TYPE_REPLACEMENT, 'fk_facture_source' => $wrong->id), array(array($p['akku'], 1)), false);
	if ($repl->validate($admin) <= 0) {
		rt_fail('replacement: '.$repl->error.' '.implode(',', (array) $repl->errors));
	}
	$docs['rechnung-4-ersatz'] = array('facture', $repl->id);

	// (9) A draft with the watermark.
	$draft = rt_invoice($db, $admin, $firm->id, array('ref_client' => 'Notebook-Reinigung'), array(array($p['std'], 1, 0, 'Lüfter gereinigt, Wärmeleitpaste erneuert.')), false);
	$docs['entwurf-rechnung'] = array('facture', $draft->id);

	// ---------------------------------------------------------------- phase 2
	require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
	require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
	require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
	require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
	require_once DOL_DOCUMENT_ROOT.'/supplier_proposal/class/supplier_proposal.class.php';
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
	require_once DOL_DOCUMENT_ROOT.'/reception/class/reception.class.php';
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.dispatch.class.php';
	require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';

	$warehouse = new Entrepot($db);
	$warehouse->label = 'Werkstatt';
	$warehouse->ref = 'Werkstatt';
	$warehouse->statut = 1;
	if ($warehouse->create($admin) <= 0) {
		rt_fail('warehouse: '.$warehouse->error);
	}

	// (10) Order confirmation from proposal 1, with a delivery date.
	$order = new Commande($db);
	$order->socid = $firm->id;
	$order->date = rt_day('2026-11-25');
	$order->delivery_date = rt_day('2026-12-02');
	$order->ref_client = 'PC-Reparatur nach Überspannung';
	$order->cond_reglement_id = $term14;
	$order->mode_reglement_id = $vir;
	if ($order->create($admin) <= 0) {
		rt_fail('order: '.$order->error.' '.implode(',', (array) $order->errors));
	}
	foreach (array(array($p['ssd'], 1), array($p['mat'], 1), array($p['win'], 1), array($p['daten'], 1)) as $line) {
		if ($order->addline($line[0]->description, $line[0]->price, $line[1], 0, 0, 0, $line[0]->id, 0, 0, 0, 'HT', 0, '', '', $line[0]->type, -1, 0, 0, null, 0, '', array(), $line[0]->fk_unit) <= 0) {
			rt_fail('order line: '.$order->error);
		}
	}
	$order->add_object_linked('propal', $proposalOne->id);
	$order->add_contact($contact->id, 'SHIPPING', 'external');
	if ($order->valid($admin) <= 0) {
		rt_fail('validate order: '.$order->error.' '.implode(',', (array) $order->errors));
	}
	$order->fetch($order->id);
	$docs['auftrag-1'] = array('commande', $order->id);

	// (11) Delivery note for the SSD of that order.
	$shipment = new Expedition($db);
	$shipment->socid = $firm->id;
	$shipment->origin = 'commande';
	$shipment->origin_type = 'commande';
	$shipment->origin_id = $order->id;
	$shipment->date_delivery = rt_day('2026-12-02');
	$shipment->date_shipping = rt_day('2026-12-01');
	$shipment->shipping_method_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_shipment_mode WHERE code = 'COLSUI'");
	$shipment->tracking_number = '1Z999AA10123456784';
	$shipment->ref_customer = $order->ref_client;
	foreach ($order->lines as $orderLine) {
		if ((int) $orderLine->product_type === 0) {
			$shipment->addline($warehouse->id, $orderLine->id, 1);
		}
	}
	if ($shipment->create($admin) <= 0) {
		rt_fail('shipment: '.$shipment->error.' '.implode(',', (array) $shipment->errors));
	}
	if ($shipment->valid($admin) <= 0) {
		rt_fail('validate shipment: '.$shipment->error.' '.implode(',', (array) $shipment->errors));
	}
	$docs['lieferschein-1'] = array('shipping', $shipment->id);

	// (12) Contract with minimum term and notice period, mixed billing intervals.
	$contract = new Contrat($db);
	$contract->socid = $firm->id;
	$contract->date_contrat = rt_day('2026-11-24');
	$contract->commercial_signature_id = $admin->id;
	$contract->commercial_suivi_id = $admin->id;
	$contract->ref_customer = 'IT-Betreuung und Hosting';
	$contract->note_public = 'Laufende Betreuung des Büros und Hosting der Website. Verrechnet wird über wiederkehrende Rechnungen.';
	$contract->array_options = array('options_ittab_mindestlaufzeit' => 12, 'options_ittab_kuendigungsfrist' => '1 Monat zum Monatsende');
	if ($contract->create($admin) <= 0) {
		rt_fail('contract: '.$contract->error.' '.implode(',', (array) $contract->errors));
	}
	$contract->addline($p['wartung']->description, 89.00, 1, 0, 0, 0, $p['wartung']->id, 0, rt_day('2027-01-01'), rt_day('2027-12-31'), 'HT', 0, 0, null, 0, array(), $p['wartung']->fk_unit);
	$contract->addline($p['hosting']->description, 120.00, 1, 0, 0, 0, $p['hosting']->id, 0, rt_day('2027-01-01'), '', 'HT', 0, 0, null, 0, array(), $p['hosting']->fk_unit);
	if ($contract->validate($admin) <= 0) {
		rt_fail('validate contract: '.$contract->error);
	}
	$docs['vertrag-1-gemischt'] = array('contrat', $contract->id);

	// (13) Contract with one monthly service and no term fields (they must stay out).
	$contract2 = new Contrat($db);
	$contract2->socid = $private->id;
	$contract2->date_contrat = rt_day('2026-11-24');
	$contract2->commercial_signature_id = $admin->id;
	$contract2->commercial_suivi_id = $admin->id;
	if ($contract2->create($admin) <= 0) {
		rt_fail('contract: '.$contract2->error);
	}
	$contract2->addline($p['wartung']->description, 49.00, 1, 0, 0, 0, $p['wartung']->id, 0, rt_day('2026-12-01'), '', 'HT', 0, 0, null, 0, array(), $p['wartung']->fk_unit);
	$contract2->validate($admin);
	$docs['vertrag-2-monatlich'] = array('contrat', $contract2->id);

	// (14) Work report for the contract: two visits with durations.
	$inter = new Fichinter($db);
	$inter->socid = $firm->id;
	$inter->fk_contrat = $contract->id;
	$inter->description = 'Drucker im Empfang druckt nicht mehr, dazu Kontrolle der Datensicherung.';
	if ($inter->create($admin) <= 0) {
		rt_fail('intervention: '.$inter->error.' '.implode(',', (array) $inter->errors));
	}
	$inter->addline($admin, $inter->id, "Fehlersuche Drucker\nTreiber neu installiert, Netzwerkfreigabe korrigiert, Testdruck aus allen Arbeitsplätzen.", dol_mktime(9, 0, 0, 11, 26, 2026), 5400);
	$inter->addline($admin, $inter->id, 'Kontrolle Datensicherung, Rücksicherung einer Testdatei', dol_mktime(14, 30, 0, 11, 26, 2026), 1800);
	$inter->fetch($inter->id);
	if ($inter->setValid($admin) <= 0) {
		rt_fail('validate intervention: '.$inter->error);
	}
	$docs['arbeitsbericht-1'] = array('fichinter', $inter->id);

	// Supplier for the purchase side.
	$supplier = new Societe($db);
	$supplier->name = 'Hardware Großhandel GmbH';
	$supplier->fournisseur = 1;
	$supplier->code_fournisseur = 'SU2611-00001';
	$supplier->email = 'vertrieb@grosshandel.example';
	$supplier->address = 'Industriestraße 4';
	$supplier->zip = '1230';
	$supplier->town = 'Wien';
	$supplier->country_id = 41;
	if ($supplier->create($admin) <= 0) {
		rt_fail('supplier: '.$supplier->error);
	}

	// (15) Price request.
	$request = new SupplierProposal($db);
	$request->socid = $supplier->id;
	$request->delivery_date = rt_day('2026-12-05');
	$request->note_public = 'Für ein Kundenprojekt: bitte Preis und Lieferzeit, gern auch eine gleichwertige Alternative.';
	if ($request->create($admin) <= 0) {
		rt_fail('price request: '.$request->error.' '.implode(',', (array) $request->errors));
	}
	$request->addline($p['mat']->label, 0, 8, 0, 0, 0, $p['mat']->id, 0, 'HT', 0, 0, 0, -1, 0, 0, 0, 0, '', array(), 'MZ-V9P1T0BW', $p['mat']->fk_unit);
	$request->addline('Netzwerk-Switch 24 Port, managed', 0, 1, 0, 0, 0, 0, 0, 'HT', 0, 0, 0);
	$request->valid($admin);
	$docs['preisanfrage-1'] = array('supplier_proposal', $request->id);

	// (16) Purchase order with the supplier's reference.
	$purchase = new CommandeFournisseur($db);
	$purchase->socid = $supplier->id;
	$purchase->ref_supplier = 'AB-77812';
	$purchase->delivery_date = rt_day('2026-12-03');
	$purchase->cond_reglement_id = $term14;
	$purchase->mode_reglement_id = $vir;
	if ($purchase->create($admin) <= 0) {
		rt_fail('purchase order: '.$purchase->error.' '.implode(',', (array) $purchase->errors));
	}
	if ($purchase->addline($p['mat']->label, 98.50, 8, 0, 0, 0, $p['mat']->id, 0, 'MZ-V9P1T0BW', 0, 'HT', 0, 0, 0, 0, null, null, array(), $p['mat']->fk_unit) <= 0) {
		rt_fail('purchase line: '.$purchase->error);
	}
	if ($purchase->valid($admin) <= 0) {
		rt_fail('validate purchase order: '.$purchase->error);
	}
	$purchase->approve($admin);
	$purchase->fetch($purchase->id);
	$docs['bestellung-1'] = array('order_supplier', $purchase->id);

	// (17) Goods receipt for that order.
	$reception = new Reception($db);
	$reception->socid = $supplier->id;
	$reception->origin = 'order_supplier';
	$reception->origin_type = 'order_supplier';
	$reception->origin_id = $purchase->id;
	$reception->date_reception = rt_day('2026-12-03');
	$reception->ref_supplier = 'LS-55120';
	foreach ($purchase->lines as $purchaseLine) {
		$reception->addline($warehouse->id, $purchaseLine->id, 8, array(), 'Karton unbeschädigt');
	}
	if ($reception->create($admin) <= 0) {
		rt_fail('reception: '.$reception->error.' '.implode(',', (array) $reception->errors));
	}
	$reception->valid($admin);
	$docs['wareneingang-1'] = array('reception', $reception->id);

	$db->commit();
	file_put_contents(RT_STATE, json_encode($docs));
	echo json_encode(array('documents' => array_keys($docs))), "\n";
	exit(0);
}

if ($stage === 'render') {
	$only = $argv[2] ?? '';
	$docs = json_decode((string) file_get_contents(RT_STATE), true);
	dol_mkdir(RT_OUT);
	$classes = array(
		'propal' => array('Propal', '/comm/propal/class/propal.class.php', 'propal'),
		'facture' => array('Facture', '/compta/facture/class/facture.class.php', 'facture'),
		'commande' => array('Commande', '/commande/class/commande.class.php', 'commande'),
		'shipping' => array('Expedition', '/expedition/class/expedition.class.php', 'expedition'),
		'fichinter' => array('Fichinter', '/fichinter/class/fichinter.class.php', 'fichinter'),
		'contrat' => array('Contrat', '/contrat/class/contrat.class.php', 'contrat'),
		'supplier_proposal' => array('SupplierProposal', '/supplier_proposal/class/supplier_proposal.class.php', 'supplierproposal'),
		'order_supplier' => array('CommandeFournisseur', '/fourn/class/fournisseur.commande.class.php', 'supplierorder'),
		'reception' => array('Reception', '/reception/class/reception.class.php', 'reception'),
	);
	require_once dol_buildpath('/ittabelanderpdf/class/werkbankbuilder.class.php', 0);
	$done = array();
	foreach ($docs as $name => $entry) {
		list($element, $id) = $entry;
		if ($only !== '' && strpos($name, $only) === false) {
			continue;
		}
		list($class, $file, $builderFile) = $classes[$element];
		require_once DOL_DOCUMENT_ROOT.$file;
		$object = new $class($db);
		$object->fetch($id);
		$object->fetch_thirdparty();
		if ($object->generateDocument('werkbank', $langs) <= 0) {
			rt_fail($name.': '.$object->error.' '.implode(',', (array) $object->errors));
		}
		$ref = dol_sanitizeFileName($object->ref);
		// Where the builder writes, so every type is found the same way.
		require_once dol_buildpath('/ittabelanderpdf/class/werkbankbuilder.'.$builderFile.'.class.php', 0);
		$builderClass = 'WerkbankBuilder'.array('propal' => 'Propal', 'facture' => 'Facture', 'commande' => 'Commande', 'expedition' => 'Expedition',
			'fichinter' => 'Fichinter', 'contrat' => 'Contrat', 'supplierproposal' => 'SupplierProposal', 'supplierorder' => 'SupplierOrder', 'reception' => 'Reception')[$builderFile];
		$builder = new $builderClass($db, $object, $langs);
		$source = $builder->outputFile();
		if (!is_file($source)) {
			rt_fail($name.': no PDF at '.$source);
		}
		copy($source, RT_OUT.'/'.$name.'.pdf');
		$done[] = $name.' ('.$ref.')';
	}
	echo json_encode(array('rendered' => $done)), "\n";
	exit(0);
}

/** Load a document of the sample set. */
function rt_load($db, $element, $id)
{
	$classes = array(
		'propal' => array('Propal', '/comm/propal/class/propal.class.php'),
		'facture' => array('Facture', '/compta/facture/class/facture.class.php'),
		'commande' => array('Commande', '/commande/class/commande.class.php'),
		'shipping' => array('Expedition', '/expedition/class/expedition.class.php'),
		'fichinter' => array('Fichinter', '/fichinter/class/fichinter.class.php'),
		'contrat' => array('Contrat', '/contrat/class/contrat.class.php'),
		'supplier_proposal' => array('SupplierProposal', '/supplier_proposal/class/supplier_proposal.class.php'),
		'order_supplier' => array('CommandeFournisseur', '/fourn/class/fournisseur.commande.class.php'),
	);
	require_once DOL_DOCUMENT_ROOT.$classes[$element][1];
	$object = new $classes[$element][0]($db);
	if ($object->fetch($id) <= 0) {
		rt_fail('cannot load '.$element.' '.$id);
	}
	$object->fetch_thirdparty();
	return $object;
}

/** The dark-mode logo where production keeps it (DMS), from the website's brand files. */
function rt_dark_logo()
{
	$target = DOL_DATA_ROOT.'/ecm/IT-Tabelander/Bilder/Logo';
	dol_mkdir($target);
	copy(__DIR__.'/logo-dark.png', $target.'/IT-Tabelander Banner Hell Transparent.png');
}

/** The queue row of a document and event. */
function rt_queue($db, $element, $id, $event)
{
	$resql = $db->query("SELECT rowid, status, date_due, date_creation, error, recipient, fk_template FROM ".MAIN_DB_PREFIX."ittabpdf_mailqueue"
		." WHERE element = '".$db->escape($element)."' AND fk_object = ".((int) $id)." AND event = '".$db->escape($event)."'");
	return $resql ? $db->fetch_object($resql) : null;
}

/** Make a planned row due now, as if the delay were over. */
function rt_due($db, $element, $id)
{
	rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."ittabpdf_mailqueue SET date_due = '".$db->idate(dol_now() - 60)."' WHERE element = '".$db->escape($element)."' AND fk_object = ".((int) $id)." AND status = 'planned'");
}

if ($stage === 'mails') {
	rt_dark_logo();
	dol_include_once('/ittabelanderpdf/class/werkbankmailqueue.class.php');
	require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	$docs = json_decode((string) file_get_contents(RT_STATE), true);
	$queue = new WerkbankMailQueue($db);
	$checks = array();
	$check = function ($name, $ok, $detail = '') use (&$checks) {
		$checks[$name] = $ok ? 'ok' : 'FAILED: '.$detail;
	};

	// Installation: templates, extra field, cron job.
	$templates = (int) rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf'");
	$check('templates installed (31)', $templates === 31, (string) $templates);
	$check('extra field ittab_du', (bool) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."extrafields WHERE name = 'ittab_du' AND elementtype = 'societe'"));
	$check('cron job registered', (bool) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."cronjob WHERE objectname = 'WerkbankMailQueue' AND methodename = 'runCron' AND frequency = 5 AND unitfrequency = 60"));

	// Labels of 1.7.0 and earlier: renamed on activation, text untouched (also edited ones), once.
	rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."c_email_templates SET label = 'Angebot (Sie)' WHERE module = 'ittabelanderpdf' AND label = 'Angebot · Sie'");
	rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."c_email_templates SET label = 'Nachricht (Du)', content = CONCAT(content, '<p>Eigene Zeile</p>')"
		." WHERE module = 'ittabelanderpdf' AND label = 'Nachricht · Du'");
	$renamed = WerkbankMailTemplates::renameLabels($db, (int) $conf->entity);
	$check('old labels with brackets are renamed', $renamed === 2 && !rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf' AND label LIKE '%(%'"), (string) $renamed);
	$check('renaming keeps the text of an edited template', strpos((string) rt_value($db, "SELECT content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf' AND label = 'Nachricht · Du'"), 'Eigene Zeile') !== false);
	$check('renaming twice changes nothing, nothing installed twice', WerkbankMailTemplates::renameLabels($db, (int) $conf->entity) === 0 && WerkbankMailTemplates::install($db, (int) $conf->entity) === 0
		&& (int) rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf'") === 31);
	WerkbankMailTemplates::upgrade($db, (int) $conf->entity, true);

	// Templates of 1.0.0/1.0.1 as production has them: two untouched, one edited
	// by the owner, plus an own template of the owner that must never change.
	require_once __DIR__.'/templates_1_0_1.php';
	$old = function ($key, $form) {
		$definition = WerkbankMailTemplatesV101::definitions()[$key];
		$topic = ($key === 'shipping' && $form === 'du') ? 'Deine Lieferung: Lieferschein __REF__' : $definition[2];
		return array($topic, WerkbankMailTemplatesV101::html($form === 'du' ? $definition[4] : $definition[3], $form, $key));
	};
	$setTemplate = function ($label, $topic, $content) use ($db) {
		rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."c_email_templates SET topic = '".$db->escape($topic)."', content = '".$db->escape($content)."'"
			." WHERE module = 'ittabelanderpdf' AND label = '".$db->escape($label)."'");
	};
	list($topic, $content) = $old('propal', 'sie');
	$setTemplate('Angebot · Sie', $topic, $content);
	list($topic, $content) = $old('invoice', 'du');
	$setTemplate('Rechnung · Du', $topic, $content);
	list($topic, $content) = $old('contract', 'sie');
	$setTemplate('Vertrag · Sie', $topic, $content.'<p>Eigener Zusatz</p>');
	if (!rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_email_templates WHERE label = 'Angebot senden'")) {
		rt_exec($db, "INSERT INTO ".MAIN_DB_PREFIX."c_email_templates (entity, module, type_template, label, active, topic, content) VALUES (1, NULL, 'propal_send', 'Angebot senden', 0, 'Angebot', 'Eigener Text')");
	}
	$result = WerkbankMailTemplates::upgrade($db, (int) $conf->entity);
	$check('upgrade renews untouched 1.0.1 templates', is_array($result) && $result['updated'] === 2 && $result['edited'] === 1, json_encode($result));
	$check('upgrade keeps the edited template', strpos((string) rt_value($db, "SELECT content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf' AND label = 'Vertrag · Sie'"), 'Eigener Zusatz') !== false);
	$result = WerkbankMailTemplates::upgrade($db, (int) $conf->entity, true);
	$check('forced upgrade replaces the edited one', is_array($result) && $result['updated'] === 1 && $result['current'] === 30, json_encode($result));
	$check('own templates stay untouched', rt_value($db, "SELECT content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE label = 'Angebot senden'") === 'Eigener Text');
	if (!function_exists('testSqlAndScriptInject')) {
		require_once DOL_DOCUMENT_ROOT.'/waf.inc.php';
	}
	$blocked = array();
	$resql = $db->query("SELECT label, content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf'");
	while ($obj = $db->fetch_object($resql)) {
		if (testSqlAndScriptInject($obj->content, 0) || stripos($obj->content, '<style') !== false || stripos($obj->content, '<table') !== false) {
			$blocked[] = $obj->label;
		}
		// What Dolibarr keeps when the template is saved in the admin page (restricthtml).
		$plain = function ($html) {
			// The sanitiser adds line breaks after <br> and blocks: compare without whitespace.
			return preg_replace('/\s+/u', '', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
		};
		if ($plain(dol_htmlwithnojs($obj->content, 1, 'restricthtml')) !== $plain($obj->content)) {
			$blocked[] = $obj->label.' (text changes when saved)';
		}
	}
	$check('templates pass the WAF, no <style>, no <table>, survive restricthtml', !$blocked, implode(', ', $blocked));
	$check('no "call me" in any module template (questions by e-mail or customer area)', !rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf' AND (content LIKE '%erreichen Sie mich%' OR content LIKE '%erreichst du mich%')"));
	$check('no "Viele Grüße" in any module template',!rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf' AND content LIKE '%Viele Grüße%'"));

	// 1. Every template, Sie and Du, through the module's own send path.
	$firm = new Societe($db);
	$firm->fetch(0, 'Musterfirma GmbH');
	$private = new Societe($db);
	$private->fetch(0, 'Max Mustermann');
	$paidInvoice = rt_invoice($db, $admin, $private->id, array(), array(array(null, 1, 0, "Fernwartung\nDrucker neu eingerichtet.", '', '', 45.00)));
	$account = (int) getDolGlobalInt('FACTURE_RIB_NUMBER');
	rt_pay($db, $admin, $paidInvoice, 45.00, $account, '2026-11-24');
	$samples = array(
		'propal' => array('propal', $docs['angebot-1-wie-entwurf'][1], 1),
		'invoice' => array('facture', $docs['rechnung-2-teilzahlung'][1], 1),
		'deposit' => array('facture', $docs['rechnung-3-anzahlung'][1], 1),
		'creditnote' => array('facture', $docs['gutschrift-1-offen'][1], 1),
		'paid' => array('facture', $paidInvoice->id, 0),
		'order' => array('commande', $docs['auftrag-1'][1], 1),
		'shipping' => array('shipping', $docs['lieferschein-1'][1], 1),
		'fichinter' => array('fichinter', $docs['arbeitsbericht-1'][1], 1),
		'contract' => array('contrat', $docs['vertrag-1-gemischt'][1], 1),
		'thirdparty' => array('societe', $firm->id, 0),
		'supplier_proposal' => array('supplier_proposal', $docs['preisanfrage-1'][1], 1),
		'order_supplier' => array('order_supplier', $docs['bestellung-1'][1], 1),
	);
	$definitions = WerkbankMailTemplates::definitions();
	foreach ($samples as $key => $sample) {
		$object = $sample[0] === 'societe' ? $firm : rt_load($db, $sample[0], $sample[1]);
		$forms = $definitions[$key][4] === null ? array('sie') : array('sie', 'du');
		foreach ($forms as $form) {
			$result = $queue->send($object, $key, $admin, (bool) $sample[2], '', $form);
			$check('template '.$key.' '.$form, $result['ok'], $result['error']);
		}
	}

	// 1a. Hand-sent: what card_presend + actions_sendmails do with a template.
	$manual = array(array('propal', $docs['angebot-1-wie-entwurf'][1], 'propal', 'sie'), array('facture', $docs['rechnung-2-teilzahlung'][1], 'invoice', 'du'));
	foreach ($manual as $case) {
		$object = rt_load($db, $case[0], $case[1]);
		$template = $db->fetch_object($db->query("SELECT topic, content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE rowid = ".WerkbankMailTemplates::find($db, $case[2], $case[3], $conf->entity)));
		$substitutions = getCommonSubstitutionArray($langs, 0, null, $object);
		complete_substitutions_array($substitutions, $langs, $object, array('mode' => 'formemail'));
		$message = make_substitutions($template->content, $substitutions);
		$check('hand-sent '.$case[2].' '.$case[3].': form POST passes the WAF', !testSqlAndScriptInject($message, 0));
		$message = dol_htmlwithnojs($message, 1, 'restricthtml');
		$check('hand-sent '.$case[2].' '.$case[3].': light logo only', strpos($message, 'variant=dark') === false && strpos($message, 'maillogo.php') !== false);
		$mail = new CMailFile('[von Hand] '.make_substitutions($template->topic, $substitutions), 'test-manuell@example.test', 'IT-Tabelander <office@tabelander.co.at>', $message, array(), array(), array(), '', '', 0, 1);
		$check('hand-sent '.$case[2].' '.$case[3].': sent', $mail->sendfile(), $mail->error);
	}

	// 1b. Customer area switch: off (default) above, now on for the proposal
	// mails and PDF, then off again.
	$proposal = rt_load($db, 'propal', $docs['angebot-1-wie-entwurf'][1]);
	$vars = function () use ($langs, $proposal) {
		$substitutions = getCommonSubstitutionArray($langs, 0, null, $proposal);
		complete_substitutions_array($substitutions, $langs, $proposal, array('mode' => 'formemail'));
		return $substitutions;
	};
	$off = $vars();
	$check('portal off: no button', $off['__ITTAB_KUNDENBEREICH_BLOCK__'] === '');
	$check('portal off: reply sentence', strpos($off['__ITTAB_ANNAHME_SATZ__'], 'antworten Sie einfach auf diese E-Mail') !== false && strpos($off['__ITTAB_ANNAHME_SATZ_DU__'], 'antworte einfach auf diese Mail') !== false);
	$check('logo from the public endpoint', strpos($off['__ITTAB_LOGO__'], '/custom/ittabelanderpdf/public/maillogo.php?v=') !== false && strpos($off['__ITTAB_LOGO__'], 'width="180"') !== false, $off['__ITTAB_LOGO__']);
	rt_const($db, 'ITTABPDF_PORTAL_ENABLED', '1');
	$conf->global->ITTABPDF_PORTAL_ENABLED = '1';
	$on = $vars();
	$check('portal on: button to the proposal', strpos($on['__ITTAB_KUNDENBEREICH_BLOCK__'], '/kundenbereich/angebote/'.$proposal->id) !== false);
	$check('portal on: customer area sentence', strpos($on['__ITTAB_ANNAHME_SATZ__'], 'im Kundenbereich annehmen') !== false);
	foreach (array('sie', 'du') as $form) {
		$result = $queue->send($proposal, 'propal', $admin, true, '', $form);
		$check('template propal '.$form.' (portal on)', $result['ok'], $result['error']);
	}
	// Every customer document links to its own page of the customer area (#115),
	// the button sits in each customer template, not in supplier ones.
	$pages = array(
		'invoice' => array('facture', $docs['rechnung-2-teilzahlung'][1], '/kundenbereich/rechnungen/'),
		'creditnote' => array('facture', $docs['gutschrift-1-offen'][1], '/kundenbereich/rechnungen/'),
		'order' => array('commande', $docs['auftrag-1'][1], '/kundenbereich/belege/auftraege/'),
		'shipping' => array('shipping', $docs['lieferschein-1'][1], '/kundenbereich/belege/lieferscheine/'),
		'fichinter' => array('fichinter', $docs['arbeitsbericht-1'][1], '/kundenbereich/belege/arbeitsberichte/'),
		'contract' => array('contrat', $docs['vertrag-1-gemischt'][1], '/kundenbereich/belege/vertraege/'),
	);
	foreach ($pages as $key => $page) {
		$object = rt_load($db, $page[0], $page[1]);
		$check('portal link '.$key, WerkbankMailData::portalUrl($object) === 'https://it.tabelander.co.at'.$page[2].((int) $object->id), WerkbankMailData::portalUrl($object));
		$template = (string) rt_value($db, "SELECT content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE rowid = ".WerkbankMailTemplates::find($db, $key, 'sie', $conf->entity));
		$check('button in template '.$key, strpos($template, '__ITTAB_KUNDENBEREICH_BLOCK__') !== false);
		$result = $queue->send($object, $key, $admin, false, '', 'du');
		$check('template '.$key.' du (portal on)', $result['ok'], $result['error']);
	}
	$supplier = (string) rt_value($db, "SELECT content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE rowid = ".WerkbankMailTemplates::find($db, 'order_supplier', '', $conf->entity));
	$check('no button for suppliers', strpos($supplier, '__ITTAB_KUNDENBEREICH_BLOCK__') === false);
	if ($proposal->generateDocument('werkbank', $langs) > 0) {
		dol_mkdir(RT_OUT);
		copy(DOL_DATA_ROOT.'/'.$proposal->last_main_doc, RT_OUT.'/angebot-1-kundenbereich-an.pdf');
	}
	rt_const($db, 'ITTABPDF_PORTAL_ENABLED', '0');
	$conf->global->ITTABPDF_PORTAL_ENABLED = '0';
	$proposal->generateDocument('werkbank', $langs);

	// 2. Automatic sending: switched on, 5 minutes delay.
	rt_const($db, 'ITTABPDF_AUTOSEND_PROPAL', '1');
	rt_const($db, 'ITTABPDF_AUTOSEND_INVOICE', '1');
	rt_const($db, 'ITTABPDF_AUTOSEND_PAID', '1');
	rt_const($db, 'ITTABPDF_AUTOSEND_DELAY', '5');
	$contactId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."socpeople WHERE lastname = 'Muster'");
	$newProposal = function ($label) use ($db, $admin, $firm, $contactId) {
		$prop = new Propal($db);
		$prop->socid = $firm->id;
		$prop->date = dol_now();
		$prop->duree_validite = 14;
		$prop->ref_client = $label;
		if ($prop->create($admin) <= 0) {
			rt_fail('proposal: '.$prop->error);
		}
		rt_lines($prop, array(array(null, 1, 0, "Fernwartung\nEinrichtung des neuen Notebooks.", '', '', 90.00)));
		$prop->add_contact($contactId, 'CUSTOMER', 'external');
		return $prop;
	};

	// a) Validation plans the e-mail 5 minutes ahead; a run before then sends nothing.
	$prop = $newProposal('Automatischer Versand');
	$prop->valid($admin);
	$row = rt_queue($db, 'propal', $prop->id, 'propal_validated');
	$delay = $row ? $db->jdate($row->date_due) - $db->jdate($row->date_creation) : -1;
	$check('proposal planned with 5 min delay', $row && $row->status === 'planned' && $delay >= 295 && $delay <= 305, $row ? $row->status.' '.$delay.' s' : 'no row');
	$queue->runCron();
	$row = rt_queue($db, 'propal', $prop->id, 'propal_validated');
	$check('not sent before the delay', $row && $row->status === 'planned', $row ? $row->status : 'no row');
	rt_due($db, 'propal', $prop->id);
	$queue->runCron();
	$row = rt_queue($db, 'propal', $prop->id, 'propal_validated');
	$check('sent after the delay', $row && $row->status === 'sent', $row ? $row->status.' '.$row->error : 'no row');
	$check('recipient is the proposal contact', $row && strpos((string) $row->recipient, 'maria.muster@musterfirma.example') !== false, $row ? (string) $row->recipient : '');
	$event = $db->fetch_object($db->query("SELECT id, note, label FROM ".MAIN_DB_PREFIX."actioncomm WHERE elementtype = 'propal' AND fk_element = ".((int) $prop->id)." AND code = 'AC_PROPAL_SENTBYMAIL'"));
	$check('agenda entry like a manual send', (bool) $event, 'no actioncomm AC_PROPAL_SENTBYMAIL');
	$check('Sie greeting with civility', $event && strpos((string) $event->note, 'Guten Tag Frau Muster,') !== false);

	// b) Validating again after going back to draft sends no second e-mail.
	$prop->setDraft($admin);
	$prop->valid($admin);
	$row = rt_queue($db, 'propal', $prop->id, 'propal_validated');
	$check('no second send after re-validation', $row && $row->status === 'sent', $row ? $row->status : '');

	// c) Cancelled before the delay: never sent.
	$inv = rt_invoice($db, $admin, $firm->id, array('ref_client' => 'Abbruch-Test'), array(array(null, 1, 0, 'Fernwartung', '', '', 30.00)));
	$row = rt_queue($db, 'facture', $inv->id, 'invoice_validated');
	$check('invoice planned', $row && $row->status === 'planned');
	$check('cancel works', $row && $queue->cancel((int) $row->rowid, $admin) === 1);
	rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."ittabpdf_mailqueue SET date_due = '".$db->idate(dol_now() - 60)."' WHERE rowid = ".((int) $row->rowid));
	$queue->runCron();
	$row = rt_queue($db, 'facture', $inv->id, 'invoice_validated');
	$check('cancelled stays unsent', $row && $row->status === 'cancelled', $row ? $row->status : '');

	// d) Back to draft before the delay: cancelled by the run.
	$inv = rt_invoice($db, $admin, $firm->id, array('ref_client' => 'Entwurf-Test'), array(array(null, 1, 0, 'Fernwartung', '', '', 30.00)));
	$inv->setDraft($admin);
	rt_due($db, 'facture', $inv->id);
	$queue->runCron();
	$row = rt_queue($db, 'facture', $inv->id, 'invoice_validated');
	$check('draft again is not sent', $row && $row->status === 'cancelled', $row ? $row->status.' '.$row->error : '');

	// e) No address anywhere: error, visible on the card and in the list.
	$nomail = new Societe($db);
	$nomail->name = 'Ohne Mail KG '.dol_print_date(dol_now(), '%H%M%S');
	$nomail->client = 1;
	$nomail->code_client = 'CU-NOMAIL-'.dol_print_date(dol_now(), '%H%M%S');
	$nomail->country_id = 41;
	$nomail->create($admin);
	$inv = rt_invoice($db, $admin, $nomail->id, array(), array(array(null, 1, 0, 'Fernwartung', '', '', 30.00)));
	rt_due($db, 'facture', $inv->id);
	$queue->runCron();
	$row = rt_queue($db, 'facture', $inv->id, 'invoice_validated');
	$check('no address gives an error', $row && $row->status === 'error' && strpos((string) $row->error, 'Keine E-Mail-Adresse') !== false, $row ? $row->status.' '.$row->error : '');
	$check('error shows in the card banner', count($queue->openFor('facture', (int) $inv->id)) === 1);

	// f) Du, invoice, then the payment confirmation without attachment.
	$inv = rt_invoice($db, $admin, $private->id, array(), array(array(null, 1, 0, "Akkuwechsel\nController geöffnet, Akku getauscht, getestet.", '', '', 54.80)));
	rt_due($db, 'facture', $inv->id);
	$queue->runCron();
	$row = rt_queue($db, 'facture', $inv->id, 'invoice_validated');
	$check('invoice sent (Du)', $row && $row->status === 'sent', $row ? $row->status.' '.$row->error : '');
	$check('Du template chosen', $row && (int) $row->fk_template === WerkbankMailTemplates::find($db, 'invoice', 'du', $conf->entity));
	rt_pay($db, $admin, $inv, 54.80, $account, '2026-11-24');
	$row = rt_queue($db, 'facture', $inv->id, 'invoice_paid');
	$check('payment confirmation planned', $row && $row->status === 'planned', $row ? $row->status : 'no row');
	rt_due($db, 'facture', $inv->id);
	$queue->runCron();
	$row = rt_queue($db, 'facture', $inv->id, 'invoice_paid');
	$check('payment confirmation sent', $row && $row->status === 'sent', $row ? $row->status.' '.$row->error : '');

	// g) Credit note and down payment invoice get their own templates.
	$credit = rt_invoice($db, $admin, $private->id, array('type' => Facture::TYPE_CREDIT_NOTE, 'fk_facture_source' => $inv->id), array(array(null, 1, 0, 'Kulanz', '', '', 10.00)));
	$deposit = rt_invoice($db, $admin, $firm->id, array('type' => Facture::TYPE_DEPOSIT), array(array(null, 1, 0, 'Anzahlung 50 %', '', '', 200.00)));
	rt_due($db, 'facture', $credit->id);
	rt_due($db, 'facture', $deposit->id);
	$queue->runCron();
	$row = rt_queue($db, 'facture', $credit->id, 'invoice_validated');
	$check('credit note template', $row && $row->status === 'sent' && (int) $row->fk_template === WerkbankMailTemplates::find($db, 'creditnote', 'du', $conf->entity), $row ? $row->status.' '.$row->error : '');
	$row = rt_queue($db, 'facture', $deposit->id, 'invoice_validated');
	$check('deposit template', $row && $row->status === 'sent' && (int) $row->fk_template === WerkbankMailTemplates::find($db, 'deposit', 'sie', $conf->entity), $row ? $row->status.' '.$row->error : '');

	// h) One proposal stays planned for the look at the card banner.
	$planned = $newProposal('Banner-Test');
	$planned->valid($admin);

	$failed = array_filter($checks, function ($value) {
		return $value !== 'ok';
	});
	echo json_encode(array('checks' => $checks, 'failed' => count($failed), 'planned_propal' => (int) $planned->id)), "\n";
	exit($failed ? 4 : 0);
}

if ($stage === 'rollout') {
	// Texts for production that live outside this module: the Mahnwesen
	// templates (same {PLACEHOLDERS} as the plugin's defaults) and the three
	// ticket texts. Written to /tmp/ittpdf-rollout, sent to Mailpit the way
	// the plugin and Dolibarr put them together.
	dol_include_once('/ittabelanderpdf/class/werkbankmailtemplates.class.php');
	require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
	$out = '/tmp/ittpdf-rollout';
	dol_mkdir($out.'/mahnwesen');
	dol_mkdir($out.'/ticket');
	$T = 'WerkbankMailTemplates';
	// The ticket_send template is new in this version: install what is missing.
	$T::install($db, (int) $conf->entity);
	$footer = $T::footerBlock($mysoc);
	$end = '<!--MAHNWESEN_PDF_END-->';

	// Mahnwesen: the PDF letter prints everything before the end marker, so the
	// word mark and the navy footer come after it (e-mail only).
	$facts = array(
		'Rechnung' => '{INVOICE_REF}',
		'Rechnungsdatum' => '{INVOICE_DATE}',
		'Fällig seit' => '{DUE_DATE}',
	);
	$letters = array(
		'zahlungserinnerung' => array('Zahlungserinnerung zur Rechnung {INVOICE_REF}', $facts + array('!Offen' => '{OPEN_AMOUNT}'), array(
			'die Rechnung <strong>{INVOICE_REF}</strong> vom {INVOICE_DATE} war am {DUE_DATE} fällig. Laut meinen Unterlagen sind davon noch <strong>{OPEN_AMOUNT}</strong> offen.',
			'Vielleicht ist sie im Alltag untergegangen. Bitte prüfen Sie das kurz und überweisen Sie den offenen Betrag. Falls Sie schon bezahlt haben, ist diese Nachricht damit erledigt. {INTEREST_PARAGRAPH}',
			'{PAYMENT_PARAGRAPH}',
		)),
		'mahnung-1' => array('1. Mahnung zur Rechnung {INVOICE_REF}', $facts + array('Offen' => '{OPEN_AMOUNT}', '!Zu zahlen' => '{DUNNING_TOTAL}'), array(
			'für die Rechnung <strong>{INVOICE_REF}</strong> ist bisher keine vollständige Zahlung eingegangen. Offen sind <strong>{OPEN_AMOUNT}</strong>, fällig war die Rechnung am {DUE_DATE}.',
			'{FEE_PARAGRAPH} {INTEREST_PARAGRAPH}',
			'{PAYMENT_PARAGRAPH}',
			'Bitte überweisen Sie den Gesamtbetrag von <strong>{DUNNING_TOTAL}</strong> in den nächsten Tagen. Wenn die Zahlung inzwischen erfolgt ist, betrachten Sie dieses Schreiben bitte als gegenstandslos.',
		)),
		'mahnung-2' => array('2. Mahnung zur Rechnung {INVOICE_REF}', $facts + array('Offen' => '{OPEN_AMOUNT}', '!Zu zahlen' => '{DUNNING_TOTAL}'), array(
			'trotz meiner Erinnerung ist die Rechnung <strong>{INVOICE_REF}</strong> weiterhin offen: <strong>{OPEN_AMOUNT}</strong>, fällig seit {DUE_DATE}.',
			'{FEE_PARAGRAPH} {INTEREST_PARAGRAPH}',
			'{PAYMENT_PARAGRAPH}',
			'Bitte überweisen Sie den Gesamtbetrag von <strong>{DUNNING_TOTAL}</strong> jetzt ohne weitere Verzögerung.',
		)),
		'mahnung-3' => array('3. Mahnung zur Rechnung {INVOICE_REF}', $facts + array('Offen' => '{OPEN_AMOUNT}', '!Zu zahlen' => '{DUNNING_TOTAL}'), array(
			'das ist die dritte Mahnung zur Rechnung <strong>{INVOICE_REF}</strong>. Der offene Betrag von <strong>{OPEN_AMOUNT}</strong> ist seit {DUE_DATE} fällig.',
			'{FEE_PARAGRAPH} {INTEREST_PARAGRAPH}',
			'{PAYMENT_PARAGRAPH}',
			'Bitte zahlen Sie den Gesamtbetrag von <strong>{DUNNING_TOTAL}</strong> umgehend. Wenn es etwas zu klären gibt, melden Sie sich bitte gleich bei mir.',
		)),
	);
	$sample = array('{INVOICE_REF}' => 'IN2611-0002', '{INVOICE_DATE}' => '24.11.2026', '{DUE_DATE}' => '24.12.2026', '{OPEN_AMOUNT}' => '101,50 €',
		'{DUNNING_TOTAL}' => '111,50 €', '{FEE_PARAGRAPH}' => 'Für diese Mahnung fallen Mahnspesen von 10,00 € an.', '{INTEREST_PARAGRAPH}' => '',
		'{PAYMENT_PARAGRAPH}' => '', '{COMPANY_NAME}' => $mysoc->name);
	$sent = array();
	rt_dark_logo();
	foreach ($letters as $name => $letter) {
		list($subject, $rows, $paragraphs) = $letter;
		$body = $T::paragraph('Guten Tag,');
		foreach ($paragraphs as $index => $paragraph) {
			$body .= $T::paragraph($paragraph);
			if ($index === 0) {
				$body .= $T::factsBox($rows);
			}
		}
		// The letter keeps the closing words; the signature with the logo is for the e-mail (after the marker).
		$body .= '<p style="'.$T::P.'margin:24px 0 0 0;">'.$T::text('Mit freundlichen Grüßen<br>'.dol_escape_htmltag($mysoc->managers)).'</p>';
		// Mahnwesen runs its texts through complete_substitutions_array, so the
		// logo variable of this module works there (FormMail::setSubstitFromObject).
		// Mahnwesen prints everything before the marker into the letter, so logo
		// and footer come after it (e-mail only). __ITTAB_LOGO__ works there
		// because Mahnwesen runs FormMail::setSubstitFromObject (light logo:
		// Mahnwesen sends without a style sheet).
		// 1.9.0: no logo on top; after the marker the signature block as in the owner's signature.
		$signature = $T::signatureFor('', $mysoc, '__ITTAB_LOGO__');
		// The name stands in the letter already: from the rule on (logo and contact).
		$signature = substr($signature, 0, strpos($signature, '<p ')).substr($signature, strpos($signature, '<hr '));
		$html = '<div style="max-width:560px;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:1.6;color:#3B4D63;">'.$body.'</div>'.$end
			.'<div style="max-width:560px;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:1.6;margin:16px 0 0 0;">'.$signature.'</div>';
		file_put_contents($out.'/mahnwesen/'.$name.'.html', $html."\n");
		if (!function_exists('testSqlAndScriptInject')) {
			require_once DOL_DOCUMENT_ROOT.'/waf.inc.php';
		}
		if (testSqlAndScriptInject($html, 0)) {
			$sent['mahnwesen '.$name.' WAF'] = 'FAILED';
		}
		file_put_contents($out.'/mahnwesen/'.$name.'.betreff.txt', $subject."\n");
		// Sent with sample values, substituted as the plugin does it.
		$substitutions = getCommonSubstitutionArray($langs, 0, null, null);
		complete_substitutions_array($substitutions, $langs, null);
		$mail = new CMailFile(strtr($subject, $sample), 'max@mustermann.example', 'IT-Tabelander <office@tabelander.co.at>', make_substitutions(strtr($html, $sample), $substitutions), array(), array(), array(), '', '', 0, 1);
		$sent['mahnwesen '.$name] = $mail->sendfile() ? 'ok' : 'FAILED '.$mail->error;
	}

	// The letter body as the plugin prints it: everything before the marker,
	// images removed, into a Dolibarr PDF with the default font.
	require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
	$pdf = pdf_getInstance();
	$pdf->setPrintHeader(false);
	$pdf->setPrintFooter(false);
	$pdf->SetMargins(10, 10, 10);
	$pdf->AddPage();
	$pdf->SetFont(pdf_getPDFFont($langs), '', 9);
	$letterBody = strtr((string) file_get_contents($out.'/mahnwesen/mahnung-1.html'), $sample);
	$letterBody = substr($letterBody, 0, stripos($letterBody, $end));
	$letterBody = preg_replace('~<img\b[^>]*>~is', '', $letterBody);
	$pdf->SetY(90);
	$pdf->writeHTML('<div style="font-size:9pt; line-height:1.20;">'.$letterBody.'</div>', true, false, true, false, '');
	dol_mkdir(RT_OUT);
	$pdf->Output(RT_OUT.'/mahnwesen-brieftext-simulation.pdf', 'F');

	// Tickets. New ticket from the public form: MAIL_NEW + ticket infos + SIGNATURE.
	// Reply: INTRO + "<br><br>" + message (template ticket_send) + "<br>" + SIGNATURE.
	// Dolibarr replaces no variables in TICKET_MESSAGE_MAIL_NEW, so the logo is
	// the module's public endpoint on the production address, written out.
	$production = 'https://erp.tabelander.co.at/custom/ittabelanderpdf/public/maillogo.php';
	$local = dol_buildpath('/ittabelanderpdf/public/maillogo.php', 2);
	$image = function ($variant) use ($production, $mysoc) {
		$file = WerkbankMailData::logoFile($variant);
		$size = $file !== '' ? getimagesize($file) : array(480, 110);
		$url = $production.($variant === 'dark' ? '?variant=dark' : '');
		return '<img alt="'.dol_escape_htmltag($mysoc->name).'" src="'.$url.'" width="180" height="'.((int) round($size[1] * 180 / $size[0])).'"'
			.' style="border:0;display:block;height:auto;max-width:100%;width:180px" />';
	};
	// Both logos, as asked: the dark one stays hidden (inline display:none);
	// ticket mails carry no style sheet that could show it.
	$logo = $image('light').'<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">'.$image('dark').'</div>';
	$div = '<div style="max-width:560px;background:#ffffff;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:1.6;color:#3B4D63;">';
	$texts = array(
		'neues-ticket' => $div.$T::paragraph('Guten Tag,')
			.$T::paragraph('danke für Ihre Anfrage. Sie ist bei mir angekommen, ich sehe sie mir an und melde mich bei Ihnen.')
			.$T::paragraph('Den aktuellen Stand finden Sie jederzeit über den Link unten.').'</div>',
		'einleitung' => $div.$T::paragraph('Guten Tag,').'</div>',
		'signatur' => $div.$T::signatureFor('Mit freundlichen Grüßen', $mysoc, $image('light')).'</div>',
	);
	foreach ($texts as $name => $html) {
		file_put_contents($out.'/ticket/'.$name.'.html', $html."\n");
		if (!function_exists('testSqlAndScriptInject')) {
			require_once DOL_DOCUMENT_ROOT.'/waf.inc.php';
		}
		if (testSqlAndScriptInject($html, 0)) {
			$sent['ticket '.$name.' WAF'] = 'FAILED';
		}
		// In the test the image comes from this Dolibarr.
		$texts[$name] = str_replace($production, $local, $html);
	}
	$langs->load('ticket');
	$newTicket = $texts['neues-ticket'].'<br><br>'.$langs->transnoentities('TicketNewEmailBodyInfosTicket').'<br>'
		.$langs->transnoentities('TicketNewEmailBodyInfosTrackId', '<a href="#">TS2611-0001</a>').'<br>'
		.$langs->transnoentities('TicketNewEmailBodyInfosTrackUrl').'<br><br>'.$texts['signatur'];
	$mail = new CMailFile('['.$mysoc->name.'] '.$langs->transnoentities('TicketNewEmailSubject', 'TS2611-0001', 'abc123'), 'max@mustermann.example', 'IT-Tabelander <office@tabelander.co.at>', $newTicket, array(), array(), array(), '', '', 0, 1);
	$sent['ticket new'] = $mail->sendfile() ? 'ok' : 'FAILED '.$mail->error;
	$template = $db->fetch_object($db->query("SELECT topic, content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf' AND type_template = 'ticket_send'"));
	// Since 1.0.6 the template carries closing and signature (__USER_SIGNATURE__);
	// TICKET_MESSAGE_MAIL_SIGNATURE stays empty.
	$middle = strtr((string) $template->content, array('__TICKET_SUBJECT__' => 'Notebook startet nicht',
		"\u{00A0}" => 'das Netzteil war defekt, ein neues ist bestellt und kommt am Donnerstag. Danach ist das Notebook am selben Tag fertig.',
		'__USER_SIGNATURE__' => $div.'<strong style="color:#1B3348;">'.dol_escape_htmltag($mysoc->managers).'</strong><br>'.dol_escape_htmltag($mysoc->name).'</div>'.$footer));
	$reply = $texts['einleitung'].'<br><br>'.$middle;
	$mail = new CMailFile('['.$mysoc->name.' - Ticket TS2611-0001] Notebook startet nicht', 'max@mustermann.example', 'IT-Tabelander <office@tabelander.co.at>', $reply, array(), array(), array(), '', '', 0, 1);
	$sent['ticket reply'] = $mail->sendfile() ? 'ok' : 'FAILED '.$mail->error;

	echo json_encode(array('rollout' => $sent, 'ticket_template' => (bool) $template)), "\n";
	exit(in_array(false, array_map(function ($value) {
		return $value === 'ok';
	}, $sent), true) ? 5 : 0);
}

if ($stage === 'vat') {
	// The same invoice layout for a company that charges VAT (only in this
	// process: the setup stays a small business).
	$firm = new Societe($db);
	$firm->fetch(0, 'Musterfirma GmbH');
	$mysoc->tva_assuj = 1;
	$inv = new Facture($db);
	$inv->socid = $firm->id;
	$inv->type = Facture::TYPE_STANDARD;
	$inv->date = rt_day('2026-11-24');
	$inv->create($admin);
	$inv->addline('Notebook-Reparatur, Displaytausch', 120.00, 1, 20, 0, 0, 0, 0, '', '', 0, 0, 0, 'HT', 0, 1);
	$inv->addline('Fachbuch „Netzwerke für Einsteiger“', 30.00, 1, 10, 0, 0, 0, 0, '', '', 0, 0, 0, 'HT', 0, 0);
	$inv->validate($admin);
	$inv->fetch($inv->id);
	$inv->fetch_thirdparty();
	if ($inv->generateDocument('werkbank', $langs) <= 0) {
		rt_fail('vat invoice: '.$inv->error);
	}
	dol_mkdir(RT_OUT);
	copy(DOL_DATA_ROOT.'/'.$inv->last_main_doc, RT_OUT.'/test-ust-pflichtig.pdf');
	echo json_encode(array('vat' => $inv->ref, 'ht' => $inv->total_ht, 'tva' => $inv->total_tva, 'ttc' => $inv->total_ttc)), "\n";
	exit(0);
}

if ($stage === 'const') {
	rt_const($db, $argv[2], $argv[3] ?? '');
	echo json_encode(array($argv[2] => $argv[3] ?? '')), "\n";
	exit(0);
}

rt_fail('unknown stage '.$stage);
