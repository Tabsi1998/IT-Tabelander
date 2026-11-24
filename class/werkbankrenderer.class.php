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
 * Draws one document in the Werkbank layout from a plain description.
 *
 * The renderer knows nothing about Dolibarr objects: a builder
 * (class/werkbankbuilder*.class.php) turns a proposal, an invoice, ... into
 * the array described in docs/ARCHITECTURE.md, and this class lays it out on
 * A4: head, fold marks, address, info block, title, table, bottom block
 * with signature lines, free text, footer. Measurements are millimetres, font sizes points.
 */
class WerkbankRenderer
{
	const INK = array(27, 51, 72);
	const BODY = array(59, 77, 99);
	const MUTED = array(74, 91, 110);
	const BRAND = array(254, 90, 35);
	const LINE = array(214, 222, 231);
	const MARK = array(154, 168, 184);
	const CAP_ON_INK = array(197, 208, 220);
	const WHITE = array(255, 255, 255);

	const PAGE_W = 210.0;
	const PAGE_H = 297.0;
	const LEFT = 20.0;
	const RIGHT = 195.0;          // page width minus 15 mm
	const BOTTOM = 273.0;         // nothing but the footer below this line
	const NEXT_PAGE_TOP = 32.0;   // first content line of a follow-up page

	/** @var TCPDF */
	public $pdf;

	/** @var Translate */
	private $langs;

	/** @var array<string,mixed> */
	private $doc;

	/** @var float */
	private $descWidth;

	/**
	 * @param TCPDF     $pdf   A fresh instance from pdf_getInstance()
	 * @param Translate $langs Output language
	 */
	public function __construct($pdf, $langs)
	{
		$this->pdf = $pdf;
		$this->langs = $langs;
		$this->registerFonts();
	}

	/**
	 * The brand fonts shipped in fonts/. Italic styles map to the upright files
	 * so an <i> in a product description cannot stop the PDF (no italic cut).
	 *
	 * @return void
	 */
	private function registerFonts()
	{
		$dir = dol_buildpath('/ittabelanderpdf/fonts/', 0);
		$map = array(
			'itsans' => array('' => 'itsans', 'B' => 'itsansb', 'I' => 'itsans', 'BI' => 'itsansb'),
			'itsanssb' => array('' => 'itsanssb', 'B' => 'itsansb', 'I' => 'itsanssb', 'BI' => 'itsansb'),
			'itsora' => array('' => 'itsora', 'B' => 'itsorab', 'I' => 'itsora', 'BI' => 'itsorab'),
			'itmichroma' => array('' => 'itmichroma', 'B' => 'itmichroma', 'I' => 'itmichroma', 'BI' => 'itmichroma'),
		);
		foreach ($map as $family => $styles) {
			foreach ($styles as $style => $file) {
				$this->pdf->AddFont($family, $style, $dir.$file.'.php');
			}
		}
	}

	/**
	 * Lay out the whole document and write it to $file.
	 *
	 * @param array<string,mixed> $doc  Description built by a WerkbankBuilder
	 * @param string              $file Target path
	 * @return void
	 */
	public function render(array $doc, $file)
	{
		$this->doc = $doc;
		$pdf = $this->pdf;
		$pdf->setAutoPageBreak(false, 0);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetMargins(self::LEFT, 10, self::PAGE_W - self::RIGHT);
		$pdf->setCellPaddings(0, 0, 0, 0);
		$pdf->setCellMargins(0, 0, 0, 0);
		$pdf->setHtmlVSpace(array(
			'p' => array(array('h' => 0, 'n' => 0), array('h' => 0, 'n' => 0)),
			'div' => array(array('h' => 0, 'n' => 0), array('h' => 0, 'n' => 0)),
			'ul' => array(array('h' => 0, 'n' => 0), array('h' => 0, 'n' => 0)),
			'ol' => array(array('h' => 0, 'n' => 0), array('h' => 0, 'n' => 0)),
		));
		$pdf->setListIndentWidth(4);
		$pdf->SetTitle($doc['pdf_title']);
		$pdf->SetSubject($doc['pdf_subject']);
		$pdf->SetCreator('Dolibarr '.DOL_VERSION.' / IT-Tabelander PDF');
		$pdf->SetAuthor($doc['author']);
		$pdf->SetKeyWords($doc['keywords']);
		if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
			$pdf->SetCompression(false);
		}

		$columns = $doc['columns'];
		$fixed = 9.0;
		foreach ($columns as $column) {
			$fixed += $column['w'];
		}
		$this->descWidth = (self::RIGHT - self::LEFT) - $fixed;

		$pdf->AddPage();
		$this->drawFirstHead();
		$y = $this->drawTitle(98.0);
		$y = $this->drawTable($y + 8.0);
		$this->drawBottom($y + 6.0);

		$pages = $pdf->getNumPages();
		for ($page = 1; $page <= $pages; $page++) {
			$pdf->setPage($page);
			if ($page > 1) {
				$this->drawFollowHead();
			}
			$this->drawFoldMarks();
			$this->drawFooter($page, $pages);
			if (!empty($doc['watermark'])) {
				pdf_watermark($pdf, $this->langs, self::PAGE_H, self::PAGE_W, 'mm', $doc['watermark']);
			}
		}
		$pdf->lastPage();
		$pdf->Close();
		$pdf->Output($file, 'F');
	}

	// ------------------------------------------------------------ primitives

	/**
	 * @param string    $family Font family
	 * @param string    $style  '' or 'B'
	 * @param float     $size   Points
	 * @param int[]     $color  RGB
	 * @param float     $ratio  Line height as a multiple of the font size
	 * @param float     $spacing Letter spacing in mm
	 * @return void
	 */
	private function font($family, $style, $size, $color, $ratio = 1.4, $spacing = 0.0)
	{
		$this->pdf->SetFont($family, $style, $size);
		$this->pdf->SetTextColor($color[0], $color[1], $color[2]);
		$this->pdf->setCellHeightRatio($ratio);
		$this->pdf->setFontSpacing($spacing);
	}

	/**
	 * Height of one text line in mm for a size in points.
	 *
	 * @param float $size  Points
	 * @param float $ratio Line height ratio
	 * @return float
	 */
	private static function lineHeight($size, $ratio)
	{
		return $size * 25.4 / 72 * $ratio;
	}

	/**
	 * Plain text in a box; returns the y below it.
	 *
	 * @param float  $x     Left
	 * @param float  $y     Top
	 * @param float  $w     Width
	 * @param string $text  Text, "\n" breaks lines
	 * @param string $align L, R or C
	 * @return float
	 */
	private function text($x, $y, $w, $text, $align = 'L')
	{
		$this->pdf->MultiCell($w, 0, $text, 0, $align, false, 1, $x, $y, true, 0, false, true, 0, 'T');
		return $this->pdf->GetY();
	}

	/**
	 * HTML (descriptions, notes, free text) in a box; returns the y below it.
	 *
	 * @param float  $x    Left
	 * @param float  $y    Top
	 * @param float  $w    Width
	 * @param string $html HTML
	 * @return float
	 */
	private function html($x, $y, $w, $html)
	{
		$this->pdf->writeHTMLCell($w, 0, $x, $y, $html, 0, 1, false, true, 'L', true);
		return $this->pdf->GetY();
	}

	/**
	 * A caption: Michroma, upper case, spaced.
	 *
	 * @param float   $x     Left
	 * @param float   $y     Top
	 * @param float   $w     Width
	 * @param string  $label Label
	 * @param string  $align Alignment
	 * @param int[]   $color RGB
	 * @return float
	 */
	private function caption($x, $y, $w, $label, $align = 'L', $color = self::MUTED)
	{
		$this->font('itmichroma', '', 5.6, $color, 1.3, 0.28);
		$bottom = $this->text($x, $y, $w, dol_strtoupper($label), $align);
		$this->pdf->setFontSpacing(0);
		return $bottom;
	}

	/**
	 * @param float $x1    Start x
	 * @param float $y     Y
	 * @param float $x2    End x
	 * @param float $width Line width in mm
	 * @param int[] $color RGB
	 * @return void
	 */
	private function hline($x1, $y, $x2, $width, $color)
	{
		$this->pdf->Line($x1, $y, $x2, $y, array('width' => $width, 'color' => $color, 'dash' => 0, 'cap' => 'butt'));
	}

	// ------------------------------------------------------------ page furniture

	/**
	 * Logo, contact lines, brand rule, sender line, address and info block.
	 *
	 * @return void
	 */
	private function drawFirstHead()
	{
		$doc = $this->doc;
		$this->drawLogo(12.0, 12.0);

		$lines = array_values(array_filter($doc['head_lines'], 'strlen'));
		$y = 12.0;
		foreach ($lines as $index => $line) {
			if ($index === 0) {
				$this->font('itsanssb', '', 8, self::INK, 1.45);
			} else {
				$this->font('itsans', '', 8, self::MUTED, 1.45);
			}
			$y = $this->text(105, $y, self::RIGHT - 105, $line, 'R');
		}

		$this->drawRule(30.0);

		// Address field (DIN 5008, type B), sender line with a hairline below.
		$this->font('itsans', '', 6.5, self::MUTED, 1.3);
		$y = $this->text(self::LEFT, 45.0, 85, $doc['sender_line']);
		$this->hline(self::LEFT, $y + 1.0, self::LEFT + 85, 0.088, self::LINE);
		$y += 4.0;
		$this->font('itsanssb', '', 10.5, self::INK, 1.35);
		$y = $this->text(self::LEFT, $y, 85, $doc['recipient_name']);
		$this->font('itsans', '', 10.5, self::INK, 1.35);
		if ($doc['recipient_address'] !== '') {
			$this->text(self::LEFT, $y, 85, $doc['recipient_address']);
		}

		// Info block: label left, value right.
		$y = 50.0;
		foreach ($doc['info'] as $row) {
			$this->caption(125, $y + 1.0, 40, $row[0]);
			$this->font('itsanssb', '', 9.5, self::INK, 1.3);
			$y = $this->text(150, $y, self::RIGHT - 150, $row[1], 'R') + 1.6;
		}
	}

	/**
	 * Follow-up pages: small logo, document name and number, the rule.
	 *
	 * @return void
	 */
	private function drawFollowHead()
	{
		$this->drawLogo(12.0, 7.0);
		$this->font('itsanssb', '', 8, self::INK, 1.3);
		$this->text(105, 13.5, self::RIGHT - 105, $this->doc['short_title'], 'R');
		$this->drawRule(23.0);
	}

	/**
	 * @param float $y      Top
	 * @param float $height Logo height
	 * @return void
	 */
	private function drawLogo($y, $height)
	{
		$logo = $this->doc['logo'];
		if ($logo !== '' && is_readable($logo)) {
			$this->pdf->Image($logo, self::LEFT, $y, 0, $height, '', '', '', true, 300);
			return;
		}
		$this->font('itsora', 'B', $height > 10 ? 16 : 11, self::INK, 1.1);
		$this->text(self::LEFT, $y + 1, 80, $this->doc['company_name']);
	}

	/**
	 * The 0.6 mm rule: ink across the page, the first 28 mm orange.
	 *
	 * @param float $y Top of the rule
	 * @return void
	 */
	private function drawRule($y)
	{
		$this->pdf->Rect(self::LEFT, $y, self::RIGHT - self::LEFT, 0.6, 'F', array(), self::INK);
		$this->pdf->Rect(self::LEFT, $y, 28, 0.6, 'F', array(), self::BRAND);
	}

	/**
	 * Fold and punch marks on the left edge.
	 *
	 * @return void
	 */
	private function drawFoldMarks()
	{
		foreach (array(105.0 => 4.0, 148.5 => 6.0, 210.0 => 4.0) as $y => $length) {
			$this->hline(0, $y, $length, 0.088, self::MARK);
		}
	}

	/**
	 * Hairline and four columns 9 mm above the bottom edge.
	 *
	 * @param int $page  Page number
	 * @param int $pages Page count
	 * @return void
	 */
	private function drawFooter($page, $pages)
	{
		$columns = $this->doc['footer'];
		$lineHeight = self::lineHeight(6.6, 1.45);
		$rows = 1;
		foreach ($columns as $column) {
			$rows = max($rows, count($column));
		}
		$top = self::PAGE_H - 9.0 - $rows * $lineHeight;
		$this->hline(self::LEFT, $top - 2.5, self::RIGHT, 0.088, self::LINE);
		$widths = array(46.0, 42.0, 62.0);
		$x = self::LEFT;
		foreach ($columns as $index => $column) {
			$y = $top;
			foreach ($column as $row => $line) {
				$bold = ($index === 0 && $row === 0);
				$this->font($bold ? 'itsanssb' : 'itsans', '', 6.6, $bold ? self::INK : self::MUTED, 1.45);
				$y = $this->text($x, $y, $widths[$index] - 2, $line);
			}
			$x += $widths[$index] + 2.5;
		}
		$this->font('itsans', '', 6.6, self::MUTED, 1.45);
		$this->text(self::RIGHT - 25, $top, 25, $this->langs->transnoentities('WerkbankPageOf', $page, $pages), 'R');
	}

	// ------------------------------------------------------------ flowing part

	/**
	 * "Angebot." in Sora, the orange point, the optional subtitle, the intro.
	 *
	 * @param float $y Top
	 * @return float   Y below
	 */
	private function drawTitle($y)
	{
		$pdf = $this->pdf;
		$doc = $this->doc;
		$this->font('itsora', 'B', 20, self::INK, 1.15, -0.14);
		$pdf->SetXY(self::LEFT, $y);
		$height = self::lineHeight(20, 1.15);
		$pdf->Write($height, $doc['title']);
		$pdf->SetTextColor(self::BRAND[0], self::BRAND[1], self::BRAND[2]);
		$pdf->Write($height, '.');
		if ($doc['subtitle'] !== '') {
			$pdf->SetTextColor(self::INK[0], self::INK[1], self::INK[2]);
			$pdf->Write($height, ' '.$doc['subtitle']);
		}
		$pdf->setFontSpacing(0);
		$y = $pdf->GetY() + $height;
		if ($doc['intro'] !== '') {
			$this->font('itsans', '', 9.5, self::BODY, 1.4);
			$y = $this->html(self::LEFT, $y + 2.5, 150, $doc['intro']);
		}
		return $y;
	}

	/**
	 * Column captions with the ink line below.
	 *
	 * @param float $y Top
	 * @return float   Y of the first row
	 */
	private function drawTableHead($y)
	{
		$this->caption(self::LEFT, $y, 9, $this->langs->transnoentities('WerkbankColPos'));
		$this->caption(self::LEFT + 9, $y, $this->descWidth, $this->doc['desc_title']);
		$x = self::LEFT + 9 + $this->descWidth;
		foreach ($this->doc['columns'] as $column) {
			$this->caption($x, $y, $column['w'], $column['title'], 'R');
			$x += $column['w'];
		}
		$bottom = $y + 2.4 + 2.0;
		$this->hline(self::LEFT, $bottom, self::RIGHT, 0.212, self::INK);
		return $bottom;
	}

	/**
	 * All rows; a row that does not fit goes to a new page with the head repeated.
	 *
	 * @param float $y Top of the table
	 * @return float   Y below the last row
	 */
	private function drawTable($y)
	{
		$pdf = $this->pdf;
		if (empty($this->doc['rows'])) {
			return $y;
		}
		// Head and at least one row stay together.
		if ($y > self::BOTTOM - 30) {
			$y = $this->newPage();
		}
		$y = $this->drawTableHead($y);
		foreach ($this->doc['rows'] as $index => $row) {
			$pdf->startTransaction();
			$bottom = $this->drawRow($y, $index + 1, $row);
			if ($bottom > self::BOTTOM) {
				$pdf->rollbackTransaction(true);
				$y = $this->drawTableHead($this->newPage());
				$bottom = $this->drawRow($y, $index + 1, $row);
			} else {
				$pdf->commitTransaction();
			}
			$y = $bottom;
		}
		return $y;
	}

	/**
	 * @return float Top of the content on the new page
	 */
	private function newPage()
	{
		$this->pdf->AddPage();
		return self::NEXT_PAGE_TOP;
	}

	/**
	 * One table row with its hairline; returns its bottom.
	 *
	 * @param float               $y   Top
	 * @param int                 $pos Position number
	 * @param array<string,mixed> $row Row description
	 * @return float
	 */
	private function drawRow($y, $pos, array $row)
	{
		$top = $y + 2.4;
		$x = self::LEFT + 9;
		$w = $this->descWidth;

		$this->font('itsans', '', 8, self::MUTED, 1.3);
		$this->text(self::LEFT, $top + 0.3, 9, (string) $pos);

		$cursor = $top;
		if ($row['ref'] !== '') {
			$this->font('itmichroma', '', 5.4, self::BRAND, 1.2, 0.19);
			$cursor = $this->text($x, $cursor, $w - 4, $row['ref']) + 0.6;
			$this->pdf->setFontSpacing(0);
		}
		if ($row['label'] !== '') {
			$this->font('itsanssb', '', 9.8, self::INK, 1.3);
			$cursor = $this->text($x, $cursor, $w - 4, $row['label']);
		}
		if ($row['desc'] !== '') {
			$this->font('itsans', '', 8.3, $row['label'] === '' ? self::INK : self::MUTED, 1.35);
			$cursor = $this->html($x, $cursor + ($row['label'] === '' ? 0 : 0.5), $w - 6, $row['desc']);
		}
		if ($row['meta'] !== '') {
			$this->font('itsans', '', 7.6, self::MUTED, 1.35);
			$cursor = $this->text($x, $cursor + 0.8, $w - 4, $row['meta']);
		}

		$cx = $x + $w;
		$numbers = $top;
		foreach ($this->doc['columns'] as $column) {
			$cell = isset($row['cells'][$column['key']]) ? $row['cells'][$column['key']] : null;
			if ($cell !== null) {
				$numbers = max($numbers, $this->drawCell($cx, $top, $column['w'], $cell));
			}
			$cx += $column['w'];
		}

		$bottom = max($cursor, $numbers) + 2.4;
		$this->hline(self::LEFT, $bottom, self::RIGHT, 0.088, self::LINE);
		return $bottom;
	}

	/**
	 * A right aligned number with an optional small unit and a note below.
	 *
	 * @param float               $x    Left of the column
	 * @param float               $y    Top
	 * @param float               $w    Width
	 * @param array<string,mixed> $cell text, unit, note, bold
	 * @return float                    Y below
	 */
	private function drawCell($x, $y, $w, array $cell)
	{
		$pdf = $this->pdf;
		$unit = isset($cell['unit']) ? (string) $cell['unit'] : '';
		$unitWidth = 0.0;
		if ($unit !== '') {
			$this->font('itsans', '', 8, self::MUTED, 1.3);
			$unitWidth = $pdf->GetStringWidth($unit) + 1.0;
			$this->text($x, $y + 0.4, $w, $unit, 'R');
		}
		$bold = !empty($cell['bold']);
		$this->font($bold ? 'itsanssb' : 'itsans', '', 9.5, $bold ? self::INK : self::BODY, 1.3);
		$bottom = $this->text($x, $y, $w - $unitWidth, (string) $cell['text'], 'R');
		if (!empty($cell['note'])) {
			$this->font('itsans', '', 7.3, self::BRAND, 1.3);
			$bottom = $this->text($x, $bottom, $w, $cell['note'], 'R');
		}
		return $bottom;
	}

	/**
	 * Payment/terms left, totals and signature right - one block that is never
	 * split - then the free text, which may move to a page of its own.
	 *
	 * @param float $y Top
	 * @return void
	 */
	private function drawBottom($y)
	{
		$pdf = $this->pdf;
		$pdf->startTransaction();
		$bottom = $this->drawBottomBlock($y);
		if ($bottom > self::BOTTOM) {
			$pdf->rollbackTransaction(true);
			$bottom = $this->drawBottomBlock($this->newPage());
		} else {
			$pdf->commitTransaction();
		}
		if ($this->doc['freetext'] === '') {
			return;
		}
		$pdf->startTransaction();
		$this->font('itsans', '', 6.8, self::MUTED, 1.4);
		$end = $this->html(self::LEFT, $bottom + 6.0, self::RIGHT - self::LEFT, $this->doc['freetext']);
		if ($end > self::BOTTOM) {
			$pdf->rollbackTransaction(true);
			$this->font('itsans', '', 6.8, self::MUTED, 1.4);
			$this->html(self::LEFT, $this->newPage(), self::RIGHT - self::LEFT, $this->doc['freetext']);
		} else {
			$pdf->commitTransaction();
		}
	}

	/**
	 * @param float $y Top
	 * @return float   Y below
	 */
	private function drawBottomBlock($y)
	{
		$doc = $this->doc;
		$left = $y;
		foreach ($doc['left'] as $index => $block) {
			$left = $this->caption(self::LEFT, $left + ($index ? 3.0 : 0.0), 75, $block['cap']) + 1.0;
			$this->font('itsans', '', 8.3, self::BODY, 1.4);
			$left = $this->text(self::LEFT, $left, 75, $block['text']);
		}
		if (!empty($doc['qr'])) {
			$size = 22.0;
			$this->pdf->write2DBarcode($doc['qr']['payload'], 'QRCODE,M', self::LEFT, $left + 3.0, $size, $size,
				array('fgcolor' => self::INK, 'bgcolor' => false, 'padding' => 0), 'N');
			$this->font('itsans', '', 7.6, self::BODY, 1.4);
			$this->text(self::LEFT + $size + 4.0, $left + 3.0 + $size / 2 - 5.0, 75 - $size - 4.0, $doc['qr']['text']);
			$left += 3.0 + $size;
		}

		$right = $y;
		if (!empty($doc['totals'])) {
			$right = $this->drawTotals($y, $doc['totals']);
		}
		if (isset($doc['signatures']['left'])) {
			$left = $this->drawSignature(self::LEFT, $left + ($left > $y ? 6.0 : 10.0), $doc['signatures']['left'], false);
		}
		if (isset($doc['signatures']['right'])) {
			$right = $this->drawSignature(self::RIGHT - 75.0, $right + ($right > $y ? 6.0 : 10.0), $doc['signatures']['right'], true);
		}
		return max($left, $right);
	}

	/**
	 * Sum rows, the navy box and the tax notice in the right column.
	 *
	 * @param float               $y      Top
	 * @param array<string,mixed> $totals rows, grand_label, grand, notice
	 * @return float                      Y below
	 */
	private function drawTotals($y, array $totals)
	{
		$x = self::RIGHT - 90.0;
		foreach ($totals['rows'] as $row) {
			$this->font('itsans', '', 8.6, self::BODY, 1.4);
			$this->text($x + 4, $y, 50, $row[0]);
			$this->text($x + 40, $y, 46, $row[1], 'R');
			$y += self::lineHeight(8.6, 1.4) + 0.4;
		}
		if (!empty($totals['rows'])) {
			$y += 1.2;
		}
		$box = 11.6;
		$this->pdf->RoundedRect($x, $y, 90.0, $box, 0.8, '1111', 'F', array(), self::INK);
		$this->caption($x + 4, $y + 4.6, 50, $totals['grand_label'], 'L', self::CAP_ON_INK);
		$this->font('itsora', 'B', 15, self::WHITE, 1.1, -0.05);
		$this->text($x + 30, $y + 2.6, 56, $totals['grand'], 'R');
		$this->pdf->setFontSpacing(0);
		$y += $box;
		if ($totals['notice'] !== '') {
			$this->font('itsans', '', 7.3, self::MUTED, 1.35);
			$y = $this->text($x, $y + 1.8, 90.0, $totals['notice'], 'R');
		}
		return $y;
	}

	/**
	 * A slim signature line with its caption. The customer's line carries the
	 * empty signature field of MAIN_PDF_PROPAL_USE_ELECTRONIC_SIGNING (as
	 * pdf_cyan adds it) and its page number goes into the keywords as
	 * PAGESIGN=n, where Dolibarr's online signature looks for it.
	 *
	 * @param float  $x        Left
	 * @param float  $y        Line position
	 * @param string $label    Caption
	 * @param bool   $customer The customer's signature
	 * @return float           Y below the caption
	 */
	private function drawSignature($x, $y, $label, $customer)
	{
		$pdf = $this->pdf;
		$this->hline($x, $y, $x + 75.0, 0.18, self::INK);
		$bottom = $this->caption($x, $y + 1.4, 75.0, $label);
		if ($customer && !empty($this->doc['esign'])) {
			$pdf->addEmptySignatureAppearance($x, $y - 12.0, 75.0, 12.0);
		}
		if ($customer && !empty($this->doc['pagesign'])) {
			$pdf->SetKeyWords($this->doc['keywords'].' PAGESIGN='.$pdf->getPage());
		}
		return $bottom;
	}
}
