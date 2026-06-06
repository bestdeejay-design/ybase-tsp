<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use YellParser\Database;
use YellParser\Repository;

$db = Database::getInstance();
$repo = new Repository($db);

// Простой роутер
$path = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($path, PHP_URL_PATH);
$path = trim($path, '/');

// Определяем маршрут
if ($path === '' || $path === 'index.php') {
    // Главная - объединённая страница
    require __DIR__ . '/../templates/home.php';
} elseif ($path === 'search') {
    // Страница поиска
    require __DIR__ . '/../templates/search.php';
} elseif (preg_match('/^tag\/(.+)$/', $path, $matches)) {
    // Фильтр по тегу
    $_GET['tag'] = $matches[1];
    require __DIR__ . '/../templates/search.php';
} elseif ($path === 'catalog' || $path === 'catalog.php') {
    // Новый каталог в стиле LOVII
    require __DIR__ . '/../templates/catalog-lovii.php';
} elseif (preg_match('/^city\/(\d+)$/', $path, $matches)) {
    // Каталог заведений города (редирект на новый)
    header('Location: /catalog?city=' . $matches[1]);
    exit;
} elseif (preg_match('/^company\/(\d+)$/', $path, $matches)) {
    // Детальная страница
    $_GET['id'] = $matches[1];
    require __DIR__ . '/../templates/detail.php';
} else {
    http_response_code(404);
    echo '<h1>404 - Not Found</h1>';
}
