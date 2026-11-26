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
 * Stores one signature drawn on the device (js/sign.js) and writes the PDF
 * again. POST only, with the page's token (NOTOKENRENEWAL). The image comes
 * as base64 without "data:" prefix and "=" padding, which Dolibarr's WAF
 * would refuse in a form field.
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
dol_include_once('/ittabelanderpdf/class/werkbanksignature.class.php');

/** @var DoliDB $db */
/** @var User $user */
/** @var Translate $langs */

top_httphead('application/json');
$langs->loadLangs(array('errors', 'ittabelanderpdf@ittabelanderpdf'));

$answer = function ($code, array $data) {
	http_response_code($code);
	print json_encode($data);
	exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || GETPOST('action', 'aZ09') !== 'sign') {
	$answer(405, array('ok' => false));
}
$kind = GETPOST('kind', 'aZ09');
$allowed = $user->socid <= 0 && (($kind === 'qs' && $user->hasRight('ittabelanderpdf', 'qs', 'run') && $user->hasRight('ficheinter', 'lire'))
	|| ($kind === 'intake' && $user->hasRight('ticket', 'write')));
if (!$allowed) {
	$answer(403, array('ok' => false));
}
$signature = new WerkbankSignature($db);
// The image is taken raw: base64 only, checked and decoded in save().
$png = isset($_POST['png']) && is_string($_POST['png']) ? $_POST['png'] : '';
if ($signature->save($kind, GETPOSTINT('id'), GETPOST('role', 'aZ09'), GETPOST('name', 'alphanohtml'), $png, $user) < 0) {
	$answer(422, array('ok' => false, 'error' => $langs->transnoentities($signature->error)));
}
$answer(200, array('ok' => true));
