<?php

declare(strict_types=1);

/**
 * إدارة مخطط قاعدة البيانات:
 *   php scripts/migrate.php create-db   إنشاء قاعدة البيانات (utf8mb4) إذا لم تكن موجودة
 *   php scripts/migrate.php up          تطبيق Migrations المعلقة
 *   php scripts/migrate.php down [N]    التراجع عن آخر دفعة أو N خطوات
 *   php scripts/migrate.php status      عرض الإصدار الحالي والمعلق
 */

use TurathManager\Core\Database;
use TurathManager\Support\Migrator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$factory = require dirname(__DIR__) . '/backend/bootstrap.php';
$app = $factory();
$cmd = $argv[1] ?? 'status';

if ($cmd === 'create-db') {
    $name = $app->config['db']['name'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        fwrite(STDERR, "اسم قاعدة البيانات غير صالح\n");
        exit(1);
    }
    Database::connect($app->config['db'], false)
        ->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "تم التأكد من وجود قاعدة البيانات: $name\n";
    exit(0);
}

$migrator = new Migrator($app->db(), $app->config['paths']['migrations']);
switch ($cmd) {
    case 'up':
        $done = $migrator->up();
        echo $done ? "تم تطبيق:\n - " . implode("\n - ", $done) . "\n" : "لا توجد Migrations معلقة\n";
        break;
    case 'down':
        $done = $migrator->down((int) ($argv[2] ?? 0));
        echo $done ? "تم التراجع عن:\n - " . implode("\n - ", $done) . "\n" : "لا يوجد ما يمكن التراجع عنه\n";
        break;
    case 'status':
        $s = $migrator->status();
        echo 'الإصدار الحالي: ' . ($s['current'] ?? 'لا يوجد') . "\n";
        echo 'المعلق: ' . ($s['pending'] ? implode(', ', $s['pending']) : 'لا يوجد') . "\n";
        if ($s['modified']) {
            echo 'تحذير - ملفات معدلة بعد تطبيقها: ' . implode(', ', $s['modified']) . "\n";
            exit(2);
        }
        break;
    default:
        fwrite(STDERR, "أمر غير معروف: $cmd\n");
        exit(1);
}
