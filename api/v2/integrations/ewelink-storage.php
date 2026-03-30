<?php
/**
 * Хранение учётных данных eWeLink: метаданные в открытом виде, токены — в зашифрованном blob.
 */

function ewelink_data_dir(): string
{
    return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data';
}

function ewelink_ensure_data_dir(): bool
{
    $d = ewelink_data_dir();
    if (is_dir($d)) {
        return true;
    }
    return @mkdir($d, 0700, true) || is_dir($d);
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

/**
 * Расшифровать сохранённую сессию eWeLink (at/rt) из ewelink-state.json.
 *
 * @return array{at:string,rt:string,region:string}|null
 */
function ewelink_read_session_tokens(): ?array
{
    $p = ewelink_state_path();
    if (!is_readable($p)) {
        return null;
    }
    $j = json_decode((string) file_get_contents($p), true);
    if (!is_array($j) || empty($j['secrets'])) {
        return null;
    }
    $km = ewelink_key_material();
    if ($km === '') {
        return null;
    }
    try {
        $dec = ewelink_decrypt_secrets((string) $j['secrets'], $km);
    } catch (Throwable $e) {
        return null;
    }
    return [
        'at' => (string) ($dec['at'] ?? ''),
        'rt' => (string) ($dec['rt'] ?? ''),
        'region' => (string) ($j['meta']['region'] ?? 'eu'),
    ];
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

function ewelink_key_file_path(): string
{
    return ewelink_data_dir() . DIRECTORY_SEPARATOR . 'ewelink.key';
}

function ewelink_key_file_exists(): bool
{
    return is_readable(ewelink_key_file_path());
}

function ewelink_oauth_pending_path(): string
{
    return ewelink_data_dir() . DIRECTORY_SEPARATOR . 'ewelink-oauth-pending.json';
}

/**
 * URL callback OAuth — должен совпадать с Redirect URL в консоли CoolKit (часто /ewelink-oauth-callback.php).
 * Переопределение: EWELINK_OAUTH_REDIRECT_URL (полный URL без хвоста).
 */
function ewelink_oauth_callback_url(): string
{
    $override = ewelink_getenv_str('EWELINK_OAUTH_REDIRECT_URL');
    if ($override !== '') {
        return rtrim($override, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/ewelink-oauth-callback.php';
}

function ewelink_credentials_path(): string
{
    return ewelink_data_dir() . DIRECTORY_SEPARATOR . 'ewelink-credentials.json';
}

/** @return array{from_file:bool,app_id:string,has_app_secret:bool} */
function ewelink_read_credentials_meta(): array
{
    $p = ewelink_credentials_path();
    if (!is_readable($p)) {
        return ['from_file' => false, 'app_id' => '', 'has_app_secret' => false];
    }
    $j = json_decode((string) file_get_contents($p), true);
    if (!is_array($j)) {
        return ['from_file' => false, 'app_id' => '', 'has_app_secret' => false];
    }
    return [
        'from_file' => true,
        'app_id' => (string) ($j['app_id'] ?? ''),
        'has_app_secret' => !empty($j['app_secret_cipher']),
    ];
}

/** @return ?array<string,mixed> */
function ewelink_read_credentials_raw(): ?array
{
    $p = ewelink_credentials_path();
    if (!is_readable($p)) {
        return null;
    }
    $j = json_decode((string) file_get_contents($p), true);
    return is_array($j) ? $j : null;
}

function ewelink_derive_subkey(string $keyMaterial, string $context): string
{
    return hash('sha256', $keyMaterial . "\x1e" . $context, true);
}

function ewelink_encrypt_app_secret(string $plain, string $keyMaterial): string
{
    $key = ewelink_derive_subkey($keyMaterial, 'coolkit-app-secret-v1');
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed (app secret)');
    }
    return base64_encode($iv . $tag . $cipher);
}

function ewelink_decrypt_app_secret(string $blob, string $keyMaterial): string
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 28) {
        throw new RuntimeException('Invalid app secret blob');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $key = ewelink_derive_subkey($keyMaterial, 'coolkit-app-secret-v1');
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new RuntimeException('Decryption failed (app secret)');
    }
    return $plain;
}

/**
 * CoolKit APP ID + SECRET: сначала переменные окружения, иначе файл (секрет зашифрован мастер-ключом).
 *
 * @return array{0:string,1:string}|null
 */
function ewelink_resolve_coolkit_credentials(): ?array
{
    $id = ewelink_getenv_str('EWELINK_APP_ID');
    $sec = ewelink_getenv_str('EWELINK_APP_SECRET');
    if ($id !== '' && $sec !== '') {
        return [$id, $sec];
    }
    $meta = ewelink_read_credentials_meta();
    if (!$meta['from_file'] || $meta['app_id'] === '' || !$meta['has_app_secret']) {
        return null;
    }
    $km = ewelink_key_material();
    if ($km === '') {
        return null;
    }
    $j = ewelink_read_credentials_raw();
    if (!$j || empty($j['app_secret_cipher'])) {
        return null;
    }
    try {
        $sec2 = ewelink_decrypt_app_secret((string) $j['app_secret_cipher'], $km);
    } catch (Throwable $e) {
        return null;
    }
    return [$meta['app_id'], $sec2];
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
