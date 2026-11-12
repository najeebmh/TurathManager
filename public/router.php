<?php

declare(strict_types=1);

/**
 * موجه خادم PHP المدمج للتطوير والاختبار فقط:
 *   php -S 127.0.0.1:8080 public/router.php
 * يوجه /api/* إلى index.php ويقدم ملفات الواجهة من frontend/.
 */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    require __DIR__ . '/index.php';
    return true;
}
$frontend = realpath(dirname(__DIR__) . '/frontend');
$path = $uri === '/' ? '/index.html' : $uri;
$file = realpath($frontend . $path);
if ($file === false || !str_starts_with($file, $frontend) || !is_file($file)) {
    $file = $frontend . '/index.html';
}
$types = [
    'html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8',
    'json' => 'application/json', 'webmanifest' => 'application/manifest+json', 'png' => 'image/png', 'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'map' => 'application/json',
];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
if (basename($file) === 'sw.js') {
    header('Cache-Control: no-cache');
}
readfile($file);
return true;
