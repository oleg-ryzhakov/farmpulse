<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(200);
	exit();
}

$logFile = __DIR__ . '/../requests.log';
$hdrs = [];
foreach ($_SERVER as $k => $v) { if (strpos($k, 'HTTP_') === 0) { $hdrs[$k] = $v; } }
$rawBody = file_get_contents('php://input');
file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI'] . "\n" . json_encode($hdrs) . "\n" . ($rawBody ?: '') . "\n---\n", FILE_APPEND);

$body = json_decode($rawBody, true) ?: [];
$farmId = $body['farm_id'] ?? $body['rig_id'] ?? $body['login'] ?? ($_POST['farm_id'] ?? $_POST['rig_id'] ?? $_POST['login'] ?? null);
$password = $body['password'] ?? ($_POST['password'] ?? null);

$configFile = __DIR__ . '/../farms/config.json';
$config = json_decode(file_get_contents($configFile), true);

if (!$farmId || !$password || !isset($config['farms'][$farmId])) {
	http_response_code(401);
	echo json_encode(["error" => "Invalid credentials"]);
	exit;
}

if (($config['farms'][$farmId]['password'] ?? null) !== $password) {
	http_response_code(401);
	echo json_encode(["error" => "Invalid credentials"]);
	exit;
}

$token = bin2hex(random_bytes(16));
$config['farms'][$farmId]['token'] = $token;
file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));

echo json_encode([
	"access_token" => $token,
	"token_type" => "Bearer",
	"expires_in" => 3600,
	"farm_id" => (string)$farmId
]);


