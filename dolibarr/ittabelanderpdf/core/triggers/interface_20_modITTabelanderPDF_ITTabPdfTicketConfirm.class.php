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
 * Replaces Dolibarr's built-in mails for a new ticket.
 *
 * Dolibarr 23 composes them from language strings only: the customer gets
 * "please do not reply" and every extra field as a line, the owner an
 * English list in the API user's language. This trigger runs before
 * Dolibarr's own ticket trigger (priority 20 < 50) and sends both in the
 * house style:
 *  - the customer's confirmation (number, subject, status link; not the
 *    ticket text, which may carry internal notes from the website), then it
 *    clears notify_tiers_at_create so Dolibarr skips its own;
 *  - the owner's notice to TICKET_NOTIFICATION_EMAIL_TO in German with the
 *    full text and a button into Dolibarr, then it sets
 *    context['disableticketemail'] so Dolibarr skips its admin mail - only
 *    when nothing else depends on that flag (customer handled, no assignee).
 * If a send fails, the flags stay and Dolibarr's own mail goes out.
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
		$this->description = 'Ticket confirmation and owner notice in the IT-Tabelander style instead of Dolibarr\'s built-in texts.';
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
		if (!empty($object->context['disableticketemail']) || getDolGlobalString('TICKET_DISABLE_ALL_MAILS')) {
			return 0;
		}
		dol_include_once('/ittabelanderpdf/class/werkbankmailtemplates.class.php');
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$done = 0;

		// The customer.
		if (!empty($object->notify_tiers_at_create) && !getDolGlobalString('TICKET_DISABLE_CUSTOMER_MAILS')) {
			try {
				$to = $this->recipient($object);
				if ($to !== '' && $this->sendCustomer($object, $to)) {
					// Dolibarr's ticket trigger (priority 50) must not send its text as well.
					$object->notify_tiers_at_create = 0;
					$done = 1;
				}
			} catch (Throwable $e) {
				dol_syslog('ittabelanderpdf: ticket confirmation failed, Dolibarr sends its own: '.$e->getMessage(), LOG_ERR);
			}
		}

		// The owner. Dolibarr has no switch for its admin mail alone, only
		// disableticketemail, which also stops the customer and assignee mails.
		$adminTo = getDolGlobalString('TICKET_NOTIFICATION_EMAIL_TO');
		$assignee = !empty($object->fk_user_assign) && (int) $object->fk_user_assign !== (int) $user->id;
		if ($adminTo !== '' && empty($object->notify_tiers_at_create) && !$assignee) {
			try {
				if ($this->sendOwner($object, $adminTo)) {
					if (!is_array($object->context)) {
						$object->context = array();
					}
					$object->context['disableticketemail'] = 1;
					$done = 1;
				}
			} catch (Throwable $e) {
				dol_syslog('ittabelanderpdf: ticket notice failed, Dolibarr sends its own: '.$e->getMessage(), LOG_ERR);
			}
		}
		return $done;
	}

	/**
	 * The recipient Dolibarr's ticket trigger would use: the chosen contact,
	 * else the third party, else the e-mail the ticket came from.
	 *
	 * @param Ticket $object Ticket
	 * @return string        E-mail address or ''
	 */
	private function recipient($object)
	{
		$contactid = empty($object->context['contact_id']) ? 0 : (int) $object->context['contact_id'];
		if ($contactid > 0) {
			require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
			$contact = new Contact($this->db);
			if ($contact->fetch($contactid) > 0 && !empty($contact->email) && !empty($contact->statut)) {
				return (string) $contact->email;
			}
		}
		$thirdparty = $this->thirdparty($object);
		if ($thirdparty && !empty($thirdparty->email)) {
			return (string) $thirdparty->email;
		}
		return (string) ($object->origin_email ?? '');
	}

	/**
	 * @param Ticket $object Ticket
	 * @return Societe|null
	 */
	private function thirdparty($object)
	{
		static $cache = array();
		$id = (int) ($object->fk_soc ?? 0);
		if ($id <= 0) {
			return null;
		}
		if (!array_key_exists($id, $cache)) {
			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			$thirdparty = new Societe($this->db);
			$cache[$id] = $thirdparty->fetch($id) > 0 ? $thirdparty : null;
		}
		return $cache[$id];
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
		$thirdparty = $this->thirdparty($object);
		if ($thirdparty) {
			$thirdparty->fetch_optionals();
			$du = !empty($thirdparty->array_options['options_ittab_du']);
			list($hallo, $gutentag) = WerkbankMailData::greetings($thirdparty);
			return array($du ? 'du' : 'sie', $du ? $hallo : $gutentag);
		}
		return array('sie', 'Guten Tag,');
	}

	/**
	 * Text as HTML: kept when it is HTML, else escaped with its line breaks.
	 *
	 * @param string $text Text
	 * @return string
	 */
	private static function textHtml($text)
	{
		$text = (string) $text;
		return dol_textishtml($text) ? dol_htmlwithnojs($text) : dol_nl2br(dol_escape_htmltag($text, 0, 1));
	}

	/**
	 * @return string Sender "Company <address>"
	 */
	private static function from()
	{
		global $mysoc;
		$name = (string) ($mysoc->name ?? '');
		$address = getDolGlobalString('TICKET_NOTIFICATION_EMAIL_FROM', getDolGlobalString('MAIN_MAIL_EMAIL_FROM', (string) ($mysoc->email ?? '')));
		return ($name !== '' ? dol_string_nospecial($name, ' ', array(',')).' ' : '').'<'.$address.'>';
	}

	/**
	 * The frame around a body (light and dark); the body ends with the
	 * signature where there is one.
	 *
	 * @param string $body Body HTML
	 * @return string      Full document
	 */
	private static function document($body)
	{
		return WerkbankMailTemplates::wrapDocument($body);
	}

	/**
	 * @param string $label Small caps line
	 * @param string $title Bold line (HTML)
	 * @return string       Facts box
	 */
	private static function requestBox($label, $title)
	{
		$small = 'font-family:'.WerkbankMailTemplates::FONT.';font-size:12px;line-height:20px;letter-spacing:1px;text-transform:uppercase;color:'.WerkbankMailTemplates::MUTED.';';
		$bold = 'margin:4px 0 0 0;font-family:'.WerkbankMailTemplates::FONT.';font-size:16px;line-height:1.5;font-weight:bold;color:'.WerkbankMailTemplates::INK.';';
		return WerkbankMailData::box('<div style="'.$small.'">'.$label.'</div>'.($title !== '' ? '<div style="'.$bold.'">'.$title.'</div>' : ''));
	}

	/**
	 * @param string $subject Subject
	 * @param string $to      Recipient
	 * @param string $html    Full document
	 * @param int    $id      Ticket id (track id tic<id>)
	 * @return bool           True when the mail went out
	 */
	private static function mail($subject, $to, $html, $id)
	{
		$mail = new CMailFile($subject, $to, self::from(), $html, array(), array(), array(), '', '', 0, 1, '', '', 'tic'.$id, '', 'ticket');
		if (!empty($mail->error) || !empty($mail->errors)) {
			dol_syslog('ittabelanderpdf: ticket mail not built: '.$mail->error, LOG_ERR);
			return false;
		}
		if (!$mail->sendfile()) {
			dol_syslog('ittabelanderpdf: ticket mail not sent: '.$mail->error, LOG_ERR);
			return false;
		}
		return true;
	}

	/**
	 * The customer's confirmation.
	 *
	 * @param Ticket $object Ticket
	 * @param string $to     Recipient
	 * @return bool          True when the mail went out
	 */
	private function sendCustomer($object, $to)
	{
		global $mysoc;
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
		$body .= self::requestBox(($du ? 'Deine Anfrage' : 'Ihre Anfrage').' '.$e($object->ref), $e($subjectText));
		// Status, messages and replies live in the website's customer area
		// (/kundenbereich/anfragen/<id>); questions go by e-mail or there, not by phone.
		if (getDolGlobalInt('ITTABPDF_PORTAL_ENABLED')) {
			$body .= WerkbankMailTemplates::paragraph($du
				? 'Den Stand und alle Nachrichten siehst du im Kundenbereich. Dort kannst du mir auch schreiben und Fotos schicken.'
				: 'Den Stand und alle Nachrichten sehen Sie im Kundenbereich. Dort können Sie mir auch schreiben und Fotos schicken.');
			$body .= WerkbankMailTemplates::portalButton(WerkbankMailData::portalUrl($object));
			$body .= WerkbankMailTemplates::paragraph($du
				? 'Oder antworte einfach auf diese Mail.'
				: 'Oder antworten Sie einfach auf diese E-Mail.');
		} else {
			$body .= WerkbankMailTemplates::paragraph($du
				? 'Ergänzungen und Fragen schickst du einfach als Antwort auf diese Mail.'
				: 'Ergänzungen und Fragen schicken Sie einfach als Antwort auf diese E-Mail.');
		}
		$body .= WerkbankMailTemplates::signatureFor($du ? 'Liebe Grüße' : 'Mit freundlichen Grüßen', $mysoc, WerkbankMailData::logoHtml(true));

		$subject = ($du ? 'Deine' : 'Ihre').' Anfrage '.$object->ref.($subjectText !== '' ? ': '.$subjectText : '');
		return self::mail($subject, $to, self::document($body), (int) $object->id);
	}

	/**
	 * The owner's notice: everything the ticket says, in German.
	 *
	 * @param Ticket $object Ticket
	 * @param string $to     TICKET_NOTIFICATION_EMAIL_TO
	 * @return bool          True when the mail went out
	 */
	private function sendOwner($object, $to)
	{
		global $conf;
		$e = function ($text) {
			return dol_escape_htmltag((string) $text);
		};
		$de = new Translate('', $conf);
		$de->setDefaultLang('de_DE');
		$de->loadLangs(array('main', 'ticket'));
		$label = function ($prefix, $table, $code) use ($de) {
			$code = (string) $code;
			if ($code === '') {
				return '';
			}
			$text = (string) $de->getLabelFromKey($this->db, $prefix.$code, $table, 'code', 'label', $code);
			return $text === $prefix.$code ? $code : $text;
		};
		$subjectText = trim((string) $object->subject);

		$rows = array(
			'Ticket' => $e($object->ref),
			'Art' => $e($label('TicketTypeShort', 'c_ticket_type', $object->type_code)),
			'Gruppe' => $e($label('TicketCategoryShort', 'c_ticket_category', $object->category_code)),
			'Dringlichkeit' => $e($label('TicketSeverityShort', 'c_ticket_severity', $object->severity_code)),
		);
		$thirdparty = $this->thirdparty($object);
		if ($thirdparty) {
			$rows['Kunde'] = $e($thirdparty->name).(!empty($thirdparty->code_client) ? ' ('.$e($thirdparty->code_client).')' : '');
		}
		$email = (string) ($object->origin_email ?: ($thirdparty ? $thirdparty->email : ''));
		if ($email !== '') {
			$rows['E-Mail'] = '<a href="mailto:'.$e($email).'" style="color:'.WerkbankMailTemplates::INK.';">'.$e($email).'</a>';
		}
		$facts = '';
		foreach (array_filter($rows, 'strlen') as $name => $value) {
			// Long values (a company name with its number) go below their label,
			// floated they would push the label under them on a phone.
			$facts .= mb_strlen(trim(strip_tags($value))) > 24
				? '<div style="padding:2px 0;"><div style="'.WerkbankMailData::LABEL.'">'.$e($name).'</div>'
					.'<div style="'.str_replace('float:right;', '', WerkbankMailData::VALUE).'line-height:1.4;">'.$value.'</div></div>'
				: WerkbankMailData::factRow($e($name), $value);
		}

		$body = WerkbankMailTemplates::paragraph('Neue Anfrage: <strong style="color:'.WerkbankMailTemplates::INK.';">'.$e($subjectText !== '' ? $subjectText : $object->ref).'</strong>');
		$body .= WerkbankMailData::box($facts);
		$body .= '<div style="margin:0 0 24px 0;font-family:'.WerkbankMailTemplates::FONT.';font-size:15px;line-height:1.6;color:'.WerkbankMailTemplates::BODY.';">'.self::textHtml($object->message).'</div>';
		$url = dol_buildpath('/ticket/card.php', 2).'?track_id='.urlencode((string) $object->track_id);
		$body .= '<div style="margin:4px 0 8px 0;"><a href="'.$e($url).'" style="display:block;padding:14px 20px;border-radius:4px;background:'.WerkbankMailTemplates::BRAND.';color:#fff;font-family:'.WerkbankMailTemplates::FONT.';font-size:16px;font-weight:bold;line-height:1.3;text-align:center;text-decoration:none;">Ticket in Dolibarr öffnen</a></div>';

		$subject = 'Neue Anfrage '.$object->ref.($subjectText !== '' ? ': '.$subjectText : '');
		return self::mail($subject, $to, self::document($body), (int) $object->id);
	}
}
