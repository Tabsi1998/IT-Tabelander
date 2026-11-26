<?php
/*
 * The price list from the products, in the disposable Dolibarr of
 * scripts/dev_dolibarr.py (after "unterlagen"):
 *
 *   php pricelist.php run   sections per category, only for sale and above 0 €, units,
 *                           a document with the placeholders, written again on a price change
 *
 * Never run against a real Dolibarr.
 */

require __DIR__.'/bootstrap.php';

$stage = $argv[1] ?? '';
$admin = rt_admin($db);
$user = $admin;
$GLOBALS['user'] = $admin;
$langs->setDefaultLang('de_DE');
$langs->loadLangs(array('main', 'ittabelanderpdf@ittabelanderpdf'));
if ($stage !== 'run') {
	rt_fail('unknown stage '.$stage);
}
rt_modules(array('modCategorie', 'modProduct', 'modService'));

require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/ittabelanderpdf/class/werkbankpricelist.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankdoc.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankvars.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankunterlagen.class.php');

$checks = array();
$check = function ($name, $ok, $detail = '') use (&$checks) {
	$checks[] = array('check' => $name, 'ok' => (bool) $ok, 'detail' => $detail);
};

$category = function ($label) use ($db, $admin) {
	$id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."categorie WHERE type = 0 AND label = '".$db->escape($label)."'");
	if ($id) {
		return $id;
	}
	$tag = new Categorie($db);
	$tag->label = $label;
	$tag->type = Categorie::TYPE_PRODUCT;
	if ($tag->create($admin) <= 0) {
		rt_fail('category: '.$tag->error);
	}
	return (int) $tag->id;
};
$hours = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_units WHERE code = 'H'");
$product = function ($ref, $label, $price, array $extra = array()) use ($db, $admin) {
	$item = new Product($db);
	if ($item->fetch(0, $ref) <= 0) {
		$item->ref = $ref;
		$item->label = $label;
		$item->type = Product::TYPE_SERVICE;
		$item->price = $price;
		$item->price_base_type = 'HT';
		$item->status = isset($extra['tosell']) ? $extra['tosell'] : 1;
		$item->status_buy = 0;
		$item->fk_unit = isset($extra['unit']) ? $extra['unit'] : null;
		$item->duration_value = isset($extra['duration']) ? 1 : 0;
		$item->duration_unit = isset($extra['duration']) ? $extra['duration'] : '';
		if ($item->create($admin) <= 0) {
			rt_fail('product '.$ref.': '.$item->error);
		}
	}
	return $item;
};

$repair = $category('PL Reparatur');
$hosting = $category('PL Hosting');
$unused = $category('PL Nicht gewählt');
$diag = $product('PL-DIAG', 'Fehlerdiagnose', 29);
$hour = $product('PL-STD', 'Werkstattstunde', 45, array('unit' => $hours));
$month = $product('PL-HOST', 'Webhosting', 9.9, array('duration' => 'm'));
$zero = $product('PL-FEST', 'Festpreis-Vorlage', 0);
$off = $product('PL-ALT', 'Nicht mehr im Verkauf', 10, array('tosell' => 0));
$loose = $product('PL-LOSE', 'Ohne Kategorie', 5);
foreach (array(array($repair, $diag), array($repair, $hour), array($hosting, $month), array($repair, $zero), array($repair, $off), array($unused, $loose)) as $pair) {
	$tag = new Categorie($db);
	$tag->fetch($pair[0]);
	if (!rt_value($db, "SELECT fk_product FROM ".MAIN_DB_PREFIX."categorie_product WHERE fk_categorie = ".$pair[0]." AND fk_product = ".((int) $pair[1]->id))) {
		$tag->add_type($pair[1], Categorie::TYPE_PRODUCT);
	}
}
rt_const($db, 'ITTABPDF_PRICELIST_CATEGORIES', $hosting.','.$repair);
rt_const($db, 'ITTABPDF_PRICELIST_OTHERS', '0');
rt_const($db, 'ITTABPDF_PRICELIST_DESC', '0');
rt_const($db, 'ITTABPDF_PRICELIST_AUTO', '1');

// 1. The list itself.
$html = WerkbankPricelist::html($db);
$check('a section per chosen category, in tree order', strpos($html, '<h3>PL Reparatur</h3>') !== false && strpos($html, '<h3>PL Hosting</h3>') !== false
	&& strpos($html, 'PL Nicht gewählt') === false, strip_tags($html));
$check('only for sale and above 0 €', strpos($html, 'Fehlerdiagnose') !== false && strpos($html, 'Festpreis-Vorlage') === false && strpos($html, 'Nicht mehr im Verkauf') === false
	&& strpos($html, 'Ohne Kategorie') === false);
$check('prices with their unit', preg_match('/Werkstattstunde<\/strong>: 45,00 .{1,8} pro Stunde/u', $html) && preg_match('/Webhosting<\/strong>: 9,90 .{1,8} pro Monat/u', $html)
	&& preg_match('/Fehlerdiagnose<\/strong>: 29,00 [^<]*<\/li>/u', $html), strip_tags($html));
rt_const($db, 'ITTABPDF_PRICELIST_OTHERS', '1');
$others = WerkbankPricelist::html($db);
$check('products without a chosen category in their own section', strpos($others, 'Weitere Leistungen</h3>') !== false
	&& strpos($others, 'Ohne Kategorie') > strpos($others, 'Weitere Leistungen</h3>'), strip_tags($others));
rt_const($db, 'ITTABPDF_PRICELIST_OTHERS', '0');
$check('the list passes the WAF', !preg_match('/<style|<svg|data:|\son[a-z]+=/i', $html));

// 2. A document with the placeholders: filled, "Stand" today, PDF written.
$text = '<p>Alle Preise in Euro.</p><p>__ITTAB_PREISLISTE__</p><p>Die Diagnose kostet __ITTAB_PREIS:PL-DIAG__.</p><p>Von __ITTAB_FIRMA_NAME__, Stand __ITTAB_DATUM__.</p>';
$filled = WerkbankVars::fill($text, WerkbankVars::values($db, null));
$check('placeholders of a general document are filled', WerkbankVars::left($filled) === array() && strpos($filled, '<p><h3') === false && strpos($filled, 'Die Diagnose kostet 29,00') !== false
	&& strpos($filled, (string) $mysoc->name) !== false, $filled);
$store = new WerkbankDoc($db);
$existing = $store->bySlug('preisliste-test');
$docId = $store->save($existing ? (int) $existing->rowid : 0, array('slug' => 'preisliste-test', 'title' => 'Preisliste Test', 'kind' => 'internal', 'public' => 0,
	'text' => $text, 'position' => 98), $admin);
$store->release($docId, 'Test', $admin);
$doc = $store->get($docId);
$pdf = WerkbankUnterlagen::dir().'/preisliste-test.pdf';
$archive = WerkbankUnterlagen::dir().'/Archiv/preisliste-test-v'.((int) $doc->version).'.pdf';
$check('released: PDF and archive copy', is_file($pdf) && is_file($archive));
@mkdir('/tmp/ittpdf-out', 0777, true);
copy($pdf, '/tmp/ittpdf-out/preisliste.pdf');

// 3. A price change writes the PDF again; the archive keeps the released version.
$before = md5_file($pdf);
$archived = md5_file($archive);
sleep(1);
$diag->updatePrice(32, 'HT', $admin);
clearstatcache();
$check('a price change writes the PDF again', md5_file($pdf) !== $before && strpos(WerkbankPricelist::html($db), '32,00') !== false);
$check('the archive copy stays', md5_file($archive) === $archived);
rt_const($db, 'ITTABPDF_PRICELIST_AUTO', '0');
$before = md5_file($pdf);
sleep(1);
$diag->updatePrice(29, 'HT', $admin);
clearstatcache();
$check('switched off: nothing is written', md5_file($pdf) === $before);
rt_const($db, 'ITTABPDF_PRICELIST_AUTO', '1');
$check('the button writes it again', WerkbankPricelist::refresh($db, $admin) >= 1 && md5_file($pdf) !== $before);
$tag = new Categorie($db);
$tag->fetch($hosting);
$tag->del_type($month, Categorie::TYPE_PRODUCT);
$check('taking a product out of a category moves it', strpos(WerkbankPricelist::html($db), 'PL Hosting') === false);
$tag->add_type($month, Categorie::TYPE_PRODUCT);

$failed = array_values(array_filter($checks, function ($entry) {
	return !$entry['ok'];
}));
echo json_encode(array('checks' => $checks, 'failed' => count($failed), 'doc' => $docId)), "\n";
exit(0);
