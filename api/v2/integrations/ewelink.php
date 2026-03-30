<?php
/**
 * Интеграция eWeLink: CoolKit (через веб или env), мастер-ключ (веб один раз → data/ewelink.key), аккаунт eWeLink.
 *
 * Node: api/ewelink-node/ (npm ci). Вход: OAuth2 (рекомендуется для типа OAuth2.0) или пароль (если разрешён /v2/user/login).
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/ewelink-storage.php';
require_once __DIR__ . '/ewelink-node-runner.php';

$method = $_SERVER['REQUEST_METHOD'];
$dataDir = ewelink_data_dir();
$statePath = ewelink_state_path();

function ewelink_read_state(): ?array
{
    global $statePath;
    if (!is_readable($statePath)) {
        return null;
    }
    $raw = file_get_contents($statePath);
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : null;
}

function ewelink_write_state(array $state): bool
{
    global $statePath, $dataDir;
    if (!is_dir($dataDir)) {
        if (!@mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
            return false;
        }
    }
    return file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function ewelink_status_payload(): array
{
    $state = ewelink_read_state();
    $credMeta = ewelink_read_credentials_meta();
    $envId = ewelink_getenv_str('EWELINK_APP_ID');
    $envSec = ewelink_getenv_str('EWELINK_APP_SECRET');
    $coolkit_from_env = ($envId !== '' && $envSec !== '');

    $keyFromEnv = ewelink_getenv_str('FARMPULSE_EWELINK_KEY');
    $encryption_key_configured = (strlen($keyFromEnv) >= 16) || ewelink_key_file_exists();
    $encryption_key_from_env = (strlen($keyFromEnv) >= 16);

    $app_id = '';
    if ($coolkit_from_env) {
        $app_id = $envId;
    } elseif ($credMeta['from_file']) {
        $app_id = $credMeta['app_id'];
    }

    $payload = [
        'connected' => (bool) ($state && !empty($state['meta'])),
        'encryption_key_configured' => $encryption_key_configured,
        'encryption_key_from_env' => $encryption_key_from_env,
        'encryption_key_from_file' => ewelink_key_file_exists(),
        'coolkit_from_env' => $coolkit_from_env,
        'app_id' => $app_id,
        'app_secret_configured' => $coolkit_from_env || $credMeta['has_app_secret'],
        'oauth_callback_url' => ewelink_oauth_callback_url(),
    ];
    if ($payload['connected']) {
        $payload['account_masked'] = $state['meta']['account_masked'] ?? '';
        $payload['region'] = $state['meta']['region'] ?? null;
        $payload['connected_at'] = $state['meta']['connected_at'] ?? null;
        $payload['auth_via'] = $state['meta']['auth_via'] ?? 'password';
    }
    return $payload;
}

function ewelink_run_node_login(array $input): array
{
    return ewelink_run_node_script('login.mjs', [], json_encode($input, JSON_UNESCAPED_UNICODE));
}

function ewelink_handle_oauth_start(): void
{
    if (!ewelink_resolve_coolkit_credentials()) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Сохраните APP ID и APP SECRET CoolKit в блоке выше.']);
        return;
    }
    if (ewelink_key_material() === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Сначала задайте секретный ключ шифрования (мастер-ключ).']);
        return;
    }
    $redirectUrl = ewelink_oauth_callback_url();
    $state = bin2hex(random_bytes(16));
    if (!ewelink_ensure_data_dir()) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Не удалось создать каталог data']);
        return;
    }
    $pending = [
        'state' => $state,
        'redirect_url' => $redirectUrl,
        'ts' => time(),
    ];
    if (file_put_contents(ewelink_oauth_pending_path(), json_encode($pending, JSON_UNESCAPED_UNICODE)) === false) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Не удалось сохранить состояние OAuth']);
        return;
    }
    $stdin = json_encode(['redirectUrl' => $redirectUrl, 'state' => $state], JSON_UNESCAPED_UNICODE);
    $r = ewelink_run_node_script('oauth.mjs', ['login-url'], $stdin);
    if (empty($r['ok']) || empty($r['url'])) {
        http_response_code(401);
        $msg = $r['msg'] ?? 'OAuth URL failed';
        echo json_encode(['status' => 'error', 'message' => is_string($msg) ? $msg : json_encode($r)]);
        return;
    }
    echo json_encode([
        'status' => 'OK',
        'url' => $r['url'],
        'oauth_callback' => $redirectUrl,
    ]);
}

function ewelink_handle_devices(): void
{
    $tok = ewelink_read_session_tokens();
    if (!$tok || ($tok['at'] ?? '') === '') {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Сначала подключите eWeLink.']);
        return;
    }
    $stdin = json_encode([
        'at' => $tok['at'],
        'region' => ($tok['region'] ?? '') !== '' ? $tok['region'] : 'eu',
    ], JSON_UNESCAPED_UNICODE);
    $r = ewelink_run_node_script('devices.mjs', [], $stdin);
    if (empty($r['ok'])) {
        http_response_code(502);
        $msg = $r['msg'] ?? 'devices';
        echo json_encode(['status' => 'error', 'message' => is_string($msg) ? $msg : json_encode($r)]);
        return;
    }
    echo json_encode([
        'status' => 'OK',
        'devices' => $r['devices'] ?? [],
        'familyId' => $r['familyId'] ?? null,
    ]);
}

function ewelink_handle_save_settings(array $body): void
{
    if (ewelink_getenv_str('EWELINK_APP_ID') !== '' && ewelink_getenv_str('EWELINK_APP_SECRET') !== '') {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => 'CoolKit задан в окружении сервера; отредактируйте переменные EWELINK_APP_* или удалите их для настройки из веба.',
        ]);
        return;
    }

    $encryptionKeyInput = isset($body['encryption_key']) ? trim((string) $body['encryption_key']) : '';
    $appIdInput = isset($body['app_id']) ? trim((string) $body['app_id']) : '';
    $appSecretInput = isset($body['app_secret']) ? (string) $body['app_secret'] : '';

    $hadKeyFile = ewelink_key_file_exists();
    $keyFromEnv = strlen(ewelink_getenv_str('FARMPULSE_EWELINK_KEY')) >= 16;

    if ($encryptionKeyInput !== '') {
        if ($keyFromEnv) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Мастер-ключ задан в окружении (FARMPULSE_EWELINK_KEY); поле веба не используется.']);
            return;
        }
        if ($hadKeyFile) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Секретный ключ уже сохранён; изменить или просмотреть его через интерфейс нельзя.']);
            return;
        }
        if (strlen($encryptionKeyInput) < 16) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Секретный ключ: не менее 16 символов.']);
            return;
        }
        if (!ewelink_ensure_data_dir()) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Не удалось создать каталог data']);
            return;
        }
        $kf = ewelink_key_file_path();
        if (file_put_contents($kf, $encryptionKeyInput) === false) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Не удалось записать ewelink.key']);
            return;
        }
        @chmod($kf, 0600);
    }

    $keyMaterial = ewelink_key_material();
    if ($keyMaterial === '') {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Сначала задайте секретный ключ шифрования (один раз) или переменную FARMPULSE_EWELINK_KEY на сервере.',
        ]);
        return;
    }

    if ($appIdInput === '' && $appSecretInput === '' && $encryptionKeyInput === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Нет данных для сохранения']);
        return;
    }

    if ($appIdInput !== '' || $appSecretInput !== '') {
        $meta = ewelink_read_credentials_meta();
        $j = ewelink_read_credentials_raw() ?: [];

        $newId = $appIdInput !== '' ? $appIdInput : ($j['app_id'] ?? '');
        if ($newId === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Укажите APP ID']);
            return;
        }
        $j['app_id'] = $newId;

        if ($appSecretInput !== '') {
            try {
                $j['app_secret_cipher'] = ewelink_encrypt_app_secret($appSecretInput, $keyMaterial);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                return;
            }
        } elseif (empty($j['app_secret_cipher'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'При первом сохранении укажите APP SECRET']);
            return;
        }

        if (!ewelink_ensure_data_dir()) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Не удалось создать каталог data']);
            return;
        }
        if (file_put_contents(
            ewelink_credentials_path(),
            json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ) === false) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Не удалось сохранить учётные данные CoolKit']);
            return;
        }
        @chmod(ewelink_credentials_path(), 0600);
    }

    echo json_encode(array_merge(ewelink_status_payload(), ['status' => 'OK']));
}

if ($method === 'GET') {
    if (($_GET['action'] ?? '') === 'oauth_start') {
        ewelink_handle_oauth_start();
        exit;
    }
    if (($_GET['action'] ?? '') === 'devices') {
        ewelink_handle_devices();
        exit;
    }
    echo json_encode(array_merge(ewelink_status_payload(), ['status' => 'OK']));
    exit;
}

if ($method === 'DELETE') {
    if (is_file($statePath)) {
        @unlink($statePath);
    }
    echo json_encode(array_merge(ewelink_status_payload(), ['connected' => false, 'status' => 'OK']));
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    if (($body['action'] ?? '') === 'save_settings') {
        ewelink_handle_save_settings($body);
        exit;
    }

    $account = isset($body['account']) ? trim((string) $body['account']) : '';
    $password = isset($body['password']) ? (string) $body['password'] : '';
    $areaCode = isset($body['area_code']) ? trim((string) $body['area_code']) : '+7';
    $regionHint = isset($body['region']) ? trim((string) $body['region']) : '';

    if ($account === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Укажите аккаунт и пароль eWeLink']);
        exit;
    }

    $keyMaterial = ewelink_key_material();
    if ($keyMaterial === '') {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Задайте секретный ключ шифрования во вкладке eWeLink или FARMPULSE_EWELINK_KEY / data/ewelink.key на сервере',
        ]);
        exit;
    }

    if (!ewelink_resolve_coolkit_credentials()) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Сначала сохраните APP ID и APP SECRET CoolKit в блоке настроек выше или через окружение сервера',
        ]);
        exit;
    }

    $nodeIn = [
        'account' => $account,
        'password' => $password,
        'area_code' => $areaCode,
    ];
    if ($regionHint !== '') {
        $nodeIn['region'] = $regionHint;
    }

    $result = ewelink_run_node_login($nodeIn);
    if (empty($result['ok'])) {
        http_response_code(401);
        $msg = $result['msg'] ?? ($result['error'] ?? 'Login failed');
        $msgStr = is_string($msg) ? $msg : json_encode($msg);
        $hint = (stripos($msgStr, 'path of request is not allowed') !== false)
            ? 'Для приложения типа OAuth2.0 вход по паролю недоступен — нажмите «Войти через eWeLink (OAuth)».'
            : null;
        echo json_encode([
            'status' => 'error',
            'message' => $msgStr,
            'code' => $result['error'] ?? null,
            'hint' => $hint,
        ]);
        exit;
    }

    try {
        $blob = ewelink_encrypt_secrets(
            [
                'at' => $result['at'] ?? '',
                'rt' => $result['rt'] ?? '',
                'apikey' => $result['apikey'] ?? '',
            ],
            $keyMaterial
        );
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }

    $meta = [
        'account_masked' => ewelink_mask_account($account),
        'region' => $result['region'] ?? null,
        'connected_at' => gmdate('Y-m-d H:i:s'),
        'auth_via' => 'password',
    ];

    $state = [
        'version' => 1,
        'meta' => $meta,
        'secrets' => $blob,
    ];

    if (!ewelink_write_state($state)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Не удалось сохранить настройки']);
        exit;
    }

    echo json_encode([
        'status' => 'OK',
        'connected' => true,
        'account_masked' => $meta['account_masked'],
        'region' => $meta['region'],
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
