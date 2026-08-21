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

function build_controller_selection(array $input): array
{
    $catalog = controller_catalog();
    $models = is_array($catalog['models'] ?? null) ? $catalog['models'] : [];
    $issues = is_array($catalog['issues'] ?? null) ? $catalog['issues'] : [];
    $extras = is_array($catalog['extras'] ?? null) ? $catalog['extras'] : [];

    $modelId = trim((string) ($input['model'] ?? ''));
    $issueIds = array_values(array_intersect(controller_string_list($input['issues'] ?? []), array_keys($issues)));
    $extraIds = array_values(array_intersect(controller_string_list($input['extras'] ?? []), array_keys($extras)));
    $notes = controller_notes((string) ($input['notes'] ?? ''));
    $errors = [];

    if (!array_key_exists($modelId, $models)) {
        $errors[] = 'model';
    }

    if ($issueIds === []) {
        $errors[] = 'issues';
    }

    if ($errors !== []) {
        return [
            'valid' => false,
            'errors' => $errors,
            'modelId' => $modelId,
            'issueIds' => $issueIds,
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
    $messageLines = [
        'Controller-Konfiguration:',
        'Modell: ' . $modelLabel,
        'Fehlerbild: ' . implode(', ', $issueLabels),
    ];

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
        'extraIds' => $extraIds,
        'extraLabels' => $extraLabels,
        'notes' => $notes,
        'message' => implode("\n", $messageLines),
    ];
}
