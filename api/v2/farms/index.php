<?php
// Fake Hive OS Management API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// --- Авторизация ---
if ($uri === '/api/v2/auth/login' && $method === 'POST') {
    $body = json_decode(file_get_contents("php://input"), true);

    if (!empty($body['login']) && !empty($body['password'])) {
        // Проверяем на "правильные" данные (можно подставить свои rig_id и пароль)
        if ($body['login'] === '123' && $body['password'] === '12345678') {
            echo json_encode([
                "access_token" => "fake_token_123",
                "token_type"   => "Bearer",
                "expires_in"   => 3600
            ]);
        } else {
            http_response_code(401);
            echo json_encode(["error" => "Invalid credentials"]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["error" => "Bad request"]);
    }
    exit;
}

// --- Список ферм ---
if ($uri === '/api/v2/farms' && $method === 'GET') {
    echo json_encode([
        "data" => [[
            "id" => 1,
            "name" => "Test Farm",
            "workers_count" => 1
        ]]
    ]);
    exit;
}

// --- Список воркеров ---
if ($uri === '/api/v2/farms/1/workers' && $method === 'GET') {
    echo json_encode([
        "data" => [[
            "id" => 123,
            "name" => "TestRig",
            "status" => "online",
            "hashrate" => "0 MH/s"
        ]]
    ]);
    exit;
}

// --- Конкретный воркер ---
if ($uri === '/api/v2/farms/1/workers/123' && $method === 'GET') {
    echo json_encode([
        "id" => 123,
        "name" => "TestRig",
        "status" => "online",
        "hashrate" => "0 MH/s"
    ]);
    exit;
}

// --- Ответ по умолчанию ---
echo json_encode([
    "status" => "OK",
    "message" => "Hive OS Fake Management API",
    "version" => "0.1",
    "time" => date('Y-m-d H:i:s')
]);
