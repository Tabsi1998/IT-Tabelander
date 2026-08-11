<?php
declare(strict_types=1);

return [
    'home' => [
        'path' => '',
        'title' => 'IT-Tabelander | IT-Service in Telfs und Tirol',
        'description' => 'IT-Service in Telfs: Reparatur, Einrichtung, Server, Netzwerk, WLAN und Sicherheitsprüfung für Privatpersonen und Unternehmen in Tirol.',
        'ogImage' => 'img/hero-it-tabelander.png',
        'schemaType' => 'ProfessionalService',
        'serviceTypes' => [
            'PC- und Laptop-Reparatur',
            'PC- und Laptop-Upgrades',
            'Windows- und Linux-Systembetreuung',
            'Netzwerk- und WLAN-Konzeption',
            'Server- und Benutzerverwaltung',
            'IT-Sicherheits- und Virenprüfung',
        ],
        'indexable' => true,
        'changefreq' => 'weekly',
        'priority' => '1.0',
        'source' => 'private/pages/home.php',
        'preloadFonts' => true,
        'googleSiteVerification' => 'ZQiRDZwcqyQ1si_x_Wxw5NBKlLvHH0_AIsGCbK9xSrc',
    ],
    'impressum' => [
        'path' => 'impressum.php',
        'title' => 'Impressum | IT-Tabelander',
        'description' => 'Impressum und Offenlegung von IT-Tabelander in Telfs.',
        'indexable' => true,
        'changefreq' => 'yearly',
        'priority' => '0.2',
        'source' => 'private/pages/impressum.php',
    ],
    'datenschutz' => [
        'path' => 'datenschutz.php',
        'title' => 'Datenschutz | IT-Tabelander',
        'description' => 'Datenschutzerklärung von IT-Tabelander in Telfs.',
        'indexable' => true,
        'changefreq' => 'yearly',
        'priority' => '0.2',
        'source' => 'private/pages/datenschutz.php',
    ],
    'nutzungsbedingungen' => [
        'path' => 'nutzungsbedingungen.php',
        'title' => 'Nutzungsbedingungen | IT-Tabelander',
        'description' => 'Nutzungsbedingungen und Hinweise zur Website von IT-Tabelander.',
        'indexable' => true,
        'changefreq' => 'yearly',
        'priority' => '0.2',
        'source' => 'private/pages/nutzungsbedingungen.php',
    ],
];
