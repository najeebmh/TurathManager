<?php

declare(strict_types=1);

namespace TurathManager\Services;

use TurathManager\Core\App;
use TurathManager\Repositories\Generic;

/**
 * حساب توفر القطع لفترة زمنية.
 * المحجوز = أقصى كمية متزامنة (Peak) للعناصر النشطة المتداخلة مع الفترة،
 * وليس مجموعها، حتى لا تُحسب حجوزات متتالية غير متداخلة كأنها متزامنة.
 * العنصر المركّب الذي لم يُفك بعد يبقى محجوزاً حتى فكه فعلياً ولو تجاوز موعده.
 */
final class AvailabilityService
{
    private Generic $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new Generic($app->db());
    }

    public function peakReserved(string $productId, string $start, ?string $end, ?string $excludeBookingId = null): int
    {
        $now = $this->app->clock->nowDb();
        $end ??= '9999-12-31 00:00:00';
        $rows = $this->repo->all("SELECT bi.quantity, bi.install_at,
                CASE WHEN bi.status = 'installed' AND bi.removal_at < ? THEN ? ELSE bi.removal_at END AS eff_end
            FROM booking_items bi
            JOIN bookings b ON b.id = bi.booking_id
            WHERE bi.product_id = ?
              AND bi.status IN ('reserved','installed')
              AND b.status IN ('confirmed','installed')
              AND bi.install_at < ?
              AND (CASE WHEN bi.status = 'installed' AND bi.removal_at < ? THEN ? ELSE bi.removal_at END) > ?
              AND b.id <> ?", [$now, '9999-12-31 00:00:00', $productId, $end, $now, '9999-12-31 00:00:00', $start, $excludeBookingId ?? '']);
        if (!$rows) {
            return 0;
        }
        $events = [];
        foreach ($rows as $r) {
            $s = max($r['install_at'], $start);
            $e = min($r['eff_end'], $end);
            $events[] = [$s, 1, (int) $r['quantity']];
            $events[] = [$e, 0, (int) $r['quantity']];
        }
        // عند تساوي الوقت تُعالج النهايات قبل البدايات (الفترات نصف مفتوحة [بداية، نهاية))
        usort($events, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $cur = 0;
        $peak = 0;
        foreach ($events as [, $isStart, $q]) {
            $cur += $isStart ? $q : -$q;
            $peak = max($peak, $cur);
        }
        return $peak;
    }

    public function available(string $productId, string $start, string $end, ?string $excludeBookingId = null): int
    {
        $total = (int) $this->repo->value('SELECT quantity_total FROM products WHERE id = ?', [$productId]);
        return $total - $this->peakReserved($productId, $start, $end, $excludeBookingId);
    }

    /**
     * أقصى كمية متزامنة أثناء فترات العناصر الجديدة، شاملة الحجوزات القائمة والعناصر الجديدة نفسها.
     * @param list<array{0:string,1:string,2:int}> $intervals [بداية، نهاية، كمية]
     */
    public function peakWithNew(string $productId, array $intervals, ?string $excludeBookingId = null): int
    {
        if (!$intervals) {
            return 0;
        }
        $start = min(array_column($intervals, 0));
        $end = max(array_column($intervals, 1));
        $now = $this->app->clock->nowDb();
        $far = '9999-12-31 00:00:00';
        $rows = $this->repo->all("SELECT bi.quantity, bi.install_at,
                CASE WHEN bi.status = 'installed' AND bi.removal_at < ? THEN ? ELSE bi.removal_at END AS eff_end
            FROM booking_items bi JOIN bookings b ON b.id = bi.booking_id
            WHERE bi.product_id = ? AND bi.status IN ('reserved','installed') AND b.status IN ('confirmed','installed')
              AND bi.install_at < ? AND (CASE WHEN bi.status = 'installed' AND bi.removal_at < ? THEN ? ELSE bi.removal_at END) > ?
              AND b.id <> ?", [$now, $far, $productId, $end, $now, $far, $start, $excludeBookingId ?? '']);
        $events = [];
        foreach ($rows as $r) {
            $events[] = [$r['install_at'], 1, (int) $r['quantity'], false];
            $events[] = [$r['eff_end'], 0, (int) $r['quantity'], false];
        }
        foreach ($intervals as [$s, $e, $q]) {
            $events[] = [$s, 1, (int) $q, true];
            $events[] = [$e, 0, (int) $q, true];
        }
        usort($events, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $cur = 0;
        $newActive = 0;
        $peak = 0;
        foreach ($events as [, $isStart, $q, $isNew]) {
            $cur += $isStart ? $q : -$q;
            if ($isNew) {
                $newActive += $isStart ? 1 : -1;
            }
            if ($newActive > 0) {
                $peak = max($peak, $cur);
            }
        }
        return $peak;
    }

    /** الكمية المحجوزة حالياً (خارج المحل الآن أو محجوزة لهذه اللحظة) */
    public function reservedNow(string $productId): int
    {
        $now = $this->app->clock->nowDb();
        $next = $this->app->clock->now()->modify('+1 second')->format('Y-m-d H:i:s');
        return $this->peakReserved($productId, $now, $next);
    }

    /** @return list<array<string,mixed>> الحجوزات القادمة والحالية لمنتج */
    public function schedule(string $productId): array
    {
        return $this->repo->all("SELECT b.id AS booking_id, b.booking_no, b.status AS booking_status, bi.id AS item_id, bi.quantity, bi.install_at, bi.removal_at, bi.status,
                c.full_name AS customer_name, ce.name AS center_name
            FROM booking_items bi JOIN bookings b ON b.id = bi.booking_id
            JOIN customers c ON c.id = b.customer_id JOIN centers ce ON ce.id = b.center_id
            WHERE bi.product_id = ? AND bi.status IN ('reserved','installed') AND b.status IN ('confirmed','installed')
              AND (bi.removal_at >= ? OR bi.status = 'installed')
            ORDER BY bi.install_at LIMIT 100", [$productId, $this->app->clock->nowDb()]);
    }
}
