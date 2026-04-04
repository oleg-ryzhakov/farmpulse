<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/config_io.php';

$configFile = __DIR__ . '/config.json';
$config = hive_farms_config_load($configFile);
if ($config === null) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Farm configuration unreadable or corrupted']);
    exit;
}
if (!isset($config['farms']) || !is_array($config['farms'])) {
    $config['farms'] = [];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function get_host_url(): string {
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '' && isset($_SERVER['SERVER_NAME'])) { $host = $_SERVER['SERVER_NAME']; }
    return ($https ? 'https://' : 'http://') . $host;
}

if ($method === 'GET') {
    $farmId = isset($_GET['farm_id']) ? (string)$_GET['farm_id'] : null;
    if (!$farmId || !isset($config['farms'][$farmId])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or missing farm_id']);
        exit;
    }
    $fs = $config['farms'][$farmId]['flightsheet'] ?? null;
    echo json_encode(['status' => 'OK', 'flightsheet' => $fs]);
    exit;
}

if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $farmId = $body['farm_id'] ?? null;
    if (!$farmId || !isset($config['farms'][$farmId])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or missing farm_id']);
        exit;
    }
    unset($config['farms'][$farmId]['flightsheet']);
    hive_farms_config_save($configFile, $config);
    echo json_encode(['status' => 'OK']);
    exit;
}

// POST: set/update flight sheet and optionally apply (enqueue config)
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$farmId = $body['farm_id'] ?? null;
if (!$farmId || !isset($config['farms'][$farmId])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing farm_id']);
    exit;
}

$miner = trim((string)($body['miner'] ?? ''));
$pool = trim((string)($body['pool'] ?? ''));
$wallet = trim((string)($body['wallet'] ?? ''));
$pass = isset($body['pass']) ? (string)$body['pass'] : 'x';
$coin = trim((string)($body['coin'] ?? ''));
$apply = (bool)($body['apply'] ?? false);

if ($miner === '' || $pool === '' || $wallet === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'miner, pool, wallet are required']);
    exit;
}

// Save per-farm flight sheet
$config['farms'][$farmId]['flightsheet'] = [
    'miner' => $miner,
    'pool' => $pool,
    'wallet' => $wallet,
    'pass' => $pass,
    'coin' => $coin
];

if (!isset($config['farms'][$farmId]['commands']) || !is_array($config['farms'][$farmId]['commands'])) {
    $config['farms'][$farmId]['commands'] = [];
}

$enqueued = null;
if ($apply) {
    // Build rig.conf content aligning with worker/api.php logic, but override from flight sheet
    $serverUrl = get_host_url();
    $lines = [];
    $lines[] = 'RIG_ID=' . escapeshellarg((string)$farmId);
    $farmPassword = (string)($config['farms'][$farmId]['password'] ?? '');
    if ($farmPassword !== '') { $lines[] = 'RIG_PASSWD=' . escapeshellarg($farmPassword); }
    if ($serverUrl !== '') { $lines[] = 'HIVE_HOST_URL=' . escapeshellarg($serverUrl); }
    $workerName = $config['farms'][$farmId]['name'] ?? '';
    if ($workerName !== '') { $lines[] = 'WORKER_NAME=' . escapeshellarg($workerName); }
    $lines[] = 'MINER=' . escapeshellarg($miner);
    $lines[] = 'POOL=' . escapeshellarg($pool);
    $lines[] = 'WALLET=' . escapeshellarg($wallet);
    $lines[] = 'PASS=' . escapeshellarg($pass);
    if ($coin !== '') { $lines[] = 'COIN=' . escapeshellarg($coin); }
    $lines[] = 'TIMEZONE=' . escapeshellarg('UTC');
    $lines[] = 'SHELLINABOX_ENABLE=0';
    $lines[] = 'SSH_ENABLE=1';
    $lines[] = 'SSH_PASSWORD_ENABLE=1';
    $rigConf = implode("\n", $lines) . "\n";

    $cmdId = time();
    $enqueued = [
        'id' => $cmdId,
        'command' => 'config',
        'config' => $rigConf,
        'created_at' => date('Y-m-d H:i:s')
    ];
    $config['farms'][$farmId]['commands'][] = $enqueued;
}

hive_farms_config_save($configFile, $config);

echo json_encode(['status' => 'OK', 'flightsheet' => $config['farms'][$farmId]['flightsheet'], 'enqueued' => $enqueued]);




