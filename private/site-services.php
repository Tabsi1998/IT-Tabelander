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
    $fieldLabels = [
        'name' => 'Name',
        'email' => 'E-Mail-Adresse',
        'audience' => 'Anliegen',
        'service' => 'Leistung',
        'message' => 'Nachricht mit mindestens 12 Zeichen',
    ];

    if (in_array('privacyConfirmation', $errors, true)) {
        return 'Bitte bestätigen Sie die Datenschutzerklärung, bevor die Anfrage gesendet wird.';
    }

    if (in_array('captcha', $errors, true)) {
        return 'Die Sicherheitsfrage wurde nicht korrekt beantwortet. Bitte prüfen Sie die Eingabe.';
    }

    if (in_array('token', $errors, true) || in_array('timing', $errors, true)) {
        return 'Das Formular wurde ungültig übermittelt. Bitte laden Sie die Seite kurz neu und senden Sie die Anfrage erneut.';
    }

    $missingFields = array_values(array_intersect_key($fieldLabels, array_flip($errors)));

    if ($missingFields !== []) {
        return 'Bitte prüfen Sie folgende Angaben: ' . implode(', ', $missingFields) . '.';
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

function manual_reviews_path(): string
{
    return dirname(__DIR__) . '/private/data/reviews.json';
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
            'diagnostics' => smtp_configuration_diagnostics($mailConfig),
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

function smtp_configuration_diagnostics(array $mailConfig): array
{
    $smtp = $mailConfig['smtp'] ?? [];
    $passwordFile = function_exists('config_sibling_secret_file')
        ? config_sibling_secret_file('smtp-password.txt')
        : '';

    return [
        'enabled' => (bool) ($smtp['enabled'] ?? false),
        'recipientSet' => trim((string) ($mailConfig['recipient'] ?? '')) !== '',
        'fromEmailValid' => filter_var((string) ($mailConfig['fromEmail'] ?? ''), FILTER_VALIDATE_EMAIL) !== false,
        'hostSet' => trim((string) ($smtp['host'] ?? '')) !== '',
        'portSet' => (int) ($smtp['port'] ?? 0) > 0,
        'usernameSet' => trim((string) ($smtp['username'] ?? '')) !== '',
        'passwordLoaded' => trim((string) ($smtp['password'] ?? '')) !== '',
        'passwordFileExists' => $passwordFile !== '' && is_file($passwordFile),
        'passwordFileReadable' => $passwordFile !== '' && is_readable($passwordFile),
        'passwordFile' => $passwordFile,
    ];
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
        'Anliegen' => $submission['audience'],
        'Leistung' => $submission['service'],
        'Eingang' => date('d.m.Y H:i'),
        'IP-Adresse' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt'),
    ];

    $bodyHtml = render_mail_layout(
        $company,
        'Neue Anfrage über das Kontaktformular',
        'Auf der Website wurde eine neue Anfrage übermittelt. Die wichtigsten Angaben sind unten zusammengefasst.',
        render_mail_summary_table($summaryRows)
        . render_mail_message_box('Nachricht', nl2br(escape_mail_html($submission['message']), false))
    );

    $bodyText = implode(PHP_EOL, [
        'Neue Anfrage über das Kontaktformular',
        '',
        'Name: ' . $submission['name'],
        'E-Mail: ' . $submission['email'],
        'Telefon: ' . ($submission['phone'] !== '' ? $submission['phone'] : 'nicht angegeben'),
        'Anliegen: ' . $submission['audience'],
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
        'Anliegen' => $submission['audience'],
        'Leistung' => $submission['service'],
        'Eingang' => date('d.m.Y H:i'),
        'Kontaktadresse' => $company['email'],
    ];

    $bodyHtml = render_mail_layout(
        $company,
        'Vielen Dank für Ihre Anfrage',
        'Ihre Nachricht wurde erfolgreich an IT-Tabelander übermittelt.',
        '<p style="color:#1f2937; font-size:15px; line-height:1.6; margin:0 0 15px 0;">Guten Tag ' . escape_mail_html($submission['name']) . ',</p>'
        . '<p style="color:#445463; font-size:15px; line-height:1.6; margin:0 0 18px 0;">'
        . 'vielen Dank für Ihre Nachricht. Die Anfrage wird geprüft und anschließend direkt beantwortet. Nachfolgend finden Sie eine kurze Zusammenfassung Ihrer übermittelten Angaben.'
        . '</p>'
        . render_mail_summary_table($summaryRows)
        . render_mail_message_box('Ihre Nachricht', nl2br(escape_mail_html($submission['message']), false))
    );

    $bodyText = implode(PHP_EOL, [
        'Vielen Dank für Ihre Anfrage bei ' . $company['name'],
        '',
        'Ihre Nachricht wurde erfolgreich übermittelt.',
        'Leistung: ' . $submission['service'],
        'Anliegen: ' . $submission['audience'],
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
    $owner = escape_mail_html((string) ($company['owner'] ?? ''));
    $address = escape_mail_html(company_address_inline($company));
    $bannerLogo = escape_mail_html(email_asset_url('img/logo/IT-Tabelander Banner Dunkel Transparent.png'));
    $markLogo = escape_mail_html(email_asset_url('img/logo/IT-Tabelander Logo Dunkel Transparent.png'));
    $profession = trim((string) ($company['profession'] ?? ''));
    $professionDetail = trim((string) ($company['professionDetail'] ?? ''));
    $footerInfo = trim(implode(' | ', array_filter([$profession, $professionDetail])));

    return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
        . '<body style="margin:0; padding:0; background-color:#f4f6f8; color:#1f2937; font-family:Arial, Helvetica, sans-serif;">'
        . '<table border="0" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#f4f6f8; color:#1f2937; font-family:Arial, Helvetica, sans-serif; margin:0; padding:0; width:100%;">'
        . '<tbody><tr><td align="center" style="padding:20px 10px;">'
        . '<table border="0" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#ffffff; border-collapse:collapse; border:1px solid #e5e7eb; max-width:640px; width:100%;">'
        . '<tbody>'
        . '<tr><td style="background-color:#ffffff; padding:22px 24px 20px 24px;">'
        . '<img alt="' . $brand . '" src="' . $bannerLogo . '" width="360" style="border:0; display:block; height:auto; margin:0 auto; max-width:360px; outline:none; text-decoration:none; width:100%;">'
        . '</td></tr>'
        . '<tr><td style="background-color:#ff5a24; font-size:0; height:4px; line-height:4px;">&nbsp;</td></tr>'
        . '<tr><td style="padding:28px 34px 8px 34px;">'
        . '<div style="color:#ff5a24; font-size:13px; font-weight:bold; letter-spacing:0.12em; text-transform:uppercase;">' . $brand . '</div>'
        . '<h1 style="color:#111827; font-size:28px; line-height:1.15; margin:10px 0 0 0;">' . escape_mail_html($title) . '</h1>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 34px 30px 34px;">'
        . '<p style="color:#445463; font-size:15px; line-height:1.6; margin:0 0 18px 0;">' . escape_mail_html($intro) . '</p>'
        . $contentHtml
        . '<p style="color:#1f2937; font-size:15px; line-height:1.6; margin:24px 0 0 0;">Mit freundlichen Grüßen<br><strong>' . ($owner !== '' ? $owner : $brand) . '</strong></p>'
        . '</td></tr>'
        . '<tr><td style="background-color:#f9fafb; border-top:1px solid #e5e7eb; padding:20px 34px;">'
        . '<table border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse; width:100%;"><tbody><tr>'
        . '<td style="padding:0 20px 0 0; vertical-align:middle; width:78px;">'
        . '<img alt="' . $brand . '" src="' . $markLogo . '" width="58" style="border:0; display:block; height:auto; margin:0 auto; max-width:58px; outline:none; text-decoration:none; width:58px;">'
        . '</td>'
        . '<td style="color:#4b5563; font-size:13px; line-height:1.55; vertical-align:middle;">'
        . '<div style="color:#081221; font-size:14px; font-weight:bold; margin:0 0 4px 0;">' . $brand . '</div>'
        . ($owner !== '' ? $owner . '<br>' : '')
        . ($address !== '' ? $address . '<br>' : '')
        . ($email !== '' ? '<a href="mailto:' . $email . '" style="color:#1b3348; text-decoration:none;">' . $email . '</a><br>' : '')
        . ($phone !== '' ? '<a href="tel:' . escape_mail_html(phone_href((string) $company['phone'])) . '" style="color:#1b3348; text-decoration:none;">' . $phone . '</a><br>' : '')
        . '<a href="' . $website . '" style="color:#1b3348; text-decoration:none;">' . $website . '</a>'
        . '</td></tr></tbody></table>'
        . '</td></tr>'
        . ($footerInfo !== '' ? '<tr><td style="background-color:#ffffff; border-top:1px solid #e5e7eb; color:#6b7280; font-size:12px; line-height:1.5; padding:12px 34px; text-align:center;">' . escape_mail_html($footerInfo) . '</td></tr>' : '')
        . '</tbody></table>'
        . '</td></tr></tbody></table>'
        . '</body></html>';
}

function render_mail_summary_table(array $rows): string
{
    $html = '<table border="0" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#f9fafb; border-collapse:collapse; border-left:4px solid #ff5a24; margin:20px 0 22px 0; width:100%;"><tbody><tr><td style="padding:14px 16px;">';

    foreach ($rows as $label => $value) {
        $html .= '<div style="color:#1f2937; font-size:14px; line-height:1.6; margin:0 0 3px 0;">'
            . '<strong>' . escape_mail_html((string) $label) . ':</strong> '
            . escape_mail_html((string) $value)
            . '</div>';
    }

    return $html . '</td></tr></tbody></table>';
}

function render_mail_message_box(string $title, string $contentHtml): string
{
    return '<table border="0" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#f9fafb; border-collapse:collapse; border-left:4px solid #1b3348; margin:20px 0 22px 0; width:100%;">'
        . '<tbody><tr><td style="padding:14px 16px;">'
        . '<div style="color:#ff5a24; font-size:12px; font-weight:bold; letter-spacing:0.12em; margin:0 0 8px 0; text-transform:uppercase;">' . escape_mail_html($title) . '</div>'
        . '<div style="color:#1f2937; font-size:15px; line-height:1.65;">' . $contentHtml . '</div>'
        . '</td></tr></tbody></table>';
}

function escape_mail_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function email_asset_url(string $path): string
{
    $segments = array_map('rawurlencode', explode('/', ltrim($path, '/')));

    return canonical_url('public/assets/' . implode('/', $segments));
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

            $cryptoEnabled = stream_socket_enable_crypto($socket, true, smtp_crypto_method());
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
        $smtp = $mailConfig['smtp'] ?? [];

        append_mail_log([
            'type' => 'smtp',
            'to' => $message['toEmail'] ?? '',
            'subject' => $message['subject'] ?? '',
            'host' => $smtp['host'] ?? '',
            'port' => $smtp['port'] ?? '',
            'encryption' => $smtp['encryption'] ?? '',
            'allowSelfSigned' => $smtp['allowSelfSigned'] ?? null,
            'verifyPeer' => $smtp['verifyPeer'] ?? null,
            'verifyPeerName' => $smtp['verifyPeerName'] ?? null,
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
    $verifyPeer = (bool) ($smtp['verifyPeer'] ?? true);
    $verifyPeerName = (bool) ($smtp['verifyPeerName'] ?? $verifyPeer);
    $allowSelfSigned = (bool) ($smtp['allowSelfSigned'] ?? false);

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => $verifyPeer,
            'verify_peer_name' => $verifyPeerName,
            'allow_self_signed' => $allowSelfSigned,
            'SNI_enabled' => $verifyPeerName,
            'capture_peer_cert' => false,
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

function smtp_crypto_method(): int
{
    $method = 0;

    foreach ([
        'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT',
        'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT',
        'STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT',
        'STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT',
    ] as $constantName) {
        if (defined($constantName)) {
            $method |= constant($constantName);
        }
    }

    return $method !== 0 ? $method : STREAM_CRYPTO_METHOD_TLS_CLIENT;
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

function normalize_manual_review(mixed $entry): ?array
{
    if (!is_array($entry)) {
        return null;
    }

    $author = trim((string) ($entry['author'] ?? $entry['name'] ?? ''));
    $text = trim((string) ($entry['text'] ?? $entry['message'] ?? ''));

    if ($author === '' || $text === '') {
        return null;
    }

    $rating = trim((string) ($entry['rating'] ?? '5'));
    $ratingNumber = (float) str_replace(',', '.', $rating);

    if ($ratingNumber < 1 || $ratingNumber > 5) {
        $rating = '';
    }

    return [
        'author' => $author,
        'rating' => $rating,
        'text' => $text,
        'relativeTime' => trim((string) ($entry['relativeTime'] ?? $entry['date'] ?? 'Kundenrezension')),
        'url' => trim((string) ($entry['url'] ?? '')),
        'source' => 'Manuell gepflegte Rezension',
    ];
}

function read_manual_reviews_file(): array
{
    $reviewsFile = manual_reviews_path();

    if (!is_file($reviewsFile)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($reviewsFile), true);

    if (!is_array($decoded)) {
        return [];
    }

    $entries = is_array($decoded['reviews'] ?? null) ? $decoded['reviews'] : $decoded;

    return array_values(array_filter(array_map('normalize_manual_review', $entries)));
}

function manual_reviews_payload(array $company): array
{
    $reviews = read_manual_reviews_file();

    if ($reviews === [] && !empty($company['manualTestimonials']) && is_array($company['manualTestimonials'])) {
        $reviews = array_values(array_filter(array_map('normalize_manual_review', $company['manualTestimonials'])));
    }

    return [
        'source' => 'manual',
        'message' => $reviews === []
            ? ''
            : 'Aktuell werden gepflegte Kundenrezensionen angezeigt.',
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

    return manual_reviews_payload($company);
}
