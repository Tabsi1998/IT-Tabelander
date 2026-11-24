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
	$check('templates installed (22)', $templates === 22, (string) $templates);
	$check('extra field ittab_du', (bool) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."extrafields WHERE name = 'ittab_du' AND elementtype = 'societe'"));
	$check('cron job registered', (bool) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."cronjob WHERE objectname = 'WerkbankMailQueue' AND methodename = 'runCron' AND frequency = 5 AND unitfrequency = 60"));

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

if ($stage === 'const') {
	rt_const($db, $argv[2], $argv[3] ?? '');
	echo json_encode(array($argv[2] => $argv[3] ?? '')), "\n";
	exit(0);
}

rt_fail('unknown stage '.$stage);
