<?php
declare(strict_types=1);

function redirect_home(string $status): never
{
    header('Location: /?contact=' . urlencode($status) . '#kontakt', true, 303);
    exit;
}

function sanitize_multiline(string $value): string
{
    $clean = trim(str_replace(["\r\n", "\r"], "\n", $value));
    $clean = preg_replace('/\n{3,}/', "\n\n", $clean) ?? $clean;

    return trim($clean);
}

function ensure_contact_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
        return;
    }

    session_name('ittabelander_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function store_contact_form_flash(array $values, array $errors = []): void
{
    ensure_contact_session();

    $_SESSION['contact_form']['flash'] = [
        'values' => $values,
        'errors' => array_values(array_unique($errors)),
    ];
}

function consume_contact_form_flash(): array
{
    ensure_contact_session();

    $flash = $_SESSION['contact_form']['flash'] ?? [];
    unset($_SESSION['contact_form']['flash']);

    return is_array($flash) ? $flash : [];
}

function contact_submission_values(array $submission): array
{
    return [
        'name' => trim((string) ($submission['name'] ?? '')),
        'email' => trim((string) ($submission['email'] ?? '')),
        'phone' => trim((string) ($submission['phone'] ?? '')),
        'audience' => trim((string) ($submission['audience'] ?? '')),
        'service' => trim((string) ($submission['service'] ?? '')),
        'message' => trim((string) ($submission['message'] ?? '')),
        'privacyConfirmation' => trim((string) ($submission['privacyConfirmation'] ?? '')),
    ];
}

function contact_error_message(array $errors): string
{
    if (in_array('privacyConfirmation', $errors, true)) {
        return 'Bitte bestätigen Sie die Datenschutzerklärung, bevor die Anfrage gesendet wird.';
    }

    if (in_array('captcha', $errors, true)) {
        return 'Die Sicherheitsfrage wurde nicht korrekt beantwortet. Bitte prüfen Sie die Eingabe.';
    }

    if (in_array('token', $errors, true) || in_array('timing', $errors, true)) {
        return 'Das Formular wurde ungültig übermittelt. Bitte laden Sie die Seite kurz neu und senden Sie die Anfrage erneut.';
    }

    return 'Bitte prüfen Sie die Pflichtfelder und die E-Mail-Adresse. Die Anfrage konnte noch nicht übermittelt werden.';
}

function build_contact_form_view_model(array $siteConfig): array
{
    ensure_contact_session();

    $security = $siteConfig['security'] ?? [];
    $captchaConfig = $security['captcha'] ?? [];
    $captchaEnabled = (bool) ($captchaConfig['enabled'] ?? false);
    $captchaQuestion = '';

    if ($captchaEnabled) {
        $captcha = $_SESSION['contact_form']['captcha'] ?? null;

        if (!is_array($captcha) || empty($captcha['question']) || !isset($captcha['answer'])) {
            $left = random_int(2, 9);
            $right = random_int(1, 9);
            $captcha = [
                'question' => sprintf('Bitte lösen: %d + %d', $left, $right),
                'answer' => (string) ($left + $right),
                'generatedAt' => time(),
            ];
            $_SESSION['contact_form']['captcha'] = $captcha;
        }

        $captchaQuestion = (string) $captcha['question'];
    }

    if (empty($_SESSION['contact_form']['token'])) {
        $_SESSION['contact_form']['token'] = bin2hex(random_bytes(16));
    }

    return [
        'captchaEnabled' => $captchaEnabled,
        'captchaLabel' => (string) ($captchaConfig['label'] ?? 'Sicherheitsfrage'),
        'captchaQuestion' => $captchaQuestion,
        'formToken' => (string) $_SESSION['contact_form']['token'],
        'renderedAt' => time(),
    ];
}

function validate_contact_submission(array $siteConfig, array $submission): array
{
    ensure_contact_session();

    $messageLength = function_exists('mb_strlen')
        ? mb_strlen((string) ($submission['message'] ?? ''))
        : strlen((string) ($submission['message'] ?? ''));

    $errors = [];

    if (trim((string) ($submission['name'] ?? '')) === '') {
        $errors[] = 'name';
    }

    if (!filter_var((string) ($submission['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'email';
    }

    if (trim((string) ($submission['audience'] ?? '')) === '') {
        $errors[] = 'audience';
    }

    if (trim((string) ($submission['service'] ?? '')) === '') {
        $errors[] = 'service';
    }

    if ($messageLength < 12) {
        $errors[] = 'message';
    }

    if (($siteConfig['security']['privacyConsentRequired'] ?? true) && ($submission['privacyConfirmation'] ?? '') !== '1') {
        $errors[] = 'privacyConfirmation';
    }

    $minFormSeconds = max(0, (int) ($siteConfig['security']['minFormSeconds'] ?? 0));
    $renderedAt = (int) ($submission['formRenderedAt'] ?? 0);

    if ($renderedAt <= 0 || (time() - $renderedAt) < $minFormSeconds) {
        $errors[] = 'timing';
    }

    $sessionToken = (string) ($_SESSION['contact_form']['token'] ?? '');
    $formToken = trim((string) ($submission['formToken'] ?? ''));

    if ($sessionToken === '' || !hash_equals($sessionToken, $formToken)) {
        $errors[] = 'token';
    }

    $captchaConfig = $siteConfig['security']['captcha'] ?? [];
    if ((bool) ($captchaConfig['enabled'] ?? false)) {
        $expectedAnswer = (string) ($_SESSION['contact_form']['captcha']['answer'] ?? '');
        $submittedAnswer = trim((string) ($submission['captchaAnswer'] ?? ''));

        if ($expectedAnswer === '' || $submittedAnswer === '' || !hash_equals($expectedAnswer, $submittedAnswer)) {
            $errors[] = 'captcha';
        }
    }

    if ($errors === []) {
        unset($_SESSION['contact_form']);
    }

    return [
        'valid' => $errors === [],
        'errors' => $errors,
    ];
}

function contact_log_path(): string
{
    return dirname(__DIR__) . '/private/logs/contact-submissions.log';
}

function mail_log_path(): string
{
    return dirname(__DIR__) . '/private/logs/mail.log';
}

function review_cache_path(): string
{
    return dirname(__DIR__) . '/private/cache/google-reviews.json';
}

function append_contact_log(array $payload): void
{
    ensure_runtime_directory(dirname(contact_log_path()));
    $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($line === false) {
        return;
    }

    @file_put_contents(contact_log_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function append_mail_log(array $payload): void
{
    ensure_runtime_directory(dirname(mail_log_path()));
    $payload['loggedAt'] = date('c');
    $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($line === false) {
        return;
    }

    @file_put_contents(mail_log_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function send_contact_mail(array $siteConfig, array $submission): array
{
    $company = $siteConfig['company'] ?? [];
    $mailConfig = $siteConfig['mail'] ?? [];

    $result = [
        'ownerSent' => false,
        'customerSent' => false,
    ];

    if (!smtp_configured($mailConfig)) {
        append_mail_log([
            'type' => 'configuration',
            'message' => 'SMTP ist nicht vollständig konfiguriert.',
        ]);

        append_contact_log([
            'sentAt' => date('c'),
            'ownerSent' => false,
            'customerSent' => false,
            'name' => $submission['name'],
            'email' => $submission['email'],
            'phone' => $submission['phone'],
            'audience' => $submission['audience'],
            'service' => $submission['service'],
            'message' => $submission['message'],
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);

        return $result;
    }

    if (($mailConfig['sendOwnerNotification'] ?? true) === true) {
        $ownerMessage = build_owner_notification_message($siteConfig, $submission);
        $result['ownerSent'] = smtp_send_message($mailConfig, $ownerMessage);
    }

    if ($result['ownerSent'] && ($mailConfig['sendCustomerConfirmation'] ?? true) === true) {
        $customerMessage = build_customer_confirmation_message($siteConfig, $submission);
        $result['customerSent'] = smtp_send_message($mailConfig, $customerMessage);
    } else {
        $result['customerSent'] = !($mailConfig['sendCustomerConfirmation'] ?? true);
    }

    append_contact_log([
        'sentAt' => date('c'),
        'ownerSent' => $result['ownerSent'],
        'customerSent' => $result['customerSent'],
        'name' => $submission['name'],
        'email' => $submission['email'],
        'phone' => $submission['phone'],
        'audience' => $submission['audience'],
        'service' => $submission['service'],
        'message' => $submission['message'],
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);

    return $result;
}

function smtp_configured(array $mailConfig): bool
{
    $smtp = $mailConfig['smtp'] ?? [];

    return (bool) ($smtp['enabled'] ?? false)
        && trim((string) ($mailConfig['recipient'] ?? '')) !== ''
        && filter_var((string) ($mailConfig['fromEmail'] ?? ''), FILTER_VALIDATE_EMAIL)
        && trim((string) ($smtp['host'] ?? '')) !== ''
        && (int) ($smtp['port'] ?? 0) > 0
        && trim((string) ($smtp['username'] ?? '')) !== ''
        && trim((string) ($smtp['password'] ?? '')) !== '';
}

function build_owner_notification_message(array $siteConfig, array $submission): array
{
    $company = $siteConfig['company'];
    $mailConfig = $siteConfig['mail'];

    $subject = sprintf('[%s] Neue Anfrage: %s', $company['name'], $submission['service']);
    $summaryRows = [
        'Name' => $submission['name'],
        'E-Mail' => $submission['email'],
        'Telefon' => $submission['phone'] !== '' ? $submission['phone'] : 'nicht angegeben',
        'Bereich' => $submission['audience'],
        'Leistung' => $submission['service'],
        'Eingang' => date('d.m.Y H:i'),
        'IP-Adresse' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt'),
    ];

    $bodyHtml = render_mail_layout(
        $company,
        'Neue Anfrage über das Kontaktformular',
        'Auf der Website wurde eine neue Anfrage übermittelt.',
        render_mail_summary_table($summaryRows)
        . render_mail_message_box('Nachricht', nl2br(escape_mail_html($submission['message']), false))
    );

    $bodyText = implode(PHP_EOL, [
        'Neue Anfrage über das Kontaktformular',
        '',
        'Name: ' . $submission['name'],
        'E-Mail: ' . $submission['email'],
        'Telefon: ' . ($submission['phone'] !== '' ? $submission['phone'] : 'nicht angegeben'),
        'Bereich: ' . $submission['audience'],
        'Leistung: ' . $submission['service'],
        'Eingang: ' . date('d.m.Y H:i'),
        'IP-Adresse: ' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt'),
        '',
        'Nachricht:',
        $submission['message'],
    ]);

    return [
        'toEmail' => (string) $mailConfig['recipient'],
        'toName' => $company['name'],
        'replyToEmail' => $submission['email'],
        'replyToName' => $submission['name'],
        'subject' => $subject,
        'html' => $bodyHtml,
        'text' => $bodyText,
    ];
}

function build_customer_confirmation_message(array $siteConfig, array $submission): array
{
    $company = $siteConfig['company'];
    $mailConfig = $siteConfig['mail'];

    $subject = (string) ($mailConfig['customerConfirmationSubject'] ?? 'Ihre Anfrage bei ' . $company['name']);
    $summaryRows = [
        'Angefragter Bereich' => $submission['audience'],
        'Leistung' => $submission['service'],
        'Eingang' => date('d.m.Y H:i'),
        'Kontaktadresse' => $company['email'],
    ];

    $bodyHtml = render_mail_layout(
        $company,
        'Vielen Dank für Ihre Anfrage',
        'Ihre Nachricht wurde erfolgreich an IT-Tabelander übermittelt.',
        '<p style="margin:0 0 16px; color:#445463; line-height:1.7;">'
        . 'Die Anfrage wird geprüft und anschließend direkt beantwortet. Nachfolgend finden Sie eine kurze Zusammenfassung Ihrer übermittelten Angaben.'
        . '</p>'
        . render_mail_summary_table($summaryRows)
        . render_mail_message_box('Ihre Nachricht', nl2br(escape_mail_html($submission['message']), false))
    );

    $bodyText = implode(PHP_EOL, [
        'Vielen Dank für Ihre Anfrage bei ' . $company['name'],
        '',
        'Ihre Nachricht wurde erfolgreich übermittelt.',
        'Leistung: ' . $submission['service'],
        'Bereich: ' . $submission['audience'],
        'Eingang: ' . date('d.m.Y H:i'),
        '',
        'Ihre Nachricht:',
        $submission['message'],
        '',
        'Kontakt:',
        $company['name'],
        $company['email'],
        $company['phone'],
    ]);

    return [
        'toEmail' => $submission['email'],
        'toName' => $submission['name'],
        'replyToEmail' => (string) ($mailConfig['replyToEmail'] ?? $company['email']),
        'replyToName' => $company['name'],
        'subject' => $subject,
        'html' => $bodyHtml,
        'text' => $bodyText,
    ];
}

function render_mail_layout(array $company, string $title, string $intro, string $contentHtml): string
{
    $brand = escape_mail_html((string) ($company['name'] ?? 'IT-Tabelander'));
    $email = escape_mail_html((string) ($company['email'] ?? ''));
    $phone = escape_mail_html((string) ($company['phone'] ?? ''));
    $website = escape_mail_html(canonical_url());

    return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0; padding:24px; background:#eef3f8; color:#172635; font-family:Segoe UI, Arial, sans-serif;">'
        . '<div style="max-width:680px; margin:0 auto; background:#ffffff; border-radius:20px; overflow:hidden; box-shadow:0 20px 48px rgba(21,39,55,0.12);">'
        . '<div style="padding:24px 28px; background:linear-gradient(135deg, #08141d 0%, #103146 100%); color:#ffffff;">'
        . '<div style="display:inline-block; padding:8px 12px; border-radius:999px; background:rgba(255,255,255,0.12); font-size:12px; letter-spacing:0.16em; text-transform:uppercase;">'
        . $brand
        . '</div>'
        . '<h1 style="margin:16px 0 8px; font-size:28px; line-height:1.15;">' . escape_mail_html($title) . '</h1>'
        . '<p style="margin:0; color:rgba(255,255,255,0.84); line-height:1.7;">' . escape_mail_html($intro) . '</p>'
        . '</div>'
        . '<div style="padding:28px;">'
        . $contentHtml
        . '<div style="margin-top:24px; padding-top:20px; border-top:1px solid #dde6ef; color:#5a6f81; font-size:14px; line-height:1.7;">'
        . '<strong style="display:block; color:#172635;">' . $brand . '</strong>'
        . ($email !== '' ? '<div>E-Mail: <a href="mailto:' . $email . '" style="color:#b44720; text-decoration:none;">' . $email . '</a></div>' : '')
        . ($phone !== '' ? '<div>Telefon: <a href="tel:' . escape_mail_html(phone_href((string) $company['phone'])) . '" style="color:#b44720; text-decoration:none;">' . $phone . '</a></div>' : '')
        . '<div>Website: <a href="' . $website . '" style="color:#b44720; text-decoration:none;">' . $website . '</a></div>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</body></html>';
}

function render_mail_summary_table(array $rows): string
{
    $html = '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse; margin:0 0 20px;">';

    foreach ($rows as $label => $value) {
        $html .= '<tr>'
            . '<td style="padding:10px 0; border-bottom:1px solid #e5ecf3; width:34%; color:#5a6f81; vertical-align:top;">' . escape_mail_html((string) $label) . '</td>'
            . '<td style="padding:10px 0; border-bottom:1px solid #e5ecf3; color:#172635; font-weight:600;">' . escape_mail_html((string) $value) . '</td>'
            . '</tr>';
    }

    return $html . '</table>';
}

function render_mail_message_box(string $title, string $contentHtml): string
{
    return '<div style="padding:18px 20px; border-radius:16px; background:#f6f9fc; border:1px solid #e3ebf3;">'
        . '<div style="margin:0 0 10px; color:#5a6f81; font-size:12px; letter-spacing:0.12em; text-transform:uppercase;">' . escape_mail_html($title) . '</div>'
        . '<div style="color:#172635; line-height:1.8;">' . $contentHtml . '</div>'
        . '</div>';
}

function escape_mail_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function smtp_send_message(array $mailConfig, array $message): bool
{
    $smtp = $mailConfig['smtp'] ?? [];

    try {
        $socket = smtp_open_connection($smtp);
        $ehloDomain = trim((string) ($smtp['ehloDomain'] ?? 'localhost'));
        smtp_expect($socket, [220]);
        smtp_command($socket, 'EHLO ' . $ehloDomain, [250]);

        if (strtolower((string) ($smtp['encryption'] ?? '')) === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);

            $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('TLS konnte nicht aktiviert werden.');
            }

            smtp_command($socket, 'EHLO ' . $ehloDomain, [250]);
        }

        $username = trim((string) ($smtp['username'] ?? ''));
        $password = (string) ($smtp['password'] ?? '');

        if ($username !== '' && $password !== '') {
            smtp_command($socket, 'AUTH LOGIN', [334]);
            smtp_command($socket, base64_encode($username), [334]);
            smtp_command($socket, base64_encode($password), [235]);
        }

        $fromEmail = (string) $mailConfig['fromEmail'];
        $fromName = (string) ($mailConfig['fromName'] ?? '');
        $replyToEmail = (string) ($message['replyToEmail'] ?? $mailConfig['replyToEmail'] ?? $fromEmail);
        $replyToName = (string) ($message['replyToName'] ?? $fromName);

        smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $message['toEmail'] . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $mime = build_mime_message(
            $fromEmail,
            $fromName,
            (string) $message['toEmail'],
            (string) ($message['toName'] ?? ''),
            $replyToEmail,
            $replyToName,
            (string) $message['subject'],
            (string) $message['html'],
            (string) $message['text'],
            trim((string) ($smtp['ehloDomain'] ?? 'localhost'))
        );

        fwrite($socket, dot_stuff(normalize_crlf($mime)) . "\r\n.\r\n");
        smtp_expect($socket, [250]);
        smtp_command($socket, 'QUIT', [221]);
        fclose($socket);

        return true;
    } catch (Throwable $exception) {
        append_mail_log([
            'type' => 'smtp',
            'to' => $message['toEmail'] ?? '',
            'subject' => $message['subject'] ?? '',
            'message' => $exception->getMessage(),
        ]);

        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }

        return false;
    }
}

function smtp_open_connection(array $smtp)
{
    $host = trim((string) ($smtp['host'] ?? ''));
    $port = (int) ($smtp['port'] ?? 0);
    $timeout = max(3, (int) ($smtp['timeout'] ?? 12));
    $encryption = strtolower((string) ($smtp['encryption'] ?? ''));
    $transport = $encryption === 'ssl' ? 'ssl://' : '';

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errorCode,
        $errorMessage,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        throw new RuntimeException('SMTP-Verbindung fehlgeschlagen: ' . $errorMessage . ' (' . $errorCode . ')');
    }

    stream_set_timeout($socket, $timeout);

    return $socket;
}

function smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");

    return smtp_expect($socket, $expectedCodes);
}

function smtp_expect($socket, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP-Antwort unerwartet: ' . trim($response));
    }

    return $response;
}

function build_mime_message(
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $toName,
    string $replyToEmail,
    string $replyToName,
    string $subject,
    string $html,
    string $text,
    string $messageDomain
): string {
    $boundary = 'b_' . bin2hex(random_bytes(12));
    $messageId = bin2hex(random_bytes(12)) . '@' . preg_replace('/[^a-z0-9.-]/i', '', $messageDomain);

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . $messageId . '>',
        'From: ' . format_email_address($fromEmail, $fromName),
        'To: ' . format_email_address($toEmail, $toName),
        'Reply-To: ' . format_email_address($replyToEmail, $replyToName),
        'Subject: ' . mime_header($subject),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    $body = [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        normalize_crlf($text),
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        normalize_crlf($html),
        '--' . $boundary . '--',
        '',
    ];

    return implode("\r\n", array_merge($headers, [''], $body));
}

function format_email_address(string $email, string $name = ''): string
{
    $cleanEmail = trim($email);
    $cleanName = trim($name);

    if ($cleanName === '') {
        return '<' . $cleanEmail . '>';
    }

    return mime_header($cleanName) . ' <' . $cleanEmail . '>';
}

function mime_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function normalize_crlf(string $value): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $value);

    return str_replace("\n", "\r\n", $normalized);
}

function dot_stuff(string $value): string
{
    return (string) preg_replace('/(?m)^\./', '..', $value);
}

function read_cached_reviews(int $ttl): ?array
{
    $cacheFile = review_cache_path();

    if (!is_file($cacheFile)) {
        return null;
    }

    $isFresh = (time() - (int) filemtime($cacheFile)) <= $ttl;
    $decoded = json_decode((string) file_get_contents($cacheFile), true);

    if (!is_array($decoded)) {
        return null;
    }

    if ($isFresh) {
        return $decoded;
    }

    $decoded['_stale'] = true;

    return $decoded;
}

function write_cached_reviews(array $payload): void
{
    ensure_runtime_directory(dirname(review_cache_path()));
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($encoded === false) {
        return;
    }

    @file_put_contents(review_cache_path(), $encoded, LOCK_EX);
}

function ensure_runtime_directory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    @mkdir($directory, 0775, true);
}

function manual_reviews_payload(array $company): array
{
    $reviews = array_values(array_filter(array_map(static function (mixed $entry): ?array {
        if (!is_array($entry)) {
            return null;
        }

        return [
            'author' => trim((string) ($entry['author'] ?? 'Kundenstimme')),
            'rating' => trim((string) ($entry['rating'] ?? '')),
            'text' => trim((string) ($entry['text'] ?? '')),
            'relativeTime' => trim((string) ($entry['relativeTime'] ?? '')),
            'url' => trim((string) ($entry['url'] ?? '')),
            'source' => 'Manuell gepflegte Referenz',
        ];
    }, $company['manualTestimonials'] ?? [])));

    return [
        'source' => 'manual',
        'message' => 'Aktuell werden gepflegte Rückmeldungen und Referenzen angezeigt.',
        'reviews' => $reviews,
    ];
}

function google_reviews_payload(array $company): array
{
    $cached = read_cached_reviews((int) ($company['reviewCacheTtl'] ?? 43200));

    if (is_array($cached) && empty($cached['_stale'])) {
        return $cached;
    }

    $placeId = rawurlencode((string) $company['googlePlaceId']);
    $endpoint = 'https://places.googleapis.com/v1/places/' . $placeId;
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Goog-Api-Key: ' . $company['googleApiKey'],
        'X-Goog-FieldMask: displayName,rating,userRatingCount,reviews,googleMapsUri',
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($endpoint, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $statusLine = $responseHeaders[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $statusCode = isset($matches[1]) ? (int) $matches[1] : 0;

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
        if (is_array($cached) && !empty($cached['_stale'])) {
            unset($cached['_stale']);
            $cached['message'] = 'Google konnte gerade nicht erreicht werden. Es wird der zuletzt zwischengespeicherte Stand angezeigt.';

            return $cached;
        }

        throw new RuntimeException('Google reviews request failed.');
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid Google reviews response.');
    }

    $placeMapsUri = trim((string) ($decoded['googleMapsUri'] ?? ''));
    $reviews = array_values(array_filter(array_map(static function (mixed $entry) use ($placeMapsUri): ?array {
        if (!is_array($entry)) {
            return null;
        }

        $text = trim((string) ($entry['text']['text'] ?? ''));

        if ($text === '') {
            return null;
        }

        return [
            'author' => trim((string) ($entry['authorAttribution']['displayName'] ?? 'Google-Bewertung')),
            'rating' => trim((string) ($entry['rating'] ?? '')),
            'text' => $text,
            'relativeTime' => trim((string) ($entry['relativePublishTimeDescription'] ?? '')),
            'url' => trim((string) ($entry['googleMapsUri'] ?? $placeMapsUri)),
            'source' => 'Google Places API',
        ];
    }, $decoded['reviews'] ?? [])));

    $payload = [
        'source' => 'google',
        'message' => sprintf(
            'Live-Sync über die Google Places API. Zuletzt aktualisiert: %s.',
            date('d.m.Y H:i')
        ),
        'place' => trim((string) ($decoded['displayName']['text'] ?? $company['name'])),
        'overallRating' => trim((string) ($decoded['rating'] ?? '')),
        'reviewCount' => trim((string) ($decoded['userRatingCount'] ?? '')),
        'reviews' => array_slice($reviews, 0, 6),
    ];

    write_cached_reviews($payload);

    return $payload;
}

function load_reviews_payload(array $company): array
{
    if (company_has_google_reviews($company)) {
        return google_reviews_payload($company);
    }

    if (company_has_manual_reviews($company)) {
        return manual_reviews_payload($company);
    }

    return [
        'source' => 'setup',
        'message' => 'API noch nicht konfiguriert. Tragen Sie GOOGLE_PLACE_ID und GOOGLE_PLACES_API_KEY in der Konfiguration oder als Umgebungsvariablen ein.',
        'reviews' => [],
    ];
}
