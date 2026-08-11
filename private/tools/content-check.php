<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$errors = [];

function read_json_file(string $path, array &$errors): ?array
{
    $raw = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($raw)) {
        $errors[] = 'Datei fehlt: ' . $path;
        return null;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $errors[] = basename($path) . ': ungültiges JSON (' . $exception->getMessage() . ')';
        return null;
    }

    if (!is_array($decoded)) {
        $errors[] = basename($path) . ': Inhalt muss ein JSON-Objekt sein.';
        return null;
    }

    return $decoded;
}

$reviewsPath = $projectRoot . '/private/data/reviews.json';
$reviewsData = read_json_file($reviewsPath, $errors);
$reviewCount = 0;

if (is_array($reviewsData)) {
    $reviews = $reviewsData['reviews'] ?? null;
    if (!is_array($reviews)) {
        $errors[] = 'reviews.json: "reviews" muss eine Liste sein.';
    } else {
        foreach ($reviews as $index => $review) {
            $number = $index + 1;
            if (!is_array($review)) {
                $errors[] = "reviews.json: Eintrag {$number} muss ein Objekt sein.";
                continue;
            }

            if (trim((string) ($review['author'] ?? '')) === '') {
                $errors[] = "reviews.json: Eintrag {$number} braucht author.";
            }

            if (trim((string) ($review['text'] ?? '')) === '') {
                $errors[] = "reviews.json: Eintrag {$number} braucht text.";
            }

            if (isset($review['rating'])) {
                $rating = (float) str_replace(',', '.', (string) $review['rating']);
                if ($rating < 1 || $rating > 5) {
                    $errors[] = "reviews.json: rating in Eintrag {$number} muss zwischen 1 und 5 liegen.";
                }
            }

            $url = trim((string) ($review['url'] ?? ''));
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
                $errors[] = "reviews.json: url in Eintrag {$number} ist ungültig.";
            }

            $reviewCount++;
        }
    }
}

$statsPath = $projectRoot . '/private/data/stats.json';
$statsData = read_json_file($statsPath, $errors);
$enabledStats = 0;

if (is_array($statsData)) {
    if (!is_bool($statsData['enabled'] ?? null)) {
        $errors[] = 'stats.json: "enabled" muss true oder false sein.';
    }

    $items = $statsData['items'] ?? null;
    if (!is_array($items)) {
        $errors[] = 'stats.json: "items" muss eine Liste sein.';
    } else {
        foreach ($items as $index => $item) {
            $number = $index + 1;
            if (!is_array($item)) {
                $errors[] = "stats.json: Eintrag {$number} muss ein Objekt sein.";
                continue;
            }

            $value = filter_var($item['value'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($value === false) {
                $errors[] = "stats.json: value in Eintrag {$number} muss eine positive ganze Zahl oder 0 sein.";
            }

            if (trim((string) ($item['label'] ?? '')) === '') {
                $errors[] = "stats.json: Eintrag {$number} braucht label.";
            }

            if (($statsData['enabled'] ?? false) === true && is_int($value) && $value < 1) {
                $errors[] = "stats.json: aktivierter Eintrag {$number} braucht einen belegbaren Wert größer 0.";
            }

            if (($statsData['enabled'] ?? false) === true && is_int($value) && $value > 0) {
                $enabledStats++;
            }
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, '[FEHLER] ' . $error . PHP_EOL);
    }

    exit(1);
}

fwrite(STDOUT, sprintf(
    "[OK] Inhalte gültig: %d Bewertung(en), %d aktive Kennzahl(en).%s",
    $reviewCount,
    $enabledStats,
    PHP_EOL
));
