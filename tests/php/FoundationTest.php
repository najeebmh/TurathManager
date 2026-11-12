<?php

declare(strict_types=1);

namespace TurathManager\Tests;

use TurathManager\Core\Router;
use TurathManager\Core\Validator;

/**
 * المرحلتان 1 و 2: الأساس، فحص الصحة، الأخطاء الآمنة، المخطط والقيود.
 */
final class FoundationTest extends TestCase
{
    public function testHealthCheckReportsDatabaseAndSchema(): void
    {
        [$s, $d] = $this->call('GET', '/health');
        $this->assertSame(200, $s);
        $this->assertSame('ok', $d['status']);
        $this->assertSame('up', $d['database']['status']);
        $this->assertSame('0010_reference_data', $d['database']['schema_version']);
        $this->assertSame(0, $d['database']['pending_migrations']);
    }

    public function testDatabaseFailureDoesNotLeakCredentials(): void
    {
        $secret = 'SuperSecretPw_' . bin2hex(random_bytes(4));
        $app = $this->makeApp(['db' => ['pass' => $secret, 'user' => 'nonexistent_user'], 'app' => ['debug' => true, 'env' => 'production']]);
        [$s, $d, $h] = $this->call('GET', '/health', [], null, [], [], $app);
        $this->assertSame(503, $s);
        $this->assertStringNotContainsString($secret, json_encode($d));
        [$s2, $d2] = $this->call('POST', '/auth/login', ['username' => 'x', 'password' => 'y'], null, [], [], $app);
        $this->assertSame(500, $s2);
        $this->assertStringNotContainsString($secret, json_encode($d2, JSON_UNESCAPED_UNICODE));
        $this->assertSame('حدث خطأ داخلي في الخادم', $d2['error']['message']);
    }

    public function testUnknownRouteAndSecurityHeaders(): void
    {
        [$s, $d, $h] = $this->call('GET', '/nope');
        $this->assertSame(404, $s);
        $this->assertSame('nosniff', $h['X-Content-Type-Options']);
        $this->assertSame('DENY', $h['X-Frame-Options']);
    }

    public function testCorsOnlyForAllowedOrigins(): void
    {
        [, , $h] = $this->call('OPTIONS', '/health', [], null, [], ['origin' => 'capacitor://localhost']);
        $this->assertSame('capacitor://localhost', $h['Access-Control-Allow-Origin']);
        [, , $h2] = $this->call('OPTIONS', '/health', [], null, [], ['origin' => 'https://evil.example']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $h2);
    }

    public function testEveryNonPublicRouteRequiresAuthentication(): void
    {
        $public = 0;
        foreach ($this->app->router->all() as $route) {
            $this->assertNotSame('', $route['access'], $route['pattern'] . ' has no access rule');
            if ($route['access'] === Router::PUBLIC) {
                $public++;
                continue;
            }
            $path = preg_replace('/\{[a-zA-Z]+\}/', '00000000-0000-4000-8000-000000000000', $route['pattern']);
            [$s] = $this->call($route['method'], $path);
            $this->assertSame(401, $s, $route['method'] . ' ' . $route['pattern'] . ' must require login');
        }
        $this->assertSame(6, $public, 'only health, public settings, media, login, and the two webhook routes are public');
    }

    public function testPhoneNormalization(): void
    {
        $this->assertSame('+967777123456', Validator::normalizePhone('777123456'));
        $this->assertSame('+967777123456', Validator::normalizePhone('0777123456'));
        $this->assertSame('+967777123456', Validator::normalizePhone('00967 777-123-456'));
        $this->assertSame('+966501234567', Validator::normalizePhone('+966501234567'));
        $this->assertSame('+967777123456', Validator::normalizePhone('٧٧٧١٢٣٤٥٦'));
        $this->assertNull(Validator::normalizePhone('abc'));
        $this->assertNull(Validator::normalizePhone('12'));
    }

    public function testSchemaConstraintsProtectHistoricalAndFinancialRecords(): void
    {
        $t = $this->admin();
        $c = $this->center($t);
        $p = $this->product($t)['product'];
        [$s, $b] = $this->booking($t, $c['id'], [['product_id' => $p['id'], 'quantity' => 1]], 5, 6, ['total_price' => '1000']);
        $this->assertSame(201, $s);
        $this->ok('POST', "/bookings/{$b['data']['id']}/payments", ['payment_type' => 'deposit', 'amount' => '100', 'method' => 'cash'], $t);
        $pdo = $this->app->db();
        foreach ([
            ['DELETE FROM bookings WHERE id = ?', [$b['data']['id']]],
            ['DELETE FROM products WHERE id = ?', [$p['id']]],
            ['DELETE FROM customers WHERE id = ?', [$b['data']['customer_id']]],
            ['DELETE FROM centers WHERE id = ?', [$c['id']]],
        ] as [$sql, $params]) {
            try {
                $pdo->prepare($sql)->execute($params);
                $this->fail('FK should block: ' . $sql);
            } catch (\PDOException $e) {
                $this->assertSame('23000', $e->getCode());
            }
        }
        $this->expectException(\PDOException::class);
        $pdo->prepare('UPDATE products SET quantity_total = -1 WHERE id = ?')->execute([$p['id']]);
    }

    public function testArabicTextRoundTripAndDecimalTypes(): void
    {
        $t = $this->admin();
        $name = 'سجاف صنعاني عرائسي — تطريز يدوي ﷺ 😀';
        $p = $this->product($t, 'سجاف', 'صنعاني', 1, '12345.67')['product'];
        $this->ok('PUT', "/products/{$p['id']}", ['name' => $name], $t);
        $got = $this->ok('GET', "/products/{$p['id']}", [], $t)['data'];
        $this->assertSame($name, $got['name']);
        $this->assertSame('12345.67', $got['price']);
        $col = $this->app->db()->query("SELECT DATA_TYPE, NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'amount'")->fetch();
        $this->assertSame('decimal', $col['DATA_TYPE']);
        $this->assertSame(2, (int) $col['NUMERIC_SCALE']);
        $charset = $this->app->db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_COLLATION NOT LIKE 'utf8mb4%'")->fetchColumn();
        $this->assertSame(0, (int) $charset);
    }
}
