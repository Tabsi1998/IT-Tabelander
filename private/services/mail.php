<?php
declare(strict_types=1);

function send_contact_mail(array $siteConfig, array $submission): array
{
    $company = $siteConfig['company'] ?? [];
    $mailConfig = $siteConfig['mail'] ?? [];
    $requestId = bin2hex(random_bytes(6));
    $logRetentionDays = max(0, (int) ($siteConfig['logging']['retentionDays'] ?? 30));

    $result = [
        'ownerSent' => false,
        'customerSent' => false,
        'requestId' => $requestId,
    ];

    if (!smtp_configured($mailConfig)) {
        append_mail_log([
            'type' => 'configuration',
            'requestId' => $requestId,
            'message' => 'SMTP ist nicht vollständig konfiguriert.',
            'diagnostics' => smtp_configuration_diagnostics($mailConfig),
        ], $logRetentionDays);

        append_contact_log([
            'requestId' => $requestId,
            'status' => 'configuration_error',
            'ownerSent' => false,
            'customerSent' => false,
        ], $logRetentionDays);

        return $result;
    }

    if (($mailConfig['sendOwnerNotification'] ?? true) === true) {
        $ownerMessage = build_owner_notification_message($siteConfig, $submission);
        $result['ownerSent'] = smtp_send_message($mailConfig, $ownerMessage, [
            'requestId' => $requestId,
            'mailRole' => 'owner',
            'logRetentionDays' => $logRetentionDays,
        ]);
    }

    if ($result['ownerSent'] && ($mailConfig['sendCustomerConfirmation'] ?? true) === true) {
        $customerMessage = build_customer_confirmation_message($siteConfig, $submission);
        $result['customerSent'] = smtp_send_message($mailConfig, $customerMessage, [
            'requestId' => $requestId,
            'mailRole' => 'customer',
            'logRetentionDays' => $logRetentionDays,
        ]);
    } else {
        $result['customerSent'] = !($mailConfig['sendCustomerConfirmation'] ?? true);
    }

    append_contact_log([
        'requestId' => $requestId,
        'status' => $result['ownerSent'] ? 'accepted' : 'delivery_error',
        'ownerSent' => $result['ownerSent'],
        'customerSent' => $result['customerSent'],
    ], $logRetentionDays);

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
        'enabledRequested' => (bool) ($smtp['enabledRequested'] ?? false),
        'recipientSet' => trim((string) ($mailConfig['recipient'] ?? '')) !== '',
        'fromEmailValid' => filter_var((string) ($mailConfig['fromEmail'] ?? ''), FILTER_VALIDATE_EMAIL) !== false,
        'hostSet' => trim((string) ($smtp['host'] ?? '')) !== '',
        'portSet' => (int) ($smtp['port'] ?? 0) > 0,
        'usernameSet' => trim((string) ($smtp['username'] ?? '')) !== '',
        'passwordLoaded' => trim((string) ($smtp['password'] ?? '')) !== '',
        'passwordFileExists' => $passwordFile !== '' && is_file($passwordFile),
        'passwordFileReadable' => $passwordFile !== '' && is_readable($passwordFile),
    ];
}

function smtp_public_config(array $mailConfig): array
{
    $smtp = $mailConfig['smtp'] ?? [];

    return [
        'enabled' => (bool) ($smtp['enabled'] ?? false),
        'host' => (string) ($smtp['host'] ?? ''),
        'port' => (int) ($smtp['port'] ?? 0),
        'encryption' => (string) ($smtp['encryption'] ?? ''),
        'username' => (string) ($smtp['username'] ?? ''),
        'passwordLoaded' => trim((string) ($smtp['password'] ?? '')) !== '',
        'allowSelfSigned' => (bool) ($smtp['allowSelfSigned'] ?? false),
        'verifyPeer' => (bool) ($smtp['verifyPeer'] ?? true),
        'verifyPeerName' => (bool) ($smtp['verifyPeerName'] ?? true),
        'timeout' => (int) ($smtp['timeout'] ?? 0),
        'ehloDomain' => (string) ($smtp['ehloDomain'] ?? ''),
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

function smtp_send_message(array $mailConfig, array $message, array $context = []): bool
{
    $smtp = $mailConfig['smtp'] ?? [];
    $trace = [];
    $startedAt = microtime(true);
    $logRetentionDays = max(0, (int) ($context['logRetentionDays'] ?? 30));

    try {
        $socket = smtp_open_connection($smtp);
        smtp_trace($trace, 'connect', null, 'connected to ' . (string) ($smtp['host'] ?? '') . ':' . (string) ($smtp['port'] ?? ''));
        $ehloDomain = trim((string) ($smtp['ehloDomain'] ?? 'localhost'));
        smtp_expect($socket, [220], $trace, 'banner');
        smtp_command($socket, 'EHLO ' . $ehloDomain, [250], $trace, 'EHLO');

        if (strtolower((string) ($smtp['encryption'] ?? '')) === 'tls') {
            smtp_command($socket, 'STARTTLS', [220], $trace, 'STARTTLS');

            $cryptoEnabled = stream_socket_enable_crypto($socket, true, smtp_crypto_method());
            smtp_trace($trace, 'TLS', null, $cryptoEnabled === true ? 'enabled' : 'failed');
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('TLS konnte nicht aktiviert werden.');
            }

            smtp_command($socket, 'EHLO ' . $ehloDomain, [250], $trace, 'EHLO after STARTTLS');
        }

        $username = trim((string) ($smtp['username'] ?? ''));
        $password = (string) ($smtp['password'] ?? '');

        if ($username !== '' && $password !== '') {
            smtp_command($socket, 'AUTH LOGIN', [334], $trace, 'AUTH LOGIN');
            smtp_command($socket, base64_encode($username), [334], $trace, 'AUTH username', true);
            smtp_command($socket, base64_encode($password), [235], $trace, 'AUTH password', true);
        }

        $fromEmail = (string) $mailConfig['fromEmail'];
        $fromName = (string) ($mailConfig['fromName'] ?? '');
        $replyToEmail = (string) ($message['replyToEmail'] ?? $mailConfig['replyToEmail'] ?? $fromEmail);
        $replyToName = (string) ($message['replyToName'] ?? $fromName);

        smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250], $trace, 'MAIL FROM');
        smtp_command($socket, 'RCPT TO:<' . $message['toEmail'] . '>', [250, 251], $trace, 'RCPT TO');
        smtp_command($socket, 'DATA', [354], $trace, 'DATA');

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
        smtp_expect($socket, [250], $trace, 'message accepted');
        smtp_command($socket, 'QUIT', [221], $trace, 'QUIT');
        fclose($socket);

        append_mail_log([
            'type' => 'smtp_success',
            'requestId' => $context['requestId'] ?? '',
            'mailRole' => $context['mailRole'] ?? '',
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            'trace' => $trace,
        ], $logRetentionDays);

        return true;
    } catch (Throwable $exception) {
        $smtp = $mailConfig['smtp'] ?? [];

        append_mail_log([
            'type' => 'smtp',
            'requestId' => $context['requestId'] ?? '',
            'mailRole' => $context['mailRole'] ?? '',
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            'errorType' => get_class($exception),
            'trace' => $trace,
        ], $logRetentionDays);

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

function smtp_command($socket, string $command, array $expectedCodes, ?array &$trace = null, string $label = '', bool $redactCommand = false): string
{
    fwrite($socket, $command . "\r\n");
    smtp_trace($trace, $label !== '' ? $label : 'command', $redactCommand ? '[redacted]' : $command);

    return smtp_expect($socket, $expectedCodes, $trace, $label !== '' ? $label : 'response');
}

function smtp_expect($socket, array $expectedCodes, ?array &$trace = null, string $label = ''): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);
    smtp_trace($trace, $label !== '' ? $label : 'response', null, smtp_compact_response($response), $code);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP-Antwort unerwartet: ' . trim($response));
    }

    return $response;
}

function smtp_trace(?array &$trace, string $step, ?string $command = null, ?string $response = null, ?int $code = null): void
{
    if ($trace === null) {
        return;
    }

    $entry = [
        'step' => $step,
    ];

    if ($code !== null) {
        $entry['code'] = $code;
    }

    $trace[] = $entry;
}

function smtp_compact_response(string $response): string
{
    $compact = trim(str_replace(["\r\n", "\r", "\n"], ' | ', $response));

    if (strlen($compact) > 800) {
        return substr($compact, 0, 800) . '...';
    }

    return $compact;
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
