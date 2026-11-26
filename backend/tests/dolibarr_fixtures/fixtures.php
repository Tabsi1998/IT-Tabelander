<?php
/*
 * Prepare the disposable Dolibarr 24.0.1 of the local check (group "dolibarr").
 *
 *   php fixtures.php base      company, mail through Mailpit, modules, API users
 *   php fixtures.php customer  an existing customer the website must never change
 *   php fixtures.php content   knowledge articles: FAQ, legal texts, internal notes
 *   php fixtures.php move      the company moves (new address in the setup)
 *   php fixtures.php ecm       PDFs of the legal texts in the documents (ECM)
 *
 * Every stage prints one JSON object; scripts/local_check.py passes it to the
 * scenarios in backend/tests/test_dolibarr_runtime.py.
 */

require __DIR__.'/bootstrap.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

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

    rt_modules(array('modSociete', 'modTicket', 'modApi', 'modPropale', 'modFacture', 'modAgenda',
        'modCategorie', 'modKnowledgeManagement',
        // The legal texts as PDF in the documents (#128).
        'modECM',
        // All documents of a customer in the customer area (#100).
        'modCommande', 'modExpedition', 'modFicheinter', 'modContrat',
        // The billing interval of a running service is the service's duration (#101).
        'modProduct', 'modService'));
    // Company data and opening hours as the owner keeps them in the setup (#74).
    rt_const($db, 'MAIN_INFO_SOCIETE_ADDRESS', 'Werkstattweg 1');
    rt_const($db, 'MAIN_INFO_SOCIETE_ZIP', '6020');
    rt_const($db, 'MAIN_INFO_SOCIETE_TOWN', 'Innsbruck');
    rt_const($db, 'MAIN_INFO_SOCIETE_TEL', '+43 512 111111');
    rt_const($db, 'MAIN_INFO_SOCIETE_MANAGERS', 'Test Inhaber');
    rt_const($db, 'MAIN_INFO_SOCIETE_OBJECT', 'IT-Reparatur und PC-Bau');
    rt_const($db, 'MAIN_INFO_SOCIETE_NOTE', 'Interne Firmennotiz, nie öffentlich');
    rt_const($db, 'MAIN_INFO_TVAINTRA', 'ATU22222222');
    rt_const($db, 'MAIN_INFO_SIRET', 'LG Innsbruck');
    rt_const($db, 'MAIN_INFO_APE', 'FN 222222a');
    rt_const($db, 'MAIN_INFO_OPENINGHOURS_MONDAY', '09:00–17:00');
    rt_const($db, 'MAIN_INFO_OPENINGHOURS_FRIDAY', '09:00–12:00');
    // Instead of admin rights: name the website user for these two readings.
    rt_const($db, 'API_LOGINS_ALLOWED_FOR_GET_COMPANY', 'itweb');
    rt_const($db, 'API_LOGINS_ALLOWED_FOR_CONST_READ', 'itweb');
    // The owner's yes/no field on tickets (#78).
    $extrafields = new ExtraFields($db);
    if ($extrafields->addExtraField('abholbereit', 'Gerät abholbereit', 'boolean', 100, '', 'ticket',
        0, 0, '', '', 1, '', '1') <= 0) {
        rt_fail('extra field abholbereit: '.$extrafields->error);
    }
    // The owner's block of the customer area, right on the customer (#63).
    if ($extrafields->addExtraField('kundenbereich_gesperrt', 'Kundenbereich gesperrt', 'boolean', 100, '', 'societe',
        0, 0, '', '', 1, '', '1') <= 0) {
        rt_fail('extra field kundenbereich_gesperrt: '.$extrafields->error);
    }
    // Minimum term and notice period of a contract, as the module
    // "IT-Tabelander PDF" creates them in production (#101).
    if ($extrafields->addExtraField('ittab_mindestlaufzeit', 'Mindestlaufzeit (Monate)', 'int', 100, '10', 'contrat',
        0, 0, '', '', 1, '', '1') <= 0) {
        rt_fail('extra field ittab_mindestlaufzeit: '.$extrafields->error);
    }
    if ($extrafields->addExtraField('ittab_kuendigungsfrist', 'Kündigungsfrist', 'varchar', 101, '128', 'contrat',
        0, 0, '', '', 1, '', '1') <= 0) {
        rt_fail('extra field ittab_kuendigungsfrist: '.$extrafields->error);
    }
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
        // Callback wishes become phone calls in the agenda (#73).
        array('agenda', 'myactions', 'read'), array('agenda', 'myactions', 'create'),
        // Website content and "Angebot bereit" (#74, #78, #81); "write" only
        // for the one-time copy of the website FAQ.
        array('knowledgemanagement', 'knowledgerecord', 'read'),
        array('knowledgemanagement', 'knowledgerecord', 'write'),
        array('categorie', 'lire'), array('propale', 'lire'),
        // The customer area (#63, #64): sign-in by a contact's address, the
        // customer's own invoices.
        array('societe', 'contact', 'lire'), array('facture', 'lire'),
        // Accepting or declining an offer closes it in Dolibarr (#65).
        array('propale', 'creer'),
        // Order confirmations, delivery notes, work reports, contracts (#100): read only.
        array('commande', 'lire'), array('expedition', 'lire'), array('ficheinter', 'lire'), array('contrat', 'lire'),
        // The billing interval of running services (#101): the service's duration.
        array('produit', 'lire'),
        // The legal texts as PDF from the documents (#128): read/download only.
        array('ecm', 'read'),
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

if ($stage === 'content') {
    require_once DOL_DOCUMENT_ROOT.'/knowledgemanagement/class/knowledgerecord.class.php';
    require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
    // The three categories of the owner's knowledge base (#128): FAQ (once
    // called "Website"), Rechtstexte and Unterlagen.
    $categories = array();
    foreach (array('faq' => 'FAQ', 'legal' => 'Rechtstexte', 'internal' => 'Unterlagen') as $role => $label) {
        $categories[$role] = new Categorie($db);
        $categories[$role]->label = $label;
        $categories[$role]->type = Categorie::TYPE_KNOWLEDGEMANAGEMENT;
        if ($categories[$role]->create($admin) <= 0) {
            rt_fail('category '.$label.': '.$categories[$role]->error);
        }
    }
    // question, answer, released, categories. As in production, the legal
    // texts also still carry the FAQ category; the terms lack "Rechtstexte".
    $articles = array(
        'faq' => array('Holt ihr Geräte auch ab?', '<p>Ja, im Raum Innsbruck.</p><script>alert(1)</script>', true, array('faq')),
        'faq_draft' => array('Eine Frage im Entwurf?', '<p>Noch nicht fertig.</p>', false, array('faq')),
        'internal' => array('Interne Notiz: Lieferant', '<p>Nur für die Werkstatt.</p>', true, array()),
        'privacy' => array('Datenschutzerklärung', '<h2>Datenschutz</h2><p>Test-Datenschutztext.</p>', true, array('faq', 'legal')),
        'terms' => array('Nutzungsbedingungen', '<p>Test-Nutzungsbedingungen.</p>', false, array('faq')),
        'imprint' => array('Impressum-Ergänzung', '<p>Aufsichtsbehörde: Test-BH</p>', true, array('faq', 'legal')),
        // Released and tagged FAQ by mistake: the other category keeps them off the FAQ.
        'b2b' => array('AGB für Unternehmer', '<p>Test-AGB für Firmen.</p>', true, array('faq', 'legal')),
        'paper' => array('Preisliste', '<p>Nur intern.</p>', true, array('faq', 'internal')),
    );
    $ids = array('category' => (int) $categories['faq']->id, 'legal_category' => (int) $categories['legal']->id,
        'internal_category' => (int) $categories['internal']->id);
    foreach ($articles as $key => $spec) {
        $record = new KnowledgeRecord($db);
        $record->question = $spec[0];
        $record->answer = $spec[1];
        $record->lang = 'de_DE';
        if ($record->create($admin) <= 0) {
            rt_fail('article '.$key.': '.$record->error);
        }
        if ($spec[2] && $record->validate($admin) <= 0) {
            rt_fail('validate '.$key.': '.$record->error);
        }
        foreach ($spec[3] as $role) {
            if ($categories[$role]->add_type($record, Categorie::TYPE_KNOWLEDGEMANAGEMENT) < 0) {
                rt_fail('tag '.$key.': '.$categories[$role]->error);
            }
        }
        $ids[$key] = (int) $record->id;
    }
    print json_encode($ids, JSON_PRETTY_PRINT)."\n";
    exit(0);
}

if ($stage === 'ecm') {
    // What the module "IT-Tabelander PDF" files in Dolibarr's documents (#128):
    // two real PDFs, the one of a draft, and a file that only claims to be one.
    $dir = (!empty($conf->ecm->dir_output) ? $conf->ecm->dir_output : DOL_DATA_ROOT.'/ecm').'/IT-Tabelander/Unterlagen';
    if (dol_mkdir($dir) < 0) {
        rt_fail('ecm folder: '.$dir);
    }
    $files = array(
        'datenschutz' => "%PDF-1.4\n% Datenschutz IT-Tabelander\n%%EOF\n",
        'nutzungsbedingungen' => "%PDF-1.4\n% Entwurf\n%%EOF\n",
        'agb-unternehmer' => "%PDF-1.4\n% AGB IT-Tabelander\n%%EOF\n",
        'widerruf' => "<html>kein PDF</html>\n",
    );
    foreach ($files as $slug => $content) {
        $file = $dir.'/'.$slug.'.pdf';
        if (file_put_contents($file, $content) === false) {
            rt_fail('ecm file: '.$file);
        }
        @chmod($file, 0644);
        @chown($file, 'www-data');
    }
    foreach (array($dir, dirname($dir)) as $folder) {
        @chmod($folder, 0755);
        @chown($folder, 'www-data');
    }
    print json_encode(array('folder' => 'IT-Tabelander/Unterlagen', 'files' => array_keys($files),
        'datenschutz' => base64_encode($files['datenschutz'])), JSON_PRETTY_PRINT)."\n";
    exit(0);
}

if ($stage === 'move') {
    rt_const($db, 'MAIN_INFO_SOCIETE_ADDRESS', 'Neue Gasse 7');
    print json_encode(array('address' => 'Neue Gasse 7'))."\n";
    exit(0);
}

rt_fail('unknown stage: '.$stage);
