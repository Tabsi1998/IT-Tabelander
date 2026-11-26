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

require_once __DIR__.'/werkbankmailtemplates.class.php';
require_once __DIR__.'/werkbankmaildata.class.php';

/**
 * Texts of this design for places outside the module: the Mahnwesen
 * templates (same {PLACEHOLDERS} as the plugin) and the ticket introduction.
 * Built from the company data; the setup page shows them and writes them
 * into Dolibarr after confirmation, keeping the old values in a backup file.
 *
 * Mahnwesen prints its e-mail text into the letter (PDF) up to the marker
 * <!--MAHNWESEN_PDF_END-->: the letter keeps greeting, text, facts and
 * closing; rule, logo and contact come after the marker, e-mail only.
 * The ticket introduction is the start of a reply (greeting only); the
 * template "Ticket-Antwort" ends with the user's signature, so
 * TICKET_MESSAGE_MAIL_SIGNATURE stays empty.
 */
class WerkbankRollout
{
	const MARKER = '<!--MAHNWESEN_PDF_END-->';

	/** Template label in Dolibarr => key. */
	const MAHNWESEN = array('Mahnwesen - Zahlungserinnerung' => 'zahlungserinnerung', 'Mahnwesen - 1. Mahnung' => 'mahnung-1',
		'Mahnwesen - 2. Mahnung' => 'mahnung-2', 'Mahnwesen - 3. Mahnung' => 'mahnung-3');

	/**
	 * The Mahnwesen texts: key => array(subject, html).
	 *
	 * @param Societe $company Company
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function mahnwesen($company)
	{
		$T = 'WerkbankMailTemplates';
		$facts = array('Rechnung' => '{INVOICE_REF}', 'Rechnungsdatum' => '{INVOICE_DATE}', 'Fällig seit' => '{DUE_DATE}');
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
		// The name stands in the letter: after the marker the signature from the rule on (logo, contact).
		$signature = $T::signatureFor('', $company, '__ITTAB_LOGO__');
		$signature = substr($signature, 0, strpos($signature, '<p ')).substr($signature, strpos($signature, '<hr '));
		$out = array();
		foreach ($letters as $key => $letter) {
			list($subject, $rows, $paragraphs) = $letter;
			$body = $T::paragraph('Guten Tag,');
			foreach ($paragraphs as $index => $paragraph) {
				$body .= $T::paragraph($paragraph);
				if ($index === 0) {
					$body .= $T::factsBox($rows);
				}
			}
			$body .= '<p style="'.$T::P.'margin:24px 0 0 0;">'.$T::text('Mit freundlichen Grüßen<br>'.dol_escape_htmltag((string) $company->managers)).'</p>';
			$out[$key] = array($subject, '<div style="max-width:560px;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:1.6;color:#3B4D63;">'.$body.'</div>'
				.self::MARKER.'<div style="max-width:560px;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:1.6;margin:16px 0 0 0;">'.$signature.'</div>');
		}
		return $out;
	}

	/**
	 * The ticket introduction (TICKET_MESSAGE_MAIL_INTRO): the greeting.
	 *
	 * @return string
	 */
	public static function ticketIntro()
	{
		return '<div style="max-width:560px;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:1.6;color:#3B4D63;">'
			.WerkbankMailTemplates::paragraph('Guten Tag,').'</div>';
	}

	/**
	 * What would change: target => array(label, current, new, subject current, subject new).
	 *
	 * @param DoliDB  $db      Database
	 * @param Societe $company Company
	 * @return array<string,array<string,string>>
	 */
	public static function plan($db, $company)
	{
		global $conf;
		$plan = array();
		$texts = self::mahnwesen($company);
		foreach (self::MAHNWESEN as $label => $key) {
			$resql = $db->query("SELECT rowid, topic, content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE label = '".$db->escape($label)."'"
				." AND entity IN (0, ".((int) $conf->entity).") ORDER BY entity DESC");
			$row = $resql ? $db->fetch_object($resql) : null;
			$plan['tpl:'.$key] = array('label' => $label, 'found' => $row ? 1 : 0, 'id' => $row ? (int) $row->rowid : 0,
				'current' => $row ? (string) $row->content : '', 'new' => $texts[$key][1],
				'subject' => $row ? (string) $row->topic : '', 'newsubject' => $texts[$key][0]);
		}
		$plan['const:TICKET_MESSAGE_MAIL_INTRO'] = array('label' => 'TICKET_MESSAGE_MAIL_INTRO', 'found' => 1, 'id' => 0,
			'current' => getDolGlobalString('TICKET_MESSAGE_MAIL_INTRO'), 'new' => self::ticketIntro(), 'subject' => '', 'newsubject' => '');
		$plan['const:TICKET_MESSAGE_MAIL_SIGNATURE'] = array('label' => 'TICKET_MESSAGE_MAIL_SIGNATURE', 'found' => 1, 'id' => 0,
			'current' => getDolGlobalString('TICKET_MESSAGE_MAIL_SIGNATURE'), 'new' => '', 'subject' => '', 'newsubject' => '');
		return $plan;
	}

	/**
	 * Write the chosen texts into Dolibarr; the old values first into a
	 * backup file in the module's documents.
	 *
	 * @param DoliDB   $db      Database
	 * @param Societe  $company Company
	 * @param string[] $targets Keys of plan()
	 * @param User     $user    User
	 * @return array{written:int,backup:string}|int -1 on error
	 */
	public static function apply($db, $company, array $targets, $user)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$plan = self::plan($db, $company);
		$backup = array();
		foreach ($targets as $target) {
			if (isset($plan[$target]) && $plan[$target]['found']) {
				$backup[$target] = array('label' => $plan[$target]['label'], 'subject' => $plan[$target]['subject'], 'content' => $plan[$target]['current']);
			}
		}
		if (!$backup) {
			return array('written' => 0, 'backup' => '');
		}
		$dir = $conf->ittabelanderpdf->dir_output.'/rollout';
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			return -1;
		}
		$file = $dir.'/sicherung-'.dol_print_date(dol_now(), '%Y%m%d-%H%M%S').'.json';
		if (file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
			return -1;
		}
		$written = 0;
		foreach (array_keys($backup) as $target) {
			$entry = $plan[$target];
			if (strpos($target, 'tpl:') === 0) {
				$ok = $db->query("UPDATE ".MAIN_DB_PREFIX."c_email_templates SET topic = '".$db->escape($entry['newsubject'])."', content = '".$db->escape($entry['new'])."'"
					." WHERE rowid = ".((int) $entry['id']));
			} else {
				$ok = dolibarr_set_const($db, substr($target, 6), $entry['new'], 'chaine', 0, '', $conf->entity) > 0;
			}
			$written += $ok ? 1 : 0;
		}
		return array('written' => $written, 'backup' => basename($file));
	}
}
