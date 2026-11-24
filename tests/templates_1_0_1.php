<?php
/*
 * Test fixture: the e-mail templates exactly as version 1.0.0/1.0.1 installed them
 * (class/werkbankmailtemplates.class.php at commit ed91746, class renamed), to test
 * that WerkbankMailTemplates::upgrade() renews untouched templates only.
 */


/**
 * The e-mail templates in the Werkbank look, installed into
 * llx_c_email_templates on activation.
 *
 * Mail clients get tables and inline styles only, at most 600 px wide, the
 * word mark as styled text (no image: webp is not shown everywhere). The
 * texts use Dolibarr's own variables (__REF__, __MYCOMPANY_NAME__, ...) and
 * the module's __ITTAB_*__ variables from
 * core/substitutions/functions_ittabelanderpdf.lib.php.
 */
class WerkbankMailTemplatesV101
{
	const INK = '#1B3348';
	const BODY = '#3B4D63';
	const MUTED = '#4A5B6E';
	const BRAND = '#FE5A23';
	const PAGE = '#F3F5F8';
	const FONT = "Arial, Helvetica, sans-serif";
	const P = 'margin:0 0 14px 0;font-family:Arial, Helvetica, sans-serif;font-size:15px;line-height:1.55;color:#3B4D63;';

	/**
	 * Every template: key => array(type_template, label, topic, paragraphs Sie, paragraphs Du, attach PDF).
	 * A template with null for "Du" is neutral (suppliers) and exists once.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string[],4:?string[],5:int}>
	 */
	public static function definitions()
	{
		return array(
			'propal' => array('propal_send', 'Angebot', 'Angebot __REF__ von __MYCOMPANY_NAME__',
				array('anbei das Angebot __REF__ als PDF.',
					'__ITTAB_ANNAHME_SATZ__'),
				array('anbei mein Angebot __REF__ als PDF.',
					'__ITTAB_ANNAHME_SATZ_DU__'),
				1),
			'invoice' => array('facture_send', 'Rechnung', 'Rechnung __REF__ von __MYCOMPANY_NAME__',
				array('anbei die Rechnung __REF__ als PDF. Danke für den Auftrag.',
					'Bitte überweisen Sie den Betrag bis zum Fälligkeitsdatum. Im PDF ist ein QR-Code: mit der Banking-App scannen, dann sind Betrag, IBAN und Verwendungszweck schon ausgefüllt.'),
				array('anbei die Rechnung __REF__ als PDF. Danke für den Auftrag.',
					'Bitte überweise den Betrag bis zum Fälligkeitsdatum. Im PDF ist ein QR-Code: mit der Banking-App scannen, dann sind Betrag, IBAN und Verwendungszweck schon ausgefüllt.'),
				1),
			'deposit' => array('facture_send', 'Anzahlungsrechnung', 'Anzahlungsrechnung __REF__ von __MYCOMPANY_NAME__',
				array('anbei die Anzahlungsrechnung __REF__ als PDF.',
					'Sobald die Anzahlung eingegangen ist, geht es los. Der Betrag wird auf der Schlussrechnung abgezogen.'),
				array('anbei die Anzahlungsrechnung __REF__ als PDF.',
					'Sobald die Anzahlung da ist, geht es los. Der Betrag wird auf der Schlussrechnung abgezogen.'),
				1),
			'creditnote' => array('facture_send', 'Gutschrift', 'Gutschrift __REF__ von __MYCOMPANY_NAME__',
				array('anbei die Gutschrift __REF__ als PDF.',
					'Den Betrag verrechne ich mit Ihrer nächsten Rechnung oder überweise ihn zurück, so wie wir es vereinbart haben.'),
				array('anbei die Gutschrift __REF__ als PDF.',
					'Den Betrag verrechne ich mit deiner nächsten Rechnung oder überweise ihn zurück, so wie wir es ausgemacht haben.'),
				1),
			'paid' => array('facture_send', 'Zahlungsbestätigung', 'Zahlung erhalten: Rechnung __REF__',
				array('Ihre Zahlung für die Rechnung __REF__ ist eingegangen. Die Rechnung ist damit vollständig bezahlt.',
					'Danke!'),
				array('deine Zahlung für die Rechnung __REF__ ist eingegangen. Die Rechnung ist damit vollständig bezahlt.',
					'Danke!'),
				0),
			'order' => array('order_send', 'Auftragsbestätigung', 'Auftragsbestätigung __REF__ von __MYCOMPANY_NAME__',
				array('danke für Ihren Auftrag. Anbei die Auftragsbestätigung __REF__ mit allen Positionen.',
					'Wenn etwas nicht passt, melden Sie sich bitte kurz, bevor ich anfange.'),
				array('danke für deinen Auftrag. Anbei die Auftragsbestätigung __REF__ mit allen Positionen.',
					'Wenn etwas nicht passt, melde dich bitte kurz, bevor ich anfange.'),
				1),
			'shipping' => array('shipping_send', 'Lieferung', 'Ihre Lieferung: Lieferschein __REF__',
				array('Ihre Lieferung ist unterwegs. Den Lieferschein __REF__ finden Sie im Anhang.'),
				array('deine Lieferung ist unterwegs. Den Lieferschein __REF__ findest du im Anhang.'),
				1),
			'fichinter' => array('fichinter_send', 'Arbeitsbericht', 'Arbeitsbericht __REF__ von __MYCOMPANY_NAME__',
				array('anbei der Arbeitsbericht __REF__ mit den erledigten Arbeiten und der Zeit, die dafür angefallen ist.',
					'Falls etwas fehlt oder nicht stimmt, sagen Sie mir bitte Bescheid.'),
				array('anbei der Arbeitsbericht __REF__ mit den erledigten Arbeiten und der Zeit, die dafür angefallen ist.',
					'Falls etwas fehlt oder nicht stimmt, sag mir bitte Bescheid.'),
				1),
			'contract' => array('contract', 'Vertrag', 'Vertrag __REF__ von __MYCOMPANY_NAME__',
				array('anbei der Vertrag __REF__ für die laufenden Leistungen.',
					'Bitte lesen Sie ihn in Ruhe durch und schicken Sie ihn unterschrieben zurück, gern als Scan oder Foto.'),
				array('anbei der Vertrag __REF__ für die laufenden Leistungen.',
					'Bitte lies ihn in Ruhe durch und schick ihn unterschrieben zurück, gern als Scan oder Foto.'),
				1),
			'thirdparty' => array('thirdparty', 'Nachricht', 'Nachricht von __MYCOMPANY_NAME__',
				array('&nbsp;'),
				array('&nbsp;'),
				0),
			// Only the middle of a ticket reply: Dolibarr puts TICKET_MESSAGE_MAIL_INTRO
			// before and TICKET_MESSAGE_MAIL_SIGNATURE after it (docs/rollout/ticket/).
			'ticket' => array('ticket_send', 'Ticket-Antwort', '[__MYCOMPANY_NAME__ - Ticket __REF__] __TICKET_SUBJECT__',
				array('zu Ihrer Anfrage „__TICKET_SUBJECT__“:', '&nbsp;'),
				null,
				0),
			'supplier_proposal' => array('supplier_proposal_send', 'Preisanfrage', 'Preisanfrage __REF__ von __MYCOMPANY_NAME__',
				array('anbei meine Preisanfrage __REF__.',
					'Bitte schicken Sie mir Preis und Lieferzeit je Position, gern auch eine gleichwertige Alternative.'),
				null,
				1),
			'order_supplier' => array('order_supplier_send', 'Bestellung', 'Bestellung __REF__ von __MYCOMPANY_NAME__',
				array('anbei meine Bestellung __REF__.',
					'Bitte bestätigen Sie kurz Preis und Liefertermin. Die Lieferadresse steht auf der Bestellung.'),
				null,
				1),
		);
	}

	/**
	 * Label as shown in Dolibarr: "Angebot (Sie)", "Angebot (Du)", "Preisanfrage".
	 *
	 * @param string $key Template key
	 * @param string $form 'sie', 'du' or '' for neutral
	 * @return string
	 */
	public static function label($key, $form)
	{
		$definitions = self::definitions();
		$base = $definitions[$key][1];
		return $form === '' ? $base : $base.' ('.($form === 'du' ? 'Du' : 'Sie').')';
	}

	/**
	 * The HTML of one template.
	 *
	 * @param string[] $paragraphs Body paragraphs
	 * @param string   $form       'sie', 'du' or '' (neutral: "Guten Tag,")
	 * @param string   $key        Template key
	 * @return string
	 */
	public static function html(array $paragraphs, $form, $key)
	{
		$p = self::P;
		if ($key === 'ticket') {
			$body = '';
			foreach ($paragraphs as $paragraph) {
				$body .= '<p style="'.$p.'">'.$paragraph.'</p>';
			}
			return $body;
		}
		$greeting = $form === 'du' ? '__ITTAB_HALLO__' : ($form === 'sie' ? '__ITTAB_GUTENTAG__' : 'Guten Tag,');
		$body = '<p style="'.$p.'">'.$greeting.'</p>';
		foreach ($paragraphs as $paragraph) {
			$body .= '<p style="'.$p.'">'.$paragraph.'</p>';
		}
		$extra = '';
		if ($key === 'propal') {
			// Button or nothing, depending on ITTABPDF_PORTAL_ENABLED.
			$extra .= '__ITTAB_KUNDENBEREICH_BLOCK__';
		}
		if ($key === 'invoice' || $key === 'deposit') {
			$extra .= '__ITTAB_UEBERWEISUNG__';
		}
		if ($key === 'shipping') {
			$extra .= '__ITTAB_SENDUNG__';
		}
		$facts = in_array($key, array('thirdparty'), true) ? '' : '__ITTAB_FAKTEN__';
		$reach = $form === 'du' ? 'Bei Fragen erreichst du mich unter __ITTAB_TELEFON__ oder per Antwort auf diese Mail.' : 'Bei Fragen erreichen Sie mich unter __ITTAB_TELEFON__ oder per Antwort auf diese E-Mail.';
		if ($key === 'thirdparty') {
			$reach = '';
		}

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="'.self::PAGE.'" style="background:'.self::PAGE.';">'
			.'<tr><td align="center" style="padding:24px 12px;">'
			.'<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:4px;">'
			// Logo (or the word mark without one) and the brand rule: ink with an orange start.
			.'<tr><td style="padding:26px 32px 16px 32px;font-family:'.self::FONT.';font-size:21px;font-weight:bold;letter-spacing:1px;color:'.self::INK.';">'
			.'__ITTAB_LOGO__</td></tr>'
			.'<tr><td style="padding:0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
			.'<td width="60" height="3" bgcolor="'.self::BRAND.'" style="font-size:0;line-height:0;">&nbsp;</td>'
			.'<td height="3" bgcolor="'.self::INK.'" style="font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr>'
			.'<tr><td style="padding:26px 32px 6px 32px;">'.$body.'</td></tr>'
			.($facts !== '' ? '<tr><td style="padding:0 32px 6px 32px;">'.$facts.'</td></tr>' : '')
			.($extra !== '' ? '<tr><td style="padding:12px 32px 0 32px;">'.$extra.'</td></tr>' : '')
			.'<tr><td style="padding:14px 32px 26px 32px;">'
			.($reach !== '' ? '<p style="'.$p.'">'.$reach.'</p>' : '')
			.'<p style="'.$p.'margin-bottom:0;">Viele Grüße<br><strong style="color:'.self::INK.';">__ITTAB_INHABER__</strong><br>__MYCOMPANY_NAME__</p></td></tr>'
			// Footer with the company data, as on the PDF.
			.'<tr><td bgcolor="'.self::INK.'" style="padding:16px 32px;border-radius:0 0 4px 4px;font-family:'.self::FONT.';font-size:12px;line-height:1.6;color:#C5D0DC;">'
			.'<strong style="color:#ffffff;">__MYCOMPANY_NAME__</strong> · __ITTAB_ADRESSE__<br>'
			.'<a href="mailto:__MYCOMPANY_EMAIL__" style="color:#C5D0DC;text-decoration:none;">__MYCOMPANY_EMAIL__</a> · __ITTAB_TELEFON__ · <a href="__MYCOMPANY_URL__" style="color:#C5D0DC;text-decoration:none;">__ITTAB_WEB__</a>'
			.'__ITTAB_RECHTLICHES__'
			.'</td></tr>'
			.'</table></td></tr></table>';
	}

	/**
	 * Insert the missing templates. Existing ones (also those edited by the
	 * owner) stay as they are: the label is the key.
	 *
	 * @param DoliDB $db     Database
	 * @param int    $entity Entity
	 * @return int           Number of templates added, -1 on error
	 */
	public static function install($db, $entity)
	{
		$added = 0;
		$position = 70;
		foreach (self::definitions() as $key => $definition) {
			list($type, , $topic, $sie, $du, $attach) = $definition;
			$variants = $du === null ? array('' => $sie) : array('sie' => $sie, 'du' => $du);
			foreach ($variants as $form => $paragraphs) {
				$position++;
				$label = self::label($key, $form);
				$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf'"
					." AND label = '".$db->escape($label)."' AND entity = ".((int) $entity);
				$resql = $db->query($sql);
				if (!$resql) {
					return -1;
				}
				if ($db->num_rows($resql) > 0) {
					continue;
				}
				$topicForm = ($key === 'shipping' && $form === 'du') ? 'Deine Lieferung: Lieferschein __REF__' : $topic;
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_email_templates (entity, module, type_template, lang, private, label, position, defaultfortype, enabled, active, topic, joinfiles, content, datec)"
					." VALUES (".((int) $entity).", 'ittabelanderpdf', '".$db->escape($type)."', '', 0, '".$db->escape($label)."', ".((int) $position).", 0,"
					." 'isModEnabled(\"ittabelanderpdf\")', 1, '".$db->escape($topicForm)."', '".((int) $attach)."', '".$db->escape(self::html($paragraphs, $form, $key))."', '".$db->idate(dol_now())."')";
				if (!$db->query($sql)) {
					return -1;
				}
				$added++;
			}
		}
		return $added;
	}

	/**
	 * Row id of an installed template by key and form, 0 when missing or switched off.
	 *
	 * @param DoliDB $db     Database
	 * @param string $key    Template key
	 * @param string $form   'sie', 'du' or ''
	 * @param int    $entity Entity
	 * @return int
	 */
	public static function find($db, $key, $form, $entity)
	{
		$definitions = self::definitions();
		if (!isset($definitions[$key])) {
			return 0;
		}
		if ($definitions[$key][4] === null) {
			$form = '';
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf' AND active = 1"
			." AND label = '".$db->escape(self::label($key, $form))."' AND entity IN (0, ".((int) $entity).") ORDER BY entity DESC";
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * The orange button to the document in the customer area.
	 *
	 * @param string $url Link
	 * @return string
	 */
	public static function portalButton($url)
	{
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 18px 0;"><tr>'
			.'<td bgcolor="'.self::BRAND.'" style="border-radius:3px;"><a href="'.dol_escape_htmltag($url).'" style="display:inline-block;padding:11px 20px;font-family:'.self::FONT.';font-size:14px;font-weight:bold;color:#ffffff;text-decoration:none;">'
			.'Angebot im Kundenbereich ansehen</a></td></tr></table>';
	}

	// ------------------------------------------------------------ rollout pieces
	// Self-contained blocks for texts that Dolibarr or another module puts
	// together from parts (ticket intro/signature, Mahnwesen letters): every
	// block closes its own tags, so the parts can stand next to anything.

	/**
	 * Logo or word mark and brand rule, at most 600 px wide.
	 *
	 * @param bool $logo __ITTAB_LOGO__ (for texts that run through Dolibarr's
	 *                   substitutions) instead of the fixed word mark
	 * @return string
	 */
	public static function headerBlock($logo = false)
	{
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;"><tr>'
			.'<td style="padding:8px 0 12px 0;font-family:'.self::FONT.';font-size:21px;font-weight:bold;letter-spacing:1px;color:'.self::INK.';">'
			.($logo ? '__ITTAB_LOGO__' : '<span style="color:'.self::BRAND.';">IT</span>&#8209;TABELANDER').'</td></tr>'
			.'<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
			.'<td width="60" height="3" bgcolor="'.self::BRAND.'" style="font-size:0;line-height:0;">&nbsp;</td>'
			.'<td height="3" bgcolor="'.self::INK.'" style="font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr>'
			.'<tr><td style="font-size:0;line-height:0;height:20px;">&nbsp;</td></tr></table>';
	}

	/**
	 * The navy footer with the company data written out (no variables).
	 *
	 * @param Societe $company Company
	 * @return string
	 */
	public static function footerBlock($company)
	{
		$address = trim(str_replace("\n", ', ', trim((string) $company->address)).', '.trim($company->zip.' '.$company->town), ', ');
		$phone = WerkbankBuilder::phone(!empty($company->phone_mobile) ? $company->phone_mobile : $company->phone);
		$web = WerkbankBuilder::bareUrl((string) $company->url);
		$legal = array_filter(array((string) $company->idprof4, (string) $company->idprof6, !empty($company->idprof2) ? 'Gerichtsstand '.$company->idprof2 : ''), 'strlen');
		$e = function ($text) {
			return dol_escape_htmltag((string) $text);
		};
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin-top:18px;"><tr>'
			.'<td bgcolor="'.self::INK.'" style="padding:16px 24px;border-radius:4px;font-family:'.self::FONT.';font-size:12px;line-height:1.6;color:#C5D0DC;">'
			.'<strong style="color:#ffffff;">'.$e($company->name).'</strong> · '.$e($address).'<br>'
			.'<a href="mailto:'.$e($company->email).'" style="color:#C5D0DC;text-decoration:none;">'.$e($company->email).'</a> · '.$e($phone)
			.' · <a href="'.$e($company->url).'" style="color:#C5D0DC;text-decoration:none;">'.$e($web).'</a>'
			.($legal ? '<br>'.$e(implode(' · ', $legal)) : '')
			.'</td></tr></table>';
	}

	/**
	 * A text paragraph in the template style.
	 *
	 * @param string $html Inner HTML
	 * @return string
	 */
	public static function paragraph($html)
	{
		return '<p style="'.self::P.'">'.$html.'</p>';
	}

	/**
	 * The facts box of the templates with fixed rows (label => value HTML);
	 * a row whose label starts with "!" shows its value large.
	 *
	 * @param array<string,string> $rows Rows
	 * @return string
	 */
	public static function factsBox(array $rows)
	{
		$html = '';
		foreach ($rows as $label => $value) {
			$big = strpos($label, '!') === 0;
			$html .= '<tr><td style="'.WerkbankMailData::LABEL.'">'.dol_escape_htmltag(ltrim($label, '!')).'</td>'
				.'<td align="right" style="'.WerkbankMailData::VALUE.($big ? 'font-size:20px;' : '').'">'.$value.'</td></tr>';
		}
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F7F9FB" style="max-width:600px;background:#F7F9FB;border-left:3px solid '.self::BRAND.';margin:4px 0 16px 0;">'
			.'<tr><td style="padding:10px 18px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'.$html.'</table></td></tr></table>';
	}
}
