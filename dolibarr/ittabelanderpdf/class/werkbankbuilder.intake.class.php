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

require_once __DIR__.'/werkbankbuilder.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

/**
 * Device intake slip (Geräteannahmeschein) from a ticket, one sheet to print
 * and sign: customer, device, accessories, condition, fault, the questions
 * of the ticket's select fields (access data, backup), optionally a line
 * for the password on paper only (never stored or printed), the notices of the
 * module setup by Sie or Du, two signatures.
 */
class WerkbankBuilderIntake extends WerkbankBuilder
{
	/** Notices by Sie (companies), one per line; the module setup holds the owner's version. */
	const DEFAULT_NOTICES = "Bitte sichern Sie Ihre Daten, bevor Sie mir das Gerät geben. Bei einer Reparatur können Daten verloren gehen, etwa wenn eine Festplatte ohnehin am Ende ist. Ich sehe mir Ihre Daten nur an, wenn der Auftrag es verlangt.\n"
		."Passwort oder PIN brauche ich nur, wenn es ohne nicht geht, zum Beispiel zum Testen nach der Reparatur oder für eine Datenübernahme. Am besten ändern Sie es, wenn Sie das Gerät zurückhaben.\n"
		."Die Fehlersuche kostet 29 €. Beauftragen Sie danach die Reparatur, rechne ich den Betrag an. Mein Angebot ist ein unverbindlicher Kostenvoranschlag: Wird es deutlich teurer, melde ich mich vorher, und Sie entscheiden, ob ich weitermache.\n"
		."Ist Ihr Gerät fertig, sage ich Ihnen Bescheid. Bitte holen Sie es innerhalb von 4 Wochen ab. Danach kann ich nach einer Erinnerung 2 € pro Tag für die Aufbewahrung verlangen.\n"
		."Öffne oder baue ich ein Gerät um, etwa einen Controller, kann die Garantie des Herstellers enden. Die gesetzliche Gewährleistung für meine Arbeit bleibt.\n"
		."Ihre Angaben speichere ich für diesen Auftrag in meinem Kundensystem. Mehr dazu: it.tabelander.co.at/rechtliches/datenschutz\n"
		."Mit Ihrer Unterschrift bestätigen Sie die Angaben oben. Es gelten meine AGB für Unternehmer: it.tabelander.co.at/rechtliches/agb-unternehmer";

	/** Notices by Du (private customers and requests without a third party yet). */
	const DEFAULT_NOTICES_DU = "Bitte sichere deine Daten, bevor du mir das Gerät gibst. Bei einer Reparatur können Daten verloren gehen, etwa wenn eine Festplatte ohnehin am Ende ist. Ich sehe mir deine Daten nur an, wenn der Auftrag es verlangt.\n"
		."Passwort oder PIN brauche ich nur, wenn es ohne nicht geht, zum Beispiel zum Testen nach der Reparatur oder für eine Datenübernahme. Am besten änderst du es, wenn du das Gerät zurückhast.\n"
		."Die Fehlersuche kostet 29 €. Beauftragst du danach die Reparatur, rechne ich den Betrag an. Mein Angebot ist ein unverbindlicher Kostenvoranschlag: Wird es deutlich teurer, melde ich mich vorher, und du entscheidest, ob ich weitermache.\n"
		."Ist dein Gerät fertig, sage ich dir Bescheid. Bitte hol es innerhalb von 4 Wochen ab. Danach kann ich nach einer Erinnerung 2 € pro Tag für die Aufbewahrung verlangen.\n"
		."Öffne oder baue ich ein Gerät um, etwa einen Controller, kann die Garantie des Herstellers enden. Die gesetzliche Gewährleistung für meine Arbeit bleibt.\n"
		."Deine Angaben speichere ich für diesen Auftrag in meinem Kundensystem. Mehr dazu: it.tabelander.co.at/rechtliches/datenschutz\n"
		."Mit deiner Unterschrift bestätigst du die Angaben oben und stimmst meinen Nutzungsbedingungen zu: it.tabelander.co.at/rechtliches/nutzungsbedingungen";

	/** @var array<string,array<string,string>> */
	private $signed;

	/** @var ExtraFields|null */
	private $extrafields;

	/**
	 * @param DoliDB                             $db     Database
	 * @param Ticket                             $object Ticket
	 * @param Translate                          $langs  Output language
	 * @param array<string,array<string,string>> $signed role => image, name, when (signed on the device)
	 */
	public function __construct($db, $object, $langs, array $signed = array())
	{
		parent::__construct($db, $object, $langs);
		$this->signed = $signed;
	}

	/**
	 * @return string
	 */
	public function outputFile()
	{
		return self::pathFor($this->object);
	}

	/**
	 * @param Ticket $ticket Ticket
	 * @return string        <ticket folder>/<REF>-geraeteannahme.pdf
	 */
	public static function pathFor($ticket)
	{
		global $conf;
		$ref = dol_sanitizeFileName($ticket->ref);
		return $conf->ticket->dir_output.'/'.$ref.'/'.$ref.'-geraeteannahme.pdf';
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build()
	{
		$langs = $this->langs;
		$ticket = $this->object;
		if (empty($ticket->array_options) && method_exists($ticket, 'fetch_optionals')) {
			$ticket->fetch_optionals();
		}
		// A request from the website may have no third party yet: the
		// customer fields are then filled in by hand.
		$known = is_object($ticket->thirdparty) && !empty($ticket->thirdparty->id);
		$customer = $known ? $ticket->thirdparty : new Societe($this->db);
		if ($known && empty($customer->array_options)) {
			$customer->fetch_optionals();
		}
		$du = !$known || !empty($customer->array_options['options_ittab_du']);

		// One sheet to print and sign: no address window, the customer is a block of its own.
		$title = $langs->transnoentities('WerkbankIntakeTitle');
		$doc = $this->sheet($title, $title.' '.$ticket->ref);
		$doc['pdf_title'] = $ticket->ref.' '.$title;
		$doc['keywords'] = $ticket->ref.' '.$title;
		$doc['sign_room'] = true;
		$meta = array($langs->transnoentities('WerkbankIntakeRequest').' '.$ticket->ref, dol_print_date(dol_now(), 'day', 'tzuser', $langs));
		if ($known && !empty($customer->code_client) && !getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_CODE')) {
			$meta[] = $langs->transnoentities('WerkbankCustomerCode').' '.$customer->code_client;
		}
		$doc['meta'] = implode(' · ', $meta);

		$value = function ($field) use ($ticket) {
			$key = 'options_'.$field;
			return isset($ticket->array_options[$key]) ? trim((string) $ticket->array_options[$key]) : '';
		};
		$address = $known ? trim(str_replace("\n", ', ', trim((string) $customer->address)).', '.trim($customer->zip.' '.$customer->town), ', ') : '';
		$phone = $known ? (string) (!empty($customer->phone_mobile) ? $customer->phone_mobile : $customer->phone) : '';
		$email = $known && !empty($customer->email) ? (string) $customer->email : (string) $ticket->origin_email;
		$kinds = $this->options('ittab_geraet_art');
		$kind = $value('ittab_geraet_art');

		$questions = array();
		foreach (array('ittab_zugang' => 'WerkbankIntakeAccess', 'ittab_sicherung' => 'WerkbankIntakeBackup') as $field => $label) {
			$options = $this->options($field);
			if (!$options) {
				$options = array('1' => $langs->transnoentities('Yes'), '2' => $langs->transnoentities('No'));
			}
			$selected = array_search($value($field), array_map('strval', array_keys($options)), true);
			$questions[$field] = array('label' => $langs->transnoentities($label), 'options' => array_values($options),
				'selected' => $selected === false ? null : $selected);
		}

		$doc['blocks'] = array(
			array('type' => 'section', 'cap' => $langs->transnoentities('WerkbankIntakeCustomer')),
			array('type' => 'fields', 'rows' => array(
				array($langs->transnoentities('WerkbankIntakeName'), $known ? (string) $customer->name : ''),
				array($langs->transnoentities('WerkbankIntakeAddress'), $address),
				array($langs->transnoentities('WerkbankIntakePhone'), WerkbankBuilder::phone($phone)),
				array($langs->transnoentities('WerkbankIntakeEmail'), $email),
			)),
			array('type' => 'section', 'cap' => $langs->transnoentities('WerkbankIntakeDevice')),
			array('type' => 'fields', 'rows' => array(
				array($langs->transnoentities('WerkbankIntakeKind'), isset($kinds[$kind]) ? $kinds[$kind] : $kind),
				array($langs->transnoentities('WerkbankIntakeModel'), $value('ittab_geraet_modell')),
				array($langs->transnoentities('WerkbankIntakeSerial'), $value('ittab_geraet_sn')),
				array($langs->transnoentities('WerkbankIntakeAccessories'), $value('ittab_zubehoer')),
				array($langs->transnoentities('WerkbankIntakeCondition'), $value('ittab_zustand'), 'wide'),
				// Without a fault description, the request's subject says what it is about.
				array($langs->transnoentities('WerkbankIntakeFault'), $value('ittab_fehler') !== '' ? $value('ittab_fehler') : (string) $ticket->subject, 'wide'),
			)),
			array('type' => 'choices', 'rows' => array($questions['ittab_zugang'])),
		);
		// Only ever a line on paper: the password is never stored or printed.
		if (getDolGlobalInt('ITTABPDF_INTAKE_PASSWORD_FIELD', 0)) {
			$doc['blocks'][] = array('type' => 'fields', 'rows' => array(array($langs->transnoentities('WerkbankIntakePassword'), '', 'wide')));
		}
		$doc['blocks'][] = array('type' => 'choices', 'rows' => array($questions['ittab_sicherung']));
		$doc['blocks'][] = array('type' => 'section', 'cap' => $langs->transnoentities('WerkbankIntakeNotices'));
		$doc['blocks'][] = array('type' => 'notices', 'rows' => self::notices($du));
		$doc['signatures'] = array(
			'left' => $this->signedLine('technician', $langs->transnoentities('WerkbankIntakeSignTechnician', $this->mysoc->name)),
			'right' => $this->signedLine('customer', $langs->transnoentities('WerkbankIntakeSignCustomer')),
		);
		return $doc;
	}

	/**
	 * The notices of the setup (ITTABPDF_INTAKE_NOTICES or _DU), one per
	 * line, variables such as __MYCOMPANY_NAME__ replaced.
	 *
	 * @param bool $du By Du
	 * @return string[]
	 */
	public static function notices($du)
	{
		global $langs;
		$text = $du ? getDolGlobalString('ITTABPDF_INTAKE_NOTICES_DU', self::DEFAULT_NOTICES_DU) : getDolGlobalString('ITTABPDF_INTAKE_NOTICES', self::DEFAULT_NOTICES);
		$substitutions = getCommonSubstitutionArray($langs, 0, array('object', 'objectamount', 'user'));
		$lines = array();
		foreach (preg_split('/\R/', $text) as $line) {
			$line = trim(make_substitutions($line, $substitutions));
			if ($line !== '') {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	/**
	 * Options of a select extra field of tickets (changeable in Dolibarr's
	 * extra field setup), key => label.
	 *
	 * @param string $field Field name
	 * @return array<string,string>
	 */
	private function options($field)
	{
		if ($this->extrafields === null) {
			$this->extrafields = new ExtraFields($this->db);
			$this->extrafields->fetch_name_optionals_label('ticket');
		}
		$param = isset($this->extrafields->attributes['ticket']['param'][$field]['options']) ? $this->extrafields->attributes['ticket']['param'][$field]['options'] : array();
		$options = array();
		foreach (is_array($param) ? $param : array() as $key => $label) {
			if ((string) $key !== '') {
				$options[(string) $key] = (string) $label;
			}
		}
		return $options;
	}

	/**
	 * @param string $role  technician or customer
	 * @param string $label Caption
	 * @return string|array<string,string>
	 */
	private function signedLine($role, $label)
	{
		return isset($this->signed[$role]) ? array('label' => $label) + $this->signed[$role] : $label;
	}
}
