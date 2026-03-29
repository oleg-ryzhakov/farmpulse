<?php
/**
 * Хранение учётных данных eWeLink: метаданные в открытом виде, токены — в зашифрованном blob.
 */

function ewelink_data_dir(): string
{
    return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data';
}

function ewelink_state_path(): string
{
    return ewelink_data_dir() . DIRECTORY_SEPARATOR . 'ewelink-state.json';
}

function ewelink_getenv_str(string $name): string
{
    $v = getenv($name);
    if ($v !== false && $v !== '') {
        return $v;
    }
    if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && $_SERVER[$name] !== '') {
        return $_SERVER[$name];
    }
    return '';
}

function ewelink_key_material(): string
{
    $env = ewelink_getenv_str('FARMPULSE_EWELINK_KEY');
    if (strlen($env) >= 16) {
        return $env;
    }
    $keyFile = ewelink_data_dir() . DIRECTORY_SEPARATOR . 'ewelink.key';
    if (is_readable($keyFile)) {
        return trim((string) file_get_contents($keyFile));
    }
    return '';
}

function ewelink_derive_key(string $material): string
{
    return hash('sha256', $material, true);
}

function ewelink_encrypt_secrets(array $plain, string $keyMaterial): string
{
    $key = ewelink_derive_key($keyMaterial);
    $iv = random_bytes(12);
    $tag = '';
    $json = json_encode($plain, JSON_UNESCAPED_UNICODE);
    $cipher = openssl_encrypt($json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed');
    }
    return base64_encode($iv . $tag . $cipher);
}

function ewelink_decrypt_secrets(string $blob, string $keyMaterial): array
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 28) {
        throw new RuntimeException('Invalid blob');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $key = ewelink_derive_key($keyMaterial);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new RuntimeException('Decryption failed');
    }
    $data = json_decode($plain, true);
    return is_array($data) ? $data : [];
}

function ewelink_mask_account(string $account): string
{
    $account = trim($account);
    if ($account === '') {
        return '';
    }
    if (strpos($account, '@') !== false) {
        [$local, $domain] = explode('@', $account, 2);
        $keep = max(1, min(2, strlen($local)));
        return substr($local, 0, $keep) . '***@' . $domain;
    }
    if (strlen($account) <= 4) {
        return '***';
    }
    return substr($account, 0, 3) . '***' . substr($account, -2);
}
