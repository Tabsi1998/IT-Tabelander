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

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Device intake slip of a ticket: writes the PDF into the ticket's documents.
 */
class WerkbankIntake
{
	/** @var DoliDB */
	private $db;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param Ticket                             $ticket Ticket (fetched)
	 * @param array<string,array<string,string>> $signed role => image, name, when (signed on the device)
	 * @return string                                    Path, '' on error
	 */
	public function write($ticket, array $signed = array())
	{
		global $langs;
		require_once __DIR__.'/werkbankbuilder.intake.class.php';
		require_once __DIR__.'/werkbankrenderer.class.php';
		$langs->loadLangs(array('main', 'ticket', 'ittabelanderpdf@ittabelanderpdf'));
		if (empty($ticket->thirdparty) && !empty($ticket->fk_soc)) {
			$ticket->fetch_thirdparty();
		}
		$target = WerkbankBuilderIntake::pathFor($ticket);
		if (!is_dir(dirname($target)) && dol_mkdir(dirname($target)) < 0) {
			$this->error = $langs->transnoentities('ErrorCanNotCreateDir', dirname($target));
			return '';
		}
		try {
			$builder = new WerkbankBuilderIntake($this->db, $ticket, $langs, $signed);
			$renderer = new WerkbankRenderer(pdf_getInstance(array(WerkbankRenderer::PAGE_W, WerkbankRenderer::PAGE_H)), $langs);
			$renderer->render($builder->build(), $target);
		} catch (Throwable $exception) {
			dol_syslog('ittabelanderpdf: '.$exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine(), LOG_ERR);
			$this->error = $exception->getMessage();
			return '';
		}
		dolChmod($target);
		return $target;
	}
}
