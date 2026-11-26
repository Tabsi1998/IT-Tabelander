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

require_once __DIR__.'/werkbankdocs.class.php';
require_once __DIR__.'/werkbanksignature.class.php';

/**
 * The text of an e-mail with company documents ("Unterlagen zusenden"):
 * subject, opening line, the list of the attached documents with title,
 * "Stand" and what the customer is asked to do, and the closing line, for
 * one or several documents, Sie and Du. Built from the files attached in
 * the mail form, so it follows when documents are added or removed.
 *
 * Variables: __ITTAB_UNTERLAGEN_BETREFF__ (and _DU), __ITTAB_UNTERLAGEN_EINLEITUNG__,
 * __ITTAB_UNTERLAGEN_LISTE__, __ITTAB_UNTERLAGEN_SCHLUSS__ (each also _DU);
 * the last three are whole paragraphs (HTML).
 */
class WerkbankDocMail
{
	/**
	 * The documents among attached files: general ones of the Unterlagen
	 * folder and those generated for this customer, in the order attached.
	 *
	 * @param DoliDB   $db    Database
	 * @param int      $socid Customer (0: only general documents)
	 * @param string[] $paths Attached files
	 * @return array<int,array{slug:string,title:string,stand:string,action:string,generated:bool}>
	 */
	public static function items($db, $socid, array $paths)
	{
		$docs = new WerkbankDocs($db);
		$documents = $docs->documents();
		$actions = array();
		if ($socid > 0) {
			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			$soc = new Societe($db);
			if ($soc->fetch($socid) > 0) {
				foreach ($docs->status($soc) as $entry) {
					$actions[$entry['rule']->slug] = (string) $entry['rule']->action;
				}
			}
		}
		$byKind = array('legal' => 'accept', 'customer' => 'sign', 'internal' => 'handover');
		$items = array();
		foreach ($paths as $path) {
			$slugs = WerkbankUnterlagen::slugsIn(array($path));
			$generated = false;
			if (!$slugs && $socid > 0) {
				$slugs = $docs->slugsForPaths($socid, array($path));
				$generated = (bool) $slugs;
			}
			if (!$slugs || !isset($documents[$slugs[0]])) {
				continue;
			}
			$slug = $slugs[0];
			$document = $documents[$slug];
			$action = isset($actions[$slug]) ? $actions[$slug] : (isset($byKind[$document['kind']]) ? $byKind[$document['kind']] : 'handover');
			$items[] = array('slug' => $slug, 'title' => (string) $document['title'], 'stand' => (string) $document['stand'], 'action' => $action, 'generated' => $generated);
		}
		return $items;
	}

	/**
	 * The variables for the attached documents.
	 *
	 * @param DoliDB   $db    Database
	 * @param int      $socid Customer
	 * @param string[] $paths Attached files
	 * @return array<string,string>
	 */
	public static function substitutions($db, $socid, array $paths)
	{
		return self::fromItems(self::items($db, $socid, $paths));
	}

	/**
	 * @param array<int,array<string,mixed>> $items From items()
	 * @return array<string,string>
	 */
	public static function fromItems(array $items)
	{
		global $mysoc, $langs;
		require_once __DIR__.'/werkbankmailtemplates.class.php';
		$p = WerkbankMailTemplates::P;
		$company = (string) $mysoc->name;
		$count = count($items);
		$values = array();
		foreach (array('' => 'sie', '_DU' => 'du') as $suffix => $form) {
			$du = $form === 'du';
			if ($count === 1) {
				$subject = $items[0]['title'].' von '.$company;
			} else {
				$subject = ($du ? 'Deine Unterlagen von ' : 'Ihre Unterlagen von ').$company;
			}
			if ($count === 0) {
				$intro = $du ? 'anbei bekommst du die gewünschten Unterlagen als PDF.' : 'anbei erhalten Sie die gewünschten Unterlagen als PDF.';
			} elseif ($count === 1) {
				$intro = $du ? 'anbei bekommst du diese Unterlage als PDF:' : 'anbei erhalten Sie diese Unterlage als PDF:';
			} else {
				$intro = $du ? 'anbei bekommst du diese Unterlagen als PDF:' : 'anbei erhalten Sie diese Unterlagen als PDF:';
			}
			$todo = array(
				'accept' => $du ? 'bitte lies sie durch und bestätige sie kurz mit einer Antwort auf diese Mail.' : 'bitte lesen Sie sie durch und bestätigen Sie sie kurz mit einer Antwort auf diese E-Mail.',
				'sign' => $du ? 'bitte unterschreib sie und schick sie zurück (Scan oder Foto genügt) oder unterschreib bei mir am Handy.'
					: 'bitte unterschreiben Sie sie und schicken Sie sie zurück (Scan oder Foto genügt) oder unterschreiben Sie bei mir am Handy.',
				'handover' => $du ? 'zu deiner Information.' : 'zu Ihrer Information.',
			);
			$list = '';
			$asks = false;
			foreach ($items as $item) {
				$stand = $item['stand'] !== '' ? ' (Stand '.dol_print_date(dol_stringtotime($item['stand'].' 00:00:00'), 'day', 'tzuser', $langs).')' : '';
				$made = $item['generated'] ? ($du ? ', für dich ausgefüllt' : ', für Sie ausgefüllt') : '';
				$list .= '<li style="margin:0 0 8px 0;"><strong>'.dol_escape_htmltag($item['title'], 0, 0, '', 1).'</strong>'.dol_escape_htmltag($stand.$made, 0, 0, '', 1).': '
					.dol_escape_htmltag($todo[$item['action']] ?? $todo['handover'], 0, 0, '', 1).'</li>';
				$asks = $asks || in_array($item['action'], array('accept', 'sign'), true);
			}
			$values['__ITTAB_UNTERLAGEN_BETREFF'.$suffix.'__'] = $subject;
			$values['__ITTAB_UNTERLAGEN_EINLEITUNG'.$suffix.'__'] = '<p style="'.$p.'">'.dol_escape_htmltag($intro, 0, 0, '', 1).'</p>';
			$values['__ITTAB_UNTERLAGEN_LISTE'.$suffix.'__'] = $list !== '' ? '<ul style="margin:0 0 16px 0;padding:0 0 0 22px;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:1.6;color:#3B4D63;">'.$list.'</ul>' : '';
			$values['__ITTAB_UNTERLAGEN_SCHLUSS'.$suffix.'__'] = $asks
				? '<p style="'.$p.'">'.($du ? 'Danke, dass du dir kurz Zeit dafür nimmst.' : 'Vielen Dank, dass Sie sich kurz Zeit dafür nehmen.').'</p>' : '';
		}
		return $values;
	}

	/**
	 * A text compared without markup and spacing (what the editor changes on its own).
	 *
	 * @param string $html Message
	 * @return string
	 */
	public static function fingerprint($html)
	{
		$text = html_entity_decode(strip_tags(str_replace(array('<br>', '<br/>', '<br />', '</p>', '</li>'), ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return md5(trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text)));
	}
}
