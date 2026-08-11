<?php
declare(strict_types=1);

function config_env_value(string $key, string $default = ''): string
{
    $value = getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
}

function config_env_bool(string $key, bool $default = false): bool
{
    $value = getenv($key);

    if (!is_string($value) || $value === '') {
        return $default;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function config_env_secret(string $key, string $default = '', array $fileCandidates = []): string
{
    $value = getenv($key);
    if (is_string($value) && $value !== '') {
        return $value;
    }

    $filePath = getenv($key . '_FILE');
    if (is_string($filePath) && $filePath !== '' && is_file($filePath)) {
        $secret = trim((string) file_get_contents($filePath));

        if ($secret !== '') {
            return $secret;
        }
    }

    foreach ($fileCandidates as $candidatePath) {
        if (!is_string($candidatePath) || $candidatePath === '' || !is_file($candidatePath)) {
            continue;
        }

        $secret = trim((string) file_get_contents($candidatePath));

        if ($secret !== '') {
            return $secret;
        }
    }

    return $default;
}

function config_sibling_secret_file(string $filename): string
{
    return dirname(__DIR__, 2) . '/it-tabelander-secrets/' . ltrim($filename, '/\\');
}

$smtpHost = config_env_value('SMTP_HOST');
$smtpPort = (int) config_env_value('SMTP_PORT', '587');
$smtpUsername = config_env_value('SMTP_USERNAME');
$smtpPassword = config_env_secret('SMTP_PASSWORD', '', [
    config_sibling_secret_file('smtp-password.txt'),
]);
$smtpEnabledRequested = config_env_bool('SMTP_ENABLED', false);
$smtpRequiredConfigurationPresent = $smtpHost !== ''
    && $smtpPort > 0
    && $smtpUsername !== ''
    && $smtpPassword !== '';

// Zentrale Pflege der öffentlichen Seiteninhalte. Zugangsdaten und
// umgebungsspezifische Endpunkte werden ausschließlich extern konfiguriert.
$config = [
    'meta' => [
        'siteName' => 'IT-Tabelander',
        'language' => 'de-AT',
        'title' => 'IT-Tabelander | IT-Service in Tirol',
        'description' => 'IT-Service in Telfs: Reparatur, Einrichtung, Server, Netzwerk, WLAN und Sicherheitsprüfung in Tirol.',
        'canonicalBaseUrl' => config_env_value('CANONICAL_BASE_URL', 'https://it.tabelander.co.at'),
    ],
    'analytics' => [
        'googleMeasurementId' => config_env_value('GOOGLE_ANALYTICS_MEASUREMENT_ID', 'G-BFT0P4GG5N'),
    ],
    'company' => [
        'name' => 'IT-Tabelander',
        'owner' => 'Fabian Tabelander',
        'legalForm' => 'Einzelunternehmen',
        'street' => 'Spridrichstraße 17',
        'postalCode' => '6410',
        'city' => 'Telfs',
        'country' => 'Österreich',
        'phone' => '+43 676 6039945',
        'email' => 'office@tabelander.co.at',
        'serviceArea' => 'Telfs, Tirol und Umgebung sowie Fernwartung nach Absprache',
        'businessHours' => 'Montag bis Freitag nach Vereinbarung',
        // Google-Bewertungen leer lassen, wenn der Bereich mit private/data/reviews.json gepflegt wird.
        'googlePlaceId' => config_env_value('GOOGLE_PLACE_ID'),
        'googleApiKey' => config_env_secret('GOOGLE_PLACES_API_KEY'),
        'reviewCacheTtl' => 43200,
        'manualTestimonials' => [],
        'uid' => '',
        'vatId' => '',
        'supervisoryAuthority' => 'Bezirkshauptmannschaft Innsbruck',
        'supervisoryAuthorityUrl' => 'https://www.tirol.gv.at/innsbruck/',
        'chamber' => 'Wirtschaftskammer Tirol, Fachgruppe Unternehmensberatung, Buchhaltung und Informationstechnologie, Berufsgruppe IT-Dienstleistung',
        'chamberUrl' => 'https://www.wko.at/tirol/ubit',
        'profession' => 'IT-Dienstleistung',
        'professionDetail' => 'Dienstleistungen in der automatischen Datenverarbeitung und Informationstechnik',
        'memberState' => 'Österreich',
        'tradeRegulationLabel' => 'Gewerbeordnung (GewO)',
        'tradeRegulationUrl' => 'https://www.ris.bka.gv.at/',
        'mediaLine' => 'Information über IT-Dienstleistungen, Reparatur, Systembetreuung und Infrastrukturleistungen von IT-Tabelander.',
        'websitePurpose' => 'IT-Reparatur, Systemkonfiguration, Server-, Netzwerk-, WLAN- und Sicherheitsbetreuung',
        'privacyAuthorityName' => 'Österreichische Datenschutzbehörde',
        'privacyAuthorityUrl' => 'https://www.dsb.gv.at/',
        'wkoProfileUrl' => '',
        'legalNoticeComplete' => true,
    ],
    'mail' => [
        'recipient' => config_env_value('CONTACT_RECIPIENT', 'office@tabelander.co.at'),
        'fromName' => 'IT-Tabelander',
        'fromEmail' => 'office@tabelander.co.at',
        'replyToEmail' => 'office@tabelander.co.at',
        'sendOwnerNotification' => true,
        'sendCustomerConfirmation' => true,
        'customerConfirmationSubject' => 'Ihre Anfrage bei IT-Tabelander',
        'smtp' => [
            'enabled' => $smtpEnabledRequested && $smtpRequiredConfigurationPresent,
            'enabledRequested' => $smtpEnabledRequested,
            'host' => $smtpHost,
            'port' => $smtpPort,
            'encryption' => config_env_value('SMTP_ENCRYPTION', 'tls'),
            'username' => $smtpUsername,
            'password' => $smtpPassword,
            'allowSelfSigned' => config_env_bool('SMTP_ALLOW_SELF_SIGNED', false),
            'verifyPeer' => config_env_bool('SMTP_VERIFY_PEER', true),
            'verifyPeerName' => config_env_bool('SMTP_VERIFY_PEER_NAME', true),
            'timeout' => 12,
            'ehloDomain' => config_env_value('SMTP_EHLO_DOMAIN', (string) (gethostname() ?: 'localhost')),
        ],
    ],
    'security' => [
        'privacyConsentRequired' => true,
        'minFormSeconds' => 3,
        'captcha' => [
            'enabled' => true,
            'label' => 'Sicherheitsfrage',
        ],
    ],
    'logging' => [
        // Set to 0 to disable persistent application logs completely.
        'retentionDays' => max(0, (int) config_env_value('RUNTIME_LOG_RETENTION_DAYS', '30')),
    ],
    'hero' => [
        'eyebrow' => 'IT-Service in Telfs und Tirol',
        'headline' => 'PC-Reparatur und IT-Betreuung in Telfs.',
        'lead' => 'Persönliche Hilfe für PCs und Laptops, stabiles WLAN sowie laufende IT-Betreuung für kleine Unternehmen in Telfs und Umgebung.',
        'highlights' => [
            'PC- und Laptop-Reparatur mit nachvollziehbarer Diagnose',
            'WLAN- und Netzwerkoptimierung vor Ort',
            'IT-Betreuung für kleine Unternehmen',
        ],
        'primaryCta' => 'Anfrage senden',
        'secondaryCta' => 'Leistungsbereiche',
    ],
    'trustSignals' => [
        [
            'title' => 'Persönlich betreut',
            'text' => 'Inhaber Fabian Tabelander ist Ihr direkter Ansprechpartner – von der ersten Einordnung bis zur Übergabe.',
        ],
        [
            'title' => 'Ein Ansprechpartner',
            'text' => 'Vom einzelnen Gerät bis zur laufenden Betreuung kleiner und mittlerer IT-Umgebungen.',
        ],
        [
            'title' => 'Sicherheit mitgedacht',
            'text' => 'Virenprüfung, Basisschutz, Updates und saubere Konfiguration werden bei Bedarf direkt mitgeprüft.',
        ],
    ],
    'about' => [
        'eyebrow' => 'Über IT-Tabelander',
        'headline' => 'Persönliche IT-Hilfe aus Telfs.',
        'copy' => 'IT-Tabelander ist das Einzelunternehmen von Fabian Tabelander in Telfs. Anliegen werden direkt mit dem Inhaber geklärt – ohne wechselnde Ansprechpartner und mit einer verständlichen Einschätzung vor der Umsetzung.',
        'principles' => [
            'Erst prüfen, dann eine passende Maßnahme empfehlen.',
            'Aufwand, Nutzen und nächste Schritte verständlich erklären.',
            'Wichtige Änderungen nachvollziehbar dokumentieren.',
        ],
    ],
    'processSteps' => [
        [
            'title' => 'Anfrage und Einordnung',
            'text' => 'Fehlerbild, Ziel und Dringlichkeit werden kurz geklärt. Danach ist klar, welcher nächste Schritt sinnvoll ist.',
        ],
        [
            'title' => 'Diagnose mit Empfehlung',
            'text' => 'Gerät, System oder Infrastruktur werden geprüft. Danach gibt es eine klare Einschätzung zu Aufwand, Nutzen und Kostenrahmen.',
        ],
        [
            'title' => 'Umsetzung und Prüfung',
            'text' => 'Reparatur, Einrichtung oder Aufbau erfolgen nachvollziehbar und werden vor der Übergabe getestet.',
        ],
        [
            'title' => 'Dokumentation und Übergabe',
            'text' => 'Wichtige Einstellungen, Änderungen und Empfehlungen werden verständlich festgehalten.',
        ],
    ],
    'audiences' => [
        [
            'label' => 'Privatkunden',
            'headline' => 'Geräte reparieren und das Heimnetz zuverlässig machen.',
            'copy' => 'PCs und Laptops werden geprüft, repariert oder sinnvoll aufgerüstet. WLAN-Probleme lassen sich vor Ort messen und gezielt lösen.',
        ],
        [
            'label' => 'Unternehmen',
            'headline' => 'Ein Ansprechpartner für den laufenden IT-Alltag.',
            'copy' => 'Arbeitsplätze, Benutzer, Netzwerk, WLAN und Server werden für kleine Betriebe in Telfs und Tirol nachvollziehbar betreut und dokumentiert.',
        ],
    ],
    'serviceBands' => [
        [
            'title' => 'PC und Laptop',
            'image' => 'pc-laptop-reparatur',
            'imageWidth' => 1536,
            'imageHeight' => 1024,
            'intro' => 'Diagnose, Reparatur und Instandsetzung bei Startproblemen, Defekten, Überhitzung oder instabilem Verhalten.',
            'items' => [
                'Analyse bei Startfehlern, Bluescreens, Temperatur- oder Leistungsproblemen',
                'Austausch von SSD, RAM, Netzteil, Kühlern, Displays und weiteren Komponenten nach Befund',
                'Reinigung, Wartung und Vorbereitung für den weiteren Einsatz',
            ],
            'audience' => 'Reparatur und Diagnose',
            'groups' => ['reparatur', 'systeme'],
        ],
        [
            'title' => 'Upgrades und Systempflege',
            'image' => 'upgrades-systempflege',
            'imageWidth' => 1536,
            'imageHeight' => 1024,
            'intro' => 'Bestehende Systeme werden aufgerüstet, bereinigt und sauber für den Alltag oder den Betrieb vorbereitet.',
            'items' => [
                'SSD- und RAM-Upgrades für spürbar bessere Alltags- und Arbeitsleistung',
                'Neuaufsetzung, Treiberpflege und strukturierte Grundkonfiguration',
                'Abstimmung von Hardware und Software für Office, Homeoffice, Gaming oder gemischte Nutzung',
            ],
            'audience' => 'Systempflege und Einrichtung',
            'groups' => ['reparatur', 'systeme'],
        ],
        [
            'title' => 'Windows und Windows Server',
            'image' => 'windows-server',
            'imageWidth' => 1536,
            'imageHeight' => 1024,
            'intro' => 'Windows-Arbeitsplätze und Windows-Server werden eingerichtet, abgesichert und auf den Einsatz abgestimmt.',
            'items' => [
                'Einrichtung von Windows-Systemen für Arbeitsplatz, Homeoffice und Teams',
                'Grundkonfiguration, Rollenplanung und laufende Betreuung von Windows-Server-Systemen',
                'Benutzer, Freigaben, Updates, Basis-Sicherheit und strukturierte Dokumentation',
            ],
            'audience' => 'Windows und Server',
            'groups' => ['systeme', 'sicherheit'],
        ],
        [
            'title' => 'Netzwerk und WLAN',
            'image' => 'netzwerk-wlan',
            'imageWidth' => 1536,
            'imageHeight' => 1024,
            'intro' => 'Netzwerk- und WLAN-Strukturen werden geplant, gemessen, eingerichtet und bei Bedarf gezielt verbessert.',
            'items' => [
                'WLAN- beziehungsweise WiFi-Konzept mit sinnvoller Platzierung von Router, Access Points und Repeatern',
                'WLAN-Messung, Störungsanalyse, Kanalprüfung und Einschätzung von Abdeckung und Stabilität',
                'Netzwerkstruktur, Segmentierung, Basisschutz und nachvollziehbare Dokumentation',
            ],
            'audience' => 'Netzwerk, WLAN und Messung',
            'groups' => ['netzwerk', 'sicherheit'],
        ],
        [
            'title' => 'Linux und Open-Source-Server',
            'image' => 'linux-open-source-server',
            'imageWidth' => 1536,
            'imageHeight' => 1024,
            'intro' => 'Linux- und Open-Source-Server werden nachvollziehbar, wartbar und passend zum Einsatzzweck aufgebaut.',
            'items' => [
                'Einrichtung von Linux-Distributionen für Server und technische Speziallösungen',
                'Aufbau und Betreuung von Open-Source-Serverdiensten je nach Einsatzbereich',
                'Unterstützung bei Updates, Diensten, Zugriffen, Basis-Härtung und sauberer Strukturierung',
            ],
            'audience' => 'Linux und Serverdienste',
            'groups' => ['systeme', 'sicherheit'],
        ],
        [
            'title' => 'Active Directory und Konzeption',
            'image' => 'active-directory-konzeption',
            'imageWidth' => 1661,
            'imageHeight' => 947,
            'intro' => 'Benutzerverwaltung und Infrastruktur werden strukturiert für Betrieb, Erweiterung und Betreuung aufgebaut.',
            'items' => [
                'Konzeption, Einrichtung und Wartung von Active-Directory-Domänen',
                'Strukturierung von Benutzer-, Gruppen- und Rechtekonzepten',
                'Begleitung beim Aufbau, bei Modernisierung und laufender Pflege kleiner IT-Landschaften',
            ],
            'audience' => 'Benutzerverwaltung und Struktur',
            'groups' => ['systeme', 'netzwerk', 'sicherheit'],
        ],
        [
            'title' => 'IT-Sicherheit und Virenprüfung',
            'image' => 'service-overview',
            'imageWidth' => 1717,
            'imageHeight' => 916,
            'intro' => 'Systeme werden auf offensichtliche Risiken, Schadsoftware, unsichere Einstellungen und fehlende Schutzmaßnahmen geprüft.',
            'items' => [
                'Viren-, Malware- und Adware-Prüfung mit sauberer Einschätzung statt blinder Schnellreparatur',
                'Prüfung von Updates, Benutzerrechten, Autostart, Browser-Erweiterungen und typischen Schwachstellen',
                'Empfehlungen zu Backup, Passwortschutz, Gerätehärtung, Fernzugriff und sicherer Grundkonfiguration',
            ],
            'audience' => 'Sicherheits- und Virenprüfung',
            'groups' => ['sicherheit', 'systeme'],
        ],
        [
            'title' => 'Konsolen und Controller',
            'image' => 'controller-konsolen-service',
            'imageWidth' => 1536,
            'imageHeight' => 1024,
            'intro' => 'Service für defekte oder verschlissene Gaming-Geräte mit klarer technischer Einschätzung.',
            'items' => [
                'Reparatur und Wartung ausgewählter Konsolen nach technischer Prüfung',
                'PS5-Controller-Reparatur, sofern Fehlerbild und Ersatzteilsituation dies sinnvoll zulassen',
                'Transparente Einschätzung, ob sich eine Reparatur wirtschaftlich lohnt',
            ],
            'audience' => 'Gaming-Hardware und Reparatur',
            'groups' => ['reparatur'],
        ],
    ],
    'faq' => [
        [
            'question' => 'Arbeitet IT-Tabelander nur für bestimmte Kundengruppen?',
            'answer' => 'Nein. Entscheidend ist das IT-Thema: Reparatur, Einrichtung, Netzwerk, WLAN, Server, Sicherheit oder laufende Betreuung werden je nach Bedarf umgesetzt.',
        ],
        [
            'question' => 'Erfolgt die Betreuung nur vor Ort?',
            'answer' => 'Nein. Je nach Thema sind Vor-Ort-Termine in Tirol sinnvoll, viele Konfigurations-, Analyse- und Nachbetreuungsaufgaben können aber auch per Fernwartung erfolgen.',
        ],
        [
            'question' => 'Lohnt sich eine Reparatur immer?',
            'answer' => 'Nicht in jedem Fall. Vor größeren Maßnahmen wird geprüft, ob Aufwand, Ersatzteilsituation und Restwert des Geräts in einem vernünftigen Verhältnis stehen.',
        ],
        [
            'question' => 'Werden auch Windows-, Linux- und Server-Systeme betreut?',
            'answer' => 'Ja. Neben Endgeräten umfasst das Leistungsbild auch Windows-Betriebssysteme, Windows-Server, Linux-Systeme, Open-Source-Serverdienste und Benutzerverwaltung.',
        ],
        [
            'question' => 'Sind WLAN-Messung und Sicherheitsprüfung möglich?',
            'answer' => 'Ja. WLAN-Abdeckung, Störungen, Router- oder Access-Point-Platzierung sowie grundlegende Sicherheits- und Virenprüfungen können gemeinsam beurteilt werden.',
        ],
        [
            'question' => 'Sind laufende Wartung und Betreuung möglich?',
            'answer' => 'Ja. Gerade bei Netzwerken, Servern, WLAN oder Active Directory ist eine strukturierte laufende Betreuung oft sinnvoller als einzelne Einsätze.',
        ],
        [
            'question' => 'Werden auch neue Umgebungen konzipiert?',
            'answer' => 'Ja. Neben Reparaturen und Support können auch neue Arbeitsplätze, Serverstrukturen, Benutzerkonzepte sowie WLAN- und Netzwerklösungen geplant und eingerichtet werden.',
        ],
    ],
];

$homeContent = require __DIR__ . '/home-content.php';

return array_replace($config, $homeContent);
