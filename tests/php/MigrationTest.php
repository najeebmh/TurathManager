<?php

declare(strict_types=1);

namespace TurathManager\Tests;

use TurathManager\Core\Database;
use TurathManager\Support\Migrator;

/**
 * المرحلة 2: التراجع عن Migrations وإعادة تطبيقها، وكشف تعديل Migration بعد تطبيقها.
 */
final class MigrationTest extends \PHPUnit\Framework\TestCase
{
    public function testMigrationsRollBackAndReapplyCleanly(): void
    {
        $cfg = (require dirname(__DIR__, 2) . '/backend/config/config.php')['db'];
        $name = $GLOBALS['TM_TEST_DB'] . '_mig';
        $server = Database::connect($cfg, false);
        $server->exec("DROP DATABASE IF EXISTS `$name`");
        $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = Database::connect(['name' => $name] + $cfg);
        $m = new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations');
        $applied = $m->up();
        $this->assertCount(11, $applied);
        $tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$name'")->fetchColumn();
        $this->assertSame(44, $tables);
        $this->assertSame([], $m->status()['pending']);
        $this->assertSame([], $m->status()['modified']);

        $this->assertSame(['0011_insertion_sequences'], $m->down(1));
        $this->assertSame(['0010_reference_data'], $m->down(1));
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn());
        $down = $m->down(99);
        $this->assertCount(9, $down);
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$name'")->fetchColumn());
        $this->assertCount(11, $m->up());
        $this->assertGreaterThan(20, (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn());
        $server->exec("DROP DATABASE `$name`");
    }
}
