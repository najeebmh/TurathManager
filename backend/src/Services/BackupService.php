<?php

declare(strict_types=1);

namespace TurathManager\Services;

use PDO;
use TurathManager\Core\App;
use TurathManager\Core\Clock;
use TurathManager\Core\Database;
use TurathManager\Core\HttpException;
use TurathManager\Core\Uuid;
use TurathManager\Repositories\Generic;

/**
 * النسخ الاحتياطي والاستعادة بلغة PHP فقط (دون الاعتماد على mysqldump)،
 * بصيغة SQL مضغوطة gzip: كل جملة في سطر واحد، مع بصمة SHA-256 للتحقق.
 */
final class BackupService
{
    private Generic $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new Generic($app->db());
    }

    private function dir(): string
    {
        $d = $this->app->config['paths']['backups'];
        if (!is_dir($d)) {
            mkdir($d, 0770, true);
        }
        return $d;
    }

    public function create(?string $userId = null): array
    {
        $pdo = $this->app->db();
        $id = Uuid::v4();
        $file = 'turath-' . gmdate('Ymd-His') . '-' . substr($id, 0, 8) . '.sql.gz';
        $path = $this->dir() . '/' . $file;
        $this->repo->exec("INSERT INTO backups (id, file_name, status, created_by, created_at) VALUES (?, ?, 'running', ?, ?)", [$id, $file, $userId, $this->app->clock->nowDb()]);
        try {
            $gz = gzopen($path, 'wb6');
            gzwrite($gz, "-- Turath Manager backup " . gmdate('c') . "\n");
            gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET time_zone='+00:00';\n");
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
            foreach ($tables as [$table]) {
                if (!preg_match('/^[a-z0-9_]+$/', $table)) {
                    continue;
                }
                $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
                gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n" . preg_replace('/\s*\n\s*/', ' ', $create) . ";\n");
                $st = $pdo->query("SELECT * FROM `$table`");
                $batch = [];
                $cols = null;
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $cols ??= '`' . implode('`,`', array_keys($row)) . '`';
                    $batch[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row)) . ')';
                    if (count($batch) >= 200) {
                        gzwrite($gz, "INSERT INTO `$table` ($cols) VALUES " . implode(',', $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    gzwrite($gz, "INSERT INTO `$table` ($cols) VALUES " . implode(',', $batch) . ";\n");
                }
            }
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- END\n");
            gzclose($gz);
            @chmod($path, 0640);
            $this->repo->exec("UPDATE backups SET status = 'completed', size_bytes = ?, checksum_sha256 = ?, completed_at = ? WHERE id = ?", [filesize($path), hash_file('sha256', $path), $this->app->clock->nowDb(), $id]);
        } catch (\Throwable $e) {
            $this->repo->exec("UPDATE backups SET status = 'failed', error_message = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $id]);
            throw $e;
        }
        return $this->get($id);
    }

    public function get(string $id): array
    {
        $b = $this->repo->one('SELECT * FROM backups WHERE id = ?', [$id]) ?? throw HttpException::notFound('النسخة غير موجودة');
        return self::out($b);
    }

    public function list(): array
    {
        return array_map([self::class, 'out'], $this->repo->all('SELECT * FROM backups ORDER BY created_at DESC LIMIT 100'));
    }

    public function path(string $id): string
    {
        $b = $this->repo->one("SELECT file_name, checksum_sha256 FROM backups WHERE id = ? AND status = 'completed'", [$id]) ?? throw HttpException::notFound('النسخة غير موجودة');
        $path = $this->dir() . '/' . basename($b['file_name']);
        if (!is_file($path)) {
            throw HttpException::notFound('ملف النسخة غير موجود على الخادم');
        }
        if (!hash_equals((string) $b['checksum_sha256'], hash_file('sha256', $path))) {
            throw HttpException::conflict('بصمة ملف النسخة لا تطابق المسجلة - الملف تالف أو معدّل');
        }
        return $path;
    }

    /**
     * استعادة ملف نسخة إلى قاعدة بيانات (افتراضياً قاعدة التطبيق نفسها).
     * @return array{statements:int,tables:int}
     */
    public static function restoreFile(string $path, PDO $target): array
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('تعذر فتح ملف النسخة');
        }
        $count = 0;
        $tables = 0;
        $buffer = '';
        $target->exec('SET FOREIGN_KEY_CHECKS=0');
        while (!gzeof($gz)) {
            $line = gzgets($gz, 1 << 24);
            if ($line === false) {
                break;
            }
            $buffer .= $line;
            if (!str_ends_with(rtrim($buffer, "\r\n"), ';')) {
                continue;
            }
            $sql = trim($buffer);
            $buffer = '';
            if ($sql === '' || str_starts_with($sql, '--')) {
                continue;
            }
            $target->exec($sql);
            $count++;
            if (str_starts_with($sql, 'CREATE TABLE')) {
                $tables++;
            }
        }
        gzclose($gz);
        $target->exec('SET FOREIGN_KEY_CHECKS=1');
        return ['statements' => $count, 'tables' => $tables];
    }

    public function restoreInto(string $id, string $database): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw HttpException::validation(['database' => 'اسم قاعدة البيانات غير صالح']);
        }
        $path = $this->path($id);
        $cfg = $this->app->config['db'];
        Database::connect($cfg, false)->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $target = Database::connect(['name' => $database] + $cfg);
        return self::restoreFile($path, $target);
    }

    private static function out(array $b): array
    {
        return [
            'id' => $b['id'], 'file_name' => $b['file_name'], 'size_bytes' => (int) $b['size_bytes'], 'checksum_sha256' => $b['checksum_sha256'],
            'status' => $b['status'], 'error_message' => $b['error_message'], 'created_at' => Clock::toIso($b['created_at']), 'completed_at' => Clock::toIso($b['completed_at']),
        ];
    }
}
