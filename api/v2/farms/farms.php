<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$configFile = __DIR__ . '/config.json';
$config = json_decode(@file_get_contents($configFile), true);
if (!is_array($config)) {
    $config = [];
}

$method = $_SERVER['REQUEST_METHOD'];

if (!isset($config['farms'])) {
    $config['farms'] = [];
}

switch ($method) {
    case 'GET':
        $farms = [];
        foreach ($config['farms'] as $id => $farm) {
            $farms[] = [
                'id' => (string)$id,
                'name' => $farm['name'] ?? ('Farm ' . $id),
                'status' => $farm['status'] ?? 'offline',
                'last_seen_at' => $farm['last_seen_at'] ?? null,
                'gpu_count' => $farm['gpu_count'] ?? 0,
                'gpu_temps' => $farm['gpu_temps'] ?? [],
                'total_khs' => $farm['total_khs'] ?? null,
                'total_power_w' => $farm['total_power_w'] ?? null,
                'last_stats_at' => $farm['last_stats_at'] ?? null,
                'rig_info' => $farm['rig_info'] ?? null,
                'password' => $farm['password'] ?? null,
                'summary_miner' => $farm['summary_miner'] ?? null,
                'summary_coin' => $farm['summary_coin'] ?? null,
                'summary_algo' => $farm['summary_algo'] ?? null,
                'summary_loadavg' => $farm['summary_loadavg'] ?? null,
                'summary_uptime_sec' => $farm['summary_uptime_sec'] ?? null,
                'summary_net_ips' => $farm['summary_net_ips'] ?? null,
                'heat_warning' => $farm['heat_warning'] ?? false,
                'ewelink_device_id' => $farm['ewelink_device_id'] ?? null,
                'ewelink_device_name' => $farm['ewelink_device_name'] ?? null,
                'ewelink_device_item_type' => isset($farm['ewelink_device_item_type']) ? (int) $farm['ewelink_device_item_type'] : null,
            ];
        }
        echo json_encode([
            'status' => 'OK',
            'farms' => $farms
        ]);
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['name'] ?? 'New Farm';
        $password = $input['password'] ?? null;

        if (!$password || strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters']);
            break;
        }

        $ids = array_map(function ($k) { return is_numeric($k) ? (int)$k : 0; }, array_keys($config['farms']));
        $newId = empty($ids) ? 1 : (max($ids) + 1);

        $config['farms'][(string)$newId] = [
            'name' => $name,
            'password' => $password,
            'token' => null,
            'status' => 'offline',
            'last_seen_at' => null
        ];

        if (file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT))) {
            echo json_encode(['status' => 'OK', 'id' => (string)$newId, 'name' => $name]);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to save configuration']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}



