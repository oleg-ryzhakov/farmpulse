<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$configFile = __DIR__ . '/../v2/farms/config.json';
$config = json_decode(@file_get_contents($configFile), true);
if (!is_array($config)) {
    $config = ['farms' => []];
}

// Simple logger
$logFile = __DIR__ . '/../v2/requests.log';
$uri = $_SERVER['REQUEST_URI'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$hdrs = [];
foreach ($_SERVER as $k => $v) { if (strpos($k, 'HTTP_') === 0) { $hdrs[$k] = $v; } }
$rawBody = file_get_contents('php://input');
@file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] workerAPI $method $uri\n" . json_encode($hdrs) . "\n" . ($rawBody ?: '') . "\n---\n", FILE_APPEND);

// Parse inputs (query + form + json)
$queryId = isset($_GET['id_rig']) ? (string)$_GET['id_rig'] : null;
$queryMethod = isset($_GET['method']) ? (string)$_GET['method'] : null;
$post = [];
if (!empty($rawBody)) {
    // JSON
    $json = json_decode($rawBody, true);
    if (is_array($json)) { $post = $json; }
    else {
        // x-www-form-urlencoded or plain
        parse_str($rawBody, $post);
    }
}
$rpcParams = is_array($post['params'] ?? null) ? $post['params'] : [];
$password = $post['password'] ?? ($_POST['password'] ?? ($_GET['password'] ?? ($rpcParams['passwd'] ?? null)));

$farmId = $queryId ?? ($post['id_rig'] ?? ($rpcParams['rig_id'] ?? null));
$op = strtolower((string)$queryMethod);
if ($op === '' && isset($post['method'])) { $op = strtolower((string)$post['method']); }

if (!$farmId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing id_rig']);
    exit;
}

if (!isset($config['farms'][$farmId])) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Farm not found']);
    exit;
}

// helper to build minimal config payload from global config
function buildFarmRuntimeConfig(array $config): array {
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
                'pass' => $defaultPool['password'] ?? 'x'
            ] : null,
            // include backups as-is
        ])),
        'backup_pools' => array_map(function($p) {
            return [
                'coin' => $p['coin'] ?? null,
                'url' => $p['pool'] ?? null,
                'user' => $p['wallet'] ?? null,
                'pass' => $p['password'] ?? 'x'
            ];
        }, is_array($backupPools) ? $backupPools : []),
        'overclock' => $config['overclocking'] ?? [],
        'timezone' => $settings['timezone'] ?? 'UTC'
    ];
}

function buildFarmRuntimeConfigSh(array $config, string $farmId, string $farmPassword = '', string $serverUrl = ''): string {
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
    // Worker (rig) name — берём из имени фермы
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
    // Enable SSH on the rig to avoid localhost-only binding after reboot
    $lines[] = 'SHELLINABOX_ENABLE=0';
    $lines[] = 'SSH_ENABLE=1';
    $lines[] = 'SSH_PASSWORD_ENABLE=1';
    return implode("\n", $lines) . "\n";
}


switch ($op) {
    case 'hello':
        // Require password match
        if (!$password || ($config['farms'][$farmId]['password'] ?? null) !== $password) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid credentials']);
            exit;
        }
        // Mark online
        $config['farms'][$farmId]['status'] = 'online';
        $config['farms'][$farmId]['last_seen_at'] = date('Y-m-d H:i:s');
        // Save initial gpu count if provided in hello
        $gpuCount = intval($rpcParams['gpu_count_amd'] ?? 0) + intval($rpcParams['gpu_count_nvidia'] ?? 0);
        if ($gpuCount > 0) { $config['farms'][$farmId]['gpu_count'] = $gpuCount; }
        if (empty($config['farms'][$farmId]['token'])) {
            $config['farms'][$farmId]['token'] = bin2hex(random_bytes(16));
        }
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        $cfg = buildFarmRuntimeConfig($config);
        $serverUrl = $rpcParams['server_url'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''));
        $cfgSh = buildFarmRuntimeConfigSh($config, (string)$farmId, (string)($config['farms'][$farmId]['password'] ?? ''), (string)$serverUrl);
        $rpcId = isset($post['id']) ? $post['id'] : 0;
        $resp = [
            'jsonrpc' => '2.0',
            'id' => $rpcId,
            'result' => [
                'config' => $cfgSh,
                'config_object' => $cfg,
                'commands' => []
            ],
            // duplicate top-level for older agents that read at root
            'config' => $cfgSh
        ];
        echo json_encode($resp);
        break;

    case 'stats':
        // Heartbeat + optional command delivery (match agent expectations)
        $config['farms'][$farmId]['status'] = 'online';
        $config['farms'][$farmId]['last_seen_at'] = date('Y-m-d H:i:s');
        // Persist GPU temperatures and count (skip first placeholder)
        $tempsRaw = $rpcParams['temp'] ?? [];
        if (is_array($tempsRaw)) {
            $temps = array_slice($tempsRaw, 1);
            $temps = array_values(array_filter($temps, function($v){ return is_numeric($v) && intval($v) > 0; }));
            $config['farms'][$farmId]['gpu_temps'] = $temps;
            $config['farms'][$farmId]['gpu_count'] = count($temps);
        }
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));

        $rpcId = isset($post['id']) ? $post['id'] : 0;
        $queue = $config['farms'][$farmId]['commands'] ?? [];
        if (!empty($queue)) {
            // Choose last; prefer exec if present
            $normalized = [];
            foreach ($queue as $c) {
                $id = $c['id'] ?? uniqid('cmd_', true);
                $cmd = $c['command'] ?? ((($c['type'] ?? '') === 'cmd' && !empty($c['data'])) ? $c['data'] : null);
                if (!empty($cmd)) {
                    $item = ['id' => $id, 'command' => $cmd];
                    if (isset($c['exec']) && is_string($c['exec']) && $c['exec'] !== '') { $item['exec'] = $c['exec']; }
                    $normalized[] = $item;
                }
            }
            $chosen = end($normalized);
            foreach (array_reverse($normalized) as $cand) {
                if (($cand['command'] ?? '') === 'exec' && isset($cand['exec'])) { $chosen = $cand; break; }
            }
            // Single-command shape (like original Hive): result.command = reboot|exec
            $result = [ 'command' => $chosen['command'], 'id' => $chosen['id'] ];
            if ($result['command'] === 'exec') {
                $result['exec'] = $chosen['exec'] ?? '/hive/sbin/sreboot';
            } elseif ($result['command'] === 'reboot') {
                // leave as reboot (agent handles reboot)
            }
            echo json_encode(['jsonrpc' => '2.0', 'id' => $rpcId, 'result' => $result]);
            // Pop delivered command
            $config['farms'][$farmId]['commands'] = array_values(array_filter($queue, function($c) use ($chosen) {
                return (($c['id'] ?? null) !== $chosen['id']);
            }));
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        } else {
            // Always include command key so agent doesn't warn on null
            echo json_encode(['jsonrpc' => '2.0', 'id' => $rpcId, 'result' => ['command' => 'OK']]);
        }
        break;

    case 'message':
        // Treat as heartbeat + deliver queued commands (minimal shape for agent)
        $config['farms'][$farmId]['status'] = 'online';
        $config['farms'][$farmId]['last_seen_at'] = date('Y-m-d H:i:s');
        $queue = $config['farms'][$farmId]['commands'] ?? [];
        // Normalize to [{id, command, exec?}] and keep original exec if present
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
            // Prefer the last queued command, and prefer exec-type if present
            $chosen = end($normalized);
            foreach (array_reverse($normalized) as $cand) {
                if (($cand['command'] ?? '') === 'exec' && isset($cand['exec'])) { $chosen = $cand; break; }
            }
            // Формируем ответ в batch-режиме (наиболее совместимо с агентом)
            $result = [ 'command' => 'batch', 'confseq' => (int)time() ];
            $cmdObj = [ 'id' => (int)$chosen['id'], 'command' => $chosen['command'] ];
            if (($chosen['command'] ?? '') === 'reboot') {
                // пусть агент сам вызовет ветку reboot)
                $cmdObj['command'] = 'reboot';
            } elseif (($chosen['command'] ?? '') === 'exec') {
                $cmdObj['command'] = 'exec';
                if (isset($chosen['exec'])) { $cmdObj['exec'] = $chosen['exec']; }
            }
            // На случай когда в очереди reboot без exec — добавим явный путь
            if ($cmdObj['command'] === 'exec' && empty($cmdObj['exec'])) {
                $cmdObj['exec'] = '/hive/sbin/sreboot';
            }
            $result['commands'] = [ $cmdObj ];
            // Удалим доставленную команду из очереди, чтобы не повторялась
            $config['farms'][$farmId]['commands'] = array_values(array_filter($queue, function($c) use ($chosen) {
                return (($c['id'] ?? null) !== $chosen['id']);
            }));
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
            echo json_encode(['jsonrpc' => '2.0', 'id' => $rpcId, 'result' => $result]);
        } else {
            echo json_encode(['jsonrpc' => '2.0', 'id' => $rpcId, 'result' => ['command' => 'OK']]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unknown method']);
        break;
}


