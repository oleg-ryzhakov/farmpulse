<?php
/**
 * Атомарная запись и блокировка config.json (фермы).
 * Снижает риск обрезанного файла при сбое во время записи и гонок read-modify-write.
 */

declare(strict_types=1);

/**
 * Флаги json_encode для конфига ферм.
 */
function hive_farms_config_json_flags(): int
{
    return JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;
}

/**
 * Читает конфиг под shared lock (короткое ожидание конкурирующей записи).
 *
 * @return array<string,mixed>|null null если файла нет, пусто или JSON невалиден
 */
function hive_farms_config_load(string $configPath): ?array
{
    if (!file_exists($configPath)) {
        return ['farms' => []];
    }
    if (!is_readable($configPath)) {
        return null;
    }

    $lockPath = $configPath . '.lock';
    $lockFp = @fopen($lockPath, 'c+');
    if (!$lockFp) {
        return hive_farms_config_load_nolock($configPath);
    }

    if (!flock($lockFp, LOCK_SH)) {
        fclose($lockFp);

        return hive_farms_config_load_nolock($configPath);
    }

    try {
        return hive_farms_config_load_nolock($configPath);
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}

/**
 * @return array<string,mixed>|null
 */
function hive_farms_config_load_nolock(string $configPath): ?array
{
    $raw = @file_get_contents($configPath);
    if ($raw === false) {
        return null;
    }
    if ($raw === '') {
        return ['farms' => []];
    }

    $config = json_decode($raw, true);
    if (!is_array($config)) {
        error_log('hive_farms_config: invalid JSON in ' . $configPath . ': ' . json_last_error_msg());

        return null;
    }

    return $config;
}

/**
 * Записывает конфиг: exclusive lock + временный файл + rename (атомарная подмена на той же ФС).
 */
function hive_farms_config_save(string $configPath, array $config): bool
{
    $json = json_encode($config, hive_farms_config_json_flags());
    if ($json === false) {
        error_log('hive_farms_config: json_encode failed for ' . $configPath);

        return false;
    }

    $lockPath = $configPath . '.lock';
    $lockFp = @fopen($lockPath, 'c+');
    if (!$lockFp || !flock($lockFp, LOCK_EX)) {
        if ($lockFp) {
            fclose($lockFp);
        }

        return false;
    }

    try {
        return hive_farms_config_atomic_write($configPath, $json);
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}

/**
 * Полный цикл read → изменение в замыкании → save под одной exclusive-блокировкой.
 * При невалидном JSON не перезаписывает файл (не затирает данные пустым конфигом).
 *
 * @param callable(array<string,mixed>):bool $mutator возвращает false — не сохранять (без ошибки)
 * @return true|non-empty-string true при успешной записи; строка-код при ошибке: lock|corrupt|encode|write|skip
 */
function hive_farms_config_transaction(string $configPath, callable $mutator)
{
    $lockPath = $configPath . '.lock';
    $lockFp = @fopen($lockPath, 'c+');
    if (!$lockFp || !flock($lockFp, LOCK_EX)) {
        if ($lockFp) {
            fclose($lockFp);
        }

        return 'lock';
    }

    try {
        if (!file_exists($configPath)) {
            $config = ['farms' => []];
        } else {
            $raw = @file_get_contents($configPath);
            if ($raw === false) {
                return 'read';
            }
            if ($raw === '') {
                $config = ['farms' => []];
            } else {
                $config = json_decode($raw, true);
                if (!is_array($config)) {
                    error_log('hive_farms_config: transaction aborted, invalid JSON in ' . $configPath . ': ' . json_last_error_msg());

                    return 'corrupt';
                }
            }
        }
        if (!isset($config['farms']) || !is_array($config['farms'])) {
            $config['farms'] = [];
        }

        $m = $mutator($config);
        if ($m === false) {
            return 'skip';
        }

        $out = json_encode($config, hive_farms_config_json_flags());
        if ($out === false) {
            error_log('hive_farms_config: json_encode failed in transaction for ' . $configPath);

            return 'encode';
        }

        return hive_farms_config_atomic_write($configPath, $out) ? true : 'write';
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}

/**
 * @internal вызывать только при удерживаемой LOCK_EX на .lock
 */
function hive_farms_config_atomic_write(string $configPath, string $json): bool
{
    $dir = dirname($configPath);
    $tmp = $dir . DIRECTORY_SEPARATOR . '.farms_config_' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);

        return false;
    }

    if (file_exists($configPath)) {
        $mode = fileperms($configPath) & 07777;
        if ($mode) {
            @chmod($tmp, $mode);
        }
        $owner = fileowner($configPath);
        $group = filegroup($configPath);
        if ($owner !== false && function_exists('chown')) {
            @chown($tmp, $owner);
        }
        if ($group !== false && function_exists('chgrp')) {
            @chgrp($tmp, $group);
        }
    }

    if (!rename($tmp, $configPath)) {
        @unlink($tmp);

        return false;
    }

    return true;
}
