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
 * Setup tab "Unterlagen je Kunde": which company document a customer needs
 * and when (rules), and how loudly a missing one is shown.
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
dol_include_once('/ittabelanderpdf/class/werkbankvars.class.php');
dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
/** @var Conf $conf */

$langs->loadLangs(array('admin', 'categories', 'ittabelanderpdf@ittabelanderpdf'));
if (!$user->admin) {
	accessforbidden();
}
$docs = new WerkbankDocs($db);
$action = GETPOST('action', 'aZ09');
$self = $_SERVER['PHP_SELF'];

if ($action === 'save_rules') {
	$error = 0;
	$read = function ($suffix) {
		return array('label' => GETPOST('label_'.$suffix, 'alphanohtml'), 'slug' => GETPOST('slug_'.$suffix, 'alphanohtml'),
			'action' => GETPOST('action_'.$suffix, 'aZ09'), 'customer_type' => GETPOST('customertype_'.$suffix, 'aZ09'),
			'products' => GETPOST('products_'.$suffix, 'alphanohtml'), 'fk_category' => GETPOSTINT('category_'.$suffix),
			'required' => GETPOST('required_'.$suffix), 'renew' => GETPOST('renew_'.$suffix), 'position' => GETPOSTINT('position_'.$suffix),
			'active' => GETPOST('active_'.$suffix));
	};
	foreach ($docs->rules() as $rule) {
		if (GETPOSTISSET('label_'.$rule->rowid)) {
			$error += $docs->saveRule((int) $rule->rowid, $read((string) $rule->rowid)) > 0 ? 0 : 1;
		}
	}
	if (trim(GETPOST('label_new', 'alphanohtml')) !== '') {
		$values = $read('new');
		$values['active'] = 1;
		$error += $docs->saveRule(0, $values) > 0 ? 0 : 1;
	}
	$mode = GETPOST('ITTABPDF_DOCS_CONTRACT_CHECK', 'aZ09');
	dolibarr_set_const($db, 'ITTABPDF_DOCS_CONTRACT_CHECK', in_array($mode, array('off', 'warn', 'block'), true) ? $mode : 'warn', 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'ITTABPDF_DOCS_BANNER', GETPOST('ITTABPDF_DOCS_BANNER') ? '1' : '0', 'chaine', 0, '', $conf->entity);
	if (GETPOSTISSET('ITTABPDF_GESCHAEFTSZEITEN')) {
		dolibarr_set_const($db, 'ITTABPDF_GESCHAEFTSZEITEN', trim(GETPOST('ITTABPDF_GESCHAEFTSZEITEN', 'alphanohtml')), 'chaine', 0, '', $conf->entity);
	}
	setEventMessages($error ? $langs->trans('WerkbankDocsRuleError', $langs->transnoentities($docs->error)) : $langs->trans('SetupSaved'), null, $error ? 'errors' : 'mesgs');
	header('Location: '.$self);
	exit;
}

llxHeader('', $langs->trans('WerkbankSetup'));
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('WerkbankSetup'), $linkback, 'title_setup');
print dol_get_fiche_head(ittabpdf_admin_prepare_head(), 'docrules', '', -1, '');

$actions = array();
foreach (WerkbankDocs::ACTIONS as $key) {
	$actions[$key] = $langs->trans('WerkbankDocsAction_'.$key);
}
$scopes = array('' => $langs->trans('WerkbankDocsTypeAny'), 'company' => $langs->trans('WerkbankDocsScope_company'), 'private' => $langs->trans('WerkbankDocsScope_private'));
$categories = array(0 => $langs->trans('WerkbankDocsTypeAny'));
$resql = $db->query("SELECT rowid, label FROM ".MAIN_DB_PREFIX."categorie WHERE type = 2 AND entity IN (".getEntity('category').") ORDER BY label");
while ($resql && ($obj = $db->fetch_object($resql))) {
	$categories[(int) $obj->rowid] = $obj->label;
}
$documents = array();
foreach ($docs->documents() as $slug => $document) {
	$documents[$slug] = $document['title'];
}

/**
 * One row of the rules table.
 *
 * @param string               $suffix     Row id or "new"
 * @param object|null          $rule       Rule or null
 * @param array<string,string> $actions    Actions
 * @param array<string,string> $scopes     Scopes
 * @param array<int|string,string> $categories Customer tags
 * @param array<string,string> $documents  Released documents
 * @return void
 */
function ittabpdf_rule_row($suffix, $rule, $actions, $scopes, $categories, $documents)
{
	global $langs;
	$value = function ($field, $default = '') use ($rule) {
		return $rule ? (string) $rule->$field : $default;
	};
	print '<tr class="oddeven'.($rule && !(int) $rule->active ? ' opacitymedium' : '').'">';
	print '<td><input type="number" name="position_'.$suffix.'" class="width50" value="'.((int) $value('position', '100')).'"></td>';
	print '<td><input type="text" name="label_'.$suffix.'" class="minwidth200" value="'.dol_escape_htmltag($value('label')).'"'.($rule ? '' : ' placeholder="'.dol_escape_htmltag($langs->trans('WerkbankDocsNewRule')).'"').'>';
	print '<br><span class="opacitymedium small">'.$langs->trans('WerkbankDocsRuleSlug').'</span> <input type="text" name="slug_'.$suffix.'" list="ittabpdf-slugs" class="width200" value="'.dol_escape_htmltag($value('slug')).'">';
	$slug = $value('slug');
	if ($slug !== '' && !isset($documents[$slug])) {
		print ' '.img_warning($langs->trans('WerkbankDocsRuleNoDocument'));
	}
	print '</td>';
	print '<td>'.Form::selectarray('action_'.$suffix, $actions, $value('action', 'accept')).'</td>';
	// Conditions, all optional, all must hold.
	print '<td class="nowraponall"><span class="opacitymedium small">'.$langs->trans('WerkbankDocsCondType').'</span> '.Form::selectarray('customertype_'.$suffix, $scopes, $value('customer_type'));
	print '<br><span class="opacitymedium small">'.$langs->trans('WerkbankDocsCondProducts').'</span> <input type="text" name="products_'.$suffix.'" class="minwidth150" placeholder="DL-HOST*,DL-WART*" value="'.dol_escape_htmltag($value('products')).'" title="'.dol_escape_htmltag($langs->trans('WerkbankDocsScopeContractHelp')).'">';
	print '<br><span class="opacitymedium small">'.$langs->trans('WerkbankDocsCondCategory').'</span> '.Form::selectarray('category_'.$suffix, $categories, $value('fk_category', '0'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth150').'</td>';
	print '<td class="center"><input type="checkbox" name="required_'.$suffix.'" value="1"'.($value('required', '1') ? ' checked' : '').'></td>';
	print '<td class="center"><input type="checkbox" name="renew_'.$suffix.'" value="1"'.($value('renew', '0') ? ' checked' : '').'></td>';
	print '<td class="center">'.($rule ? '<input type="checkbox" name="active_'.$suffix.'" value="1"'.((int) $rule->active ? ' checked' : '').'>' : '').'</td>';
	print '</tr>';
}

print '<form method="POST" action="'.$self.'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save_rules">';
print '<datalist id="ittabpdf-slugs">';
foreach ($documents as $slug => $title) {
	print '<option value="'.dol_escape_htmltag($slug).'">'.dol_escape_htmltag($title).'</option>';
}
print '</datalist>';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="width50">'.$langs->trans('Position').'</td><td>'.$langs->trans('WerkbankDocsRuleLabel').'</td><td>'.$langs->trans('WerkbankDocsRuleAction').'</td>'
	.'<td>'.$langs->trans('WerkbankDocsRuleScope').'</td><td class="center">'.$langs->trans('WerkbankQsRequired').'</td><td class="center">'.$langs->trans('WerkbankDocsRuleRenew').'</td>'
	.'<td class="center">'.$langs->trans('Active').'</td></tr>';
foreach ($docs->rules() as $rule) {
	ittabpdf_rule_row((string) $rule->rowid, $rule, $actions, $scopes, $categories, $documents);
}
ittabpdf_rule_row('new', null, $actions, $scopes, $categories, $documents);
print '</table></div>';
print '<div class="opacitymedium">'.$langs->trans('WerkbankDocsRulesHelp').'</div><br>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankDocsShow').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('WerkbankDocsBannerSetting').'</td><td><input type="checkbox" name="ITTABPDF_DOCS_BANNER" value="1"'.(getDolGlobalInt('ITTABPDF_DOCS_BANNER', 1) ? ' checked' : '').'> '.$langs->trans('WerkbankDocsBannerSettingHelp').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('WerkbankDocsContractCheck').'</td><td>'.Form::selectarray('ITTABPDF_DOCS_CONTRACT_CHECK', array(
	'off' => $langs->trans('WerkbankDocsContractCheckOff'), 'warn' => $langs->trans('WerkbankDocsContractCheckWarn'), 'block' => $langs->trans('WerkbankDocsContractCheckBlock')),
	getDolGlobalString('ITTABPDF_DOCS_CONTRACT_CHECK', 'warn')).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('WerkbankVarsHours').'</td><td><input type="text" name="ITTABPDF_GESCHAEFTSZEITEN" class="minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('ITTABPDF_GESCHAEFTSZEITEN')).'"'
	.' placeholder="'.dol_escape_htmltag(WerkbankVars::openingHours()).'"> <span class="opacitymedium">'.$langs->trans('WerkbankVarsHoursHelp').'</span></td></tr>';
print '</table></div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div></form>';

// Placeholders for documents per customer, to copy into the texts.
print '<br>'.load_fiche_titre($langs->trans('WerkbankVarsTitle'), '', '');
print '<div class="opacitymedium">'.$langs->trans('WerkbankVarsHelp').'</div>';
print WerkbankVars::helpTable();

print dol_get_fiche_end();
llxFooter();
$db->close();
