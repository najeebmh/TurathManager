<?php

declare(strict_types=1);

namespace TurathManager\Services;

use TurathManager\Auth\CurrentUser;
use TurathManager\Core\App;
use TurathManager\Core\Clock;
use TurathManager\Core\Database;
use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Uuid;
use TurathManager\Repositories\Generic;

/**
 * محرك المزامنة على الخادم.
 * - Push: استقبال عمليات Outbox بالترتيب. معرف كل عملية هو مفتاح عدم التكرار (Idempotency Key):
 *   إعادة إرسال نفس العملية تعيد النتيجة المخزنة دون تنفيذها مرة ثانية.
 * - كل عملية تُنفذ مع تسجيلها داخل Transaction واحدة، فلا تُطبق عملية دون تسجيل نتيجتها.
 * - التعديلات تحمل base_version؛ إذا كان إصدار الخادم أحدث تُعاد كتعارض ولا تُستبدل البيانات الأحدث.
 * - Pull: سحب التغييرات بالترتيب عبر مؤشر change_log.seq.
 */
final class SyncService
{
    public const MAX_BATCH = 100;

    /** الصلاحية المطلوبة لكل كيان/عملية */
    private const PERMISSIONS = [
        'customer.create' => 'manage_customers', 'customer.update' => 'manage_customers',
        'product.create' => 'manage_products', 'product.update' => 'manage_products', 'product.archive' => 'manage_products',
        'inventory_movement.create' => 'manage_inventory',
        'category.create' => 'manage_categories', 'category.update' => 'manage_categories',
        'category_type.create' => 'manage_categories', 'category_type.update' => 'manage_categories',
        'center.create' => 'manage_centers', 'center.update' => 'manage_centers',
        'booking.create' => 'create_booking', 'booking.update' => 'edit_booking', 'booking.cancel' => 'cancel_booking',
        'handover.create' => 'manage_handovers',
        'payment.create' => 'manage_payments',
    ];

    private Generic $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new Generic($app->db());
    }

    public function registerDevice(CurrentUser $u, string $deviceId, array $info = []): void
    {
        if (!Uuid::isValid($deviceId)) {
            throw HttpException::validation(['device_id' => 'معرف الجهاز غير صالح']);
        }
        $owner = $this->repo->value('SELECT user_id FROM sync_devices WHERE id = ?', [$deviceId]);
        $now = $this->app->clock->nowDb();
        if ($owner === null) {
            $this->repo->exec('INSERT INTO sync_devices (id, user_id, device_name, platform, app_version, last_seen_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $deviceId, $u->id, mb_substr((string) ($info['device_name'] ?? ''), 0, 120) ?: null, mb_substr((string) ($info['platform'] ?? ''), 0, 20) ?: null,
                mb_substr((string) ($info['app_version'] ?? ''), 0, 20) ?: null, $now, $now,
            ]);
        } else {
            // الجهاز قد يُستخدم من أكثر من مستخدم (تسجيل خروج ودخول)؛ نحدّث المالك الحالي
            $this->repo->exec('UPDATE sync_devices SET user_id = ?, last_seen_at = ?, device_name = COALESCE(?, device_name), platform = COALESCE(?, platform), app_version = COALESCE(?, app_version) WHERE id = ?', [
                $u->id, $now, $info['device_name'] ?? null, $info['platform'] ?? null, $info['app_version'] ?? null, $deviceId,
            ]);
        }
    }

    /**
     * @param list<array<string,mixed>> $operations
     * @return list<array<string,mixed>>
     */
    public function push(CurrentUser $u, string $deviceId, array $operations, ?Request $req = null, array $deviceInfo = []): array
    {
        if (count($operations) > self::MAX_BATCH) {
            throw HttpException::validation(['operations' => 'الحد الأقصى ' . self::MAX_BATCH . ' عملية في الدفعة الواحدة']);
        }
        $this->registerDevice($u, $deviceId, $deviceInfo);
        $results = [];
        $rejected = 0;
        foreach ($operations as $op) {
            $r = $this->applyOne($u, $deviceId, is_array($op) ? $op : [], $req);
            if ($r['status'] === 'rejected') {
                $rejected++;
            }
            $results[] = $r;
        }
        $this->repo->exec('UPDATE sync_devices SET last_push_at = ? WHERE id = ?', [$this->app->clock->nowDb(), $deviceId]);
        if ($rejected > 0) {
            $name = (string) ($this->repo->value('SELECT device_name FROM sync_devices WHERE id = ?', [$deviceId]) ?? $deviceId);
            $this->app->service(NotificationService::class)->systemEvent('sync_failed', ['device_name' => $name], 'sync_failed:' . $deviceId . ':' . gmdate('YmdH'));
        }
        return $results;
    }

    private function applyOne(CurrentUser $u, string $deviceId, array $op, ?Request $req): array
    {
        $opId = strtolower((string) ($op['op_id'] ?? ''));
        $entity = (string) ($op['entity'] ?? '');
        $action = (string) ($op['action'] ?? '');
        $entityId = isset($op['entity_id']) ? strtolower((string) $op['entity_id']) : null;
        $payload = is_array($op['payload'] ?? null) ? $op['payload'] : [];
        $baseVersion = isset($op['base_version']) && $op['base_version'] !== null ? (int) $op['base_version'] : null;
        $base = ['op_id' => $opId, 'entity' => $entity, 'action' => $action, 'entity_id' => $entityId];

        if (!Uuid::isValid($opId)) {
            return $base + ['status' => 'rejected', 'error' => 'معرف العملية غير صالح'];
        }
        $prev = $this->repo->one('SELECT status, result, error_message, user_id FROM sync_operations WHERE id = ?', [$opId]);
        if ($prev) {
            if ($prev['user_id'] !== $u->id) {
                return $base + ['status' => 'rejected', 'error' => 'معرف العملية مستخدم مسبقاً'];
            }
            return $base + ['status' => $prev['status'], 'duplicate' => true, 'error' => $prev['error_message']] + (json_decode((string) $prev['result'], true) ?: []);
        }
        $key = "$entity.$action";
        $clientAt = null;
        if (!empty($op['client_created_at'])) {
            try {
                $clientAt = $this->app->clock->toDb((string) $op['client_created_at']);
            } catch (\Throwable) {
                $clientAt = null;
            }
        }

        $record = function (string $status, array $result, ?string $error) use ($opId, $deviceId, $u, $entity, $action, $entityId, $payload, $baseVersion, $clientAt) {
            $this->repo->exec('INSERT INTO sync_operations (id, device_id, user_id, entity_type, action, entity_id, payload, base_version, status, result, error_message, client_created_at, received_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $opId, $deviceId, $u->id, mb_substr($entity, 0, 40), mb_substr($action, 0, 20), Uuid::isValid((string) $entityId) ? $entityId : null,
                json_encode($payload, JSON_UNESCAPED_UNICODE), $baseVersion, $status, $result ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
                $error !== null ? mb_substr($error, 0, 500) : null, $clientAt, $this->app->clock->nowDb(),
            ]);
        };

        if (!isset(self::PERMISSIONS[$key])) {
            $record('rejected', [], 'عملية غير مدعومة');
            return $base + ['status' => 'rejected', 'error' => 'عملية غير مدعومة: ' . $key];
        }
        if (!$u->can(self::PERMISSIONS[$key])) {
            $record('rejected', [], 'ليست لديك صلاحية لتنفيذ هذه العملية');
            return $base + ['status' => 'rejected', 'error' => 'ليست لديك صلاحية لتنفيذ هذه العملية'];
        }

        try {
            $out = Database::transaction($this->app->db(), function () use ($u, $key, $entityId, $payload, $baseVersion, $req, $deviceId, $clientAt, $opId, $record) {
                $data = $this->execute($u, $key, $entityId, $payload, $baseVersion, $req, ['device_id' => $deviceId, 'client_created_at' => $clientAt, 'sync_operation_id' => $opId]);
                $result = ['data' => $data];
                $status = 'applied';
                if ($key === 'booking.create' && ($data['status'] ?? '') === 'conflict') {
                    $result['booking_conflict'] = true;
                }
                $record($status, $result, null);
                return ['status' => $status] + $result;
            });
            return $base + $out;
        } catch (HttpException $e) {
            if ($e->errorCode === 'version_conflict') {
                $result = ['server' => $e->details['server'] ?? null];
                $record('conflict', $result, $e->getMessage());
                return $base + ['status' => 'conflict', 'error' => $e->getMessage()] + $result;
            }
            $result = ['code' => $e->errorCode, 'details' => $e->details ?: null];
            $record('rejected', $result, $e->getMessage());
            return $base + ['status' => 'rejected', 'error' => $e->getMessage()] + $result;
        } catch (\Throwable $e) {
            $this->app->logger->error('Sync op failed', ['op' => $opId, 'key' => $key, 'error' => $e->getMessage()]);
            // خطأ داخلي غير متوقع: لا نسجل العملية حتى يتمكن الجهاز من إعادة المحاولة لاحقاً
            return $base + ['status' => 'retry', 'error' => 'خطأ مؤقت في الخادم، ستُعاد المحاولة'];
        }
    }

    private function execute(CurrentUser $u, string $key, ?string $id, array $p, ?int $baseVersion, ?Request $req, array $ctx): array
    {
        $needId = static function (?string $id): string {
            if (!Uuid::isValid((string) $id)) {
                throw HttpException::validation(['entity_id' => 'معرف الكيان غير صالح']);
            }
            return (string) $id;
        };
        switch ($key) {
            case 'customer.create':
                return $this->app->service(CustomerService::class)->save($needId($id), $p, $u, $req);
            case 'customer.update':
                return $this->app->service(CustomerService::class)->save($needId($id), $p, $u, $req, $baseVersion);
            case 'product.create':
                return $this->app->service(ProductService::class)->create(['id' => $needId($id)] + $p, $u, $req);
            case 'product.update':
                return $this->app->service(ProductService::class)->update($needId($id), $p, $u, $req, $baseVersion);
            case 'product.archive':
                return $this->app->service(ProductService::class)->archive($needId($id), (bool) ($p['archived'] ?? true), $u, $req);
            case 'inventory_movement.create':
                return $this->app->service(ProductService::class)->addMovement($needId($p['product_id'] ?? null), $p, $u, $req);
            case 'category.create':
            case 'category.update':
                return $this->app->service(CategoryService::class)->saveCategory($needId($id), $p, $u, $req, $key === 'category.update' ? $baseVersion : null);
            case 'category_type.create':
            case 'category_type.update':
                return $this->app->service(CategoryService::class)->saveType($needId($id), $p, $u, $req, $key === 'category_type.update' ? $baseVersion : null);
            case 'center.create':
            case 'center.update':
                return $this->app->service(CenterService::class)->save($needId($id), $p, $u, $req, $key === 'center.update' ? $baseVersion : null);
            case 'booking.create':
                return $this->app->service(BookingService::class)->create(['id' => $needId($id)] + $p, $u, $req, [
                    'source' => 'offline_sync', 'device_id' => $ctx['device_id'], 'client_created_at' => $ctx['client_created_at'],
                    'record_conflict' => true, 'sync_operation_id' => $ctx['sync_operation_id'],
                ]);
            case 'booking.update':
                return $this->app->service(BookingService::class)->update($needId($id), $p, $u, $req, $baseVersion);
            case 'booking.cancel':
                return $this->app->service(BookingService::class)->cancel($needId($id), (string) ($p['reason'] ?? ''), $u, $req);
            case 'handover.create':
                return $this->app->service(HandoverService::class)->record($needId($p['booking_item_id'] ?? null), (string) ($p['handover_type'] ?? ''), ['id' => $needId($id)] + $p, $u, $req);
            case 'payment.create':
                return $this->app->service(PaymentService::class)->add($needId($p['booking_id'] ?? null), ['id' => $needId($id)] + $p, $u, $req);
        }
        throw HttpException::badRequest('عملية غير مدعومة');
    }

    /** سحب التغييرات منذ مؤشر معين */
    public function pull(CurrentUser $u, string $deviceId, int $since, int $limit = 300): array
    {
        $this->registerDevice($u, $deviceId);
        $limit = max(1, min(500, $limit));
        $rows = $this->repo->all("SELECT entity_type, entity_id, MAX(seq) AS seq FROM change_log WHERE seq > ?
            GROUP BY entity_type, entity_id ORDER BY seq LIMIT $limit", [$since]);
        $changes = [];
        $cursor = $since;
        foreach ($rows as $r) {
            $cursor = max($cursor, (int) $r['seq']);
            $rec = $this->fetchEntity($u, $r['entity_type'], $r['entity_id']);
            if ($rec !== null) {
                $changes[] = ['entity' => $r['entity_type'], 'id' => $r['entity_id'], 'seq' => (int) $r['seq'], 'data' => $rec];
            }
        }
        $max = (int) $this->repo->value('SELECT COALESCE(MAX(seq),0) FROM change_log');
        $this->repo->exec('UPDATE sync_devices SET last_pull_at = ?, last_pull_cursor = ? WHERE id = ?', [$this->app->clock->nowDb(), $cursor, $deviceId]);
        return [
            'changes' => $changes,
            'cursor' => $cursor,
            'has_more' => $cursor < $max && count($rows) === $limit,
            'server_time' => $this->app->clock->now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function fetchEntity(CurrentUser $u, string $entity, string $id): ?array
    {
        try {
            return match ($entity) {
                'category' => $u->can('view_products') ? $this->app->service(CategoryService::class)->getCategory($id) : null,
                'category_type' => $u->can('view_products') ? $this->app->service(CategoryService::class)->getType($id) : null,
                'product' => $u->can('view_products') ? $this->app->service(ProductService::class)->get($id) : null,
                'center' => $u->can('view_centers') ? $this->app->service(CenterService::class)->get($id) : null,
                'customer' => $u->can('view_customers') ? $this->app->service(CustomerService::class)->get($id) : null,
                'booking' => ($u->can('view_bookings') || $u->can('view_all_bookings')) ? $this->app->service(BookingService::class)->get($id, $u) : null,
                default => null,
            };
        } catch (HttpException) {
            return null;
        }
    }

    public function status(CurrentUser $u): array
    {
        $devices = $this->repo->all('SELECT id, device_name, platform, app_version, last_seen_at, last_push_at, last_pull_at, last_pull_cursor FROM sync_devices WHERE user_id = ? ORDER BY last_seen_at DESC', [$u->id]);
        $ops = $this->repo->all("SELECT id, device_id, entity_type, action, entity_id, status, error_message, received_at FROM sync_operations
            WHERE user_id = ? AND status <> 'applied' ORDER BY received_at DESC LIMIT 100", [$u->id]);
        return [
            'devices' => array_map(static function ($d) {
                foreach (['last_seen_at', 'last_push_at', 'last_pull_at'] as $k) {
                    $d[$k] = Clock::toIso($d[$k]);
                }
                return $d;
            }, $devices),
            'problems' => array_map(static fn ($o) => $o + ['received_at' => Clock::toIso($o['received_at'])], $ops),
            'cursor' => (int) $this->repo->value('SELECT COALESCE(MAX(seq),0) FROM change_log'),
        ];
    }
}
