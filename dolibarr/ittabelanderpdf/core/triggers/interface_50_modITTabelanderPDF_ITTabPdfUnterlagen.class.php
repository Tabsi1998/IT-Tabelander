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
 * Company documents: a released knowledge base article of the chosen
 * categories gets its PDF in the Unterlagen folder again whenever it is
 * released, changed or put into one of those categories.
 */
class InterfaceITTabPdfUnterlagen extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'other';
		$this->description = 'Writes the Werkbank PDF of company documents from the knowledge base.';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'pdf';
	}

	/**
	 * @param string       $action Trigger code
	 * @param CommonObject $object Object
	 * @param User         $user   User
	 * @param Translate    $langs  Language
	 * @param Conf         $conf   Configuration
	 * @return int                 0 nothing done, 1 written
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('ittabelanderpdf') || !isModEnabled('knowledgemanagement') || !getDolGlobalInt('ITTABPDF_UNTERLAGEN_AUTO', 1)) {
			return 0;
		}
		$record = null;
		$released = false;
		if (in_array($action, array('KNOWLEDGERECORD_VALIDATE', 'KNOWLEDGERECORD_MODIFY', 'KNOWLEDGERECORD_REOPEN'), true)) {
			$record = $object;
			// validate() sets the status on the object only after its trigger.
			$released = $action !== 'KNOWLEDGERECORD_MODIFY' || (int) $object->status === 1;
		} elseif ($action === 'CATEGORY_MODIFY' && !empty($object->context['linkto']) && is_object($object->context['linkto'])
			&& $object->context['linkto']->element === 'knowledgerecord') {
			$record = $object->context['linkto'];
			$released = (int) $record->status === 1;
		}
		if (!$record || !$released || empty($record->id)) {
			return 0;
		}
		dol_include_once('/ittabelanderpdf/class/werkbankunterlagen.class.php');
		$unterlagen = new WerkbankUnterlagen($this->db);
		if (!$unterlagen->inCategories($record)) {
			return 0;
		}
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		$stand = $action === 'CATEGORY_MODIFY' ? null : dol_now();
		$path = $unterlagen->generate($record, $user, $stand);
		// Never block saving an article because its PDF failed: say so instead.
		if ($path === '') {
			dol_syslog('ittabelanderpdf: no PDF for knowledge record '.$record->id.': '.$unterlagen->error, LOG_WARNING);
			setEventMessages($langs->trans('WerkbankDocsFailed', $unterlagen->error), null, 'warnings');
			return 0;
		}
		setEventMessages($langs->trans('WerkbankDocsWritten', WerkbankUnterlagen::relativeDir().'/'.basename($path)), null, 'mesgs');
		return 1;
	}
}
