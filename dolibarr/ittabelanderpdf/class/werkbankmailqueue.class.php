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

require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once __DIR__.'/werkbankmailtemplates.class.php';
require_once __DIR__.'/werkbankmaildata.class.php';

/**
 * Delayed, cancellable automatic e-mails.
 *
 * A trigger only plans (plan()); the cron job (runCron(), every 5 minutes)
 * sends what is due. One row per document and event: a sent row is never
 * planned again, so validating a document twice sends once.
 */
class WerkbankMailQueue
{
	/** Events and their switch, element, template choice and agenda trigger. */
	const EVENTS = array(
		'propal_validated' => array('const' => 'ITTABPDF_AUTOSEND_PROPAL', 'element' => 'propal', 'pdf' => 1, 'trigger' => 'PROPAL_SENTBYMAIL'),
		'invoice_validated' => array('const' => 'ITTABPDF_AUTOSEND_INVOICE', 'element' => 'facture', 'pdf' => 1, 'trigger' => 'BILL_SENTBYMAIL'),
		'invoice_paid' => array('const' => 'ITTABPDF_AUTOSEND_PAID', 'element' => 'facture', 'pdf' => 0, 'trigger' => 'BILL_SENTBYMAIL'),
	);

	/** @var DoliDB */
	public $db;

	/** @var string Cron output */
	public $output = '';

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Plan the e-mail of an event, due after the configured delay. Nothing
	 * happens when the event is switched off, already planned or already sent.
	 *
	 * @param CommonObject $object Document
	 * @param string       $event  Key of EVENTS
	 * @return int                 1 planned, 0 nothing to do, -1 error
	 */
	public function plan($object, $event)
	{
		global $conf;
		if (!isset(self::EVENTS[$event]) || !getDolGlobalInt(self::EVENTS[$event]['const'])) {
			return 0;
		}
		$due = dol_now() + 60 * max(0, getDolGlobalInt('ITTABPDF_AUTOSEND_DELAY', 5));
		$existing = $this->find($object->element, (int) $object->id, $event);
		if ($existing) {
			if (in_array($existing->status, array('planned', 'sending', 'sent'), true)) {
				return 0;
			}
			// Cancelled or failed before: a new validation plans it again.
			$sql = "UPDATE ".MAIN_DB_PREFIX."ittabpdf_mailqueue SET status = 'planned', date_due = '".$this->db->idate($due)."', error = NULL, date_done = NULL"
				." WHERE rowid = ".((int) $existing->rowid);
			return $this->db->query($sql) ? 1 : -1;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."ittabpdf_mailqueue (entity, element, fk_object, event, date_creation, date_due, status)"
			." VALUES (".((int) $conf->entity).", '".$this->db->escape($object->element)."', ".((int) $object->id).", '".$this->db->escape($event)."',"
			." '".$this->db->idate(dol_now())."', '".$this->db->idate($due)."', 'planned')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * @param string $element Element
	 * @param int    $id      Object id
	 * @param string $event   Event
	 * @return object|null    Row
	 */
	public function find($element, $id, $event)
	{
		global $conf;
		$sql = "SELECT rowid, status, date_due, error FROM ".MAIN_DB_PREFIX."ittabpdf_mailqueue WHERE entity = ".((int) $conf->entity)
			." AND element = '".$this->db->escape($element)."' AND fk_object = ".((int) $id)." AND event = '".$this->db->escape($event)."'";
		$resql = $this->db->query($sql);
		return $resql ? ($this->db->fetch_object($resql) ?: null) : null;
	}

	/**
	 * Open entries (planned or failed) of one document, for the banner on its card.
	 *
	 * @param string $element Element
	 * @param int    $id      Object id
	 * @return object[]
	 */
	public function openFor($element, $id)
	{
		global $conf;
		$rows = array();
		$sql = "SELECT rowid, event, status, date_due, error FROM ".MAIN_DB_PREFIX."ittabpdf_mailqueue WHERE entity = ".((int) $conf->entity)
			." AND element = '".$this->db->escape($element)."' AND fk_object = ".((int) $id)." AND status IN ('planned', 'error') ORDER BY date_due";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$obj->date_due = $this->db->jdate($obj->date_due);
			$rows[] = $obj;
		}
		return $rows;
	}

	/**
	 * Cancel a planned e-mail.
	 *
	 * @param int  $rowid Queue row
	 * @param User $user  Who cancels
	 * @return int        1 cancelled, 0 not planned (any more)
	 */
	public function cancel($rowid, $user)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."ittabpdf_mailqueue SET status = 'cancelled', date_done = '".$this->db->idate(dol_now())."', fk_user_action = ".((int) $user->id)
			." WHERE rowid = ".((int) $rowid)." AND status = 'planned'";
		$resql = $this->db->query($sql);
		return ($resql && $this->db->affected_rows($resql) > 0) ? 1 : 0;
	}

	/**
	 * Plan a failed or cancelled e-mail again, due now.
	 *
	 * @param int  $rowid Queue row
	 * @param User $user  Who asks
	 * @return int        1 planned, 0 not possible
	 */
	public function retry($rowid, $user)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."ittabpdf_mailqueue SET status = 'planned', error = NULL, date_done = NULL, date_due = '".$this->db->idate(dol_now())."', fk_user_action = ".((int) $user->id)
			." WHERE rowid = ".((int) $rowid)." AND status IN ('error', 'cancelled', 'sent')";
		$resql = $this->db->query($sql);
		return ($resql && $this->db->affected_rows($resql) > 0) ? 1 : 0;
	}

	/**
	 * Cron entry: send every planned e-mail that is due.
	 *
	 * @return int 0 when the run went through (failures stay visible per row), -1 on a database error
	 */
	public function runCron()
	{
		global $conf, $user;
		$sql = "SELECT rowid, element, fk_object, event FROM ".MAIN_DB_PREFIX."ittabpdf_mailqueue WHERE entity = ".((int) $conf->entity)
			." AND status = 'planned' AND date_due <= '".$this->db->idate(dol_now())."' ORDER BY date_due LIMIT 50";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$counts = array('sent' => 0, 'cancelled' => 0, 'error' => 0);
		foreach ($rows as $row) {
			// Claim the row first: an overlapping run must not send it twice.
			$claim = $this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_mailqueue SET status = 'sending', attempts = attempts + 1 WHERE rowid = ".((int) $row->rowid)." AND status = 'planned'");
			if (!$claim || $this->db->affected_rows($claim) < 1) {
				continue;
			}
			list($status, $recipient, $template, $message) = $this->process($row, $user);
			$counts[$status]++;
			$sql = "UPDATE ".MAIN_DB_PREFIX."ittabpdf_mailqueue SET status = '".$this->db->escape($status)."', date_done = '".$this->db->idate(dol_now())."',"
				." recipient = ".($recipient !== '' ? "'".$this->db->escape(dol_trunc($recipient, 250, 'right', 'UTF-8', 1))."'" : "NULL").","
				." fk_template = ".($template > 0 ? (int) $template : "NULL").","
				." error = ".($message !== '' ? "'".$this->db->escape($message)."'" : "NULL")
				." WHERE rowid = ".((int) $row->rowid);
			$this->db->query($sql);
		}
		$this->output = 'sent '.$counts['sent'].', cancelled '.$counts['cancelled'].', failed '.$counts['error'];
		return 0;
	}

	/**
	 * Send one due row.
	 *
	 * @param object $row  Queue row
	 * @param User   $user Cron user
	 * @return array{0:string,1:string,2:int,3:string} status, recipient, template id, message
	 */
	private function process($row, $user)
	{
		global $langs;
		$event = self::EVENTS[$row->event] ?? null;
		if (!$event) {
			return array('error', '', 0, 'unknown event '.$row->event);
		}
		$object = $this->load($row->element, (int) $row->fk_object);
		if (!$object) {
			return array('cancelled', '', 0, 'Beleg gelöscht');
		}
		// Only what is still true is sent: a document back in draft is not.
		if ($row->event === 'propal_validated' && (int) $object->status !== Propal::STATUS_VALIDATED) {
			return array('cancelled', '', 0, 'Angebot ist nicht mehr offen (Status '.$object->status.')');
		}
		if ($row->event === 'invoice_validated' && (int) $object->status === Facture::STATUS_DRAFT) {
			return array('cancelled', '', 0, 'Rechnung ist wieder ein Entwurf');
		}
		if ($row->event === 'invoice_paid' && empty($object->paye)) {
			return array('cancelled', '', 0, 'Rechnung ist nicht mehr bezahlt');
		}
		$result = $this->send($object, $this->templateKey($row->event, $object), $user, (bool) $event['pdf'], $event['trigger']);
		return array($result['ok'] ? 'sent' : 'error', $result['to'], $result['template'], $result['error']);
	}

	/**
	 * Template key for an event and document (invoice, deposit, credit note, paid, proposal).
	 *
	 * @param string       $event  Event
	 * @param CommonObject $object Document
	 * @return string
	 */
	public function templateKey($event, $object)
	{
		if ($event === 'propal_validated') {
			return 'propal';
		}
		if ($event === 'invoice_paid') {
			return 'paid';
		}
		if ((int) $object->type === Facture::TYPE_CREDIT_NOTE) {
			return 'creditnote';
		}
		if ((int) $object->type === Facture::TYPE_DEPOSIT) {
			return 'deposit';
		}
		return 'invoice';
	}

	/**
	 * @param string $element Element
	 * @param int    $id      Id
	 * @return CommonObject|null
	 */
	private function load($element, $id)
	{
		$classes = array(
			'propal' => array('Propal', '/comm/propal/class/propal.class.php'),
			'facture' => array('Facture', '/compta/facture/class/facture.class.php'),
		);
		if (!isset($classes[$element])) {
			return null;
		}
		require_once DOL_DOCUMENT_ROOT.$classes[$element][1];
		$object = new $classes[$element][0]($this->db);
		if ($object->fetch($id) <= 0) {
			return null;
		}
		$object->fetch_thirdparty();
		return $object;
	}

	/**
	 * Send a document with a template the way Dolibarr's own "Send by e-mail"
	 * does: Werkbank PDF attached, the setup's BCC, and the _SENTBYMAIL
	 * trigger so the agenda shows the e-mail like a manual one.
	 *
	 * @param CommonObject $object  Document
	 * @param string       $key     Template key (WerkbankMailTemplates)
	 * @param User         $user    Sender
	 * @param bool         $withPdf Attach the PDF
	 * @param string       $trigger Trigger name, '' for none
	 * @param string       $form    'sie' or 'du' to override the third party's field
	 * @return array{ok:bool,to:string,template:int,error:string}
	 */
	public function send($object, $key, $user, $withPdf, $trigger, $form = '')
	{
		global $conf, $langs, $mysoc;
		$langs->loadLangs(array('main', 'mails', 'ittabelanderpdf@ittabelanderpdf'));
		$out = array('ok' => false, 'to' => '', 'template' => 0, 'error' => '');

		$thirdparty = ($object instanceof Societe) ? $object : $object->thirdparty;
		// Recipient: the document's contact, else the third party.
		$contact = WerkbankMailData::contact($object);
		$sendtoid = array();
		if ($contact && isValidEmail($contact->email)) {
			$to = $contact->getFullName($langs).' <'.$contact->email.'>';
			$sendtoid[] = $contact->id;
		} elseif (isValidEmail($thirdparty->email)) {
			$to = $thirdparty->name.' <'.$thirdparty->email.'>';
		} else {
			$out['error'] = 'Keine E-Mail-Adresse beim Kontakt oder Kunden';
			return $out;
		}
		$out['to'] = $to;

		// Du or Sie from the third party's extra field.
		if ($form === '') {
			if (empty($thirdparty->array_options)) {
				$thirdparty->fetch_optionals();
			}
			$form = !empty($thirdparty->array_options['options_ittab_du']) ? 'du' : 'sie';
		}
		$configured = getDolGlobalInt('ITTABPDF_TPL_'.strtoupper($key).'_'.strtoupper($form));
		$templateId = $configured > 0 ? $configured : WerkbankMailTemplates::find($this->db, $key, $form, $conf->entity);
		$template = $templateId > 0 ? $this->db->query("SELECT topic, content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE rowid = ".((int) $templateId)) : false;
		$template = $template ? $this->db->fetch_object($template) : null;
		if (!$template) {
			$out['error'] = 'Keine E-Mail-Vorlage für '.$key.' ('.$form.')';
			return $out;
		}
		$out['template'] = $templateId;

		$files = array('paths' => array(), 'names' => array(), 'mimes' => array());
		if ($withPdf) {
			if ($object->generateDocument('werkbank', $langs) <= 0) {
				$out['error'] = 'PDF: '.$object->error;
				return $out;
			}
			$file = DOL_DATA_ROOT.'/'.$object->last_main_doc;
			if (empty($object->last_main_doc) || !is_file($file)) {
				$out['error'] = 'PDF nicht gefunden';
				return $out;
			}
			$files = array('paths' => array($file), 'names' => array(basename($file)), 'mimes' => array('application/pdf'));
		}

		$substitutions = getCommonSubstitutionArray($langs, 0, null, $object);
		$substitutions['__SENDEREMAIL_SIGNATURE__'] = $user->signature;
		$substitutions['__EMAIL__'] = $to;
		complete_substitutions_array($substitutions, $langs, $object, array('mode' => 'formemail', 'ittab_auto' => 1));
		$subject = make_substitutions((string) $template->topic, $substitutions);
		// Only this path sends a full document with <style> (light and dark);
		// templates and hand-sent mails carry inline styles only.
		$message = WerkbankMailTemplates::wrapDocument(make_substitutions((string) $template->content, $substitutions));

		$fromAddress = getDolGlobalString('MAIN_MAIL_EMAIL_FROM', (string) $mysoc->email);
		$from = dol_string_nospecial((string) $mysoc->name, ' ', array(',')).' <'.$fromAddress.'>';
		$bcc = getDolGlobalString('ITTABPDF_AUTOSEND_BCC', (string) $mysoc->email);
		$prefixes = array('propal' => 'pro', 'facture' => 'inv', 'commande' => 'ord', 'shipping' => 'shi', 'fichinter' => 'int', 'contrat' => 'con',
			'supplier_proposal' => 'spr', 'order_supplier' => 'sor', 'societe' => 'thi');
		$trackid = ($prefixes[$object->element] ?? 'ittab').$object->id;
		$mail = new CMailFile($subject, $to, $from, $message, $files['paths'], $files['mimes'], $files['names'], '', $bcc, 0, 1, '', '', $trackid, '', 'standard');
		if (!empty($mail->error) || !empty($mail->errors) || !$mail->sendfile()) {
			$out['error'] = trim($mail->error.' '.implode(' ', (array) $mail->errors)) ?: 'Versand fehlgeschlagen';
			return $out;
		}

		// History entry, the same fields a manual send fills in.
		if ($trigger !== '') {
			$object->socid = $thirdparty->id;
			$object->sendtoid = $sendtoid;
			$object->actiontypecode = 'AC_OTH_AUTO';
			$object->actionmsg = $message;
			$object->actionmsg2 = $langs->transnoentities('MailSentByTo', CMailFile::getValidAddress($from, 4, 0, 1), CMailFile::getValidAddress($to, 4, 0, 1));
			if (getDolGlobalString('MAIN_MAIL_REPLACE_EVENT_TITLE_BY_EMAIL_SUBJECT')) {
				$object->actionmsg2 = $subject;
			}
			$object->trackid = $trackid;
			$object->fk_element = $object->id;
			$object->elementtype = $object->element;
			if ($files['paths']) {
				$object->attachedfiles = $files;
			}
			$object->email_msgid = $mail->msgid;
			$object->email_from = $from;
			$object->email_subject = $subject;
			$object->email_to = $to;
			$object->email_tocc = '';
			$object->email_tobcc = $bcc;
			$object->context['email_msgid'] = $mail->msgid;
			$object->context['email_from'] = $from;
			$object->context['email_subject'] = $subject;
			$object->context['email_to'] = $to;
			$object->context['email_tobcc'] = $bcc;
			if ($trigger === 'BILL_SENTBYMAIL' && $key !== 'paid') {
				$this->db->query("UPDATE ".MAIN_DB_PREFIX."facture SET email_sent_counter = email_sent_counter + 1 WHERE rowid = ".((int) $object->id));
			}
			$this->db->begin();
			if ($object->call_trigger($trigger, $user) < 0) {
				$this->db->rollback();
				$out['ok'] = true;
				$out['error'] = 'Gesendet, aber kein Eintrag in der Historie: '.$object->error;
				return $out;
			}
			$this->db->commit();
		}
		$out['ok'] = true;
		return $out;
	}
}
