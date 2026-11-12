<?php

declare(strict_types=1);

namespace TurathManager\Tests;

use TurathManager\Core\Uuid;

/**
 * المرحلة 11: محرك المزامنة على الخادم (Push/Pull، عدم التكرار، التعارضات).
 */
final class SyncTest extends TestCase
{
    private function op(string $entity, string $action, string $entityId, array $payload, ?int $baseVersion = null): array
    {
        return ['op_id' => Uuid::v4(), 'entity' => $entity, 'action' => $action, 'entity_id' => $entityId, 'payload' => $payload, 'base_version' => $baseVersion, 'client_created_at' => gmdate('c')];
    }

    public function testOfflineOperationsAreAppliedInOrderAndIdempotent(): void
    {
        $t = $this->admin();
        $c = $this->center($t);
        $catalog = $this->product($t);
        $device = Uuid::v4();
        $customerId = Uuid::v4();
        $productId = Uuid::v4();
        $bookingId = Uuid::v4();
        $ops = [
            $this->op('customer', 'create', $customerId, ['full_name' => 'عميلة دون اتصال', 'phone' => '777010203']),
            $this->op('product', 'create', $productId, ['name' => 'كوشة جديدة', 'code' => 'OFF-1', 'category_id' => $catalog['category']['id'], 'initial_quantity' => 2, 'price' => '1000']),
            $this->op('booking', 'create', $bookingId, ['center_id' => $c['id'], 'customer_id' => $customerId, 'install_at' => self::future(5), 'removal_at' => self::future(6),
                'items' => [['id' => Uuid::v4(), 'product_id' => $productId, 'quantity' => 1]]]),
        ];
        $res = $this->ok('POST', '/sync/push', ['device_id' => $device, 'operations' => $ops, 'device' => ['device_name' => 'جوال المحل', 'platform' => 'android']], $t)['results'];
        $this->assertSame(['applied', 'applied', 'applied'], array_column($res, 'status'), json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->assertSame('confirmed', $res[2]['data']['status']);
        $this->assertSame('offline_sync', $res[2]['data']['source']);

        // إعادة الإرسال (مثلاً انقطع الاتصال قبل وصول الرد) لا تكرر شيئاً
        $again = $this->ok('POST', '/sync/push', ['device_id' => $device, 'operations' => $ops], $t)['results'];
        $this->assertSame([true, true, true], array_column($again, 'duplicate'));
        $this->assertSame(1, (int) $this->app->db()->query("SELECT COUNT(*) FROM bookings WHERE id = '$bookingId'")->fetchColumn());
        $this->assertSame(1, (int) $this->app->db()->query("SELECT COUNT(*) FROM customers WHERE phone = '+967777010203'")->fetchColumn());
        $this->assertSame(2, (int) $this->app->db()->query("SELECT quantity_total FROM products WHERE id = '$productId'")->fetchColumn());

        // السحب من جهاز آخر
        $pull = $this->ok('GET', '/sync/pull?device_id=' . Uuid::v4() . '&since=0', [], $t);
        $entities = array_count_values(array_column($pull['changes'], 'entity'));
        $this->assertSame(1, $entities['booking']);
        $this->assertSame(2, $entities['product']);
        $this->assertGreaterThan(0, $pull['cursor']);
        $next = $this->ok('GET', '/sync/pull?device_id=' . Uuid::v4() . '&since=' . $pull['cursor'], [], $t);
        $this->assertSame([], $next['changes']);
    }

    public function testTwoDevicesBookLastPieceOfflineOneConfirmedOneConflictKept(): void
    {
        $t = $this->admin();
        $c = $this->center($t);
        $p = $this->product($t, 'كوش', 'صنعاني', 1)['product'];
        $mk = fn (string $phone) => $this->op('booking', 'create', Uuid::v4(), ['center_id' => $c['id'], 'customer' => ['full_name' => 'عميل ' . $phone, 'phone' => $phone],
            'install_at' => self::future(9), 'removal_at' => self::future(10), 'items' => [['product_id' => $p['id'], 'quantity' => 1]]]);
        $a = $mk('777100100');
        $b = $mk('777200200');
        $r1 = $this->ok('POST', '/sync/push', ['device_id' => Uuid::v4(), 'operations' => [$a]], $t)['results'][0];
        $r2 = $this->ok('POST', '/sync/push', ['device_id' => Uuid::v4(), 'operations' => [$b]], $t)['results'][0];
        $this->assertSame('confirmed', $r1['data']['status']);
        $this->assertSame('applied', $r2['status']);
        $this->assertTrue($r2['booking_conflict']);
        $this->assertSame('conflict', $r2['data']['status']);
        $this->assertNull($r2['data']['booking_no'], 'conflicting booking is not globally confirmed');
        $this->assertSame('pending', $r2['data']['items'][0]['status']);
        $conflicts = $this->ok('GET', '/bookings/conflicts', [], $t)['data'];
        $this->assertCount(1, $conflicts);
        $this->assertSame($b['entity_id'], $conflicts[0]['booking_id']);
        $this->assertSame(1, (int) $this->app->db()->query("SELECT COUNT(*) FROM notifications WHERE type = 'booking_conflict'")->fetchColumn());

        // المعالجة: تغيير الموعد يؤكد الحجز ويغلق التعارض
        $fixed = $this->ok('PUT', "/bookings/{$b['entity_id']}", ['install_at' => self::future(11), 'removal_at' => self::future(12), 'change_reason' => 'معالجة التعارض'], $t)['data'];
        $this->assertSame('confirmed', $fixed['status']);
        $this->assertNotNull($fixed['booking_no']);
        $this->assertSame('reserved', $fixed['items'][0]['status']);
        $this->assertSame([], $this->ok('GET', '/bookings/conflicts', [], $t)['data']);
    }

    public function testStaleUpdateIsReturnedAsConflictAndPermissionsEnforced(): void
    {
        $t = $this->admin();
        $cust = $this->ok('POST', '/customers', ['full_name' => 'هند', 'phone' => '777303030'], $t)['data'];
        $this->ok('PUT', "/customers/{$cust['id']}", ['full_name' => 'هند محمد', 'phone' => '777303030'], $t);
        $stale = $this->op('customer', 'update', $cust['id'], ['full_name' => 'هند (قديم)', 'phone' => '777303030'], $cust['version']);
        $r = $this->ok('POST', '/sync/push', ['device_id' => Uuid::v4(), 'operations' => [$stale]], $t)['results'][0];
        $this->assertSame('conflict', $r['status']);
        $this->assertSame('هند محمد', $r['server']['full_name']);
        $this->assertSame('هند محمد', $this->ok('GET', "/customers/{$cust['id']}", [], $t)['data']['full_name'], 'older data does not overwrite newer');

        $c = $this->center($t);
        [, $assistant] = $this->user(['assistant'], [$c['id'] => 'assistant']);
        $r = $this->ok('POST', '/sync/push', ['device_id' => Uuid::v4(), 'operations' => [$this->op('product', 'create', Uuid::v4(), ['name' => 'x'])]], $assistant)['results'][0];
        $this->assertSame('rejected', $r['status']);
        $this->assertSame(1, (int) $this->app->db()->query("SELECT COUNT(*) FROM notifications WHERE type = 'sync_failed'")->fetchColumn());
        $status = $this->ok('GET', '/sync/status', [], $assistant)['data'];
        $this->assertCount(1, $status['problems']);
    }

    public function testOfflineHandoverAndPayment(): void
    {
        $t = $this->admin();
        $c = $this->center($t);
        $p = $this->product($t)['product'];
        [, $b] = $this->call('POST', '/bookings', ['center_id' => $c['id'], 'customer' => ['full_name' => 'x', 'phone' => '777808080'], 'total_price' => '1000',
            'install_at' => self::future(0, 0), 'removal_at' => self::future(3), 'items' => [['product_id' => $p['id'], 'quantity' => 1]]], $t);
        $itemId = $b['data']['items'][0]['id'];
        $ops = [
            $this->op('handover', 'create', Uuid::v4(), ['booking_item_id' => $itemId, 'handover_type' => 'delivery', 'delivered_by_name' => 'علي', 'received_by_name' => 'العميلة', 'item_condition' => 'good']),
            $this->op('payment', 'create', Uuid::v4(), ['booking_id' => $b['data']['id'], 'payment_type' => 'deposit', 'amount' => '300', 'method' => 'cash']),
        ];
        $r = $this->ok('POST', '/sync/push', ['device_id' => Uuid::v4(), 'operations' => $ops], $t)['results'];
        $this->assertSame(['applied', 'applied'], array_column($r, 'status'), json_encode($r, JSON_UNESCAPED_UNICODE));
        $full = $this->ok('GET', "/bookings/{$b['data']['id']}", [], $t)['data'];
        $this->assertSame('installed', $full['status']);
        $this->assertSame('700.00', $full['remaining']);
    }
}
