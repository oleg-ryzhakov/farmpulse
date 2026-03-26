<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error','message'=>'Method not allowed']); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$login = trim((string)($in['login'] ?? ''));
$password = (string)($in['password'] ?? '');
if ($login === '' || $password === '') { http_response_code(400); echo json_encode(['status'=>'error','message'=>'login and password are required']); exit; }

$url = 'https://api2.hiveos.farm/api/v2/auth/login';
$ch = curl_init($url);
curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_CONNECTTIMEOUT => 5,
	CURLOPT_TIMEOUT => 15,
	CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
	CURLOPT_SSL_VERIFYPEER => false,
	CURLOPT_SSL_VERIFYHOST => false,
	CURLOPT_HTTPHEADER => [
		'Accept: application/json',
		'Content-Type: application/json',
		'User-Agent: HiveClient 1.0',
		'Expect:'
	],
	CURLOPT_POST => true,
	CURLOPT_POSTFIELDS => json_encode(['login'=>$login,'password'=>$password]),
	CURLOPT_FOLLOWLOCATION => false,
	CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err  = curl_error($ch);
curl_close($ch);

$data = json_decode((string)$raw, true);
if ($code >= 200 && $code < 300 && is_array($data)) {
	echo json_encode(['status'=>'OK','http'=>$code,'data'=>$data]);
} else {
	http_response_code($code > 0 ? $code : 500);
	echo json_encode([
		'status' => 'error',
		'http' => $code,
		'curl_error' => $err,
		'raw' => substr((string)$raw, 0, 800)
	]);
}


