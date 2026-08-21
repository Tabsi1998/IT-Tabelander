<?php
declare(strict_types=1);

$model = trim((string) ($_GET['model'] ?? ''));
$sources = [
    'dualsense' => 'https://www.extremerate.com/collections/ps5-controller-shell/products.json?limit=250',
    'dualsense-edge' => 'https://www.extremerate.com/collections/for-ps5-edge-shells/products.json?limit=250',
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600, stale-if-error=86400');

if (!isset($sources[$model])) {
    http_response_code(400);
    echo json_encode(['products' => [], 'error' => 'invalid_model'], JSON_UNESCAPED_SLASHES);
    exit;
}

$payload = false;
if (function_exists('curl_init')) {
    $handle = curl_init($sources[$model]);
    if ($handle !== false) {
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_USERAGENT => 'IT-Tabelander Controller-Konfigurator/1.0',
        ]);
        $payload = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($status < 200 || $status >= 300) {
            $payload = false;
        }
    }
}

if (!is_string($payload) || $payload === '') {
    http_response_code(502);
    echo json_encode(['products' => [], 'error' => 'catalog_unavailable'], JSON_UNESCAPED_SLASHES);
    exit;
}

$decoded = json_decode($payload, true);
$products = is_array($decoded['products'] ?? null) ? $decoded['products'] : [];
$result = [];

foreach ($products as $product) {
    if (!is_array($product)) {
        continue;
    }
    $title = trim((string) ($product['title'] ?? ''));
    $matches = $model === 'dualsense'
        ? preg_match('/(?:front.*shell|full set shells)/i', $title) === 1
            && preg_match('/(?:edge|backplate|back shell|decorative trim|buttons only)/i', $title) !== 1
        : preg_match('/(?:left right front housing shell|full set shells?|beyond-arc full set shell)/i', $title) === 1
            && preg_match('/replacement full set buttons/i', $title) !== 1;
    if (!$matches) {
        continue;
    }

    $images = [];
    foreach (array_slice(is_array($product['images'] ?? null) ? $product['images'] : [], 0, 2) as $image) {
        $source = is_array($image) ? trim((string) ($image['src'] ?? '')) : '';
        if (str_starts_with($source, 'https://cdn.shopify.com/')) {
            $images[] = ['src' => $source];
        }
    }
    if ($images === []) {
        continue;
    }

    $result[] = [
        'id' => (string) ($product['id'] ?? ''),
        'title' => $title,
        'images' => $images,
    ];
}

echo json_encode(['products' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
