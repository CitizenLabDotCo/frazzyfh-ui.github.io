<?php
/**
 * Go Vocal API Proxy
 * Place this file in the same directory as govocal-diagnostics.html (place both files in the same directory)
 * Forwards browser requests to the Go Vocal API, bypassing CORS restrictions.
 */

// ── Security: only allow requests from same origin ──────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host   = $_SERVER['HTTP_HOST']   ?? '';

// Allow same-origin and local file requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Validate target URL ──────────────────────────────────────
$target = $_GET['target'] ?? '';
if (!$target) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing target parameter']);
    exit;
}

$target = urldecode($target);

// Only allow HTTPS requests to govocal.com domains
if (!preg_match('#^https://[a-zA-Z0-9.\-]+/api/v2/#', $target)) {
    http_response_code(403);
    echo json_encode(['error' => 'Target URL not permitted']);
    exit;
}

// ── Forward the request ──────────────────────────────────────
$method  = $_SERVER['REQUEST_METHOD'];
$body    = file_get_contents('php://input');
$headers = [];

// Forward Authorization and Content-Type headers
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
}
if (!empty($_SERVER['HTTP_CONTENT_TYPE'])) {
    $headers[] = 'Content-Type: ' . $_SERVER['HTTP_CONTENT_TYPE'];
} elseif ($method === 'POST') {
    $headers[] = 'Content-Type: application/json';
}

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'GoVocalDiagnostics/1.0',
]);

if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$response    = curl_exec($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$error       = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(502);
    echo json_encode(['error' => 'Proxy error: ' . $error]);
    exit;
}

// ── Return response ──────────────────────────────────────────
http_response_code($httpCode);
header('Content-Type: ' . ($contentType ?: 'application/json'));
echo $response;
