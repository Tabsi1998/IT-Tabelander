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
 * Saves one point of a QS run (js/qs.js) and answers with the new counts.
 * POST only, with the page's token, which stays valid (NOTOKENRENEWAL).
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', 1);
}

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
dol_include_once('/ittabelanderpdf/class/werkbankqs.class.php');

/** @var DoliDB $db */
/** @var User $user */
/** @var Translate $langs */

top_httphead('application/json');
$langs->load('ittabelanderpdf@ittabelanderpdf');

$answer = function ($code, array $data) {
	http_response_code($code);
	print json_encode($data);
	exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || GETPOST('action', 'aZ09') !== 'saveitem') {
	$answer(405, array('ok' => false));
}
if (!$user->hasRight('ittabelanderpdf', 'qs', 'run') || !$user->hasRight('ficheinter', 'lire') || $user->socid > 0) {
	$answer(403, array('ok' => false));
}
$qs = new WerkbankQs($db);
$runId = GETPOSTINT('run');
$resultId = GETPOSTINT('item');
$checked = GETPOSTINT('checked');
if ($qs->save($runId, $resultId, $checked, GETPOST('value', 'alphanohtml'), GETPOST('note', 'alphanohtml'), $user) < 0) {
	$answer(409, array('ok' => false, 'error' => $langs->transnoentities($qs->error)));
}
$meta = '';
foreach ($qs->results($runId) as $result) {
	if ((int) $result->rowid === $resultId && $result->date_check) {
		$who = trim($result->firstname.' '.$result->lastname);
		$meta = ($who !== '' ? $who : $result->login).' · '.dol_print_date($db->jdate($result->date_check), 'dayhour', 'tzuser');
	}
}
$answer(200, array('ok' => true, 'counts' => $qs->counts($runId), 'meta' => $meta));
