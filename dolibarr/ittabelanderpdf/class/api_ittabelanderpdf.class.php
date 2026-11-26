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

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
dol_include_once('/ittabelanderpdf/class/werkbankdoc.class.php');

/**
 * Company documents for the website and the import script.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Ittabelanderpdf extends DolibarrApi
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Released public legal texts
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @url GET unterlagen
	 */
	public function listPublic()
	{
		$store = new WerkbankDoc($this->db);
		$list = array();
		foreach ($store->all(array('public' => 1, 'released' => 1)) as $doc) {
			$list[] = $this->_public($store, $doc);
		}
		return $list;
	}

	/**
	 * One released public legal text with its HTML
	 *
	 * @param string $slug File name without .pdf
	 * @return array<string,mixed>
	 *
	 * @url GET unterlagen/{slug}
	 * @throws RestException 404
	 */
	public function getPublic($slug)
	{
		$store = new WerkbankDoc($this->db);
		$doc = $store->bySlug((string) $slug);
		if (!$doc || !(int) $doc->public || (int) $doc->version === 0) {
			throw new RestException(404, 'Not found');
		}
		$version = $store->version((int) $doc->rowid);
		return $this->_public($store, $doc) + array('html' => (string) $version->text);
	}

	/**
	 * The PDF of a released public legal text
	 *
	 * @param string $slug File name without .pdf
	 * @return array<string,string>
	 *
	 * @url GET unterlagen/{slug}/pdf
	 * @throws RestException 404
	 */
	public function getPdf($slug)
	{
		$store = new WerkbankDoc($this->db);
		$doc = $store->bySlug((string) $slug);
		$file = $doc ? WerkbankUnterlagen::dir().'/'.$doc->slug.'.pdf' : '';
		if (!$doc || !(int) $doc->public || (int) $doc->version === 0 || !is_readable($file)) {
			throw new RestException(404, 'Not found');
		}
		return array('filename' => $doc->slug.'.pdf', 'content-type' => 'application/pdf', 'encoding' => 'base64',
			'content' => base64_encode((string) file_get_contents($file)));
	}

	/**
	 * All documents with their draft (for the import script)
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @url GET unterlagen-entwuerfe
	 * @throws RestException 403
	 */
	public function listDrafts()
	{
		$this->_needs('write');
		$store = new WerkbankDoc($this->db);
		$list = array();
		foreach ($store->all() as $doc) {
			$list[] = array('id' => (int) $doc->rowid, 'slug' => $doc->slug, 'title' => $doc->title, 'kind' => $doc->kind, 'public' => (int) $doc->public,
				'version' => (int) $doc->version, 'changed' => (int) $doc->changed, 'text' => (string) $doc->text);
		}
		return $list;
	}

	/**
	 * Create or change the draft of a document (never releases it)
	 *
	 * @param string $slug    File name without .pdf
	 * @param array  $request_data title, kind, public, sendable, text
	 * @return array<string,mixed>
	 *
	 * @url PUT unterlagen-entwuerfe/{slug}
	 * @throws RestException 400
	 * @throws RestException 403
	 */
	public function putDraft($slug, $request_data = null)
	{
		$this->_needs('write');
		$data = is_array($request_data) ? $request_data : array();
		$store = new WerkbankDoc($this->db);
		$doc = $store->bySlug((string) $slug);
		$values = array('slug' => (string) $slug, 'title' => isset($data['title']) ? (string) $data['title'] : ($doc ? $doc->title : ''),
			'kind' => isset($data['kind']) ? (string) $data['kind'] : ($doc ? $doc->kind : 'internal'),
			'public' => isset($data['public']) ? (int) $data['public'] : ($doc ? (int) $doc->public : 0),
			'text' => isset($data['text']) ? (string) $data['text'] : ($doc ? (string) $doc->text : ''), 'position' => $doc ? (int) $doc->position : 0);
		if (isset($data['sendable'])) {
			$values['sendable'] = (int) $data['sendable'];
		}
		$id = $store->save($doc ? (int) $doc->rowid : 0, $values, DolibarrApiAccess::$user);
		if ($id < 0) {
			throw new RestException(400, $store->error);
		}
		$saved = $store->get($id);
		return array('id' => $id, 'slug' => $saved->slug, 'version' => (int) $saved->version, 'changed' => (int) $saved->changed);
	}

	/**
	 * The documents one customer needs or may get, for the customer area of the website
	 *
	 * The website checks that the customer belongs to its session.
	 *
	 * @param int $socid Customer
	 * @return array<int,array<string,mixed>>
	 *
	 * @url GET kunden/{socid}/unterlagen
	 * @throws RestException 403
	 * @throws RestException 404
	 */
	public function listCustomer($socid)
	{
		list($soc, $docs) = $this->_customer($socid);
		$list = array();
		foreach ($this->_rows($soc, $docs) as $entry) {
			$list[] = $this->_item($soc, $docs, $entry);
		}
		return $list;
	}

	/**
	 * The PDF of one of these documents: signed, else made for the customer, else the general one
	 *
	 * @param int    $socid Customer
	 * @param string $slug  Document
	 * @return array<string,string>
	 *
	 * @url GET kunden/{socid}/unterlagen/{slug}/pdf
	 * @throws RestException 403
	 * @throws RestException 404
	 */
	public function getCustomerPdf($socid, $slug)
	{
		list($soc, $docs) = $this->_customer($socid);
		$entry = $this->_entry($soc, $docs, $slug);
		list(, $path) = $this->_file($soc, $docs, $entry);
		if ($path === '') {
			throw new RestException(404, 'No file');
		}
		$types = array('pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png');
		return array('filename' => basename($path), 'content-type' => $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))], 'encoding' => 'base64',
			'content' => base64_encode((string) file_get_contents($path)));
	}

	/**
	 * Confirm a document (AGB, Nutzungsbedingungen) in the customer area
	 *
	 * @param int    $socid        Customer
	 * @param string $slug         Document
	 * @param array  $request_data name, email, ip, version (the "Stand" the customer saw, Y-m-d)
	 * @return array<string,mixed>
	 *
	 * @url POST kunden/{socid}/unterlagen/{slug}/bestaetigen
	 * @throws RestException 400
	 * @throws RestException 403
	 * @throws RestException 404
	 * @throws RestException 409
	 * @throws RestException 500
	 */
	public function confirmCustomer($socid, $slug, $request_data = null)
	{
		list($soc, $docs) = $this->_customer($socid);
		$entry = $this->_entry($soc, $docs, $slug);
		$data = is_array($request_data) ? $request_data : array();
		list($name, $email, $ip) = $this->_who($data);
		$item = $this->_item($soc, $docs, $entry);
		if (!$item['can_confirm']) {
			throw new RestException(409, 'Not to confirm');
		}
		$this->_sameStand($entry, $data);
		$note = 'Im Kundenbereich bestätigt: '.$name.' <'.$email.'>, IP '.($ip !== '' ? $ip : 'unbekannt').$this->_standNote($entry);
		$id = $docs->record((int) $soc->id, (string) $entry['rule']->slug, 'signed', 'portal', DolibarrApiAccess::$user,
			array('fk_rule' => (int) $entry['rule']->rowid, 'version' => (string) $entry['stand'], 'note' => $note));
		if ($id <= 0) {
			throw new RestException(500, $docs->error);
		}
		return $this->_item($soc, $docs, $this->_entry($soc, $docs, $slug));
	}

	/**
	 * Sign a document made for the customer (AVV, Fernwartung) in the customer area
	 *
	 * The customer signs first; the counter signature follows on the device,
	 * until then the document stays "Unterschrift läuft".
	 *
	 * @param int    $socid        Customer
	 * @param string $slug         Document
	 * @param array  $request_data name, email, ip, version, png (base64 without "data:" and without "=")
	 * @return array<string,mixed>
	 *
	 * @url POST kunden/{socid}/unterlagen/{slug}/unterschreiben
	 * @throws RestException 400
	 * @throws RestException 403
	 * @throws RestException 404
	 * @throws RestException 409
	 * @throws RestException 500
	 */
	public function signCustomer($socid, $slug, $request_data = null)
	{
		global $conf;
		dol_include_once('/ittabelanderpdf/class/werkbanksignature.class.php');
		list($soc, $docs) = $this->_customer($socid);
		$entry = $this->_entry($soc, $docs, $slug);
		$data = is_array($request_data) ? $request_data : array();
		list($name, $email, $ip) = $this->_who($data);
		$png = isset($data['png']) && is_string($data['png']) ? $data['png'] : '';
		// Checked before anything is written: a bad image leaves no half begun signature.
		if (WerkbankSignature::decode($png) === '') {
			throw new RestException(400, 'Invalid signature image');
		}
		$item = $this->_item($soc, $docs, $entry);
		if (!$item['can_sign']) {
			throw new RestException(409, 'Not to sign');
		}
		$this->_sameStand($entry, $data);
		$note = 'Im Kundenbereich unterschrieben: '.$name.' <'.$email.'>, IP '.($ip !== '' ? $ip : 'unbekannt').$this->_standNote($entry);
		$generated = $entry['generated'];
		$signing = $entry['signing'];
		// A signature begun on the device for this very file is continued.
		if ($signing && !($signing->element === 'docgen' && (int) $signing->fk_object === (int) $generated->rowid)) {
			throw new RestException(409, 'Signature begun for another file');
		}
		$id = $signing ? (int) $signing->rowid : $docs->record((int) $soc->id, (string) $entry['rule']->slug, 'signing', 'portal', DolibarrApiAccess::$user,
			array('fk_rule' => (int) $entry['rule']->rowid, 'element' => 'docgen', 'fk_object' => (int) $generated->rowid, 'note' => $note));
		if ($id <= 0) {
			throw new RestException(500, $docs->error);
		}
		if ($signing) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_docevent SET note = '".$this->db->escape($note)."' WHERE rowid = ".((int) $id));
		}
		$signature = new WerkbankSignature($this->db);
		if ($signature->save('doc', $id, 'customer', $name, $png, DolibarrApiAccess::$user) < 0) {
			// Begun here and failed: no "Unterschrift läuft" without a signature (a signing has no agenda event).
			if (!$signing) {
				$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_signature WHERE kind = 'doc' AND fk_object = ".((int) $id)." AND entity = ".((int) $conf->entity));
				$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."ittabpdf_docevent WHERE rowid = ".((int) $id)." AND state = 'signing'");
			}
			throw new RestException(500, $signature->error);
		}
		// The address the customer signed from, not the website's.
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."ittabpdf_signature SET ip = '".$this->db->escape($ip)."' WHERE kind = 'doc' AND fk_object = ".((int) $id)
			." AND role = 'customer' AND entity = ".((int) $conf->entity));
		return $this->_item($soc, $docs, $this->_entry($soc, $docs, $slug));
	}

	/**
	 * @param int $socid Customer
	 * @return array{0:Societe,1:WerkbankDocs}
	 * @throws RestException 403
	 * @throws RestException 404
	 */
	private function _customer($socid)
	{
		$this->_needs('read');
		$socid = (int) $socid;
		// An external user sees only its own third party.
		if (DolibarrApiAccess::$user->socid > 0 && (int) DolibarrApiAccess::$user->socid !== $socid) {
			throw new RestException(404, 'Not found');
		}
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		dol_include_once('/ittabelanderpdf/class/werkbankdocs.class.php');
		$soc = new Societe($this->db);
		if ($socid <= 0 || $soc->fetch($socid) <= 0 || !in_array((string) $soc->entity, explode(',', getEntity('societe')), true)) {
			throw new RestException(404, 'Not found');
		}
		return array($soc, new WerkbankDocs($this->db));
	}

	/**
	 * The status rows of the customer, one per document (two rules for one
	 * document show once; required if one of them requires it).
	 *
	 * @param Societe      $soc  Customer
	 * @param WerkbankDocs $docs Documents
	 * @return array<string,array<string,mixed>> slug => status row
	 */
	private function _rows($soc, $docs)
	{
		$rows = array();
		foreach ($docs->status($soc) as $entry) {
			$slug = (string) $entry['rule']->slug;
			if (!isset($rows[$slug])) {
				$entry['required'] = (int) $entry['rule']->required;
				$rows[$slug] = $entry;
			} elseif ((int) $entry['rule']->required) {
				$rows[$slug]['required'] = 1;
			}
		}
		return $rows;
	}

	/**
	 * The status row of one document of this customer; any other slug is not there.
	 *
	 * @param Societe      $soc  Customer
	 * @param WerkbankDocs $docs Documents
	 * @param string       $slug Document
	 * @return array<string,mixed>
	 * @throws RestException 404
	 */
	private function _entry($soc, $docs, $slug)
	{
		$rows = $this->_rows($soc, $docs);
		if (!is_string($slug) || !isset($rows[$slug])) {
			throw new RestException(404, 'Not found');
		}
		return $rows[$slug];
	}

	/**
	 * What the customer area shows of a row, from a whitelist.
	 *
	 * @param Societe             $soc   Customer
	 * @param WerkbankDocs        $docs  Documents
	 * @param array<string,mixed> $entry Status row
	 * @return array<string,mixed>
	 */
	private function _item($soc, $docs, array $entry)
	{
		$rule = $entry['rule'];
		$generated = $this->_ownFile($soc, $entry['generated'] ? (string) $entry['generated']->filename : '');
		$customerSigned = false;
		if ($entry['signing']) {
			dol_include_once('/ittabelanderpdf/class/werkbanksignature.class.php');
			$signature = new WerkbankSignature($this->db);
			$loaded = $signature->load('doc', (int) $entry['signing']->rowid);
			$customerSigned = $loaded && isset($signature->signatures('doc', (int) $entry['signing']->rowid, $loaded)['customer']);
		}
		$open = !$entry['done'] || $entry['state'] === 'outdated';
		list($kind) = $this->_file($soc, $docs, $entry);
		$done = $entry['done'];
		return array(
			'slug' => (string) $rule->slug,
			'title' => (string) $entry['title'],
			'label' => (string) $rule->label,
			'stand' => (string) $entry['stand'],
			'state' => (string) $entry['state'],
			'action' => (string) $rule->action,
			'required' => (bool) $entry['required'],
			// AGB, Nutzungsbedingungen: the general PDF the customer reads, then confirms.
			'can_confirm' => $rule->action === 'accept' && $open && !$entry['signing'] && $kind === 'general',
			// AVV, Fernwartung: the PDF made for this customer, signed here first.
			'can_sign' => $rule->action === 'sign' && !$done && $generated !== '' && !$customerSigned,
			'waiting_countersign' => $customerSigned,
			'done' => $done ? array('date' => dol_print_date($this->db->jdate($done->date_event), '%Y-%m-%d'), 'source' => (string) $done->source,
				'version' => (string) $done->version) : null,
			'pdf' => $kind,
			'files' => array('generated' => $generated !== '', 'signed' => $kind === 'signed'),
		);
	}

	/**
	 * The file the customer may download: the signed one (done), else the one
	 * made for the customer, else the general PDF. Never a blank template per
	 * customer.
	 *
	 * @param Societe             $soc   Customer
	 * @param WerkbankDocs        $docs  Documents
	 * @param array<string,mixed> $entry Status row
	 * @return array{0:string,1:string} kind (signed, generated, general, ''), path
	 */
	private function _file($soc, $docs, array $entry)
	{
		if ($entry['done'] && $entry['state'] === 'done') {
			$path = $this->_ownFile($soc, (string) $entry['done']->filename);
			if ($path !== '') {
				return array('signed', $path);
			}
		}
		if ($entry['generated']) {
			$path = $this->_ownFile($soc, (string) $entry['generated']->filename);
			if ($path !== '' && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf') {
				return array('generated', $path);
			}
		}
		$documents = $docs->documents();
		$slug = (string) $entry['rule']->slug;
		if (isset($documents[$slug]) && $documents[$slug]['kind'] !== 'customer' && $documents[$slug]['sendable'] && $entry['exists']) {
			return array('general', WerkbankUnterlagen::dir().'/'.$slug.'.pdf');
		}
		return array('', '');
	}

	/**
	 * A file of an event, only from this customer's own documents and only PDF, JPG or PNG.
	 *
	 * @param Societe $soc      Customer
	 * @param string  $filename File name of the event
	 * @return string           Path, '' when there is none
	 */
	private function _ownFile($soc, $filename)
	{
		dol_include_once('/ittabelanderpdf/class/werkbanksignature.class.php');
		if ($filename === '' || basename($filename) !== $filename || !preg_match('/^[A-Za-z0-9._ -]+\.(pdf|jpe?g|png)$/i', $filename)) {
			return '';
		}
		$dir = realpath(WerkbankSignature::thirdpartyDir((int) $soc->id));
		$path = $dir ? realpath($dir.'/'.$filename) : false;
		return $path && dirname($path) === $dir && is_readable($path) ? $path : '';
	}

	/**
	 * Name, e-mail and address of the customer acting, checked.
	 *
	 * @param array<string,mixed> $data Request
	 * @return array{0:string,1:string,2:string}
	 * @throws RestException 400
	 */
	private function _who(array $data)
	{
		$name = isset($data['name']) && is_string($data['name']) ? trim((string) preg_replace('/\s+/u', ' ', $data['name'])) : '';
		$email = isset($data['email']) && is_string($data['email']) ? trim($data['email']) : '';
		$ip = isset($data['ip']) && is_string($data['ip']) ? trim($data['ip']) : '';
		if (dol_strlen($name) < 2 || dol_strlen($name) > 128 || preg_match('/[<>]/', $name)) {
			throw new RestException(400, 'Name missing');
		}
		if (strlen($email) > 254 || !isValidEmail($email)) {
			throw new RestException(400, 'E-mail invalid');
		}
		if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
			throw new RestException(400, 'IP invalid');
		}
		return array($name, $email, $ip);
	}

	/**
	 * The customer saw the current "Stand"; a newer one in between is refused.
	 *
	 * @param array<string,mixed> $entry Status row
	 * @param array<string,mixed> $data  Request
	 * @return void
	 * @throws RestException 409
	 */
	private function _sameStand(array $entry, array $data)
	{
		$seen = isset($data['version']) && is_string($data['version']) ? $data['version'] : '';
		if ($seen !== (string) $entry['stand']) {
			throw new RestException(409, 'Document changed');
		}
	}

	/**
	 * @param array<string,mixed> $entry Status row
	 * @return string ", Stand 20.11.2026" or ''
	 */
	private function _standNote(array $entry)
	{
		return (string) $entry['stand'] !== '' ? ', Stand '.dol_print_date(dol_stringtotime($entry['stand'].' 00:00:00'), '%d.%m.%Y') : '';
	}

	/**
	 * @param WerkbankDoc $store Store
	 * @param object      $doc   Document
	 * @return array<string,mixed>
	 */
	private function _public($store, $doc)
	{
		return array('slug' => (string) $doc->slug, 'title' => (string) $doc->title, 'kind' => (string) $doc->kind, 'version' => (int) $doc->version,
			'stand' => dol_print_date($this->db->jdate($doc->date_release), '%Y-%m-%d'), 'pdf' => is_readable(WerkbankUnterlagen::dir().'/'.$doc->slug.'.pdf'));
	}

	/**
	 * @param string $right read or write
	 * @return void
	 * @throws RestException 403
	 */
	private function _needs($right)
	{
		if (!DolibarrApiAccess::$user->hasRight('ittabelanderpdf', 'unterlagen', $right)) {
			throw new RestException(403, 'Missing right ittabelanderpdf unterlagen '.$right);
		}
	}
}
