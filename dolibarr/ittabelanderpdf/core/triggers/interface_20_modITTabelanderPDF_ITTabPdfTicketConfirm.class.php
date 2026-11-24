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

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Replaces Dolibarr's built-in confirmation for a new ticket.
 *
 * Dolibarr 23 composes that mail from language strings only (no template,
 * no signature, "please do not reply", every ticket extra field as a line).
 * This trigger runs before Dolibarr's own ticket trigger (priority 20 < 50),
 * sends the confirmation in the house style and then clears
 * notify_tiers_at_create on the object, so Dolibarr does not send its own.
 * If anything fails here, it leaves the flag alone and Dolibarr's mail goes
 * out as before - a customer never gets no confirmation at all.
 *
 * Switch: ITTABPDF_TICKET_CONFIRM (default on).
 */
class InterfaceITTabPdfTicketConfirm extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'other';
		$this->description = 'Ticket confirmation in the IT-Tabelander style instead of Dolibarr\'s built-in text.';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'email';
	}

	/**
	 * @param string       $action Trigger code
	 * @param CommonObject $object Object
	 * @param User         $user   User
	 * @param Translate    $langs  Language
	 * @param Conf         $conf   Configuration
	 * @return int                 0 nothing done, 1 sent
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action !== 'TICKET_CREATE' || !isModEnabled('ittabelanderpdf') || !getDolGlobalInt('ITTABPDF_TICKET_CONFIRM', 1)) {
			return 0;
		}
		if (!empty($object->context['disableticketemail']) || empty($object->notify_tiers_at_create)
			|| getDolGlobalString('TICKET_DISABLE_ALL_MAILS') || getDolGlobalString('TICKET_DISABLE_CUSTOMER_MAILS')) {
			return 0;
		}
		try {
			$to = $this->recipient($object);
			if ($to === '') {
				return 0;
			}
			if (!$this->send($object, $to)) {
				return 0;
			}
		} catch (Throwable $e) {
			dol_syslog('ittabelanderpdf: ticket confirmation failed, Dolibarr sends its own: '.$e->getMessage(), LOG_ERR);
			return 0;
		}
		// Sent: Dolibarr's own ticket trigger (priority 50) must not send its text as well.
		$object->notify_tiers_at_create = 0;
		return 1;
	}

	/**
	 * The same recipient Dolibarr's ticket trigger would use: the chosen
	 * contact, else the third party, else the e-mail the ticket came from.
	 *
	 * @param Ticket $object Ticket
	 * @return string        E-mail address or ''
	 */
	private function recipient($object)
	{
		$contactid = GETPOSTINT('contactid');
		if ($contactid > 0) {
			require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
			$contact = new Contact($this->db);
			if ($contact->fetch($contactid) > 0 && !empty($contact->email) && !empty($contact->statut)) {
				return (string) $contact->email;
			}
		}
		if (!empty($object->fk_soc)) {
			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			$thirdparty = new Societe($this->db);
			if ($thirdparty->fetch((int) $object->fk_soc) > 0 && !empty($thirdparty->email)) {
				return (string) $thirdparty->email;
			}
		}
		return (string) ($object->origin_email ?? '');
	}

	/**
	 * Du or Sie and the greeting: from the third party (extra field
	 * ittab_du, contact or private person's name), else neutral Sie.
	 *
	 * @param Ticket $object Ticket
	 * @return string[]      array(form, greeting)
	 */
	private function greeting($object)
	{
		if (!empty($object->fk_soc)) {
			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			$thirdparty = new Societe($this->db);
			if ($thirdparty->fetch((int) $object->fk_soc) > 0) {
				$thirdparty->fetch_optionals();
				$du = !empty($thirdparty->array_options['options_ittab_du']);
				list($hallo, $gutentag) = WerkbankMailData::greetings($thirdparty);
				return array($du ? 'du' : 'sie', $du ? $hallo : $gutentag);
			}
		}
		return array('sie', 'Guten Tag,');
	}

	/**
	 * @param Ticket $object Ticket
	 * @param string $to     Recipient
	 * @return bool          True when the mail went out
	 */
	private function send($object, $to)
	{
		global $mysoc;

		dol_include_once('/ittabelanderpdf/class/werkbankmailtemplates.class.php');
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

		$e = function ($text) {
			return dol_escape_htmltag((string) $text);
		};
		list($form, $greeting) = $this->greeting($object);
		$du = $form === 'du';
		$subjectText = trim((string) $object->subject);

		$body = WerkbankMailTemplates::paragraph($e($greeting));
		$body .= WerkbankMailTemplates::paragraph($du
			? 'danke für deine Anfrage. Sie ist bei mir angekommen, ich sehe sie mir an und melde mich bei dir.'
			: 'danke für Ihre Anfrage. Sie ist bei mir angekommen, ich sehe sie mir an und melde mich bei Ihnen.');

		$message = (string) $object->message;
		$message = dol_textishtml($message) ? dol_htmlwithnojs($message) : dol_nl2br($e($message));
		$label = 'font-family:'.WerkbankMailTemplates::FONT.';font-size:12px;line-height:20px;letter-spacing:1px;text-transform:uppercase;color:'.WerkbankMailTemplates::MUTED.';';
		$body .= WerkbankMailData::box(
			'<div style="'.$label.'">'.($du ? 'Deine Anfrage' : 'Ihre Anfrage').' '.$e($object->ref).'</div>'
			.($subjectText !== '' ? '<div style="margin:4px 0 0 0;font-family:'.WerkbankMailTemplates::FONT.';font-size:16px;line-height:1.5;font-weight:bold;color:'.WerkbankMailTemplates::INK.';">'.$e($subjectText).'</div>' : '')
			.(trim(strip_tags($message)) !== '' ? '<div style="margin:8px 0 0 0;font-family:'.WerkbankMailTemplates::FONT.';font-size:15px;line-height:1.6;color:'.WerkbankMailTemplates::BODY.';">'.$message.'</div>' : '')
		);

		if (getDolGlobalInt('TICKET_ENABLE_PUBLIC_INTERFACE') && !empty($object->track_id)) {
			$url = getDolGlobalString('TICKET_URL_PUBLIC_INTERFACE', dol_buildpath('/public/ticket/', 2));
			$url = rtrim($url, '/').'/view.php?track_id='.urlencode((string) $object->track_id);
			$body .= WerkbankMailTemplates::paragraph(($du ? 'Den Stand siehst du jederzeit hier:' : 'Den Stand sehen Sie jederzeit hier:')
				.'<br><a href="'.$e($url).'" style="color:'.WerkbankMailTemplates::INK.';">'.$e($url).'</a>');
		}
		$phone = WerkbankMailData::phoneLink(!empty($mysoc->phone_mobile) ? $mysoc->phone_mobile : $mysoc->phone);
		$body .= WerkbankMailTemplates::paragraph($du
			? 'Ergänzungen schickst du einfach als Antwort auf diese Mail, bei Fragen erreichst du mich unter '.$phone.'.'
			: 'Ergänzungen schicken Sie einfach als Antwort auf diese E-Mail, bei Fragen erreichen Sie mich unter '.$phone.'.');
		$body .= WerkbankMailTemplates::closing($du ? 'Liebe Grüße' : 'Mit freundlichen Grüßen', $e($mysoc->managers), $e($mysoc->name));

		$address = trim(str_replace("\n", ', ', trim((string) $mysoc->address)).', '.trim($mysoc->zip.' '.$mysoc->town), ', ');
		$html = WerkbankMailTemplates::sheet(WerkbankMailData::logoHtml(true), $body, WerkbankMailTemplates::footerLines(
			$e($mysoc->name), $e($mysoc->managers), $e($address), $e($mysoc->email), $e($mysoc->url),
			$e(WerkbankBuilder::bareUrl((string) $mysoc->url)), $phone
		));

		$name = (string) ($mysoc->name ?? '');
		$fromAddress = getDolGlobalString('TICKET_NOTIFICATION_EMAIL_FROM', getDolGlobalString('MAIN_MAIL_EMAIL_FROM', (string) ($mysoc->email ?? '')));
		$from = ($name !== '' ? dol_string_nospecial($name, ' ', array(',')).' ' : '').'<'.$fromAddress.'>';
		$subject = ($du ? 'Deine' : 'Ihre').' Anfrage '.$object->ref.($subjectText !== '' ? ': '.$subjectText : '').' – '.$name;

		$mail = new CMailFile($subject, $to, $from, WerkbankMailTemplates::wrapDocument($html), array(), array(), array(), '', '', 0, 1, '', '', 'tic'.$object->id, '', 'ticket');
		if (!empty($mail->error) || !empty($mail->errors)) {
			dol_syslog('ittabelanderpdf: ticket confirmation not built: '.$mail->error, LOG_ERR);
			return false;
		}
		if (!$mail->sendfile()) {
			dol_syslog('ittabelanderpdf: ticket confirmation not sent: '.$mail->error, LOG_ERR);
			return false;
		}
		return true;
	}
}
