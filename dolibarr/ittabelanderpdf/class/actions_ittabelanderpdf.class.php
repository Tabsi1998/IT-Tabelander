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
	/** @var string[] Documents to attach once more inside get_form() */
	private static $pending = array();

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
		// Contract and proposal: make or add a company document for this customer, filed here too.
		if (is_object($object) && !empty($object->id) && in_array($object->element, array('contrat', 'propal'), true) && (int) $object->socid > 0) {
			$langs->load('ittabelanderpdf@ittabelanderpdf');
			$allowed = $user->hasRight('societe', 'creer');
			$url = dol_buildpath('/ittabelanderpdf/docs.php', 1).'?socid='.((int) $object->socid).'&action=pickdoc&'.($object->element === 'contrat' ? 'contract' : 'propal').'='.((int) $object->id).'#docadd';
			print dolGetButtonAction($allowed ? '' : $langs->trans('NotEnoughPermissions'), $langs->trans('WerkbankDocsManualFromObject'), 'default', $url, 'ittabpdf-adddoc', $allowed);
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
		if ($inside) {
			// Dolibarr clears the attachments of a fresh form at the start of
			// get_form(): the documents are put in again here, inside.
			$this->attachPaths($object, self::$pending);
			return 0;
		}
		dol_include_once('/ittabelanderpdf/class/werkbankunterlagen.class.php');
		dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
		dol_include_once('/ittabelanderpdf/class/werkbankdocmail.class.php');
		dol_include_once('/ittabelanderpdf/class/werkbankmailtemplates.class.php');
		$unterlagen = new WerkbankUnterlagen($this->db);
		$socid = $this->formSocid();
		self::$pending = array();
		$notes = $this->addUnterlagen($object, $unterlagen, $socid);
		$attached = $object->get_attached_files();
		$paths = isset($attached['paths']) ? (array) $attached['paths'] : array();
		$items = WerkbankDocMail::items($this->db, $socid, $paths);
		$object->substit = array_merge(is_array($object->substit) ? $object->substit : array(), WerkbankDocMail::fromItems($items));
		$retext = $this->chooseTemplate($object, $socid, $items);
		$this->chooseRecipient($object);
		$this->greetRecipient($object);
		$this->dropNoFileError();

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
		$message = preg_match('#<textarea[^>]*name="message"[^>]*>(.*?)</textarea>#s', $html, $match) ? html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
		$subject = preg_match('#<input[^>]*name="subject"[^>]*value="([^"]*)"#', $html, $match) ? html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
		$this->resprints = substr($html, 0, $at).$this->unterlagenChoice($unterlagen, $socid, $parameters['addfileaction'], $notes, $retext, WerkbankDocMail::fingerprint($message), $subject).substr($html, $at);
		return 1;
	}

	/**
	 * Preselect one recipient when Dolibarr would select none (it does only
	 * when there is exactly one): the contact of the document (proposal,
	 * contract: customer contact), else the third party's main contact for
	 * documents, else the third party's own address. Never several.
	 *
	 * @param FormMail $formmail Form
	 * @return void
	 */
	private function chooseRecipient($formmail)
	{
		if (GETPOSTISSET('receiver') || GETPOST('action', 'aZ09') !== 'presend' || !is_array($formmail->withto) || count($formmail->withto) < 2) {
			return;
		}
		$keys = array_map('strval', array_keys($formmail->withto));
		$card = isset($GLOBALS['object']) && is_object($GLOBALS['object']) ? $GLOBALS['object'] : null;
		$want = '';
		dol_include_once('/ittabelanderpdf/class/werkbankmaildata.class.php');
		$contact = $card ? WerkbankMailData::contact($card) : null;
		if ($contact && !empty($contact->email) && in_array((string) $contact->id, $keys, true)) {
			$want = (string) $contact->id;
		}
		$socid = $this->formSocid();
		if ($want === '' && $socid > 0) {
			// A contact with a role at the third party (Dolibarr: contact roles of the company), the first one.
			$resql = $this->db->query("SELECT sc.fk_socpeople FROM ".MAIN_DB_PREFIX."societe_contacts AS sc INNER JOIN ".MAIN_DB_PREFIX."socpeople AS p ON p.rowid = sc.fk_socpeople"
				." WHERE sc.fk_soc = ".((int) $socid)." AND p.email <> '' AND p.statut = 1 ORDER BY sc.rowid");
			while ($resql && ($row = $this->db->fetch_object($resql))) {
				if (in_array((string) $row->fk_socpeople, $keys, true)) {
					$want = (string) $row->fk_socpeople;
					break;
				}
			}
		}
		if ($want === '' && in_array('thirdparty', $keys, true)) {
			$want = 'thirdparty';
		}
		if ($want !== '') {
			$_POST['receiver'] = array($want);
		}
	}

	/**
	 * The greeting of the module's templates for the chosen recipient: a
	 * contact by name ("Guten Tag Frau Muster," / "Hallo Maria,"), the
	 * third party's address like the third party.
	 *
	 * @param FormMail $formmail Form
	 * @return void
	 */
	private function greetRecipient($formmail)
	{
		$chosen = GETPOST('receiver', 'array');
		if (count($chosen) !== 1) {
			return;
		}
		dol_include_once('/ittabelanderpdf/class/werkbankmaildata.class.php');
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		$key = (string) reset($chosen);
		$contact = null;
		if (ctype_digit($key)) {
			$contact = new Contact($this->db);
			if ($contact->fetch((int) $key) <= 0) {
				$contact = null;
			}
		}
		$card = isset($GLOBALS['object']) && is_object($GLOBALS['object']) ? $GLOBALS['object'] : null;
		$thirdparty = null;
		if ($card) {
			if ($card->element === 'societe') {
				$thirdparty = $card;
			} elseif (method_exists($card, 'fetch_thirdparty')) {
				if (empty($card->thirdparty)) {
					$card->fetch_thirdparty();
				}
				$thirdparty = $card->thirdparty;
			}
		}
		list($du, $sie) = WerkbankMailData::greetingsFor($contact, $thirdparty);
		$formmail->substit['__ITTAB_HALLO__'] = $du;
		$formmail->substit['__ITTAB_GUTENTAG__'] = $sie;
	}

	/**
	 * Pressing "Beilegen" (or a reload for a new recipient) without a file
	 * makes Dolibarr say a file is required; for the module's choice that
	 * is no error.
	 *
	 * @return void
	 */
	private function dropNoFileError()
	{
		global $langs;
		$names = isset($_FILES['addedfile']['name']) ? (array) $_FILES['addedfile']['name'] : array();
		if (!GETPOST('addfile', 'alpha') || array_filter($names, 'strlen')) {
			return;
		}
		$langs->load('errors');
		$text = $langs->trans('ErrorFieldRequired', $langs->transnoentities('File'));
		if (!empty($_SESSION['dol_events']['errors'])) {
			$_SESSION['dol_events']['errors'] = array_values(array_filter((array) $_SESSION['dol_events']['errors'], function ($message) use ($text) {
				return $message !== $text;
			}));
			if (!$_SESSION['dol_events']['errors']) {
				unset($_SESSION['dol_events']['errors']);
			}
		}
	}

	/**
	 * The customer of the card the mail form is on (third party, or the
	 * third party of the proposal, contract, ...), 0 when there is none.
	 *
	 * @return int
	 */
	private function formSocid()
	{
		$card = isset($GLOBALS['object']) && is_object($GLOBALS['object']) ? $GLOBALS['object'] : null;
		if (!$card) {
			return 0;
		}
		return $card->element === 'societe' ? (int) $card->id : (int) (isset($card->socid) ? $card->socid : 0);
	}

	/**
	 * Pick the module's template where Dolibarr would use none or the plain
	 * message: "Unterlagen zusenden" when documents are attached on a third
	 * party, else "Nachricht" (Sie or Du after the customer's setting). The
	 * text of "Unterlagen zusenden" follows the attached documents while the
	 * user has not changed it; afterwards only on request.
	 *
	 * @param FormMail                       $formmail Form
	 * @param int                            $socid    Customer
	 * @param array<int,array<string,mixed>> $items    Attached documents
	 * @return string                                  'edited' when the text was changed by hand, else ''
	 */
	private function chooseTemplate($formmail, $socid, array $items)
	{
		global $conf;
		$edited = GETPOSTISSET('message') && GETPOST('ittabpdf_msghash', 'aZ09') !== ''
			&& WerkbankDocMail::fingerprint(GETPOST('message', 'restricthtml')) !== GETPOST('ittabpdf_msghash', 'aZ09');
		if (!isset($formmail->param['models']) || $formmail->param['models'] !== 'thirdparty' || $socid <= 0) {
			// Other forms: a text nobody changed is built again (new recipient, other documents).
			if (GETPOSTISSET('ittabpdf_msghash') && !$edited && !GETPOST('modelselected', 'alpha')) {
				unset($_POST['message'], $_GET['message'], $_REQUEST['message']);
				if (GETPOST('subject', 'restricthtml') === GETPOST('ittabpdf_subject', 'restricthtml')) {
					unset($_POST['subject'], $_GET['subject'], $_REQUEST['subject']);
				}
			}
			return '';
		}
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($this->db);
		if ($soc->fetch($socid) <= 0) {
			return '';
		}
		$form = !empty($soc->array_options['options_ittab_du']) ? 'du' : 'sie';
		$message = WerkbankMailTemplates::find($this->db, 'thirdparty', $form, (int) $conf->entity);
		$docsend = WerkbankMailTemplates::find($this->db, 'docsend', $form, (int) $conf->entity);
		$chosen = GETPOSTISSET('modelmailselected') ? GETPOSTINT('modelmailselected') : (int) (isset($formmail->param['models_id']) ? $formmail->param['models_id'] : 0);
		$picked = GETPOST('modelselected', 'alpha') !== '';
		$ours = array_filter(array($message, $docsend, WerkbankMailTemplates::find($this->db, 'thirdparty', $form === 'du' ? 'sie' : 'du', (int) $conf->entity),
			WerkbankMailTemplates::find($this->db, 'docsend', $form === 'du' ? 'sie' : 'du', (int) $conf->entity)));
		// The user picked a template himself: that one stays.
		if ($picked && $chosen > 0) {
			$want = $chosen;
		} elseif ($chosen > 0 && !in_array($chosen, $ours, true)) {
			$want = $chosen;
		} else {
			$want = $items ? $docsend : $message;
		}
		if ($want <= 0) {
			return '';
		}
		$edited = GETPOSTISSET('message') && GETPOST('ittabpdf_msghash', 'aZ09') !== ''
			&& WerkbankDocMail::fingerprint(GETPOST('message', 'restricthtml')) !== GETPOST('ittabpdf_msghash', 'aZ09');
		if ($want !== $chosen || (in_array($want, $ours, true) && (!$edited || GETPOST('ittabpdf_retext', 'int')))) {
			// Built again from the template with the documents attached now (Dolibarr keeps a posted text).
			$formmail->param['models_id'] = $want;
			$_POST['modelmailselected'] = $want;
			unset($_POST['message'], $_GET['message'], $_REQUEST['message']);
			// The subject too, unless it was changed by hand.
			if (!GETPOSTISSET('ittabpdf_subject') || GETPOST('subject', 'restricthtml') === GETPOST('ittabpdf_subject', 'restricthtml')) {
				unset($_POST['subject'], $_GET['subject'], $_REQUEST['subject']);
			}
			return '';
		}
		return $edited && in_array($want, $ours, true) ? 'edited' : '';
	}
	/**
	 * The chosen Unterlagen (button "addfile") and, on a fresh form, the
	 * defaults of the document type, into the form's session list.
	 *
	 * @param FormMail           $formmail   Form
	 * @param WerkbankUnterlagen $unterlagen Unterlagen
	 * @return void
	 */
	private function addUnterlagen($formmail, $unterlagen, $socid)
	{
		$paths = array();
		$notes = array();
		$docs = new WerkbankDocs($this->db);
		if (GETPOST('addfile', 'alpha')) {
			foreach (GETPOST('ittabpdf_unterlagen', 'array:alphanohtml') as $name) {
				$path = $unterlagen->path($name);
				if ($path !== '') {
					$paths[] = $path;
				}
			}
			foreach (GETPOST('ittabpdf_made', 'array:int') as $event) {
				$path = $this->generatedPath($docs, (int) $event, $socid);
				if ($path !== '') {
					$paths[] = $path;
				}
			}
			// Templates per customer: made for this customer now, never sent blank.
			foreach (GETPOST('ittabpdf_gen', 'array:aZ09arobase') as $slug) {
				$made = $this->generateFor($docs, (string) $slug, $socid);
				if (isset($made['path'])) {
					$paths[] = $made['path'];
				} elseif (isset($made['note'])) {
					$notes[] = $made['note'];
				}
			}
		}
		$fresh = GETPOST('mode', 'alpha') === 'init' || (GETPOST('modelselected') && GETPOST('modelmailselected', 'alpha') && GETPOST('modelmailselected', 'alpha') != '-1');
		$context = isset($formmail->param['models']) ? (string) $formmail->param['models'] : '';
		if ($fresh && GETPOSTINT('ittabpdf_preselect_event') > 0) {
			// A document generated for this customer (tab "Unterlagen").
			$path = $this->generatedPath($docs, GETPOSTINT('ittabpdf_preselect_event'), $socid);
			if ($path !== '') {
				$paths[] = $path;
			}
		}
		// "Alle fehlenden zusenden": every open required document of the customer, templates generated.
		if ($fresh && GETPOSTINT('ittabpdf_preselect_missing') && $socid > 0) {
			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			$soc = new Societe($this->db);
			if ($soc->fetch($socid) > 0) {
				$documents = $docs->documents();
				foreach ($docs->status($soc) as $entry) {
					if (!(int) $entry['rule']->required || $entry['state'] === 'done') {
						continue;
					}
					$slug = (string) $entry['rule']->slug;
					$kind = isset($documents[$slug]) ? $documents[$slug]['kind'] : '';
					if ($kind === 'customer') {
						$path = !empty($entry['generated']) ? $this->generatedPath($docs, (int) $entry['generated']->rowid, $socid) : '';
						if ($path === '') {
							$made = $this->generateFor($docs, $slug, $socid);
							$path = isset($made['path']) ? $made['path'] : '';
							if (isset($made['note'])) {
								$notes[] = $made['note'];
							}
						}
					} else {
						$path = $unterlagen->path($slug.'.pdf');
					}
					if ($path !== '') {
						$paths[] = $path;
					}
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
		self::$pending = array_values(array_unique($paths));
		$this->attachPaths($formmail, self::$pending);
		return $notes;
	}

	/**
	 * Attach files that are not attached yet.
	 *
	 * @param FormMail $formmail Form
	 * @param string[] $paths    Files
	 * @return void
	 */
	private function attachPaths($formmail, array $paths)
	{
		$attached = $formmail->get_attached_files();
		$have = isset($attached['paths']) ? (array) $attached['paths'] : array();
		foreach ($paths as $path) {
			if (!in_array($path, $have, true)) {
				$formmail->add_attached_files($path, basename($path), 'application/pdf');
				$have[] = $path;
			}
		}
	}

	/**
	 * The file of a document generated for this customer (event generated or signed).
	 *
	 * @param WerkbankDocs $docs  Documents
	 * @param int          $id    Event
	 * @param int          $socid Customer of the form
	 * @return string             Path, '' when it is not one of this customer
	 */
	private function generatedPath($docs, $id, $socid)
	{
		dol_include_once('/ittabelanderpdf/class/werkbanksignature.class.php');
		$event = $docs->event($id);
		if (!$event || !in_array($event->state, array('generated', 'signed'), true) || (int) $event->fk_soc !== $socid || $socid <= 0 || (string) $event->filename === '') {
			return '';
		}
		$file = WerkbankSignature::thirdpartyDir($socid).'/'.basename((string) $event->filename);
		return is_readable($file) ? $file : '';
	}

	/**
	 * Generate a template per customer for the customer of the form, with
	 * what was entered last time and the contract or proposal of the card.
	 * Missing required fields: a link to the form, back into this mail form.
	 *
	 * @param WerkbankDocs $docs  Documents
	 * @param string       $slug  Document
	 * @param int          $socid Customer
	 * @return array{path?:string,note?:string}
	 */
	private function generateFor($docs, $slug, $socid)
	{
		global $user, $langs;
		$documents = $docs->documents();
		if ($socid <= 0 || !isset($documents[$slug]) || $documents[$slug]['kind'] !== 'customer') {
			return array();
		}
		require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		dol_include_once('/ittabelanderpdf/class/werkbankfields.class.php');
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($this->db);
		$soc->fetch($socid);
		$rule = null;
		foreach ($docs->status($soc) as $entry) {
			if ($entry['rule']->slug === $slug) {
				$rule = $entry['rule'];
			}
		}
		if (!$rule) {
			$id = $docs->addManual($soc, $slug);
			$rule = $id > 0 ? $docs->rule($id) : null;
		}
		if (!$rule) {
			return array();
		}
		$card = isset($GLOBALS['object']) && is_object($GLOBALS['object']) ? $GLOBALS['object'] : null;
		$last = $docs->lastValues($socid, $slug);
		$contractId = $card && $card->element === 'contrat' ? (int) $card->id : ($last['contract'] ?: (int) key($docs->contracts($socid, (string) $rule->products)));
		$contract = null;
		if ($contractId > 0) {
			$contract = new Contrat($this->db);
			if ($contract->fetch($contractId) <= 0 || (int) $contract->socid !== $socid) {
				$contract = null;
			}
		}
		$contact = null;
		if ($last['contact'] > 0) {
			$contact = new Contact($this->db);
			if ($contact->fetch($last['contact']) <= 0) {
				$contact = null;
			}
		}
		$template = $docs->template($slug);
		$fields = $template ? WerkbankFields::parse((string) $template->answer) : array();
		$answers = $last['fields'];
		foreach ($fields as $name => $field) {
			if (!array_key_exists($name, $answers)) {
				$answers[$name] = $field['kind'] === 'multi' ? $field['selected'] : ($field['kind'] === 'single' ? (string) reset($field['selected']) : $field['default']);
			}
		}
		$propal = $card && $card->element === 'propal' ? $card : null;
		if ($template && !WerkbankFields::missing($fields, $answers)) {
			$event = $docs->generate($soc, $rule, $answers, $contract, $contact, $user, $propal);
			$path = $event > 0 ? $this->generatedPath($docs, $event, $socid) : '';
			if ($path !== '') {
				return array('path' => $path);
			}
		}
		// Fill in first: the form in the tab, then back here with the document attached.
		$back = $_SERVER['PHP_SELF'].'?'.http_build_query(array_filter(array('id' => $card && $card->element !== 'societe' ? (int) $card->id : null,
			'socid' => $card && $card->element === 'societe' ? $socid : null, 'action' => 'presend', 'mode' => 'init'))).'#formmail';
		$url = dol_buildpath('/ittabelanderpdf/docs.php', 1).'?socid='.$socid.'&action=generateform&rule='.((int) $rule->rowid)
			.($contract ? '&contract='.((int) $contract->id) : '').($propal ? '&propal='.((int) $propal->id) : '').'&mailback='.urlencode($back).'#docform';
		return array('note' => $langs->trans('WerkbankDocsMailFillFirst', dol_escape_htmltag($documents[$slug]['title']), $url));
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
	 * The choice above the attachment list, in three groups: generate for
	 * this customer (templates per customer), made for this customer
	 * already, general documents. Plus the state of the text.
	 *
	 * @param WerkbankUnterlagen $unterlagen    Documents
	 * @param int                $socid         Customer of the form (0: none, no templates per customer)
	 * @param string             $addfileaction Name of the attach button
	 * @param string[]           $notes         Messages (HTML) from attaching
	 * @param string             $retext        'edited' when the text was changed by hand
	 * @param string             $fingerprint   Of the text as shown
	 * @param string             $subject       Subject as shown
	 * @return string
	 */
	private function unterlagenChoice($unterlagen, $socid, $addfileaction, array $notes, $retext, $fingerprint, $subject = '')
	{
		global $langs, $form;
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		if (!is_object($form)) {
			$form = new Form($this->db);
		}
		$docs = new WerkbankDocs($this->db);
		$templates = array();
		$made = array();
		if ($socid > 0) {
			foreach ($docs->documents() as $slug => $document) {
				if ($document['kind'] === 'customer') {
					$templates[$slug] = $document['title'];
				}
			}
			$documents = $docs->documents();
			foreach ($docs->events($socid) as $event) {
				if (in_array($event->state, array('generated', 'signed'), true) && (string) $event->filename !== '' && $this->generatedPath($docs, (int) $event->rowid, $socid) !== '') {
					$title = isset($documents[$event->slug]) ? $documents[$event->slug]['title'] : $event->slug;
					$made[(int) $event->rowid] = $title.' – '.$langs->transnoentities($event->state === 'signed' ? 'WerkbankDocsMailMadeSigned' : 'WerkbankDocsMailMade',
						dol_print_date($this->db->jdate($event->date_event), 'day'));
				}
			}
		}
		$files = $unterlagen->files();
		$out = '<div class="ittabpdf-unterlagen paddingbottom">';
		$out .= '<input type="hidden" name="ittabpdf_msghash" value="'.dol_escape_htmltag($fingerprint).'">';
		$out .= '<input type="hidden" name="ittabpdf_subject" value="'.dol_escape_htmltag($subject).'">';
		foreach ($notes as $note) {
			$out .= '<div class="warning">'.$note.'</div>';
		}
		$groups = array(array('ittabpdf_gen', $templates, 'WerkbankDocsMailGroupGenerate'), array('ittabpdf_made', $made, 'WerkbankDocsMailGroupMade'),
			array('ittabpdf_unterlagen', $files, 'WerkbankDocsMailGroupGeneral'));
		$rows = '';
		foreach ($groups as $group) {
			if (!$group[1]) {
				continue;
			}
			$rows .= '<tr><td class="nowraponall paddingright tdtop ittabpdf-unterlagen-label">'.$langs->trans($group[2]).'</td><td>'
				.$form->multiselectarray($group[0], $group[1], array(), 0, 0, 'minwidth300 maxwidth500 widthcentpercentminusx', 0, '100%', '', '', $langs->transnoentitiesnoconv('WerkbankDocsChoose')).'</td></tr>';
		}
		if ($rows !== '') {
			$out .= '<table class="nobordernopadding ittabpdf-unterlagen-table">'.$rows
				.'<tr><td></td><td><input type="submit" class="button smallpaddingimp" name="'.dol_escape_htmltag($addfileaction).'" value="'.dol_escape_htmltag($langs->trans('WerkbankDocsAttachButton')).'"></td></tr></table>';
		}
		// Another recipient: the greeting follows while nobody changed the text (reload like "Beilegen").
		$out .= '<script>jQuery(function ($) {'
			.' var changed = false;'
			.' $("textarea[name=message]").on("input", function () { changed = true; });'
			.' if (window.CKEDITOR && CKEDITOR.instances.message) { CKEDITOR.instances.message.on("change", function () { changed = true; }); }'
			.' $("#receiver").on("change", function () { if (!changed) { $("#'.dol_escape_js($addfileaction).'").trigger("click"); } });'
			.'});</script>';
		if ($retext === 'edited') {
			$out .= '<div class="info">'.$langs->trans('WerkbankDocsMailEdited')
				.' <label><input type="checkbox" name="ittabpdf_retext" value="1"> '.$langs->trans('WerkbankDocsMailRetext').'</label></div>';
		}
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
