<?php

declare(strict_types=1);

namespace TurathManager\Core;

use PDO;

/**
 * مصنع اتصال PDO. جميع التواريخ تُخزن بتوقيت UTC.
 */
final class Database
{
    /** @param array<string,mixed> $cfg */
    public static function connect(array $cfg, bool $withDatabase = true): PDO
    {
        $dsn = $cfg['socket'] !== ''
            ? 'mysql:unix_socket=' . $cfg['socket']
            : 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'];
        if ($withDatabase) {
            $dsn .= ';dbname=' . $cfg['name'];
        }
        $dsn .= ';charset=utf8mb4';

        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00', NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        return $pdo;
    }

    /**
     * تنفيذ دالة داخل Transaction مع التراجع عند الخطأ.
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(PDO $pdo, callable $fn): mixed
    {
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
