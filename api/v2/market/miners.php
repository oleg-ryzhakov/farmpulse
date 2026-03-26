<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$algo = trim((string)($_GET['algorithm'] ?? ''));
$limit = max(1, min(200, intval($_GET['limit'] ?? 100)));
$debug = (isset($_GET['debug']) && $_GET['debug'] == '1');

$cacheFile = __DIR__ . '/miners.cache.json';
$cacheTtl = 600;
$forceRefresh = (isset($_GET['refresh']) && $_GET['refresh'] == '1');
$now = time();
$miners = null;

if (!$forceRefresh && is_file($cacheFile) && ($now - filemtime($cacheFile) < $cacheTtl)) {
    $miners = json_decode(@file_get_contents($cacheFile), true);
}

if (!is_array($miners)) {
    $cfg = @include __DIR__ . '/config.php';
    $token = is_array($cfg) ? ($cfg['hive_token'] ?? '') : '';

    $miners = [];
    $lastHttp = 0; $lastErr = ''; $lastRaw = '';

    // 1) If we have a token (paid farms), try private API
    if ($token !== '') {
        $headers = [
            'Accept: application/json',
            'User-Agent: HiveClient 1.0',
            'Authorization: Bearer ' . $token
        ];
        $page = 1; $perPage = 200;
        while ($page <= 20) {
            $url = 'https://api2.hiveos.farm/api/v2/miners?' . http_build_query(['per_page'=>$perPage,'page'=>$page]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => $headers,
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
            foreach ($arr as $m) {
                $miners[] = [
                    'id' => $m['id'] ?? null,
                    'name' => $m['name'] ?? null,
                    'version' => $m['version'] ?? null,
                    'algorithms' => $m['algorithms'] ?? ($m['supported_algorithms'] ?? []),
                ];
            }
            if (count($arr) < $perPage) break;
            $page++;
        }
        if (!empty($miners)) {
            @file_put_contents($cacheFile, json_encode($miners));
        }
    }

    // 2) If no token or private API failed, try public web-api host without auth
    if (empty($miners)) {
        $page = 1; $perPage = 200;
        while ($page <= 5) {
            $url = 'https://web-api.hiveos.farm/api/v2/miners?' . http_build_query(['per_page'=>$perPage,'page'=>$page]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => [ 'Accept: application/json', 'User-Agent: HiveClient 1.0' ],
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
            foreach ($arr as $m) {
                $miners[] = [
                    'id' => $m['id'] ?? null,
                    'name' => $m['name'] ?? null,
                    'version' => $m['version'] ?? null,
                    'algorithms' => $m['algorithms'] ?? ($m['supported_algorithms'] ?? []),
                ];
            }
            if (count($arr) < $perPage) break;
            $page++;
        }
        if (!empty($miners)) {
            @file_put_contents($cacheFile, json_encode($miners));
        }
    }

    // 3) Fallback to bundled sample
    if (empty($miners)) {
        $sample = __DIR__ . '/miners.sample.json';
        if (is_file($sample)) {
            $miners = json_decode(@file_get_contents($sample), true);
        }
        if ($debug && empty($miners)) {
            echo json_encode(['status'=>'error','message'=>'Remote fetch failed','http'=>$lastHttp,'curl_error'=>$lastErr,'raw'=>substr($lastRaw,0,400)]);
            exit;
        }
    }
}

if (!is_array($miners)) { $miners = []; }
if ($algo !== '') {
    $algoLower = mb_strtolower($algo);
    $miners = array_values(array_filter($miners, function($m) use ($algoLower) {
        $algs = array_map('mb_strtolower', (array)($m['algorithms'] ?? []));
        return in_array($algoLower, $algs, true);
    }));
}

echo json_encode(['status' => 'OK', 'data' => array_slice($miners, 0, $limit)]);


