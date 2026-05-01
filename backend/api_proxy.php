
<?php

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$FLASK_BASE = 'http://127.0.0.1:8800';

$ALLOWED = [
    'dashboard'              => ['path' => '/api/dashboard',             'csv' => false, 'methods' => ['GET']],
    'health'                 => ['path' => '/api/health',                'csv' => false, 'methods' => ['GET']],
    'analytics'              => ['path' => '/api/analytics',             'csv' => false, 'methods' => ['GET']],
    'analytics/filters'      => ['path' => '/api/analytics/filters',     'csv' => false, 'methods' => ['GET']],
    'analytics/export/csv'   => ['path' => '/api/analytics/export/csv',  'csv' => true,  'methods' => ['GET']],

    // Payment Insights
    'payment-insights'         => ['path' => '/api/payment-insights',         'csv' => false, 'methods' => ['GET']],
    'payment-insights/table'   => ['path' => '/api/payment-insights/table',   'csv' => false, 'methods' => ['GET']],
    'payment-insights/filters' => ['path' => '/api/payment-insights/filters', 'csv' => false, 'methods' => ['GET']],
    'payment-insights/export'  => ['path' => '/api/payment-insights/export',  'csv' => true,  'methods' => ['GET']],

    // Customer Insights
    'customer-insights'         => ['path' => '/api/customer-insights',         'csv' => false, 'methods' => ['GET']],
    'customer-insights/filters' => ['path' => '/api/customer-insights/filters', 'csv' => false, 'methods' => ['GET']],
    'customer-insights/table'   => ['path' => '/api/customer-insights/table',   'csv' => false, 'methods' => ['GET']],
    'customer-insights/export'  => ['path' => '/api/customer-insights/export',  'csv' => true,  'methods' => ['GET']],

    // Branch Performance
    'branch-performance'              => ['path' => '/api/branch-performance',             'csv' => false, 'methods' => ['GET']],
    'branch-performance/transactions' => ['path' => '/api/branch-performance',             'csv' => false, 'methods' => ['GET']],

    // Data Management — Transactions
    'dm/transactions'             => ['path' => '/api/dm/transactions',             'csv' => false, 'methods' => ['GET','POST']],
    'dm/transactions/export'      => ['path' => '/api/dm/transactions/export',      'csv' => true,  'methods' => ['GET']],
    'dm/transactions/import'      => ['path' => '/api/dm/transactions/import',      'csv' => false, 'methods' => ['POST']],
    'dm/transactions/bulk-delete' => ['path' => '/api/dm/transactions/bulk-delete', 'csv' => false, 'methods' => ['POST']],

    // Data Management — Customers
    'dm/customers'             => ['path' => '/api/dm/customers',             'csv' => false, 'methods' => ['GET','POST']],
    'dm/customers/export'      => ['path' => '/api/dm/customers/export',      'csv' => true,  'methods' => ['GET']],
    'dm/customers/import'      => ['path' => '/api/dm/customers/import',      'csv' => false, 'methods' => ['POST']],
    'dm/customers/bulk-delete' => ['path' => '/api/dm/customers/bulk-delete', 'csv' => false, 'methods' => ['POST']],

    // Data Management — Branches
    'dm/branches'             => ['path' => '/api/dm/branches',             'csv' => false, 'methods' => ['GET','POST']],
    'dm/branches/export'      => ['path' => '/api/dm/branches/export',      'csv' => true,  'methods' => ['GET']],
    'dm/branches/import'      => ['path' => '/api/dm/branches/import',      'csv' => false, 'methods' => ['POST']],
    'dm/branches/bulk-delete' => ['path' => '/api/dm/branches/bulk-delete', 'csv' => false, 'methods' => ['POST']],

    // ML Training
    'ml/upload-csv' => ['path' => '/api/ml/upload-csv', 'csv' => false, 'methods' => ['POST']],
    'ml/train'      => ['path' => '/api/ml/train',      'csv' => false, 'methods' => ['POST']],
    'ml/cancel'     => ['path' => '/api/ml/cancel',     'csv' => false, 'methods' => ['POST']],

    // ── Reports Module ────────────────────────────────────────────────────────
    'reports/filters'             => ['path' => '/api/reports/filters',             'csv' => false, 'methods' => ['GET']],
    'reports/revenue'             => ['path' => '/api/reports/revenue',             'csv' => false, 'methods' => ['GET']],
    'reports/revenue/export/csv'  => ['path' => '/api/reports/revenue/export/csv',  'csv' => true,  'methods' => ['GET']],
    'reports/vat'                 => ['path' => '/api/reports/vat',                 'csv' => false, 'methods' => ['GET']],
    'reports/vat/export/csv'      => ['path' => '/api/reports/vat/export/csv',      'csv' => true,  'methods' => ['GET']],
    'reports/discount'            => ['path' => '/api/reports/discount',            'csv' => false, 'methods' => ['GET']],
    'reports/discount/export/csv' => ['path' => '/api/reports/discount/export/csv', 'csv' => true,  'methods' => ['GET']],
    'reports/comparison'          => ['path' => '/api/reports/comparison',          'csv' => false, 'methods' => ['GET']],
    'reports/integrity'           => ['path' => '/api/reports/integrity',           'csv' => false, 'methods' => ['GET']],
    'reports/schedules'           => ['path' => '/api/reports/schedules',           'csv' => false, 'methods' => ['GET','POST']],
];

// ── Resolve endpoint ──────────────────────────────────────────────────────────
$endpoint = $_GET['endpoint'] ?? '';

if (!array_key_exists($endpoint, $ALLOWED)) {
    if (preg_match('#^ml/stream/([a-f0-9\-]{36})$#', $endpoint, $m)) {
        $config = ['path' => '/api/ml/stream/' . $m[1], 'csv' => false, 'methods' => ['GET'], 'stream' => true];
    } elseif (preg_match('#^ml/cancel/([a-f0-9\-]{36})$#', $endpoint, $m)) {
        $config = ['path' => '/api/ml/cancel/' . $m[1], 'csv' => false, 'methods' => ['POST'], 'stream' => false];
    } elseif (preg_match('#^branch-performance/(\d+)/transactions$#', $endpoint, $m)) {
        $config = ['path' => '/api/branch-performance/' . $m[1] . '/transactions', 'csv' => false, 'methods' => ['GET'], 'stream' => false];
    } elseif (preg_match('#^reports/schedules/(\d+)$#', $endpoint, $m)) {
        // PUT (update) and DELETE for individual schedule records
        $config = ['path' => '/api/reports/schedules/' . $m[1], 'csv' => false, 'methods' => ['GET','PUT','DELETE'], 'stream' => false];
    } else {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unknown endpoint: ' . htmlspecialchars($endpoint)]);
        exit;
    }
} else {
    $config = $ALLOWED[$endpoint];
}

// ── Detect method ─────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

$recordId = $_GET['id'] ?? null;
$flaskPath = $config['path'];
if ($recordId !== null) {
    $flaskPath .= '/' . intval($recordId);
}

// ── Forward query params to Flask ─────────────────────────────────────────────
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

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'multipart/form-data') !== false) {
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $ch = curl_init($flaskUrl);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_POSTFIELDS     => [
                    'file' => new CURLFile(
                        $_FILES['file']['tmp_name'],
                        $_FILES['file']['type'] ?: 'text/csv',
                        $_FILES['file']['name']
                    ),
                ],
            ]);
            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            http_response_code($status);
            header('Content-Type: application/json');
            echo $body;
            exit;
        }
        $rawBody = file_get_contents('php://input');
        $ctxOptions['http']['content'] = $rawBody;
        $ctxOptions['http']['header']  = "Content-Type: " . $contentType . "\r\n";
    } else {
        $rawBody = file_get_contents('php://input');
        $ctxOptions['http']['content'] = $rawBody;
        $ctxOptions['http']['header']  = "Content-Type: application/json\r\n";
    }
}

// ── SSE stream ────────────────────────────────────────────────────────────────
if (!empty($config['stream'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    while (ob_get_level() > 0) { ob_end_clean(); }

    $ch = curl_init($flaskUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET         => true,
        CURLOPT_TIMEOUT         => 300,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_HTTPHEADER      => ['Accept: text/event-stream', 'Cache-Control: no-cache'],
        CURLOPT_WRITEFUNCTION   => function($curl, $chunk) {
            echo $chunk;
            if (ob_get_level() > 0) ob_flush();
            flush();
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    curl_close($ch);

    if (!$ok) {
        echo "data: " . json_encode(['type' => 'error', 'data' => ['msg' => 'Flask backend unreachable']]) . "\n\n";
        flush();
    }
    exit;
}

// ── Regular request ───────────────────────────────────────────────────────────
$ctx  = stream_context_create($ctxOptions);
$body = @file_get_contents($flaskUrl, false, $ctx);

if ($body === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Flask backend unreachable. Make sure app.py is running on port 8800.']);
    exit;
}

$status = 200;
foreach ($http_response_header as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int) $m[1]; }
}
http_response_code($status);

if ($config['csv']) {
    foreach ($http_response_header as $h) {
        if (stripos($h, 'Content-Disposition:') === 0) { header($h); break; }
    }
    header('Content-Type: text/csv; charset=utf-8');
} else {
    header('Content-Type: application/json');
}

echo $body;