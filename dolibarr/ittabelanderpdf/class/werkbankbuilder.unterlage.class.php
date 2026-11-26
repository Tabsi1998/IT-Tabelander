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

require_once __DIR__.'/werkbankbuilder.class.php';
require_once __DIR__.'/werkbankunterlagen.class.php';

/**
 * A knowledge base article as a company document: head, title, "Stand",
 * the article's HTML set in the brand fonts, footer with page numbers.
 */
class WerkbankBuilderUnterlage extends WerkbankBuilder
{
	/** Colours of the HTML, as in WerkbankRenderer. */
	const INK = '#1B3348';
	const BODY = '#3B4D63';
	const MUTED = '#4A5B6E';
	const LINE = '#D6DEE7';

	/** @var int|null */
	private $stand;

	/**
	 * @param DoliDB          $db     Database
	 * @param KnowledgeRecord $object Article
	 * @param Translate       $langs  Output language
	 * @param int|null        $stand  "Stand" date, null = last change of the article
	 */
	public function __construct($db, $object, $langs, $stand = null)
	{
		parent::__construct($db, $object, $langs);
		$this->stand = $stand;
	}

	/**
	 * @return string
	 */
	public function outputFile()
	{
		return WerkbankUnterlagen::dir().'/'.WerkbankUnterlagen::slugOf($this->object).'.pdf';
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build()
	{
		$record = $this->object;
		$langs = $this->langs;
		$title = trim((string) $record->question);
		$doc = $this->sheet($title, $title);
		$doc['meta'] = $langs->transnoentities('WerkbankDocsStand', dol_print_date($this->standDate(), 'day', false, $langs, true));
		$doc['keywords'] = $title.' '.WerkbankUnterlagen::slugOf($record);
		$doc['blocks'] = array(array('type' => 'html', 'html' => self::html((string) $record->answer)));
		return $doc;
	}

	/**
	 * The date printed as "Stand": given, else the later of the last change
	 * and the release of the article.
	 *
	 * @return int
	 */
	private function standDate()
	{
		if ($this->stand) {
			return (int) $this->stand;
		}
		$dates = array();
		foreach (array('tms', 'date_validation', 'date_creation') as $field) {
			if (!empty($this->object->$field)) {
				$dates[] = is_numeric($this->object->$field) ? (int) $this->object->$field : (int) $this->db->jdate($this->object->$field);
			}
		}
		return $dates ? max($dates) : dol_now();
	}

	/**
	 * The article's HTML reduced to what a document needs (headings,
	 * paragraphs, lists, emphasis, links, simple tables), every tag with the
	 * inline style of the Werkbank layout. Classes, scripts, styles and
	 * images are dropped; text always stays.
	 *
	 * @param string $html HTML from the editor (or plain text)
	 * @return string
	 */
	public static function html($html)
	{
		$html = trim($html);
		if ($html === '') {
			return '';
		}
		if (!dol_textishtml($html)) {
			$html = '<p>'.implode('</p><p>', array_map(function ($part) {
				return htmlspecialchars($part, ENT_NOQUOTES, 'UTF-8');
			}, preg_split('/\n\s*\n/', $html))).'</p>';
			$html = str_replace("\n", '<br>', $html);
		}
		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$body = $dom->getElementsByTagName('body')->item(0);
		return $body ? self::children($body) : '';
	}

	/**
	 * @param DOMNode $node Parent
	 * @return string       HTML of its children
	 */
	private static function children($node)
	{
		$out = '';
		foreach ($node->childNodes as $child) {
			$out .= self::node($child);
		}
		return $out;
	}

	/**
	 * @param DOMNode $node One node
	 * @return string
	 */
	private static function node($node)
	{
		if ($node instanceof DOMText) {
			return htmlspecialchars(str_replace("\xC2\xA0", ' ', $node->nodeValue), ENT_NOQUOTES, 'UTF-8');
		}
		if (!($node instanceof DOMElement)) {
			return '';
		}
		$tag = strtolower($node->tagName);
		if (in_array($tag, array('script', 'style', 'img', 'svg', 'iframe', 'object', 'head', 'title'), true)) {
			return '';
		}
		$inner = self::children($node);
		$heading = 'font-family:itsora;font-weight:bold;color:'.self::INK.';';
		switch ($tag) {
			case 'h1':
			case 'h2':
				return '<h2 style="'.$heading.'font-size:13pt;">'.$inner.'</h2>';
			case 'h3':
				return '<h3 style="'.$heading.'font-size:11pt;">'.$inner.'</h3>';
			case 'h4':
			case 'h5':
			case 'h6':
				return '<h4 style="font-family:itsanssb;color:'.self::INK.';font-size:9.8pt;">'.$inner.'</h4>';
			case 'p':
				return trim(strip_tags($inner)) === '' ? '' : '<p>'.$inner.'</p>';
			case 'div':
			case 'section':
			case 'article':
				return $inner === '' ? '' : '<div>'.$inner.'</div>';
			case 'br':
				return '<br>';
			case 'ul':
			case 'ol':
				return '<'.$tag.'>'.$inner.'</'.$tag.'>';
			case 'li':
				return '<li>'.$inner.'</li>';
			case 'strong':
			case 'b':
				return '<b style="font-family:itsanssb;color:'.self::INK.';">'.$inner.'</b>';
			case 'em':
			case 'i':
			case 'u':
				return '<'.$tag.'>'.$inner.'</'.$tag.'>';
			case 'a':
				$href = trim((string) $node->getAttribute('href'));
				if (!preg_match('#^(https?://|mailto:|tel:)#i', $href)) {
					return $inner;
				}
				return '<a href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'" style="color:'.self::INK.';text-decoration:underline;">'.$inner.'</a>';
			case 'hr':
				return '<hr style="color:'.self::LINE.';">';
			case 'blockquote':
				return '<p style="color:'.self::MUTED.';">'.$inner.'</p>';
			case 'table':
				return '<table cellpadding="3" border="0" style="border-top:0.25pt solid '.self::LINE.';">'.$inner.'</table>';
			case 'thead':
			case 'tbody':
			case 'tfoot':
				return $inner;
			case 'tr':
				return '<tr>'.$inner.'</tr>';
			case 'th':
				return '<th style="font-family:itsanssb;color:'.self::INK.';border-bottom:0.25pt solid '.self::LINE.';">'.$inner.'</th>';
			case 'td':
				return '<td style="border-bottom:0.25pt solid '.self::LINE.';">'.$inner.'</td>';
			default:
				return $inner;
		}
	}
}
