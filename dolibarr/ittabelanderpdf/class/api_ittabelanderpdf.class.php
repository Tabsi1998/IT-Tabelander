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
