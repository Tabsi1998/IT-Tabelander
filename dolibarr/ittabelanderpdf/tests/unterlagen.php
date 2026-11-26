<?php
/*
 * Company documents (Unterlagen) from the knowledge base, in the disposable
 * Dolibarr of scripts/dev_dolibarr.py (after "fixtures"):
 *
 *   php unterlagen.php enable   knowledge base, categories, ECM, tickets; this module once more (extra field)
 *   php unterlagen.php run      articles -> PDFs in the ECM folder, regeneration, slug clash,
 *                               defaults, appended terms; prints the checks and ids for the web part
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

if ($stage === 'enable') {
	rt_modules(array('modCategorie', 'modECM', 'modKnowledgeManagement', 'modTicket'));
	// Again, now that the knowledge base exists: creates the extra field ittab_slug.
	$result = unActivateModule('modITTabelanderPDF');
	// activateModule() skips a module whose constant this process still holds.
	unset($conf->global->MAIN_MODULE_ITTABELANDERPDF);
	rt_modules(array('modITTabelanderPDF'));
	if (!rt_value($db, "SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."extrafields WHERE name = 'ittab_slug' AND elementtype = 'knowledgemanagement_knowledgerecord'")) {
		rt_fail('the extra field ittab_slug was not created');
	}
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	foreach (array('FAQ', 'Rechtstexte', 'Unterlagen') as $label) {
		if (!rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."categorie WHERE type = 13 AND label = '".$db->escape($label)."'")) {
			$category = new Categorie($db);
			$category->label = $label;
			$category->type = Categorie::TYPE_KNOWLEDGEMANAGEMENT;
			if ($category->create($admin) <= 0) {
				rt_fail('category '.$label.': '.$category->error);
			}
		}
	}
	echo json_encode(array('enable' => 'ok', 'unactivate' => $result)), "\n";
	exit(0);
}

if ($stage !== 'run') {
	rt_fail('unknown stage '.$stage);
}

require_once DOL_DOCUMENT_ROOT.'/knowledgemanagement/class/knowledgerecord.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/ittabelanderpdf/class/werkbankunterlagen.class.php');

$checks = array();
$check = function ($name, $ok, $detail = '') use (&$checks) {
	$checks[] = array('check' => $name, 'ok' => (bool) $ok, 'detail' => $detail);
};
$category = function ($label) use ($db) {
	return (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."categorie WHERE type = 13 AND label = '".$db->escape($label)."'");
};
$pages = function ($path) {
	return is_file($path) ? preg_match_all('#/Type\s*/Page[^s]#', (string) file_get_contents($path)) : 0;
};
$article = function ($title, $html, $categories, $slug = '', $validate = true) use ($db, $admin) {
	$record = new KnowledgeRecord($db);
	$record->question = $title;
	$record->answer = $html;
	$record->lang = 'de_DE';
	if ($slug !== '') {
		$record->array_options['options_ittab_slug'] = $slug;
	}
	if ($record->create($admin) <= 0) {
		rt_fail('article '.$title.': '.$record->error.' '.implode(',', (array) $record->errors));
	}
	// As the card does: categories after create.
	$record->setCategories($categories);
	if ($validate && $record->validate($admin) <= 0) {
		rt_fail('validate '.$title.': '.$record->error);
	}
	$record->fetch($record->id);
	$record->fetch_optionals();
	return $record;
};

$legal = $category('Rechtstexte');
$internal = $category('Unterlagen');
$faq = $category('FAQ');
$dir = WerkbankUnterlagen::dir();
// A run starts from an empty knowledge base and folder, so it can run again.
foreach (array('categorie_knowledgemanagement', 'knowledgemanagement_knowledgerecord_extrafields', 'knowledgemanagement_knowledgerecord') as $table) {
	rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX.$table);
}
dol_delete_dir_recursive($dir);
rt_exec($db, "DELETE FROM ".MAIN_DB_PREFIX."ecm_files WHERE filepath LIKE 'ecm/IT-Tabelander%'");

$long = '<h2>1. Geltungsbereich</h2><p>Diese Bedingungen gelten für alle Leistungen von IT-Tabelander: Reparatur, Aufrüstung, '
	.'Bau von PCs und Betreuung. <strong>Wichtig:</strong> Gearbeitet wird erst nach Zusage.</p>'
	.'<h3>1.1 Kostenvoranschlag</h3><ul><li>Der Kostenvoranschlag ist unverbindlich.</li><li>Die Diagnose kostet 29&nbsp;€ und wird bei Auftrag angerechnet.</li>'
	.'<li>Abholfrist 4 Wochen, danach 2 € pro Tag.</li></ul><ol><li>Erster Schritt</li><li>Zweiter Schritt</li></ol>'
	.'<p class="x" style="color:red" onclick="alert(1)">Absatz mit <a href="https://it.tabelander.co.at">Link</a> und <a href="javascript:alert(1)">bösem Link</a>.</p>'
	.'<script>alert(1)</script><table><tr><th>Leistung</th><th>Preis</th></tr><tr><td>Diagnose</td><td>29 €</td></tr></table>';
for ($i = 2; $i <= 9; $i++) {
	$long .= '<h2>'.$i.'. Abschnitt</h2><p>'.str_repeat('Ein Satz, damit die Unterlage über mehrere Seiten läuft und der Seitenumbruch geprüft wird. ', 14).'</p>';
}

// 1. Released article in "Rechtstexte" with its website name: the PDF is there.
$terms = $article('Nutzungsbedingungen', $long, array($legal));
$termsFile = $dir.'/nutzungsbedingungen.pdf';
$check('released legal text writes nutzungsbedingungen.pdf', is_file($termsFile), $termsFile);
$check('long text runs over several pages', $pages($termsFile) >= 2, $pages($termsFile).' pages');
$ecmDir = rt_value($db, "SELECT d.rowid FROM ".MAIN_DB_PREFIX."ecm_directories AS d INNER JOIN ".MAIN_DB_PREFIX."ecm_directories AS p ON p.rowid = d.fk_parent"
	." WHERE d.label = 'Unterlagen' AND p.label = 'IT-Tabelander'");
$check('ECM folder IT-Tabelander/Unterlagen exists as directories', (bool) $ecmDir);
$indexed = rt_value($db, "SELECT label FROM ".MAIN_DB_PREFIX."ecm_files WHERE filepath = 'ecm/IT-Tabelander/Unterlagen' AND filename = 'nutzungsbedingungen.pdf'");
$check('file is in the ECM index', $indexed === md5_file($termsFile), (string) $indexed);
dol_include_once('/ittabelanderpdf/class/werkbankbuilder.unterlage.class.php');
$clean = WerkbankBuilderUnterlage::html($long);
$check('HTML keeps headings, lists, table and the web link', strpos($clean, '<h2 style=') !== false && strpos($clean, '<ul><li>') !== false
	&& strpos($clean, '<ol>') !== false && strpos($clean, '<td ') !== false && strpos($clean, 'href="https://it.tabelander.co.at"') !== false);
$check('script, event handler, class and javascript link are dropped', strpos($clean, 'alert(1)') === false && stripos($clean, 'onclick') === false
	&& strpos($clean, 'class=') === false && stripos($clean, 'javascript:') === false && strpos($clean, 'bösem Link') !== false, substr($clean, 0, 300));
$check('plain text becomes paragraphs', WerkbankBuilderUnterlage::html("Zeile eins\n\nZeile <zwei>") === '<p>Zeile eins</p><p>Zeile &lt;zwei&gt;</p>',
	WerkbankBuilderUnterlage::html("Zeile eins\n\nZeile <zwei>"));

// 2. Own slug, the names the website expects.
$widerruf = $article('Widerrufsbelehrung', '<p>Du kannst binnen 14 Tagen ohne Angabe von Gründen widerrufen.</p>', array($legal), 'widerruf');
$agb = $article('AGB für Unternehmer', '<h2>Allgemeines</h2><p>Für Firmenkunden.</p>', array($legal), 'agb-unternehmer');
$check('own slug widerruf.pdf', is_file($dir.'/widerruf.pdf'));
$check('own slug agb-unternehmer.pdf', is_file($dir.'/agb-unternehmer.pdf'));

// 3. FAQ and drafts get no PDF; a draft gets one on release.
$article('Wie lange dauert eine Reparatur?', '<p>Meist zwei bis drei Tage.</p>', array($faq));
$check('FAQ article writes nothing', !is_file($dir.'/wie-lange-dauert-eine-reparatur.pdf'));
$checklist = $article('Checkliste Übergabe', '<p>Intern.</p>', array($internal), '', false);
$check('draft in Unterlagen writes nothing', !is_file($dir.'/checkliste-uebergabe.pdf'));
$checklist->validate($admin);
$check('release writes checkliste-uebergabe.pdf (umlaut in the slug)', is_file($dir.'/checkliste-uebergabe.pdf'));

// 4. A change of a released article writes the PDF again.
$before = md5_file($termsFile);
$terms->answer = '<h2>Neu</h2><p>Geänderte Fassung.</p>';
$terms->update($admin);
clearstatcache();
$check('change writes the PDF again', md5_file($termsFile) !== $before && $pages($termsFile) === 1, $pages($termsFile).' pages');

// 5. Put a released article into the category: PDF on CATEGORY_MODIFY.
$later = $article('Datenschutzerklärung', '<p>Daten bleiben in Dolibarr.</p>', array(), 'datenschutz');
$check('released article outside the categories writes nothing', !is_file($dir.'/datenschutz.pdf'));
$later->setCategories(array($legal));
$check('putting it into Rechtstexte writes datenschutz.pdf', is_file($dir.'/datenschutz.pdf'));

// 6. Two articles with one name: the second one is refused, the first file stays.
$clash = $article('Widerruf', '<p>Doppelt.</p>', array($legal), 'widerruf');
$unterlagen = new WerkbankUnterlagen($db);
$check('slug clash is refused', $unterlagen->generate($clash, $admin) === '' && strpos($unterlagen->error, 'widerruf.pdf') !== false, $unterlagen->error);
$check('first article keeps its file', strpos((string) file_get_contents($dir.'/widerruf.pdf'), 'Doppelt') === false);

// 7. The list of the mail form and the setting with an unknown name.
$files = $unterlagen->files();
$check('mail choice lists the PDFs with titles', ($files['nutzungsbedingungen.pdf'] ?? '') === 'Nutzungsbedingungen' && isset($files['agb-unternehmer.pdf']), json_encode($files));
$check('path() refuses names outside the folder', $unterlagen->path('../../../conf/conf.php') === '' && $unterlagen->path('nutzungsbedingungen.pdf') === $termsFile);
rt_const($db, 'ITTABPDF_DOCS_ORDER_SEND_B2B', 'agb-unternehmer.pdf,gibtsnicht.pdf');
$check('defaults skip missing files', count($unterlagen->pathsFrom('ITTABPDF_DOCS_ORDER_SEND_B2B')) === 1);
rt_const($db, 'ITTABPDF_DOCS_ORDER_SEND_B2B', '');

// 8. Terms behind the proposal PDF: own setting, then Dolibarr's option.
$firm = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'office@musterfirma.example'");
$private = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = 'max@mustermann.example'");
$proposal = function ($socid) use ($db, $admin) {
	$prop = new Propal($db);
	$prop->socid = $socid;
	$prop->date = dol_now();
	$prop->duree_validite = 14;
	$prop->model_pdf = 'werkbank';
	if ($prop->create($admin) <= 0) {
		rt_fail('proposal: '.$prop->error);
	}
	$prop->addline('Diagnose', 29, 1, 0);
	$prop->valid($admin);
	$prop->fetch($prop->id);
	return $prop;
};
$prop = $proposal($firm);
$render = function ($prop) use ($langs) {
	global $conf;
	$prop->generateDocument('werkbank', $langs);
	$ref = dol_sanitizeFileName($prop->ref);
	return $conf->propal->dir_output.'/'.$ref.'/'.$ref.'.pdf';
};
$plain = $pages($render($prop));
rt_const($db, 'ITTABPDF_APPEND_PROPAL', 'agb-unternehmer.pdf');
$with = $render($prop);
$check('ITTABPDF_APPEND_PROPAL appends agb-unternehmer.pdf', $pages($with) === $plain + $pages($dir.'/agb-unternehmer.pdf'), $plain.' -> '.$pages($with));
@mkdir('/tmp/ittpdf-out', 0777, true);
copy($with, '/tmp/ittpdf-out/angebot-mit-agb.pdf');
rt_const($db, 'ITTABPDF_APPEND_PROPAL', '');
copy($termsFile, $conf->propal->dir_output.'/terms.pdf');
rt_const($db, 'MAIN_PDF_ADD_TERMSOFSALE_PROPAL', '1');
rt_const($db, 'MAIN_INFO_PROPAL_TERMSOFSALE', 'terms.pdf');
$check('Dolibarr\'s MAIN_PDF_ADD_TERMSOFSALE_PROPAL works with werkbank', $pages($render($prop)) === $plain + 1, (string) $pages($render($prop)));
rt_const($db, 'MAIN_PDF_ADD_TERMSOFSALE_PROPAL', '0');
$render($prop);

// Examples for a look.
foreach (array('nutzungsbedingungen', 'widerruf', 'agb-unternehmer', 'datenschutz', 'checkliste-uebergabe') as $name) {
	copy($dir.'/'.$name.'.pdf', '/tmp/ittpdf-out/unterlage-'.$name.'.pdf');
}
// The long version again for the example file.
$terms->answer = $long;
$terms->update($admin);
copy($termsFile, '/tmp/ittpdf-out/unterlage-nutzungsbedingungen.pdf');

// For the web part: a proposal to a company and one to a private person, a ticket.
require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
$ticket = new Ticket($db);
$ticket->ref = $ticket->getDefaultRef();
$ticket->track_id = bin2hex(random_bytes(8));
$ticket->subject = 'Unterlagen zur Anfrage';
$ticket->message = 'Bitte die Bedingungen schicken.';
$ticket->type_code = 'ISSUE';
$ticket->category_code = 'OTHER';
$ticket->severity_code = 'NORMAL';
$ticket->fk_soc = $private;
$ticket->context = array('disableticketemail' => 1);
if ($ticket->create($admin) <= 0) {
	rt_fail('ticket: '.$ticket->error);
}
$failed = array_values(array_filter($checks, function ($entry) {
	return !$entry['ok'];
}));
echo json_encode(array('checks' => $checks, 'failed' => count($failed), 'propal_firm' => (int) $prop->id,
	'propal_private' => (int) $proposal($private)->id, 'article' => (int) $terms->id, 'ticket' => $ticket->track_id)), "\n";
exit(0);
