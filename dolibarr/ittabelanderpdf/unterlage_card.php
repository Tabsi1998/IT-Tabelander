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
 * Card of a company document (Unterlage): the draft (title, file name,
 * kind, public, text), "Freigeben" as a new version with a note, the
 * versions with their text and archived PDF, a preview of the draft.
 */

$res = 0;
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
dol_include_once('/ittabelanderpdf/class/werkbankdoc.class.php');

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

$langs->loadLangs(array('ittabelanderpdf@ittabelanderpdf'));
if (!$user->hasRight('ittabelanderpdf', 'unterlagen', 'read') || !empty($user->socid)) {
	accessforbidden();
}
$write = $user->hasRight('ittabelanderpdf', 'unterlagen', 'write');
$store = new WerkbankDoc($db);
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$doc = $id > 0 ? $store->get($id) : null;
if ($id > 0 && !$doc) {
	accessforbidden();
}
$self = $_SERVER['PHP_SELF'];

/*
 * Actions
 */

if (in_array($action, array('add', 'update', 'confirm_release'), true) && !$write) {
	accessforbidden();
}
if ($action === 'add' || $action === 'update') {
	$values = array('title' => GETPOST('title', 'alphanohtml'), 'slug' => GETPOST('slug', 'alphanohtml'), 'kind' => GETPOST('kind', 'aZ09'),
		'public' => GETPOST('public'), 'text' => GETPOST('text', 'restricthtml'), 'position' => GETPOSTINT('position'));
	$saved = $store->save($action === 'add' ? 0 : $id, $values, $user);
	if ($saved > 0) {
		setEventMessages($langs->trans('WerkbankDocSaved'), null, 'mesgs');
		header('Location: '.$self.'?id='.$saved);
		exit;
	}
	setEventMessages($langs->trans($store->error), null, 'errors');
	$action = $action === 'add' ? 'create' : '';
}
if ($action === 'confirm_release' && $doc && GETPOST('confirm', 'alpha') === 'yes') {
	$number = $store->release($id, GETPOST('note', 'alphanohtml'), $user);
	if ($number > 0) {
		setEventMessages($langs->trans('WerkbankDocReleased', $number), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('WerkbankDocReleaseFailed', $langs->transnoentities($store->error)), null, 'errors');
	}
	header('Location: '.$self.'?id='.$id);
	exit;
}
// The draft as PDF, without releasing it.
if ($action === 'preview' && $doc) {
	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
	dol_include_once('/ittabelanderpdf/class/werkbankbuilder.unterlage.class.php');
	dol_include_once('/ittabelanderpdf/class/werkbankrenderer.class.php');
	$record = (object) array('id' => (int) $doc->rowid, 'element' => 'ittabpdf_doc', 'table_element' => 'ittabpdf_doc', 'entity' => (int) $doc->entity,
		'ref' => $doc->slug, 'question' => $doc->title, 'answer' => (string) $doc->text, 'status' => 0, 'tms' => dol_now(),
		'array_options' => array('options_ittab_slug' => $doc->slug));
	$temp = $conf->user->dir_temp.'/ittabpdf-preview-'.((int) $user->id).'.pdf';
	dol_mkdir(dirname($temp));
	$builder = new WerkbankBuilderUnterlage($db, $record, $langs, dol_now());
	$built = $builder->build();
	$built['watermark'] = $langs->transnoentities('WerkbankDocPreviewMark');
	$renderer = new WerkbankRenderer(pdf_getInstance(array(WerkbankRenderer::PAGE_W, WerkbankRenderer::PAGE_H)), $langs);
	$renderer->render($built, $temp);
	top_httphead('application/pdf');
	header('Content-Disposition: inline; filename="'.$doc->slug.'-entwurf.pdf"');
	readfile($temp);
	dol_delete_file($temp, 0, 1);
	exit;
}

/*
 * View
 */

$form = new Form($db);
llxHeader('', $doc ? $doc->title : $langs->trans('WerkbankDocNew'), '', '', 0, 0, array(), array(), '', 'mod-ittabelanderpdf page-unterlage');
print load_fiche_titre($doc ? dol_escape_htmltag($doc->title) : $langs->trans('WerkbankDocNew'),
	'<a href="'.dol_buildpath('/ittabelanderpdf/unterlagen_list.php', 1).'">'.$langs->trans('BackToList').'</a>', 'pdf');

if ($action === 'askrelease' && $doc) {
	print $form->formconfirm($self.'?id='.$id, $langs->trans('WerkbankDocRelease'), $langs->trans('WerkbankDocReleaseConfirm', (int) $doc->version + 1), 'confirm_release',
		array(array('type' => 'text', 'name' => 'note', 'label' => $langs->trans('WerkbankDocReleaseNote'), 'value' => '', 'morecss' => 'minwidth300')), 'yes', 1);
}

if ($doc) {
	$state = (int) $doc->version === 0 ? 'WerkbankDocStateDraft' : ((int) $doc->changed ? 'WerkbankDocStateChanged' : 'WerkbankDocStateReleased');
	print '<div class="info">'.$langs->trans($state).((int) $doc->version > 0 ? ' · '.$langs->trans('WerkbankDocCurrent', (int) $doc->version, dol_print_date($db->jdate($doc->date_release), 'day')) : '').'</div>';
}

$editable = $write && ($action === 'create' || $doc);
print '<form method="POST" action="'.$self.($doc ? '?id='.$id : '').'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.($doc ? 'update' : 'add').'">';
print '<table class="border centpercent tableforfield">';
$value = function ($field, $default = '') use ($doc) {
	return GETPOSTISSET($field) ? GETPOST($field, $field === 'text' ? 'restricthtml' : 'alphanohtml') : ($doc ? (string) $doc->$field : $default);
};
print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('WerkbankDocTitle').'</td><td><input type="text" name="title" class="minwidth400" value="'.dol_escape_htmltag($value('title')).'"'.($editable ? '' : ' disabled').'></td></tr>';
print '<tr><td>'.$langs->trans('WerkbankDocsFile').'</td><td><input type="text" name="slug" class="minwidth300" value="'.dol_escape_htmltag($value('slug')).'"'.($editable ? '' : ' disabled').'> <span class="opacitymedium">.pdf</span>'
	.'<div class="opacitymedium small">'.$langs->trans('WerkbankDocSlugHelp').'</div></td></tr>';
$kinds = array();
foreach (WerkbankDoc::KINDS as $key) {
	$kinds[$key] = $langs->trans('WerkbankDocKind_'.$key);
}
print '<tr><td>'.$langs->trans('WerkbankDocKind').'</td><td>'.$form->selectarray('kind', $kinds, $value('kind', 'internal'), 0, 0, 0, $editable ? '' : 'disabled').'</td></tr>';
print '<tr><td>'.$langs->trans('WerkbankDocPublic').'</td><td><input type="checkbox" name="public" value="1"'.($value('public') ? ' checked' : '').($editable ? '' : ' disabled').'> '.$langs->trans('WerkbankDocPublicHelp').'</td></tr>';
print '<tr><td>'.$langs->trans('Position').'</td><td><input type="number" name="position" class="width75" value="'.((int) $value('position', '0')).'"'.($editable ? '' : ' disabled').'></td></tr>';
print '<tr><td class="tdtop">'.$langs->trans('WerkbankDocText').'</td><td>';
if ($editable) {
	$editor = new DolEditor('text', $value('text'), '', 480, 'dolibarr_notes', 'In', true, false, isModEnabled('fckeditor'), ROWS_9, '95%');
	print $editor->Create(1);
	print '<div class="opacitymedium small">'.$langs->trans('WerkbankDocTextHelp').'</div>';
} else {
	print '<div class="ittabpdf-doctext">'.dol_htmlwithnojs((string) $value('text')).'</div>';
}
print '</td></tr></table>';
if ($editable) {
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
}
print '</form>';

if ($doc) {
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$self.'?id='.$id.'&action=preview&token='.newToken().'" target="_blank">'.$langs->trans('WerkbankDocPreview').'</a>';
	if ($write) {
		print '<a class="butAction" href="'.$self.'?id='.$id.'&action=askrelease&token='.newToken().'">'.$langs->trans('WerkbankDocRelease').'</a>';
	}
	print '</div>';

	// Versions: what was released when, by whom, why - with the text and the PDF of the time.
	$versions = $store->versions($id);
	print load_fiche_titre($langs->trans('WerkbankDocVersions'), '', '');
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('WerkbankDocVersion').'</td><td>'.$langs->trans('WerkbankDocStand').'</td><td>'.$langs->trans('User').'</td>'
		.'<td>'.$langs->trans('WerkbankDocReleaseNote').'</td><td></td></tr>';
	if (!$versions) {
		print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('WerkbankDocNoVersion').'</span></td></tr>';
	}
	$show = GETPOSTINT('showversion');
	foreach ($versions as $version) {
		$archived = WerkbankUnterlagen::dir().'/Archiv/'.$version->filename;
		print '<tr class="oddeven"><td>v'.((int) $version->version).'</td><td>'.dol_print_date($db->jdate($version->date_release), 'dayhour').'</td>';
		print '<td>'.dol_escape_htmltag(trim($version->firstname.' '.$version->lastname) ?: (string) $version->login).'</td><td>'.dol_escape_htmltag((string) $version->note).'</td>';
		print '<td class="right nowraponall"><a href="'.$self.'?id='.$id.'&showversion='.((int) $version->version).'#version">'.$langs->trans('WerkbankDocShowText').'</a>';
		if ((string) $version->filename !== '' && is_file($archived)) {
			print ' · <a href="'.DOL_URL_ROOT.'/document.php?modulepart=ecm&amp;file='.urlencode(WerkbankUnterlagen::relativeDir().'/Archiv/'.$version->filename).'">'.img_picto('', 'pdf').' PDF</a>';
		}
		print '</td></tr>';
		if ($show === (int) $version->version) {
			print '<tr id="version"><td colspan="5"><div class="ittabpdf-doctext"><strong>'.dol_escape_htmltag($version->title).'</strong>'.dol_htmlwithnojs((string) $version->text).'</div></td></tr>';
		}
	}
	print '</table></div>';
}

llxFooter();
$db->close();
