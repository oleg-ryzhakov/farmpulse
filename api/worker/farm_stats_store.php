<?php
/**
 * Сохранение расширенной телеметрии рига в записи фермы (config.json).
 */

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function hive_worker_strip_sensitive(array $params): array
{
    $out = $params;
    unset($out['passwd'], $out['password']);

    return $out;
}

/**
 * Сумма мощности по GPU (массив как у Hive: [0] — заглушка, далее ваты).
 *
 * @param array<int|string,mixed>|mixed $power
 */
function hive_worker_sum_gpu_power($power): ?float
{
    if (!is_array($power)) {
        return null;
    }
    $slice = array_slice(array_values($power), 1);
    $sum = 0.0;
    $n = 0;
    foreach ($slice as $w) {
        if (is_numeric($w)) {
            $sum += (float) $w;
            $n++;
        }
    }

    return $n > 0 ? $sum : null;
}

/**
 * Собирает total_khs* из params (несколько майнеров).
 *
 * @param array<string,mixed> $params
 * @return array<string,float>
 */
function hive_worker_collect_hashrates(array $params): array
{
    $h = [];
    foreach ($params as $k => $v) {
        if (preg_match('/^total_khs\d*$/', (string) $k) && is_numeric($v)) {
            $h[(string) $k] = (float) $v;
        }
    }

    return $h;
}

/**
 * @param array<string,mixed> $farm ссылка на $config['farms'][$id]
 * @param array<string,mixed> $rpcParams содержимое JSON params из stats
 */
function hive_worker_merge_stats_into_farm(array &$farm, array $rpcParams): void
{
    $sanitized = hive_worker_strip_sensitive($rpcParams);
    $farm['last_stats'] = $sanitized;
    $farm['last_stats_at'] = gmdate('Y-m-d H:i:s');

    if (isset($rpcParams['last_cmd_id'])) {
        $farm['last_cmd_ack'] = $rpcParams['last_cmd_id'];
    }

    if (isset($rpcParams['temp']) && is_array($rpcParams['temp'])) {
        $raw = $rpcParams['temp'];
        $temps = array_slice($raw, 1);
        $farm['gpu_temps'] = array_values(array_filter($temps, static function ($v) {
            return is_numeric($v) && (int) $v > 0;
        }));
        $farm['gpu_count'] = count($farm['gpu_temps']);
    }

    $hashrates = hive_worker_collect_hashrates($rpcParams);
    if ($hashrates !== []) {
        $farm['hashrates'] = $hashrates;
        $farm['total_khs'] = $hashrates['total_khs'] ?? (float) reset($hashrates);
    } else {
        $farm['total_khs'] = isset($rpcParams['total_khs']) && is_numeric($rpcParams['total_khs'])
            ? (float) $rpcParams['total_khs'] : ($farm['total_khs'] ?? null);
    }

    $pw = hive_worker_sum_gpu_power($rpcParams['power'] ?? null);
    if ($pw !== null) {
        $farm['total_power_w'] = $pw;
    }
}

/**
 * Краткая информация с hello (без секретов).
 *
 * @param array<string,mixed> $farm
 * @param array<string,mixed> $rpcParams
 */
function hive_worker_merge_hello_into_farm(array &$farm, array $rpcParams): void
{
    $p = hive_worker_strip_sensitive($rpcParams);
    $farm['rig_info'] = [
        'hello_at' => gmdate('Y-m-d H:i:s'),
        'uid' => $rpcParams['uid'] ?? null,
        'ip' => $rpcParams['ip'] ?? null,
        'gpu' => $rpcParams['gpu'] ?? null,
        'gpu_count_amd' => $rpcParams['gpu_count_amd'] ?? null,
        'gpu_count_nvidia' => $rpcParams['gpu_count_nvidia'] ?? null,
        'gpu_count_intel' => $rpcParams['gpu_count_intel'] ?? null,
        'mb' => $rpcParams['mb'] ?? null,
        'cpu' => $rpcParams['cpu'] ?? null,
        'disk_model' => $rpcParams['disk_model'] ?? null,
        'image_version' => $rpcParams['image_version'] ?? null,
        'kernel' => $rpcParams['kernel'] ?? null,
        'version' => $rpcParams['version'] ?? null,
        'lan_config' => $rpcParams['lan_config'] ?? null,
        'net_interfaces' => $rpcParams['net_interfaces'] ?? null,
        'server_url' => $rpcParams['server_url'] ?? null,
        'ref_id' => $rpcParams['ref_id'] ?? null,
        'nvidia_version' => $rpcParams['nvidia_version'] ?? null,
        'amd_version' => $rpcParams['amd_version'] ?? null,
        'intel_version' => $rpcParams['intel_version'] ?? null,
    ];
    // Полный сырой hello (без пароля) для отладки / будущего UI — ограничим размер
    $farm['hello_params'] = $p;
}
