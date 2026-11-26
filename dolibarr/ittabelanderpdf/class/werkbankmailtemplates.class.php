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
 * transparent PNG (public/maillogo.php). The
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
	const P = 'margin:0 0 16px 0;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:1.6;color:#3B4D63;';

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
			'qsreport' => array('fichinter_send', 'Prüfprotokoll', 'Prüfprotokoll __REF__ von __MYCOMPANY_NAME__',
				array('anbei das Prüfprotokoll zum Serviceauftrag __REF__: was geprüft wurde, mit den Messwerten und unseren Unterschriften.',
					'Bitte heben Sie es auf. Falls etwas nicht stimmt, sagen Sie mir bitte Bescheid.'),
				array('anbei das Prüfprotokoll zum Serviceauftrag __REF__: was geprüft wurde, mit den Messwerten und unseren Unterschriften.',
					'Heb es bitte auf. Falls etwas nicht stimmt, sag mir bitte Bescheid.'),
				1),
			'intake' => array('ticket_send', 'Geräteannahme', 'Geräteannahme __REF__ – __MYCOMPANY_NAME__',
				array('danke, Ihr Gerät ist bei mir angekommen. Anbei der Geräteannahmeschein mit allem, was wir bei der Abgabe festgehalten haben.',
					'Sobald ich weiß, was dem Gerät fehlt, melde ich mich mit einem Kostenvoranschlag.'),
				array('danke, dein Gerät ist bei mir angekommen. Anbei der Geräteannahmeschein mit allem, was wir bei der Abgabe festgehalten haben.',
					'Sobald ich weiß, was dem Gerät fehlt, melde ich mich mit einem Kostenvoranschlag.'),
				1),
			// The copy after both signed on the device (sign.php).
			'unterlage' => array('thirdparty', 'Unterschriebene Unterlage', 'Ihre unterschriebene Unterlage von __MYCOMPANY_NAME__',
				array('anbei die Unterlage mit unseren Unterschriften als PDF, für Ihre Unterlagen. Sie müssen nichts weiter tun.'),
				array('anbei die Unterlage mit unseren Unterschriften als PDF, für deine Unterlagen. Du musst nichts weiter tun.'),
				1),
			// Company documents to the customer (tab "Unterlagen", "Unterlagen beilegen"):
			// subject, opening, list with what to do and closing follow the attached files (WerkbankDocMail).
			'docsend' => array('thirdparty', 'Unterlagen zusenden', '__ITTAB_UNTERLAGEN_BETREFF__',
				array('__ITTAB_UNTERLAGEN_EINLEITUNG__', '__ITTAB_UNTERLAGEN_LISTE__', '__ITTAB_UNTERLAGEN_SCHLUSS__'),
				array('__ITTAB_UNTERLAGEN_EINLEITUNG_DU__', '__ITTAB_UNTERLAGEN_LISTE_DU__', '__ITTAB_UNTERLAGEN_SCHLUSS_DU__'),
				0),
			'contract' => array('contract', 'Vertrag', 'Vertrag __REF__ von __MYCOMPANY_NAME__',
				array('anbei der Vertrag __REF__ für die laufenden Leistungen.',
					'Bitte lesen Sie ihn in Ruhe durch und schicken Sie ihn unterschrieben zurück, gern als Scan oder Foto.'),
				array('anbei der Vertrag __REF__ für die laufenden Leistungen.',
					'Bitte lies ihn in Ruhe durch und schick ihn unterschrieben zurück, gern als Scan oder Foto.'),
				1),
			// One empty paragraph to write in (<br>, the way CKEditor keeps an empty line).
			'thirdparty' => array('thirdparty', 'Nachricht', 'Nachricht von __MYCOMPANY_NAME__',
				array('<br>'),
				array('<br>'),
				0),
			// A ticket reply: Dolibarr puts TICKET_MESSAGE_MAIL_INTRO ("Guten Tag,")
			// before it; closing and signature (__USER_SIGNATURE__) are part of the
			// template, TICKET_MESSAGE_MAIL_SIGNATURE stays empty (docs/rollout/ticket/).
			'ticket' => array('ticket_send', 'Ticket-Antwort', '[__MYCOMPANY_NAME__ - Ticket __REF__] __TICKET_SUBJECT__',
				array('zu Ihrer Anfrage „__TICKET_SUBJECT__“:', "\u{00A0}"),
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
	 * Label as shown in Dolibarr: "Angebot · Sie", "Angebot · Du", "Preisanfrage".
	 * No brackets: Dolibarr's mail form shows only the text in brackets of a
	 * label (it is meant for a translation key), "Angebot (Sie)" became "Sie".
	 *
	 * @param string $key Template key
	 * @param string $form 'sie', 'du' or '' for neutral
	 * @return string
	 */
	public static function label($key, $form)
	{
		$definitions = self::definitions();
		$base = $definitions[$key][1];
		return $form === '' ? $base : $base.' · '.($form === 'du' ? 'Du' : 'Sie');
	}

	/** Earlier names of a template (key => name), for renameLabels(). */
	const OLD_NAMES = array('unterlage' => array('Unterlage'));

	/**
	 * The labels a template had before: with the form in brackets (up to
	 * 1.7.0) and under an earlier name.
	 *
	 * @param string $key  Template key
	 * @param string $form 'sie', 'du' or ''
	 * @return string[]
	 */
	public static function oldLabels($key, $form)
	{
		$definitions = self::definitions();
		$names = array_merge(array($definitions[$key][1]), isset(self::OLD_NAMES[$key]) ? self::OLD_NAMES[$key] : array());
		$labels = array();
		foreach ($names as $base) {
			if ($form === '') {
				$labels[] = $base;
				continue;
			}
			$word = $form === 'du' ? 'Du' : 'Sie';
			$labels[] = $base.' ('.$word.')';
			$labels[] = $base.' · '.$word;
		}
		return array_values(array_diff(array_unique($labels), array(self::label($key, $form))));
	}

	/**
	 * Give the module's templates (module = ittabelanderpdf) of 1.7.0 and
	 * earlier their new label; topic and text stay as they are, edited ones
	 * too. A template whose new label exists already keeps its old one.
	 *
	 * @param DoliDB $db     Database
	 * @param int    $entity Entity
	 * @return int           Templates renamed, -1 on error
	 */
	public static function renameLabels($db, $entity)
	{
		$renamed = 0;
		foreach (self::variants() as $label => $row) {
			foreach (self::oldLabels($row[0], $row[1]) as $old) {
				$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf'"
					." AND label = '".$db->escape($label)."' AND entity = ".((int) $entity));
				if (!$resql) {
					return -1;
				}
				if ($db->num_rows($resql) > 0) {
					break;
				}
				if (!$db->query("UPDATE ".MAIN_DB_PREFIX."c_email_templates SET label = '".$db->escape($label)."'"
					." WHERE module = 'ittabelanderpdf' AND label = '".$db->escape($old)."' AND entity = ".((int) $entity))) {
					return -1;
				}
				$renamed += (int) $db->affected_rows(true);
			}
		}
		return $renamed;
	}

	/**
	 * Hashes (md5 of topic, newline, content) of the module's earlier default
	 * templates (1.0.0 to 1.0.8). A template that still has one of them was never changed by
	 * the owner and may be brought up to date without asking (upgrade()). The last
	 * hash of each is the text of 1.8.x (before the signature layout of 1.9.0).
	 * Keyed by the label since 1.7.1 (renameLabels() runs first).
	 */
	const PREVIOUS = array(
		'Angebot · Sie' => array('2c854085ac0c32e001fa3de2828a9f53', '080d2b7005a417b657cbf8a3b7ed9c53', '686a4e515523035d24f656957af888cc', 'e1dd97ea733f339f518f488a7fddf187', 'd2de25224900680ce23db86270aff646'),
		'Angebot · Du' => array('76c569235b26af58fdf5a94805b7f187', '3a93504d5ccd3930bdf2140da5506d62', '3c9473892d7ea61f6fae23f636634035', 'da1493aa288754f5868e71a6abcfef7e', 'ded3a221ed313b0eb9b01380a8d620cf'),
		'Rechnung · Sie' => array('447e59a45bcb1fa86d7d2e1b2e4317fd', '110e2ad76098a8080043c2c1411cf236', 'd37d5990d0bce4a20b855fe3f674e1e4', '4d568dcef1900d219dbd93d4acc3ccdc', 'fb3202f47da121f61ee6566641fbf4bd', 'e04999cb1d28bfa41bbb08ea04572229'),
		'Rechnung · Du' => array('3827562f6fff6e2365ec0eeede22fe92', '0c525f787cc1f34a25c125923117c191', 'f5d5279631ce5b7dac7f3db1ef881ecb', '4b9d2d6edc47adb230afae899e3834d0', '65ac37c0bde73a4c0b560bf7c0e15f2a', 'deeed83f2490ba1990bbe63122063ef0'),
		'Anzahlungsrechnung · Sie' => array('cddea59d502c24ed4a04f7ee97a875d4', 'e36a8afeaa5187cc07ea20af3521af1d', '3b2f3f94d062a6beee48c8806e4284b0', 'e3309db89998b07167ab28915f9150e2', 'deab9dea2be76f22602c85c2195e448d', '285eda356a3b7140ee9d23682993e400'),
		'Anzahlungsrechnung · Du' => array('0fd4640a53d3f471fc644dd2b5a9a836', '3b2dbeec83d74774dad6bd1fee837833', 'ba479e50ab76a84c5998c962ba3bd5a4', '61e9ea82d7ee65f6f8c89033ad1e56ad', '23833eb309f16a6b6ea5f8047056f5bc', '17628c2f1e5fdbc7ad79e9584b2c2656'),
		'Gutschrift · Sie' => array('275f9ce09a59526e7ac4d16048404b05', '9c6b547285856a7fb5e4f3f1bcfc4e85', '96a31e23d39e2352522f00279f41dea5', '13218fa26dba21107b607f99505ba337', 'b41adb749e6dc2c365b38b1629e8a3fb', 'b1c7e5bcad2d0f3631bbdba85d068e91'),
		'Gutschrift · Du' => array('549cca1e45fed1517bc2f8adfd5457a6', 'c004c69efd41e9d41adc1fe270540f8e', 'c3bd33952ea057c8a20243b0ac8ea098', 'a33c96057dbc398b74b118e3c677eeb1', '925d7707f935d79fa065310b5cdb9883', 'b06e261ae451026bff665d7c943f8455'),
		'Zahlungsbestätigung · Sie' => array('f7dfceafbfd9aa1d7a7d26b4fd62f0a3', '6e391eae803713c30899b8c04b638979', 'eaf6a3eabd9d3a7eb3c671ad52940084', '964eb0451587b530d26c9a24097a66f7', 'ef0084a2ced0a0aabdcff781df117aea', '6d60b5d6209fe503cf487b7d841eeae2'),
		'Zahlungsbestätigung · Du' => array('83cc70d6bc7085a06621bea78236781f', '861453fedfd4b9f345f620a272f5510f', 'a260d86feb58ca5f72ca42f9af72a30e', '0e212a84e0e647e9bd281c4c892e5b80', '4aaa87a7d75be765407f24fd5eea2e8c', '3c4fa02b701ca0357ffc63430e3a2a5d'),
		'Auftragsbestätigung · Sie' => array('02735101ddc5dc45e100d570ae55ffac', '1539c48b08dc02efd14c95249a770152', 'f01c0245583a92d47cdfe445df7c7ef8', 'e47e55e39386c9700986bcd89ab326dc', 'b265cc6453cd3584340c91291af958fb', 'a77b4591b236b09626fd730bf6539eac'),
		'Auftragsbestätigung · Du' => array('aa5952774046351fd92d6656d88191c8', '1f55ed93f2f22ca31cf1d894481883a3', '58700b677a4a70afcb39e7c5b1971c63', 'dd4a728afe9f685eedf7fc65186801e0', '87adf4f13b66cff51d0de9facea06d3f', '9fb91cc6d9c4a6a1a2cf44d9619afb62'),
		'Lieferung · Sie' => array('14e66c44aa976fa08db82f7580fc70c7', '5ae7833283f5bc38d8fd74d4b109ec5e', '8f4940f4dd4346e4bb0691026463b1cf', '4c36aa9e3bc7dbf2a904fa3381b1282d', 'df4e7aac30d0e4aef43d9d02778dbf30', 'd8021ea9bbbc5f8b464a96a9587ac617'),
		'Lieferung · Du' => array('40504c48ccc61838084f944dea3fcdc7', '8593ea8c34801216ce9f045ebd2099f6', 'f3c4c588dcf15474e710e2fd6e306594', 'e7b2bb00a11b569b20bf61fa16044ccf', 'c3419cbe1a22d6ad6433b6620a048e09', 'f318b7a6205678b7bc691bde9c5a0f02'),
		'Arbeitsbericht · Sie' => array('f7b3ebc9d27cd2ca3b45ca78493ad06b', '84dfaafd5a91896801a934d0cd15ae2e', 'd37a84498669f01b2f9c5c6e1449f66a', '0b61cba4c886aac7ce3794c04dcf28d2', 'd5325b42b7902e837767e992ce0dd7f7', '130896689fa7883e2e3f41cc2b22a9c5'),
		'Arbeitsbericht · Du' => array('b342bf7edfdd9323ec56a3b3770eaed5', '6ffbc72cbb66c2a4077cd7cba11aeb17', '243d7c055dc86575b0fbc2a63f14b88a', '8ed055521899be433fb214fedf71b21a', '8042831663dd002e274ab08377b66acb', '9966717aebc27fe729efb561bb0d89fe'),
		'Vertrag · Sie' => array('913c7e628356699e1a72f5cccf4ba035', '83ed5c7baf59bb1f67f2aeff53aa3452', '087b9c296330f5ee19bc45abe1820d9a', 'ebf5f57d1d6f1813cc45296692417dcb', 'd5b202757e2c1e6c773b160e6d3a262d', '6867115facb707a2d62fa45e975e8d03'),
		'Vertrag · Du' => array('e033242fb7aa2c54561c796319ce1a89', '30a5dac241d93407a4ca906eb70fb49d', 'a6d0569ef0ec9cee8032b3ce1598982e', '6d2dc895b6899d58d8cdac38f4dee41f', '7fe84aa5b60ae6b9087a30b826ab3f71', '2f699ae6898637ba162f89986338e1ac'),
		'Unterschriebene Unterlage · Sie' => array('f1c81769145a6ba7655b5015b6d6ea0e', '8e787f405d22012c4db70e7742787222'),
		'Unterschriebene Unterlage · Du' => array('13805e1aa00ea4ba264f383a5f70107a', '7ce51d9dd140f0cfb5450c558b854d1d'),
		'Nachricht · Sie' => array('bb8d44cc5d8cb1bcec6122f97e931f6b', 'ba29ff846bd2289d38591d21af1cd028', 'fc78ed7dab76930fe92ad497c1be2a31', 'd872cc822a07cc54e4a36ca88af72ab3', 'b1f1f4864caa9ba237e2a3c692aa03a1'),
		'Nachricht · Du' => array('72e9fc6264c480b439fb9e646aad93d7', 'fcf5e22c8672d8974a8389e8bb72c4c1', '8934af8c870b8fb2d58301138db43721', '3b4bd725d0cded1ae76ac37f8956be90', 'bc80f55c5c9e1eaece2c2615fab0f73b'),
		'Ticket-Antwort' => array('94ac499d4537e47f28b8fe68d4f9cea2', '5f242533ce3713418b9e8b092caef823', '348b5ca6d4b8f21529788f62e20dafb2'),
		'Preisanfrage' => array('89f601ecf0f9a750bf43c91fa70d8cb6', '5a577b89b3ba20582cad6027a58ea294', 'f0276b6caf46f39182484550914d80e5', 'e37133974650aa8e5fefc8da131da462', '4b5adb4789bc252dccd59ad541049cca'),
		'Bestellung' => array('9b600d84b9ae6c691e1d255f66ed8314', '2afdfa1b31c555e7edf380abfadf5053', 'e6e3b35c40870d6712bc8da195c371f0', 'f1f13a936df39fd711f0f9654bd72994', '5ad74fd0aaac1ea91f70aa45e4bebdc1'),
		'Prüfprotokoll · Sie' => array('ec652ce31473a2265cbe23166d118109'),
		'Prüfprotokoll · Du' => array('08300a6261af90a02809c208804d7ad0'),
		'Geräteannahme · Sie' => array('e6e4b909fd12a61aa530888d978ed32b'),
		'Geräteannahme · Du' => array('04432ac903f1553dda33f46119d0ab44'),
		'Unterlagen zusenden · Sie' => array('222d002b8456f16583dd4c05c359d346'),
		'Unterlagen zusenden · Du' => array('ed547ad1e6e5fc305cde937dbe2bf10f'),
	);

	/**
	 * The HTML of one template, as stored in llx_c_email_templates.
	 *
	 * Only <div>, <p>, <span>, <a>, <strong> and <br> with inline styles: no
	 * <style> (Dolibarr's WAF refuses "<style" in every form, so the template
	 * could not be saved or sent by hand), no layout tables (editors and
	 * webmail clients drew frames around their cells), no classes (the
	 * restricthtml sanitizer drops them). The light look stands on its own,
	 * also when a mail app darkens it by force; the dark design is added only
	 * by the automatic send (wrapDocument()).
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
			// The ticket editor shows this text as written; its font comes from the
			// mail around it, so the paragraphs carry spacing and colour only.
			$t = 'margin:0 0 16px 0;line-height:1.6;color:'.self::BODY.';';
			$body = '';
			foreach ($paragraphs as $paragraph) {
				$body .= '<p style="'.$t.'">'.$paragraph.'</p>';
			}
			return $body.'<p style="'.$t.'">Mit freundlichen Grüßen</p>__USER_SIGNATURE__';
		}
		$greeting = $form === 'du' ? '__ITTAB_HALLO__' : ($form === 'sie' ? '__ITTAB_GUTENTAG__' : 'Guten Tag,');
		$body = '<p style="'.$p.'">'.self::text($greeting).'</p>';
		foreach ($paragraphs as $paragraph) {
			// The document variables are paragraphs and a list of their own.
			$body .= strpos($paragraph, '__ITTAB_UNTERLAGEN_') === 0 ? $paragraph."
"
				// The empty line to write in stays a bare <br> (the editor drops an empty span).
				: '<p style="'.$p.'">'.($paragraph === '<br>' ? $paragraph : self::text($paragraph)).'</p>';
		}
		// A third party has no facts box (no number, no amount).
		$plain = in_array($key, array('thirdparty', 'unterlage', 'docsend'), true);
		if (!$plain) {
			$body .= '__ITTAB_FAKTEN__';
		}
		if ($key === 'invoice' || $key === 'deposit') {
			$body .= '__ITTAB_UEBERWEISUNG__';
		}
		if ($key === 'shipping') {
			$body .= '__ITTAB_SENDUNG__';
		}
		if (!$plain && !in_array($key, array('supplier_proposal', 'order_supplier'), true)) {
			// Button into the customer area or nothing, depending on ITTABPDF_PORTAL_ENABLED.
			$body .= '__ITTAB_KUNDENBEREICH_BLOCK__';
		}
		// Questions go by e-mail or through the customer area, not by phone
		// (the number stays in the footer only).
		if (in_array($key, array('supplier_proposal', 'order_supplier'), true)) {
			$body .= '<p style="'.$p.'">'.self::text('Bei Fragen antworten Sie einfach auf diese E-Mail.').'</p>';
		} elseif ($key !== 'thirdparty') {
			$body .= '<p style="'.$p.'">'.self::text($form === 'du' ? '__ITTAB_FRAGEN_SATZ_DU__' : '__ITTAB_FRAGEN_SATZ__').'</p>';
		}
		// No logo or line on top: the mail starts with the greeting and ends with
		// the owner's signature (1.9.0); the frame comes only with the automatic send.
		return $body.self::signature($form === 'du' ? 'Liebe Grüße' : 'Mit freundlichen Grüßen', '__ITTAB_INHABER__', '__MYCOMPANY_NAME__', '__ITTAB_LOGO__',
			'__ITTAB_ADRESSE__', '__MYCOMPANY_EMAIL__', '__MYCOMPANY_URL__', '__ITTAB_WEB__', '__ITTAB_TELEFON_LINK__', $key === 'thirdparty' ? 8 : 24);
	}

	/**
	 * Text with its font as a span: Dolibarr's editor (CKEditor with its
	 * content filter, also in the mail form) keeps font, size and colour on
	 * spans only and drops them from paragraphs and divs.
	 *
	 * @param string $inner HTML
	 * @param int    $size  Font size in px
	 * @param string $color Colour
	 * @return string
	 */
	public static function text($inner, $size = 16, $color = self::BODY)
	{
		return '<span style="font-family:'.self::FONT.';font-size:'.((int) $size).'px;color:'.$color.';">'.$inner.'</span>';
	}

	/**
	 * The closing and signature, in the style of the owner's own signature
	 * (sender profile): closing words, name and company, a plain rule, the
	 * logo (180 px), address, e-mail, web and phone. Only what the editor
	 * keeps and the WAF lets pass: paragraphs, one div, <hr>, inline styles.
	 * Arguments are HTML (variables or escaped values).
	 *
	 * @param string $words   "Mit freundlichen Grüßen" / "Liebe Grüße"
	 * @param string $name    Name
	 * @param string $company Company
	 * @param string $logo    Logo (image) or variable
	 * @param string $address Street, ZIP and town
	 * @param string $email   E-mail address
	 * @param string $url     Web address (href)
	 * @param string $web     Web address as shown
	 * @param string $phone   Phone link
	 * @param int    $gap     Space above the closing words in px
	 * @return string
	 */
	public static function signature($words, $name, $company, $logo, $address, $email, $url, $web, $phone, $gap = 24)
	{
		$link = 'color:'.self::MUTED.';text-decoration:none;';
		// Every block carries its font: hand-sent mails have no frame around them.
		$font = 'font-family:'.self::FONT.';line-height:1.6;';
		return ($words !== '' ? '<p style="'.self::P.'margin:'.((int) $gap).'px 0 16px 0;">'.self::text($words).'</p>' : '')
			.'<div style="color:#3b4d63;'.$font.'font-size:16px;">'
			.'<p style="margin:0 0 10px 0;line-height:1.6;">'.self::text('<strong>'.$name.'</strong><br>'.$company).'</p>'
			// A plain rule: the editor drops any style on it.
			.'<hr />'
			.'<p style="margin:0 0 8px 0;">'.$logo.'</p>'
			.'<p style="margin:0;line-height:1.6;">'.self::text($address.'<br><a href="mailto:'.$email.'" style="'.$link.'">'.$email.'</a> · <a href="'.$url.'" style="'.$link.'">'.$web.'</a><br>'.$phone, 14).'</p>'
			.'</div>';
	}

	/**
	 * The signature with the company data written out, for texts outside the
	 * module's variables (ticket confirmation, rollout texts).
	 *
	 * @param string  $words   Closing words
	 * @param Societe $company Company
	 * @param string  $logo    Logo HTML
	 * @return string
	 */
	public static function signatureFor($words, $company, $logo)
	{
		$e = function ($text) {
			return dol_escape_htmltag((string) $text);
		};
		$address = trim(str_replace("\n", ', ', trim((string) $company->address)).', '.trim($company->zip.' '.$company->town), ', ');
		$phone = !empty($company->phone_mobile) ? $company->phone_mobile : $company->phone;
		return self::signature($words, $e($company->managers), $e($company->name), $logo, $e($address), $e($company->email), $e($company->url),
			$e(WerkbankBuilder::bareUrl((string) $company->url)), WerkbankMailData::phoneLink($phone));
	}

	/**
	 * The sheet: white, 560 px at most, logo, the short orange line, the
	 * text, then the footer. Kept for texts of earlier versions; new mails
	 * use signature() and the frame of wrapDocument().
	 *
	 * @param string $logo   Logo HTML or variable
	 * @param string $body   Text
	 * @param string $footer Footer (footerLines())
	 * @return string
	 */
	public static function sheet($logo, $body, $footer)
	{
		return '<div style="max-width:560px;margin:0 auto;padding:12px 20px 28px 20px;background:#ffffff;font-family:'.self::FONT.';font-size:16px;line-height:1.6;color:'.self::BODY.';">'
			.'<div style="margin:0 0 18px 0;">'.$logo.'</div>'
			.self::accent()
			.$body
			.$footer
			.'</div>';
	}

	/**
	 * The short orange line below the logo: a border, not a filled cell, so
	 * no client can make it taller; height 0 keeps its placeholder invisible.
	 *
	 * @return string
	 */
	public static function accent()
	{
		return '<div style="width:56px;height:0;max-height:0;overflow:hidden;border-top:2px solid '.self::BRAND.';margin:0 0 28px 0;font-size:1px;line-height:1px;">&nbsp;</div>';
	}

	/**
	 * Closing line, name in ink, company.
	 *
	 * @param string $words   "Mit freundlichen Grüßen" / "Liebe Grüße"
	 * @param string $name    Name (HTML)
	 * @param string $company Company (HTML)
	 * @param int    $gap     Space above in px (the message has its writing line right before)
	 * @return string
	 */
	public static function closing($words, $name, $company, $gap = 24)
	{
		return '<p style="'.self::P.'margin:'.((int) $gap).'px 0 0 0;">'.$words.'<br><strong style="color:'.self::INK.';">'.$name.'</strong><br>'.$company.'</p>';
	}

	/**
	 * The footer: one fine divider, then four short grey lines. No register
	 * numbers (those stay on the PDF), the phone number as its own neutral
	 * tel: link. Arguments are HTML (variables or escaped values).
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
		return '<div style="margin:32px 0 0 0;padding:16px 0 0 0;border-top:1px solid '.self::LINE.';font-family:'.self::FONT.';font-size:13px;line-height:1.7;color:'.self::MUTED.';">'
			.'<strong style="color:'.self::INK.';">'.$name.'</strong> · '.$manager.'<br>'
			.$address.'<br>'
			.'<a href="mailto:'.$email.'" style="'.$link.'">'.$email.'</a> · <a href="'.$url.'" style="'.$link.'">'.$web.'</a><br>'
			.$phone
			.'</div>';
	}

	/**
	 * The full document the automatic send puts around a template: light and
	 * dark. The template itself carries no classes (Dolibarr strips them when
	 * saving), so before wrapping, every tag gets a class from its inline
	 * colours (classFromColours). Samsung Mail ignores attribute selectors in
	 * its dark-mode media query but honours classes (seen 24.11.2026).
	 * Attribute selectors stay as a fallback for other clients.
	 *
	 * @param string $content Template after substitution
	 * @return string
	 */
	public static function wrapDocument($content)
	{
		if (preg_match('/^\s*<html/i', $content)) {
			return $content;
		}
		$content = self::classFromColours($content);
		$dark = array(
			'.wb-bg' => 'background:#0D1823 !important;background-color:#0D1823 !important;',
			'.wb-tx' => 'color:#C5D0DC !important;',
			'.wb-ink' => 'color:#EEF2F6 !important;',
			'.wb-mu' => 'color:#A9B8CA !important;',
			'.wb-box' => 'background:#14263A !important;background-color:#14263A !important;',
			'.wb-ln' => 'border-color:#2C4763 !important;',
			'body, .ittab-page' => 'background:#0D1823 !important;background-color:#0D1823 !important;',
			'[style*="background:#ffffff"], [style*="background:#FFFFFF"]' => 'background:#0D1823 !important;background-color:#0D1823 !important;',
			'[style*="3B4D63"], [style*="3b4d63"]' => 'color:#C5D0DC !important;',
			'[style*="color:#1B3348"], [style*="color:#1b3348"]' => 'color:#EEF2F6 !important;',
			'[style*="color:#4A5B6E"], [style*="color:#4a5b6e"]' => 'color:#A9B8CA !important;',
			'[style*="F7F9FB"], [style*="f7f9fb"]' => 'background:#14263A !important;background-color:#14263A !important;',
			'[style*="D6DEE7"], [style*="d6dee7"]' => 'border-color:#2C4763 !important;',
			'hr' => 'border-color:#2C4763 !important;background-color:#2C4763 !important;',
			'.ittab-logo-light' => 'display:none !important;',
			'.ittab-logo-dark' => 'display:block !important;max-height:none !important;overflow:visible !important;',
		);
		$media = '';
		$outlook = '';
		foreach ($dark as $selector => $rule) {
			$media .= $selector.'{'.$rule.'}';
			// Outlook.com / Outlook apps mark their forced dark mode with data-ogsc/ogsb.
			if (strpos($selector, 'logo') === false) {
				foreach (explode(',', $selector) as $part) {
					$outlook .= '[data-ogsc] '.trim($part).',[data-ogsb] '.trim($part).'{'.$rule.'}';
				}
			}
		}
		// No doctype: Dolibarr's mailer wraps anything that does not start with <html> once more.
		return '<html lang="de"><head><meta charset="utf-8">'
			.'<meta name="viewport" content="width=device-width, initial-scale=1">'
			.'<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
			.'<style>:root{color-scheme:light dark;supported-color-schemes:light dark;}'
			.'body{margin:0;padding:0;-webkit-text-size-adjust:100%;}'
			.'a[x-apple-data-detectors]{color:inherit !important;text-decoration:none !important;font-size:inherit !important;font-family:inherit !important;font-weight:inherit !important;line-height:inherit !important;}'
			.'@media (prefers-color-scheme: dark){'.$media.'}'
			.$outlook
			.'</style></head>'
			.'<body class="ittab-page" style="margin:0;padding:0;background:#ffffff;">'
			.'<div class="wb-bg wb-tx" style="max-width:560px;margin:0 auto;padding:20px 20px 28px 20px;background:#ffffff;font-family:'.self::FONT.';font-size:16px;line-height:1.6;color:'.self::BODY.';">'
			.$content.'</div></body></html>';
	}

	/**
	 * Add classes to every opening tag that carries one of the brand colours
	 * inline, so the dark-mode stylesheet can address it by class.
	 *
	 * @param string $html HTML
	 * @return string
	 */
	public static function classFromColours($html)
	{
		$map = array(
			'wb-tx' => '/(^|;)\s*color\s*:\s*#3b4d63/i',
			'wb-ink' => '/(^|;)\s*color\s*:\s*#1b3348/i',
			'wb-mu' => '/(^|;)\s*color\s*:\s*#4a5b6e/i',
			'wb-box' => '/background(-color)?\s*:\s*#f7f9fb/i',
			'wb-bg' => '/background(-color)?\s*:\s*#ffffff/i',
			'wb-ln' => '/border[a-z-]*\s*:[^;]*#d6dee7/i',
		);
		return (string) preg_replace_callback('/<([a-z][a-z0-9]*)(\s[^>]*?)?(\/?)>/i', static function ($m) use ($map) {
			$attrs = isset($m[2]) ? $m[2] : '';
			if (!preg_match('/\sstyle\s*=\s*"([^"]*)"/i', $attrs, $st)) {
				return $m[0];
			}
			$classes = array();
			foreach ($map as $class => $pattern) {
				if (preg_match($pattern, $st[1])) {
					$classes[] = $class;
				}
			}
			if (empty($classes)) {
				return $m[0];
			}
			if (preg_match('/\sclass\s*=\s*"([^"]*)"/i', $attrs)) {
				$attrs = (string) preg_replace('/(\sclass\s*=\s*")([^"]*)"/i', '$1$2 '.implode(' ', $classes).'"', $attrs, 1);
			} else {
				$attrs .= ' class="'.implode(' ', $classes).'"';
			}
			return '<'.$m[1].$attrs.$m[3].'>';
		}, $html);
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
				if ($key === 'docsend' && $form === 'du') {
					$topicForm = '__ITTAB_UNTERLAGEN_BETREFF_DU__';
				}
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
	 * The orange button to the document in the customer area, full width.
	 *
	 * @param string $url Link
	 * @return string
	 */
	public static function portalButton($url)
	{
		return self::button($url, 'Im Kundenbereich ansehen');
	}

	/**
	 * An orange button: a block with background and rounded corners, the
	 * link in white inside (what Dolibarr's editor keeps on div, a and span).
	 *
	 * @param string $url   Address
	 * @param string $label Text (HTML)
	 * @return string
	 */
	public static function button($url, $label)
	{
		$radius = 'border-top-left-radius:4px;border-top-right-radius:4px;border-bottom-left-radius:4px;border-bottom-right-radius:4px;';
		return '<div style="margin:4px 0 24px 0;padding:14px 20px;background-color:'.self::BRAND.';'.$radius.'">'
			.'<a href="'.dol_escape_htmltag($url).'" style="color:#ffffff;text-decoration:none;">'
			.'<span style="font-family:'.self::FONT.';font-size:16px;color:#ffffff;"><strong>'.$label.'</strong></span></a></div>';
	}

	// ------------------------------------------------------------ rollout pieces
	// For texts that Dolibarr or another module puts together from parts
	// (ticket intro/signature, Mahnwesen letters): every piece closes its own
	// tags, inline styles only, so the parts can stand next to anything.

	/**
	 * Logo and the short orange line, for a text that starts a mail.
	 *
	 * @param string $logo Logo HTML or a variable (__ITTAB_LOGO__)
	 * @return string
	 */
	public static function headerBlock($logo)
	{
		// 1.9.0: mails start with the greeting; the logo is part of the signature.
		return '';
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
		return '<div style="max-width:560px;background:#ffffff;">'
			.self::footerLines($e($company->name), $e($company->managers), $e($address), $e($company->email), $e($company->url),
				$e(WerkbankBuilder::bareUrl((string) $company->url)), WerkbankMailData::phoneLink($phone))
			.'</div>';
	}

	/**
	 * A text paragraph in the template style.
	 *
	 * @param string $html Inner HTML
	 * @return string
	 */
	public static function paragraph($html)
	{
		return '<p style="'.self::P.'">'.self::text($html).'</p>';
	}

	/**
	 * The facts box with fixed rows (label => value HTML); a row whose label
	 * starts with "!" shows its value large.
	 *
	 * @param array<string,string> $rows Rows
	 * @return string
	 */
	public static function factsBox(array $rows)
	{
		$html = '';
		foreach ($rows as $label => $value) {
			$html .= WerkbankMailData::factRow(dol_escape_htmltag(ltrim($label, '!')), $value, strpos($label, '!') === 0);
		}
		return WerkbankMailData::box($html);
	}
}
