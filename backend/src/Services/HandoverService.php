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
use TurathManager\Support\Audit;
use TurathManager\Support\ChangeLog;
use TurathManager\Support\UploadService;

/**
 * التسليم (عند التركيب) والاستلام (عند الفك) مع تقرير حالة القطعة قبل التسليم وبعد الاستلام.
 */
final class HandoverService
{
    public const CONDITIONS = ['excellent' => 'ممتازة', 'good' => 'جيدة', 'fair' => 'مقبولة', 'damaged' => 'متضررة', 'missing' => 'مفقودة'];

    private Generic $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new Generic($app->db());
    }

    /** @param array<string,mixed> $input */
    public function record(string $itemId, string $type, array $input, CurrentUser $actor, ?Request $req = null): array
    {
        if (!in_array($type, ['delivery', 'return'], true)) {
            throw HttpException::badRequest('نوع العملية غير صالح');
        }
        $d = $this->app->validator->validate($input, [
            'id' => 'nullable|uuid',
            'occurred_at' => 'nullable|datetime',
            'delivered_by_name' => 'required|string|max:150',
            'received_by_name' => 'required|string|max:150',
            'item_condition' => 'required|in:' . implode(',', array_keys(self::CONDITIONS)),
            'damaged_quantity' => 'nullable|int|min:0|max:1000',
            'notes' => 'nullable|string|max:5000',
            'signature_data' => 'nullable|string|max:400000',
            'early_reason' => 'nullable|string|max:255',
        ], [
            'delivered_by_name' => 'اسم المسلّم', 'received_by_name' => 'اسم المستلم', 'item_condition' => 'حالة القطعة',
            'damaged_quantity' => 'الكمية المتضررة', 'occurred_at' => 'التاريخ والوقت', 'early_reason' => 'سبب الفك المبكر',
        ]);
        if (!empty($d['signature_data']) && !preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $d['signature_data'])) {
            throw HttpException::validation(['signature_data' => 'صيغة التوقيع غير صالحة']);
        }
        $item = $this->repo->one('SELECT * FROM booking_items WHERE id = ?', [$itemId]) ?? throw HttpException::notFound('عنصر الحجز غير موجود');
        $bookings = $this->app->service(BookingService::class);
        $booking = $bookings->load($item['booking_id'], $actor);

        $id = $d['id'] ?? Uuid::v4();
        if ($this->repo->value('SELECT 1 FROM handovers WHERE id = ?', [$id])) {
            return $this->get($id);
        }
        $existing = $this->repo->value('SELECT id FROM handovers WHERE booking_item_id = ? AND handover_type = ?', [$itemId, $type]);
        if ($existing) {
            throw HttpException::conflict($type === 'delivery' ? 'تم تسجيل تسليم هذا العنصر مسبقاً' : 'تم تسجيل استلام هذا العنصر مسبقاً', ['handover_id' => $existing], 'already_recorded');
        }
        $occurred = $d['occurred_at'] ?? $this->app->clock->nowDb();
        $damaged = (int) ($d['damaged_quantity'] ?? 0);
        if ($damaged > (int) $item['quantity']) {
            throw HttpException::validation(['damaged_quantity' => 'الكمية المتضررة أكبر من كمية العنصر']);
        }
        if (in_array($d['item_condition'], ['damaged', 'missing'], true) && $damaged === 0 && $type === 'return') {
            $damaged = (int) $item['quantity'];
        }

        if ($type === 'delivery') {
            if ($item['status'] !== 'reserved' || $booking['status'] !== 'confirmed' && $booking['status'] !== 'installed') {
                throw HttpException::conflict('لا يمكن تسجيل تركيب لعنصر حالته: ' . $item['status']);
            }
        } else {
            if ($item['status'] !== 'installed') {
                throw HttpException::conflict('لا يمكن تسجيل فك عنصر لم يُسجل تركيبه');
            }
            if ($occurred < $item['removal_at']) {
                if (!$actor->can('force_early_removal')) {
                    throw HttpException::forbidden('تسجيل الفك قبل موعده (' . Clock::toIso($item['removal_at']) . ') يتطلب صلاحية خاصة');
                }
                if (empty($d['early_reason'])) {
                    throw HttpException::validation(['early_reason' => 'يجب توثيق سبب الفك قبل الموعد']);
                }
            }
        }

        Database::transaction($this->app->db(), function () use ($id, $type, $d, $item, $booking, $occurred, $damaged, $actor, $bookings) {
            $now = $this->app->clock->nowDb();
            $this->repo->exec('INSERT INTO handovers (id, booking_id, booking_item_id, handover_type, occurred_at, delivered_by_name, received_by_name, item_condition, notes, signature_data, early_reason, recorded_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $id, $item['booking_id'], $item['id'], $type, $occurred, $d['delivered_by_name'], $d['received_by_name'], $d['item_condition'],
                $d['notes'] ?? null, $d['signature_data'] ?? null, $type === 'return' ? ($d['early_reason'] ?? null) : null, $actor->id, $now,
            ]);
            $this->repo->exec('INSERT INTO item_condition_reports (id, handover_id, booking_item_id, product_id, phase, item_condition, damaged_quantity, damage_notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                Uuid::v4(), $id, $item['id'], $item['product_id'], $type === 'delivery' ? 'before_delivery' : 'after_return', $d['item_condition'],
                $type === 'return' ? $damaged : 0, $d['notes'] ?? null, $actor->id, $now,
            ]);
            if ($type === 'delivery') {
                $this->repo->exec("UPDATE booking_items SET status = 'installed', installed_at = ?, updated_at = ? WHERE id = ?", [$occurred, $now, $item['id']]);
            } else {
                $this->repo->exec("UPDATE booking_items SET status = 'removed', removed_at = ?, updated_at = ? WHERE id = ?", [$occurred, $now, $item['id']]);
                if ($damaged > 0) {
                    $this->app->service(ProductService::class)->insertMovement(
                        $item['product_id'],
                        $d['item_condition'] === 'missing' ? 'loss' : 'damage',
                        -$damaged,
                        'عند الاستلام - حجز ' . ($booking['booking_no'] ?? $booking['id']),
                        $actor->id,
                        'handover',
                        $id
                    );
                    $this->app->service(ChangeLog::class)->record('product', $item['product_id']);
                }
            }
            $counts = $this->repo->one("SELECT SUM(status = 'reserved') AS reserved, SUM(status = 'installed') AS installed, SUM(status = 'removed') AS removed
                FROM booking_items WHERE booking_id = ? AND status <> 'cancelled'", [$item['booking_id']]);
            $newStatus = $booking['status'];
            if ((int) $counts['reserved'] === 0 && (int) $counts['installed'] === 0 && (int) $counts['removed'] > 0) {
                $newStatus = 'removed';
            } elseif ((int) $counts['installed'] > 0 || (int) $counts['removed'] > 0) {
                $newStatus = 'installed';
            }
            if ($newStatus !== $booking['status']) {
                $this->repo->exec('UPDATE bookings SET status = ?, updated_by = ?, updated_at = ?, version = version + 1 WHERE id = ?', [$newStatus, $actor->id, $now, $booking['id']]);
                $bookings->history($booking['id'], $booking['status'], $newStatus, $type === 'delivery' ? 'تسجيل تركيب' : 'تسجيل فك', $actor->id);
            } else {
                $this->repo->exec('UPDATE bookings SET updated_at = ?, version = version + 1 WHERE id = ?', [$now, $booking['id']]);
            }
            $this->app->service(ChangeLog::class)->record('booking', $booking['id']);
        });
        $this->app->service(Audit::class)->log($actor->id, 'handover.' . $type, 'booking', $item['booking_id'], ['handover_id' => $id, 'item_id' => $itemId, 'condition' => $d['item_condition'], 'damaged' => $damaged, 'early_reason' => $d['early_reason'] ?? null], $req);
        return $this->get($id);
    }

    public function get(string $id): array
    {
        $h = $this->repo->one('SELECT h.*, u.full_name AS recorded_by_name, p.name AS product_name, p.code AS product_code, bi.quantity, b.booking_no,
                r.damaged_quantity
            FROM handovers h JOIN booking_items bi ON bi.id = h.booking_item_id JOIN products p ON p.id = bi.product_id JOIN bookings b ON b.id = h.booking_id
            LEFT JOIN users u ON u.id = h.recorded_by LEFT JOIN item_condition_reports r ON r.handover_id = h.id WHERE h.id = ?', [$id]) ?? throw HttpException::notFound('العملية غير موجودة');
        return self::out($h) + ['images' => $this->images($id)];
    }

    public function forBooking(string $bookingId): array
    {
        $rows = $this->repo->all('SELECT h.*, u.full_name AS recorded_by_name, p.name AS product_name, p.code AS product_code, bi.quantity, b.booking_no, r.damaged_quantity
            FROM handovers h JOIN booking_items bi ON bi.id = h.booking_item_id JOIN products p ON p.id = bi.product_id JOIN bookings b ON b.id = h.booking_id
            LEFT JOIN users u ON u.id = h.recorded_by LEFT JOIN item_condition_reports r ON r.handover_id = h.id WHERE h.booking_id = ? ORDER BY h.occurred_at, h.handover_type', [$bookingId]);
        return array_map(fn ($h) => self::out($h) + ['images' => $this->images($h['id'])], $rows);
    }

    private function images(string $handoverId): array
    {
        return array_map(static fn ($i) => ['id' => $i['id'], 'url' => 'media/' . $i['file_path']], $this->repo->all('SELECT id, file_path FROM handover_images WHERE handover_id = ? ORDER BY created_at', [$handoverId]));
    }

    public function addImage(string $handoverId, array $file, CurrentUser $actor, ?string $imageId = null): array
    {
        $h = $this->repo->one('SELECT booking_id FROM handovers WHERE id = ?', [$handoverId]) ?? throw HttpException::notFound('العملية غير موجودة');
        $this->app->service(BookingService::class)->load($h['booking_id'], $actor);
        if ($imageId !== null && $this->repo->value('SELECT 1 FROM handover_images WHERE id = ?', [strtolower($imageId)])) {
            return $this->get($handoverId);
        }
        $stored = $this->app->service(UploadService::class)->storeImage($file, 'handovers', $imageId ? strtolower($imageId) : null);
        $id = basename($stored['path'], '.' . pathinfo($stored['path'], PATHINFO_EXTENSION));
        $this->repo->exec('INSERT INTO handover_images (id, handover_id, file_path, mime, size_bytes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $id, $handoverId, $stored['path'], $stored['mime'], $stored['size'], $actor->id, $this->app->clock->nowDb(),
        ]);
        $this->app->service(ChangeLog::class)->record('booking', $h['booking_id']);
        return $this->get($handoverId);
    }

    private static function out(array $h): array
    {
        return [
            'id' => $h['id'], 'booking_id' => $h['booking_id'], 'booking_no' => $h['booking_no'], 'booking_item_id' => $h['booking_item_id'],
            'handover_type' => $h['handover_type'], 'type_label' => $h['handover_type'] === 'delivery' ? 'تسليم (تركيب)' : 'استلام (فك)',
            'occurred_at' => Clock::toIso($h['occurred_at']), 'delivered_by_name' => $h['delivered_by_name'], 'received_by_name' => $h['received_by_name'],
            'item_condition' => $h['item_condition'], 'condition_label' => self::CONDITIONS[$h['item_condition']] ?? $h['item_condition'],
            'damaged_quantity' => (int) ($h['damaged_quantity'] ?? 0), 'notes' => $h['notes'], 'has_signature' => !empty($h['signature_data']),
            'signature_data' => $h['signature_data'], 'early_reason' => $h['early_reason'],
            'product_name' => $h['product_name'], 'product_code' => $h['product_code'], 'quantity' => (int) $h['quantity'],
            'recorded_by_name' => $h['recorded_by_name'], 'created_at' => Clock::toIso($h['created_at']),
        ];
    }
}
