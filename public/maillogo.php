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
 * Public, read-only: the company logo for e-mails, an opaque PNG on white
 * (see WerkbankMailData::logoFile()). No login, no parameters but the
 * entity; it serves this one image and nothing else.
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}
$entity = isset($_GET['entity']) ? (int) $_GET['entity'] : 0;
if ($entity > 0 && !defined('DOLENTITY')) {
	define('DOLENTITY', $entity);
}

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	http_response_code(500);
	exit;
}

$file = '';
if (isModEnabled('ittabelanderpdf')) {
	dol_include_once('/ittabelanderpdf/class/werkbankmaildata.class.php');
	$file = WerkbankMailData::logoFile();
}
if ($file === '') {
	http_response_code(404);
	exit;
}

$etag = '"'.md5_file($file).'"';
header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');
header('ETag: '.$etag);
header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
header('X-Content-Type-Options: nosniff');
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
	http_response_code(304);
	exit;
}
header('Content-Length: '.filesize($file));
readfile($file);
