<?php
declare(strict_types=1);

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

function dolibarr_log_path(): string
{
    return dirname(__DIR__) . '/logs/dolibarr.log';
}

function manual_reviews_path(): string
{
    return dirname(__DIR__) . '/private/data/reviews.json';
}

function append_contact_log(array $payload, int $retentionDays = 30): void
{
    $entry = [
        'loggedAt' => date('c'),
        'requestId' => (string) ($payload['requestId'] ?? ''),
        'status' => (string) ($payload['status'] ?? 'unknown'),
        'ownerSent' => (bool) ($payload['ownerSent'] ?? false),
        'customerSent' => (bool) ($payload['customerSent'] ?? false),
    ];

    append_runtime_log(contact_log_path(), $entry, $retentionDays);
}

function append_mail_log(array $payload, int $retentionDays = 30): void
{
    ensure_runtime_directory(dirname(mail_log_path()));
    $payload['loggedAt'] = date('c');
    append_runtime_log(mail_log_path(), $payload, $retentionDays);
}

function append_dolibarr_log(array $payload, int $retentionDays = 30): void
{
    append_runtime_log(dolibarr_log_path(), [
        'loggedAt' => date('c'),
        'requestId' => (string) ($payload['requestId'] ?? ''),
        'status' => (string) ($payload['status'] ?? 'unknown'),
        'step' => (string) ($payload['step'] ?? ''),
        'httpStatus' => max(0, (int) ($payload['httpStatus'] ?? 0)),
        'thirdpartyId' => max(0, (int) ($payload['thirdpartyId'] ?? 0)),
        'ticketId' => max(0, (int) ($payload['ticketId'] ?? 0)),
        'proposalId' => max(0, (int) ($payload['proposalId'] ?? 0)),
    ], $retentionDays);
}

function append_runtime_log(string $path, array $payload, int $retentionDays): void
{
    if ($retentionDays <= 0) {
        return;
    }

    ensure_runtime_directory(dirname($path));
    prune_runtime_log($path, $retentionDays);

    $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line !== false) {
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function prune_runtime_log(string $path, int $retentionDays): void
{
    if (!is_file($path)) {
        return;
    }

    if ($retentionDays <= 0) {
        @file_put_contents($path, '', LOCK_EX);
        return;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    $cutoff = time() - ($retentionDays * 86400);
    $retained = [];

    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        $loggedAt = is_array($entry) ? (string) ($entry['loggedAt'] ?? '') : '';
        $timestamp = $loggedAt !== '' ? strtotime($loggedAt) : false;

        if ($timestamp !== false && $timestamp >= $cutoff) {
            $retained[] = $line;
        }
    }

    $contents = $retained === [] ? '' : implode(PHP_EOL, $retained) . PHP_EOL;
    @file_put_contents($path, $contents, LOCK_EX);
}

function ensure_runtime_directory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    @mkdir($directory, 0775, true);
}
