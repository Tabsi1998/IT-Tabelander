<?php
/*
 * The module's e-mail templates in Dolibarr's editor (CKEditor), in the
 * disposable Dolibarr of scripts/dev_dolibarr.py ("editor"):
 *
 *   php editor.php prepare   editor on for templates; prints the templates to open and their text
 *   php editor.php compare   after saving them in the editor: same structure, same text, nothing added
 *
 * Never run against a real Dolibarr.
 */

require __DIR__.'/bootstrap.php';

$stage = $argv[1] ?? '';
$admin = rt_admin($db);
dol_include_once('/ittabelanderpdf/class/werkbankmailtemplates.class.php');
$labels = array('Angebot · Sie', 'Rechnung · Du', 'Nachricht · Du', 'Unterlagen zusenden · Sie', 'Unterschriebene Unterlage · Sie');
$state = '/tmp/ittpdf-editor.json';

/**
 * What a mail shows: the tags in order (without attributes, <br> and <hr> as such) and the text.
 *
 * @param string $html Template
 * @return array{tags:string,text:string,empty:int}
 */
function rt_shape($html)
{
	// The editor keeps an empty line as <p>&nbsp;</p>; the template writes <p><br></p>.
	$html = preg_replace('#<p([^>]*)>\s*<br\s*/?>\s*</p>#i', '<p$1>&nbsp;</p>', (string) $html);
	$html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	preg_match_all('#<(/?)([a-z0-9]+)\b[^>]*>#i', $html, $tags, PREG_SET_ORDER);
	$names = array();
	foreach ($tags as $tag) {
		$names[] = $tag[1].strtolower($tag[2]);
	}
	// Words without spacing: the editor puts line breaks between blocks.
	$text = preg_replace('/[\s\x{00A0}]+/u', '', strip_tags($html));
	// Blocks without anything in them (what the editor made of the old orange line).
	$empty = preg_match_all('#<(div|p)\b[^>]*>(\s|&nbsp;|\x{00A0})*</\1>#iu', $html);
	return array('tags' => implode(' ', $names), 'text' => $text, 'empty' => (int) $empty);
}

if ($stage === 'prepare') {
	rt_modules(array('modFckeditor'));
	rt_const($db, 'FCKEDITOR_ENABLE_MAIL', '1');
	$list = array();
	foreach ($labels as $label) {
		$row = $db->fetch_object($db->query("SELECT rowid, content FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'ittabelanderpdf' AND label = '".$db->escape($label)."'"));
		if ($row) {
			$list[$label] = array('rowid' => (int) $row->rowid, 'content' => (string) $row->content);
		}
	}
	file_put_contents($state, json_encode($list));
	echo json_encode(array('rowids' => array_values(array_map(function ($entry) {
		return $entry['rowid'];
	}, $list)))), "\n";
	exit(0);
}
if ($stage !== 'compare') {
	rt_fail('unknown stage '.$stage);
}
$before = json_decode((string) file_get_contents($state), true);
$checks = array();
foreach ($before as $label => $entry) {
	$row = $db->fetch_object($db->query("SELECT content, tms FROM ".MAIN_DB_PREFIX."c_email_templates WHERE rowid = ".((int) $entry['rowid'])));
	$old = rt_shape($entry['content']);
	$new = rt_shape($row ? $row->content : '');
	$saved = $row && (string) $row->content !== '';
	$checks[] = array('check' => $label.': saved in the editor, same blocks and text, nothing empty added',
		'ok' => $saved && $old['tags'] === $new['tags'] && $old['text'] === $new['text'] && $new['empty'] <= $old['empty'],
		'detail' => $old['tags'] === $new['tags'] ? ($old['text'] === $new['text'] ? 'empty '.$old['empty'].' -> '.$new['empty'] : 'text: '.$new['text'])
			: 'tags before: '.$old['tags'].' | after: '.$new['tags']);
	$sign = (string) substr((string) $row->content, (int) strrpos((string) $row->content, '<div'));
	$checks[] = array('check' => $label.': the signature keeps its font and sizes after saving',
		'ok' => substr_count($sign, 'font-family:Arial') >= 3 && strpos($sign, 'font-size:14px') !== false && strpos($sign, 'font-size:16px') !== false,
		'detail' => substr($sign, 0, 400));
	$checks[] = array('check' => $label.': the rule keeps its light colour', 'ok' => (bool) preg_match('~<hr[^>]*border-top:\s*1px solid #D6DEE7~i', (string) $row->content),
		'detail' => preg_match('#<hr[^>]*>#i', (string) $row->content, $hr) ? $hr[0] : 'no hr');
	$checks[] = array('check' => $label.': no logo or line on top, the signature at the end',
		'ok' => strpos((string) $row->content, 'border-top:2px') === false && strpos((string) $row->content, '<hr') !== false
			&& strpos((string) $row->content, '__ITTAB_LOGO__') > strpos((string) $row->content, '<hr'));
}
$failed = array_values(array_filter($checks, function ($entry) {
	return !$entry['ok'];
}));
echo json_encode(array('checks' => $checks, 'failed' => count($failed))), "\n";
exit(0);
