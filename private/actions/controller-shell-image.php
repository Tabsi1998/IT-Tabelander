<?php
declare(strict_types=1);

$encoded = trim((string) ($_GET['src'] ?? ''));
if ($encoded === '' || strlen($encoded) > 2048) {
    http_response_code(400);
    exit;
}
$padding = (4 - strlen($encoded) % 4) % 4;
$decoded = base64_decode(strtr($encoded . str_repeat('=', $padding), '-_', '+/'), true);
$parts = is_string($decoded) ? parse_url($decoded) : false;

if (!is_array($parts)
    || ($parts['scheme'] ?? '') !== 'https'
    || ($parts['host'] ?? '') !== 'cdn.shopify.com'
    || isset($parts['port'])
    || isset($parts['user'])
    || isset($parts['pass'])
    || !str_starts_with((string) ($parts['path'] ?? ''), '/s/files/')) {
    http_response_code(400);
    exit;
}

$handle = function_exists('curl_init') ? curl_init($decoded) : false;
if ($handle === false) {
    http_response_code(502);
    exit;
}

curl_setopt_array($handle, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_MAXFILESIZE => 5 * 1024 * 1024,
    CURLOPT_USERAGENT => 'IT-Tabelander Controller-Konfigurator/1.0',
]);
$payload = curl_exec($handle);
$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
$contentType = strtolower(trim((string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE)));
curl_close($handle);

$allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!is_string($payload)
    || $payload === ''
    || strlen($payload) > 5 * 1024 * 1024
    || $status < 200
    || $status >= 300
    || !in_array($contentType, $allowedTypes, true)) {
    http_response_code(502);
    exit;
}

header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
header('X-Content-Type-Options: nosniff');
echo $payload;
