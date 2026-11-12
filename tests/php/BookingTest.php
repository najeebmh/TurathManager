<?php

declare(strict_types=1);

namespace TurathManager\Tests;

/**
 * المرحلة 6: الحجوزات - السيناريوهات الثمانية المطلوبة ومنع التعارض.
 */
final class BookingTest extends TestCase
{
    private string $t;
    private array $center;
    private array $sijaf;
    private array $kosha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->admin();
        $this->center = $this->center($this->t, 'مركز اليفا');
        $this->sijaf = $this->product($this->t, 'سجاف', 'مغربي', 3, '45000', 'SJ-1')['product'];
        $this->kosha = $this->product($this->t, 'كوش', 'صنعاني', 1, '120000', 'KS-1')['product'];
    }

    public function testRequiredBookingScenarios(): void
    {
        // 1. حجز سجاف مغربي
        [$s, $b1] = $this->booking($this->t, $this->center['id'], [['product_id' => $this->sijaf['id'], 'quantity' => 2, 'unit_price' => '45000']], 10, 12);
        $this->assertSame(201, $s, json_encode($b1, JSON_UNESCAPED_UNICODE));
        $b1 = $b1['data'];
        $this->assertSame('confirmed', $b1['status']);
        $this->assertMatchesRegularExpression('/^TM-\d{4}-\d{5}$/', $b1['booking_no']);
        $this->assertSame('90000.00', $b1['total_price']);

        // 2. حجز كوش صنعانية
        [$s, $b2] = $this->booking($this->t, $this->center['id'], [['product_id' => $this->kosha['id'], 'quantity' => 1]], 20, 22, ['total_price' => '120000']);
        $this->assertSame(201, $s);

        // 3. حجز يجمع الاثنين مع مواعيد مختلفة لكل عنصر
        [$s, $b3] = $this->booking($this->t, $this->center['id'], [
            ['product_id' => $this->sijaf['id'], 'quantity' => 1, 'install_at' => self::future(30, 10), 'removal_at' => self::future(31, 10)],
            ['product_id' => $this->kosha['id'], 'quantity' => 1, 'install_at' => self::future(30, 14), 'removal_at' => self::future(33, 10)],
        ], 30, 33);
        $this->assertSame(201, $s, json_encode($b3, JSON_UNESCAPED_UNICODE));
        $this->assertCount(2, $b3['data']['items']);
        $this->assertNotSame($b3['data']['items'][0]['removal_at'], $b3['data']['items'][1]['removal_at']);

        // 4. كمية أكبر من المتوفر
        [$s, $d] = $this->booking($this->t, $this->center['id'], [['product_id' => $this->sijaf['id'], 'quantity' => 4]], 40, 41);
        $this->assertSame(409, $s);
        $this->assertSame('availability_conflict', $d['error']['code']);
        $this->assertSame(3, $d['error']['details']['shortages'][0]['available']);

        // 5. نفس القطعة في نفس التاريخ (الكوشة الوحيدة محجوزة في 20-22)
        [$s, $d] = $this->booking($this->t, $this->center['id'], [['product_id' => $this->kosha['id'], 'quantity' => 1]], 21, 23);
        $this->assertSame(409, $s);
        $this->assertSame(0, $d['error']['details']['shortages'][0]['available']);
        // والسجاف: 2 محجوزة من 3 → يمكن حجز واحدة إضافية فقط
        $this->assertSame(201, $this->booking($this->t, $this->center['id'], [['product_id' => $this->sijaf['id'], 'quantity' => 1]], 11, 12)[0]);
        $this->assertSame(409, $this->booking($this->t, $this->center['id'], [['product_id' => $this->sijaf['id'], 'quantity' => 1]], 11, 12)[0]);
        // حجز متتالٍ مباشرة بعد الفك مسموح (الفترات لا تتداخل)
        $back = $this->call('POST', '/bookings', ['center_id' => $this->center['id'], 'customer' => ['full_name' => 'س', 'phone' => '777999888'],
            'install_at' => $b2['data']['removal_at'], 'removal_at' => self::future(24), 'items' => [['product_id' => $this->kosha['id'], 'quantity' => 1]]], $this->t);
        $this->assertSame(201, $back[0], json_encode($back[1], JSON_UNESCAPED_UNICODE));

        // 6. تغيير تاريخ التركيب والفك
        [$s, $d] = $this->call('PUT', "/bookings/{$b2['data']['id']}", ['install_at' => self::future(50), 'removal_at' => self::future(52), 'change_reason' => 'طلب العميل'], $this->t);
        $this->assertSame(200, $s, json_encode($d, JSON_UNESCAPED_UNICODE));
        $this->assertSame($d['data']['install_at'], $d['data']['items'][0]['install_at']);
        // نقل الحجز إلى موعد محجوز للكوشة يُرفض
        [$s] = $this->call('PUT', "/bookings/{$b1['id']}", ['items' => [['id' => $b1['items'][0]['id'], 'product_id' => $this->kosha['id'], 'quantity' => 1]], 'install_at' => self::future(50, 18), 'removal_at' => self::future(51)], $this->t);
        $this->assertSame(409, $s);

        // 7. الإلغاء والتعديل
        $this->ok('PUT', "/bookings/{$b1['id']}", ['notes' => 'تم الاتفاق على اللون', 'total_price' => '85000'], $this->t);
        [$s] = $this->call('POST', "/bookings/{$b1['id']}/cancel", ['reason' => ''], $this->t);
        $this->assertSame(422, $s, 'reason required');
        $cancelled = $this->ok('POST', "/bookings/{$b1['id']}/cancel", ['reason' => 'العميل ألغى الحفل'], $this->t)['data'];
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame(201, $this->booking($this->t, $this->center['id'], [['product_id' => $this->sijaf['id'], 'quantity' => 2]], 10, 12)[0], 'cancelled booking frees stock');
        [$s] = $this->call('PUT', "/bookings/{$b1['id']}", ['notes' => 'x'], $this->t);
        $this->assertSame(409, $s);

        // 8. السجل لا يختفي
        $full = $this->ok('GET', "/bookings/{$b1['id']}", [], $this->t)['data'];
        $this->assertSame(['confirmed', 'cancelled'], array_column($full['history'], 'to_status'));
        $this->assertCount(3, $full['revisions']);
        $this->assertSame('85000.00', $full['revisions'][1]['snapshot']['total_price']);
        $this->assertSame(1, (int) $this->app->db()->query("SELECT COUNT(*) FROM bookings WHERE id = '{$b1['id']}'")->fetchColumn());
        $this->assertGreaterThan(0, (int) $this->app->db()->query("SELECT COUNT(*) FROM audit_logs WHERE entity_id = '{$b1['id']}' AND action = 'booking.cancelled'")->fetchColumn());
    }

    public function testListSearchFilterAndCenterScope(): void
    {
        $other = $this->center($this->t, 'بنت اليمن');
        $this->booking($this->t, $this->center['id'], [['product_id' => $this->sijaf['id'], 'quantity' => 1]], 5, 6, ['customer' => ['full_name' => 'نورة', 'phone' => '777111222']]);
        $this->booking($this->t, $other['id'], [['product_id' => $this->kosha['id'], 'quantity' => 1]], 7, 8, ['customer' => ['full_name' => 'هدى', 'phone' => '777333444']]);
        $all = $this->ok('GET', '/bookings', [], $this->t);
        $this->assertSame(2, $all['total']);
        $this->assertSame('نورة', $this->ok('GET', '/bookings?search=' . urlencode('نورة'), [], $this->t)['data'][0]['customer_name']);
        $this->assertSame(1, $this->ok('GET', '/bookings?search=777333', [], $this->t)['total']);
        $this->assertSame(1, $this->ok('GET', '/bookings?category_id=' . $this->kosha['category_id'], [], $this->t)['total']);
        $this->assertSame(1, $this->ok('GET', '/bookings?center_id=' . $other['id'], [], $this->t)['total']);
        $d = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Aden')))->modify('+7 days')->format('Y-m-d');
        $this->assertSame(1, $this->ok('GET', "/bookings?date_from=$d&date_to=$d", [], $this->t)['total']);

        [, $assistant] = $this->user(['assistant'], [$this->center['id'] => 'assistant']);
        $mine = $this->ok('GET', '/bookings', [], $assistant);
        $this->assertSame(1, $mine['total'], 'assistant sees only bookings of linked centers');
        $otherId = $this->ok('GET', '/bookings?center_id=' . $other['id'], [], $this->t)['data'][0]['id'];
        [$s] = $this->call('GET', "/bookings/$otherId", [], $assistant);
        $this->assertSame(404, $s);
        [$s] = $this->booking($assistant, $other['id'], [['product_id' => $this->sijaf['id'], 'quantity' => 1]], 60, 61);
        $this->assertSame(403, $s, 'cannot create for unlinked center');
        [$s] = $this->call('POST', "/bookings/$otherId/cancel", ['reason' => 'x'], $assistant);
        $this->assertSame(403, $s, 'assistant lacks cancel_booking');
    }

    public function testVersionConflictOnStaleUpdate(): void
    {
        [, $b] = $this->booking($this->t, $this->center['id'], [['product_id' => $this->sijaf['id'], 'quantity' => 1]]);
        $v = $b['data']['version'];
        $this->ok('PUT', "/bookings/{$b['data']['id']}", ['notes' => 'تعديل أول', 'version' => $v], $this->t);
        [$s, $d] = $this->call('PUT', "/bookings/{$b['data']['id']}", ['notes' => 'تعديل قديم', 'version' => $v], $this->t);
        $this->assertSame(409, $s);
        $this->assertSame('version_conflict', $d['error']['code']);
        $this->assertSame('تعديل أول', $d['error']['details']['server']['notes'], 'newer data is not overwritten');
    }

    public function testConcurrentRequestsForLastPieceOnlyOneSucceeds(): void
    {
        $script = dirname(__DIR__, 2) . '/tests/php/concurrent_booking.php';
        $procs = [];
        for ($i = 0; $i < 6; $i++) {
            $procs[] = proc_open([PHP_BINARY, $script, $GLOBALS['TM_TEST_DB'], $this->center['id'], $this->kosha['id'], (string) $i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $results = [];
        foreach ($procs as $i => $p) {
            $results[] = trim(stream_get_contents($pipes[$i][1]));
            $err = stream_get_contents($pipes[$i][2]);
            proc_close($p);
            $this->assertSame('', $err);
        }
        sort($results);
        $this->assertSame(['201', '409', '409', '409', '409', '409'], $results, 'exactly one concurrent booking of the last piece succeeds');
        $this->assertSame(1, (int) $this->app->db()->query("SELECT COUNT(*) FROM booking_items WHERE product_id = '{$this->kosha['id']}' AND status = 'reserved'")->fetchColumn());
    }
}
