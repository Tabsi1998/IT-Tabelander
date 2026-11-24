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

require_once __DIR__.'/werkbankmaildata.class.php';

/**
 * The e-mail templates in the Werkbank look, installed into
 * llx_c_email_templates on activation.
 *
 * Mail clients get tables and inline styles only, at most 600 px wide, white
 * like the PDF also where a client forces a dark mode, the logo as a hosted
 * PNG on white (public/maillogo.php). The
 * texts use Dolibarr's own variables (__REF__, __MYCOMPANY_NAME__, ...) and
 * the module's __ITTAB_*__ variables from
 * core/substitutions/functions_ittabelanderpdf.lib.php.
 */
class WerkbankMailTemplates
{
	const INK = '#1B3348';
	const BODY = '#3B4D63';
	const MUTED = '#4A5B6E';
	const BRAND = '#FE5A23';
	const LINE = '#D6DEE7';
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
	 * Hashes (md5 of topic, newline, content) of the module's earlier default
	 * templates. A template that still has one of them was never changed by
	 * the owner and may be brought up to date without asking (upgrade()).
	 */
	const PREVIOUS = array(
		'Angebot (Sie)' => array('2c854085ac0c32e001fa3de2828a9f53', '080d2b7005a417b657cbf8a3b7ed9c53'),
		'Angebot (Du)' => array('76c569235b26af58fdf5a94805b7f187', '3a93504d5ccd3930bdf2140da5506d62'),
		'Rechnung (Sie)' => array('447e59a45bcb1fa86d7d2e1b2e4317fd', '110e2ad76098a8080043c2c1411cf236'),
		'Rechnung (Du)' => array('3827562f6fff6e2365ec0eeede22fe92', '0c525f787cc1f34a25c125923117c191'),
		'Anzahlungsrechnung (Sie)' => array('cddea59d502c24ed4a04f7ee97a875d4', 'e36a8afeaa5187cc07ea20af3521af1d'),
		'Anzahlungsrechnung (Du)' => array('0fd4640a53d3f471fc644dd2b5a9a836', '3b2dbeec83d74774dad6bd1fee837833'),
		'Gutschrift (Sie)' => array('275f9ce09a59526e7ac4d16048404b05', '9c6b547285856a7fb5e4f3f1bcfc4e85'),
		'Gutschrift (Du)' => array('549cca1e45fed1517bc2f8adfd5457a6', 'c004c69efd41e9d41adc1fe270540f8e'),
		'Zahlungsbestätigung (Sie)' => array('f7dfceafbfd9aa1d7a7d26b4fd62f0a3', '6e391eae803713c30899b8c04b638979'),
		'Zahlungsbestätigung (Du)' => array('83cc70d6bc7085a06621bea78236781f', '861453fedfd4b9f345f620a272f5510f'),
		'Auftragsbestätigung (Sie)' => array('02735101ddc5dc45e100d570ae55ffac', '1539c48b08dc02efd14c95249a770152'),
		'Auftragsbestätigung (Du)' => array('aa5952774046351fd92d6656d88191c8', '1f55ed93f2f22ca31cf1d894481883a3'),
		'Lieferung (Sie)' => array('14e66c44aa976fa08db82f7580fc70c7', '5ae7833283f5bc38d8fd74d4b109ec5e'),
		'Lieferung (Du)' => array('40504c48ccc61838084f944dea3fcdc7', '8593ea8c34801216ce9f045ebd2099f6'),
		'Arbeitsbericht (Sie)' => array('f7b3ebc9d27cd2ca3b45ca78493ad06b', '84dfaafd5a91896801a934d0cd15ae2e'),
		'Arbeitsbericht (Du)' => array('b342bf7edfdd9323ec56a3b3770eaed5', '6ffbc72cbb66c2a4077cd7cba11aeb17'),
		'Vertrag (Sie)' => array('913c7e628356699e1a72f5cccf4ba035', '83ed5c7baf59bb1f67f2aeff53aa3452'),
		'Vertrag (Du)' => array('e033242fb7aa2c54561c796319ce1a89', '30a5dac241d93407a4ca906eb70fb49d'),
		'Nachricht (Sie)' => array('bb8d44cc5d8cb1bcec6122f97e931f6b', 'ba29ff846bd2289d38591d21af1cd028'),
		'Nachricht (Du)' => array('72e9fc6264c480b439fb9e646aad93d7', 'fcf5e22c8672d8974a8389e8bb72c4c1'),
		'Ticket-Antwort' => array('94ac499d4537e47f28b8fe68d4f9cea2'),
		'Preisanfrage' => array('89f601ecf0f9a750bf43c91fa70d8cb6', '5a577b89b3ba20582cad6027a58ea294'),
		'Bestellung' => array('9b600d84b9ae6c691e1d255f66ed8314', '2afdfa1b31c555e7edf380abfadf5053'),
	);

	/**
	 * Head of every full template: light only. Mail clients that honour
	 * color-scheme keep the paper look; the rest still gets white surfaces and
	 * explicit text colours inline. Apple's data detectors stay neutral.
	 */
	const HEAD = '<html lang="de"><head><meta charset="utf-8">'
		.'<meta name="viewport" content="width=device-width, initial-scale=1">'
		.'<meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light">'
		.'<style>:root{color-scheme:light only;supported-color-schemes:light;}'
		.'body{margin:0;padding:0;background:#ffffff;}'
		.'a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important;font-size:inherit!important;font-family:inherit!important;font-weight:inherit!important;line-height:inherit!important;}'
		.'u + .ittab-body a{color:inherit;text-decoration:none;}</style></head>';

	/**
	 * The HTML of one template: a full document, white like the PDF.
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
		$facts = $key === 'thirdparty' ? '' : '__ITTAB_FAKTEN__';
		$reach = $form === 'du' ? 'Bei Fragen erreichst du mich unter __ITTAB_TELEFON_LINK__ oder per Antwort auf diese Mail.' : 'Bei Fragen erreichen Sie mich unter __ITTAB_TELEFON_LINK__ oder per Antwort auf diese E-Mail.';
		if ($key === 'thirdparty') {
			$reach = '';
		}
		$cell = 'background:#ffffff;';

		return self::HEAD
			.'<body class="ittab-body" bgcolor="#ffffff" style="margin:0;padding:0;background:#ffffff;color:'.self::BODY.';">'
			.'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="background:#ffffff;">'
			.'<tr><td align="center" bgcolor="#ffffff" style="padding:16px 8px;'.$cell.'">'
			// The sheet: white, framed by a thin line, at most 600 px.
			.'<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:600px;background:#ffffff;border:1px solid '.self::LINE.';border-radius:4px;">'
			.'<tr><td bgcolor="#ffffff" style="padding:20px 24px 14px 16px;'.$cell.'font-family:'.self::FONT.';font-size:21px;font-weight:bold;letter-spacing:1px;color:'.self::INK.';">__ITTAB_LOGO__</td></tr>'
			.'<tr><td bgcolor="#ffffff" style="padding:0 24px;'.$cell.'">'.self::rule().'</td></tr>'
			.'<tr><td bgcolor="#ffffff" style="padding:24px 24px 4px 24px;'.$cell.'">'.$body.'</td></tr>'
			.($facts !== '' ? '<tr><td bgcolor="#ffffff" style="padding:0 24px 4px 24px;'.$cell.'">'.$facts.'</td></tr>' : '')
			.($extra !== '' ? '<tr><td bgcolor="#ffffff" style="padding:0 24px;'.$cell.'">'.$extra.'</td></tr>' : '')
			.'<tr><td bgcolor="#ffffff" style="padding:12px 24px 20px 24px;'.$cell.'">'
			.($reach !== '' ? '<p style="'.$p.'">'.$reach.'</p>' : '')
			.'<p style="'.$p.'margin-bottom:0;">'.($form === 'du' ? 'Liebe Grüße' : 'Mit freundlichen Grüßen').'<br><strong style="color:'.self::INK.';">__ITTAB_INHABER__</strong><br>__MYCOMPANY_NAME__</p></td></tr>'
			.'<tr><td bgcolor="#ffffff" style="padding:0 24px 22px 24px;'.$cell.'">'.self::footerLines(
				'__MYCOMPANY_NAME__', '__ITTAB_INHABER__', '__ITTAB_ADRESSE__', '__MYCOMPANY_EMAIL__', '__MYCOMPANY_URL__', '__ITTAB_WEB__', '__ITTAB_TELEFON_LINK__'
			).'</td></tr>'
			.'</table></td></tr></table></body></html>';
	}

	/**
	 * The brand rule: 2 px, orange start, ink after.
	 *
	 * @return string
	 */
	public static function rule()
	{
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
			.'<td width="56" height="2" bgcolor="'.self::BRAND.'" style="font-size:0;line-height:0;height:2px;background:'.self::BRAND.';">&nbsp;</td>'
			.'<td height="2" bgcolor="'.self::INK.'" style="font-size:0;line-height:0;height:2px;background:'.self::INK.';">&nbsp;</td></tr></table>';
	}

	/**
	 * The footer: a hairline, then four short grey lines. No register numbers
	 * (those stay on the PDF), the phone number as its own neutral tel: link.
	 * Arguments are HTML (variables or escaped values).
	 *
	 * @param string $name    Company name
	 * @param string $manager Manager
	 * @param string $address Street, ZIP and town
	 * @param string $email   E-mail address
	 * @param string $url     Web address (href)
	 * @param string $web     Web address as shown
	 * @param string $phone   Phone link
	 * @return string
	 */
	public static function footerLines($name, $manager, $address, $email, $url, $web, $phone)
	{
		$link = 'color:'.self::MUTED.';text-decoration:none;';
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="background:#ffffff;"><tr>'
			.'<td bgcolor="#ffffff" style="border-top:1px solid '.self::LINE.';padding-top:12px;background:#ffffff;font-family:'.self::FONT.';font-size:12px;line-height:1.6;color:'.self::MUTED.';">'
			.'<strong style="color:'.self::INK.';">'.$name.'</strong> · '.$manager.'<br>'
			.$address.'<br>'
			.'<a href="mailto:'.$email.'" style="'.$link.'">'.$email.'</a> · <a href="'.$url.'" style="'.$link.'">'.$web.'</a><br>'
			.$phone
			.'</td></tr></table>';
	}

	/**
	 * Every template as rows: label => (key, form, type, topic, content, attach).
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string,4:string,5:int}>
	 */
	public static function variants()
	{
		$rows = array();
		foreach (self::definitions() as $key => $definition) {
			list($type, , $topic, $sie, $du, $attach) = $definition;
			$forms = $du === null ? array('' => $sie) : array('sie' => $sie, 'du' => $du);
			foreach ($forms as $form => $paragraphs) {
				$topicForm = ($key === 'shipping' && $form === 'du') ? 'Deine Lieferung: Lieferschein __REF__' : $topic;
				$rows[self::label($key, $form)] = array($key, $form, $type, $topicForm, self::html($paragraphs, $form, $key), (int) $attach);
			}
		}
		return $rows;
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
		foreach (self::variants() as $label => $row) {
			$position++;
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf'"
				." AND label = '".$db->escape($label)."' AND entity = ".((int) $entity);
			$resql = $db->query($sql);
			if (!$resql) {
				return -1;
			}
			if ($db->num_rows($resql) > 0) {
				continue;
			}
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_email_templates (entity, module, type_template, lang, private, label, position, defaultfortype, enabled, active, topic, joinfiles, content, datec)"
				." VALUES (".((int) $entity).", 'ittabelanderpdf', '".$db->escape($row[2])."', '', 0, '".$db->escape($label)."', ".((int) $position).", 0,"
				." 'isModEnabled(\"ittabelanderpdf\")', 1, '".$db->escape($row[3])."', '".((int) $row[5])."', '".$db->escape($row[4])."', '".$db->idate(dol_now())."')";
			if (!$db->query($sql)) {
				return -1;
			}
			$added++;
		}
		return $added;
	}

	/**
	 * Bring the module's own templates (module = ittabelanderpdf) up to date.
	 * Without $force only templates that still hold an earlier default text
	 * change; templates the owner edited are counted, not touched. Templates
	 * of other modules and the owner's own templates are never read here.
	 *
	 * @param DoliDB $db     Database
	 * @param int    $entity Entity
	 * @param bool   $force  Overwrite edited ones too (asked for on the setup page)
	 * @param bool   $dry    Only count, change nothing (state on the setup page)
	 * @return array{updated:int,current:int,edited:int}|int  Counts, -1 on error
	 */
	public static function upgrade($db, $entity, $force = false, $dry = false)
	{
		$counts = array('updated' => 0, 'current' => 0, 'edited' => 0);
		foreach (self::variants() as $label => $row) {
			$resql = $db->query("SELECT rowid, topic, content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf'"
				." AND label = '".$db->escape($label)."' AND entity = ".((int) $entity));
			if (!$resql) {
				return -1;
			}
			while ($obj = $db->fetch_object($resql)) {
				$hash = md5($obj->topic."\n".$obj->content);
				if ($hash === md5($row[3]."\n".$row[4])) {
					$counts['current']++;
					continue;
				}
				$untouched = isset(self::PREVIOUS[$label]) && in_array($hash, self::PREVIOUS[$label], true);
				if (!$untouched && !$force) {
					$counts['edited']++;
					continue;
				}
				if ($dry) {
					$counts['updated']++;
					continue;
				}
				$sql = "UPDATE ".MAIN_DB_PREFIX."c_email_templates SET type_template = '".$db->escape($row[2])."', topic = '".$db->escape($row[3])."',"
					." content = '".$db->escape($row[4])."', joinfiles = '".((int) $row[5])."'"
					." WHERE rowid = ".((int) $obj->rowid)." AND module = 'ittabelanderpdf'";
				if (!$db->query($sql)) {
					return -1;
				}
				$counts['updated']++;
			}
		}
		return $counts;
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
			.'<td bgcolor="'.self::BRAND.'" style="border-radius:3px;background:'.self::BRAND.';"><a href="'.dol_escape_htmltag($url).'" style="display:inline-block;padding:12px 20px;font-family:'.self::FONT.';font-size:15px;font-weight:bold;line-height:1.2;color:#ffffff;text-decoration:none;">'
			.'Im Kundenbereich ansehen</a></td></tr></table>';
	}

	// ------------------------------------------------------------ rollout pieces
	// Self-contained blocks for texts that Dolibarr or another module puts
	// together from parts (ticket intro/signature, Mahnwesen letters): every
	// block closes its own tags, so the parts can stand next to anything.

	/**
	 * Logo and brand rule on white, at most 600 px wide.
	 *
	 * @param string $logo Logo HTML or a variable (__ITTAB_LOGO__)
	 * @return string
	 */
	public static function headerBlock($logo)
	{
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="max-width:600px;background:#ffffff;">'
			.'<tr><td bgcolor="#ffffff" style="padding:4px 0 12px 0;background:#ffffff;font-family:'.self::FONT.';font-size:21px;font-weight:bold;letter-spacing:1px;color:'.self::INK.';">'.$logo.'</td></tr>'
			.'<tr><td bgcolor="#ffffff" style="background:#ffffff;">'.self::rule().'</td></tr>'
			.'<tr><td bgcolor="#ffffff" style="font-size:0;line-height:0;height:20px;background:#ffffff;">&nbsp;</td></tr></table>';
	}

	/**
	 * The footer with the company data written out (no variables), for texts
	 * that Dolibarr does not run through the module's variables.
	 *
	 * @param Societe $company Company
	 * @return string
	 */
	public static function footerBlock($company)
	{
		$e = function ($text) {
			return dol_escape_htmltag((string) $text);
		};
		$address = trim(str_replace("\n", ', ', trim((string) $company->address)).', '.trim($company->zip.' '.$company->town), ', ');
		$phone = !empty($company->phone_mobile) ? $company->phone_mobile : $company->phone;
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="max-width:600px;margin-top:18px;background:#ffffff;"><tr><td bgcolor="#ffffff" style="background:#ffffff;">'
			.self::footerLines($e($company->name), $e($company->managers), WerkbankMailData::noAutoLink($e($address)), $e($company->email), $e($company->url),
				$e(WerkbankBuilder::bareUrl((string) $company->url)), WerkbankMailData::phoneLink($phone))
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
		return WerkbankMailData::box($html);
	}
}
