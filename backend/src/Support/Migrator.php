<?php

declare(strict_types=1);

namespace TurathManager\Support;

use PDO;

/**
 * مشغل Migrations مرقمة. يسجل الإصدارات المطبقة في جدول schema_migrations
 * مع بصمة SHA-256 لكل ملف لاكتشاف تعديل Migration بعد تطبيقها.
 */
final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $dir)
    {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            batch INT UNSIGNED NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @return array<string,string> version => path */
    public function files(): array
    {
        $files = glob($this->dir . '/[0-9][0-9][0-9][0-9]_*.php') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $f) {
            $out[basename($f, '.php')] = $f;
        }
        return $out;
    }

    /** @return array<string,array{checksum:string,batch:int}> */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->pdo->query('SELECT version, checksum, batch FROM schema_migrations ORDER BY version')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['version']] = ['checksum' => $r['checksum'], 'batch' => (int) $r['batch']];
        }
        return $out;
    }

    /** @return list<string> */
    public function up(): array
    {
        $applied = $this->applied();
        $batch = $applied ? max(array_column($applied, 'batch')) + 1 : 1;
        $done = [];
        foreach ($this->files() as $version => $file) {
            if (isset($applied[$version])) {
                continue;
            }
            $m = require $file;
            $this->run($m['up']);
            $this->pdo->prepare('INSERT INTO schema_migrations (version, checksum, batch) VALUES (?, ?, ?)')
                ->execute([$version, hash_file('sha256', $file), $batch]);
            $done[] = $version;
        }
        return $done;
    }

    /** التراجع عن آخر دفعة، أو عدد محدد من الخطوات */
    public function down(int $steps = 0): array
    {
        $applied = $this->applied();
        if (!$applied) {
            return [];
        }
        $versions = array_keys($applied);
        rsort($versions);
        if ($steps <= 0) {
            $last = max(array_column($applied, 'batch'));
            $versions = array_values(array_filter($versions, static fn ($v) => $applied[$v]['batch'] === $last));
        } else {
            $versions = array_slice($versions, 0, $steps);
        }
        $files = $this->files();
        $done = [];
        foreach ($versions as $version) {
            $m = require $files[$version];
            $this->run($m['down']);
            $this->pdo->prepare('DELETE FROM schema_migrations WHERE version = ?')->execute([$version]);
            $done[] = $version;
        }
        return $done;
    }

    /** @return array{current:?string,pending:list<string>,modified:list<string>} */
    public function status(): array
    {
        $applied = $this->applied();
        $pending = [];
        $modified = [];
        foreach ($this->files() as $version => $file) {
            if (!isset($applied[$version])) {
                $pending[] = $version;
            } elseif ($applied[$version]['checksum'] !== hash_file('sha256', $file)) {
                $modified[] = $version;
            }
        }
        $keys = array_keys($applied);
        return ['current' => $keys ? end($keys) : null, 'pending' => $pending, 'modified' => $modified];
    }

    private function run(mixed $step): void
    {
        if (is_callable($step)) {
            $step($this->pdo);
            return;
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach ((array) $step as $sql) {
            $this->pdo->exec($sql);
        }
    }
}
