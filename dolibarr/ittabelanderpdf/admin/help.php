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
 * Setup tab "Hilfe": short guides for the daily work, with links to where it happens.
 */

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
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/ittabelanderpdf/lib/ittabelanderpdf.lib.php');

/** @var Translate $langs */
/** @var User $user */

$langs->loadLangs(array('admin', 'ittabelanderpdf@ittabelanderpdf'));
if (!$user->hasRight('societe', 'lire')) {
	accessforbidden();
}

// Topic => where to start.
$topics = array(
	'send' => DOL_URL_ROOT.'/societe/list.php?type=c',
	'generate' => dol_buildpath('/ittabelanderpdf/unterlagen_list.php', 1).'?kind=customer',
	'sign' => DOL_URL_ROOT.'/societe/list.php?type=c',
	'documents' => dol_buildpath('/ittabelanderpdf/unterlagen_list.php', 1),
	'rules' => dol_buildpath('/ittabelanderpdf/admin/docrules.php', 1),
	'followup' => DOL_URL_ROOT.'/index.php?mainmenu=home',
	'qs' => DOL_URL_ROOT.'/fichinter/list.php',
	'intake' => DOL_URL_ROOT.'/ticket/list.php',
	'pricelist' => dol_buildpath('/ittabelanderpdf/admin/setup.php', 1).'#pricelist',
	'mails' => DOL_URL_ROOT.'/admin/mails_templates.php',
	'check' => dol_buildpath('/ittabelanderpdf/admin/check.php', 1),
);

llxHeader('', $langs->trans('WerkbankSetupTabHelp'));
print load_fiche_titre($langs->trans('WerkbankSetup'), '', 'title_setup');
print dol_get_fiche_head(ittabpdf_admin_prepare_head(), 'help', '', -1, '');

print '<div class="opacitymedium">'.$langs->trans('WerkbankHelpIntro').'</div>';
foreach ($topics as $topic => $url) {
	print '<br>'.load_fiche_titre($langs->trans('WerkbankHelp_'.$topic.'_Title'), '<a href="'.dol_escape_htmltag($url).'">'.$langs->trans('WerkbankHelpGo').'</a>', '');
	print '<div class="ittabpdf-help">'.$langs->trans('WerkbankHelp_'.$topic.'_Text').'</div>';
}

print dol_get_fiche_end();
llxFooter();
