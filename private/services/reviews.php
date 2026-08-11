<?php
declare(strict_types=1);

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
