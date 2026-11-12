<?php

declare(strict_types=1);

namespace TurathManager\Services;

use DateTimeImmutable;
use DateTimeZone;
use TurathManager\Core\App;
use TurathManager\Core\Clock;
use TurathManager\Core\HttpException;
use TurathManager\Repositories\Generic;

/**
 * التقارير المالية والتشغيلية. التجميع اليومي/الشهري/السنوي يتم بتوقيت Asia/Aden.
 */
final class ReportService
{
    public const FINANCIAL = ['revenue', 'outstanding'];
    public const OPERATIONAL = ['bookings_by_center', 'bookings_by_type', 'top_products', 'inventory_movements', 'damaged_items'];

    private Generic $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new Generic($app->db());
    }

    /** @return array{0:string,1:string} نطاق UTC من تاريخين محليين */
    private function range(?string $from, ?string $to): array
    {
        $tz = $this->app->clock->localTz();
        $now = $this->app->clock->now()->setTimezone($tz);
        $f = $from ? DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz) : $now->modify('first day of this month')->setTime(0, 0);
        $t = $to ? DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz) : $now->setTime(0, 0);
        if (!$f || !$t || $t < $f) {
            throw HttpException::validation(['range' => 'نطاق التاريخ غير صالح']);
        }
        $utc = new DateTimeZone('UTC');
        return [$f->setTimezone($utc)->format('Y-m-d H:i:s'), $t->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    private function offset(): string
    {
        $o = $this->app->clock->now()->setTimezone($this->app->clock->localTz())->format('P');
        return $o;
    }

    /** الإيرادات مجمعة حسب يوم/شهر/سنة */
    public function revenue(string $period, ?string $from, ?string $to, ?string $centerId = null): array
    {
        [$f, $t] = $this->range($from, $to);
        $fmt = match ($period) {
            'monthly' => '%Y-%m',
            'yearly' => '%Y',
            default => '%Y-%m-%d',
        };
        $p = [$this->offset(), $f, $t];
        $centerSql = '';
        if ($centerId) {
            $centerSql = ' AND b.center_id = ?';
            $p[] = $centerId;
        }
        $rows = $this->repo->all("SELECT DATE_FORMAT(CONVERT_TZ(p.paid_at, '+00:00', ?), '$fmt') AS period_key,
                SUM(CASE WHEN p.amount > 0 THEN p.amount ELSE 0 END) AS received,
                SUM(CASE WHEN p.amount < 0 THEN -p.amount ELSE 0 END) AS refunded,
                SUM(p.amount) AS net, COUNT(*) AS payments_count
            FROM payments p JOIN bookings b ON b.id = p.booking_id
            WHERE p.paid_at >= ? AND p.paid_at < ? $centerSql
            GROUP BY period_key ORDER BY period_key", $p);
        $totals = ['received' => 0, 'refunded' => 0, 'net' => 0];
        foreach ($rows as &$r) {
            foreach (['received', 'refunded', 'net'] as $k) {
                $totals[$k] += PaymentService::cents($r[$k]);
                $r[$k] = PaymentService::money(PaymentService::cents($r[$k]));
            }
            $r['payments_count'] = (int) $r['payments_count'];
        }
        return ['rows' => $rows, 'totals' => array_map([PaymentService::class, 'money'], $totals)];
    }

    public function outstanding(?string $centerId = null): array
    {
        $p = [];
        $centerSql = '';
        if ($centerId) {
            $centerSql = ' AND b.center_id = ?';
            $p[] = $centerId;
        }
        $rows = $this->repo->all("SELECT b.id, b.booking_no, b.status, b.install_at, b.total_price, c.full_name AS customer_name, c.phone AS customer_phone, ce.name AS center_name,
                COALESCE((SELECT SUM(amount) FROM payments pa WHERE pa.booking_id = b.id), 0) AS paid
            FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN centers ce ON ce.id = b.center_id
            WHERE b.status IN ('confirmed','installed','removed') AND b.total_price IS NOT NULL $centerSql
            HAVING b.total_price - paid > 0 ORDER BY b.install_at", $p);
        $sum = 0;
        $out = array_map(static function ($r) use (&$sum) {
            $rem = PaymentService::cents($r['total_price']) - PaymentService::cents($r['paid']);
            $sum += $rem;
            return [
                'booking_id' => $r['id'], 'booking_no' => $r['booking_no'], 'status' => $r['status'], 'customer_name' => $r['customer_name'],
                'customer_phone' => $r['customer_phone'], 'center_name' => $r['center_name'], 'install_at' => Clock::toIso($r['install_at']),
                'total_price' => $r['total_price'], 'paid' => PaymentService::money(PaymentService::cents($r['paid'])), 'remaining' => PaymentService::money($rem),
            ];
        }, $rows);
        return ['rows' => $out, 'totals' => ['remaining' => PaymentService::money($sum), 'count' => count($out)]];
    }

    public function bookingsByCenter(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);
        $rows = $this->repo->all("SELECT ce.id, ce.name AS center_name,
                COUNT(b.id) AS bookings_count,
                SUM(b.status = 'cancelled') AS cancelled_count,
                COALESCE(SUM(CASE WHEN b.status <> 'cancelled' THEN b.total_price END), 0) AS total_value
            FROM centers ce LEFT JOIN bookings b ON b.center_id = ce.id AND b.install_at >= ? AND b.install_at < ?
            GROUP BY ce.id, ce.name ORDER BY bookings_count DESC, ce.name", [$f, $t]);
        return ['rows' => array_map(static fn ($r) => [
            'center_id' => $r['id'], 'center_name' => $r['center_name'], 'bookings_count' => (int) $r['bookings_count'],
            'cancelled_count' => (int) $r['cancelled_count'], 'total_value' => PaymentService::money(PaymentService::cents($r['total_value'])),
        ], $rows)];
    }

    public function bookingsByType(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);
        $rows = $this->repo->all("SELECT ca.name AS category_name, COALESCE(ty.name, 'بدون نوع') AS type_name,
                COUNT(DISTINCT b.id) AS bookings_count, SUM(bi.quantity) AS pieces
            FROM booking_items bi JOIN bookings b ON b.id = bi.booking_id JOIN products p ON p.id = bi.product_id
            JOIN categories ca ON ca.id = p.category_id LEFT JOIN category_types ty ON ty.id = p.category_type_id
            WHERE b.status <> 'cancelled' AND bi.status <> 'cancelled' AND bi.install_at >= ? AND bi.install_at < ?
            GROUP BY ca.name, type_name ORDER BY bookings_count DESC", [$f, $t]);
        return ['rows' => array_map(static fn ($r) => array_merge($r, ['bookings_count' => (int) $r['bookings_count'], 'pieces' => (int) $r['pieces']]), $rows)];
    }

    public function topProducts(?string $from, ?string $to, int $limit = 20): array
    {
        [$f, $t] = $this->range($from, $to);
        $rows = $this->repo->all("SELECT p.id, p.code, p.name, ca.name AS category_name, COUNT(DISTINCT b.id) AS bookings_count, SUM(bi.quantity) AS pieces
            FROM booking_items bi JOIN bookings b ON b.id = bi.booking_id JOIN products p ON p.id = bi.product_id JOIN categories ca ON ca.id = p.category_id
            WHERE b.status <> 'cancelled' AND bi.status <> 'cancelled' AND bi.install_at >= ? AND bi.install_at < ?
            GROUP BY p.id, p.code, p.name, ca.name ORDER BY bookings_count DESC, pieces DESC LIMIT $limit", [$f, $t]);
        return ['rows' => array_map(static fn ($r) => array_merge($r, ['bookings_count' => (int) $r['bookings_count'], 'pieces' => (int) $r['pieces']]), $rows)];
    }

    public function inventoryMovements(?string $from, ?string $to, ?string $productId = null): array
    {
        [$f, $t] = $this->range($from, $to);
        $p = [$f, $t];
        $extra = '';
        if ($productId) {
            $extra = ' AND m.product_id = ?';
            $p[] = $productId;
        }
        $rows = $this->repo->all("SELECT m.*, pr.code, pr.name AS product_name, u.full_name AS created_by_name
            FROM inventory_movements m JOIN products pr ON pr.id = m.product_id LEFT JOIN users u ON u.id = m.created_by
            WHERE m.created_at >= ? AND m.created_at < ? $extra ORDER BY m.seq DESC LIMIT 1000", $p);
        return ['rows' => array_map(static fn ($r) => [
            'created_at' => Clock::toIso($r['created_at']), 'code' => $r['code'], 'product_name' => $r['product_name'],
            'movement_type' => $r['movement_type'], 'label' => ProductService::MOVEMENT_LABELS[$r['movement_type']] ?? $r['movement_type'],
            'quantity' => (int) $r['quantity'], 'balance_after' => (int) $r['balance_after'], 'reason' => $r['reason'], 'created_by_name' => $r['created_by_name'],
        ], $rows)];
    }

    public function damagedItems(?string $from, ?string $to): array
    {
        [$f, $t] = $this->range($from, $to);
        $rows = $this->repo->all("SELECT r.created_at, r.item_condition, r.damaged_quantity, r.damage_notes, p.code, p.name AS product_name,
                b.booking_no, b.id AS booking_id, c.full_name AS customer_name
            FROM item_condition_reports r JOIN products p ON p.id = r.product_id JOIN booking_items bi ON bi.id = r.booking_item_id
            JOIN bookings b ON b.id = bi.booking_id JOIN customers c ON c.id = b.customer_id
            WHERE r.phase = 'after_return' AND (r.item_condition IN ('damaged','missing') OR r.damaged_quantity > 0)
              AND r.created_at >= ? AND r.created_at < ? ORDER BY r.created_at DESC", [$f, $t]);
        return ['rows' => array_map(static fn ($r) => [
            'created_at' => Clock::toIso($r['created_at']), 'code' => $r['code'], 'product_name' => $r['product_name'],
            'condition' => HandoverService::CONDITIONS[$r['item_condition']] ?? $r['item_condition'], 'damaged_quantity' => (int) $r['damaged_quantity'],
            'notes' => $r['damage_notes'], 'booking_no' => $r['booking_no'], 'booking_id' => $r['booking_id'], 'customer_name' => $r['customer_name'],
        ], $rows)];
    }
}
