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
use TurathManager\Repositories\BookingItemRepository;
use TurathManager\Repositories\BookingRepository;
use TurathManager\Support\Audit;
use TurathManager\Support\ChangeLog;
use TurathManager\Support\Sequence;

/**
 * نظام الحجوزات: عدة عناصر لكل حجز، مواعيد تركيب وفك لكل عنصر، كميات،
 * ومنع تجاوز المخزون وتداخل المواعيد داخل Transaction مع قفل صفوف المنتجات.
 */
final class BookingService
{
    public const STATUS_LABELS = [
        'new' => 'جديد', 'confirmed' => 'مؤكد', 'installed' => 'تم التركيب', 'removed' => 'تم الفك',
        'cancelled' => 'ملغي', 'conflict' => 'يحتاج معالجة تعارض', 'overdue' => 'متأخر', 'pending_sync' => 'بانتظار المزامنة',
    ];

    private BookingRepository $bookings;
    private BookingItemRepository $items;

    public function __construct(private readonly App $app)
    {
        $this->bookings = new BookingRepository($app->db());
        $this->items = new BookingItemRepository($app->db());
    }

    /**
     * @param array<string,mixed> $input
     * @param array{source?:string,device_id?:?string,client_created_at?:?string,record_conflict?:bool} $opts
     * @return array<string,mixed>
     */
    public function create(array $input, CurrentUser $actor, ?Request $req = null, array $opts = []): array
    {
        $id = isset($input['id']) && Uuid::isValid((string) $input['id']) ? strtolower((string) $input['id']) : Uuid::v4();
        if ($existing = $this->bookings->find($id)) {
            if ($existing['created_by'] !== $actor->id && !$actor->can('view_all_bookings')) {
                throw HttpException::conflict('معرف الحجز مستخدم مسبقاً');
            }
            return $this->get($id, $actor);
        }
        $d = $this->validateHeader($input, false);
        if (!$actor->canAccessCenter($d['center_id'])) {
            throw HttpException::forbidden('لا تملك صلاحية إنشاء حجوزات لهذا المركز');
        }
        $items = $this->validateItems($input['items'] ?? null, $d);
        $recordConflict = (bool) ($opts['record_conflict'] ?? false);

        $result = Database::transaction($this->app->db(), function () use ($id, $d, $items, $input, $actor, $opts, $recordConflict) {
            $this->lockProducts(array_column($items, 'product_id'));
            $customerId = $this->resolveCustomer($input, $actor);
            $shortages = $this->checkAvailability($items, null);
            $status = 'confirmed';
            if ($shortages) {
                if (!$recordConflict) {
                    throw HttpException::conflict($this->shortageMessage($shortages), ['shortages' => $shortages], 'availability_conflict');
                }
                $status = 'conflict';
            }
            [$install, $removal] = $this->bounds($items);
            $now = $this->app->clock->nowDb();
            $this->bookings->insert([
                'id' => $id,
                'booking_no' => $status === 'confirmed' ? $this->nextBookingNo() : null,
                'customer_id' => $customerId,
                'center_id' => $d['center_id'],
                'status' => $status,
                'install_at' => $install,
                'removal_at' => $removal,
                'address' => $d['address'] ?? null,
                'total_price' => $this->totalPrice($d, $items),
                'notes' => $d['notes'] ?? null,
                'source' => $opts['source'] ?? 'online',
                'origin_device_id' => $opts['device_id'] ?? null,
                'client_created_at' => $opts['client_created_at'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($items as $it) {
                $this->items->insert($it + [
                    'booking_id' => $id,
                    'status' => $status === 'confirmed' ? 'reserved' : 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->history($id, null, $status, $status === 'conflict' ? 'تعارض في التوفر عند المزامنة' : null, $actor->id);
            if ($shortages) {
                $this->recordConflict($id, $shortages, $opts['sync_operation_id'] ?? null);
            }
            $this->revision($id, 'إنشاء الحجز', $actor->id);
            $this->app->service(ChangeLog::class)->record('booking', $id);
            return ['status' => $status, 'shortages' => $shortages];
        });

        $this->app->service(Audit::class)->log($actor->id, 'booking.created', 'booking', $id, ['status' => $result['status'], 'source' => $opts['source'] ?? 'online'], $req);
        $this->notify($result['status'] === 'conflict' ? 'booking_conflict' : 'booking_created', $id, 'v1');
        $out = $this->get($id, $actor);
        $out['shortages'] = $result['shortages'];
        return $out;
    }

    /** @param array<string,mixed> $input */
    public function update(string $id, array $input, CurrentUser $actor, ?Request $req = null, ?int $baseVersion = null): array
    {
        $before = $this->load($id, $actor);
        if (in_array($before['status'], ['cancelled', 'removed'], true)) {
            throw HttpException::conflict('لا يمكن تعديل حجز ' . self::STATUS_LABELS[$before['status']]);
        }
        $d = $this->validateHeader($input + ['center_id' => $before['center_id'], 'install_at' => Clock::toIso($before['install_at']), 'removal_at' => Clock::toIso($before['removal_at'])], true);
        if ($d['center_id'] !== $before['center_id'] && !$actor->canAccessCenter($d['center_id'])) {
            throw HttpException::forbidden('لا تملك صلاحية نقل الحجز إلى هذا المركز');
        }
        $reason = isset($input['change_reason']) ? mb_substr(trim((string) $input['change_reason']), 0, 255) : null;

        $outcome = Database::transaction($this->app->db(), function () use ($id, $d, $input, $actor, $baseVersion, $before, $reason) {
            $current = $this->bookings->find($id, true);
            if ($baseVersion !== null && (int) $current['version'] !== $baseVersion) {
                throw HttpException::conflict('تم تعديل الحجز من جهاز آخر. راجع النسخة الأحدث', ['server' => $this->get($id, $actor)], 'version_conflict');
            }
            $existingItems = $this->items->all("SELECT * FROM booking_items WHERE booking_id = ? AND status <> 'cancelled'", [$id]);
            $byId = array_column($existingItems, null, 'id');
            $now = $this->app->clock->nowDb();

            if (array_key_exists('items', $input)) {
                $newItems = $this->validateItems($input['items'], $d);
                if ($current['status'] === 'installed') {
                    $this->assertInstalledChangeAllowed($byId, $newItems);
                }
            } else {
                // دون قائمة عناصر: تُطبق تواريخ الحجز الجديدة على العناصر التي كانت تتبع تواريخ الحجز
                $newItems = array_map(function ($it) use ($before, $d) {
                    $copy = array_intersect_key($it, array_flip(['id', 'product_id', 'quantity', 'unit_price', 'install_at', 'removal_at', 'notes']));
                    if ($it['status'] !== 'installed' && $it['install_at'] === $before['install_at']) {
                        $copy['install_at'] = $d['install_at'];
                    }
                    if ($it['removal_at'] === $before['removal_at']) {
                        $copy['removal_at'] = $d['removal_at'];
                    }
                    if ($copy['removal_at'] <= $copy['install_at']) {
                        throw HttpException::validation(['removal_at' => 'تاريخ الفك يجب أن يكون بعد تاريخ التركيب']);
                    }
                    return $copy;
                }, $existingItems);
            }

            $this->lockProducts(array_column($newItems, 'product_id'));
            $shortages = $this->checkAvailability($newItems, $id);
            if ($shortages) {
                throw HttpException::conflict($this->shortageMessage($shortages), ['shortages' => $shortages], 'availability_conflict');
            }

            $keep = [];
            foreach ($newItems as $it) {
                if (isset($byId[$it['id']])) {
                    $old = $byId[$it['id']];
                    $upd = array_intersect_key($it, array_flip(['product_id', 'quantity', 'unit_price', 'install_at', 'removal_at', 'notes']));
                    if ($old['status'] === 'pending' && $current['status'] === 'conflict') {
                        $upd['status'] = 'reserved';
                    }
                    $this->items->update($it['id'], $upd + ['updated_at' => $now]);
                    $keep[] = $it['id'];
                } else {
                    if ($this->items->find($it['id'])) {
                        $it['id'] = Uuid::v4();
                    }
                    $this->items->insert($it + ['booking_id' => $id, 'status' => $current['status'] === 'installed' ? 'reserved' : 'reserved', 'created_at' => $now, 'updated_at' => $now]);
                    $keep[] = $it['id'];
                }
            }
            foreach ($byId as $oldId => $old) {
                if (!in_array($oldId, $keep, true)) {
                    if ($old['status'] === 'installed') {
                        throw HttpException::conflict('لا يمكن حذف عنصر تم تركيبه قبل تسجيل فكه');
                    }
                    $this->items->update($oldId, ['status' => 'cancelled', 'updated_at' => $now]);
                }
            }

            [$install, $removal] = $this->bounds($newItems);
            $status = $current['status'];
            $data = [
                'center_id' => $d['center_id'],
                'install_at' => $install,
                'removal_at' => $removal,
                'updated_by' => $actor->id,
                'updated_at' => $now,
            ];
            foreach (['address', 'notes'] as $k) {
                if (array_key_exists($k, $d)) {
                    $data[$k] = $d[$k];
                }
            }
            if (array_key_exists('total_price', $d)) {
                $paid = (float) $this->bookings->value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id = ?', [$id]);
                if ($d['total_price'] !== null && (float) $d['total_price'] < $paid) {
                    throw HttpException::validation(['total_price' => 'السعر الإجمالي لا يمكن أن يقل عن المبلغ المدفوع (' . number_format($paid, 2) . ')']);
                }
                $data['total_price'] = $d['total_price'];
            }
            $resolved = false;
            if ($status === 'conflict') {
                $status = 'confirmed';
                $data['status'] = 'confirmed';
                $data['booking_no'] = $current['booking_no'] ?? $this->nextBookingNo();
                $this->items->exec("UPDATE booking_items SET status = 'reserved' WHERE booking_id = ? AND status = 'pending'", [$id]);
                $this->bookings->exec("UPDATE booking_conflicts SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_at = ? WHERE booking_id = ? AND status = 'open'", [
                    'تم تعديل الحجز وتأكيده', $actor->id, $now, $id,
                ]);
                $this->history($id, 'conflict', 'confirmed', $reason ?? 'معالجة التعارض', $actor->id);
                $resolved = true;
            }
            $this->bookings->update($id, $data);
            $this->revision($id, $reason ?? 'تعديل الحجز', $actor->id);
            $this->app->service(ChangeLog::class)->record('booking', $id);
            return ['resolved' => $resolved];
        });

        $after = $this->get($id, $actor);
        $this->app->service(Audit::class)->log($actor->id, 'booking.updated', 'booking', $id, [
            'reason' => $reason,
            'from' => ['install_at' => $before['install_at'], 'removal_at' => $before['removal_at']],
            'to' => ['install_at' => $after['install_at'], 'removal_at' => $after['removal_at']],
        ], $req);
        $this->notify($outcome['resolved'] ? 'booking_created' : 'booking_updated', $id, 'v' . $after['version']);
        return $after;
    }

    public function confirm(string $id, CurrentUser $actor, ?Request $req = null): array
    {
        $b = $this->load($id, $actor);
        if (!in_array($b['status'], ['conflict', 'new'], true)) {
            throw HttpException::conflict('الحجز ليس بحاجة إلى تأكيد');
        }
        return $this->update($id, ['change_reason' => 'إعادة محاولة التأكيد'], $actor, $req);
    }

    public function cancel(string $id, string $reason, CurrentUser $actor, ?Request $req = null): array
    {
        $b = $this->load($id, $actor);
        if (!in_array($b['status'], ['new', 'confirmed', 'conflict'], true)) {
            throw HttpException::conflict('لا يمكن إلغاء حجز ' . self::STATUS_LABELS[$b['status']] . ($b['status'] === 'installed' ? '. سجّل الفك أولاً' : ''));
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw HttpException::validation(['reason' => 'سبب الإلغاء مطلوب']);
        }
        Database::transaction($this->app->db(), function () use ($id, $b, $reason, $actor) {
            $now = $this->app->clock->nowDb();
            $this->bookings->update($id, ['status' => 'cancelled', 'cancelled_at' => $now, 'cancel_reason' => mb_substr($reason, 0, 255), 'updated_by' => $actor->id, 'updated_at' => $now]);
            $this->items->exec("UPDATE booking_items SET status = 'cancelled', updated_at = ? WHERE booking_id = ? AND status IN ('reserved','pending')", [$now, $id]);
            $this->bookings->exec("UPDATE booking_conflicts SET status = 'dismissed', resolution = ?, resolved_by = ?, resolved_at = ? WHERE booking_id = ? AND status = 'open'", ['إلغاء الحجز', $actor->id, $now, $id]);
            $this->history($id, $b['status'], 'cancelled', $reason, $actor->id);
            $this->revision($id, 'إلغاء الحجز: ' . mb_substr($reason, 0, 200), $actor->id);
            $this->app->service(ChangeLog::class)->record('booking', $id);
        });
        $this->app->service(Audit::class)->log($actor->id, 'booking.cancelled', 'booking', $id, ['reason' => $reason], $req);
        $this->notify('booking_cancelled', $id, 'cancel');
        return $this->get($id, $actor);
    }

    public function archive(string $id, bool $archive, CurrentUser $actor, ?Request $req = null): array
    {
        $b = $this->load($id, $actor);
        if ($archive && !in_array($b['status'], ['cancelled', 'removed'], true)) {
            throw HttpException::conflict('يمكن أرشفة الحجوزات الملغاة أو المنتهية فقط');
        }
        $this->bookings->update($id, ['archived_at' => $archive ? $this->app->clock->nowDb() : null, 'updated_at' => $this->app->clock->nowDb()]);
        $this->app->service(ChangeLog::class)->record('booking', $id);
        $this->app->service(Audit::class)->log($actor->id, $archive ? 'booking.archived' : 'booking.restored', 'booking', $id, [], $req);
        return $this->get($id, $actor);
    }

    /** @param array<string,mixed> $f */
    public function list(array $f, CurrentUser $actor, int $limit = 50, int $offset = 0): array
    {
        [$where, $p] = $this->filters($f, $actor);
        $w = implode(' AND ', $where);
        $total = (int) $this->bookings->value("SELECT COUNT(*) FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE $w", $p);
        $sort = ($f['sort'] ?? '') === 'newest' ? 'b.created_at DESC' : (($f['sort'] ?? '') === 'install_desc' ? 'b.install_at DESC' : 'b.install_at ASC');
        $rows = $this->bookings->all("SELECT b.*, c.full_name AS customer_name, c.phone AS customer_phone, ce.name AS center_name,
                (SELECT COALESCE(SUM(amount),0) FROM payments pa WHERE pa.booking_id = b.id) AS paid
            FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN centers ce ON ce.id = b.center_id
            WHERE $w ORDER BY $sort LIMIT $limit OFFSET $offset", $p);
        $ids = array_column($rows, 'id');
        $itemsBy = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->items->all("SELECT bi.booking_id, bi.quantity, bi.status, p.name, p.code, ca.name AS category_name, t.name AS type_name
                FROM booking_items bi JOIN products p ON p.id = bi.product_id JOIN categories ca ON ca.id = p.category_id LEFT JOIN category_types t ON t.id = p.category_type_id
                WHERE bi.booking_id IN ($in) AND bi.status <> 'cancelled'", $ids) as $it) {
                $itemsBy[$it['booking_id']][] = ['name' => $it['name'], 'code' => $it['code'], 'quantity' => (int) $it['quantity'], 'category_name' => $it['category_name'], 'type_name' => $it['type_name'], 'status' => $it['status']];
            }
        }
        return ['data' => array_map(fn ($r) => $this->summary($r) + ['items' => $itemsBy[$r['id']] ?? []], $rows), 'total' => $total];
    }

    /** @return array{0:list<string>,1:list<mixed>} */
    private function filters(array $f, CurrentUser $actor): array
    {
        $where = ['1=1'];
        $p = [];
        if (!$actor->can('view_all_bookings')) {
            if ($actor->centerIds) {
                $in = implode(',', array_fill(0, count($actor->centerIds), '?'));
                $where[] = "(b.center_id IN ($in) OR b.created_by = ?)";
                array_push($p, ...$actor->centerIds);
            } else {
                $where[] = 'b.created_by = ?';
            }
            $p[] = $actor->id;
        }
        $archived = $f['archived'] ?? '0';
        if ($archived === '1') {
            $where[] = 'b.archived_at IS NOT NULL';
        } elseif ($archived !== 'all') {
            $where[] = 'b.archived_at IS NULL';
        }
        if (!empty($f['search'])) {
            $like = BookingRepository::like((string) $f['search']);
            $where[] = '(b.booking_no LIKE ? OR c.full_name LIKE ? OR c.phone LIKE ? OR b.address LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        if (!empty($f['status'])) {
            if ($f['status'] === 'overdue') {
                $where[] = "((b.status = 'installed' AND b.removal_at < ?) OR (b.status = 'confirmed' AND b.install_at < ?))";
                array_push($p, $this->app->clock->nowDb(), $this->app->clock->nowDb());
            } elseif (isset(self::STATUS_LABELS[$f['status']])) {
                $where[] = 'b.status = ?';
                $p[] = $f['status'];
            }
        }
        if (!empty($f['center_id']) && Uuid::isValid((string) $f['center_id'])) {
            $where[] = 'b.center_id = ?';
            $p[] = $f['center_id'];
        }
        if (!empty($f['customer_id']) && Uuid::isValid((string) $f['customer_id'])) {
            $where[] = 'b.customer_id = ?';
            $p[] = $f['customer_id'];
        }
        if (!empty($f['category_id']) && Uuid::isValid((string) $f['category_id'])) {
            $where[] = "EXISTS (SELECT 1 FROM booking_items x JOIN products xp ON xp.id = x.product_id WHERE x.booking_id = b.id AND xp.category_id = ? AND x.status <> 'cancelled')";
            $p[] = $f['category_id'];
        }
        if (!empty($f['product_id']) && Uuid::isValid((string) $f['product_id'])) {
            $where[] = "EXISTS (SELECT 1 FROM booking_items x WHERE x.booking_id = b.id AND x.product_id = ? AND x.status <> 'cancelled')";
            $p[] = $f['product_id'];
        }
        $tz = $this->app->clock->localTz();
        if (!empty($f['date_from'])) {
            $where[] = 'b.removal_at >= ?';
            $p[] = $this->localDateToUtc((string) $f['date_from'], $tz);
        }
        if (!empty($f['date_to'])) {
            $where[] = 'b.install_at < ?';
            $p[] = $this->localDateToUtc((string) $f['date_to'], $tz, true);
        }
        return [$where, $p];
    }

    private function localDateToUtc(string $date, \DateTimeZone $tz, bool $endOfDay = false): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if ($d === false) {
            throw HttpException::validation(['date' => 'صيغة التاريخ يجب أن تكون YYYY-MM-DD']);
        }
        if ($endOfDay) {
            $d = $d->modify('+1 day');
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function get(string $id, CurrentUser $actor): array
    {
        $b = $this->load($id, $actor);
        $row = $this->bookings->one('SELECT b.*, c.full_name AS customer_name, c.phone AS customer_phone, c.address AS customer_address, ce.name AS center_name,
                (SELECT COALESCE(SUM(amount),0) FROM payments pa WHERE pa.booking_id = b.id) AS paid,
                u.full_name AS created_by_name
            FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN centers ce ON ce.id = b.center_id LEFT JOIN users u ON u.id = b.created_by WHERE b.id = ?', [$b['id']]);
        $o = $this->summary($row);
        $o['customer'] = ['id' => $row['customer_id'], 'full_name' => $row['customer_name'], 'phone' => $row['customer_phone'], 'address' => $row['customer_address']];
        $o['center'] = $this->app->service(CenterService::class)->get($row['center_id']);
        $o['created_by_name'] = $row['created_by_name'];
        $o['items'] = array_map(fn ($it) => [
            'id' => $it['id'], 'product_id' => $it['product_id'], 'product_name' => $it['product_name'], 'product_code' => $it['product_code'],
            'category_name' => $it['category_name'], 'type_name' => $it['type_name'], 'quantity' => (int) $it['quantity'],
            'unit_price' => $it['unit_price'], 'install_at' => Clock::toIso($it['install_at']), 'removal_at' => Clock::toIso($it['removal_at']),
            'status' => $it['status'], 'notes' => $it['notes'],
            'installed_at' => Clock::toIso($it['installed_at']), 'removed_at' => Clock::toIso($it['removed_at']),
            'product_image' => $it['product_image'] ? 'media/' . $it['product_image'] : null,
            'images' => array_map(static fn ($i) => ['id' => $i['id'], 'url' => 'media/' . $i['file_path']], $this->items->all('SELECT id, file_path FROM booking_item_images WHERE booking_item_id = ? AND deleted_at IS NULL ORDER BY created_at', [$it['id']])),
        ], $this->items->all("SELECT bi.*, p.name AS product_name, p.code AS product_code, ca.name AS category_name, t.name AS type_name,
                (SELECT file_path FROM product_images i WHERE i.product_id = p.id AND i.deleted_at IS NULL ORDER BY i.is_primary DESC, i.sort_order LIMIT 1) AS product_image
            FROM booking_items bi JOIN products p ON p.id = bi.product_id JOIN categories ca ON ca.id = p.category_id LEFT JOIN category_types t ON t.id = p.category_type_id
            WHERE bi.booking_id = ? ORDER BY bi.created_at, bi.id", [$id]));
        $o['history'] = array_map(static fn ($h) => [
            'from_status' => $h['from_status'], 'to_status' => $h['to_status'], 'reason' => $h['reason'],
            'changed_by_name' => $h['changed_by_name'], 'changed_at' => Clock::toIso($h['changed_at']),
        ], $this->bookings->all('SELECT h.*, u.full_name AS changed_by_name FROM booking_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.booking_id = ? ORDER BY h.id', [$id]));
        $o['revisions'] = array_map(static fn ($r) => [
            'revision' => (int) $r['revision'], 'change_summary' => $r['change_summary'], 'changed_by_name' => $r['changed_by_name'],
            'created_at' => Clock::toIso($r['created_at']), 'snapshot' => json_decode($r['snapshot'], true),
        ], $this->bookings->all('SELECT r.*, u.full_name AS changed_by_name FROM booking_revisions r LEFT JOIN users u ON u.id = r.changed_by WHERE r.booking_id = ? ORDER BY r.revision', [$id]));
        $o['conflicts'] = array_map(static fn ($c) => [
            'id' => $c['id'], 'conflict_type' => $c['conflict_type'], 'status' => $c['status'], 'details' => json_decode((string) $c['details'], true),
            'resolution' => $c['resolution'], 'created_at' => Clock::toIso($c['created_at']), 'resolved_at' => Clock::toIso($c['resolved_at']),
        ], $this->bookings->all('SELECT * FROM booking_conflicts WHERE booking_id = ? ORDER BY created_at', [$id]));
        $o['handovers'] = $this->app->service(HandoverService::class)->forBooking($id);
        $o['payments'] = $this->app->service(PaymentService::class)->forBooking($id);
        return $o;
    }

    /** تحميل الحجز مع التحقق من صلاحية الوصول إلى مركزه */
    public function load(string $id, CurrentUser $actor): array
    {
        $b = $this->bookings->find($id) ?? throw HttpException::notFound('الحجز غير موجود');
        if (!$actor->canAccessCenter($b['center_id']) && $b['created_by'] !== $actor->id) {
            throw HttpException::notFound('الحجز غير موجود');
        }
        return $b;
    }

    private function summary(array $r): array
    {
        $now = $this->app->clock->nowDb();
        $overdue = ($r['status'] === 'installed' && $r['removal_at'] < $now) || ($r['status'] === 'confirmed' && $r['install_at'] < $now);
        $total = $r['total_price'];
        $paid = number_format((float) ($r['paid'] ?? 0), 2, '.', '');
        return [
            'id' => $r['id'], 'booking_no' => $r['booking_no'], 'status' => $r['status'], 'status_label' => self::STATUS_LABELS[$r['status']],
            'is_overdue' => $overdue, 'customer_id' => $r['customer_id'], 'customer_name' => $r['customer_name'] ?? null,
            'customer_phone' => $r['customer_phone'] ?? null, 'center_id' => $r['center_id'], 'center_name' => $r['center_name'] ?? null,
            'install_at' => Clock::toIso($r['install_at']), 'removal_at' => Clock::toIso($r['removal_at']),
            'address' => $r['address'], 'total_price' => $total, 'paid' => $paid,
            'remaining' => $total === null ? null : number_format((float) $total - (float) $paid, 2, '.', ''),
            'notes' => $r['notes'], 'source' => $r['source'], 'cancel_reason' => $r['cancel_reason'],
            'archived' => $r['archived_at'] !== null, 'version' => (int) $r['version'],
            'created_at' => Clock::toIso($r['created_at']), 'updated_at' => Clock::toIso($r['updated_at']),
        ];
    }

    private function validateHeader(array $input, bool $partial): array
    {
        $d = $this->app->validator->validate($input, [
            'center_id' => 'required|uuid',
            'customer_id' => 'nullable|uuid',
            'install_at' => 'required|datetime',
            'removal_at' => 'required|datetime',
            'address' => 'nullable|string|max:500',
            'total_price' => 'nullable|decimal|min:0',
            'notes' => 'nullable|string|max:5000',
        ], ['center_id' => 'المركز', 'install_at' => 'تاريخ التركيب', 'removal_at' => 'تاريخ الفك النهائي', 'total_price' => 'السعر', 'address' => 'العنوان']);
        if (!$partial) {
            foreach (['address', 'total_price', 'notes'] as $k) {
                $d[$k] ??= null;
            }
        } else {
            foreach (['address', 'total_price', 'notes'] as $k) {
                if (!array_key_exists($k, $input)) {
                    unset($d[$k]);
                }
            }
        }
        if ($d['removal_at'] <= $d['install_at']) {
            throw HttpException::validation(['removal_at' => 'تاريخ الفك يجب أن يكون بعد تاريخ التركيب']);
        }
        $center = $this->bookings->one('SELECT id, status, archived_at FROM centers WHERE id = ?', [$d['center_id']]);
        if (!$center || $center['archived_at'] !== null || $center['status'] !== 'active') {
            throw HttpException::validation(['center_id' => 'المركز غير موجود أو غير نشط']);
        }
        return $d;
    }

    /** @return list<array<string,mixed>> */
    private function validateItems(mixed $raw, array $header): array
    {
        if (!is_array($raw) || $raw === []) {
            throw HttpException::validation(['items' => 'يجب إضافة عنصر واحد على الأقل للحجز']);
        }
        if (count($raw) > 50) {
            throw HttpException::validation(['items' => 'عدد العناصر كبير جداً']);
        }
        $out = [];
        foreach (array_values($raw) as $i => $it) {
            if (!is_array($it)) {
                throw HttpException::validation(["items.$i" => 'عنصر غير صالح']);
            }
            try {
                $d = $this->app->validator->validate($it, [
                    'id' => 'nullable|uuid',
                    'product_id' => 'required|uuid',
                    'quantity' => 'required|int|min:1|max:1000',
                    'unit_price' => 'nullable|decimal|min:0',
                    'install_at' => 'nullable|datetime',
                    'removal_at' => 'nullable|datetime',
                    'notes' => 'nullable|string|max:2000',
                ], ['product_id' => 'الموديل', 'quantity' => 'الكمية', 'unit_price' => 'سعر الوحدة', 'install_at' => 'تاريخ التركيب', 'removal_at' => 'تاريخ الفك']);
            } catch (HttpException $e) {
                $errors = [];
                foreach ($e->details['errors'] ?? [] as $k => $v) {
                    $errors["items.$i.$k"] = 'العنصر ' . ($i + 1) . ': ' . $v;
                }
                throw HttpException::validation($errors);
            }
            $d['install_at'] ??= $header['install_at'];
            $d['removal_at'] ??= $header['removal_at'];
            if ($d['removal_at'] <= $d['install_at']) {
                throw HttpException::validation(["items.$i.removal_at" => 'العنصر ' . ($i + 1) . ': تاريخ الفك يجب أن يكون بعد التركيب']);
            }
            $p = $this->bookings->one('SELECT id, status, archived_at, price FROM products WHERE id = ?', [$d['product_id']]);
            if (!$p || $p['archived_at'] !== null || $p['status'] !== 'active') {
                throw HttpException::validation(["items.$i.product_id" => 'العنصر ' . ($i + 1) . ': الموديل غير موجود أو غير متاح']);
            }
            $out[] = [
                'id' => $d['id'] ?? Uuid::v4(),
                'product_id' => $d['product_id'],
                'quantity' => $d['quantity'],
                'unit_price' => $d['unit_price'] ?? null,
                'install_at' => $d['install_at'],
                'removal_at' => $d['removal_at'],
                'notes' => $d['notes'] ?? null,
            ];
        }
        return $out;
    }

    private function assertInstalledChangeAllowed(array $byId, array $newItems): void
    {
        $newById = array_column($newItems, null, 'id');
        foreach ($byId as $id => $old) {
            if ($old['status'] !== 'installed') {
                continue;
            }
            $n = $newById[$id] ?? null;
            if (!$n || $n['product_id'] !== $old['product_id'] || (int) $n['quantity'] !== (int) $old['quantity'] || $n['install_at'] !== $old['install_at']) {
                throw HttpException::conflict('العناصر المركّبة يمكن تعديل موعد فكها وملاحظاتها فقط');
            }
        }
    }

    /** @param list<string> $productIds */
    private function lockProducts(array $productIds): void
    {
        $ids = array_values(array_unique($productIds));
        sort($ids);
        if (!$ids) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->bookings->all("SELECT id FROM products WHERE id IN ($in) ORDER BY id FOR UPDATE", $ids);
    }

    /** @return list<array<string,mixed>> */
    private function checkAvailability(array $items, ?string $excludeBookingId): array
    {
        $avail = $this->app->service(AvailabilityService::class);
        $byProduct = [];
        foreach ($items as $it) {
            $byProduct[$it['product_id']][] = [$it['install_at'], $it['removal_at'], (int) $it['quantity']];
        }
        $shortages = [];
        foreach ($byProduct as $pid => $intervals) {
            $p = $this->bookings->one('SELECT name, code, quantity_total FROM products WHERE id = ?', [$pid]);
            $peak = $avail->peakWithNew($pid, $intervals, $excludeBookingId);
            if ($peak > (int) $p['quantity_total']) {
                $requested = array_sum(array_column($intervals, 2));
                $shortages[] = [
                    'product_id' => $pid, 'product_name' => $p['name'], 'product_code' => $p['code'],
                    'requested' => $requested, 'total' => (int) $p['quantity_total'],
                    'available' => max(0, (int) $p['quantity_total'] - ($peak - $requested)),
                ];
            }
        }
        return $shortages;
    }

    private function shortageMessage(array $shortages): string
    {
        $parts = array_map(static fn ($s) => "{$s['product_name']} ({$s['product_code']}): المطلوب {$s['requested']} والمتاح {$s['available']}", $shortages);
        return 'الكمية غير متوفرة في الفترة المحددة - ' . implode('، ', $parts);
    }

    private function resolveCustomer(array $input, CurrentUser $actor): string
    {
        if (!empty($input['customer_id'])) {
            $id = strtolower((string) $input['customer_id']);
            if ($this->bookings->value('SELECT id FROM customers WHERE id = ?', [$id])) {
                return $id;
            }
            if (empty($input['customer']) || !is_array($input['customer'])) {
                throw HttpException::validation(['customer_id' => 'العميل غير موجود']);
            }
            return $this->app->service(CustomerService::class)->findOrCreate($input['customer'] + ['id' => $id], $actor);
        }
        if (empty($input['customer']) || !is_array($input['customer'])) {
            throw HttpException::validation(['customer' => 'بيانات العميل مطلوبة']);
        }
        return $this->app->service(CustomerService::class)->findOrCreate($input['customer'], $actor);
    }

    /** @return array{0:string,1:string} */
    private function bounds(array $items): array
    {
        return [min(array_column($items, 'install_at')), max(array_column($items, 'removal_at'))];
    }

    private function totalPrice(array $d, array $items): ?string
    {
        if ($d['total_price'] !== null) {
            return $d['total_price'];
        }
        $priced = array_filter($items, static fn ($i) => $i['unit_price'] !== null);
        if (!$priced) {
            return null;
        }
        $sum = 0.0;
        foreach ($priced as $i) {
            $sum += (float) $i['unit_price'] * (int) $i['quantity'];
        }
        return number_format($sum, 2, '.', '');
    }

    private function nextBookingNo(): string
    {
        $year = $this->app->clock->now()->setTimezone($this->app->clock->localTz())->format('Y');
        return sprintf('TM-%s-%05d', $year, Sequence::next($this->app->db(), 'booking', $year));
    }

    public function history(string $bookingId, ?string $from, string $to, ?string $reason, ?string $userId): void
    {
        $this->bookings->exec('INSERT INTO booking_status_history (booking_id, from_status, to_status, reason, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, ?)', [
            $bookingId, $from, $to, $reason, $userId, $this->app->clock->nowDb(),
        ]);
    }

    public function revision(string $bookingId, string $summary, ?string $userId): void
    {
        $b = $this->bookings->find($bookingId);
        $items = $this->items->all('SELECT id, product_id, quantity, unit_price, install_at, removal_at, status, notes FROM booking_items WHERE booking_id = ? ORDER BY created_at, id', [$bookingId]);
        $rev = (int) $this->bookings->value('SELECT COALESCE(MAX(revision),0) + 1 FROM booking_revisions WHERE booking_id = ?', [$bookingId]);
        $snapshot = array_intersect_key($b, array_flip(['status', 'center_id', 'customer_id', 'install_at', 'removal_at', 'address', 'total_price', 'notes'])) + ['items' => $items];
        $this->bookings->exec('INSERT INTO booking_revisions (booking_id, revision, change_summary, snapshot, changed_by, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
            $bookingId, $rev, mb_substr($summary, 0, 255), json_encode($snapshot, JSON_UNESCAPED_UNICODE), $userId, $this->app->clock->nowDb(),
        ]);
    }

    private function recordConflict(string $bookingId, array $shortages, ?string $syncOpId): void
    {
        $this->bookings->exec('INSERT INTO booking_conflicts (id, booking_id, sync_operation_id, conflict_type, details, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            Uuid::v4(), $bookingId, $syncOpId, 'stock', json_encode(['shortages' => $shortages], JSON_UNESCAPED_UNICODE), 'open', $this->app->clock->nowDb(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function openConflicts(CurrentUser $actor): array
    {
        $rows = $this->bookings->all("SELECT bc.*, b.center_id, b.created_by, c.full_name AS customer_name FROM booking_conflicts bc
            LEFT JOIN bookings b ON b.id = bc.booking_id LEFT JOIN customers c ON c.id = b.customer_id
            WHERE bc.status = 'open' ORDER BY bc.created_at DESC");
        $rows = array_filter($rows, static fn ($r) => $r['center_id'] === null || $actor->canAccessCenter($r['center_id']) || $r['created_by'] === $actor->id);
        return array_values(array_map(static fn ($r) => [
            'id' => $r['id'], 'booking_id' => $r['booking_id'], 'customer_name' => $r['customer_name'], 'conflict_type' => $r['conflict_type'],
            'details' => json_decode((string) $r['details'], true), 'created_at' => Clock::toIso($r['created_at']),
        ], $rows));
    }

    private function notify(string $type, string $bookingId, string $variant): void
    {
        try {
            $this->app->service(NotificationService::class)->bookingEvent($type, $bookingId, $variant);
        } catch (\Throwable $e) {
            $this->app->logger->error('Notification dispatch failed', ['type' => $type, 'booking' => $bookingId, 'error' => $e->getMessage()]);
        }
    }

    public function addItemImage(string $itemId, array $file, CurrentUser $actor, ?string $imageId = null): array
    {
        $item = $this->items->find($itemId) ?? throw HttpException::notFound('العنصر غير موجود');
        $this->load($item['booking_id'], $actor);
        if ($imageId !== null && $this->items->value('SELECT 1 FROM booking_item_images WHERE id = ?', [strtolower($imageId)])) {
            return $this->get($item['booking_id'], $actor);
        }
        $stored = $this->app->service(\TurathManager\Support\UploadService::class)->storeImage($file, 'bookings', $imageId ? strtolower($imageId) : null);
        $id = basename($stored['path'], '.' . pathinfo($stored['path'], PATHINFO_EXTENSION));
        $this->items->exec('INSERT INTO booking_item_images (id, booking_item_id, file_path, mime, size_bytes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $id, $itemId, $stored['path'], $stored['mime'], $stored['size'], $actor->id, $this->app->clock->nowDb(),
        ]);
        $this->bookings->update($item['booking_id'], ['updated_at' => $this->app->clock->nowDb()]);
        $this->app->service(ChangeLog::class)->record('booking', $item['booking_id']);
        return $this->get($item['booking_id'], $actor);
    }
}
