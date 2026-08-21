<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/private/bootstrap.php';
require $projectRoot . '/private/site-services.php';

$tests = [];
$failures = 0;

function test(string $name, callable $callback): void
{
    global $tests;
    $tests[] = [$name, $callback];
}

function assert_true(bool $condition, string $message = 'Bedingung ist nicht erfüllt.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = $message !== '' ? $message . ' ' : '';
        throw new RuntimeException($detail . 'Erwartet: ' . var_export($expected, true) . '; erhalten: ' . var_export($actual, true));
    }
}

function valid_submission(): array
{
    $_SESSION['contact_form'] = [
        'token' => 'valid-test-token',
        'captcha' => ['answer' => '7'],
    ];

    return [
        'name' => 'Test Person',
        'email' => 'test@example.test',
        'phone' => '',
        'audience' => 'Reparatur und Diagnose',
        'service' => 'PC und Laptop',
        'message' => 'Das ist eine ausreichend lange Testnachricht.',
        'privacyConfirmation' => '1',
        'captchaAnswer' => '7',
        'formRenderedAt' => time() - 10,
        'formToken' => 'valid-test-token',
    ];
}

function assert_validation_error(array $siteConfig, string $expectedError, callable $mutate): void
{
    $submission = valid_submission();
    $mutate($submission);
    $result = validate_contact_submission($siteConfig, $submission);

    assert_true(!$result['valid'], 'Ungültige Eingabe wurde akzeptiert.');
    assert_true(in_array($expectedError, $result['errors'], true), 'Erwarteter Validierungsfehler fehlt: ' . $expectedError);
}

function render_php_script(string $script): string
{
    $process = proc_open([PHP_BINARY, $script], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('PHP-Unterprozess konnte nicht gestartet werden.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException('Rendering fehlgeschlagen: ' . trim((string) $stderr));
    }

    return (string) $stdout;
}

test('gültige Kontaktanfrage', function () use ($siteConfig): void {
    $result = validate_contact_submission($siteConfig, valid_submission());
    assert_same(true, $result['valid']);
    assert_same([], $result['errors']);
});

test('ungültige E-Mail', function () use ($siteConfig): void {
    assert_validation_error($siteConfig, 'email', static function (array &$submission): void {
        $submission['email'] = 'keine-adresse';
    });
});

test('fehlende Datenschutzzustimmung', function () use ($siteConfig): void {
    assert_validation_error($siteConfig, 'privacyConfirmation', static function (array &$submission): void {
        $submission['privacyConfirmation'] = '';
    });
});

test('falsches CAPTCHA', function () use ($siteConfig): void {
    assert_validation_error($siteConfig, 'captcha', static function (array &$submission): void {
        $submission['captchaAnswer'] = '8';
    });
});

test('falscher Formular-Token', function () use ($siteConfig): void {
    assert_validation_error($siteConfig, 'token', static function (array &$submission): void {
        $submission['formToken'] = 'wrong-token';
    });
});

test('zu schnelle Formularübermittlung', function () use ($siteConfig): void {
    assert_validation_error($siteConfig, 'timing', static function (array &$submission): void {
        $submission['formRenderedAt'] = time();
    });
});

test('URL- und Telefon-Helfer', function () use (&$siteConfig): void {
    $originalBase = $siteConfig['meta']['canonicalBaseUrl'];
    $siteConfig['meta']['canonicalBaseUrl'] = 'https://example.test/';
    assert_same('https://example.test/', canonical_url());
    assert_same('https://example.test/pc-reparatur-telfs', canonical_url('/pc-reparatur-telfs'));
    assert_same('+436766039945', phone_href('+43 676 603 99 45'));
    $siteConfig['meta']['canonicalBaseUrl'] = $originalBase;
});

test('sichere SMTP-Defaults', function () use ($siteConfig): void {
    $smtp = $siteConfig['mail']['smtp'];
    assert_same(false, $smtp['enabled']);
    assert_same(false, $smtp['allowSelfSigned']);
    assert_same(true, $smtp['verifyPeer']);
    assert_same(true, $smtp['verifyPeerName']);
    assert_same('', $smtp['host']);
    assert_same('', $smtp['username']);
});

test('Dolibarr bleibt ohne bewusste Freigabe deaktiviert', function () use ($siteConfig): void {
    $dolibarr = $siteConfig['dolibarr'];
    assert_same(false, $dolibarr['enabled']);
    assert_same('https://erp.tabelander.co.at', $dolibarr['baseUrl']);
    assert_same(null, $dolibarr['vatRate']);
    assert_true(!dolibarr_configured($dolibarr));
});

test('SMTP-Konfigurationsvalidierung', function (): void {
    $mail = [
        'recipient' => 'office@example.test',
        'fromEmail' => 'office@example.test',
        'smtp' => [
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 587,
            'username' => 'mailer@example.test',
            'password' => 'test-only-password',
        ],
    ];

    assert_true(smtp_configured($mail));
    $mail['smtp']['password'] = '';
    assert_true(!smtp_configured($mail), 'SMTP ohne Passwort wurde als konfiguriert erkannt.');
});

test('Controller-Konfiguration wird ausschließlich aus dem Katalog aufgebaut', function (): void {
    $selection = build_controller_selection([
        'model' => 'dualsense-edge',
        'offers' => ['edge-face-clicky', 'hall-pair', 'nicht-erlaubt'],
        'extras' => ['opened-before', 'nicht-erlaubt'],
        'notes' => 'Bitte schwarze Tasten verwenden.',
    ]);

    assert_same(true, $selection['valid']);
    assert_same('PS5 DualSense Edge', $selection['modelLabel']);
    assert_same(['edge-face-clicky'], $selection['offerIds']);
    assert_same(['opened-before'], $selection['extraIds']);
    assert_same(5990, $selection['totalPriceCents']);
    assert_true(str_contains($selection['message'], 'Bitte schwarze Tasten verwenden.'));
    assert_true(!str_contains($selection['message'], 'nicht-erlaubt'));
});

test('Unvollständige Controller-Konfiguration wird abgelehnt', function (): void {
    $selection = build_controller_selection([
        'model' => 'xbox',
        'offers' => [],
    ]);

    assert_same(false, $selection['valid']);
    assert_same(['model', 'selection'], $selection['errors']);
});

test('Controller-Pauschalpreise werden serverseitig summiert', function (): void {
    $selection = build_controller_selection([
        'model' => 'dualsense',
        'offers' => ['hall-pair', 'face-clicky'],
    ]);

    assert_same(true, $selection['valid']);
    assert_same(14980, $selection['totalPriceCents']);
    assert_same('149,80 €', $selection['totalPriceLabel']);
    assert_true(str_contains($selection['message'], 'Hall-Effect-Umbau (2 Sticks): 99,90 €'));
});

test('Konkurrierende Controller-Pakete werden abgelehnt', function (): void {
    $selection = build_controller_selection([
        'model' => 'dualsense',
        'offers' => ['face-clicky', 'full-clicky'],
    ]);

    assert_same(false, $selection['valid']);
    assert_true(in_array('offers', $selection['errors'], true));
});

test('Controller-Auswahl bleibt bis zum Kontaktversand serverseitig gespeichert', function (): void {
    $_SESSION['controller_requests'] = [];
    $selection = build_controller_selection([
        'model' => 'dualsense',
        'offers' => ['hall-pair'],
    ]);

    $requestId = store_controller_request($selection);
    assert_true((bool) preg_match('/^[a-f0-9]{32}$/', $requestId));
    assert_same('dualsense', controller_request($requestId)['modelId'] ?? '');
    assert_same('dualsense', controller_request($requestId, true)['modelId'] ?? '');
    assert_same([], controller_request($requestId));
});

test('Dolibarr-Positionen verwenden ausschließlich serverseitige Bruttopreise', function (): void {
    $selection = build_controller_selection([
        'model' => 'dualsense',
        'offers' => ['hall-pair', 'battery-upgrade'],
    ]);
    $lines = dolibarr_controller_lines($selection, 20.0);

    assert_same(2, count($lines));
    assert_same('99.90', $lines[0]['subprice']);
    assert_same('TTC', $lines[0]['price_base_type']);
    assert_same(20.0, $lines[0]['tva_tx']);
    assert_same('54.90', $lines[1]['subprice']);
});

test('Dolibarr-Kundensuche legt nur bei 404 einen neuen Kunden an', function (): void {
    $calls = [];
    $transport = static function (string $method, string $path, ?array $payload) use (&$calls): array {
        $calls[] = [$method, $path, $payload];

        return count($calls) === 1
            ? ['ok' => false, 'status' => 404, 'data' => ['error' => 'not found']]
            : ['ok' => true, 'status' => 200, 'data' => 42];
    };
    $result = dolibarr_find_or_create_thirdparty([
        'countryCode' => 'AT',
    ], [
        'name' => 'Test Person',
        'email' => 'TEST@example.test',
        'phone' => '+43 123',
    ], $transport);

    assert_same(true, $result['ok']);
    assert_same(42, $result['id']);
    assert_same('GET', $calls[0][0]);
    assert_same('thirdparties/email/test%40example.test', $calls[0][1]);
    assert_same('POST', $calls[1][0]);
    assert_same('thirdparties', $calls[1][1]);
    assert_same('-1', $calls[1][2]['code_client']);
    assert_same(2, $calls[1][2]['client']);
});

test('Allgemeine Website-Anfrage wird als Dolibarr-Ticket verknüpft', function (): void {
    $calls = [];
    $transport = static function (string $method, string $path, ?array $payload) use (&$calls): array {
        $calls[] = [$method, $path, $payload];

        return count($calls) === 1
            ? ['ok' => true, 'status' => 200, 'data' => ['id' => 42]]
            : ['ok' => true, 'status' => 201, 'data' => 91];
    };
    $result = create_dolibarr_contact_ticket([
        'dolibarr' => [
            'enabled' => true,
            'baseUrl' => 'https://erp.example.test',
            'apiKey' => 'test-key',
            'vatRate' => 0,
            'countryCode' => 'AT',
        ],
        'logging' => ['retentionDays' => 0],
    ], [
        'name' => 'Test Person',
        'email' => 'test@example.test',
        'phone' => '+43 123',
        'audience' => 'Computer',
        'service' => 'Reparatur und Diagnose',
        'message' => "Laptop startet nicht.\nBitte zurückrufen.",
    ], 'abc123', $transport);

    assert_same(true, $result['ok']);
    assert_same(91, $result['ticketId']);
    assert_same('GET', $calls[0][0]);
    assert_same('thirdparties/email/test%40example.test', $calls[0][1]);
    assert_same('POST', $calls[1][0]);
    assert_same('tickets', $calls[1][1]);
    assert_same(42, $calls[1][2]['socid']);
    assert_same(42, $calls[1][2]['fk_soc']);
    assert_true(str_contains($calls[1][2]['subject'], 'Reparatur und Diagnose'));
    assert_true(str_contains($calls[1][2]['message'], 'Laptop startet nicht.<br'));
    assert_same('test@example.test', $calls[1][2]['origin_email']);
});

test('private Pfade und Endpunkte bleiben außerhalb der Sitemap', function () use ($projectRoot): void {
    $rootRules = file_get_contents($projectRoot . '/.htaccess');
    assert_true(is_string($rootRules) && str_contains($rootRules, 'RewriteRule ^private(/|$) - [F,L]'));

    $registry = page_registry();
    foreach ($registry as $page) {
        if (empty($page['indexable'])) {
            continue;
        }

        $path = (string) ($page['path'] ?? '');
        assert_true(!str_starts_with($path, 'private/'));
        assert_true(!in_array($path, ['contact.php', 'reviews.php'], true));
    }
});

test('Privatkunden-Fokus ohne unbelegte Kennzahlen', function () use ($projectRoot): void {
    $registry = page_registry();
    assert_same(false, (bool) ($registry['it-betreuung-telfs']['indexable'] ?? true), 'Firmen-Landingpage ist noch indexierbar.');

    $home = render_php_script($projectRoot . '/index.php');
    $sitemap = render_php_script($projectRoot . '/sitemap.php');
    assert_true(str_contains($home, 'PC kaputt?'), 'Neue Privatkunden-Ansprache fehlt.');
    assert_true(str_contains($home, 'Controller-Konfigurator'), 'Direkter Navigationslink zum Controller-Konfigurator fehlt.');
    assert_true(!str_contains($home, 'Für Unternehmen'), 'Firmenansprache ist noch in der sichtbaren Startseite enthalten.');
    assert_true(!str_contains($home, 'data-count-up'), 'Deaktivierte oder unbelegte Kennzahlen wurden ausgegeben.');
    assert_true(!str_contains($sitemap, 'it-betreuung-telfs'), 'Firmen-Landingpage ist noch in der Sitemap enthalten.');
});

test('Controller-Konfigurator bleibt auf Upgrades begrenzt und Theme-Korrektur ist geladen', function () use ($projectRoot): void {
    $controller = render_php_script($projectRoot . '/controller-service-telfs.php');
    $styles = file_get_contents($projectRoot . '/public/assets/css/styles.css');
    $themePolish = file_get_contents($projectRoot . '/public/assets/css/theme-polish.css');

    assert_true(str_contains($controller, 'Welche Upgrades möchten Sie?'));
    assert_true(str_contains($controller, 'Reparaturen bleiben bewusst individuelle Anfragen.'));
    assert_true(!str_contains($controller, 'Was funktioniert nicht?'));
    assert_true(!str_contains($controller, 'Diagnosepauschale'));
    assert_true(is_string($styles) && str_contains($styles, 'theme-polish.css'));
    assert_true(is_string($themePolish) && str_contains($themePolish, '.contact-facts dd'));
    assert_true(str_contains($themePolish, 'html[data-resolved-theme="light"] body.controller-page'));
});

test('Smoke-Rendering der öffentlichen Seiten', function () use ($projectRoot): void {
    $scripts = [
        'index.php' => 'Ich bringe Ihre Technik wieder in Ordnung.',
        'pc-reparatur-telfs.php' => 'Wenn der Computer streikt',
        'controller-service-telfs.php' => 'Ihr Controller. Ihr Setup.',
        'wlan-netzwerk-telfs.php' => 'WLAN &amp; Netzwerk in Telfs',
        'sitemap.php' => '<urlset',
    ];

    foreach ($scripts as $script => $expectedText) {
        $output = render_php_script($projectRoot . '/' . $script);
        assert_true(str_contains($output, $expectedText), 'Erwarteter Inhalt fehlt in ' . $script);
    }
});

foreach ($tests as [$name, $callback]) {
    try {
        $callback();
        fwrite(STDOUT, "[OK] {$name}" . PHP_EOL);
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "[FEHLER] {$name}: {$exception->getMessage()}" . PHP_EOL);
    }
}

fwrite(STDOUT, sprintf('%d Tests, %d Fehler.%s', count($tests), $failures, PHP_EOL));
exit($failures === 0 ? 0 : 1);
