<?php
/**
 * Базовый URL API для фронта (путь на том же хосте, без хвостового слэша).
 * Переопределение: переменная окружения WEB_API_BASE или константа до подключения файла.
 */
if (!defined('WEB_API_BASE')) {
    $env = getenv('WEB_API_BASE');
    define('WEB_API_BASE', ($env !== false && $env !== '') ? rtrim($env, '/') : '/api');
}
