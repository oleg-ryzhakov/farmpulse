<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Log every API request in a single place
$logFile = __DIR__ . '/v2/requests.log';
$hdrs = [];
foreach ($_SERVER as $k => $v) { if (strpos($k, 'HTTP_') === 0) { $hdrs[$k] = $v; } }
$rawBody = file_get_contents('php://input');
file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $method $uri\n" . json_encode($hdrs) . "\n" . ($rawBody ?: '') . "\n---\n", FILE_APPEND);

// Very small dispatcher to existing v2 handlers by path prefix
if (strpos($uri, '/api/v2/rigs') !== false) {
    require __DIR__ . '/v2/rigs/index.php';
    exit;
}
if (strpos($uri, '/api/v2/auth') !== false) {
    // Support direct file
    require __DIR__ . '/v2/auth/login.php';
    exit;
}
if (strpos($uri, '/api/v2/farms') !== false || strpos($uri, '/api/v2/hello') !== false) {
    require __DIR__ . '/v2/farms/index.php';
    exit;
}

http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Not found']);



