<?php
/**
 * Локальная разработка: из каталога vps-service выполнить:
 *   php -S localhost:8080 -t web web/router-dev.php
 * Тогда /css, /js, *.php — из web/, а /api/* — из ../api/.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($uri, '/api/') === 0) {
    $apiRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api';
    $rel = substr($uri, strlen('/api'));
    $file = $apiRoot . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (is_file($file) && substr($file, -4) === '.php') {
        require $file;
        return true;
    }
    require $apiRoot . DIRECTORY_SEPARATOR . 'index.php';
    return true;
}
return false;
