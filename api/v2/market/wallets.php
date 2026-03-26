<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$file = __DIR__ . '/wallets.json';
if (!file_exists($file)) { file_put_contents($file, json_encode(['wallets' => []], JSON_PRETTY_PRINT)); }
$db = json_decode(@file_get_contents($file), true);
if (!is_array($db)) { $db = ['wallets' => []]; }

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $coin = trim((string)($_GET['coin'] ?? ''));
    $out = array_values(array_filter($db['wallets'], function($w) use ($coin) {
        return ($coin === '' || strcasecmp($w['coin'] ?? '', $coin) === 0);
    }));
    echo json_encode(['status' => 'OK', 'data' => $out]);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $coin = trim((string)($body['coin'] ?? ''));
    $name = trim((string)($body['name'] ?? ''));
    $address = trim((string)($body['address'] ?? ''));
    if ($coin === '' || $address === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'coin and address are required']);
        exit;
    }
    $id = time();
    $wallet = ['id' => $id, 'coin' => $coin, 'name' => ($name ?: $address), 'address' => $address, 'created_at' => date('Y-m-d H:i:s')];
    $db['wallets'][] = $wallet;
    file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'OK', 'wallet' => $wallet]);
    exit;
}

if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = $body['id'] ?? null;
    if (!$id) { http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'id required']); exit; }
    $db['wallets'] = array_values(array_filter($db['wallets'], function($w) use ($id){ return ($w['id'] ?? null) != $id; }));
    file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'OK']);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);




