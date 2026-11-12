<?php

declare(strict_types=1);

use TurathManager\Core\App;
use TurathManager\Core\Env;

/**
 * تهيئة التطبيق: التحميل التلقائي، ملف البيئة، المنطقة الزمنية الموحدة (UTC).
 */
$root = dirname(__DIR__);

if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'TurathManager\\';
        if (str_starts_with($class, $prefix)) {
            $file = $root . '/backend/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

Env::load($root . '/.env');
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

return static function (array $overrides = []): App {
    $config = require __DIR__ . '/config/config.php';
    $config = array_replace_recursive($config, $overrides);
    return new App($config);
};
