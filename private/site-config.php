<?php
declare(strict_types=1);

function config_env_value(string $key, string $default = ''): string
{
    $value = getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
}

function config_env_secret(string $key, string $default = ''): string
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

    return $default;
}

// Zentrale Pflege: Google-Place-ID, Google-API-Key und SMTP-Zugangsdaten
// können direkt hier eingetragen oder alternativ als Umgebungsvariablen gesetzt werden.
return [
    'meta' => [
        'siteName' => 'IT-Tabelander',
        'language' => 'de-AT',
        'title' => 'IT-Tabelander | IT-Service in Tirol',
        'description' => 'IT-Service in Telfs für Privatkunden und Unternehmen: Reparatur, Einrichtung, Server, Netzwerk und WLAN in Tirol.',
        'canonicalBaseUrl' => config_env_value('CANONICAL_BASE_URL', 'https://www.tabelander.co.at'),
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
        // Google-Bewertungen: leer lassen, wenn der Bereich manuell gepflegt werden soll.
        'googlePlaceId' => config_env_value('GOOGLE_PLACE_ID'),
        'googleApiKey' => config_env_secret('GOOGLE_PLACES_API_KEY'),
        'reviewCacheTtl' => 43200,
        'manualTestimonials' => [
            [
                'author' => 'Privatkunden-Anliegen aus Telfs',
                'rating' => '5',
                'text' => 'Der Laptop wurde geprüft, aufgerüstet und sauber neu eingerichtet. Vorab war klar, welche Arbeiten sinnvoll sind und welche Kosten zu erwarten sind.',
                'relativeTime' => 'Typisches Feedback',
            ],
            [
                'author' => 'Kleines Unternehmen in Tirol',
                'rating' => '5',
                'text' => 'Arbeitsplätze, Freigaben und Netzwerk wurden strukturiert eingerichtet. Die Dokumentation macht spätere Anpassungen einfacher.',
                'relativeTime' => 'Typisches Feedback',
            ],
            [
                'author' => 'Reparatur und Technikservice',
                'rating' => '5',
                'text' => 'Controller und PC wurden nachvollziehbar beurteilt. Vor der Umsetzung war klar, ob sich eine Reparatur lohnt oder ein Austausch sinnvoller ist.',
                'relativeTime' => 'Typisches Feedback',
            ],
        ],
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
        'websitePurpose' => 'IT-Reparatur, Systemkonfiguration, Server-, Netzwerk- und Infrastrukturbetreuung',
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
            'enabled' => true,
            // Zugangsdaten des Mailservers. Alternativ können die SMTP_* Variablen am Server gesetzt werden.
            'host' => config_env_value('SMTP_HOST', 'mail.tabelander.co.at'),
            'port' => (int) config_env_value('SMTP_PORT', '587'),
            'encryption' => config_env_value('SMTP_ENCRYPTION', 'tls'),
            'username' => config_env_value('SMTP_USERNAME', 'office@tabelander.co.at'),
            'password' => config_env_secret('SMTP_PASSWORD'),
            'timeout' => 12,
            'ehloDomain' => config_env_value('SMTP_EHLO_DOMAIN', 'tabelander.co.at'),
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
    'hero' => [
        'eyebrow' => 'IT-Dienstleistungen in Tirol',
        'headline' => 'IT-Service für Privatkunden und Unternehmen.',
        'lead' => 'Reparatur, Einrichtung und Betreuung von PCs, Arbeitsplätzen, Servern und Netzwerken in Tirol.',
        'highlights' => [
            'PC, Laptop und Konsole',
            'Windows, Linux und Server',
            'Netzwerk, WLAN und Benutzerverwaltung',
        ],
        'primaryCta' => 'Anfrage senden',
        'secondaryCta' => 'Leistungsbereiche',
    ],
    'trustSignals' => [
        [
            'title' => 'Standort Tirol',
            'text' => 'Vor Ort in Telfs, Tirol und Umgebung sowie per Fernwartung, wenn es technisch sinnvoll ist.',
        ],
        [
            'title' => 'Privatkunden und Unternehmen',
            'text' => 'Vom einzelnen Gerät bis zur laufenden Betreuung kleiner und mittlerer IT-Umgebungen.',
        ],
        [
            'title' => 'Nachvollziehbare Umsetzung',
            'text' => 'Änderungen, Konfigurationen und Empfehlungen bleiben verständlich dokumentiert.',
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
            'label' => 'Für Privatkunden',
            'headline' => 'Reparatur und Aufrüstung für Zuhause.',
            'copy' => 'PCs, Laptops, Konsolen und Controller werden geprüft, repariert, neu eingerichtet oder gezielt aufgerüstet.',
        ],
        [
            'label' => 'Für Unternehmen',
            'headline' => 'Systeme und Infrastruktur für den Betrieb.',
            'copy' => 'Windows, Linux, Server, WLAN und Benutzerverwaltung werden stabil, nachvollziehbar und wartbar eingerichtet.',
        ],
    ],
    'serviceBands' => [
        [
            'title' => 'PC und Laptop',
            'image' => 'pc-laptop-reparatur.png',
            'intro' => 'Diagnose, Reparatur und Instandsetzung bei Startproblemen, Defekten, Überhitzung oder instabilem Verhalten.',
            'items' => [
                'Analyse bei Startfehlern, Bluescreens, Temperatur- oder Leistungsproblemen',
                'Austausch von SSD, RAM, Netzteil, Kühlern, Displays und weiteren Komponenten nach Befund',
                'Reinigung, Wartung und Vorbereitung für den weiteren Einsatz',
            ],
            'audience' => 'Privatkunden und Unternehmen',
        ],
        [
            'title' => 'Upgrades und Systempflege',
            'image' => 'upgrades-systempflege.png',
            'intro' => 'Bestehende Systeme werden aufgerüstet, bereinigt und sauber für den Alltag oder den Betrieb vorbereitet.',
            'items' => [
                'SSD- und RAM-Upgrades für spürbar bessere Alltags- und Arbeitsleistung',
                'Neuaufsetzung, Treiberpflege und strukturierte Grundkonfiguration',
                'Abstimmung von Hardware und Software für Office, Homeoffice, Gaming oder gemischte Nutzung',
            ],
            'audience' => 'Privatkunden und Unternehmen',
        ],
        [
            'title' => 'Windows und Windows Server',
            'image' => 'windows-server.png',
            'intro' => 'Windows-Arbeitsplätze und Windows-Server werden eingerichtet, abgesichert und auf den Einsatz abgestimmt.',
            'items' => [
                'Einrichtung von Windows-Systemen für Arbeitsplatz, Homeoffice und Teams',
                'Grundkonfiguration, Rollenplanung und laufende Betreuung von Windows-Server-Systemen',
                'Benutzer, Freigaben, Updates, Basis-Sicherheit und strukturierte Dokumentation',
            ],
            'audience' => 'Privatkunden und Unternehmen',
        ],
        [
            'title' => 'Netzwerk und WLAN',
            'image' => 'netzwerk-wlan.png',
            'intro' => 'Netzwerk- und WLAN-Strukturen werden geplant, eingerichtet und bei Bedarf gezielt verbessert.',
            'items' => [
                'Planung, Erweiterung und Verbesserung von Netzwerk- und WLAN-Strukturen',
                'Segmentierung, Basisschutz und nachvollziehbare Dokumentation',
                'Unterstützung bei Umstellungen, Störungen und Kapazitätserweiterungen',
            ],
            'audience' => 'Privatkunden und Unternehmen',
        ],
        [
            'title' => 'Linux und Open-Source-Server',
            'image' => 'linux-open-source-server.png',
            'intro' => 'Linux- und Open-Source-Server werden nachvollziehbar, wartbar und passend zum Einsatzzweck aufgebaut.',
            'items' => [
                'Einrichtung von Linux-Distributionen für Server und technische Speziallösungen',
                'Aufbau und Betreuung von Open-Source-Serverdiensten je nach Einsatzbereich',
                'Unterstützung bei Updates, Diensten, Zugriffen, Basis-Härtung und sauberer Strukturierung',
            ],
            'audience' => 'Unternehmen',
        ],
        [
            'title' => 'Active Directory und Konzeption',
            'image' => 'active-directory-konzeption.png',
            'intro' => 'Benutzerverwaltung und Infrastruktur werden strukturiert für Betrieb, Erweiterung und Betreuung aufgebaut.',
            'items' => [
                'Konzeption, Einrichtung und Wartung von Active-Directory-Domänen',
                'Strukturierung von Benutzer-, Gruppen- und Rechtekonzepten',
                'Begleitung beim Aufbau, bei Modernisierung und laufender Pflege kleiner IT-Landschaften',
            ],
            'audience' => 'Unternehmen',
        ],
        [
            'title' => 'Konsolen und Controller',
            'image' => 'controller-konsolen-service.png',
            'intro' => 'Service für defekte oder verschlissene Gaming-Geräte mit klarer technischer Einschätzung.',
            'items' => [
                'Reparatur und Wartung ausgewählter Konsolen nach technischer Prüfung',
                'PS5-Controller-Reparatur, sofern Fehlerbild und Ersatzteilsituation dies sinnvoll zulassen',
                'Transparente Einschätzung, ob sich eine Reparatur wirtschaftlich lohnt',
            ],
            'audience' => 'Privatkunden',
        ],
    ],
    'faq' => [
        [
            'question' => 'Arbeitet IT-Tabelander nur für Unternehmen?',
            'answer' => 'Nein. IT-Tabelander unterstützt Privatkunden bei Reparaturen und Upgrades ebenso wie Unternehmen bei System-, Server- und Infrastrukturthemen.',
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
            'answer' => 'Ja. Neben Endgeräten umfasst das Leistungsbild auch Windows-Betriebssysteme, Windows-Server, Linux-Systeme und Open-Source-Serverdienste.',
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
