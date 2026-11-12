<?php

declare(strict_types=1);

namespace TurathManager\Tests;

use PDO;
use TurathManager\Core\App;
use TurathManager\Core\Request;
use TurathManager\Core\Uuid;

/**
 * أساس اختبارات التكامل: ينفذ الطلبات عبر نواة التطبيق مباشرة (نفس المسار الذي يمر به HTTP)
 * ويعيد ضبط البيانات قبل كل اختبار.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected App $app;
    private static int $counter = 0;

    private const KEEP = ['permissions', 'roles', 'role_permissions', 'notification_templates', 'app_settings', 'whatsapp_settings', 'schema_migrations'];

    protected function setUp(): void
    {
        $this->app = $this->makeApp();
        $pdo = $this->app->db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$t]) {
            if (!in_array($t, self::KEEP, true)) {
                $pdo->exec("DELETE FROM `$t`");
            }
        }
        $pdo->exec('DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE is_system = 0)');
        $pdo->exec('DELETE FROM roles WHERE is_system = 0');
        $pdo->exec("DELETE FROM app_settings WHERE setting_key NOT IN ('shop_name','shop_logo','installation_reminder_hours','removal_reminder_hours')");
        $pdo->exec("UPDATE whatsapp_settings SET business_account_id = NULL, phone_number_id = NULL, access_token_enc = NULL, app_secret_enc = NULL, verify_token_enc = NULL, mode = 'test', is_enabled = 0, connection_status = 'not_configured'");
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function makeApp(array $overrides = []): App
    {
        $base = [
            'app' => ['env' => 'testing', 'debug' => false, 'key' => 'base64:' . base64_encode(str_repeat('k', 32))],
            'db' => ['name' => $GLOBALS['TM_TEST_DB']],
            'paths' => ['uploads' => $GLOBALS['TM_UPLOADS'], 'backups' => $GLOBALS['TM_UPLOADS'] . '/backups', 'logs' => sys_get_temp_dir() . '/turath-test-logs'],
            'whatsapp' => ['access_token' => '', 'app_secret' => '', 'verify_token' => ''],
            'fcm' => ['project_id' => '', 'service_account_file' => ''],
        ];
        return ($GLOBALS['TM_FACTORY'])(array_replace_recursive($base, $overrides));
    }

    /** @return array{0:int,1:mixed,2:array<string,string>} */
    protected function call(string $method, string $path, array $body = [], ?string $token = null, array $files = [], array $headers = [], ?App $app = null): array
    {
        $h = ['content-type' => 'application/json', 'user-agent' => 'phpunit'] + array_change_key_case($headers);
        if ($token) {
            $h['authorization'] = 'Bearer ' . $token;
        }
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query);
        }
        $req = new Request($method, $path, $query, $body, $h, $files, '10.0.0.' . (self::$counter % 200 + 1), json_encode($body));
        $resp = ($app ?? $this->app)->handle($req);
        $data = $resp->raw !== null ? $resp->raw : $resp->data;
        return [$resp->status, $data, $resp->headers];
    }

    protected function ok(string $method, string $path, array $body = [], ?string $token = null): mixed
    {
        [$s, $d] = $this->call($method, $path, $body, $token);
        $this->assertLessThan(300, $s, "$method $path => $s " . json_encode($d, JSON_UNESCAPED_UNICODE));
        return $d;
    }

    /** إنشاء مستخدم مباشرة في القاعدة وإرجاع [id, token] */
    protected function user(array $roles = ['admin'], array $centers = [], ?string $username = null, array $extra = []): array
    {
        self::$counter++;
        $id = Uuid::v4();
        $username ??= 'user' . self::$counter;
        $pdo = $this->app->db();
        $pdo->prepare('INSERT INTO users (id, full_name, username, password_hash, is_active, created_at, updated_at, whatsapp_number, whatsapp_opt_in) VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)')
            ->execute([$id, $extra['full_name'] ?? ('مستخدم ' . self::$counter), $username, password_hash('Passw0rd99', PASSWORD_BCRYPT, ['cost' => 4]), $extra['whatsapp_number'] ?? null, (int) ($extra['whatsapp_opt_in'] ?? 0)]);
        foreach ($roles as $r) {
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ?')->execute([$id, $r]);
        }
        foreach ($centers as $c => $role) {
            $pdo->prepare('INSERT INTO center_users (center_id, user_id, role_in_center) VALUES (?, ?, ?)')->execute([$c, $id, $role]);
        }
        [$s, $d] = $this->call('POST', '/auth/login', ['username' => $username, 'password' => 'Passw0rd99']);
        $this->assertSame(200, $s, json_encode($d, JSON_UNESCAPED_UNICODE));
        return [$id, $d['token']];
    }

    protected function admin(): string
    {
        return $this->user(['admin'])[1];
    }

    protected function center(string $token, string $name = 'مركز اليفا', ?string $code = null): array
    {
        return $this->ok('POST', '/centers', ['name' => $name, 'code' => $code ?? ('C' . random_int(1000, 99999)), 'address' => 'صنعاء'], $token)['data'];
    }

    /** @return array{category:array,type:array,product:array} */
    protected function product(string $token, string $category = 'سجاف', string $type = 'مغربي', int $qty = 3, string $price = '50000', ?string $code = null): array
    {
        [, $cats] = $this->call('GET', '/categories', [], $token);
        $cat = null;
        foreach ($cats['data'] as $c) {
            if ($c['name'] === $category) {
                $cat = $c;
            }
        }
        $cat ??= $this->ok('POST', '/categories', ['name' => $category], $token)['data'];
        $t = null;
        foreach ($cat['types'] ?? [] as $tt) {
            if ($tt['name'] === $type) {
                $t = $tt;
            }
        }
        $t ??= $this->ok('POST', '/category-types', ['category_id' => $cat['id'], 'name' => $type], $token)['data'];
        $p = $this->ok('POST', '/products', [
            'name' => "$category $type", 'code' => $code ?? ('P-' . random_int(10000, 999999)), 'category_id' => $cat['id'],
            'category_type_id' => $t['id'], 'price' => $price, 'initial_quantity' => $qty,
        ], $token)['data'];
        return ['category' => $cat, 'type' => $t, 'product' => $p];
    }

    protected static function future(int $days, int $hour = 16): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Aden')))->modify("+$days days")->setTime($hour, 0)->format('Y-m-d\TH:i:sP');
    }

    protected function booking(string $token, string $centerId, array $items, int $startDay = 10, int $endDay = 12, array $extra = []): array
    {
        return $this->call('POST', '/bookings', $extra + [
            'center_id' => $centerId,
            'customer' => ['full_name' => 'أم محمد', 'phone' => '777123456', 'address' => 'صنعاء - حدة'],
            'install_at' => self::future($startDay),
            'removal_at' => self::future($endDay),
            'items' => $items,
        ], $token);
    }

    protected function image(string $type = 'png'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        $im = imagecreatetruecolor(40, 30);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 20, 60));
        $type === 'jpeg' ? imagejpeg($im, $path) : imagepng($im, $path);
        imagedestroy($im);
        return ['name' => 'x.' . $type, 'type' => 'image/' . $type, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
    }
}
