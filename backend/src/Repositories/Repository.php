<?php

declare(strict_types=1);

namespace TurathManager\Repositories;

use PDO;

/**
 * مستودع أساسي: جميع الاستعلامات عبر Prepared Statements،
 * وأسماء الأعمدة مقيدة بقائمة بيضاء ثابتة لكل جدول.
 */
abstract class Repository
{
    protected const TABLE = '';
    /** @var list<string> */
    protected const COLUMNS = [];
    protected const VERSIONED = true;

    public function __construct(protected readonly PDO $db)
    {
    }

    public function find(string $id, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM ' . static::TABLE . ' WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
        return $this->one($sql, [$id]);
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): void
    {
        $data = $this->filter($data);
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            static::TABLE,
            implode(', ', $cols),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        $this->db->prepare($sql)->execute(array_map([$this, 'scalar'], array_values($data)));
    }

    /**
     * تحديث مع زيادة رقم الإصدار. إذا مُرر expectedVersion يفشل التحديث عند اختلافه.
     * @param array<string,mixed> $data
     */
    public function update(string $id, array $data, ?int $expectedVersion = null): bool
    {
        $data = $this->filter($data);
        unset($data['id']);
        if ($data === [] && !static::VERSIONED) {
            return true;
        }
        $sets = array_map(static fn ($c) => "$c = ?", array_keys($data));
        if (static::VERSIONED) {
            $sets[] = 'version = version + 1';
        }
        $params = array_map([$this, 'scalar'], array_values($data));
        $sql = 'UPDATE ' . static::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $params[] = $id;
        if ($expectedVersion !== null) {
            $sql .= ' AND version = ?';
            $params[] = $expectedVersion;
        }
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->rowCount() > 0;
    }

    /** @param array<string,mixed> $data */
    protected function filter(array $data): array
    {
        return array_intersect_key($data, array_flip(static::COLUMNS));
    }

    protected function scalar(mixed $v): mixed
    {
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return $v;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function exec(string $sql, array $params = []): int
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** بناء شرط LIKE آمن */
    public static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }
}
