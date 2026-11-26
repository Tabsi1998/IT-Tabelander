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
		if ($action === 'ittabpdf_takeover' && is_object($object) && $object->element === 'knowledgerecord') {
			if (GETPOST('token', 'alphanohtml') !== currentToken() || !$user->hasRight('ittabelanderpdf', 'unterlagen', 'write')) {
				accessforbidden();
			}
			dol_include_once('/ittabelanderpdf/class/werkbankdoc.class.php');
			$langs->load('ittabelanderpdf@ittabelanderpdf');
			$store = new WerkbankDoc($this->db);
			$taken = $store->importKnowledge($user);
			setEventMessages($taken < 0 ? $langs->trans('WerkbankDocTakeoverFailed', $store->error) : $langs->trans('WerkbankDocTakenOver', $taken), null, $taken < 0 ? 'errors' : 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
			exit;
		}
		if ($action === 'ittabpdf_intake' && is_object($object) && $object->element === 'ticket') {
			return $this->writeIntake($object);
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
		$missing = $this->missingBanner($object);
		if (!is_object($object) || empty($object->id) || !in_array($object->element, array('propal', 'facture'), true)) {
			$this->resprints = $missing;
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
		$this->resprints = $missing.$html;
		return 0;
	}

	/**
	 * "2 Unterlagen fehlen: AVV, …" with the way to the customer's tab, on the
	 * cards of the customer, proposals, contracts and interventions.
	 *
	 * @param CommonObject $object Card object
	 * @return string
	 */
	private function missingBanner($object)
	{
		global $langs, $user;
		if (!is_object($object) || empty($object->id) || !in_array($object->element, array('societe', 'propal', 'contrat', 'fichinter'), true)
			|| !$user->hasRight('societe', 'lire') || !empty($user->socid) || !getDolGlobalInt('ITTABPDF_DOCS_BANNER', 1)) {
			return '';
		}
		$socid = $object->element === 'societe' ? (int) $object->id : (int) (!empty($object->socid) ? $object->socid : 0);
		if ($socid <= 0) {
			return '';
		}
		dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = $object->element === 'societe' ? $object : new Societe($this->db);
		if ($object->element !== 'societe' && $soc->fetch($socid) <= 0) {
			return '';
		}
		$docs = new WerkbankDocs($this->db);
		$missing = $docs->missing($soc);
		if (!$missing) {
			return '';
		}
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		$url = dol_buildpath('/ittabelanderpdf/docs.php', 1).'?socid='.$socid;
		return '<div class="warning ittabpdf-docs-missing">'.img_picto('', 'warning', 'class="pictofixedwidth"')
			.dol_escape_htmltag($langs->transnoentities(count($missing) === 1 ? 'WerkbankDocsBannerOne' : 'WerkbankDocsBanner', count($missing), implode(', ', $missing)))
			.' <a class="butAction smallpaddingimp" href="'.$url.'">'.$langs->trans('WerkbankDocsBannerButton').'</a></div>';
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
		if (is_object($object) && !empty($object->id) && $object->element === 'ticket') {
			global $langs, $user;
			$langs->load('ittabelanderpdf@ittabelanderpdf');
			$url = $_SERVER['PHP_SELF'].'?track_id='.urlencode((string) $object->track_id).'&action=ittabpdf_intake&token='.newToken();
			print dolGetButtonAction($user->hasRight('ticket', 'write') ? '' : $langs->trans('NotEnoughPermissions'), $langs->trans('WerkbankIntakeButton'), 'default', $url, 'ittabpdf-intake', $user->hasRight('ticket', 'write'));
			print dolGetButtonAction($user->hasRight('ticket', 'write') ? '' : $langs->trans('NotEnoughPermissions'), $langs->trans('WerkbankSignButtonIntake'), 'default',
				dol_buildpath('/ittabelanderpdf/sign.php', 1).'?kind=intake&id='.((int) $object->id), 'ittabpdf-intake-sign', $user->hasRight('ticket', 'write'));
			return 0;
		}
		if (!is_object($object) || empty($object->id) || $object->element !== 'knowledgerecord') {
			return 0;
		}
		// Company documents live in the module now: an article of the old
		// categories can be taken over (once); the article itself stays.
		dol_include_once('/ittabelanderpdf/class/werkbankunterlagen.class.php');
		$unterlagen = new WerkbankUnterlagen($this->db);
		if (!$unterlagen->inCategories($object) || $this->takenOver($object)) {
			return 0;
		}
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		$allowed = $user->hasRight('ittabelanderpdf', 'unterlagen', 'write');
		$url = $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=ittabpdf_takeover&token='.newToken();
		print dolGetButtonAction($allowed ? '' : $langs->trans('NotEnoughPermissions'), $langs->trans('WerkbankDocTakeover'), 'default', $url, 'ittabpdf-takeover', $allowed);
		return 0;
	}

	/**
	 * The module document an article was taken over into, or null.
	 *
	 * @param KnowledgeRecord $record Article
	 * @return object|null
	 */
	private function takenOver($record)
	{
		$resql = $this->db->query("SELECT rowid, title FROM ".MAIN_DB_PREFIX."ittabpdf_doc WHERE fk_km = ".((int) $record->id));
		return $resql ? ($this->db->fetch_object($resql) ?: null) : null;
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
		if ($fresh && GETPOSTINT('ittabpdf_preselect_event') > 0) {
			// A document generated for this customer (tab "Unterlagen").
			dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
			dol_include_once('/ittabelanderpdf/class/werkbanksignature.class.php');
			$docs = new WerkbankDocs($this->db);
			$event = $docs->event(GETPOSTINT('ittabpdf_preselect_event'));
			$card = isset($GLOBALS['object']) && is_object($GLOBALS['object']) ? $GLOBALS['object'] : null;
			$socid = $card ? ($card->element === 'societe' ? (int) $card->id : (int) $card->socid) : 0;
			if ($event && in_array($event->state, array('generated', 'signed'), true) && (int) $event->fk_soc === $socid && (string) $event->filename !== '') {
				$file = WerkbankSignature::thirdpartyDir($socid).'/'.basename((string) $event->filename);
				if (is_readable($file)) {
					$paths[] = $file;
				}
			}
		}
		if ($fresh && GETPOST('ittabpdf_preselect', 'alphanohtml') !== '') {
			$path = $unterlagen->path(GETPOST('ittabpdf_preselect', 'alphanohtml'));
			if ($path !== '') {
				$paths[] = $path;
			}
		}
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
	 * On the card of an article of the old categories: company documents are
	 * kept in the module now (with a link to it), or the way to take it over.
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
		$doc = $this->takenOver($record);
		if ($doc) {
			return '<div class="info ittabpdf-unterlage">'.img_picto('', 'pdf', 'class="pictofixedwidth"').$langs->trans('WerkbankDocKnowledgeMoved')
				.' <a href="'.dol_buildpath('/ittabelanderpdf/unterlage_card.php', 1).'?id='.((int) $doc->rowid).'">'.dol_escape_htmltag($doc->title).'</a></div>';
		}
		return '<div class="warning ittabpdf-unterlage">'.$langs->trans('WerkbankDocKnowledgeNotMoved').'</div>';
	}

	/**
	 * Button "Geräteannahmeschein" on the ticket card: the PDF into the
	 * ticket's documents, ready to print and sign.
	 *
	 * @param Ticket $ticket Ticket
	 * @return int
	 */
	private function writeIntake($ticket)
	{
		global $user, $langs;
		if (GETPOST('token', 'alphanohtml') !== currentToken() || !$user->hasRight('ticket', 'write')) {
			accessforbidden();
		}
		dol_include_once('/ittabelanderpdf/class/werkbankintake.class.php');
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		dol_include_once('/ittabelanderpdf/class/werkbanksignature.class.php');
		$intake = new WerkbankIntake($this->db);
		// Signatures given on the device so far stay on the slip.
		$signature = new WerkbankSignature($this->db);
		$path = $signature->write('intake', (int) $ticket->id, array('object' => $ticket, 'run' => null, 'socid' => (int) $ticket->fk_soc, 'ref' => (string) $ticket->ref));
		$intake->error = $signature->error;
		if ($path === '') {
			setEventMessages($langs->trans('WerkbankIntakeFailed', $intake->error), null, 'errors');
		} else {
			$file = dol_sanitizeFileName($ticket->ref).'/'.basename($path);
			setEventMessages($langs->trans('WerkbankIntakeWritten').' <a href="'.DOL_URL_ROOT.'/document.php?modulepart=ticket&amp;file='.urlencode($file).'">'
				.dol_escape_htmltag(basename($path)).'</a>', null, 'mesgs');
		}
		header('Location: '.$_SERVER['PHP_SELF'].'?track_id='.urlencode((string) $ticket->track_id));
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
