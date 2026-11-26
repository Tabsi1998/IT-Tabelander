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
 * Setup page: texts of the Werkbank PDFs and the automatic e-mails.
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
dol_include_once('/ittabelanderpdf/class/werkbankmailtemplates.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankunterlagen.class.php');

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
/** @var Conf $conf */

$langs->loadLangs(array('admin', 'mails', 'ittabelanderpdf@ittabelanderpdf'));
if (!$user->admin) {
	accessforbidden();
}

$texts = array(
	'ITTABPDF_ACCEPT_TEXT' => 'WerkbankSetupAcceptText',
	'ITTABPDF_ACCEPT_TEXT_NOPORTAL' => 'WerkbankSetupAcceptTextNoPortal',
	'ITTABPDF_TAX_NOTICE' => 'WerkbankSetupTaxNotice',
);
$switches = array(
	'ITTABPDF_AUTOSEND_PROPAL' => 'WerkbankEvent_propal_validated',
	'ITTABPDF_AUTOSEND_INVOICE' => 'WerkbankEvent_invoice_validated',
	'ITTABPDF_AUTOSEND_PAID' => 'WerkbankEvent_invoice_paid',
);
$values = array(
	'ITTABPDF_AUTOSEND_DELAY' => 'WerkbankSetupDelay',
	'ITTABPDF_AUTOSEND_BCC' => 'WerkbankSetupBcc',
	'ITTABPDF_PORTAL_URL' => 'WerkbankSetupPortal',
);
// Template choice per automatic e-mail: key => type_template.
$choices = array('propal' => 'propal_send', 'invoice' => 'facture_send', 'deposit' => 'facture_send', 'creditnote' => 'facture_send', 'paid' => 'facture_send');

$action = GETPOST('action', 'aZ09');
$unterlagen = new WerkbankUnterlagen($db);

// Company documents (Unterlagen): categories, folder, default attachments.
if ($action === 'save_unterlagen') {
	$error = 0;
	$ids = array_values(array_filter(array_map('intval', GETPOST('ITTABPDF_UNTERLAGEN_CATEGORIES', 'array:int'))));
	// '0' = deliberately none; '' would fall back to the categories named in WerkbankUnterlagen::DEFAULT_CATEGORIES.
	$error += dolibarr_set_const($db, 'ITTABPDF_UNTERLAGEN_CATEGORIES', $ids ? implode(',', $ids) : '0', 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	$dir = trim(str_replace('\\', '/', GETPOST('ITTABPDF_UNTERLAGEN_DIR', 'alphanohtml')), '/');
	$error += dolibarr_set_const($db, 'ITTABPDF_UNTERLAGEN_DIR', $dir !== '' ? $dir : WerkbankUnterlagen::DEFAULT_DIR, 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	$error += dolibarr_set_const($db, 'ITTABPDF_UNTERLAGEN_AUTO', GETPOST('ITTABPDF_UNTERLAGEN_AUTO') ? '1' : '0', 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	$names = array();
	foreach (array_keys(WerkbankUnterlagen::MAIL_CONTEXTS) as $context) {
		foreach (array('B2B', 'B2C') as $kind) {
			$names[] = 'ITTABPDF_DOCS_'.strtoupper($context).'_'.$kind;
		}
	}
	foreach (WerkbankUnterlagen::APPEND_TYPES as $key) {
		$names[] = 'ITTABPDF_APPEND_'.$key;
	}
	foreach ($names as $const) {
		$files = array_map('basename', GETPOST($const, 'array:alphanohtml'));
		$error += dolibarr_set_const($db, $const, implode(',', array_filter($files)), 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	}
	setEventMessages($langs->trans($error ? 'Error' : 'SetupSaved'), null, $error ? 'errors' : 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'#unterlagen');
	exit;
}
if ($action === 'regenerate_unterlagen') {
	require_once DOL_DOCUMENT_ROOT.'/knowledgemanagement/class/knowledgerecord.class.php';
	$done = 0;
	$failed = array();
	foreach ($unterlagen->articles() as $article) {
		if ($article['status'] !== 1) {
			continue;
		}
		$record = new KnowledgeRecord($db);
		if ($record->fetch($article['id']) > 0) {
			$record->fetch_optionals();
			if ($unterlagen->generate($record, $user) !== '') {
				$done++;
			} else {
				$failed[] = $article['ref'].': '.$unterlagen->error;
			}
		}
	}
	setEventMessages($langs->trans('WerkbankDocsRegenerated', $done), $failed, $failed ? 'warnings' : 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'#unterlagen');
	exit;
}
// Only templates with module = ittabelanderpdf, after the confirmation below.
if ($action === 'confirm_update_templates' && GETPOST('confirm', 'alpha') === 'yes') {
	$result = WerkbankMailTemplates::upgrade($db, (int) $conf->entity, true);
	if ($result === -1) {
		setEventMessages($db->lasterror(), null, 'errors');
	} else {
		setEventMessages($langs->trans('WerkbankTemplatesUpdated', $result['updated'], $result['current']), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action === 'save') {
	$error = 0;
	foreach (array_keys($texts) as $const) {
		$error += dolibarr_set_const($db, $const, GETPOST($const, 'restricthtml'), 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	}
	$error += dolibarr_set_const($db, 'ITTABPDF_PORTAL_ENABLED', GETPOST('ITTABPDF_PORTAL_ENABLED') ? '1' : '0', 'yesno', 0, '', $conf->entity) > 0 ? 0 : 1;
	foreach (array_keys($switches) as $const) {
		$error += dolibarr_set_const($db, $const, GETPOST($const) ? '1' : '0', 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	}
	$error += dolibarr_set_const($db, 'ITTABPDF_AUTOSEND_DELAY', (string) max(0, GETPOSTINT('ITTABPDF_AUTOSEND_DELAY')), 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	$bcc = trim(GETPOST('ITTABPDF_AUTOSEND_BCC', 'alphanohtml'));
	if ($bcc !== '' && !isValidEmail($bcc)) {
		setEventMessages($langs->trans('ErrorBadEMail', $bcc), null, 'errors');
		$error++;
	} else {
		$error += dolibarr_set_const($db, 'ITTABPDF_AUTOSEND_BCC', $bcc, 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	}
	$error += dolibarr_set_const($db, 'ITTABPDF_PORTAL_URL', trim(GETPOST('ITTABPDF_PORTAL_URL', 'alphanohtml')), 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	foreach (array_keys($choices) as $key) {
		foreach (array('SIE', 'DU') as $form) {
			$const = 'ITTABPDF_TPL_'.strtoupper($key).'_'.$form;
			$error += dolibarr_set_const($db, $const, (string) GETPOSTINT($const), 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
		}
	}
	setEventMessages($langs->trans($error ? 'Error' : 'SetupSaved'), null, $error ? 'errors' : 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/**
 * The active templates of one type as a select, 0 = the module's own.
 *
 * @param DoliDB $db    Database
 * @param string $name  Field name
 * @param string $type  type_template
 * @param int    $value Selected row id
 * @return string
 */
function ittabpdf_template_select($db, $name, $type, $value)
{
	global $conf, $langs;
	$html = '<select name="'.$name.'" class="minwidth300"><option value="0">'.dol_escape_htmltag($langs->trans('WerkbankSetupTemplateDefault')).'</option>';
	$sql = "SELECT rowid, label FROM ".MAIN_DB_PREFIX."c_email_templates WHERE type_template = '".$db->escape($type)."' AND active = 1"
		." AND entity IN (0, ".((int) $conf->entity).") ORDER BY position, label";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$html .= '<option value="'.((int) $obj->rowid).'"'.((int) $obj->rowid === (int) $value ? ' selected' : '').'>'.dol_escape_htmltag($obj->label).'</option>';
	}
	return $html.'</select>';
}

llxHeader('', $langs->trans('WerkbankSetup'));
if ($action === 'update_templates') {
	$form = new Form($db);
	print $form->formconfirm($_SERVER['PHP_SELF'], $langs->trans('WerkbankTemplatesUpdate'), $langs->trans('WerkbankTemplatesUpdateConfirm'), 'confirm_update_templates', '', 0, 1);
}
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('WerkbankSetup'), $linkback, 'title_setup');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankSetupPdf').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('WerkbankSetupPortalEnabled').'</td><td>';
print '<input type="checkbox" name="ITTABPDF_PORTAL_ENABLED" value="1"'.(getDolGlobalInt('ITTABPDF_PORTAL_ENABLED') ? ' checked' : '').'> '.$langs->trans('WerkbankSetupPortalEnabledHelp');
print '</td></tr>';
foreach ($texts as $const => $label) {
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($label).'</td><td>';
	print '<textarea name="'.$const.'" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag(getDolGlobalString($const)).'</textarea>';
	print '</td></tr>';
}
print '</table></div>';
print '<div class="opacitymedium">'.$langs->trans('WerkbankSetupModels').'</div><br>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankAutoTitle').'</td><td>'.$langs->trans('Value').'</td></tr>';
foreach ($switches as $const => $label) {
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($label).'</td><td>';
	print '<input type="checkbox" name="'.$const.'" value="1"'.(getDolGlobalInt($const) ? ' checked' : '').'> '.$langs->trans('WerkbankSetupSendAuto');
	print '</td></tr>';
}
print '<tr class="oddeven"><td>'.$langs->trans('WerkbankSetupDelay').'</td><td><input type="number" min="0" name="ITTABPDF_AUTOSEND_DELAY" class="width75" value="'.getDolGlobalInt('ITTABPDF_AUTOSEND_DELAY', 5).'"> '.$langs->trans('Minutes').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('WerkbankSetupBcc').'</td><td><input type="text" name="ITTABPDF_AUTOSEND_BCC" class="minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('ITTABPDF_AUTOSEND_BCC')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('WerkbankSetupPortal').'</td><td><input type="text" name="ITTABPDF_PORTAL_URL" class="minwidth400" value="'.dol_escape_htmltag(getDolGlobalString('ITTABPDF_PORTAL_URL', 'https://it.tabelander.co.at/kundenbereich')).'"></td></tr>';
foreach ($choices as $key => $type) {
	foreach (array('SIE' => 'Sie', 'DU' => 'Du') as $form => $formLabel) {
		$const = 'ITTABPDF_TPL_'.strtoupper($key).'_'.$form;
		print '<tr class="oddeven"><td>'.$langs->trans('WerkbankSetupTemplate', $langs->transnoentities('WerkbankTpl_'.$key), $formLabel).'</td><td>';
		print ittabpdf_template_select($db, $const, $type, getDolGlobalInt($const));
		print '</td></tr>';
	}
}
print '</table></div>';
print '<div class="opacitymedium">'.$langs->trans('WerkbankSetupAutoHelp').'</div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

// State of the module's e-mail templates and the button to update them.
$state = WerkbankMailTemplates::upgrade($db, (int) $conf->entity, false, true);
print '<br>'.load_fiche_titre($langs->trans('WerkbankTemplatesTitle'), '', '');
print '<div class="opacitymedium">';
if (is_array($state)) {
	print $langs->trans('WerkbankTemplatesState', $state['current'], $state['edited']);
	if ($state['updated'] > 0) {
		print ' '.$langs->trans('WerkbankTemplatesAutoUpdated', $state['updated']);
	}
}
print '</div><br>';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=update_templates&token='.newToken().'">'.$langs->trans('WerkbankTemplatesUpdate').'</a>';

// ------------------------------------------------------------ company documents
print '<br><br><a id="unterlagen"></a>'.load_fiche_titre($langs->trans('WerkbankDocsTitle'), '', 'pdf');
if (!isModEnabled('knowledgemanagement')) {
	print '<div class="warning">'.$langs->trans('WerkbankDocsNeedsKnowledge').'</div>';
} else {
	$files = $unterlagen->files();
	// Names of articles not written yet can be chosen too.
	$choosable = $files;
	foreach ($unterlagen->articles() as $article) {
		if (!isset($choosable[$article['slug'].'.pdf'])) {
			$choosable[$article['slug'].'.pdf'] = $article['title'];
		}
	}
	$form = new Form($db);
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#unterlagen">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="save_unterlagen">';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankDocsSource').'</td><td>'.$langs->trans('Value').'</td></tr>';
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('WerkbankDocsCategories').'</td><td>';
	print $form->multiselectarray('ITTABPDF_UNTERLAGEN_CATEGORIES', $unterlagen->categories(), $unterlagen->categoryIds(), 0, 0, 'minwidth300', 0, 0, '', '', '');
	print '<div class="opacitymedium">'.$langs->trans('WerkbankDocsCategoriesHelp', implode(', ', WerkbankUnterlagen::DEFAULT_CATEGORIES)).'</div></td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('WerkbankDocsDir').'</td><td>documents/ecm/ <input type="text" name="ITTABPDF_UNTERLAGEN_DIR" class="minwidth300" value="'.dol_escape_htmltag(WerkbankUnterlagen::relativeDir()).'"></td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('WerkbankDocsAuto').'</td><td><input type="checkbox" name="ITTABPDF_UNTERLAGEN_AUTO" value="1"'.(getDolGlobalInt('ITTABPDF_UNTERLAGEN_AUTO', 1) ? ' checked' : '').'> '.$langs->trans('WerkbankDocsAutoHelp').'</td></tr>';

	print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankDocsDefaults').'</td><td>'.$langs->trans('WerkbankDocsDefaultsHelp').'</td></tr>';
	foreach (WerkbankUnterlagen::MAIL_CONTEXTS as $context => $label) {
		foreach (array('B2B' => 'WerkbankDocsCompanies', 'B2C' => 'WerkbankDocsPrivate') as $kind => $kindLabel) {
			$const = 'ITTABPDF_DOCS_'.strtoupper($context).'_'.$kind;
			$selected = array_values(array_filter(array_map('trim', explode(',', getDolGlobalString($const)))));
			$options = $choosable;
			foreach ($selected as $name) {
				if (!isset($options[$name])) {
					$options[$name] = $name;
				}
			}
			print '<tr class="oddeven"><td>'.$langs->trans($label).' · '.$langs->trans($kindLabel).'</td><td>';
			print $form->multiselectarray($const, $options, $selected, 0, 0, 'minwidth300 maxwidth500', 0, 0, '', '', '');
			print '</td></tr>';
		}
	}

	print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankDocsAppend').'</td><td>'.$langs->trans('WerkbankDocsAppendHelp').'</td></tr>';
	foreach (WerkbankUnterlagen::APPEND_TYPES as $element => $key) {
		$const = 'ITTABPDF_APPEND_'.$key;
		$selected = array_values(array_filter(array_map('trim', explode(',', getDolGlobalString($const)))));
		$options = $choosable;
		foreach ($selected as $name) {
			if (!isset($options[$name])) {
				$options[$name] = $name;
			}
		}
		$fallback = getDolGlobalInt('MAIN_PDF_ADD_TERMSOFSALE_'.$key) && getDolGlobalString('MAIN_INFO_'.$key.'_TERMSOFSALE')
			? $langs->trans('WerkbankDocsAppendDolibarr', getDolGlobalString('MAIN_INFO_'.$key.'_TERMSOFSALE')) : '';
		print '<tr class="oddeven"><td>'.$langs->trans('WerkbankDocsAppend_'.$key).'</td><td>';
		print $form->multiselectarray($const, $options, $selected, 0, 0, 'minwidth300 maxwidth500', 0, 0, '', '', '');
		print $fallback !== '' ? '<div class="opacitymedium">'.dol_escape_htmltag($fallback).'</div>' : '';
		print '</td></tr>';
	}
	print '</table></div>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
	print '</form><br>';

	// What is there: every article of the chosen categories with its file.
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankDocsArticle').'</td><td>'.$langs->trans('WerkbankDocsFile').'</td><td class="center">'.$langs->trans('Status').'</td><td class="right">'.$langs->trans('WerkbankDocsWrittenAt').'</td></tr>';
	$articles = $unterlagen->articles();
	if (!$articles) {
		print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('WerkbankDocsNone').'</span></td></tr>';
	}
	foreach ($articles as $article) {
		$name = $article['slug'].'.pdf';
		$path = WerkbankUnterlagen::dir().'/'.$name;
		$relative = WerkbankUnterlagen::relativeDir().'/'.$name;
		print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/knowledgemanagement/knowledgerecord_card.php?id='.$article['id'].'">'.dol_escape_htmltag($article['ref']).'</a> '.dol_escape_htmltag($article['title']).'</td>';
		print '<td>'.(is_file($path) ? '<a href="'.DOL_URL_ROOT.'/document.php?modulepart=ecm&amp;file='.urlencode($relative).'">'.img_picto('', 'pdf', 'class="pictofixedwidth"').dol_escape_htmltag($name).'</a>' : '<span class="opacitymedium">'.dol_escape_htmltag($name).'</span>').'</td>';
		print '<td class="center">'.dol_escape_htmltag($langs->trans($article['status'] === 1 ? 'Validated' : ($article['status'] === 9 ? 'Obsolete' : 'Draft'))).'</td>';
		print '<td class="right">'.(is_file($path) ? dol_print_date(dol_filemtime($path), 'dayhour', 'tzuser') : '').'</td></tr>';
	}
	print '</table></div>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#unterlagen" class="center">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="regenerate_unterlagen">';
	print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('WerkbankDocsRegenerate')).'"></form>';
	print '<div class="opacitymedium">'.$langs->trans('WerkbankDocsSetupHelp').'</div>';
}

llxFooter();
$db->close();
