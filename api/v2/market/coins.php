<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$q = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min(200, intval($_GET['limit'] ?? 50)));

// Простое проксирование к HiveOS API с кэшированием на 10 минут
$cacheFile = __DIR__ . '/coins.cache.json';
$cacheTtl = 600; // seconds
$forceRefresh = (isset($_GET['refresh']) && $_GET['refresh'] == '1');
$debug = (isset($_GET['debug']) && $_GET['debug'] == '1');
$now = time();
$coins = null;

if (!$forceRefresh && is_file($cacheFile) && ($now - filemtime($cacheFile) < $cacheTtl)) {
    $coins = json_decode(@file_get_contents($cacheFile), true);
}

if (!is_array($coins)) {
    $cfg = @include __DIR__ . '/config.php';
    $token = is_array($cfg) ? ($cfg['hive_token'] ?? '') : '';

    $coins = [];
    $lastHttp = 0; $lastErr = ''; $lastRaw = '';

    if ($token !== '') {
        $headers = [
            'Accept: application/json',
            'User-Agent: HiveClient 1.0',
            'Authorization: Bearer ' . $token
        ];
        $base = 'https://api2.hiveos.farm/api/v2/coins';
        $page = 1; $perPage = 200; $fetched = false;
        while ($page <= 20) {
            $url = $base . '?' . http_build_query(['per_page'=>$perPage,'page'=>$page]);
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
            $data = json_decode($raw, true);
            $arr = [];
            if (isset($data['data']) && is_array($data['data'])) { $arr = $data['data']; }
            elseif (is_array($data)) { $arr = $data; }
            if (empty($arr)) break;
            foreach ($arr as $c) {
                if (!is_array($c)) continue;
                $coins[] = [
                    'id' => $c['id'] ?? null,
                    'symbol' => $c['symbol'] ?? null,
                    'name' => $c['name'] ?? null,
                    'algorithm' => $c['algorithm'] ?? ($c['algo'] ?? null)
                ];
            }
            $fetched = true;
            if (count($arr) < $perPage) break;
            $page++;
        }
        if ($fetched && !empty($coins)) {
            @file_put_contents($cacheFile, json_encode($coins));
        }
    }

    // Public web endpoint fallback (no auth). Some environments block DNS; we still try with IPv4 & short timeouts
    if (empty($coins)) {
        $page = 1; $perPage = 200;
        while ($page <= 10) {
            $url = 'https://web-api.hiveos.farm/api/v2/coins?' . http_build_query(['per_page'=>$perPage,'page'=>$page]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: HiveClient 1.0'
                ],
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
            foreach ($arr as $c) {
                if (!is_array($c)) continue;
                $coins[] = [
                    'id' => $c['id'] ?? null,
                    'symbol' => $c['symbol'] ?? null,
                    'name' => $c['name'] ?? null,
                    'algorithm' => $c['algorithm'] ?? ($c['algo'] ?? null)
                ];
            }
            if (count($arr) < $perPage) break;
            $page++;
        }
        if (!empty($coins)) {
            @file_put_contents($cacheFile, json_encode($coins));
        }
    }

    if (empty($coins)) {
        // try to derive coins from public pools endpoint
        $poolsCoins = [];
        $page = 1; $perPage = 200;
        while ($page <= 10) {
            $url = 'https://api2.hiveos.farm/api/v2/public/pools?' . http_build_query(['per_page'=>$perPage,'page'=>$page]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: HiveClient 1.0'
                ],
            ]);
            $raw = curl_exec($ch);
            $lastHttp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $lastErr = curl_error($ch);
            curl_close($ch);
            $data = json_decode((string)$raw, true);
            $arr = [];
            if (isset($data['data']) && is_array($data['data'])) { $arr = $data['data']; }
            elseif (is_array($data)) { $arr = $data; }
            if (empty($arr)) break;
            foreach ($arr as $p) {
                $sym = trim((string)($p['coin'] ?? ''));
                if ($sym === '') continue;
                $poolsCoins[$sym] = [ 'id'=>null, 'symbol'=>$sym, 'name'=>$sym, 'algorithm'=>null ];
            }
            if (count($arr) < $perPage) break;
            $page++;
        }
        if (!empty($poolsCoins)) {
            $coins = array_values($poolsCoins);
            @file_put_contents($cacheFile, json_encode($coins));
        }
    }

    // Enrich with local mapping if available (fill names/algorithms and normalize symbols)
    if (!empty($coins)) {
        $mapFile = __DIR__ . '/coin_algos.json';
        $bySymbol = [];
        foreach ($coins as $c) {
            $sym = strtoupper(trim((string)($c['symbol'] ?? '')));
            if ($sym === '') continue;
            $bySymbol[$sym] = [
                'id' => $c['id'] ?? null,
                'symbol' => $sym,
                'name' => $c['name'] ?? $sym,
                'algorithm' => $c['algorithm'] ?? null,
            ];
        }
        if (is_file($mapFile)) {
            $map = json_decode(@file_get_contents($mapFile), true);
            if (is_array($map)) {
                foreach ($map as $sym => $info) {
                    $usym = strtoupper(trim((string)$sym));
                    if ($usym === '') continue;
                    if (!isset($bySymbol[$usym])) {
                        $bySymbol[$usym] = [ 'id'=>null, 'symbol'=>$usym, 'name'=>$info['name'] ?? $usym, 'algorithm'=>$info['algorithm'] ?? null ];
                    } else {
                        if (empty($bySymbol[$usym]['name']) && !empty($info['name'])) $bySymbol[$usym]['name'] = $info['name'];
                        if (empty($bySymbol[$usym]['algorithm']) && !empty($info['algorithm'])) $bySymbol[$usym]['algorithm'] = $info['algorithm'];
                    }
                }
            }
        }
        $coins = array_values($bySymbol);
        @file_put_contents($cacheFile, json_encode($coins));
    }

    if (empty($coins)) {
        if (is_file($cacheFile)) {
            $coins = json_decode(@file_get_contents($cacheFile), true);
        }
        if ($debug) {
            echo json_encode(['status'=>'error','message'=>'Remote fetch failed','http'=>$lastHttp,'curl_error'=>$lastErr,'raw'=>substr($lastRaw,0,400)]);
            exit;
        }
    }
}

if (!is_array($coins)) { $coins = []; }

if ($q !== '') {
    $qLower = mb_strtolower($q);
    $coins = array_values(array_filter($coins, function($c) use ($qLower) {
        return (strpos(mb_strtolower($c['symbol'] ?? ''), $qLower) !== false)
            || (strpos(mb_strtolower($c['name'] ?? ''), $qLower) !== false)
            || (strpos(mb_strtolower($c['algorithm'] ?? ''), $qLower) !== false);
    }));
}

echo json_encode([ 'status' => 'OK', 'data' => array_slice($coins, 0, $limit) ]);


