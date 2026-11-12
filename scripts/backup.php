<?php

declare(strict_types=1);

/**
 * إنشاء نسخة احتياطية:            php scripts/backup.php
 * اختبار الاستعادة إلى قاعدة منفصلة: php scripts/backup.php --verify
 *   (ينشئ نسخة، يستعيدها إلى قاعدة مؤقتة، يقارن عدد الصفوف لكل جدول، ثم يحذف القاعدة المؤقتة)
 */

use TurathManager\Core\Database;
use TurathManager\Services\BackupService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$factory = require dirname(__DIR__) . '/backend/bootstrap.php';
$app = $factory();
$svc = $app->service(BackupService::class);
$b = $svc->create();
echo "تم إنشاء النسخة: {$b['file_name']} ({$b['size_bytes']} بايت)\nSHA-256: {$b['checksum_sha256']}\n";

if (in_array('--verify', $argv, true)) {
    $tmp = $app->config['db']['name'] . '_restore_check';
    $r = $svc->restoreInto($b['id'], $tmp);
    $src = $app->db();
    $dst = Database::connect(['name' => $tmp] + $app->config['db']);
    $mismatch = [];
    foreach ($src->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$t]) {
        if ($t === 'backups') {
            continue;
        }
        $a = (int) $src->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $c = (int) $dst->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        if ($a !== $c) {
            $mismatch[] = "$t: $a != $c";
        }
    }
    $dst->exec("DROP DATABASE `$tmp`");
    if ($mismatch) {
        fwrite(STDERR, "فشل التحقق من الاستعادة:\n" . implode("\n", $mismatch) . "\n");
        exit(1);
    }
    echo "تم التحقق من الاستعادة بنجاح: {$r['tables']} جدول، {$r['statements']} جملة SQL، وتطابق عدد الصفوف في جميع الجداول\n";
}
