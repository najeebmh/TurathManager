<?php

declare(strict_types=1);

// عملية مستقلة تحاول حجز آخر قطعة؛ تُشغل عدة نسخ متوازية من BookingTest
use TurathManager\Core\Request;

[$_, $db, $center, $product, $n] = $argv;
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$factory = require dirname(__DIR__, 2) . '/backend/bootstrap.php';
$app = $factory(['app' => ['env' => 'testing'], 'db' => ['name' => $db]]);
$admin = $app->db()->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.code = 'admin' LIMIT 1")->fetchColumn();
$user = $app->service(\TurathManager\Auth\AuthService::class)->loadUser($admin);
usleep(random_int(0, 2000));
$req = new Request('POST', '/bookings', [], [
    'center_id' => $center,
    'customer' => ['full_name' => 'عميل ' . $n, 'phone' => '77700000' . $n],
    'install_at' => (new DateTimeImmutable('+90 days'))->format('Y-m-d\T10:00:00P'),
    'removal_at' => (new DateTimeImmutable('+91 days'))->format('Y-m-d\T10:00:00P'),
    'items' => [['product_id' => $product, 'quantity' => 1]],
]);
try {
    $app->service(\TurathManager\Services\BookingService::class)->create($req->body, $user, $req);
    echo '201';
} catch (\TurathManager\Core\HttpException $e) {
    echo $e->status;
}
