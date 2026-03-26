<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$logFile = __DIR__ . '/v2/requests.log';
$uri = $_SERVER['REQUEST_URI'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$hdrs = [];
foreach ($_SERVER as $k => $v) { if (strpos($k, 'HTTP_') === 0) { $hdrs[$k] = $v; } }
$rawBody = file_get_contents('php://input');
@file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] 404 $method $uri\n" . json_encode($hdrs) . "\n" . ($rawBody ?: '') . "\n---\n", FILE_APPEND);

http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Not found']);





