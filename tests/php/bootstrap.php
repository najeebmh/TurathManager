<?php

declare(strict_types=1);

use TurathManager\Core\Database;
use TurathManager\Support\Migrator;

/**
 * تهيئة بيئة الاختبار: قاعدة بيانات منفصلة (turath_test) لا علاقة لها بقاعدة الإنتاج،
 * تُعاد بناؤها بالكامل من Migrations في بداية كل تشغيل.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$GLOBALS['TM_FACTORY'] = require dirname(__DIR__, 2) . '/backend/bootstrap.php';

$testDb = getenv('TEST_DB_DATABASE') ?: 'turath_test';
if (!str_contains($testDb, 'test')) {
    fwrite(STDERR, "اسم قاعدة الاختبار يجب أن يحتوي على test\n");
    exit(1);
}
$GLOBALS['TM_TEST_DB'] = $testDb;
$GLOBALS['TM_UPLOADS'] = sys_get_temp_dir() . '/turath-test-uploads-' . getmypid();
@mkdir($GLOBALS['TM_UPLOADS'], 0777, true);

$cfg = (require dirname(__DIR__, 2) . '/backend/config/config.php')['db'];
$cfg['name'] = $testDb;
$server = Database::connect($cfg, false);
$server->exec("DROP DATABASE IF EXISTS `$testDb`");
$server->exec("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = Database::connect($cfg);
(new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->up();
