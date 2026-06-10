<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/site-services.php';

$mailConfig = $siteConfig['mail'] ?? [];
$smtp = $mailConfig['smtp'] ?? [];
$sendTest = in_array('--send', $argv, true);

function smtp_check_line(string $label, bool $ok, string $detail = ''): void
{
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $label;

    if ($detail !== '') {
        echo ' - ' . $detail;
    }

    echo PHP_EOL;
}

function smtp_check_secret_files(): void
{
    $candidates = [];
    $envFile = getenv('SMTP_PASSWORD_FILE');

    if (is_string($envFile) && $envFile !== '') {
        $candidates[] = $envFile;
    }

    if (function_exists('config_sibling_secret_file')) {
        $candidates[] = config_sibling_secret_file('smtp-password.txt');
    }

    $candidates = array_values(array_unique(array_filter($candidates)));

    foreach ($candidates as $path) {
        smtp_check_line(
            'Passwortdatei',
            is_file($path) && is_readable($path),
            $path . ' exists=' . (is_file($path) ? 'yes' : 'no') . ' readable=' . (is_readable($path) ? 'yes' : 'no')
        );
    }
}

echo 'SMTP Diagnose IT-Tabelander' . PHP_EOL;
echo 'Host: ' . (string) ($smtp['host'] ?? '') . ':' . (string) ($smtp['port'] ?? '') . PHP_EOL;
echo 'Encryption: ' . (string) ($smtp['encryption'] ?? '') . PHP_EOL;
echo 'Username: ' . (string) ($smtp['username'] ?? '') . PHP_EOL;
echo PHP_EOL;

smtp_check_secret_files();
smtp_check_line('Passwort geladen', trim((string) ($smtp['password'] ?? '')) !== '', 'Wert wird nicht ausgegeben');
smtp_check_line('SMTP vollständig konfiguriert', smtp_configured($mailConfig));

if (!smtp_configured($mailConfig)) {
    exit(2);
}

try {
    $socket = smtp_open_connection($smtp);
    smtp_check_line('TCP-Verbindung', true);

    $banner = smtp_expect($socket, [220]);
    smtp_check_line('SMTP-Banner', true, trim($banner));

    $ehloDomain = trim((string) ($smtp['ehloDomain'] ?? 'localhost'));
    $ehlo = smtp_command($socket, 'EHLO ' . $ehloDomain, [250]);
    smtp_check_line('EHLO', true, str_replace(["\r", "\n"], ' | ', trim($ehlo)));

    if (strtolower((string) ($smtp['encryption'] ?? '')) === 'tls') {
        $startTls = smtp_command($socket, 'STARTTLS', [220]);
        smtp_check_line('STARTTLS', true, trim($startTls));

        $cryptoEnabled = stream_socket_enable_crypto($socket, true, smtp_crypto_method());
        smtp_check_line('TLS aktiv', $cryptoEnabled === true);

        if ($cryptoEnabled !== true) {
            throw new RuntimeException('TLS konnte nicht aktiviert werden.');
        }

        $ehlo = smtp_command($socket, 'EHLO ' . $ehloDomain, [250]);
        smtp_check_line('EHLO nach TLS', true, str_replace(["\r", "\n"], ' | ', trim($ehlo)));
    }

    $username = trim((string) ($smtp['username'] ?? ''));
    $password = (string) ($smtp['password'] ?? '');

    if ($username !== '' && $password !== '') {
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        $auth = smtp_command($socket, base64_encode($password), [235]);
        smtp_check_line('AUTH LOGIN', true, trim($auth));
    }

    smtp_command($socket, 'QUIT', [221]);
    fclose($socket);

    if ($sendTest) {
        $submission = [
            'name' => 'SMTP Diagnose',
            'email' => (string) ($mailConfig['replyToEmail'] ?? $mailConfig['fromEmail'] ?? $mailConfig['recipient']),
            'phone' => '',
            'audience' => 'SMTP-Test',
            'service' => 'SMTP-Test',
            'message' => 'Automatischer SMTP-Test von private/tools/smtp-check.php',
        ];
        $message = build_owner_notification_message($siteConfig, $submission);
        smtp_check_line('Testmail versendet', smtp_send_message($mailConfig, $message));
    }

    exit(0);
} catch (Throwable $exception) {
    smtp_check_line('SMTP-Prüfung', false, $exception->getMessage());

    if (isset($socket) && is_resource($socket)) {
        fclose($socket);
    }

    exit(1);
}
