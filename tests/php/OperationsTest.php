<?php

declare(strict_types=1);

namespace TurathManager\Tests;

use TurathManager\Services\SchedulerService;

/**
 * المرحلة 7: التقويم والتركيب والفك والتسليم والاستلام.
 */
final class OperationsTest extends TestCase
{
    public function testInstallRemoveHandoverFlowWithCalendar(): void
    {
        [$adminId, $t] = $this->user(['admin']);
        $c = $this->center($t);
        $p = $this->product($t, 'كوش', 'صنعاني', 2)['product'];
        // حجز بدأ أمس وينتهي بعد يومين (للتسليم الآن)
        [$s, $b] = $this->call('POST', '/bookings', [
            'center_id' => $c['id'], 'customer' => ['full_name' => 'أم سارة', 'phone' => '777555666'],
            'install_at' => self::future(0, 0), 'removal_at' => self::future(2), 'items' => [['product_id' => $p['id'], 'quantity' => 2]],
        ], $t);
        $this->assertSame(201, $s, json_encode($b, JSON_UNESCAPED_UNICODE));
        $item = $b['data']['items'][0];

        [$s, $d] = $this->call('POST', "/booking-items/{$item['id']}/remove", ['delivered_by_name' => 'العميل', 'received_by_name' => 'علي', 'item_condition' => 'good'], $t);
        $this->assertSame(409, $s, 'cannot remove before install');

        $h = $this->ok('POST', "/booking-items/{$item['id']}/install", [
            'delivered_by_name' => 'علي (المحل)', 'received_by_name' => 'أم سارة', 'item_condition' => 'excellent', 'notes' => 'سليمة',
            'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
        ], $t)['data'];
        $this->assertSame('delivery', $h['handover_type']);
        $this->assertTrue($h['has_signature']);
        [$s] = $this->call('POST', "/booking-items/{$item['id']}/install", ['delivered_by_name' => 'x', 'received_by_name' => 'y', 'item_condition' => 'good'], $t);
        $this->assertSame(409, $s, 'duplicate delivery blocked');
        $img = $this->call('POST', "/handovers/{$h['id']}/images", [], $t, ['image' => $this->image()]);
        $this->assertSame(201, $img[0]);
        $this->assertCount(1, $img[1]['data']['images']);
        $this->assertSame('installed', $this->ok('GET', "/bookings/{$b['data']['id']}", [], $t)['data']['status']);

        // الفك قبل الموعد يحتاج صلاحية وسبباً
        [, $assistant] = $this->user(['assistant'], [$c['id'] => 'assistant']);
        [$s] = $this->call('POST', "/booking-items/{$item['id']}/remove", ['delivered_by_name' => 'أم سارة', 'received_by_name' => 'علي', 'item_condition' => 'good'], $assistant);
        $this->assertSame(403, $s);
        [$s, $d] = $this->call('POST', "/booking-items/{$item['id']}/remove", ['delivered_by_name' => 'أم سارة', 'received_by_name' => 'علي', 'item_condition' => 'good'], $t);
        $this->assertSame(422, $s);
        $this->assertArrayHasKey('early_reason', $d['error']['details']['errors']);
        $ret = $this->ok('POST', "/booking-items/{$item['id']}/remove", [
            'delivered_by_name' => 'أم سارة', 'received_by_name' => 'علي', 'item_condition' => 'damaged', 'damaged_quantity' => 1,
            'early_reason' => 'انتهى الحفل مبكراً', 'notes' => 'تمزق في الطرف',
        ], $t)['data'];
        $this->assertSame(1, $ret['damaged_quantity']);

        $full = $this->ok('GET', "/bookings/{$b['data']['id']}", [], $t)['data'];
        $this->assertSame('removed', $full['status']);
        $this->assertSame(['confirmed', 'installed', 'removed'], array_column($full['history'], 'to_status'));
        $this->assertCount(2, $full['handovers']);
        $this->assertSame('excellent', $full['handovers'][0]['item_condition']);
        $this->assertSame('damaged', $full['handovers'][1]['item_condition']);

        // القطعة المتضررة تُخصم من المخزون بحركة مسجلة
        $prod = $this->ok('GET', "/products/{$p['id']}", [], $t)['data'];
        $this->assertSame(1, $prod['quantity_total']);
        $this->assertSame('damage', $prod['movements'][0]['movement_type']);
        $damaged = $this->ok('GET', '/reports/damaged_items', [], $t)['data']['rows'];
        $this->assertCount(1, $damaged);

        // التقويم
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Aden')))->format('Y-m-d');
        $end = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Aden')))->modify('+3 days')->format('Y-m-d');
        $events = $this->ok('GET', "/calendar?from=$today&to=$end", [], $t)['data'];
        $this->assertSame(['installation', 'removal'], array_column($events, 'kind'));
        $this->assertTrue($events[0]['is_done']);
        $audit = $this->app->db()->query("SELECT action FROM audit_logs WHERE action LIKE 'handover.%' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['handover.delivery', 'handover.return'], $audit);
    }

    public function testOverdueAndDashboard(): void
    {
        $t = $this->admin();
        $c = $this->center($t);
        $p = $this->product($t)['product'];
        [, $b] = $this->booking($t, $c['id'], [['product_id' => $p['id'], 'quantity' => 1]], 1, 2);
        $item = $b['data']['items'][0];
        $this->ok('POST', "/booking-items/{$item['id']}/install", ['delivered_by_name' => 'a', 'received_by_name' => 'b', 'item_condition' => 'good'], $t);
        // محاكاة تجاوز موعد الفك
        $this->app->clock->freeze(new \DateTimeImmutable('+5 days'));
        $dash = $this->ok('GET', '/dashboard', [], $t)['data'];
        $this->assertSame(1, $dash['counts']['overdue']);
        $this->assertTrue($dash['overdue'][0]['is_overdue']);
        // القطعة المركبة المتأخرة تبقى محجوزة حتى فكها فعلياً
        [$s] = $this->call('POST', '/bookings', ['center_id' => $c['id'], 'customer' => ['full_name' => 'x', 'phone' => '777222111'],
            'install_at' => (new \DateTimeImmutable('+6 days'))->format('c'), 'removal_at' => (new \DateTimeImmutable('+7 days'))->format('c'),
            'items' => [['product_id' => $p['id'], 'quantity' => 3]]], $t);
        $this->assertSame(409, $s);
        $this->app->clock->freeze(null);
    }
}
