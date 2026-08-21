<?php
declare(strict_types=1);

return [
    'models' => [
        'dualsense' => [
            'label' => 'PS5 DualSense',
            'shortLabel' => 'DualSense',
            'description' => 'Standard-Controller der PlayStation 5',
        ],
        'dualsense-edge' => [
            'label' => 'PS5 DualSense Edge',
            'shortLabel' => 'DualSense Edge',
            'description' => 'DualSense-Edge-Controller der PlayStation 5',
        ],
    ],
    'issues' => [
        'stick-left' => [
            'label' => 'Linker Stick driftet oder reagiert falsch',
            'shortLabel' => 'Linker Stick',
            'zone' => 'stick-left',
        ],
        'stick-right' => [
            'label' => 'Rechter Stick driftet oder reagiert falsch',
            'shortLabel' => 'Rechter Stick',
            'zone' => 'stick-right',
        ],
        'buttons' => [
            'label' => 'Tasten klemmen oder reagieren nicht',
            'shortLabel' => 'Tasten',
            'zone' => 'buttons',
        ],
        'dpad' => [
            'label' => 'Steuerkreuz reagiert fehlerhaft',
            'shortLabel' => 'Steuerkreuz',
            'zone' => 'dpad',
        ],
        'triggers' => [
            'label' => 'Trigger oder Schultertasten sind auffällig',
            'shortLabel' => 'Trigger / Schultertasten',
            'zone' => 'triggers',
        ],
        'charging' => [
            'label' => 'Laden oder USB-C-Anschluss funktioniert nicht',
            'shortLabel' => 'Laden / USB-C',
            'zone' => 'charging',
        ],
        'battery' => [
            'label' => 'Akku hält nicht mehr oder lädt schlecht',
            'shortLabel' => 'Akku',
            'zone' => 'battery',
        ],
        'connection' => [
            'label' => 'Verbindung oder Anmeldung schlägt fehl',
            'shortLabel' => 'Verbindung',
            'zone' => 'connection',
        ],
        'housing' => [
            'label' => 'Gehäuse, Sturz- oder mechanischer Schaden',
            'shortLabel' => 'Gehäuse / Sturzschaden',
            'zone' => 'housing',
        ],
        'liquid' => [
            'label' => 'Flüssigkeit ist in den Controller gelangt',
            'shortLabel' => 'Flüssigkeitsschaden',
            'zone' => 'housing',
        ],
        'other' => [
            'label' => 'Anderes oder noch unklareres Fehlerbild',
            'shortLabel' => 'Anderes Fehlerbild',
            'zone' => 'connection',
        ],
    ],
    'extras' => [
        'cleaning' => [
            'label' => 'Innenreinigung und Wartung mitprüfen',
            'shortLabel' => 'Innenreinigung & Wartung',
        ],
        'opened-before' => [
            'label' => 'Controller wurde bereits geöffnet oder repariert',
            'shortLabel' => 'Bereits geöffnet / repariert',
        ],
    ],
];
