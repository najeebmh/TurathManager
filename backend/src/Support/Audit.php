<?php

declare(strict_types=1);

namespace TurathManager\Support;

use TurathManager\Core\App;
use TurathManager\Core\Logger;
use TurathManager\Core\Request;

/**
 * تسجيل العمليات الحساسة في جدول audit_logs.
 */
final class Audit
{
    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string,mixed> $details */
    public function log(?string $userId, string $action, ?string $entityType = null, ?string $entityId = null, array $details = [], ?Request $request = null): void
    {
        $this->app->db()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $userId,
                $action,
                $entityType,
                $entityId,
                $details ? json_encode(Logger::redact($details), JSON_UNESCAPED_UNICODE) : null,
                $request?->ip,
                $request ? mb_substr((string) $request->header('user-agent'), 0, 255) : null,
                $this->app->clock->nowDb(),
            ]);
    }
}
