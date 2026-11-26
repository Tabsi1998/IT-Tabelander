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
 * Tabs of the module setup: general (PDF, e-mails, company documents) and workshop.
 *
 * @return array<int,array{0:string,1:string,2:string}>
 */
function ittabpdf_admin_prepare_head()
{
	global $langs, $user;
	$langs->load('ittabelanderpdf@ittabelanderpdf');
	$head = array();
	if ($user->admin) {
		$head[] = array(dol_buildpath('/ittabelanderpdf/admin/setup.php', 1), $langs->trans('WerkbankSetupTabGeneral'), 'general');
	}
	$head[] = array(dol_buildpath('/ittabelanderpdf/admin/werkstatt.php', 1), $langs->trans('WerkbankSetupTabWorkshop'), 'workshop');
	return $head;
}
