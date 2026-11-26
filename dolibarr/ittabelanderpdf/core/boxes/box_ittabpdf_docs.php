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

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

/**
 * Start page widget "Fehlende Unterlagen": customers whose required company
 * documents are not signed, uploaded or ticked yet.
 */
class box_ittabpdf_docs extends ModeleBoxes
{
	public $boxcode = 'ittabpdfdocs';
	public $boximg = 'object_company';
	public $boxlabel = 'WerkbankDocsBox';
	public $depends = array('societe', 'ittabelanderpdf');

	/**
	 * @param DoliDB $db    Database
	 * @param string $param More parameters
	 */
	public function __construct($db, $param = '')
	{
		global $user;
		$this->db = $db;
		$this->hidden = !($user->hasRight('societe', 'lire') && empty($user->socid));
	}

	/**
	 * @param int $max Customers at most
	 * @return void
	 */
	public function loadBox($max = 10)
	{
		global $langs;
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
		$this->max = $max;
		$docs = new WerkbankDocs($this->db);
		$list = $docs->customersMissing($max);
		$this->info_box_head = array('text' => $langs->trans('WerkbankDocsBoxTitle', count($list)));
		$line = 0;
		foreach ($list as $entry) {
			$url = dol_buildpath('/ittabelanderpdf/docs.php', 1).'?socid='.((int) $entry['soc']->id);
			$this->info_box_contents[$line][] = array('td' => 'class="tdoverflowmax150"', 'text' => $entry['soc']->getNomUrl(1), 'asis' => 1);
			$this->info_box_contents[$line][] = array('td' => 'class="tdoverflowmax300"',
				'text' => '<a href="'.$url.'" title="'.dol_escape_htmltag(implode(', ', $entry['missing'])).'">'.dol_escape_htmltag(implode(', ', $entry['missing'])).'</a>', 'asis' => 1);
			$this->info_box_contents[$line][] = array('td' => 'class="right"', 'text' => '<span class="badge badge-status8">'.count($entry['missing']).'</span>', 'asis' => 1);
			$line++;
		}
		if (!$list) {
			$this->info_box_contents[0][] = array('td' => 'class="center"', 'text' => '<span class="opacitymedium">'.$langs->trans('WerkbankDocsBoxNone').'</span>', 'asis' => 1);
		}
	}

	/**
	 * @param array<string,mixed>|null $head     Head
	 * @param array<int,mixed>|null    $contents Contents
	 * @param int                      $nooutput Return instead of print
	 * @return string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}
