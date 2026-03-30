<?php
/**
 * OAuth redirect от CoolKit: ?code=&state= — обмен code на токены и сохранение.
 * Redirect URL в консоли разработчика должен совпадать с этим файлом (полный URL).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/v2/integrations/ewelink-storage.php';
require_once dirname(__DIR__) . '/api/v2/integrations/ewelink-node-runner.php';

function ewelink_oauth_redirect(string $query): void
{
    header('Location: /index.php' . $query, true, 302);
    exit;
}

$code = isset($_GET['code']) ? (string) $_GET['code'] : '';
$state = isset($_GET['state']) ? (string) $_GET['state'] : '';
$err = isset($_GET['error']) ? (string) $_GET['error'] : '';

if ($err !== '') {
    ewelink_oauth_redirect('?ewelink_oauth_err=' . rawurlencode($err));
}

if ($code === '' || $state === '') {
    ewelink_oauth_redirect('?ewelink_oauth_err=' . rawurlencode('missing_params'));
}

$pendingPath = ewelink_oauth_pending_path();
$raw = @file_get_contents($pendingPath);
$pending = json_decode($raw ?: '', true);
if (!is_array($pending) || ($pending['state'] ?? '') !== $state) {
    ewelink_oauth_redirect('?ewelink_oauth_err=' . rawurlencode('bad_state'));
}
if (time() - (int) ($pending['ts'] ?? 0) > 600) {
    @unlink($pendingPath);
    ewelink_oauth_redirect('?ewelink_oauth_err=' . rawurlencode('expired'));
}

$redirectUrl = (string) ($pending['redirect_url'] ?? '');
if ($redirectUrl === '') {
    $redirectUrl = ewelink_oauth_callback_url();
}

$keyMaterial = ewelink_key_material();
if ($keyMaterial === '') {
    @unlink($pendingPath);
    ewelink_oauth_redirect('?ewelink_oauth_err=' . rawurlencode('no_encryption_key'));
}

$payload = json_encode(['redirectUrl' => $redirectUrl, 'code' => $code], JSON_UNESCAPED_UNICODE);
$result = ewelink_run_node_script('oauth.mjs', ['token'], $payload);
@unlink($pendingPath);

if (empty($result['ok'])) {
    $m = $result['msg'] ?? json_encode($result);
    ewelink_oauth_redirect('?ewelink_oauth_err=' . rawurlencode(is_string($m) ? $m : 'token_failed'));
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
    ewelink_oauth_redirect('?ewelink_oauth_err=' . rawurlencode($e->getMessage()));
}

$accLabel = 'OAuth';
if (!empty($result['email']) && is_string($result['email'])) {
    $accLabel = ewelink_mask_account($result['email']);
}

$meta = [
    'account_masked' => $accLabel,
    'region' => $result['region'] ?? null,
    'connected_at' => gmdate('Y-m-d H:i:s'),
    'auth_via' => 'oauth',
];

$stateData = [
    'version' => 1,
    'meta' => $meta,
    'secrets' => $blob,
];

if (!ewelink_ensure_data_dir()) {
    ewelink_oauth_redirect('?ewelink_oauth_err=' . rawurlencode('data_dir'));
}

$stateFile = ewelink_state_path();
if (file_put_contents($stateFile, json_encode($stateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    ewelink_oauth_redirect('?ewelink_oauth_err=' . rawurlencode('save_failed'));
}

ewelink_oauth_redirect('?ewelink_oauth_ok=1');
