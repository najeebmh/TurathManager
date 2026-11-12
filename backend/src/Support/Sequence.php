<?php

declare(strict_types=1);

namespace TurathManager\Support;

use PDO;

/**
 * مولد أرقام بشرية متسلسلة (أرقام الحجوزات والسندات) آمن ضد التزامن.
 * يجب استدعاؤه داخل Transaction.
 */
final class Sequence
{
    public static function next(PDO $db, string $name, string $period): int
    {
        $db->prepare('INSERT INTO sequences (name, period, last_number) VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE last_number = last_number + 1')->execute([$name, $period]);
        $st = $db->prepare('SELECT last_number FROM sequences WHERE name = ? AND period = ?');
        $st->execute([$name, $period]);
        return (int) $st->fetchColumn();
    }
}
