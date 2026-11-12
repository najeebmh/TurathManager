<?php

declare(strict_types=1);

/**
 * إنشاء أول حساب مدير نظام بشكل آمن (من سطر الأوامر فقط).
 *
 * تفاعلي:      php scripts/create-admin.php
 * غير تفاعلي:  echo "كلمة_المرور" | php scripts/create-admin.php --username=admin --full-name="مدير النظام" --password-stdin
 *
 * لا توجد حسابات أو كلمات مرور افتراضية في النظام. كلمة المرور لا تُمرر أبداً كوسيط في سطر الأوامر.
 * يرفض السكربت إنشاء مدير إضافي إذا وُجد مدير نشط إلا مع الخيار --additional.
 */

use TurathManager\Auth\AuthService;
use TurathManager\Core\Uuid;
use TurathManager\Core\Validator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$factory = require dirname(__DIR__) . '/backend/bootstrap.php';
$app = $factory();
$db = $app->db();

$opts = getopt('', ['username:', 'full-name:', 'email:', 'phone:', 'password-stdin', 'additional']);
$existing = (int) $db->query("SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.code = 'admin' AND u.deleted_at IS NULL")->fetchColumn();
if ($existing > 0 && !isset($opts['additional'])) {
    fwrite(STDERR, "يوجد مدير نظام بالفعل. لإضافة مدير آخر استخدم لوحة التحكم أو الخيار --additional\n");
    exit(1);
}

$ask = static function (string $label): string {
    echo $label . ': ';
    return trim((string) fgets(STDIN));
};
$askHidden = static function (string $label): string {
    echo $label . ': ';
    $isTty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($isTty && DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty -echo');
        $v = trim((string) fgets(STDIN));
        shell_exec('stty echo');
        echo PHP_EOL;
        return $v;
    }
    return trim((string) fgets(STDIN));
};

$username = mb_strtolower($opts['username'] ?? $ask('اسم المستخدم (إنجليزي)'));
$fullName = $opts['full-name'] ?? $ask('الاسم الكامل');
$email = $opts['email'] ?? null;
$phone = $opts['phone'] ?? null;
if (isset($opts['password-stdin'])) {
    $password = trim((string) fgets(STDIN));
} else {
    $password = $askHidden('كلمة المرور');
    if ($askHidden('تأكيد كلمة المرور') !== $password) {
        fwrite(STDERR, "كلمتا المرور غير متطابقتين\n");
        exit(1);
    }
}

try {
    if (!preg_match('/^[a-z0-9._\-]{3,60}$/', $username)) {
        throw new RuntimeException('اسم المستخدم غير صالح');
    }
    if (mb_strlen($fullName) < 2) {
        throw new RuntimeException('الاسم الكامل مطلوب');
    }
    AuthService::assertStrongPassword($password, 'password');
    if ($phone !== null && ($phone = Validator::normalizePhone($phone, $app->config['app']['default_country_code'])) === null) {
        throw new RuntimeException('رقم الهاتف غير صالح');
    }
    $st = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $st->execute([$username]);
    if ((int) $st->fetchColumn() > 0) {
        throw new RuntimeException('اسم المستخدم مستخدم مسبقاً');
    }
    $id = Uuid::v4();
    $now = $app->clock->nowDb();
    $db->beginTransaction();
    $db->prepare('INSERT INTO users (id, full_name, username, email, phone, password_hash, is_active, must_change_password, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, ?)')
        ->execute([$id, $fullName, $username, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $now, $now]);
    $db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = 'admin'")->execute([$id]);
    $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([null, 'user.admin_created_cli', 'user', $id, json_encode(['username' => $username]), $now]);
    $db->commit();
    echo "تم إنشاء مدير النظام: $username\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $msg = $e instanceof \TurathManager\Core\HttpException ? implode(' ', $e->details['errors'] ?? [$e->getMessage()]) : $e->getMessage();
    fwrite(STDERR, 'خطأ: ' . $msg . "\n");
    exit(1);
}
