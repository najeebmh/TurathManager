<?php

declare(strict_types=1);

namespace TurathManager\Tests;

use TurathManager\Core\Database;

/**
 * النسخ الاحتياطي واستعادته فعلياً إلى قاعدة منفصلة مع مقارنة البيانات.
 */
final class BackupTest extends TestCase
{
    public function testBackupAndRealRestore(): void
    {
        $t = $this->admin();
        $c = $this->center($t, "مركز 'اليفا' \"الرئيسي\"\nسطر ثانٍ");
        $p = $this->product($t, 'سجاف', 'مغربي', 3, '45000.50')['product'];
        $this->booking($t, $c['id'], [['product_id' => $p['id'], 'quantity' => 2]], 5, 6, ['total_price' => '1234.56', 'notes' => "ملاحظة; DROP TABLE users; --\n😀"]);
        $b = $this->ok('POST', '/backups', [], $t)['data'];
        $this->assertSame('completed', $b['status']);
        $this->assertSame(64, strlen($b['checksum_sha256']));
        [$s, , $h] = $this->call('GET', "/backups/{$b['id']}/download", [], $t);
        $this->assertSame(200, $s);
        $this->assertSame('application/gzip', $h['Content-Type']);

        $target = $GLOBALS['TM_TEST_DB'] . '_restore';
        $r = $this->app->service(\TurathManager\Services\BackupService::class)->restoreInto($b['id'], $target);
        $this->assertSame(44, $r['tables']);
        $dst = Database::connect(['name' => $target] + $this->app->config['db']);
        $src = $this->app->db();
        foreach (['users', 'centers', 'products', 'bookings', 'booking_items', 'inventory_movements', 'permissions', 'schema_migrations'] as $table) {
            $this->assertSame(
                $src->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(),
                $dst->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(),
                "row count mismatch: $table"
            );
        }
        $this->assertSame(
            (int) $src->query("SELECT COUNT(*) FROM audit_logs WHERE action NOT IN ('backup.created','backup.downloaded')")->fetchColumn(),
            (int) $dst->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()
        );
        $this->assertSame($c['name'], $dst->query('SELECT name FROM centers')->fetchColumn());
        $this->assertSame("ملاحظة; DROP TABLE users; --\n😀", $dst->query('SELECT notes FROM bookings')->fetchColumn());
        $this->assertSame('45000.50', $dst->query('SELECT price FROM products')->fetchColumn());
        $dst->exec("DROP DATABASE `$target`");

        file_put_contents($this->app->config['paths']['backups'] . '/' . $b['file_name'], 'tampered', FILE_APPEND);
        [$s] = $this->call('GET', "/backups/{$b['id']}/download", [], $t);
        $this->assertSame(409, $s, 'tampered backup detected by checksum');
    }
}
