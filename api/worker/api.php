<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/request_parse.php';
require_once __DIR__ . '/farm_stats_store.php';
require_once __DIR__ . '/../v2/farms/config_io.php';

$configFile = __DIR__ . '/../v2/farms/config.json';

// Simple logger
$logFile = __DIR__ . '/../v2/requests.log';
$uri = $_SERVER['REQUEST_URI'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$hdrs = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $hdrs[$k] = $v;
    }
}
$rawBody = file_get_contents('php://input');
@file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] workerAPI $method $uri\n" . json_encode($hdrs) . "\n" . ($rawBody ?: '') . "\n---\n", FILE_APPEND);

// Parse inputs (query + gzip/json/msgpack + form)
$queryId = isset($_GET['id_rig']) ? (string) $_GET['id_rig'] : null;
$queryMethod = isset($_GET['method']) ? (string) $_GET['method'] : null;
$contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
$contentEncoding = $_SERVER['HTTP_CONTENT_ENCODING'] ?? '';
$post = [];
if ($rawBody !== '') {
    $post = hive_worker_parse_request_body($rawBody, $contentType, $contentEncoding);
    if ($post === []) {
        parse_str($rawBody, $post);
    }
}
$rpcParams = is_array($post['params'] ?? null) ? $post['params'] : [];
$password = $post['password'] ?? ($_POST['password'] ?? ($_GET['password'] ?? ($rpcParams['passwd'] ?? null)));

$farmId = $queryId ?? ($post['id_rig'] ?? ($rpcParams['rig_id'] ?? null));
$op = strtolower((string) $queryMethod);
if ($op === '' && isset($post['method'])) {
    $op = strtolower((string) $post['method']);
}

if (!$farmId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing id_rig']);
    exit;
}

if (!in_array($op, ['hello', 'stats', 'message'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown method']);
    exit;
}

// helper to build minimal config payload from global config
function buildFarmRuntimeConfig(array $config): array
{
    $mining = $config['mining'] ?? [];
    $defaultPool = $mining['default_pool'] ?? [];
    $backupPools = $mining['backup_pools'] ?? [];
    $settings = $config['settings'] ?? [];

    return [
        'miner' => $settings['default_miner'] ?? 'teamredminer',
        'pools' => array_values(array_filter([
            $defaultPool ? [
                'coin' => $defaultPool['coin'] ?? null,
                'url' => $defaultPool['pool'] ?? null,
                'user' => $defaultPool['wallet'] ?? null,
                'pass' => $defaultPool['password'] ?? 'x',
            ] : null,
        ])),
        'backup_pools' => array_map(function ($p) {
            return [
                'coin' => $p['coin'] ?? null,
                'url' => $p['pool'] ?? null,
                'user' => $p['wallet'] ?? null,
                'pass' => $p['password'] ?? 'x',
            ];
        }, is_array($backupPools) ? $backupPools : []),
        'overclock' => $config['overclocking'] ?? [],
        'timezone' => $settings['timezone'] ?? 'UTC',
    ];
}

function buildFarmRuntimeConfigSh(array $config, string $farmId, string $farmPassword = '', string $serverUrl = ''): string
{
    $mining = $config['mining'] ?? [];
    $defaultPool = $mining['default_pool'] ?? [];
    $settings = $config['settings'] ?? [];

    $lines = [];
    $lines[] = 'RIG_ID=' . escapeshellarg($farmId);
    if ($farmPassword !== '') {
        $lines[] = 'RIG_PASSWD=' . escapeshellarg($farmPassword);
    }
    if ($serverUrl !== '') {
        $lines[] = 'HIVE_HOST_URL=' . escapeshellarg($serverUrl);
    }
    $workerName = $config['farms'][$farmId]['name'] ?? '';
    if ($workerName !== '') {
        $lines[] = 'WORKER_NAME=' . escapeshellarg($workerName);
    }
    $lines[] = 'MINER=' . escapeshellarg($settings['default_miner'] ?? 'teamredminer');
    if (!empty($defaultPool)) {
        $lines[] = 'POOL=' . escapeshellarg($defaultPool['pool'] ?? '');
        $lines[] = 'WALLET=' . escapeshellarg($defaultPool['wallet'] ?? '');
        $lines[] = 'PASS=' . escapeshellarg($defaultPool['password'] ?? 'x');
        $lines[] = 'COIN=' . escapeshellarg($defaultPool['coin'] ?? '');
    }
    $lines[] = 'TIMEZONE=' . escapeshellarg($settings['timezone'] ?? 'UTC');
    $lines[] = 'SHELLINABOX_ENABLE=0';
    $lines[] = 'SSH_ENABLE=1';
    $lines[] = 'SSH_PASSWORD_ENABLE=1';

    return implode("\n", $lines) . "\n";
}

$ctx = ['code' => 200, 'body' => ''];

$tx = hive_farms_config_transaction($configFile, function (&$config) use ($farmId, $op, $password, $post, $rpcParams, &$ctx) {
    if (!isset($config['farms'][$farmId])) {
        $ctx['code'] = 404;
        $ctx['body'] = json_encode(['status' => 'error', 'message' => 'Farm not found']);

        return false;
    }

    switch ($op) {
        case 'hello':
            if (!$password || ($config['farms'][$farmId]['password'] ?? null) !== $password) {
                $ctx['code'] = 401;
                $ctx['body'] = json_encode(['status' => 'error', 'message' => 'Invalid credentials']);

                return false;
            }
            $config['farms'][$farmId]['status'] = 'online';
            $config['farms'][$farmId]['last_seen_at'] = date('Y-m-d H:i:s');
            $gpuCount = intval($rpcParams['gpu_count_amd'] ?? 0) + intval($rpcParams['gpu_count_nvidia'] ?? 0);
            if ($gpuCount > 0) {
                $config['farms'][$farmId]['gpu_count'] = $gpuCount;
            }
            if (empty($config['farms'][$farmId]['token'])) {
                $config['farms'][$farmId]['token'] = bin2hex(random_bytes(16));
            }
            hive_worker_merge_hello_into_farm($config['farms'][$farmId], $rpcParams);
            $cfg = buildFarmRuntimeConfig($config);
            $serverUrl = $rpcParams['server_url'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''));
            $cfgSh = buildFarmRuntimeConfigSh($config, (string) $farmId, (string) ($config['farms'][$farmId]['password'] ?? ''), (string) $serverUrl);
            $rpcId = isset($post['id']) ? $post['id'] : 0;
            $resp = [
                'jsonrpc' => '2.0',
                'id' => $rpcId,
                'result' => [
                    'config' => $cfgSh,
                    'config_object' => $cfg,
                    'commands' => [],
                ],
                'config' => $cfgSh,
            ];
            $ctx['body'] = json_encode($resp);

            return true;

        case 'stats':
            if (($config['farms'][$farmId]['password'] ?? '') !== ($password ?? '')) {
                $ctx['code'] = 401;
                $ctx['body'] = json_encode(['status' => 'error', 'message' => 'Invalid credentials']);

                return false;
            }
            $config['farms'][$farmId]['status'] = 'online';
            $config['farms'][$farmId]['last_seen_at'] = gmdate('Y-m-d H:i:s');
            hive_worker_merge_stats_into_farm($config['farms'][$farmId], $rpcParams);

            $rpcId = isset($post['id']) ? $post['id'] : 0;
            $queue = $config['farms'][$farmId]['commands'] ?? [];
            if (!empty($queue)) {
                $normalized = [];
                foreach ($queue as $c) {
                    $id = $c['id'] ?? uniqid('cmd_', true);
                    $cmd = $c['command'] ?? ((($c['type'] ?? '') === 'cmd' && !empty($c['data'])) ? $c['data'] : null);
                    if (!empty($cmd)) {
                        $item = ['id' => $id, 'command' => $cmd];
                        if (isset($c['exec']) && is_string($c['exec']) && $c['exec'] !== '') {
                            $item['exec'] = $c['exec'];
                        }
                        $normalized[] = $item;
                    }
                }
                $chosen = end($normalized);
                foreach (array_reverse($normalized) as $cand) {
                    if (($cand['command'] ?? '') === 'exec' && isset($cand['exec'])) {
                        $chosen = $cand;
                        break;
                    }
                }
                $result = ['command' => $chosen['command'], 'id' => $chosen['id']];
                if ($result['command'] === 'exec') {
                    $result['exec'] = $chosen['exec'] ?? '/hive/sbin/sreboot';
                }
                $config['farms'][$farmId]['commands'] = array_values(array_filter($queue, function ($c) use ($chosen) {
                    return (($c['id'] ?? null) !== $chosen['id']);
                }));
                $ctx['body'] = json_encode(['jsonrpc' => '2.0', 'id' => $rpcId, 'result' => $result]);
            } else {
                $ctx['body'] = json_encode(['jsonrpc' => '2.0', 'id' => $rpcId, 'result' => ['command' => 'OK']]);
            }

            return true;

        case 'message':
            $config['farms'][$farmId]['status'] = 'online';
            $config['farms'][$farmId]['last_seen_at'] = date('Y-m-d H:i:s');
            $queue = $config['farms'][$farmId]['commands'] ?? [];
            $normalized = [];
            foreach ($queue as $c) {
                $id = $c['id'] ?? uniqid('cmd_', true);
                $cmd = $c['command'] ?? ((($c['type'] ?? '') === 'cmd' && !empty($c['data'])) ? $c['data'] : null);
                if (!empty($cmd)) {
                    $item = ['id' => $id, 'command' => $cmd];
                    if (isset($c['exec']) && is_string($c['exec']) && $c['exec'] !== '') {
                        $item['exec'] = $c['exec'];
                    }
                    $normalized[] = $item;
                }
            }
            $rpcId = isset($post['id']) ? $post['id'] : 0;
            if (!empty($normalized)) {
                $chosen = end($normalized);
                foreach (array_reverse($normalized) as $cand) {
                    if (($cand['command'] ?? '') === 'exec' && isset($cand['exec'])) {
                        $chosen = $cand;
                        break;
                    }
                }
                $result = ['command' => 'batch', 'confseq' => (int) time()];
                $cmdObj = ['id' => (int) $chosen['id'], 'command' => $chosen['command']];
                if (($chosen['command'] ?? '') === 'reboot') {
                    $cmdObj['command'] = 'reboot';
                } elseif (($chosen['command'] ?? '') === 'exec') {
                    $cmdObj['command'] = 'exec';
                    if (isset($chosen['exec'])) {
                        $cmdObj['exec'] = $chosen['exec'];
                    }
                }
                if ($cmdObj['command'] === 'exec' && empty($cmdObj['exec'])) {
                    $cmdObj['exec'] = '/hive/sbin/sreboot';
                }
                $result['commands'] = [$cmdObj];
                $config['farms'][$farmId]['commands'] = array_values(array_filter($queue, function ($c) use ($chosen) {
                    return (($c['id'] ?? null) !== $chosen['id']);
                }));
                $ctx['body'] = json_encode(['jsonrpc' => '2.0', 'id' => $rpcId, 'result' => $result]);

                return true;
            }
            $ctx['body'] = json_encode(['jsonrpc' => '2.0', 'id' => $rpcId, 'result' => ['command' => 'OK']]);

            return false;

        default:
            $ctx['code'] = 400;
            $ctx['body'] = json_encode(['status' => 'error', 'message' => 'Unknown method']);

            return false;
    }
});

$okOut = ($tx === true && $ctx['body'] !== '') || ($tx === 'skip' && $ctx['body'] !== '');
if (!$okOut) {
    $ctx['code'] = $tx === 'lock' ? 503 : 500;
    if ($tx === 'corrupt') {
        $ctx['body'] = json_encode(['status' => 'error', 'message' => 'Farm configuration file is corrupted (invalid JSON)']);
    } elseif ($tx === 'read') {
        $ctx['body'] = json_encode(['status' => 'error', 'message' => 'Farm configuration file is not readable']);
    } elseif ($tx === 'lock') {
        $ctx['body'] = json_encode(['status' => 'error', 'message' => 'Configuration is busy, retry']);
    } else {
        $ctx['body'] = json_encode(['status' => 'error', 'message' => 'Failed to update configuration']);
    }
}

http_response_code($ctx['code']);
echo $ctx['body'];
