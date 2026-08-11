<?php
declare(strict_types=1);

return [
    'hero' => [
        'eyebrow' => 'Persönliche Computerhilfe in Telfs',
        'headlineAccent' => 'PC kaputt? ',
        'headline' => 'Ich bringe Ruhe in Ihre Technik.',
        'lead' => 'Ob Laptop, WLAN oder Controller: Sie sprechen direkt mit Fabian. Ich prüfe das Problem, erkläre die Möglichkeiten verständlich und repariere nur, was wirklich Sinn ergibt.',
        'highlights' => [
            'Direkter Ansprechpartner',
            'Verständlich erklärt',
            'Telfs & Umgebung',
        ],
        'primaryCta' => 'Problem schildern',
        'secondaryCta' => 'Hilfe finden',
    ],
    'trustSignals' => [
        ['title' => 'Persönlich', 'text' => 'Vom ersten Anruf bis zur Übergabe bleibt Fabian Ihr Ansprechpartner.'],
        ['title' => 'Nachvollziehbar', 'text' => 'Sie erfahren vor der Umsetzung, was sinnvoll ist und welche Alternativen es gibt.'],
        ['title' => 'Flexibel', 'text' => 'Je nach Anliegen vor Ort, per Fernwartung oder nach Geräteübergabe.'],
    ],
    'about' => [
        'eyebrow' => 'Hallo, ich bin Fabian',
        'headline' => 'Kein Callcenter. Kein Fachchinesisch. Einfach persönliche IT-Hilfe.',
        'copy' => 'IT-Tabelander ist mein Einzelunternehmen in Telfs. Mir ist wichtig, dass Sie nicht nur ein funktionierendes Gerät zurückbekommen, sondern auch verstehen, was passiert ist und was Sie künftig beachten können.',
        'principles' => [
            'Erst zuhören und prüfen – dann eine passende Lösung empfehlen.',
            'Kosten, Nutzen und Alternativen verständlich besprechen.',
            'Ihre Daten und Geräte so behandeln, wie ich es selbst erwarten würde.',
        ],
    ],
    'processSteps' => [
        [
            'title' => 'Kurz erzählen',
            'text' => 'Schildern Sie ohne Fachbegriffe, was nicht funktioniert. Fotos oder Fehlermeldungen können später helfen.',
        ],
        [
            'title' => 'Prüfen & erklären',
            'text' => 'Ich ordne das Problem ein und erkläre Ihnen die sinnvollen Möglichkeiten, bevor etwas Größeres gemacht wird.',
        ],
        [
            'title' => 'Lösen & testen',
            'text' => 'Nach Ihrer Freigabe wird repariert oder eingerichtet. Zum Schluss prüfen wir gemeinsam, ob wieder alles passt.',
        ],
    ],
    'serviceBands' => [
        [
            'title' => 'PC & Laptop',
            'image' => 'pc-laptop-reparatur',
            'imageAlt' => 'Geöffneter Computer bei der Fehlerdiagnose',
            'imageWidth' => 1536,
            'imageHeight' => 1024,
            'intro' => 'Der Computer startet nicht, wird heiß, ist sehr langsam oder zeigt ständig Fehler? Ich suche zuerst die Ursache und bespreche dann die vernünftige Lösung.',
            'items' => ['Fehlerdiagnose und Reparatur', 'SSD- oder RAM-Aufrüstung', 'Reinigung, Windows- und Startprobleme'],
            'audience' => 'Startfehler · langsam · laut · defekt',
            'groups' => ['reparatur'],
        ],
        [
            'title' => 'WLAN zuhause',
            'image' => 'netzwerk-wlan',
            'imageAlt' => 'WLAN- und Netzwerkprüfung in einem Zuhause',
            'imageWidth' => 1536,
            'imageHeight' => 1024,
            'intro' => 'Funklöcher, Abbrüche oder langsames Internet löst man nicht automatisch mit noch einem Repeater. Ich prüfe Platzierung, Einstellungen und vorhandene Geräte.',
            'items' => ['WLAN-Abdeckung und Störungen prüfen', 'Router, Mesh und Access Points einrichten', 'Heimnetz verständlich und sicher aufbauen'],
            'audience' => 'Funklöcher · Abbrüche · langsames Internet',
            'groups' => ['netzwerk'],
        ],
        [
            'title' => 'Einrichten & aufrüsten',
            'image' => 'upgrades-systempflege',
            'imageAlt' => 'Computer-Komponenten für eine sinnvolle Aufrüstung',
            'imageWidth' => 1536,
            'imageHeight' => 1024,
            'intro' => 'Ein neuer Computer, Drucker oder eine neue Festplatte soll einfach funktionieren. Ich richte alles sauber ein und übernehme auf Wunsch Ihre vorhandenen Daten.',
            'items' => ['Neue Geräte startklar machen', 'Daten und Programme übersiedeln', 'Sinnvoll aufrüsten statt vorschnell ersetzen'],
            'audience' => 'Neugerät · Datenumzug · mehr Leistung',
            'groups' => ['systeme'],
        ],
        [
            'title' => 'Sicherheit & Virenverdacht',
            'image' => 'service-overview',
            'imageAlt' => 'Sicherheitsprüfung eines privaten Computers',
            'imageWidth' => 1717,
            'imageHeight' => 916,
            'intro' => 'Seltsame Pop-ups, ein gehacktes Konto oder die Sorge um wichtige Fotos? Ich prüfe das Gerät und helfe bei einer verständlichen, sicheren Grundeinstellung.',
            'items' => ['Viren- und Schadsoftware-Prüfung', 'Updates, Konten und Basisschutz', 'Backup für wichtige persönliche Daten'],
            'audience' => 'Pop-ups · Konto · Daten · Backup',
            'groups' => ['sicherheit'],
        ],
        [
            'title' => 'Konsole & Controller',
            'image' => 'controller-konsolen-service',
            'imageAlt' => 'Controller bei einer technischen Reparatur',
            'imageWidth' => 1536,
            'imageHeight' => 1024,
            'intro' => 'Stick-Drift, klemmende Tasten oder eine auffällige Konsole werden technisch geprüft. Sie erfahren ehrlich, ob sich die Reparatur noch lohnt.',
            'items' => ['Controller-Fehler prüfen und reparieren', 'Ausgewählte Konsolen warten', 'Wirtschaftlichkeit vorab einschätzen'],
            'audience' => 'Stick-Drift · Tasten · Wartung',
            'groups' => ['gaming'],
        ],
    ],
    'faq' => [
        [
            'question' => 'Muss ich wissen, was genau kaputt ist?',
            'answer' => 'Nein. Beschreiben Sie einfach, was Sie sehen, hören oder seit wann nicht mehr funktioniert. Die technische Einordnung ist Teil meiner Arbeit.',
        ],
        [
            'question' => 'Kommen Sie auch zu mir nach Hause?',
            'answer' => 'Ja, wenn das Problem vor Ort gelöst werden sollte – zum Beispiel bei WLAN oder fest eingerichteten Geräten. Anderes lässt sich per Fernwartung oder nach Geräteübergabe meist effizienter lösen.',
        ],
        [
            'question' => 'Erfahre ich die Kosten vor der Reparatur?',
            'answer' => 'Vor größeren Maßnahmen erhalten Sie eine verständliche Einschätzung zu Aufwand, Nutzen und Alternativen. Sie entscheiden dann, wie es weitergeht.',
        ],
        [
            'question' => 'Lohnt sich die Reparatur bei einem älteren Gerät?',
            'answer' => 'Nicht immer. Ersatzteilkosten, Zustand und geplanter Einsatz werden gemeinsam betrachtet. Wenn ein Ersatz sinnvoller ist, sage ich das offen.',
        ],
        [
            'question' => 'Helfen Sie auch per Fernwartung?',
            'answer' => 'Ja, für viele Einstellungen und Softwareprobleme ist Fernwartung praktisch. Sie wird nur nach Absprache gestartet und Sie behalten den Vorgang auf Ihrem Bildschirm im Blick.',
        ],
    ],
];
