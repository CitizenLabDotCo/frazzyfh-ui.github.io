<?php
/**
 * planhat-proxy.php
 * ------------------------------------------------------------
 * Minimal server-side relay between the Planhat Companies tool
 * (planhat-companies.html) and the Planhat REST API.
 *
 * Why a proxy? The Planhat API does not send CORS headers, so
 * the browser cannot call it directly. This script forwards a
 * restricted set of read-only requests and returns the JSON.
 *
 * Security model:
 *   - GET-equivalent, read-only: only whitelisted Planhat paths
 *   - Target host must end in .planhat.com (regional clusters OK)
 *   - API key is passed through per-request, never stored/logged
 *   - No dependencies, PHP 8+, works on shared hosting
 * ------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ---- Config -------------------------------------------------
const ALLOWED_PATHS = [
    'companies',
    'leancompanies',
    'conversations',
    'licenses',
];
const DEFAULT_BASE  = 'https://api.planhat.com';
const TIMEOUT_SECS  = 60;

// ---- Helpers ------------------------------------------------
function fail(int $httpCode, string $message, array $extra = []): void {
    http_response_code($httpCode);
    echo json_encode(array_merge([
        'ok'    => false,
        'error' => $message,
    ], $extra));
    exit;
}

// ---- Input --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'POST only. Send JSON: { apiKey, path, query?, baseUrl? }');
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    fail(400, 'Request body must be valid JSON.');
}

$apiKey  = trim((string)($body['apiKey']  ?? ''));
$cookie  = trim((string)($body['cookie']  ?? ''));
$path    = trim((string)($body['path']    ?? ''), "/ \t\n\r");
$query   = $body['query'] ?? [];
$baseUrl = rtrim(trim((string)($body['baseUrl'] ?? DEFAULT_BASE)), '/');

// Header-injection guard: credentials must be single-line values.
$apiKey = preg_replace('/[\r\n]+/', '', $apiKey);
$cookie = preg_replace('/[\r\n]+/', ' ', $cookie);
// Tolerate a paste that includes the header name itself.
$cookie = preg_replace('/^cookie:\s*/i', '', $cookie);

// apiKey is optional — when empty, the request is forwarded unauthenticated
// and Planhat's own response (e.g. 401) is passed straight back.
if (!in_array($path, ALLOWED_PATHS, true)) {
    fail(400, 'Path not allowed.', ['allowed' => ALLOWED_PATHS]);
}
if (!is_array($query)) {
    fail(400, 'query must be an object of key/value pairs.');
}

// Validate the base URL: https + host must be planhat.com or a subdomain.
$parts = parse_url($baseUrl);
$host  = strtolower($parts['host'] ?? '');
if (($parts['scheme'] ?? '') !== 'https'
    || $host === ''
    || !($host === 'planhat.com' || str_ends_with($host, '.planhat.com'))
    || !empty($parts['path']) || !empty($parts['query'])
) {
    fail(400, 'baseUrl must be a bare https://….planhat.com origin, e.g. https://api.planhat.com');
}

// Build query string from scalar values only.
$qs = [];
foreach ($query as $k => $v) {
    if (is_scalar($v)) {
        $qs[(string)$k] = (string)$v;
    }
}
$url = $baseUrl . '/' . $path . ($qs ? ('?' . http_build_query($qs)) : '');

// ---- Forward to Planhat ------------------------------------
$started = microtime(true);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => TIMEOUT_SECS,
    CURLOPT_HTTPHEADER     => array_values(array_filter([
        $apiKey !== '' ? 'Authorization: Bearer ' . $apiKey : null,
        $cookie !== '' ? 'Cookie: ' . $cookie : null,
        'Content-Type: application/json',
        'Accept: application/json',
    ])),
]);

$responseBody = curl_exec($ch);
$curlErr      = curl_error($ch);
$status       = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$elapsedMs = (int)round((microtime(true) - $started) * 1000);

if ($responseBody === false) {
    fail(502, 'Could not reach Planhat: ' . $curlErr, ['url' => $url, 'ms' => $elapsedMs]);
}

// Pass Planhat's own status through so the front end can react
// (401 bad key, 429 rate limited, etc.).
http_response_code($status ?: 502);

$decoded = json_decode($responseBody, true);
echo json_encode([
    'ok'     => $status >= 200 && $status < 300,
    'status' => $status,
    'ms'     => $elapsedMs,
    'url'    => $url,
    'data'   => $decoded ?? $responseBody,
]);
