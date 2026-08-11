<?php
declare(strict_types=1);

function public_stats_path(): string
{
    return dirname(__DIR__) . '/data/stats.json';
}

function load_public_stats(): array
{
    $path = public_stats_path();

    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || ($decoded['enabled'] ?? false) !== true) {
        return [];
    }

    $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
    $normalized = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $value = filter_var($item['value'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 9999999],
        ]);
        $label = trim((string) ($item['label'] ?? ''));
        $suffix = trim((string) ($item['suffix'] ?? ''));

        $labelLength = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);
        $suffixLength = function_exists('mb_strlen') ? mb_strlen($suffix) : strlen($suffix);

        if ($value === false || $label === '' || $labelLength > 60 || $suffixLength > 5) {
            continue;
        }

        $normalized[] = [
            'value' => $value,
            'label' => $label,
            'suffix' => $suffix,
        ];
    }

    return array_slice($normalized, 0, 4);
}
