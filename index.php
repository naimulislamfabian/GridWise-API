<?php
declare(strict_types=1);

require __DIR__ . '/src/autoload.php';

use GridWise\App;
use GridWise\Config;

Config::loadEnv();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}
if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
    $requestPath = substr($requestPath, strlen($basePath));
    if ($requestPath === '' || $requestPath === false) {
        $requestPath = '/';
    }
}

$raw = file_get_contents('php://input');
[$status, $body] = App::handle($method, $requestPath, $raw === false ? '' : $raw);
http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
