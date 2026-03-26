<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$coin = trim((string)($_GET['coin'] ?? ''));
$limit = max(1, min(200, intval($_GET['limit'] ?? 100)));
$debug = (isset($_GET['debug']) && $_GET['debug'] == '1');

$cacheFile = __DIR__ . '/pools.cache.json';
$cacheTtl = 600;
$pools = null;
$forceRefresh = (isset($_GET['refresh']) && $_GET['refresh'] == '1');
if (!$forceRefresh && is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
	$pools = json_decode(@file_get_contents($cacheFile), true);
}

if (!is_array($pools)) {
	$pools = [];
	$lastHttp = 0; $lastErr = ''; $lastRaw = '';
	$page = 1; $perPage = 200;
	while ($page <= 10) {
		$url = 'https://api2.hiveos.farm/api/v2/public/pools?' . http_build_query(['per_page'=>$perPage,'page'=>$page]);
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 12,
			CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_HTTPHEADER => [
				'Accept: application/json',
				'User-Agent: HiveClient 1.0'
			]
		]);
		$raw = curl_exec($ch);
		$lastHttp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$lastErr = curl_error($ch);
		curl_close($ch);
		$lastRaw = (string)$raw;
		$data = json_decode((string)$raw, true);
		$arr = [];
		if (isset($data['data']) && is_array($data['data'])) { $arr = $data['data']; }
		elseif (is_array($data)) { $arr = $data; }
		if (empty($arr)) break;
		foreach ($arr as $p) {
			$pools[] = [
				'id' => $p['id'] ?? null,
				'name' => $p['name'] ?? null,
				'coin' => $p['coin'] ?? null,
				'url' => $p['url'] ?? null,
				'port' => $p['port'] ?? null,
				'ssl_port' => $p['ssl_port'] ?? null,
				'template' => $p['template'] ?? null
			];
		}
		if (count($arr) < $perPage) break;
		$page++;
	}
	if (!empty($pools)) {
		@file_put_contents($cacheFile, json_encode($pools));
	} else {
		$sample = __DIR__ . '/pools.sample.json';
		if (is_file($sample)) {
			$pools = json_decode(@file_get_contents($sample), true);
		}
		if ($debug && empty($pools)) {
			echo json_encode(['status'=>'error','message'=>'Remote fetch failed','http'=>$lastHttp,'curl_error'=>$lastErr,'raw'=>substr($lastRaw,0,400)]);
			exit;
		}
	}
}

if (!is_array($pools)) { $pools = []; }
if ($coin !== '') {
	$coinLower = mb_strtolower($coin);
	$pools = array_values(array_filter($pools, function($p) use ($coinLower) {
		return mb_strtolower($p['coin'] ?? '') === $coinLower;
	}));
}

echo json_encode(['status' => 'OK', 'data' => array_slice($pools, 0, $limit)]);


