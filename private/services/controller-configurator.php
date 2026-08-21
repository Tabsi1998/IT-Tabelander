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

function controller_submission_values(array $input): array
{
    return [
        'model' => trim((string) ($input['model'] ?? '')),
        'source' => trim((string) ($input['source'] ?? '')),
        'shell' => trim((string) ($input['shell'] ?? 'original')),
        'shellDesign' => controller_notes((string) ($input['shell_design'] ?? ''), 160),
        'offers' => controller_string_list($input['offers'] ?? []),
        'extras' => controller_string_list($input['extras'] ?? []),
        'notes' => controller_notes((string) ($input['notes'] ?? '')),
        'firstName' => trim((string) ($input['first_name'] ?? '')),
        'lastName' => trim((string) ($input['last_name'] ?? '')),
        'email' => trim((string) ($input['email'] ?? '')),
        'phone' => trim((string) ($input['phone'] ?? '')),
        'address' => trim((string) ($input['address'] ?? '')),
        'postalCode' => trim((string) ($input['postal_code'] ?? '')),
        'city' => trim((string) ($input['city'] ?? '')),
        'preferredContact' => trim((string) ($input['preferred_contact'] ?? 'email')),
        'privacyConfirmation' => trim((string) ($input['privacy_confirmation'] ?? '')),
    ];
}

function store_controller_form_flash(array $values, array $errors = [], array $meta = []): void
{
    ensure_contact_session();
    $_SESSION['controller_form']['flash'] = [
        'values' => $values,
        'errors' => array_values(array_unique($errors)),
        'meta' => $meta,
    ];
}

function consume_controller_form_flash(): array
{
    ensure_contact_session();
    $flash = $_SESSION['controller_form']['flash'] ?? [];
    unset($_SESSION['controller_form']['flash']);

    return is_array($flash) ? $flash : [];
}

function build_controller_selection(array $input): array
{
    $catalog = controller_catalog();
    $models = is_array($catalog['models'] ?? null) ? $catalog['models'] : [];
    $sources = is_array($catalog['sources'] ?? null) ? $catalog['sources'] : [];
    $shells = is_array($catalog['shells'] ?? null) ? $catalog['shells'] : [];
    $offers = is_array($catalog['offers'] ?? null) ? $catalog['offers'] : [];
    $extras = is_array($catalog['extras'] ?? null) ? $catalog['extras'] : [];

    $modelId = trim((string) ($input['model'] ?? ''));
    $sourceId = trim((string) ($input['source'] ?? ''));
    $shellId = trim((string) ($input['shell'] ?? 'original'));
    $shellDesign = controller_notes((string) ($input['shell_design'] ?? ''), 160);
    $submittedOfferIds = array_values(array_intersect(controller_string_list($input['offers'] ?? []), array_keys($offers)));
    $extraIds = array_values(array_intersect(controller_string_list($input['extras'] ?? []), array_keys($extras)));
    $notes = controller_notes((string) ($input['notes'] ?? ''));
    $errors = [];

    if (!array_key_exists($modelId, $models)) {
        $errors[] = 'model';
    }

    if (!array_key_exists($sourceId, $sources)) {
        $errors[] = 'source';
    }

    $shellModels = is_array($shells[$shellId]['models'] ?? null) ? $shells[$shellId]['models'] : [];
    if (!array_key_exists($shellId, $shells) || !in_array($modelId, $shellModels, true)) {
        $errors[] = 'shell';
    }

    $offerIds = array_values(array_filter($submittedOfferIds, static function (string $offerId) use ($offers, $modelId): bool {
        $models = is_array($offers[$offerId]['models'] ?? null) ? $offers[$offerId]['models'] : [];

        return in_array($modelId, $models, true);
    }));

    if ($offerIds === []) {
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
            'sourceId' => $sourceId,
            'shellId' => $shellId,
            'shellDesign' => $shellDesign,
            'offerIds' => $offerIds,
            'extraIds' => $extraIds,
            'notes' => $notes,
        ];
    }

    $modelLabel = (string) ($models[$modelId]['label'] ?? $modelId);
    $sourceLabel = (string) ($sources[$sourceId]['shortLabel'] ?? $sourceId);
    $sourcePrices = is_array($sources[$sourceId]['priceCents'] ?? null) ? $sources[$sourceId]['priceCents'] : [];
    $sourcePriceCents = max(0, (int) ($sourcePrices[$modelId] ?? 0));
    $shellLabel = (string) ($shells[$shellId]['shortLabel'] ?? $shellId);
    if ($shellId !== 'original' && $shellDesign !== '') {
        $shellLabel .= ' · ' . $shellDesign;
    }
    $shellPriceCents = max(0, (int) ($shells[$shellId]['priceCents'] ?? 0));
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
    $totalPriceCents = $sourcePriceCents + $shellPriceCents + $offerPriceCents;
    $messageLines = [
        'Controller-Upgrade-Konfiguration:',
        'Modell: ' . $modelLabel,
        'Bereitstellung: ' . $sourceLabel
            . ($sourcePriceCents > 0 ? ' (' . controller_price($sourcePriceCents) . ')' : ''),
        'Gehäuse / Optik: ' . $shellLabel
            . ($shellPriceCents > 0 ? ' (' . controller_price($shellPriceCents) . ')' : ''),
        '',
        'Gewählte Upgrade-Pakete:',
    ];

    foreach ($offerIds as $offerId) {
        $messageLines[] = '- ' . (string) ($offers[$offerId]['shortLabel'] ?? $offerId)
            . ': ' . controller_price(max(0, (int) ($offers[$offerId]['priceCents'] ?? 0)));
    }
    $messageLines[] = 'Voraussichtliche Paketsumme: ' . controller_price($totalPriceCents);

    if ($extraLabels !== []) {
        $messageLines[] = 'Zusatzangaben: ' . implode(', ', $extraLabels);
    }

    if ($notes !== '') {
        $messageLines[] = '';
        $messageLines[] = 'Weitere Beschreibung:';
        $messageLines[] = $notes;
    }

    $messageLines[] = '';
    $messageLines[] = 'Die Auswahl ist eine unverbindliche Upgrade-Anfrage. Kompatibilität und Ausführung werden vor der Annahme durch IT-Tabelander geprüft.';

    return [
        'valid' => true,
        'errors' => [],
        'modelId' => $modelId,
        'modelLabel' => $modelLabel,
        'sourceId' => $sourceId,
        'sourceLabel' => $sourceLabel,
        'sourcePriceCents' => $sourcePriceCents,
        'shellId' => $shellId,
        'shellDesign' => $shellDesign,
        'shellLabel' => $shellLabel,
        'shellPriceCents' => $shellPriceCents,
        'offerIds' => $offerIds,
        'offerLabels' => $offerLabels,
        'extraIds' => $extraIds,
        'extraLabels' => $extraLabels,
        'notes' => $notes,
        'totalPriceCents' => $totalPriceCents,
        'totalPriceLabel' => controller_price($totalPriceCents),
        'message' => implode("\n", $messageLines),
    ];
}
