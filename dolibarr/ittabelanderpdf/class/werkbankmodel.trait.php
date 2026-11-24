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

require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once __DIR__.'/werkbankrenderer.class.php';

/**
 * Everything a pdf_werkbank model does, for every document type.
 *
 * Dolibarr expects the class pdf_werkbank in each core/modules/<type>/doc
 * folder. Two classes of the same name cannot live in one PHP process (a cron
 * run that builds a proposal and an invoice), so every model file declares
 * the class only when it does not exist yet, and the class itself works for
 * any type: write_file() picks the builder by $object->element.
 */
trait WerkbankModel
{
	/** Builder file and class per Dolibarr element. */
	private static $werkbankBuilders = array(
		'propal' => array('propal', 'WerkbankBuilderPropal'),
		'facture' => array('facture', 'WerkbankBuilderFacture'),
		'commande' => array('commande', 'WerkbankBuilderCommande'),
		'shipping' => array('expedition', 'WerkbankBuilderExpedition'),
		'fichinter' => array('fichinter', 'WerkbankBuilderFichinter'),
		'contrat' => array('contrat', 'WerkbankBuilderContrat'),
		'supplier_proposal' => array('supplierproposal', 'WerkbankBuilderSupplierProposal'),
		'order_supplier' => array('supplierorder', 'WerkbankBuilderSupplierOrder'),
		'reception' => array('reception', 'WerkbankBuilderReception'),
	);

	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		global $langs, $mysoc;
		$langs->loadLangs(array('main', 'bills', 'ittabelanderpdf@ittabelanderpdf'));
		$this->db = $db;
		$this->name = 'werkbank';
		$this->description = $langs->trans('WerkbankModelDescription');
		$this->version = 'dolibarr';
		$this->update_main_doc_field = 1;
		$this->type = 'pdf';
		$this->page_largeur = WerkbankRenderer::PAGE_W;
		$this->page_hauteur = WerkbankRenderer::PAGE_H;
		$this->format = array($this->page_largeur, $this->page_hauteur);
		$this->marge_gauche = WerkbankRenderer::LEFT;
		$this->marge_droite = WerkbankRenderer::PAGE_W - WerkbankRenderer::RIGHT;
		$this->marge_haute = 12;
		$this->marge_basse = 9;
		$this->corner_radius = 0;
		$this->option_logo = 1;
		$this->option_tva = 1;
		$this->option_modereg = 1;
		$this->option_condreg = 1;
		$this->option_multilang = 1;
		$this->option_escompte = 0;
		$this->option_credit_note = 1;
		$this->option_freetext = 1;
		$this->option_draft_watermark = 1;
		$this->watermark = '';
		$this->emetteur = $mysoc;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Build the PDF of a document onto disk.
	 *
	 * @param CommonObject $object          Document
	 * @param ?Translate   $outputlangs     Output language
	 * @param string       $srctemplatepath Unused
	 * @param int<0,1>     $hidedetails     Unused
	 * @param int<0,1>     $hidedesc        Unused (MAIN_GENERATE_DOCUMENTS_HIDE_DESC is read directly)
	 * @param int<0,1>     $hideref         Unused
	 * @return int<-1,1>                    1 if OK, <=0 if KO
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $langs, $hookmanager, $action;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		$outputlangs->loadLangs(array('main', 'dict', 'companies', 'bills', 'products', 'propal', 'orders', 'sendings',
			'interventions', 'contracts', 'supplier_proposal', 'receptions', 'ittabelanderpdf@ittabelanderpdf'));

		$element = (string) $object->element;
		if (!isset(self::$werkbankBuilders[$element])) {
			$this->error = $outputlangs->transnoentities('WerkbankUnsupported', $element);
			return 0;
		}
		list($file, $class) = self::$werkbankBuilders[$element];
		require_once dirname(__DIR__).'/class/werkbankbuilder.'.$file.'.class.php';

		if (method_exists($object, 'fetch_thirdparty') && empty($object->thirdparty)) {
			$object->fetch_thirdparty();
		}
		/** @var WerkbankBuilder $builder */
		$builder = new $class($this->db, $object, $outputlangs);
		$target = $builder->outputFile();
		if (!is_dir(dirname($target)) && dol_mkdir(dirname($target)) < 0) {
			$this->error = $outputlangs->transnoentities('ErrorCanNotCreateDir', dirname($target));
			return 0;
		}

		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('pdfgeneration'));
		$parameters = array('file' => $target, 'object' => $object, 'outputlangs' => $outputlangs);
		$hookmanager->executeHooks('beforePDFCreation', $parameters, $object, $action);

		try {
			$doc = $builder->build();
			$this->watermark = $doc['watermark'];
			$renderer = new WerkbankRenderer(pdf_getInstance($this->format), $outputlangs);
			$renderer->render($doc, $target);
		} catch (Throwable $exception) {
			dol_syslog('ittabelanderpdf: '.$exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine(), LOG_ERR);
			$this->error = $exception->getMessage();
			return -1;
		}

		$hookmanager->initHooks(array('pdfgeneration'));
		$parameters = array('file' => $target, 'object' => $object, 'outputlangs' => $outputlangs);
		$reshook = $hookmanager->executeHooks('afterPDFCreation', $parameters, $this, $action);
		$this->warnings = $hookmanager->warnings;
		dolChmod($target);
		if ($reshook < 0) {
			$this->error = $hookmanager->error;
			$this->errors = $hookmanager->errors;
			return -1;
		}
		$this->result = array('fullpath' => $target);
		return 1;
	}
}
