<?php
/**
 * Запуск node-скриптов в api/ewelink-node/ (login.mjs, oauth.mjs).
 */
require_once __DIR__ . '/ewelink-storage.php';

function ewelink_ewelink_node_dir(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'ewelink-node';
}

/**
 * @return array<string,mixed> распарсенный JSON из stdout или массив с ok:false
 */
function ewelink_run_node_script(string $scriptFile, array $argvExtra, string $stdinJson): array
{
    $dir = ewelink_ewelink_node_dir();
    $scriptPath = $dir . DIRECTORY_SEPARATOR . $scriptFile;
    if (!is_readable($scriptPath)) {
        return ['ok' => false, 'error' => 'script', 'msg' => $scriptFile . ' not found'];
    }

    $creds = ewelink_resolve_coolkit_credentials();
    if (!$creds) {
        return ['ok' => false, 'error' => 'config', 'msg' => 'CoolKit credentials not configured'];
    }
    [$appId, $appSecret] = $creds;

    $node = ewelink_getenv_str('NODE_BINARY') ?: 'node';
    $args = array_map('escapeshellarg', $argvExtra);
    $cmd = $node . ' ' . escapeshellarg($scriptPath) . ($args ? ' ' . implode(' ', $args) : '');

    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open(
        $cmd,
        $descriptorspec,
        $pipes,
        $dir,
        ewelink_child_env_for_node($appId, $appSecret),
        []
    );

    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => 'proc', 'msg' => 'Could not start node'];
    }

    fwrite($pipes[0], $stdinJson);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code !== 0) {
        $err = json_decode(trim($stderr) ?: '{}', true);
        if (is_array($err) && isset($err['ok']) && $err['ok'] === false) {
            return $err;
        }
        return [
            'ok' => false,
            'error' => 'node',
            'msg' => $stderr ?: ('exit ' . $code),
        ];
    }

    $out = json_decode(trim($stdout) ?: '{}', true);
    return is_array($out) ? $out : ['ok' => false, 'error' => 'parse', 'msg' => 'Invalid node output'];
}

function ewelink_child_env_for_node(string $appId, string $appSecret): array
{
    $env = [];
    $path = ewelink_getenv_str('PATH');
    if ($path === '') {
        $path = ewelink_getenv_str('Path');
    }
    if ($path === '') {
        $path = $_SERVER['PATH'] ?? $_SERVER['Path'] ?? '';
    }
    if ($path !== '') {
        $env['PATH'] = $path;
    }
    foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'PATHEXT', 'TEMP', 'TMP', 'USERPROFILE', 'HOME'] as $k) {
        $v = ewelink_getenv_str($k);
        if ($v === '' && isset($_SERVER[$k])) {
            $v = (string) $_SERVER[$k];
        }
        if ($v !== '') {
            $env[$k] = $v;
        }
    }
    $env['EWELINK_APP_ID'] = $appId;
    $env['EWELINK_APP_SECRET'] = $appSecret;
    return $env;
}
