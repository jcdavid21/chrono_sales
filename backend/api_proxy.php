<?php
/**
 * api_proxy.php
 * Same-origin relay: JS calls /backend/api_proxy.php?endpoint=analytics
 * Forwards to Flask on localhost:8800 and returns the JSON (or CSV).
 * Eliminates all CORS issues since the browser only talks to PHP's origin.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Config ────────────────────────────────────────────────────────────────────
$FLASK_BASE = 'http://127.0.0.1:8800';

$ALLOWED = [
    'dashboard'              => ['path' => '/api/dashboard',             'csv' => false, 'methods' => ['GET']],
    'health'                 => ['path' => '/api/health',                'csv' => false, 'methods' => ['GET']],
    'analytics'              => ['path' => '/api/analytics',             'csv' => false, 'methods' => ['GET']],
    'analytics/filters'      => ['path' => '/api/analytics/filters',     'csv' => false, 'methods' => ['GET']],
    'analytics/export/csv'   => ['path' => '/api/analytics/export/csv',  'csv' => true,  'methods' => ['GET']],

    // Data Management — Transactions
    'dm/transactions'        => ['path' => '/api/dm/transactions',        'csv' => false, 'methods' => ['GET','POST']],
    'dm/transactions/export' => ['path' => '/api/dm/transactions/export', 'csv' => true,  'methods' => ['GET']],
    'dm/transactions/import' => ['path' => '/api/dm/transactions/import', 'csv' => false, 'methods' => ['POST']],
    'dm/transactions/bulk-delete' => ['path' => '/api/dm/transactions/bulk-delete', 'csv' => false, 'methods' => ['POST']],

    // Data Management — Customers
    'dm/customers'           => ['path' => '/api/dm/customers',           'csv' => false, 'methods' => ['GET','POST']],
    'dm/customers/export'    => ['path' => '/api/dm/customers/export',    'csv' => true,  'methods' => ['GET']],
    'dm/customers/import'    => ['path' => '/api/dm/customers/import',    'csv' => false, 'methods' => ['POST']],
    'dm/customers/bulk-delete' => ['path' => '/api/dm/customers/bulk-delete', 'csv' => false, 'methods' => ['POST']],

    // Data Management — Branches
    'dm/branches'            => ['path' => '/api/dm/branches',            'csv' => false, 'methods' => ['GET','POST']],
    'dm/branches/export'     => ['path' => '/api/dm/branches/export',     'csv' => true,  'methods' => ['GET']],
    'dm/branches/import'     => ['path' => '/api/dm/branches/import',     'csv' => false, 'methods' => ['POST']],
    'dm/branches/bulk-delete' => ['path' => '/api/dm/branches/bulk-delete', 'csv' => false, 'methods' => ['POST']],
];

// ── Resolve endpoint ──────────────────────────────────────────────────────────
$endpoint = $_GET['endpoint'] ?? '';

if (!array_key_exists($endpoint, $ALLOWED)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unknown endpoint: ' . htmlspecialchars($endpoint)]);
    exit;
}

$config = $ALLOWED[$endpoint];

// ── Forward query params to Flask (strip 'endpoint' key) ─────────────────────
$forwardParams = $_GET;
unset($forwardParams['endpoint']);

$flaskUrl = $FLASK_BASE . $config['path'];
if (!empty($forwardParams)) {
    $flaskUrl .= '?' . http_build_query($forwardParams);
}

// ── Detect method and body ────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

// Extract ID from query string for routed calls (e.g. ?endpoint=dm/transactions&id=5)
$recordId = $_GET['id'] ?? null;
$flaskPath = $config['path'];
if ($recordId !== null) {
    $flaskPath .= '/' . intval($recordId);
}

// ── Forward query params to Flask (strip proxy-only keys) ────────────────────
$forwardParams = $_GET;
unset($forwardParams['endpoint'], $forwardParams['id']);

$flaskUrl = $FLASK_BASE . $flaskPath;
if (!empty($forwardParams)) {
    $flaskUrl .= '?' . http_build_query($forwardParams);
}

// ── Build stream context ──────────────────────────────────────────────────────
$ctxOptions = [
    'http' => [
        'method'        => $method,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]
];

if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $rawBody = file_get_contents('php://input');
    $ctxOptions['http']['content'] = $rawBody;
    $ctxOptions['http']['header']  = "Content-Type: application/json\r\n";
}

$ctx  = stream_context_create($ctxOptions);
$body = @file_get_contents($flaskUrl, false, $ctx);

if ($body === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Flask backend unreachable. Make sure app.py is running on port 8800.',
    ]);
    exit;
}

// ── Pass Flask's HTTP status code through ────────────────────────────────────
$status = 200;
foreach ($http_response_header as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
        $status = (int) $m[1];
    }
}
http_response_code($status);

// ── Set response headers ──────────────────────────────────────────────────────
if ($config['csv']) {
    // Pass Content-Disposition from Flask so the browser downloads the file
    foreach ($http_response_header as $h) {
        if (stripos($h, 'Content-Disposition:') === 0) {
            header($h);
            break;
        }
    }
    header('Content-Type: text/csv; charset=utf-8');
} else {
    header('Content-Type: application/json');
}

echo $body;