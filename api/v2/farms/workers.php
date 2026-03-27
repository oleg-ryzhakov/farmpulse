<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$configFile = __DIR__ . '/config.json';
$config = json_decode(@file_get_contents($configFile), true);
if (!is_array($config)) {
    $config = ['farms' => []];
}
if (!isset($config['farms']) || !is_array($config['farms'])) {
    $config['farms'] = [];
}

// ID фермы из запроса
$farmId = $_GET['farm_id'] ?? null;

$method = $_SERVER['REQUEST_METHOD'];

if (!$farmId || !isset($config['farms'][$farmId])) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Farm not found"]);
    exit;
}

switch ($method) {
    case 'GET':
        echo json_encode([
            "status" => "OK",
            "farm" => [
                'id' => $farmId,
                'name' => $config['farms'][$farmId]['name'] ?? ('Farm ' . $farmId),
                'status' => $config['farms'][$farmId]['status'] ?? 'offline',
                'last_seen_at' => $config['farms'][$farmId]['last_seen_at'] ?? null,
                'password' => $config['farms'][$farmId]['password'] ?? null
            ]
        ]);
        break;

    case 'POST':
        // Для новой модели POST означает "heartbeat/online" от реальной фермы
        $input = json_decode(file_get_contents("php://input"), true);
        $token = $input['token'] ?? null;
        if (!$token || !isset($config['farms'][$farmId]['token']) || $config['farms'][$farmId]['token'] !== $token) {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "Unauthorized"]);
            exit;
        }
        $config['farms'][$farmId]['status'] = 'online';
        $config['farms'][$farmId]['last_seen_at'] = gmdate('Y-m-d H:i:s');

        if (file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT))) {
            echo json_encode(["status" => "OK"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Failed to save configuration"]);
        }
        break;

    case 'DELETE':
        // DELETE теперь удаляет ферму целиком
        unset($config['farms'][$farmId]);

        if (file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT))) {
            echo json_encode(["status" => "OK"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Failed to save configuration"]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        break;
}