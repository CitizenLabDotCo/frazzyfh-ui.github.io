<?php
/**
 * Go Vocal Suite — API proxy (single file, no dependencies, NO caching).
 *
 * All suite tools call this as:  curlproxy.php?target=<encoded platform URL>
 * The Authorization header from the browser is forwarded to the platform,
 * and every request goes straight through — nothing is stored on the server.
 */

const CURL_TIMEOUT = 120;   // seconds — big paginated pulls can be slow

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ── validate target ── */
$target = isset($_GET['target']) ? $_GET['target'] : '';
if (!$target || !preg_match('#^https://[a-z0-9.-]+(/|$)#i', $target)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing or invalid target URL (must be https).']);
    exit;
}

/* ── pick up the Authorization header (varies by server config) ── */
$auth = '';
if (isset($_SERVER['HTTP_AUTHORIZATION']))              $auth = $_SERVER['HTTP_AUTHORIZATION'];
elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
elseif (function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $k => $v) {
        if (strcasecmp($k, 'Authorization') === 0) { $auth = $v; break; }
    }
}

/* ── forward to the platform ── */
$headers = ['Accept: application/json'];
if ($auth) $headers[] = 'Authorization: ' . $auth;

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_TIMEOUT        => CURL_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 15,
]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Proxy could not reach the platform: ' . $err]);
    exit;
}

http_response_code($code ?: 200);
header('Content-Type: application/json');
echo $resp;
