<?php

declare(strict_types=1);

use TurathManager\Core\Request;
use TurathManager\Core\Response;

/**
 * نقطة الدخول الوحيدة لواجهة REST API.
 */
try {
    $factory = require dirname(__DIR__) . '/backend/bootstrap.php';
    $app = $factory();
    $app->handle(Request::fromGlobals())->send();
} catch (\Throwable $e) {
    error_log('[TurathManager] ' . get_class($e));
    Response::json(['error' => ['code' => 'server_error', 'message' => 'حدث خطأ داخلي في الخادم']], 500)->send();
}
