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
 * The price list from Dolibarr's products and services: one section per
 * product category chosen in the setup (in that order), only what is for
 * sale, without 0 € template lines. Placeholder __ITTAB_PREISLISTE__.
 *
 * Settings: ITTABPDF_PRICELIST_CATEGORIES (ids, comma separated; sections
 * in the order of the category tree), ITTABPDF_PRICELIST_OTHERS (section for products without one of
 * these categories), ITTABPDF_PRICELIST_DESC (descriptions under the label),
 * ITTABPDF_PRICELIST_AUTO (write the PDFs again when a product changes).
 */
class WerkbankPricelist
{
	const PLACEHOLDER = '__ITTAB_PREISLISTE__';

	/**
	 * Product categories, in tree order: id => label (with parents).
	 *
	 * @param DoliDB $db Database
	 * @return array<int,string>
	 */
	public static function categories($db)
	{
		if (!isModEnabled('categorie')) {
			return array();
		}
		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		$tag = new Categorie($db);
		$list = array();
		foreach ((array) $tag->get_full_arbo(Categorie::TYPE_PRODUCT) as $row) {
			$list[(int) $row['id']] = (string) $row['fulllabel'];
		}
		return $list;
	}

	/**
	 * Category ids of the sections, in order.
	 *
	 * @return int[]
	 */
	public static function chosen()
	{
		return array_values(array_unique(array_filter(array_map('intval', explode(',', getDolGlobalString('ITTABPDF_PRICELIST_CATEGORIES'))))));
	}

	/**
	 * The price list as HTML: <h3> per section, a list of "Label: price per unit".
	 *
	 * @param DoliDB $db Database
	 * @return string    '' when nothing is for sale
	 */
	public static function html($db)
	{
		global $langs, $mysoc, $conf;
		$langs->loadLangs(array('main', 'products', 'ittabelanderpdf@ittabelanderpdf'));
		$names = self::categories($db);
		// Sections in the order of the category tree.
		$chosen = array_values(array_intersect(array_keys($names), self::chosen()));
		$withVat = !empty($mysoc->tva_assuj);
		$sql = "SELECT p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc, p.fk_unit, p.duration, u.code AS unit_code, u.label AS unit_label"
			." FROM ".MAIN_DB_PREFIX."product AS p LEFT JOIN ".MAIN_DB_PREFIX."c_units AS u ON u.rowid = p.fk_unit"
			." WHERE p.entity IN (".getEntity('product').") AND p.tosell = 1 AND p.price > 0"
			." ORDER BY p.ref";
		$resql = $db->query($sql);
		$products = array();
		while ($resql && ($row = $db->fetch_object($resql))) {
			$products[(int) $row->rowid] = $row;
		}
		$links = array();
		if ($products && $chosen) {
			$resql = $db->query("SELECT fk_categorie, fk_product FROM ".MAIN_DB_PREFIX."categorie_product WHERE fk_categorie IN (".implode(',', $chosen).")");
			while ($resql && ($row = $db->fetch_object($resql))) {
				$links[(int) $row->fk_categorie][] = (int) $row->fk_product;
			}
		}
		$line = function ($row) use ($langs, $withVat) {
			$amount = $withVat ? (float) $row->price_ttc : (float) $row->price;
			$text = '<strong>'.dol_escape_htmltag(trim((string) $row->label)).'</strong>: '.price($amount, 0, $langs, 1, -1, -1, 'auto').self::per($row, $langs);
			$description = trim(dol_string_nohtmltag((string) $row->description, 1));
			if (getDolGlobalInt('ITTABPDF_PRICELIST_DESC') && $description !== '') {
				$text .= '<br>'.dol_escape_htmltag($description, 0, 1);
			}
			return '<li>'.$text.'</li>';
		};
		$out = '';
		$shown = array();
		foreach ($chosen as $id) {
			$items = '';
			foreach ($products as $productId => $row) {
				if (in_array($productId, isset($links[$id]) ? $links[$id] : array(), true) && !isset($shown[$productId])) {
					$items .= $line($row);
					$shown[$productId] = true;
				}
			}
			if ($items !== '') {
				// The last part of "Parent >> Child" names the section.
				$parts = explode('>>', $names[$id]);
				$out .= '<h3>'.dol_escape_htmltag(trim(end($parts))).'</h3><ul>'.$items.'</ul>';
			}
		}
		// Without one of the chosen categories: own section, or all when none is chosen.
		if (!$chosen || getDolGlobalInt('ITTABPDF_PRICELIST_OTHERS')) {
			$items = '';
			foreach ($products as $productId => $row) {
				if (!isset($shown[$productId])) {
					$items .= $line($row);
				}
			}
			if ($items !== '') {
				$out .= ($chosen ? '<h3>'.$langs->trans('WerkbankPricelistOthers').'</h3>' : '').'<ul>'.$items.'</ul>';
			}
		}
		return $out;
	}

	/**
	 * " pro Stunde", " pro Monat": the unit, or the duration of a service; nothing for pieces.
	 *
	 * @param object    $row   Product row
	 * @param Translate $langs Language
	 * @return string
	 */
	private static function per($row, $langs)
	{
		// Time units of Dolibarr's dictionary "Units"; pieces show nothing, others "pro <unit>" (e.g. km).
		$units = array('MI' => 'WerkbankPricelistPerMinute', 'H' => 'WerkbankPricelistPerHour', 'D' => 'WerkbankPricelistPerDay',
			'W' => 'WerkbankPricelistPerWeek', 'MO' => 'WerkbankPricelistPerMonth', 'Y' => 'WerkbankPricelistPerYear');
		$code = strtoupper((string) $row->unit_code);
		if (isset($units[$code])) {
			return ' '.$langs->trans($units[$code]);
		}
		if ($code !== '' && !in_array($code, array('P', 'PCE', 'U', 'SET'), true)) {
			return ' '.$langs->trans('WerkbankPricelistPer', $langs->trans((string) $row->unit_label));
		}
		// A service with a duration of one unit (1h, 1m, 1y).
		if (preg_match('/^1([hdwmy])$/i', trim((string) $row->duration), $match)) {
			$map = array('h' => 'H', 'd' => 'D', 'w' => 'W', 'm' => 'MO', 'y' => 'Y');
			return ' '.$langs->trans($units[$map[strtolower($match[1])]]);
		}
		return '';
	}

	/**
	 * Whether a text has placeholders that depend on prices.
	 *
	 * @param string $html Text
	 * @return bool
	 */
	public static function usesPrices($html)
	{
		return strpos((string) $html, self::PLACEHOLDER) !== false || strpos((string) $html, '__ITTAB_PREIS:') !== false;
	}

	/**
	 * Write the PDFs of the released general documents with price
	 * placeholders again (Stand today, the archive keeps the version's file).
	 *
	 * @param DoliDB $db   Database
	 * @param User   $user User
	 * @return int         PDFs written, -1 when one failed
	 */
	public static function refresh($db, $user)
	{
		require_once __DIR__.'/werkbankdoc.class.php';
		$store = new WerkbankDoc($db);
		$count = 0;
		$failed = false;
		foreach ($store->all(array('released' => 1)) as $doc) {
			if ($doc->kind === 'customer') {
				continue;
			}
			$record = $store->asRecord($doc);
			if (!$record || !self::usesPrices($record->answer)) {
				continue;
			}
			if ($store->writePdf($doc, $user, false) !== '') {
				$count++;
			} else {
				$failed = true;
				dol_syslog('ittabelanderpdf: price list '.$doc->slug.': '.$store->error, LOG_WARNING);
			}
		}
		return $failed ? -1 : $count;
	}
}
