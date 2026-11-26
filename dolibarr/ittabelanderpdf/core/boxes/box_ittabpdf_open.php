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
 * Start page widget "Offen": what waits for someone, in one list – required
 * documents missing, documents sent and not answered for some days (with the
 * days), signatures begun on the device, QS checklists not closed.
 */
class box_ittabpdf_open extends ModeleBoxes
{
	public $boxcode = 'ittabpdfopen';
	public $boximg = 'object_action';
	public $boxlabel = 'WerkbankOpenBox';
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
	 * @param int $max Lines at most
	 * @return void
	 */
	public function loadBox($max = 15)
	{
		global $langs;
		$langs->load('ittabelanderpdf@ittabelanderpdf');
		dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
		$this->max = $max;
		$docs = new WerkbankDocs($this->db);
		$items = $docs->openItems($max);
		$this->info_box_head = array('text' => $langs->trans('WerkbankOpenBoxTitle', count($items)));
		$badges = array('missing' => 'status8', 'waiting' => 'status1', 'signing' => 'status3', 'qs' => 'status4');
		foreach ($items as $line => $item) {
			$this->info_box_contents[$line][] = array('td' => 'class="nowraponall"', 'text' => '<span class="badge badge-'.$badges[$item['kind']].'">'
				.$langs->trans('WerkbankOpenKind_'.$item['kind']).'</span>', 'asis' => 1);
			$this->info_box_contents[$line][] = array('td' => 'class="tdoverflowmax150"', 'text' => $item['who'], 'asis' => 1);
			$this->info_box_contents[$line][] = array('td' => 'class="tdoverflowmax300"', 'text' => '<a href="'.$item['url'].'" title="'.dol_escape_htmltag($item['what']).'">'
				.dol_escape_htmltag($item['what']).'</a>', 'asis' => 1);
			$this->info_box_contents[$line][] = array('td' => 'class="right nowraponall opacitymedium"', 'text' => dol_escape_htmltag($item['since']), 'asis' => 1);
		}
		if (!$items) {
			$this->info_box_contents[0][] = array('td' => 'class="center"', 'text' => '<span class="opacitymedium">'.$langs->trans('WerkbankOpenBoxNone').'</span>', 'asis' => 1);
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
