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

require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

/**
 * Placeholders for company documents, the intake slip and the test report:
 * Dolibarr's own (__THIRDPARTY_NAME__, __MYCOMPANY_NAME__, …) plus the
 * module's __ITTAB_*__ for customer, company, contract and date. A
 * placeholder whose value is empty takes its line with it (fill()), so
 * "UID: __ITTAB_KUNDE_UID__" disappears for a customer without UID.
 */
class WerkbankVars
{
	/** The module's placeholders with their description (lang key suffix). */
	const OWN = array(
		'__ITTAB_KUNDE_NAME__', '__ITTAB_KUNDE_RECHTSFORM__', '__ITTAB_KUNDE_ANSCHRIFT__', '__ITTAB_KUNDE_UID__', '__ITTAB_KUNDE_FIRMENBUCH__',
		'__ITTAB_KUNDE_STEUERNUMMER__', '__ITTAB_KUNDENNR__', '__ITTAB_KUNDE_EMAIL__', '__ITTAB_KUNDE_TELEFON__', '__ITTAB_KUNDE_KONTAKT__',
		'__ITTAB_KUNDE_KONTAKT_FUNKTION__', '__ITTAB_KUNDE_VERTRETUNG__',
		'__ITTAB_FIRMA_NAME__', '__ITTAB_FIRMA_INHABER__', '__ITTAB_FIRMA_ANSCHRIFT__', '__ITTAB_FIRMA_EMAIL__', '__ITTAB_FIRMA_TELEFON__',
		'__ITTAB_FIRMA_WEB__', '__ITTAB_FIRMA_UID__', '__ITTAB_GESCHAEFTSZEITEN__',
		'__ITTAB_DATUM__', '__ITTAB_ORT__',
		'__ITTAB_VERTRAG_REF__', '__ITTAB_VERTRAG_DATUM__', '__ITTAB_VERTRAG_BEGINN__', '__ITTAB_VERTRAG_LAUFZEIT__', '__ITTAB_VERTRAG_KUENDIGUNG__',
		'__ITTAB_LEISTUNGEN__',
	);

	/** Dolibarr's own placeholders shown in the help (there are more). */
	const DOLIBARR = array('__THIRDPARTY_NAME__', '__THIRDPARTY_ADDRESS__', '__THIRDPARTY_ZIP__', '__THIRDPARTY_TOWN__', '__THIRDPARTY_EMAIL__',
		'__THIRDPARTY_TVAINTRA__', '__MYCOMPANY_NAME__', '__MYCOMPANY_EMAIL__');

	/**
	 * Values for a customer, optionally with a contract and a contact.
	 *
	 * @param DoliDB        $db       Database
	 * @param Societe|null  $soc      Customer (null: company and date only)
	 * @param Contrat|null  $contract Contract
	 * @param Contact|null  $contact  Contact
	 * @return array<string,string>   Placeholder => text (__ITTAB_LEISTUNGEN__ is HTML)
	 */
	public static function values($db, $soc, $contract = null, $contact = null)
	{
		global $langs, $mysoc;
		$langs->loadLangs(array('main', 'companies', 'ittabelanderpdf@ittabelanderpdf'));
		$values = getCommonSubstitutionArray($langs, 0, array('user', 'objectamount', 'system'), $soc);
		$line = function ($address, $zip, $town) {
			return trim(str_replace("\n", ', ', trim((string) $address)).', '.trim($zip.' '.$town), ', ');
		};
		$values['__ITTAB_DATUM__'] = dol_print_date(dol_now(), 'day', 'tzuser', $langs);
		$values['__ITTAB_ORT__'] = (string) $mysoc->town;
		$values['__ITTAB_FIRMA_NAME__'] = (string) $mysoc->name;
		$values['__ITTAB_FIRMA_INHABER__'] = (string) $mysoc->managers;
		$values['__ITTAB_FIRMA_ANSCHRIFT__'] = $line($mysoc->address, $mysoc->zip, $mysoc->town);
		$values['__ITTAB_FIRMA_EMAIL__'] = (string) $mysoc->email;
		$values['__ITTAB_FIRMA_TELEFON__'] = (string) (!empty($mysoc->phone_mobile) ? $mysoc->phone_mobile : $mysoc->phone);
		$values['__ITTAB_FIRMA_WEB__'] = preg_replace('#^https?://#', '', rtrim((string) $mysoc->url, '/'));
		$values['__ITTAB_FIRMA_UID__'] = (string) $mysoc->tva_intra;
		$values['__ITTAB_GESCHAEFTSZEITEN__'] = self::openingHours();

		$values['__ITTAB_KUNDE_NAME__'] = $soc ? (string) $soc->name : '';
		$values['__ITTAB_KUNDE_RECHTSFORM__'] = $soc && !empty($soc->forme_juridique_code) ? (string) getFormeJuridiqueLabel($soc->forme_juridique_code) : '';
		$values['__ITTAB_KUNDE_ANSCHRIFT__'] = $soc ? $line($soc->address, $soc->zip, $soc->town) : '';
		$values['__ITTAB_KUNDE_UID__'] = $soc ? (string) $soc->tva_intra : '';
		// Austria: professional id 3 is the Firmenbuchnummer, 1 the Steuernummer.
		$values['__ITTAB_KUNDE_FIRMENBUCH__'] = $soc ? (string) $soc->idprof3 : '';
		$values['__ITTAB_KUNDE_STEUERNUMMER__'] = $soc ? (string) $soc->idprof1 : '';
		$values['__ITTAB_KUNDENNR__'] = $soc ? (string) $soc->code_client : '';
		$values['__ITTAB_KUNDE_EMAIL__'] = $soc ? (string) $soc->email : '';
		$values['__ITTAB_KUNDE_TELEFON__'] = $soc ? (string) (!empty($soc->phone_mobile) ? $soc->phone_mobile : $soc->phone) : '';
		$contactName = $contact ? trim($contact->getFullName($langs)) : '';
		$values['__ITTAB_KUNDE_KONTAKT__'] = $contactName;
		$values['__ITTAB_KUNDE_KONTAKT_FUNKTION__'] = $contact ? (string) $contact->poste : '';
		// Who signs for the customer: the contact with function, else the private person.
		$private = $soc && !empty($soc->typent_id) && dol_getIdFromCode($db, (int) $soc->typent_id, 'c_typent', 'id', 'code') === 'TE_PRIVATE';
		$values['__ITTAB_KUNDE_VERTRETUNG__'] = $contactName !== '' ? $contactName.($contact->poste ? ' ('.$contact->poste.')' : '') : ($private ? (string) $soc->name : '');

		$values['__ITTAB_VERTRAG_REF__'] = '';
		$values['__ITTAB_VERTRAG_DATUM__'] = '';
		$values['__ITTAB_VERTRAG_BEGINN__'] = '';
		$values['__ITTAB_VERTRAG_LAUFZEIT__'] = '';
		$values['__ITTAB_VERTRAG_KUENDIGUNG__'] = '';
		$values['__ITTAB_LEISTUNGEN__'] = '';
		if ($contract) {
			if (empty($contract->array_options)) {
				$contract->fetch_optionals();
			}
			if (empty($contract->lines)) {
				$contract->fetch_lines();
			}
			$values['__ITTAB_VERTRAG_REF__'] = (string) $contract->ref;
			$values['__ITTAB_VERTRAG_DATUM__'] = !empty($contract->date_contrat) ? dol_print_date($contract->date_contrat, 'day', 'tzuser', $langs) : '';
			$months = isset($contract->array_options['options_ittab_mindestlaufzeit']) ? (int) $contract->array_options['options_ittab_mindestlaufzeit'] : 0;
			$values['__ITTAB_VERTRAG_LAUFZEIT__'] = $months > 0 ? $langs->transnoentities('WerkbankVarsMonths', $months) : '';
			$values['__ITTAB_VERTRAG_KUENDIGUNG__'] = isset($contract->array_options['options_ittab_kuendigungsfrist']) ? trim((string) $contract->array_options['options_ittab_kuendigungsfrist']) : '';
			$starts = array();
			$items = '';
			foreach ($contract->lines as $row) {
				// Closed lines (5) are no longer part of the service.
				if ((int) $row->statut === 5) {
					continue;
				}
				if (!empty($row->date_start)) {
					$starts[] = (int) $row->date_start;
				}
				$label = trim((string) (!empty($row->product_label) ? $row->product_label : (!empty($row->label) ? $row->label : $row->description)));
				$items .= '<li>'.dol_escape_htmltag(dol_string_nohtmltag($label)).' – '.price((float) $row->subprice, 0, $langs, 1, -1, -1, 'auto')
					.((float) $row->qty !== 1.0 ? ' × '.price((float) $row->qty, 0, $langs, 1, -1, -1) : '').'</li>';
			}
			$values['__ITTAB_VERTRAG_BEGINN__'] = $starts ? dol_print_date(min($starts), 'day', 'tzuser', $langs) : '';
			$values['__ITTAB_LEISTUNGEN__'] = $items !== '' ? '<ul>'.$items.'</ul>' : '';
		}
		return $values;
	}

	/**
	 * Opening hours: the module setting, else Dolibarr's (Setup → Company → Opening hours).
	 *
	 * @return string
	 */
	public static function openingHours()
	{
		global $langs;
		$own = trim(getDolGlobalString('ITTABPDF_GESCHAEFTSZEITEN'));
		if ($own !== '') {
			return $own;
		}
		$days = array('MONDAY' => 'Monday', 'TUESDAY' => 'Tuesday', 'WEDNESDAY' => 'Wednesday', 'THURSDAY' => 'Thursday', 'FRIDAY' => 'Friday',
			'SATURDAY' => 'Saturday', 'SUNDAY' => 'Sunday');
		$parts = array();
		foreach ($days as $const => $day) {
			$hours = trim(getDolGlobalString('MAIN_INFO_OPENINGHOURS_'.$const));
			if ($hours !== '') {
				$parts[] = $langs->transnoentities($day).' '.$hours;
			}
		}
		return implode(', ', $parts);
	}

	/**
	 * Fill a text: lines (paragraphs, list items, table rows, lines between
	 * <br>) whose placeholder is empty disappear, the rest is replaced.
	 * Values are escaped, except __ITTAB_LEISTUNGEN__ and given HTML keys.
	 *
	 * @param string               $html   Text (HTML or plain)
	 * @param array<string,string> $values Placeholder => value
	 * @param string[]             $raw    Placeholders whose value is HTML already
	 * @return string
	 */
	public static function fill($html, array $values, array $raw = array('__ITTAB_LEISTUNGEN__'))
	{
		$empty = array();
		foreach ($values as $key => $value) {
			if (trim(strip_tags((string) $value)) === '') {
				$empty[] = $key;
			}
		}
		if ($empty) {
			$pattern = '/'.implode('|', array_map(function ($key) {
				return preg_quote($key, '/');
			}, $empty)).'/';
			// Whole blocks first, then single lines between <br>.
			$html = preg_replace_callback('#<(p|li|tr|h[1-6]|div)(\s[^>]*)?>.*?</\1>#is', function ($match) use ($pattern) {
				if (!preg_match($pattern, $match[0])) {
					return $match[0];
				}
				$lines = preg_split('#(<br\s*/?>)#i', $match[0]);
				if (count($lines) > 1) {
					$kept = array_filter($lines, function ($line) use ($pattern) {
						return !preg_match($pattern, $line);
					});
					// Keep the block when other lines remain (repair its tags).
					if ($kept) {
						$text = implode('<br>', $kept);
						if (!preg_match('#^<'.preg_quote($match[1], '#').'#i', $text)) {
							$text = '<'.$match[1].'>'.$text;
						}
						if (!preg_match('#</'.preg_quote($match[1], '#').'>$#i', $text)) {
							$text .= '</'.$match[1].'>';
						}
						return $text;
					}
				}
				return '';
			}, (string) $html);
			// Plain text: drop the line.
			if (!dol_textishtml((string) $html)) {
				$html = implode("\n", array_filter(preg_split('/\R/', (string) $html), function ($line) use ($pattern) {
					return !preg_match($pattern, $line);
				}));
			}
		}
		$replace = array();
		foreach ($values as $key => $value) {
			$replace[$key] = in_array($key, $raw, true) ? (string) $value : dol_escape_htmltag((string) $value, 0, 1);
		}
		return strtr((string) $html, $replace);
	}

	/**
	 * Placeholders left in a text after filling.
	 *
	 * @param string $html Text
	 * @return string[]
	 */
	public static function left($html)
	{
		preg_match_all('/__[A-Z][A-Z0-9_]*__/', strip_tags((string) $html), $matches);
		return array_values(array_unique($matches[0]));
	}
}
