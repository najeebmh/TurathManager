<?php

declare(strict_types=1);

/**
 * استعادة نسخة احتياطية (عملية مدمرة - تستبدل بيانات القاعدة الهدف):
 *   php scripts/restore.php --file=storage/backups/turath-XXXX.sql.gz --database=turath_manager --yes
 * يُنصح بأخذ نسخة جديدة قبل الاستعادة.
 */

use TurathManager\Core\Database;
use TurathManager\Services\BackupService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$factory = require dirname(__DIR__) . '/backend/bootstrap.php';
$app = $factory();
$opts = getopt('', ['file:', 'database:', 'yes']);
$file = $opts['file'] ?? '';
$db = $opts['database'] ?? $app->config['db']['name'];
if (!is_file($file) || !preg_match('/^[A-Za-z0-9_]+$/', $db)) {
    fwrite(STDERR, "استخدم: --file=<ملف النسخة> --database=<اسم القاعدة> --yes\n");
    exit(1);
}
if (!isset($opts['yes'])) {
    fwrite(STDERR, "هذه عملية مدمرة. أضف --yes للتأكيد\n");
    exit(1);
}
Database::connect($app->config['db'], false)->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$r = BackupService::restoreFile($file, Database::connect(['name' => $db] + $app->config['db']));
echo "تمت الاستعادة إلى $db: {$r['tables']} جدول، {$r['statements']} جملة\n";
