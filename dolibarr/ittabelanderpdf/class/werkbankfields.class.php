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
 * Input fields a company document asks for before it is generated, written
 * into the article where the answer goes:
 *
 *   [[FELD:zweck*|Zweck der Verarbeitung|Textfeld|Betrieb und Wartung der Website]]
 *   [[FELD:datenkategorien|Datenkategorien|Auswahl|+Kundendaten;+Kontaktdaten;Mitarbeiterdaten]]
 *
 * name (letters, digits, _), "*" = required, label, type (Text, Textfeld,
 * Auswahl = several, Einfach = one, Datum, Zahl), defaults (Text: the
 * value; Auswahl/Einfach: options separated by ";", "+" = preselected).
 * Plain text with brackets only, so CKEditor keeps it and Dolibarr's WAF
 * lets it pass.
 */
class WerkbankFields
{
	/** Types in the article (lower case) => internal kind. */
	const TYPES = array('text' => 'text', 'textfeld' => 'textarea', 'auswahl' => 'multi', 'einfach' => 'single', 'datum' => 'date', 'zahl' => 'number');

	const PATTERN = '/\[\[FELD:([A-Za-z0-9_]+)(\*?)\|([^|\]]*)\|([^|\]]*)(?:\|([^\]]*))?\]\]/u';

	/**
	 * The fields of a text, in order, each once.
	 *
	 * @param string $html Article text
	 * @return array<string,array{name:string,required:bool,label:string,kind:string,options:string[],selected:string[],default:string}>
	 */
	public static function parse($html)
	{
		$fields = array();
		if (!preg_match_all(self::PATTERN, (string) $html, $matches, PREG_SET_ORDER)) {
			return $fields;
		}
		foreach ($matches as $match) {
			// Only the marker's own text is decoded (CKEditor writes &amp; and &nbsp;).
			$match = array_map(function ($part) {
				return str_replace("Â ", ' ', html_entity_decode((string) $part, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			}, $match);
			$name = strtolower($match[1]);
			if (isset($fields[$name])) {
				continue;
			}
			$type = strtolower(trim($match[4]));
			$kind = isset(self::TYPES[$type]) ? self::TYPES[$type] : 'text';
			$defaults = isset($match[5]) ? trim($match[5]) : '';
			$options = array();
			$selected = array();
			if ($kind === 'multi' || $kind === 'single') {
				foreach (array_filter(array_map('trim', explode(';', $defaults)), 'strlen') as $option) {
					$plain = ltrim($option, '+ ');
					$options[] = $plain;
					if ($option[0] === '+') {
						$selected[] = $plain;
					}
				}
			}
			$fields[$name] = array('name' => $name, 'required' => $match[2] === '*', 'label' => trim($match[3]) !== '' ? trim($match[3]) : $name,
				'kind' => $kind, 'options' => $options, 'selected' => $selected, 'default' => $options ? '' : $defaults);
		}
		return $fields;
	}

	/**
	 * Values posted by the form (field_<name>), defaults for those not posted.
	 *
	 * @param array<string,array<string,mixed>> $fields From parse()
	 * @return array<string,string|string[]>
	 */
	public static function posted(array $fields)
	{
		$values = array();
		foreach ($fields as $name => $field) {
			if ($field['kind'] === 'multi') {
				$chosen = GETPOST('field_'.$name, 'array:alphanohtml');
				$values[$name] = array_values(array_intersect($field['options'], array_map('trim', $chosen)));
				$extra = trim(GETPOST('field_'.$name.'_more', 'alphanohtml'));
				if ($extra !== '') {
					$values[$name] = array_merge($values[$name], array_filter(array_map('trim', explode(';', $extra)), 'strlen'));
				}
			} else {
				$values[$name] = trim(GETPOST('field_'.$name, $field['kind'] === 'textarea' ? 'restricthtml' : 'alphanohtml'));
				if ($field['kind'] === 'textarea') {
					$values[$name] = trim(dol_string_nohtmltag($values[$name], 0));
				}
			}
		}
		return $values;
	}

	/**
	 * Labels of required fields without a value.
	 *
	 * @param array<string,array<string,mixed>> $fields From parse()
	 * @param array<string,mixed>               $values Values
	 * @return string[]
	 */
	public static function missing(array $fields, array $values)
	{
		$missing = array();
		foreach ($fields as $name => $field) {
			$value = isset($values[$name]) ? $values[$name] : '';
			if ($field['required'] && (is_array($value) ? !$value : trim((string) $value) === '')) {
				$missing[] = $field['label'];
			}
		}
		return $missing;
	}

	/**
	 * Put the answers where the fields stand; an empty optional answer takes
	 * its line with it (as an empty placeholder does).
	 *
	 * @param string                            $html   Article text
	 * @param array<string,array<string,mixed>> $fields From parse()
	 * @param array<string,mixed>               $values Values
	 * @return string
	 */
	public static function apply($html, array $fields, array $values)
	{
		global $langs;
		return preg_replace_callback(self::PATTERN, function ($match) use ($fields, $values, $langs) {
			$name = strtolower($match[1]);
			$field = isset($fields[$name]) ? $fields[$name] : null;
			$value = isset($values[$name]) ? $values[$name] : '';
			if (!$field) {
				return '';
			}
			if (is_array($value)) {
				return $value ? dol_escape_htmltag(implode(', ', $value)) : '__ITTAB_FELD_LEER__';
			}
			$value = trim((string) $value);
			if ($value === '') {
				return '__ITTAB_FELD_LEER__';
			}
			if ($field['kind'] === 'date') {
				$time = dol_stringtotime($value.' 00:00:00');
				return $time ? dol_print_date($time, 'day', false, $langs) : dol_escape_htmltag($value);
			}
			return $field['kind'] === 'textarea' ? nl2br(dol_escape_htmltag($value, 0, 1)) : dol_escape_htmltag($value);
		}, (string) $html);
	}

	/**
	 * The form inputs for the fields, phone-friendly, with the values given.
	 *
	 * @param array<string,array<string,mixed>> $fields From parse()
	 * @param array<string,mixed>               $values Values (last time or posted)
	 * @return string
	 */
	public static function inputs(array $fields, array $values)
	{
		global $langs;
		$out = '';
		foreach ($fields as $name => $field) {
			$id = 'field-'.$name;
			$value = array_key_exists($name, $values) ? $values[$name] : ($field['kind'] === 'multi' ? $field['selected'] : ($field['kind'] === 'single' ? (string) reset($field['selected']) : $field['default']));
			$label = dol_escape_htmltag($field['label']).($field['required'] ? ' <span class="ittabqs-required">'.$langs->trans('WerkbankQsRequired').'</span>' : '');
			$out .= '<div class="ittabgen-field">';
			if ($field['kind'] === 'multi' || $field['kind'] === 'single') {
				$out .= '<fieldset><legend class="ittabqs-label">'.$label.'</legend>';
				$extra = array_diff(is_array($value) ? $value : array(), $field['options']);
				foreach ($field['options'] as $index => $option) {
					$on = is_array($value) ? in_array($option, $value, true) : $value === $option;
					$type = $field['kind'] === 'multi' ? 'checkbox' : 'radio';
					$out .= '<label class="ittabgen-option"><input type="'.$type.'" name="field_'.$name.($type === 'checkbox' ? '[]' : '').'" value="'.dol_escape_htmltag($option).'"'.($on ? ' checked' : '').'>'
						.'<span>'.dol_escape_htmltag($option).'</span></label>';
				}
				if ($field['kind'] === 'multi') {
					$out .= '<input type="text" name="field_'.$name.'_more" class="ittabqs-input" placeholder="'.dol_escape_htmltag($langs->trans('WerkbankGenMore')).'" value="'.dol_escape_htmltag(implode('; ', $extra)).'">';
				}
				$out .= '</fieldset>';
			} else {
				$out .= '<label class="ittabqs-label" for="'.$id.'">'.$label.'</label>';
				if ($field['kind'] === 'textarea') {
					$out .= '<textarea id="'.$id.'" name="field_'.$name.'" rows="3" class="ittabqs-input">'.dol_escape_htmltag((string) $value, 0, 1).'</textarea>';
				} else {
					$type = $field['kind'] === 'date' ? 'date' : ($field['kind'] === 'number' ? 'text" inputmode="decimal' : 'text');
					$out .= '<input type="'.$type.'" id="'.$id.'" name="field_'.$name.'" class="ittabqs-input" value="'.dol_escape_htmltag((string) $value).'">';
				}
			}
			$out .= '</div>';
		}
		return $out;
	}
}
