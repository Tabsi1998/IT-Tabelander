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
	 * @param CommonObject $object Document
	 * @return bool                The user may change this document
	 */
	private function mayChange($object)
	{
		global $user;
		return $object->element === 'propal' ? $user->hasRight('propal', 'creer') : $user->hasRight('facture', 'creer');
	}
}
