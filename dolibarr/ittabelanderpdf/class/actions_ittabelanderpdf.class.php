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

/**
 * Hooks on the proposal and invoice cards: a banner while an automatic
 * e-mail is planned (with "Abbrechen") or has failed (with "Erneut senden").
 */
class ActionsIttabelanderpdf
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $resprints = '';

	/** @var string[] */
	public $errors = array();

	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Cancel or retry from the banner.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     Card object
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;
		if ($action === 'ittabpdf_unterlage_pdf' && is_object($object) && $object->element === 'knowledgerecord') {
			return $this->writeUnterlage($object);
		}
		if ($action === 'ittabpdf_attach' && is_object($object) && $object->element === 'ticket') {
			return $this->attachToTicket($object, $action);
		}
		if (!in_array($action, array('ittabpdf_cancel', 'ittabpdf_retry'), true) || !is_object($object) || empty($object->id)) {
			return 0;
		}
		if (GETPOST('token', 'alphanohtml') !== currentToken() || !$this->mayChange($object)) {
			accessforbidden();
		}
		dol_include_once('/ittabelanderpdf/class/werkbankmailqueue.class.php');
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		$queue = new WerkbankMailQueue($this->db);
		$rowid = GETPOSTINT('queueid');
		$known = false;
		foreach ($queue->openFor($object->element, (int) $object->id) as $row) {
			$known = $known || ((int) $row->rowid === $rowid);
		}
		if ($known) {
			$done = $action === 'ittabpdf_cancel' ? $queue->cancel($rowid, $user) : $queue->retry($rowid, $user);
			setEventMessages($langs->trans($done ? ($action === 'ittabpdf_cancel' ? 'WerkbankAutoCancelled' : 'WerkbankAutoRetried') : 'WerkbankAutoTooLate'), null, $done ? 'mesgs' : 'warnings');
		}
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
		exit;
	}

	/**
	 * The banner, printed where Dolibarr prints its confirmation boxes.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject        $object     Card object
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;
		if (is_object($object) && !empty($object->id) && $object->element === 'knowledgerecord') {
			$this->resprints = $this->unterlageBox($object);
			return 0;
		}
		if (is_object($object) && !empty($object->id) && $object->element === 'ticket' && in_array($action, array('presend', 'presend_addmessage'), true)) {
			$this->resprints = $this->ticketAttachBox($object);
			return 0;
		}
		if (!is_object($object) || empty($object->id) || !in_array($object->element, array('propal', 'facture'), true)) {
			return 0;
		}
		dol_include_once('/ittabelanderpdf/class/werkbankmailqueue.class.php');
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		$queue = new WerkbankMailQueue($this->db);
		$html = '';
		foreach ($queue->openFor($object->element, (int) $object->id) as $row) {
			$link = $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&queueid='.((int) $row->rowid).'&token='.newToken();
			if ($row->status === 'planned') {
				$text = $langs->trans('WerkbankAutoPlanned', dol_print_date($row->date_due, 'hour', 'tzuser'), $langs->transnoentities('WerkbankEvent_'.$row->event));
				$button = $this->mayChange($object) ? ' <a class="butActionDelete smallpaddingimp" href="'.$link.'&action=ittabpdf_cancel">'.$langs->trans('WerkbankAutoCancel').'</a>' : '';
				$html .= '<div class="warning ittabpdf-autosend">'.img_picto('', 'email', 'class="pictofixedwidth"').dol_escape_htmltag($text).$button.'</div>';
			} else {
				$text = $langs->trans('WerkbankAutoFailed', $langs->transnoentities('WerkbankEvent_'.$row->event), (string) $row->error);
				$button = $this->mayChange($object) ? ' <a class="butAction smallpaddingimp" href="'.$link.'&action=ittabpdf_retry">'.$langs->trans('WerkbankAutoRetry').'</a>' : '';
				$html .= '<div class="error ittabpdf-autosend">'.img_picto('', 'warning', 'class="pictofixedwidth"').dol_escape_htmltag($text).$button.'</div>';
			}
		}
		$this->resprints = $html;
		return 0;
	}

	/**
	 * Button "PDF erzeugen" on the card of a company document.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject        $object      Card object
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;
		if (!is_object($object) || empty($object->id) || $object->element !== 'knowledgerecord') {
			return 0;
		}
		dol_include_once('/ittabelanderpdf/class/werkbankunterlagen.class.php');
		$unterlagen = new WerkbankUnterlagen($this->db);
		if (!$unterlagen->inCategories($object)) {
			return 0;
		}
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		$allowed = $user->hasRight('knowledgemanagement', 'knowledgerecord', 'write');
		$released = WerkbankUnterlagen::isReleased($object);
		$url = $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=ittabpdf_unterlage_pdf&token='.newToken();
		$title = !$allowed ? $langs->trans('NotEnoughPermissions') : ($released ? '' : $langs->trans('WerkbankDocsOnlyReleased'));
		print dolGetButtonAction($title, $langs->trans('WerkbankDocsGenerate'), 'default', $url, 'ittabpdf-unterlage-pdf', $allowed && $released);
		return 0;
	}

	/**
	 * Unterlagen for the e-mail form: a choice of the PDFs in the folder,
	 * put into the form's list of attachments, plus the defaults of the
	 * document type when the form opens or a template is chosen.
	 *
	 * Dolibarr's form has no hook inside its attachment row, so the form is
	 * built once more here (the hook knows it is inside by $inside) and the
	 * choice is placed in front of the attachment list.
	 *
	 * @param array<string,mixed> $parameters  addfileaction, removefileaction, trackid
	 * @param FormMail            $object      The form
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int                             1 when the form was replaced by the one with the choice
	 */
	public function getFormMail($parameters, &$object, &$action, $hookmanager)
	{
		static $inside = false;
		if (!is_object($object) || !($object instanceof FormMail) || (string) $object->withfile !== '2' || !empty($object->withfilereadonly)) {
			return 0;
		}
		dol_include_once('/ittabelanderpdf/class/werkbankunterlagen.class.php');
		$unterlagen = new WerkbankUnterlagen($this->db);
		if ($inside) {
			$this->addUnterlagen($object, $unterlagen);
			return 0;
		}
		$files = $unterlagen->files();
		if (!$files) {
			return 0;
		}
		$inside = true;
		$html = $object->get_form($parameters['addfileaction'], $parameters['removefileaction']);
		$inside = false;
		$anchor = '<input type="hidden" class="removedfilehidden"';
		$at = strpos($html, $anchor);
		if ($at === false) {
			// Unknown form layout: the files can still come as defaults.
			$this->resprints = $html;
			return 1;
		}
		$this->resprints = substr($html, 0, $at).$this->unterlagenChoice($files, $parameters['addfileaction']).substr($html, $at);
		return 1;
	}

	/**
	 * The chosen Unterlagen (button "addfile") and, on a fresh form, the
	 * defaults of the document type, into the form's session list.
	 *
	 * @param FormMail           $formmail   Form
	 * @param WerkbankUnterlagen $unterlagen Unterlagen
	 * @return void
	 */
	private function addUnterlagen($formmail, $unterlagen)
	{
		$paths = array();
		if (GETPOST('addfile', 'alpha') && GETPOSTISSET('ittabpdf_unterlagen')) {
			foreach (GETPOST('ittabpdf_unterlagen', 'array:alphanohtml') as $name) {
				$path = $unterlagen->path($name);
				if ($path !== '') {
					$paths[] = $path;
				}
			}
		}
		$fresh = GETPOST('mode', 'alpha') === 'init' || (GETPOST('modelselected') && GETPOST('modelmailselected', 'alpha') && GETPOST('modelmailselected', 'alpha') != '-1');
		$context = isset($formmail->param['models']) ? (string) $formmail->param['models'] : '';
		if ($fresh && isset(WerkbankUnterlagen::MAIL_CONTEXTS[$context])) {
			$kind = $this->isPrivateCustomer() ? 'B2C' : 'B2B';
			$paths = array_merge($paths, $unterlagen->pathsFrom('ITTABPDF_DOCS_'.strtoupper($context).'_'.$kind));
		}
		foreach (array_unique($paths) as $path) {
			$formmail->add_attached_files($path, basename($path), 'application/pdf');
		}
	}

	/**
	 * The third party of the card the form is on is a private person
	 * (third party type "Privatperson", TE_PRIVATE).
	 *
	 * @return bool
	 */
	private function isPrivateCustomer()
	{
		$card = isset($GLOBALS['object']) && is_object($GLOBALS['object']) ? $GLOBALS['object'] : null;
		if (!$card) {
			return false;
		}
		$thirdparty = $card->element === 'societe' ? $card : (isset($card->thirdparty) && is_object($card->thirdparty) ? $card->thirdparty : null);
		if (!$thirdparty && method_exists($card, 'fetch_thirdparty') && !empty($card->socid)) {
			$card->fetch_thirdparty();
			$thirdparty = $card->thirdparty;
		}
		if (!$thirdparty) {
			return false;
		}
		if (empty($thirdparty->typent_code) && !empty($thirdparty->typent_id)) {
			$thirdparty->typent_code = (string) dol_getIdFromCode($this->db, (int) $thirdparty->typent_id, 'c_typent', 'id', 'code');
		}
		return isset($thirdparty->typent_code) && $thirdparty->typent_code === 'TE_PRIVATE';
	}

	/**
	 * The choice inside the mail form: one select, one button that posts as
	 * Dolibarr's "addfile" so the form keeps its text and is shown again.
	 *
	 * @param array<string,string> $files         File name => label
	 * @param string               $addfileaction Name of the add button
	 * @return string
	 */
	private function unterlagenChoice(array $files, $addfileaction)
	{
		global $langs, $form;
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		if (!is_object($form)) {
			$form = new Form($this->db);
		}
		$out = '<div class="ittabpdf-unterlagen paddingbottom">';
		$out .= '<span class="opacitymedium">'.$langs->trans('WerkbankDocsAttach').'</span> ';
		$out .= $form->multiselectarray('ittabpdf_unterlagen', $files, array(), 0, 0, 'minwidth300 maxwidth500', 0, 0, '', '', $langs->transnoentitiesnoconv('WerkbankDocsChoose'));
		$out .= ' <input type="submit" class="button smallpaddingimp" name="'.dol_escape_htmltag($addfileaction).'" value="'.dol_escape_htmltag($langs->trans('WerkbankDocsAttachButton')).'">';
		$out .= '</div>';
		return $out;
	}

	/**
	 * Ticket messages use a form of their own without a hook: a small form
	 * above it puts the chosen Unterlagen into the message's attachments.
	 *
	 * @param Ticket $ticket Ticket
	 * @return string
	 */
	private function ticketAttachBox($ticket)
	{
		global $langs, $user, $form;
		if (!$user->hasRight('ticket', 'write')) {
			return '';
		}
		dol_include_once('/ittabelanderpdf/class/werkbankunterlagen.class.php');
		$unterlagen = new WerkbankUnterlagen($this->db);
		$files = $unterlagen->files();
		if (!$files) {
			return '';
		}
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		if (!is_object($form)) {
			$form = new Form($this->db);
		}
		$out = '<form method="POST" class="ittabpdf-unterlagen" action="'.$_SERVER['PHP_SELF'].'?track_id='.urlencode((string) $ticket->track_id).'#formmailbeforetitle">';
		$out .= '<input type="hidden" name="token" value="'.newToken().'">';
		$out .= '<input type="hidden" name="action" value="ittabpdf_attach">';
		$out .= '<div class="info">'.img_picto('', 'pdf', 'class="pictofixedwidth"').$langs->trans('WerkbankDocsAttachTicket').' ';
		$out .= $form->multiselectarray('ittabpdf_unterlagen', $files, array(), 0, 0, 'minwidth300 maxwidth500', 0, 0, '', '', $langs->transnoentitiesnoconv('WerkbankDocsChoose'));
		$out .= ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('WerkbankDocsAttachButton')).'">';
		$out .= '</div></form>';
		return $out;
	}

	/**
	 * Posted from ticketAttachBox(): the files into the session list of the
	 * ticket's message form, then that form again.
	 *
	 * @param Ticket $ticket Ticket
	 * @param string $action Action, set to presend_addmessage
	 * @return int
	 */
	private function attachToTicket($ticket, &$action)
	{
		global $user, $langs;
		if (GETPOST('token', 'alphanohtml') !== currentToken() || !$user->hasRight('ticket', 'write')) {
			accessforbidden();
		}
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
		dol_include_once('/ittabelanderpdf/class/werkbankunterlagen.class.php');
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		$unterlagen = new WerkbankUnterlagen($this->db);
		$formmail = new FormMail($this->db);
		$formmail->trackid = 'tic'.((int) $ticket->id);
		$added = 0;
		foreach (GETPOST('ittabpdf_unterlagen', 'array:alphanohtml') as $name) {
			$path = $unterlagen->path($name);
			if ($path !== '') {
				$formmail->add_attached_files($path, basename($path), 'application/pdf');
				$added++;
			}
		}
		if ($added) {
			setEventMessages($langs->trans('WerkbankDocsAttached', $added), null, 'mesgs');
		}
		$action = 'presend_addmessage';
		return 0;
	}

	/**
	 * Where the article's PDF is, on its card; nothing for articles outside
	 * the chosen categories.
	 *
	 * @param KnowledgeRecord $record Article
	 * @return string
	 */
	private function unterlageBox($record)
	{
		global $langs;
		dol_include_once('/ittabelanderpdf/class/werkbankunterlagen.class.php');
		$unterlagen = new WerkbankUnterlagen($this->db);
		if (!$unterlagen->inCategories($record)) {
			return '';
		}
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		if (empty($record->array_options) && method_exists($record, 'fetch_optionals')) {
			$record->fetch_optionals();
		}
		$name = WerkbankUnterlagen::slugOf($record).'.pdf';
		$relative = WerkbankUnterlagen::relativeDir().'/'.$name;
		$path = WerkbankUnterlagen::dir().'/'.$name;
		if (is_file($path)) {
			$link = '<a href="'.DOL_URL_ROOT.'/document.php?modulepart=ecm&amp;file='.urlencode($relative).'">'.img_picto('', 'pdf', 'class="pictofixedwidth"').dol_escape_htmltag($relative).'</a>';
			$text = $langs->trans('WerkbankDocsBoxFile').' '.$link.' <span class="opacitymedium">('.dol_print_date(dol_filemtime($path), 'dayhour', 'tzuser').')</span>';
		} else {
			$text = $langs->trans('WerkbankDocsBoxNone').' <span class="opacitymedium">'.dol_escape_htmltag($relative).'</span>';
		}
		if (!WerkbankUnterlagen::isReleased($record)) {
			$text .= ' – '.$langs->trans('WerkbankDocsOnlyReleased');
		}
		return '<div class="info ittabpdf-unterlage">'.$text.'</div>';
	}

	/**
	 * Button "PDF erzeugen" on the article card.
	 *
	 * @param KnowledgeRecord $record Article
	 * @return int
	 */
	private function writeUnterlage($record)
	{
		global $user, $langs;
		if (GETPOST('token', 'alphanohtml') !== currentToken() || !$user->hasRight('knowledgemanagement', 'knowledgerecord', 'write')) {
			accessforbidden();
		}
		dol_include_once('/ittabelanderpdf/class/werkbankunterlagen.class.php');
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		$unterlagen = new WerkbankUnterlagen($this->db);
		if (method_exists($record, 'fetch_optionals')) {
			$record->fetch_optionals();
		}
		if (!WerkbankUnterlagen::isReleased($record) || !$unterlagen->inCategories($record)) {
			setEventMessages($langs->trans('WerkbankDocsOnlyReleased'), null, 'warnings');
		} else {
			$path = $unterlagen->generate($record, $user);
			if ($path === '') {
				setEventMessages($langs->trans('WerkbankDocsFailed', $unterlagen->error), null, 'errors');
			} else {
				setEventMessages($langs->trans('WerkbankDocsWritten', WerkbankUnterlagen::relativeDir().'/'.basename($path)), null, 'mesgs');
			}
		}
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $record->id));
		exit;
	}

	/**
	 * @param CommonObject $object Document
	 * @return bool                The user may change this document
	 */
	private function mayChange($object)
	{
		global $user;
		return $object->element === 'propal' ? $user->hasRight('propal', 'creer') : $user->hasRight('facture', 'creer');
	}
}
