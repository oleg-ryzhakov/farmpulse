<?php
/**
 * Интеграция eWeLink: привязка аккаунта по логину/паролю через ewelink-api-next (Node CLI).
 *
 * Требуется: Node.js в PATH, npm-зависимости в api/ewelink-node/ (npm ci)
 * Переменные окружения: EWELINK_APP_ID, EWELINK_APP_SECRET (CoolKit Developer Center)
 * Ключ шифрования: FARMPULSE_EWELINK_KEY (≥16 символов) или файл data/ewelink.key
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/ewelink-storage.php';

$method = $_SERVER['REQUEST_METHOD'];
$dataDir = ewelink_data_dir();
$statePath = ewelink_state_path();
$scriptPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'ewelink-node' . DIRECTORY_SEPARATOR . 'login.mjs';

function ewelink_read_state(): ?array
{
    global $statePath;
    if (!is_readable($statePath)) {
        return null;
    }
    $raw = file_get_contents($statePath);
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : null;
}

function ewelink_write_state(array $state): bool
{
    global $statePath, $dataDir;
    if (!is_dir($dataDir)) {
        if (!@mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
            return false;
        }
    }
    return file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function ewelink_child_env(string $appId, string $appSecret): array
{
    $env = [];
    $path = ewelink_getenv_str('PATH');
    if ($path === '') {
        $path = ewelink_getenv_str('Path');
    }
    if ($path === '') {
        $path = $_SERVER['PATH'] ?? $_SERVER['Path'] ?? '';
    }
    if ($path !== '') {
        $env['PATH'] = $path;
    }
    foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'PATHEXT', 'TEMP', 'TMP', 'USERPROFILE', 'HOME'] as $k) {
        $v = ewelink_getenv_str($k);
        if ($v === '' && isset($_SERVER[$k])) {
            $v = (string) $_SERVER[$k];
        }
        if ($v !== '') {
            $env[$k] = $v;
        }
    }
    $env['EWELINK_APP_ID'] = $appId;
    $env['EWELINK_APP_SECRET'] = $appSecret;
    return $env;
}

function ewelink_run_node_login(array $input): array
{
    global $scriptPath;
    if (!is_readable($scriptPath)) {
        return ['ok' => false, 'error' => 'script', 'msg' => 'login.mjs not found'];
    }

    $node = ewelink_getenv_str('NODE_BINARY') ?: 'node';
    $cmd = $node . ' ' . escapeshellarg($scriptPath);

    $appId = ewelink_getenv_str('EWELINK_APP_ID');
    $appSecret = ewelink_getenv_str('EWELINK_APP_SECRET');
    if (!$appId || !$appSecret) {
        return ['ok' => false, 'error' => 'config', 'msg' => 'EWELINK_APP_ID and EWELINK_APP_SECRET must be set on the server'];
    }

    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open(
        $cmd,
        $descriptorspec,
        $pipes,
        dirname($scriptPath),
        ewelink_child_env($appId, $appSecret),
        []
    );

    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => 'proc', 'msg' => 'Could not start node'];
    }

    $payload = json_encode($input, JSON_UNESCAPED_UNICODE);
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code !== 0) {
        $err = json_decode(trim($stderr) ?: '{}', true);
        if (is_array($err) && isset($err['ok']) && $err['ok'] === false) {
            return $err;
        }
        return [
            'ok' => false,
            'error' => 'node',
            'msg' => $stderr ?: ('exit ' . $code),
        ];
    }

    $out = json_decode(trim($stdout) ?: '{}', true);
    return is_array($out) ? $out : ['ok' => false, 'error' => 'parse', 'msg' => 'Invalid node output'];
}

if ($method === 'GET') {
    $state = ewelink_read_state();
    if (!$state || empty($state['meta'])) {
        echo json_encode([
            'status' => 'OK',
            'connected' => false,
        ]);
        exit;
    }
    echo json_encode([
        'status' => 'OK',
        'connected' => true,
        'account_masked' => $state['meta']['account_masked'] ?? '',
        'region' => $state['meta']['region'] ?? null,
        'connected_at' => $state['meta']['connected_at'] ?? null,
    ]);
    exit;
}

if ($method === 'DELETE') {
    if (is_file($statePath)) {
        @unlink($statePath);
    }
    echo json_encode(['status' => 'OK', 'connected' => false]);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $account = isset($body['account']) ? trim((string) $body['account']) : '';
    $password = isset($body['password']) ? (string) $body['password'] : '';
    $areaCode = isset($body['area_code']) ? trim((string) $body['area_code']) : '+7';
    $regionHint = isset($body['region']) ? trim((string) $body['region']) : '';

    if ($account === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Укажите аккаунт и пароль eWeLink']);
        exit;
    }

    $keyMaterial = ewelink_key_material();
    if ($keyMaterial === '') {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Задайте FARMPULSE_EWELINK_KEY в окружении или создайте файл data/ewelink.key (секрет ≥16 символов)',
        ]);
        exit;
    }

    $nodeIn = [
        'account' => $account,
        'password' => $password,
        'area_code' => $areaCode,
    ];
    if ($regionHint !== '') {
        $nodeIn['region'] = $regionHint;
    }

    $result = ewelink_run_node_login($nodeIn);
    if (empty($result['ok'])) {
        http_response_code(401);
        $msg = $result['msg'] ?? ($result['error'] ?? 'Login failed');
        echo json_encode([
            'status' => 'error',
            'message' => is_string($msg) ? $msg : json_encode($msg),
            'code' => $result['error'] ?? null,
        ]);
        exit;
    }

    try {
        $blob = ewelink_encrypt_secrets(
            [
                'at' => $result['at'] ?? '',
                'rt' => $result['rt'] ?? '',
                'apikey' => $result['apikey'] ?? '',
            ],
            $keyMaterial
        );
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }

    $meta = [
        'account_masked' => ewelink_mask_account($account),
        'region' => $result['region'] ?? null,
        'connected_at' => gmdate('Y-m-d H:i:s'),
    ];

    $state = [
        'version' => 1,
        'meta' => $meta,
        'secrets' => $blob,
    ];

    if (!ewelink_write_state($state)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Не удалось сохранить настройки']);
        exit;
    }

    echo json_encode([
        'status' => 'OK',
        'connected' => true,
        'account_masked' => $meta['account_masked'],
        'region' => $meta['region'],
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
