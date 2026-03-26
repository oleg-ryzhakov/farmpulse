<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$configFile = __DIR__ . '/config.json';
$config = json_decode(@file_get_contents($configFile), true);
if (!is_array($config)) {
    $config = ['farms' => []];
}
if (!isset($config['farms']) || !is_array($config['farms'])) {
    $config['farms'] = [];
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$farmId = $body['farm_id'] ?? null;
$action = $body['action'] ?? null;

if (!$farmId || !$action) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'farm_id and action are required']);
    exit;
}

if (!isset($config['farms'][$farmId])) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Farm not found']);
    exit;
}

$allowed = ['reboot','update_password','update_name'];
if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unsupported action']);
    exit;
}

if (!isset($config['farms'][$farmId]['commands']) || !is_array($config['farms'][$farmId]['commands'])) {
    $config['farms'][$farmId]['commands'] = [];
}

// Normalize to agent-compatible command structure
$cmdId = time();
if ($action === 'reboot') {
    // Агент поддерживает 'exec' с полем .exec — используем явный вызов sreboot
    $cmd = [
        'id' => $cmdId,
        'command' => 'exec',
        'exec' => 'sreboot',
        'created_at' => date('Y-m-d H:i:s')
    ];
} elseif ($action === 'update_password') {
    $newPassword = $body['password'] ?? null;
    if (!$newPassword || strlen($newPassword) < 8) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters']);
        exit;
    }
    $config['farms'][$farmId]['password'] = $newPassword;
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'OK']);
    exit;
} elseif ($action === 'update_name') {
    $newName = $body['name'] ?? null;
    if (!$newName || trim($newName) === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Name is required']);
        exit;
    }
    $config['farms'][$farmId]['name'] = $newName;
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'OK']);
    exit;
} else {
    $cmd = [
        'id' => $cmdId,
        'type' => $action,
        'created_at' => date('Y-m-d H:i:s')
    ];
}

$config['farms'][$farmId]['commands'][] = $cmd;

file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));

echo json_encode(['status' => 'OK', 'queued' => $cmd]);


