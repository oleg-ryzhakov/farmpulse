<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$logFile = __DIR__ . '/scrape-live.log';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	if (isset($_GET['clear']) && $_GET['clear'] == '1') {
		@file_put_contents($logFile, "");
		echo json_encode(['status'=>'OK','message'=>'cleared']);
		return;
	}
	$tail = @file_get_contents($logFile);
	echo json_encode(['status'=>'OK','data'=>$tail]);
	return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error','message'=>'Method not allowed']); exit; }

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Invalid JSON']); exit; }

$now = date('Y-m-d H:i:s');
$entry = [ 'ts'=>$now, 'ip'=>($_SERVER['REMOTE_ADDR'] ?? ''), 'payload'=>$data ];
$line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
@file_put_contents($logFile, $line, FILE_APPEND);

echo json_encode(['status'=>'OK','stored'=>true]);



