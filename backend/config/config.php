<?php

declare(strict_types=1);

use TurathManager\Core\Env;

/**
 * إعدادات التطبيق. جميع الأسرار تُقرأ من ملف .env غير المتتبع في Git.
 */
return [
    'app' => [
        'name' => Env::get('APP_NAME', 'Turath Manager'),
        'env' => Env::get('APP_ENV', 'production'),
        'debug' => Env::bool('APP_DEBUG', false),
        'url' => Env::get('APP_URL', 'http://localhost/TurathManager'),
        'key' => Env::get('APP_KEY', ''),
        'timezone' => Env::get('APP_TIMEZONE', 'Asia/Aden'),
        'default_country_code' => Env::get('DEFAULT_COUNTRY_CODE', '967'),
        'token_ttl_hours' => (int) Env::get('TOKEN_TTL_HOURS', '720'),
        'cors_origins' => array_filter(array_map('trim', explode(',', (string) Env::get(
            'CORS_ALLOWED_ORIGINS',
            'http://localhost,https://localhost,capacitor://localhost,tauri://localhost,http://tauri.localhost,https://tauri.localhost'
        )))),
    ],
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_DATABASE', 'turath_manager'),
        'user' => Env::get('DB_USERNAME', 'root'),
        'pass' => Env::get('DB_PASSWORD', ''),
        'socket' => Env::get('DB_SOCKET', ''),
    ],
    'paths' => [
        'root' => dirname(__DIR__, 2),
        'storage' => dirname(__DIR__, 2) . '/storage',
        'uploads' => dirname(__DIR__, 2) . '/storage/uploads',
        'logs' => dirname(__DIR__, 2) . '/storage/logs',
        'backups' => dirname(__DIR__, 2) . '/storage/backups',
        'migrations' => dirname(__DIR__, 2) . '/database/migrations',
    ],
    'uploads' => [
        'max_bytes' => (int) Env::get('UPLOAD_MAX_BYTES', (string) (5 * 1024 * 1024)),
        'allowed_mime' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
    ],
    'whatsapp' => [
        'graph_url' => Env::get('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => Env::get('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'access_token' => Env::get('WHATSAPP_ACCESS_TOKEN', ''),
        'app_secret' => Env::get('WHATSAPP_APP_SECRET', ''),
        'verify_token' => Env::get('WHATSAPP_VERIFY_TOKEN', ''),
    ],
    'fcm' => [
        'project_id' => Env::get('FCM_PROJECT_ID', ''),
        'service_account_file' => Env::get('FCM_SERVICE_ACCOUNT_FILE', ''),
    ],
];
