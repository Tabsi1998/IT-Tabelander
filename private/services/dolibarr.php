<?php
declare(strict_types=1);

function dolibarr_configured(array $config): bool
{
    return (bool) ($config['enabled'] ?? false)
        && filter_var((string) ($config['baseUrl'] ?? ''), FILTER_VALIDATE_URL) !== false
        && str_starts_with(strtolower((string) ($config['baseUrl'] ?? '')), 'https://')
        && trim((string) ($config['apiKey'] ?? '')) !== ''
        && is_numeric($config['vatRate'] ?? null);
}

function dolibarr_api_url(array $config, string $path): string
{
    $baseUrl = rtrim((string) ($config['baseUrl'] ?? ''), '/');
    if (!str_ends_with(strtolower($baseUrl), '/api/index.php')) {
        $baseUrl .= '/api/index.php';
    }

    return $baseUrl . '/' . ltrim($path, '/');
}

function dolibarr_http_request(array $config, string $method, string $path, ?array $payload = null): array
{
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json; charset=utf-8',
        'DOLAPIKEY: ' . trim((string) ($config['apiKey'] ?? '')),
        'Connection: close',
    ];

    $entity = max(0, (int) ($config['entity'] ?? 0));
    if ($entity > 0) {
        $headers[] = 'DOLAPIENTITY: ' . $entity;
    }

    $content = $payload === null
        ? ''
        : (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'timeout' => max(2, min(20, (int) ($config['timeout'] ?? 8))),
            'ignore_errors' => true,
            'user_agent' => 'IT-Tabelander-Website/1.0',
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $responseBody = @file_get_contents(dolibarr_api_url($config, $path), false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', (string) $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    $decoded = is_string($responseBody) && trim($responseBody) !== ''
        ? json_decode($responseBody, true)
        : null;

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'data' => $decoded,
    ];
}

function dolibarr_request(array $config, string $method, string $path, ?array $payload, ?callable $transport): array
{
    $result = $transport === null
        ? dolibarr_http_request($config, $method, $path, $payload)
        : $transport($method, $path, $payload);

    return is_array($result) ? $result : ['ok' => false, 'status' => 0, 'data' => null];
}

function dolibarr_response_id(array $response): int
{
    $data = $response['data'] ?? null;
    if (is_int($data) || is_string($data)) {
        return max(0, (int) $data);
    }

    if (!is_array($data)) {
        return 0;
    }

    return max(0, (int) ($data['id'] ?? $data['rowid'] ?? 0));
}

function dolibarr_thirdparty_payload(array $config, array $submission): array
{
    return [
        'name' => trim((string) ($submission['name'] ?? '')),
        'email' => strtolower(trim((string) ($submission['email'] ?? ''))),
        'phone' => trim((string) ($submission['phone'] ?? '')),
        // Dolibarr: 2 = prospect. A confirmed order can later turn the record
        // into a customer without treating every website enquiry as a sale.
        'client' => 2,
        'code_client' => '-1',
        'country_code' => (string) ($config['countryCode'] ?? 'AT'),
        'note_private' => 'Automatisch als Interessent aus einer Anfrage über it.tabelander.co.at angelegt.',
        'caller' => 'ittabelanderwebsite',
    ];
}

function dolibarr_ticket_payload(int $thirdpartyId, array $submission, string $requestId): array
{
    $service = trim((string) ($submission['service'] ?? 'Allgemeine Anfrage'));
    $audience = trim((string) ($submission['audience'] ?? ''));
    $subjectParts = array_values(array_filter([$service, $audience]));
    $subject = 'Website-Anfrage: ' . implode(' / ', $subjectParts);
    $subject = function_exists('mb_substr') ? mb_substr($subject, 0, 255) : substr($subject, 0, 255);

    $rows = [
        'Name' => trim((string) ($submission['name'] ?? '')),
        'E-Mail' => strtolower(trim((string) ($submission['email'] ?? ''))),
        'Telefon' => trim((string) ($submission['phone'] ?? '')) ?: 'nicht angegeben',
        'Bereich' => $audience ?: 'nicht angegeben',
        'Anliegen' => $service ?: 'Allgemeine Anfrage',
        'Referenz' => $requestId,
    ];
    $messageParts = [];
    foreach ($rows as $label => $value) {
        $messageParts[] = '<strong>'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ':</strong> '
            . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $message = trim((string) ($submission['message'] ?? ''));
    $messageParts[] = '<br><strong>Nachricht:</strong><br>'
        . nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    return [
        'socid' => $thirdpartyId,
        'fk_soc' => $thirdpartyId,
        'subject' => $subject,
        'message' => implode('<br>', $messageParts),
        'origin_email' => strtolower(trim((string) ($submission['email'] ?? ''))),
        'origin_replyto' => strtolower(trim((string) ($submission['email'] ?? ''))),
        'private' => 1,
        'notify_tiers_at_create' => 0,
        'caller' => 'ittabelanderwebsite',
    ];
}

function create_dolibarr_contact_ticket(
    array $siteConfig,
    array $submission,
    string $requestId,
    ?callable $transport = null
): array {
    $config = is_array($siteConfig['dolibarr'] ?? null) ? $siteConfig['dolibarr'] : [];
    $retentionDays = max(0, (int) ($siteConfig['logging']['retentionDays'] ?? 30));

    if (!dolibarr_configured($config)) {
        if (($config['enabledRequested'] ?? false) === true) {
            append_dolibarr_log([
                'requestId' => $requestId,
                'status' => 'configuration_error',
                'step' => 'configuration',
            ], $retentionDays);
        }

        return ['ok' => false, 'status' => 'disabled'];
    }

    $thirdparty = dolibarr_find_or_create_thirdparty($config, $submission, $transport);
    if (($thirdparty['ok'] ?? false) !== true) {
        append_dolibarr_log([
            'requestId' => $requestId,
            'status' => 'error',
            'step' => (string) ($thirdparty['step'] ?? 'thirdparty'),
            'httpStatus' => (int) ($thirdparty['httpStatus'] ?? 0),
        ], $retentionDays);

        return ['ok' => false, 'status' => 'thirdparty_error'];
    }

    $thirdpartyId = (int) $thirdparty['id'];
    $ticket = dolibarr_request(
        $config,
        'POST',
        'tickets',
        dolibarr_ticket_payload($thirdpartyId, $submission, $requestId),
        $transport
    );
    $ticketId = dolibarr_response_id($ticket);

    if (($ticket['ok'] ?? false) !== true || $ticketId <= 0) {
        append_dolibarr_log([
            'requestId' => $requestId,
            'status' => 'error',
            'step' => 'ticket_create',
            'httpStatus' => (int) ($ticket['status'] ?? 0),
            'thirdpartyId' => $thirdpartyId,
        ], $retentionDays);

        return ['ok' => false, 'status' => 'ticket_error', 'thirdpartyId' => $thirdpartyId];
    }

    append_dolibarr_log([
        'requestId' => $requestId,
        'status' => 'created',
        'step' => 'ticket_complete',
        'thirdpartyId' => $thirdpartyId,
        'ticketId' => $ticketId,
    ], $retentionDays);

    return [
        'ok' => true,
        'status' => 'created',
        'thirdpartyId' => $thirdpartyId,
        'ticketId' => $ticketId,
    ];
}

function dolibarr_find_or_create_thirdparty(array $config, array $submission, ?callable $transport = null): array
{
    $email = strtolower(trim((string) ($submission['email'] ?? '')));
    $existing = dolibarr_request($config, 'GET', 'thirdparties/email/' . rawurlencode($email), null, $transport);

    if (($existing['ok'] ?? false) === true) {
        $id = dolibarr_response_id($existing);
        return $id > 0
            ? ['ok' => true, 'id' => $id, 'created' => false, 'httpStatus' => (int) ($existing['status'] ?? 200)]
            : ['ok' => false, 'id' => 0, 'step' => 'thirdparty_lookup', 'httpStatus' => (int) ($existing['status'] ?? 0)];
    }

    if ((int) ($existing['status'] ?? 0) !== 404) {
        return ['ok' => false, 'id' => 0, 'step' => 'thirdparty_lookup', 'httpStatus' => (int) ($existing['status'] ?? 0)];
    }

    $created = dolibarr_request($config, 'POST', 'thirdparties', dolibarr_thirdparty_payload($config, $submission), $transport);
    $id = dolibarr_response_id($created);

    return ($created['ok'] ?? false) === true && $id > 0
        ? ['ok' => true, 'id' => $id, 'created' => true, 'httpStatus' => (int) ($created['status'] ?? 201)]
        : ['ok' => false, 'id' => 0, 'step' => 'thirdparty_create', 'httpStatus' => (int) ($created['status'] ?? 0)];
}

function dolibarr_controller_lines(array $selection, float $vatRate): array
{
    $catalog = controller_catalog();
    $offers = is_array($catalog['offers'] ?? null) ? $catalog['offers'] : [];
    $modelLabel = (string) ($selection['modelLabel'] ?? 'PS5 Controller');
    $selectedOffers = is_array($selection['offerIds'] ?? null) ? $selection['offerIds'] : [];
    $items = [];

    foreach ($selectedOffers as $offerId) {
        if (!isset($offers[$offerId]) || !is_array($offers[$offerId])) {
            continue;
        }

        $items[] = [
            'label' => (string) ($offers[$offerId]['shortLabel'] ?? $offerId),
            'description' => $modelLabel . ': ' . (string) ($offers[$offerId]['description'] ?? ''),
            'priceCents' => max(0, (int) ($offers[$offerId]['priceCents'] ?? 0)),
        ];
    }

    return array_map(static function (array $item, int $index) use ($vatRate): array {
        return [
            'desc' => $item['description'],
            'label' => $item['label'],
            'subprice' => number_format($item['priceCents'] / 100, 2, '.', ''),
            'qty' => 1,
            'tva_tx' => $vatRate,
            'localtax1_tx' => 0,
            'localtax2_tx' => 0,
            'fk_product' => 0,
            'remise_percent' => 0,
            'price_base_type' => 'TTC',
            'info_bits' => 0,
            'product_type' => 1,
            'rang' => $index + 1,
            'special_code' => 0,
            'fk_parent_line' => 0,
            'fk_fournprice' => 0,
            'pa_ht' => 0,
            'date_start' => null,
            'date_end' => null,
            'array_options' => [],
            'fk_unit' => 0,
            'origin' => '',
            'origin_id' => 0,
            'multicurrency_subprice' => 0,
            'fk_remise_except' => 0,
        ];
    }, $items, array_keys($items));
}

function dolibarr_proposal_payload(int $thirdpartyId, array $selection, string $controllerRequestId): array
{
    return [
        'socid' => $thirdpartyId,
        'date' => time(),
        'fin_validite' => time() + (14 * 86400),
        'ref_ext' => 'WEB-CTRL-' . $controllerRequestId,
        'note_private' => (string) ($selection['message'] ?? ''),
        'note_public' => 'Unverbindlicher Angebotsentwurf vorbehaltlich technischer Prüfung des eingesandten Controllers.',
        'caller' => 'ittabelanderwebsite',
    ];
}

function create_dolibarr_controller_proposal(
    array $siteConfig,
    array $submission,
    array $selection,
    string $controllerRequestId,
    string $requestId,
    ?callable $transport = null
): array {
    $config = is_array($siteConfig['dolibarr'] ?? null) ? $siteConfig['dolibarr'] : [];
    $retentionDays = max(0, (int) ($siteConfig['logging']['retentionDays'] ?? 30));

    if (!dolibarr_configured($config)) {
        if (($config['enabledRequested'] ?? false) === true) {
            append_dolibarr_log([
                'requestId' => $requestId,
                'status' => 'configuration_error',
                'step' => 'configuration',
            ], $retentionDays);
        }

        return ['ok' => false, 'status' => 'disabled'];
    }

    $thirdparty = dolibarr_find_or_create_thirdparty($config, $submission, $transport);
    if (($thirdparty['ok'] ?? false) !== true) {
        append_dolibarr_log([
            'requestId' => $requestId,
            'status' => 'error',
            'step' => (string) ($thirdparty['step'] ?? 'thirdparty'),
            'httpStatus' => (int) ($thirdparty['httpStatus'] ?? 0),
        ], $retentionDays);

        return ['ok' => false, 'status' => 'thirdparty_error'];
    }

    $thirdpartyId = (int) $thirdparty['id'];
    $proposal = dolibarr_request(
        $config,
        'POST',
        'proposals',
        dolibarr_proposal_payload($thirdpartyId, $selection, $controllerRequestId),
        $transport
    );
    $proposalId = dolibarr_response_id($proposal);

    if (($proposal['ok'] ?? false) !== true || $proposalId <= 0) {
        append_dolibarr_log([
            'requestId' => $requestId,
            'status' => 'error',
            'step' => 'proposal_create',
            'httpStatus' => (int) ($proposal['status'] ?? 0),
            'thirdpartyId' => $thirdpartyId,
        ], $retentionDays);

        return ['ok' => false, 'status' => 'proposal_error', 'thirdpartyId' => $thirdpartyId];
    }

    $lines = dolibarr_controller_lines($selection, (float) $config['vatRate']);
    foreach ($lines as $line) {
        $lineResult = dolibarr_request($config, 'POST', 'proposals/' . $proposalId . '/line', $line, $transport);
        if (($lineResult['ok'] ?? false) !== true) {
            append_dolibarr_log([
                'requestId' => $requestId,
                'status' => 'partial',
                'step' => 'proposal_line',
                'httpStatus' => (int) ($lineResult['status'] ?? 0),
                'thirdpartyId' => $thirdpartyId,
                'proposalId' => $proposalId,
            ], $retentionDays);

            return [
                'ok' => false,
                'status' => 'line_error',
                'thirdpartyId' => $thirdpartyId,
                'proposalId' => $proposalId,
            ];
        }
    }

    append_dolibarr_log([
        'requestId' => $requestId,
        'status' => 'created',
        'step' => 'complete',
        'thirdpartyId' => $thirdpartyId,
        'proposalId' => $proposalId,
    ], $retentionDays);

    return [
        'ok' => true,
        'status' => 'created',
        'thirdpartyId' => $thirdpartyId,
        'proposalId' => $proposalId,
    ];
}
