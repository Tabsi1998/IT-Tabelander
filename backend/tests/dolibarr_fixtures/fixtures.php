<?php
/*
 * Prepare the disposable Dolibarr 24.0.1 of the local check (group "dolibarr").
 *
 *   php fixtures.php base      company, mail through Mailpit, modules, API users
 *   php fixtures.php customer  an existing customer the website must never change
 *
 * Every stage prints one JSON object; scripts/local_check.py passes it to the
 * scenarios in backend/tests/test_dolibarr_runtime.py.
 */

require __DIR__.'/bootstrap.php';

$stage = $argv[1] ?? '';
$admin = rt_admin($db);

/** A user with exactly the rights the website needs, and its API key. */
function rt_api_user($db, $admin, $login, array $rights)
{
    $user = new User($db);
    $user->login = $login;
    $user->lastname = 'Website';
    $user->firstname = 'IT-Tabelander';
    $user->email = $login.'@it-tabelander.test';
    $user->admin = 0;
    $user->entity = 1;
    if ($user->create($admin) <= 0) {
        rt_fail('user '.$login.': '.$user->error);
    }
    foreach ($rights as $pair) {
        $sub = isset($pair[2]) ? "subperms = '".$db->escape($pair[2])."'" : "(subperms IS NULL OR subperms = '')";
        $id = rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = '".$db->escape($pair[0])
            ."' AND perms = '".$db->escape($pair[1])."' AND ".$sub." AND entity = 1");
        if (!$id) {
            rt_fail('right '.implode('/', $pair).' does not exist');
        }
        $user->addrights((int) $id);
    }
    $key = bin2hex(random_bytes(20));
    rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."user SET api_key = '".$db->escape($key)."' WHERE rowid = ".((int) $user->id));
    return array((int) $user->id, $key);
}

if ($stage === 'base') {
    rt_const($db, 'MAIN_LANG_DEFAULT', 'de_DE');
    rt_const($db, 'MAIN_MONNAIE', 'EUR');
    rt_const($db, 'MAIN_INFO_SOCIETE_NOM', 'IT-Tabelander Test');
    rt_const($db, 'MAIN_INFO_SOCIETE_MAIL', 'office@it-tabelander.test');
    rt_const($db, 'MAIN_MAIL_EMAIL_FROM', 'werkstatt@it-tabelander.test');
    rt_const($db, 'MAIN_MAIL_SENDMODE', 'smtps');
    rt_const($db, 'MAIN_MAIL_SMTP_SERVER', 'mail');
    rt_const($db, 'MAIN_MAIL_SMTP_PORT', '1025');
    rt_const($db, 'MAIN_MAIL_EMAIL_TLS', '0');
    rt_const($db, 'MAIN_MAIL_EMAIL_STARTTLS', '0');
    rt_const($db, 'MAIN_DISABLE_ALL_MAILS', '0');

    rt_modules(array('modSociete', 'modTicket', 'modApi'));
    rt_const($db, 'API_PRODUCTION_MODE', '0');
    // What the owner sets in Dolibarr (README, "Dolibarr einmalig vorbereiten"):
    // new tickets reach the workshop, and the link in the customer's
    // confirmation leads to the website's status view instead of Dolibarr.
    // Dolibarr writes that link only while its public interface is switched on.
    rt_const($db, 'TICKET_ENABLE_PUBLIC_INTERFACE', '1');
    rt_const($db, 'TICKET_NOTIFICATION_EMAIL_FROM', 'werkstatt@it-tabelander.test');
    rt_const($db, 'TICKET_NOTIFICATION_EMAIL_TO', 'werkstatt@it-tabelander.test');
    rt_const($db, 'TICKET_URL_PUBLIC_INTERFACE', 'https://it.tabelander.test/status/');
    $conf->setValues($db);
    $admin->loadRights('', 1);

    // Without "client voir" Dolibarr shows a user only the third parties it is
    // sales representative of: the website would not find a regular customer
    // by e-mail and create a duplicate.
    list($webId, $webKey) = rt_api_user($db, $admin, 'itweb', array(
        array('societe', 'lire'), array('societe', 'creer'), array('societe', 'client', 'voir'),
        array('ticket', 'read'), array('ticket', 'write'),
    ));
    // The scenarios read what the website created through an administrator.
    $adminKey = bin2hex(random_bytes(20));
    rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."user SET api_key = '".$db->escape($adminKey)."' WHERE rowid = ".((int) $admin->id));

    print json_encode(array(
        'web_user' => $webId,
        'web_key' => $webKey,
        'admin_key' => $adminKey,
        'notification_to' => 'werkstatt@it-tabelander.test',
        'public_url' => 'https://it.tabelander.test/status/',
    ), JSON_PRETTY_PRINT)."\n";
    exit(0);
}

if ($stage === 'customer') {
    $countryId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
    $customer = new Societe($db);
    $customer->name = 'Stammkunde GmbH';
    $customer->email = 'buchhaltung@stammkunde.example.com';
    $customer->phone = '+43 512 000000';
    $customer->address = 'Echte Gasse 1';
    $customer->zip = '6020';
    $customer->town = 'Innsbruck';
    $customer->country_id = $countryId;
    $customer->tva_intra = 'ATU11111111';
    $customer->idprof1 = '11-111/1111';
    $customer->client = 1;
    $customer->code_client = -1;
    $customer->status = 1;
    if ($customer->create($admin) <= 0) {
        rt_fail('customer: '.$customer->error.' '.implode(' | ', (array) $customer->errors));
    }
    print json_encode(array('customer' => (int) $customer->id, 'email' => $customer->email), JSON_PRETTY_PRINT)."\n";
    exit(0);
}

rt_fail('unknown stage: '.$stage);
