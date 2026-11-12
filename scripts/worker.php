<?php

declare(strict_types=1);

/**
 * مشغل المهام الخلفية المجدولة (التذكيرات، طابور الإرسال، التنظيف).
 * لا يعتمد على فتح المتصفح. شغّله كل دقيقة:
 *   Linux (cron):  * * * * * php /path/to/TurathManager/scripts/worker.php
 *   Windows:       Task Scheduler -> C:\xampp\php\php.exe C:\xampp\htdocs\TurathManager\scripts\worker.php (كل دقيقة)
 * أو تشغيل مستمر:  php scripts/worker.php --loop
 */

use TurathManager\Services\SchedulerService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$factory = require dirname(__DIR__) . '/backend/bootstrap.php';
$loop = in_array('--loop', $argv, true);
do {
    $app = $factory();
    $result = $app->service(SchedulerService::class)->runAll();
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if ($loop) {
        sleep(60);
    }
} while ($loop);
