<?php
declare(strict_types=1);

return [
    'hero' => [
        'eyebrow' => 'Persönliche Computerhilfe in Telfs',
        'headlineAccent' => 'PC kaputt? ',
        'headline' => 'Ich bringe Ihre Technik wieder in Ordnung.',
        'lead' => 'Ob Laptop, WLAN oder Controller: Sie sprechen direkt mit mir. Ich prüfe das Problem, erkläre Ihnen die Möglichkeiten verständlich und repariere nur, was sich wirklich lohnt.',
        'highlights' => [
            'Direkter Ansprechpartner',
            'Verständlich erklärt',
            'Telfs & Umgebung',
        ],
        'primaryCta' => 'Problem schildern',
        'secondaryCta' => 'Hilfe finden',
    ],
    'trustSignals' => [
        ['title' => 'Persönlich', 'text' => 'Vom ersten Anruf bis zur Übergabe kümmere ich mich persönlich um Ihr Anliegen.'],
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
            'text' => 'Beschreiben Sie einfach, was nicht funktioniert. Fotos oder Fehlermeldungen helfen mir bei der ersten Einschätzung.',
        ],
        [
            'title' => 'Prüfen & erklären',
            'text' => 'Ich prüfe das Problem und erkläre Ihnen die sinnvollen Möglichkeiten, bevor ich mit größeren Arbeiten beginne.',
        ],
        [
            'title' => 'Lösen & testen',
            'text' => 'Nach Ihrer Freigabe repariere oder richte ich das Gerät ein. Anschließend prüfen wir gemeinsam, ob alles wieder funktioniert.',
        ],
    ],
    'serviceBands' => [
        [
            'title' => 'PC & Laptop',
            'image' => 'pc-laptop-reparatur',
            'imageAlt' => 'Geöffneter Computer bei der Fehlerdiagnose',
            'imageWidth' => 1536,
            'imageHeight' => 1024,
            'intro' => 'Der Computer startet nicht, wird heiß, ist sehr langsam oder zeigt ständig Fehler? Ich suche zuerst die Ursache und bespreche anschließend mit Ihnen, welche Lösung sinnvoll ist.',
            'items' => ['Fehlerdiagnose und Reparatur', 'SSD- oder RAM-Aufrüstung', 'Reinigung, Windows- und Startprobleme'],
            'audience' => 'Startfehler · langsam · laut · defekt',
            'groups' => ['reparatur'],
        ],
        [
            'title' => 'WLAN zu Hause',
            'image' => 'netzwerk-wlan',
            'imageAlt' => 'WLAN- und Netzwerkprüfung in einem Zuhause',
            'imageWidth' => 1536,
            'imageHeight' => 1024,
            'intro' => 'Bei Funklöchern, Verbindungsabbrüchen oder langsamem Internet hilft nicht immer ein weiterer Repeater. Ich prüfe die Platzierung, die Einstellungen und Ihre vorhandenen Geräte.',
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
            'intro' => 'Neue Geräte sollen von Anfang an zuverlässig funktionieren. Ich richte Computer, Drucker oder Festplatten für Sie ein und übertrage auf Wunsch Ihre vorhandenen Daten.',
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
            'intro' => 'Seltsame Pop-ups, ein gehacktes Konto oder Sorge um wichtige Fotos? Ich prüfe Ihr Gerät und helfe Ihnen, es verständlich und sicher einzurichten.',
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
            'items' => ['Stick-Drift, Akku- und Tastenfehler reparieren', 'Hall-Effect, Clicky-Tasten, Back-Paddles und LED-Upgrades', 'Pauschalpreise direkt im Konfigurator zusammenstellen'],
            'audience' => 'DualSense · DualSense Edge · Upgrades',
            'groups' => ['gaming'],
            'url' => 'controller-service-telfs',
            'cta' => 'PS5-Controller konfigurieren',
        ],
    ],
    'faq' => [
        [
            'question' => 'Muss ich wissen, was genau kaputt ist?',
            'answer' => 'Nein. Beschreiben Sie einfach, was nicht funktioniert, was Sie beobachten und seit wann das Problem besteht. Um die technische Einordnung kümmere ich mich.',
        ],
        [
            'question' => 'Kommen Sie auch zu mir nach Hause?',
            'answer' => 'Ja, wenn sich das Problem am besten vor Ort lösen lässt – zum Beispiel bei WLAN oder fest installierten Geräten. Andere Anliegen lassen sich per Fernwartung oder nach einer Geräteübergabe meist schneller lösen.',
        ],
        [
            'question' => 'Erfahre ich die Kosten vor der Reparatur?',
            'answer' => 'Vor größeren Maßnahmen erhalten Sie eine verständliche Einschätzung zu Aufwand, Nutzen und Alternativen. Sie entscheiden dann, wie es weitergeht.',
        ],
        [
            'question' => 'Lohnt sich die Reparatur bei einem älteren Gerät?',
            'answer' => 'Nicht immer. Ich berücksichtige die Ersatzteilkosten, den Zustand und die geplante weitere Nutzung. Wenn ein neues Gerät sinnvoller ist, sage ich Ihnen das offen.',
        ],
        [
            'question' => 'Helfen Sie auch per Fernwartung?',
            'answer' => 'Ja, für viele Einstellungen und Softwareprobleme ist Fernwartung praktisch. Sie wird nur nach Absprache gestartet und Sie behalten den Vorgang auf Ihrem Bildschirm im Blick.',
        ],
    ],
];
