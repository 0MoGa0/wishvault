<?php

declare(strict_types=1);

/*
 * Front controller.
 * /api/v1/*   → JSON API
 * /custom.css → optional operator stylesheet from the data volume
 * everything else that is not a real file → web UI (SPA, index.html)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use WishVault\App;
use WishVault\Config;
use WishVault\Http\Request;

Config::loadDotEnv(dirname(__DIR__) . '/.env');
$config = Config::fromEnv();
$path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

if (str_starts_with($path, App::API_PREFIX . '/') || $path === App::API_PREFIX) {
    (new App($config))->handle(Request::fromGlobals())->send();
    return;
}

// PHP built-in dev server: let it serve existing static files itself.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

if ($path === '/custom.css') {
    $file = $config->dataDir . '/custom.css';
    if (!is_file($file)) {
        http_response_code(404);
        return;
    }
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    return;
}

$index = $config->webDir . '/index.html';
if (!is_file($index)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Web UI not built. Run: pnpm --dir web build\n";
    return;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
readfile($index);
