<?php

declare(strict_types=1);

namespace TurathManager\Support;

use TurathManager\Core\App;

/**
 * سجل التغييرات المتسلسل الذي تعتمد عليه الأجهزة لسحب التحديثات (Pull) بالترتيب.
 */
final class ChangeLog
{
    public const ENTITIES = ['category', 'category_type', 'product', 'center', 'customer', 'booking'];

    public function __construct(private readonly App $app)
    {
    }

    public function record(string $entity, string $id, string $action = 'upsert'): void
    {
        $this->app->db()->prepare('INSERT INTO change_log (entity_type, entity_id, action, changed_at) VALUES (?, ?, ?, ?)')
            ->execute([$entity, $id, $action, $this->app->clock->nowDb()]);
    }
}
