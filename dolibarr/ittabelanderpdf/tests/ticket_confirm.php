<?php
/*
 * The module's own confirmation for a new ticket, in the disposable Dolibarr
 * of scripts/dev_dolibarr.py (after "fixtures"):
 *
 *   php ticket_confirm.php enable   Tickets module + public interface (own process)
 *   php ticket_confirm.php run      four tickets; the mails land in Mailpit
 *
 * Never run against a real Dolibarr.
 */

require __DIR__.'/bootstrap.php';

$stage = $argv[1] ?? '';
$admin = rt_admin($db);
$user = $admin;
$GLOBALS['user'] = $admin;
$langs->setDefaultLang('de_DE');

if ($stage === 'enable') {
	rt_modules(array('modTicket'));
	rt_const($db, 'TICKET_ENABLE_PUBLIC_INTERFACE', '1');
	rt_const($db, 'TICKET_NOTIFICATION_EMAIL_FROM', 'office@tabelander.co.at');
	echo json_encode(array('enable' => 'ok')), "\n";
	exit(0);
}

if ($stage !== 'run') {
	rt_fail('unknown stage '.$stage);
}

require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';

$socid = function ($email) use ($db) {
	return (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE email = '".$db->escape($email)."'");
};
$cases = array(
	'du' => array('fk_soc' => $socid('max@mustermann.example'), 'subject' => 'Notebook startet nicht'),
	'sie' => array('fk_soc' => $socid('office@musterfirma.example'), 'subject' => 'Drucker im Büro offline'),
	'website' => array('origin_email' => 'anna@beispiel.example', 'subject' => 'PC nach Wunsch – Anfrage'),
	'off' => array('origin_email' => 'still@beispiel.example', 'subject' => 'Ohne Bestätigung', 'context' => array('disableticketemail' => 1)),
);
$out = array();
foreach ($cases as $name => $case) {
	$ticket = new Ticket($db);
	$ticket->ref = $ticket->getDefaultRef();
	$ticket->track_id = bin2hex(random_bytes(8));
	$ticket->subject = $case['subject'];
	$ticket->message = "Hallo,\nseit gestern geht nichts mehr.\n<b>Danke</b>";
	$ticket->type_code = 'ISSUE';
	$ticket->category_code = 'OTHER';
	$ticket->severity_code = 'NORMAL';
	$ticket->fk_soc = $case['fk_soc'] ?? 0;
	$ticket->origin_email = $case['origin_email'] ?? '';
	$ticket->notify_tiers_at_create = 1;
	$ticket->context = $case['context'] ?? array();
	if ($ticket->create($admin) <= 0) {
		rt_fail('ticket '.$name.': '.$ticket->error.' '.implode(',', (array) $ticket->errors));
	}
	$out[$name] = array('ref' => $ticket->ref, 'notify_after' => (int) $ticket->notify_tiers_at_create);
}
echo json_encode($out), "\n";
exit(0);
