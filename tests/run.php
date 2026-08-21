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
        'issues' => ['stick-left', 'charging', 'nicht-erlaubt'],
        'offers' => ['edge-face-clicky', 'hall-pair', 'nicht-erlaubt'],
        'extras' => ['opened-before', 'nicht-erlaubt'],
        'notes' => 'Fehler tritt nicht immer auf.',
    ]);

    assert_same(true, $selection['valid']);
    assert_same('PS5 DualSense Edge', $selection['modelLabel']);
    assert_same(['stick-left', 'charging'], $selection['issueIds']);
    assert_same(['edge-face-clicky'], $selection['offerIds']);
    assert_same(['opened-before'], $selection['extraIds']);
    assert_same(5990, $selection['totalPriceCents']);
    assert_true(str_contains($selection['message'], 'Linker Stick, Laden / USB-C'));
    assert_true(!str_contains($selection['message'], 'nicht-erlaubt'));
});

test('Unvollständige Controller-Konfiguration wird abgelehnt', function (): void {
    $selection = build_controller_selection([
        'model' => 'xbox',
        'issues' => [],
    ]);

    assert_same(false, $selection['valid']);
    assert_same(['model', 'selection'], $selection['errors']);
});

test('Controller-Pauschalpreise werden serverseitig summiert', function (): void {
    $selection = build_controller_selection([
        'model' => 'dualsense',
        'issues' => [],
        'offers' => ['hall-pair', 'cleaning'],
    ]);

    assert_same(true, $selection['valid']);
    assert_same(13480, $selection['totalPriceCents']);
    assert_same('134,80 €', $selection['totalPriceLabel']);
    assert_true(str_contains($selection['message'], 'Hall-Effect-Umbau (2 Sticks): 99,90 €'));
});

test('Konkurrierende Controller-Pakete werden abgelehnt', function (): void {
    $selection = build_controller_selection([
        'model' => 'dualsense',
        'issues' => [],
        'offers' => ['hall-pair', 'stick-standard-two'],
    ]);

    assert_same(false, $selection['valid']);
    assert_true(in_array('offers', $selection['errors'], true));
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
    assert_true(!str_contains($home, 'Für Unternehmen'), 'Firmenansprache ist noch in der sichtbaren Startseite enthalten.');
    assert_true(!str_contains($home, 'data-count-up'), 'Deaktivierte oder unbelegte Kennzahlen wurden ausgegeben.');
    assert_true(!str_contains($sitemap, 'it-betreuung-telfs'), 'Firmen-Landingpage ist noch in der Sitemap enthalten.');
});

test('Smoke-Rendering der öffentlichen Seiten', function () use ($projectRoot): void {
    $scripts = [
        'index.php' => 'Ich bringe Ihre Technik wieder in Ordnung.',
        'pc-reparatur-telfs.php' => 'Wenn der Computer streikt',
        'controller-service-telfs.php' => 'Ihr Controller. Ihr Fehlerbild.',
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
