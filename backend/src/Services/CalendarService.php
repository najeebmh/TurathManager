<?php

declare(strict_types=1);

namespace TurathManager\Services;

use DateTimeImmutable;
use DateTimeZone;
use TurathManager\Auth\CurrentUser;
use TurathManager\Core\App;
use TurathManager\Core\Clock;
use TurathManager\Core\HttpException;
use TurathManager\Repositories\Generic;

/**
 * التقويم (شهري/أسبوعي/يومي) ولوحة اليوم: مواعيد التركيب والفك والمتأخرات.
 * الأيام تُحسب بتوقيت Asia/Aden.
 */
final class CalendarService
{
    private Generic $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new Generic($app->db());
    }

    /** @return list<array<string,mixed>> */
    public function events(string $from, string $to, CurrentUser $actor, ?string $centerId = null, bool $includeCancelled = true): array
    {
        $tz = $this->app->clock->localTz();
        $f = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        $t = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
        if (!$f || !$t || $t < $f || $f->diff($t)->days > 62) {
            throw HttpException::validation(['range' => 'نطاق التاريخ غير صالح (بحد أقصى 62 يوماً)']);
        }
        $utcFrom = $f->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $utcTo = $t->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $where = '((bi.install_at >= ? AND bi.install_at < ?) OR (bi.removal_at >= ? AND bi.removal_at < ?))';
        $p = [$utcFrom, $utcTo, $utcFrom, $utcTo];
        if (!$includeCancelled) {
            $where .= " AND b.status <> 'cancelled'";
        }
        if ($centerId) {
            $where .= ' AND b.center_id = ?';
            $p[] = $centerId;
        }
        if (!$actor->can('view_all_bookings')) {
            $ids = $actor->centerIds ?: ['-'];
            $where .= ' AND (b.center_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') OR b.created_by = ?)';
            array_push($p, ...$ids);
            $p[] = $actor->id;
        }
        $rows = $this->repo->all("SELECT bi.id AS item_id, bi.install_at, bi.removal_at, bi.status AS item_status, bi.quantity,
                b.id AS booking_id, b.booking_no, b.status, c.full_name AS customer_name, ce.name AS center_name, p.name AS product_name, ca.name AS category_name
            FROM booking_items bi JOIN bookings b ON b.id = bi.booking_id JOIN customers c ON c.id = b.customer_id
            JOIN centers ce ON ce.id = b.center_id JOIN products p ON p.id = bi.product_id JOIN categories ca ON ca.id = p.category_id
            WHERE $where AND b.archived_at IS NULL ORDER BY bi.install_at", $p);
        $now = $this->app->clock->nowDb();
        $events = [];
        foreach ($rows as $r) {
            foreach (['installation' => 'install_at', 'removal' => 'removal_at'] as $kind => $col) {
                if ($r[$col] < $utcFrom || $r[$col] >= $utcTo) {
                    continue;
                }
                $done = $kind === 'installation' ? in_array($r['item_status'], ['installed', 'removed'], true) : $r['item_status'] === 'removed';
                $events[] = [
                    'kind' => $kind,
                    'at' => Clock::toIso($r[$col]),
                    'booking_id' => $r['booking_id'], 'booking_no' => $r['booking_no'], 'item_id' => $r['item_id'],
                    'status' => $r['status'], 'item_status' => $r['item_status'],
                    'is_done' => $done,
                    'is_overdue' => !$done && $r['status'] !== 'cancelled' && $r[$col] < $now,
                    'customer_name' => $r['customer_name'], 'center_name' => $r['center_name'],
                    'product_name' => $r['product_name'], 'category_name' => $r['category_name'], 'quantity' => (int) $r['quantity'],
                ];
            }
        }
        usort($events, static fn ($a, $b) => $a['at'] <=> $b['at']);
        return $events;
    }

    public function dashboard(CurrentUser $actor): array
    {
        $tz = $this->app->clock->localTz();
        $today = $this->app->clock->now()->setTimezone($tz)->format('Y-m-d');
        $tomorrow = $this->app->clock->now()->setTimezone($tz)->modify('+1 day')->format('Y-m-d');
        $events = $this->events($today, $tomorrow, $actor, null, false);
        $todayEv = array_values(array_filter($events, fn ($e) => $this->localDate($e['at']) === $today));
        $tomorrowEv = array_values(array_filter($events, fn ($e) => $this->localDate($e['at']) === $tomorrow));
        $bookings = $this->app->service(BookingService::class);
        $overdue = $bookings->list(['status' => 'overdue'], $actor, 50)['data'];
        $cancelled = $bookings->list(['status' => 'cancelled', 'sort' => 'newest'], $actor, 10)['data'];
        $conflicts = $actor->can('resolve_conflicts') || $actor->can('view_all_bookings') ? $bookings->openConflicts($actor) : [];
        return [
            'today' => $todayEv,
            'tomorrow' => $tomorrowEv,
            'overdue' => $overdue,
            'recent_cancelled' => $cancelled,
            'open_conflicts' => $conflicts,
            'counts' => [
                'today_installations' => count(array_filter($todayEv, static fn ($e) => $e['kind'] === 'installation')),
                'today_removals' => count(array_filter($todayEv, static fn ($e) => $e['kind'] === 'removal')),
                'tomorrow_installations' => count(array_filter($tomorrowEv, static fn ($e) => $e['kind'] === 'installation')),
                'tomorrow_removals' => count(array_filter($tomorrowEv, static fn ($e) => $e['kind'] === 'removal')),
                'overdue' => count($overdue),
                'open_conflicts' => count($conflicts),
            ],
        ];
    }

    private function localDate(string $iso): string
    {
        return (new DateTimeImmutable($iso))->setTimezone($this->app->clock->localTz())->format('Y-m-d');
    }
}
