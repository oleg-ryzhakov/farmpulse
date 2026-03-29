<?php
/**
 * Синхронизация снимка фермы в Python app-api (память + WebSocket).
 * Вызывается из api.php после записи config.json.
 */

/**
 * @param array<string,mixed> $config Полный config.json
 */
function farmpulse_app_api_push(array $config, string $farmId): void
{
    $syncFile = __DIR__ . '/../v2/farms/app_api_sync.json';
    if (!is_file($syncFile)) {
        return;
    }
    $raw = @file_get_contents($syncFile);
    if ($raw === false || $raw === '') {
        return;
    }
    $sync = json_decode($raw, true);
    if (!is_array($sync) || empty($sync['enabled'])) {
        return;
    }
    $base = rtrim((string)($sync['base_url'] ?? ''), '/');
    $key = (string)($sync['api_key'] ?? '');
    if ($base === '' || $key === '') {
        return;
    }
    if (!isset($config['farms'][$farmId]) || !is_array($config['farms'][$farmId])) {
        return;
    }
    $f = $config['farms'][$farmId];
    $temps = [];
    if (isset($f['gpu_temps']) && is_array($f['gpu_temps'])) {
        foreach ($f['gpu_temps'] as $t) {
            if (is_numeric($t)) {
                $temps[] = (float) $t;
            }
        }
    }
    $payload = [
        'farm_id' => (string) $farmId,
        'name' => $f['name'] ?? null,
        'status' => isset($f['status']) ? (string) $f['status'] : 'online',
        'gpu_temps' => $temps,
        'gpu_count' => isset($f['gpu_count']) ? (int) $f['gpu_count'] : count($temps),
        'total_khs' => isset($f['total_khs']) && is_numeric($f['total_khs']) ? (float) $f['total_khs'] : null,
        'total_power_w' => isset($f['total_power_w']) && is_numeric($f['total_power_w']) ? (float) $f['total_power_w'] : null,
        'last_stats_at' => $f['last_stats_at'] ?? null,
        'rig_info' => isset($f['rig_info']) && is_array($f['rig_info']) ? $f['rig_info'] : null,
    ];
    $url = $base . '/internal/heartbeat';
    $body = json_encode($payload);
    if ($body === false) {
        return;
    }
    $headers = [
        'Content-Type: application/json',
        'X-Api-Key: ' . $key,
    ];
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 3,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents($url, false, $ctx);
}
