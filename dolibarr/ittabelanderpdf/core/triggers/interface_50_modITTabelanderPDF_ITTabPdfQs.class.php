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
 * Closing an intervention closes its open QS runs and writes their test
 * reports; with ITTABPDF_QS_BLOCK_CLOSE a run with open required points stops
 * the intervention from being closed, naming the points.
 */
class InterfaceITTabPdfQs extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'other';
		$this->description = 'Closes the QS runs of an intervention with their test reports.';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'pdf';
	}

	/**
	 * @param string       $action Trigger code
	 * @param CommonObject $object Object
	 * @param User         $user   User
	 * @param Translate    $langs  Language
	 * @param Conf         $conf   Configuration
	 * @return int                 0 nothing done, 1 closed, -1 refused
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action !== 'FICHINTER_CLOSE' || !isModEnabled('ittabelanderpdf')) {
			return 0;
		}
		dol_include_once('/ittabelanderpdf/class/werkbankqs.class.php');
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		$qs = new WerkbankQs($this->db);
		$runs = array_filter($qs->runsFor((int) $object->id), function ($run) {
			return (int) $run->status === 0;
		});
		if (!$runs) {
			return 0;
		}
		$block = getDolGlobalInt('ITTABPDF_QS_BLOCK_CLOSE', 1);
		foreach ($runs as $run) {
			$open = $qs->requiredOpen((int) $run->rowid);
			if ($open && $block) {
				$this->errors[] = $langs->transnoentities('WerkbankQsBlocksClose', $run->type_label, implode(', ', $open));
				continue;
			}
			if (!$open && $qs->close((int) $run->rowid, $object, $user) < 0) {
				$this->errors[] = $langs->transnoentities('WerkbankQsCloseFailed', $langs->transnoentities($qs->error));
			}
		}
		return $this->errors ? -1 : 1;
	}
}
