<?php
declare(strict_types=1);

function controller_catalog(): array
{
    static $catalog;

    if (!is_array($catalog)) {
        $loaded = require dirname(__DIR__) . '/controller-config.php';
        $catalog = is_array($loaded) ? $loaded : [];
    }

    return $catalog;
}

function controller_string_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map(
        static fn (mixed $entry): string => trim((string) $entry),
        $value
    ))));
}

function controller_notes(string $value, int $maxLength = 500): string
{
    $clean = sanitize_multiline($value);

    return function_exists('mb_substr')
        ? mb_substr($clean, 0, $maxLength)
        : substr($clean, 0, $maxLength);
}

function controller_price(int $priceCents): string
{
    return number_format($priceCents / 100, 2, ',', '.') . ' €';
}

function store_controller_request(array $selection): string
{
    ensure_contact_session();
    prune_controller_requests();

    $requestId = bin2hex(random_bytes(16));
    $_SESSION['controller_requests'][$requestId] = [
        'createdAt' => time(),
        'selection' => $selection,
    ];

    return $requestId;
}

function controller_request(string $requestId, bool $consume = false): array
{
    ensure_contact_session();
    prune_controller_requests();

    if (!preg_match('/^[a-f0-9]{32}$/', $requestId)) {
        return [];
    }

    $entry = $_SESSION['controller_requests'][$requestId] ?? null;
    if (!is_array($entry) || !is_array($entry['selection'] ?? null)) {
        return [];
    }

    if ($consume) {
        unset($_SESSION['controller_requests'][$requestId]);
    }

    return $entry['selection'];
}

function prune_controller_requests(int $maxAgeSeconds = 7200): void
{
    $requests = $_SESSION['controller_requests'] ?? [];
    if (!is_array($requests)) {
        $_SESSION['controller_requests'] = [];
        return;
    }

    $cutoff = time() - max(300, $maxAgeSeconds);
    foreach ($requests as $requestId => $entry) {
        if (!is_array($entry) || (int) ($entry['createdAt'] ?? 0) < $cutoff) {
            unset($requests[$requestId]);
        }
    }

    $_SESSION['controller_requests'] = array_slice($requests, -5, null, true);
}

function build_controller_selection(array $input): array
{
    $catalog = controller_catalog();
    $models = is_array($catalog['models'] ?? null) ? $catalog['models'] : [];
    $issues = is_array($catalog['issues'] ?? null) ? $catalog['issues'] : [];
    $offers = is_array($catalog['offers'] ?? null) ? $catalog['offers'] : [];
    $extras = is_array($catalog['extras'] ?? null) ? $catalog['extras'] : [];

    $modelId = trim((string) ($input['model'] ?? ''));
    $issueIds = array_values(array_intersect(controller_string_list($input['issues'] ?? []), array_keys($issues)));
    $submittedOfferIds = array_values(array_intersect(controller_string_list($input['offers'] ?? []), array_keys($offers)));
    $extraIds = array_values(array_intersect(controller_string_list($input['extras'] ?? []), array_keys($extras)));
    $notes = controller_notes((string) ($input['notes'] ?? ''));
    $errors = [];

    if (!array_key_exists($modelId, $models)) {
        $errors[] = 'model';
    }

    $offerIds = array_values(array_filter($submittedOfferIds, static function (string $offerId) use ($offers, $modelId): bool {
        $models = is_array($offers[$offerId]['models'] ?? null) ? $offers[$offerId]['models'] : [];

        return in_array($modelId, $models, true);
    }));

    if ($issueIds === [] && $offerIds === []) {
        $errors[] = 'selection';
    }

    $selectedGroups = [];
    foreach ($offerIds as $offerId) {
        $group = trim((string) ($offers[$offerId]['group'] ?? ''));
        if ($group === '') {
            continue;
        }

        if (isset($selectedGroups[$group])) {
            $errors[] = 'offers';
            break;
        }

        $selectedGroups[$group] = $offerId;
    }

    if ($errors !== []) {
        return [
            'valid' => false,
            'errors' => $errors,
            'modelId' => $modelId,
            'issueIds' => $issueIds,
            'offerIds' => $offerIds,
            'extraIds' => $extraIds,
            'notes' => $notes,
        ];
    }

    $modelLabel = (string) ($models[$modelId]['label'] ?? $modelId);
    $issueLabels = array_map(
        static fn (string $id): string => (string) ($issues[$id]['shortLabel'] ?? $id),
        $issueIds
    );
    $extraLabels = array_map(
        static fn (string $id): string => (string) ($extras[$id]['shortLabel'] ?? $id),
        $extraIds
    );
    $offerLabels = array_map(
        static fn (string $id): string => (string) ($offers[$id]['shortLabel'] ?? $id),
        $offerIds
    );
    $offerPriceCents = array_sum(array_map(
        static fn (string $id): int => max(0, (int) ($offers[$id]['priceCents'] ?? 0)),
        $offerIds
    ));
    $diagnosisPriceCents = max(0, (int) ($catalog['diagnosisPriceCents'] ?? 0));
    $totalPriceCents = $offerIds !== [] ? $offerPriceCents : $diagnosisPriceCents;
    $messageLines = [
        'Controller-Konfiguration:',
        'Modell: ' . $modelLabel,
        'Fehlerbild: ' . ($issueLabels !== [] ? implode(', ', $issueLabels) : 'Kein Defekt angegeben – Upgrade-Anfrage'),
    ];

    if ($offerIds !== []) {
        $messageLines[] = '';
        $messageLines[] = 'Gewählte Pauschalpakete:';
        foreach ($offerIds as $offerId) {
            $messageLines[] = '- ' . (string) ($offers[$offerId]['shortLabel'] ?? $offerId)
                . ': ' . controller_price(max(0, (int) ($offers[$offerId]['priceCents'] ?? 0)));
        }
        $messageLines[] = 'Voraussichtliche Paketsumme: ' . controller_price($totalPriceCents);
    } else {
        $messageLines[] = 'Diagnosepauschale: ' . controller_price($diagnosisPriceCents)
            . ' (wird bei anschließender Reparatur angerechnet)';
    }

    if ($extraLabels !== []) {
        $messageLines[] = 'Zusatzangaben: ' . implode(', ', $extraLabels);
    }

    if ($notes !== '') {
        $messageLines[] = '';
        $messageLines[] = 'Weitere Beschreibung:';
        $messageLines[] = $notes;
    }

    $messageLines[] = '';
    $messageLines[] = 'Die Auswahl ist eine unverbindliche Reparaturanfrage. Die technische Prüfung erfolgt durch IT-Tabelander.';

    return [
        'valid' => true,
        'errors' => [],
        'modelId' => $modelId,
        'modelLabel' => $modelLabel,
        'issueIds' => $issueIds,
        'issueLabels' => $issueLabels,
        'offerIds' => $offerIds,
        'offerLabels' => $offerLabels,
        'extraIds' => $extraIds,
        'extraLabels' => $extraLabels,
        'notes' => $notes,
        'totalPriceCents' => $totalPriceCents,
        'totalPriceLabel' => controller_price($totalPriceCents),
        'isDiagnosisOnly' => $offerIds === [],
        'message' => implode("\n", $messageLines),
    ];
}
