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
 * The module's e-mail variables. Dolibarr calls this for every substitution
 * (module_parts substitutions = 1): in the e-mail form, when an e-mail is
 * sent, and for PDF texts. The document blocks are built only for e-mails
 * (mode "formemail"), so a PDF never pays for them.
 *
 * @param array<string,string> $substitutionarray Variables, completed in place
 * @param Translate            $langs             Language
 * @param ?CommonObject        $object            Document
 * @param ?array<string,mixed> $parameters        mode = formemail for e-mails
 * @return void
 */
function ittabelanderpdf_completesubstitutionarray(&$substitutionarray, $langs, $object, $parameters = null)
{
	global $mysoc;
	require_once dol_buildpath('/ittabelanderpdf/class/werkbankmaildata.class.php', 0);

	$substitutionarray['__ITTAB_INHABER__'] = (string) $mysoc->managers;
	// Light logo alone for hand-sent mails, light and dark for the automatic send.
	$substitutionarray['__ITTAB_LOGO__'] = WerkbankMailData::logoHtml(is_array($parameters) && !empty($parameters['ittab_auto']));
	$substitutionarray['__ITTAB_TELEFON__'] = WerkbankBuilder::phone(!empty($mysoc->phone_mobile) ? $mysoc->phone_mobile : $mysoc->phone);
	$substitutionarray['__ITTAB_WEB__'] = WerkbankBuilder::bareUrl((string) $mysoc->url);
	$substitutionarray['__ITTAB_ADRESSE__'] = dol_escape_htmltag(trim(str_replace("\n", ', ', trim((string) $mysoc->address)).', '.trim($mysoc->zip.' '.$mysoc->town), ', '));
	$substitutionarray['__ITTAB_TELEFON_LINK__'] = WerkbankMailData::phoneLink(!empty($mysoc->phone_mobile) ? $mysoc->phone_mobile : $mysoc->phone);
	$legal = array_filter(array((string) $mysoc->idprof4, (string) $mysoc->idprof6, !empty($mysoc->idprof2) ? 'Gerichtsstand '.$mysoc->idprof2 : ''), 'strlen');
	$substitutionarray['__ITTAB_RECHTLICHES__'] = $legal ? '<br>'.dol_escape_htmltag(implode(' · ', $legal)) : '';
	$substitutionarray['__ITTAB_KUNDENBEREICH_URL__'] = WerkbankMailData::portalUrl($object);
	// Customer area of the website on or off (ITTABPDF_PORTAL_ENABLED): the
	// proposal e-mails show the button and mention it only when it is on.
	require_once dol_buildpath('/ittabelanderpdf/class/werkbankmailtemplates.class.php', 0);
	$portal = getDolGlobalInt('ITTABPDF_PORTAL_ENABLED');
	$substitutionarray['__ITTAB_KUNDENBEREICH_BLOCK__'] = $portal ? WerkbankMailTemplates::portalButton($substitutionarray['__ITTAB_KUNDENBEREICH_URL__']) : '';
	$substitutionarray['__ITTAB_ANNAHME_SATZ__'] = $portal
		? 'Sie können es online im Kundenbereich annehmen oder einfach auf diese E-Mail antworten. Ich fange erst an, wenn Ihre Zusage da ist.'
		: 'Wenn alles passt, antworten Sie einfach auf diese E-Mail. Ich fange erst an, wenn Ihre Zusage da ist.';
	$substitutionarray['__ITTAB_ANNAHME_SATZ_DU__'] = $portal
		? 'Du kannst es online im Kundenbereich annehmen oder einfach auf diese Mail antworten. Ich fange erst an, wenn deine Zusage da ist.'
		: 'Wenn alles passt, antworte einfach auf diese Mail. Ich fange erst an, wenn deine Zusage da ist.';
	// How to ask: by e-mail, or in the customer area when it is on. No phone.
	$substitutionarray['__ITTAB_FRAGEN_SATZ__'] = $portal
		? 'Bei Fragen antworten Sie einfach auf diese E-Mail oder schreiben Sie mir im Kundenbereich.'
		: 'Bei Fragen antworten Sie einfach auf diese E-Mail.';
	$substitutionarray['__ITTAB_FRAGEN_SATZ_DU__'] = $portal
		? 'Bei Fragen antworte einfach auf diese Mail oder schreib mir im Kundenbereich.'
		: 'Bei Fragen antworte einfach auf diese Mail.';

	// Company documents of the e-mail: the mail form puts in the attached ones (WerkbankDocMail).
	if (!isset($substitutionarray['__ITTAB_UNTERLAGEN_LISTE__'])) {
		require_once dol_buildpath('/ittabelanderpdf/class/werkbankdocmail.class.php', 0);
		$substitutionarray = array_merge($substitutionarray, WerkbankDocMail::fromItems(array()));
	}

	if (!is_array($parameters) || ($parameters['mode'] ?? '') !== 'formemail' || !is_object($object)) {
		$substitutionarray['__ITTAB_HALLO__'] = 'Hallo,';
		$substitutionarray['__ITTAB_GUTENTAG__'] = 'Guten Tag,';
		$substitutionarray['__ITTAB_FAKTEN__'] = '';
		$substitutionarray['__ITTAB_UEBERWEISUNG__'] = '';
		$substitutionarray['__ITTAB_SENDUNG__'] = '';
		return;
	}
	if (method_exists($object, 'fetch_thirdparty') && empty($object->thirdparty) && !($object instanceof Societe)) {
		$object->fetch_thirdparty();
	}
	list($substitutionarray['__ITTAB_HALLO__'], $substitutionarray['__ITTAB_GUTENTAG__']) = WerkbankMailData::greetings($object);
	$substitutionarray['__ITTAB_FAKTEN__'] = WerkbankMailData::facts($object, $langs);
	$substitutionarray['__ITTAB_UEBERWEISUNG__'] = WerkbankMailData::transfer($object, $langs);
	$substitutionarray['__ITTAB_SENDUNG__'] = WerkbankMailData::shipment($object, $langs);
}
