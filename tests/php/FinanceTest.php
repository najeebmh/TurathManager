<?php

declare(strict_types=1);

namespace TurathManager\Tests;

/**
 * المرحلة 8: الحسابات والدفعات والتقارير.
 */
final class FinanceTest extends TestCase
{
    public function testDepositPaymentsRemainingAndReversal(): void
    {
        $t = $this->admin();
        $c = $this->center($t);
        $p = $this->product($t)['product'];
        [, $b] = $this->booking($t, $c['id'], [['product_id' => $p['id'], 'quantity' => 1]], 3, 4, ['total_price' => '100000']);
        $id = $b['data']['id'];
        $r1 = $this->ok('POST', "/bookings/$id/payments", ['payment_type' => 'deposit', 'amount' => '30,000', 'method' => 'cash'], $t)['data'];
        $this->assertSame('70000.00', $r1['summary']['remaining']);
        $this->assertMatchesRegularExpression('/^RC-\d{4}-\d{6}$/', $r1['payment']['receipt_no']);
        $r2 = $this->ok('POST', "/bookings/$id/payments", ['payment_type' => 'payment', 'amount' => '20000', 'method' => 'mobile_wallet', 'notes' => 'كريمي'], $t)['data'];
        $this->assertSame('50000.00', $r2['summary']['remaining'], 'المطلوب: المتبقي 50,000');
        $this->assertSame('30000.00', $r2['summary']['deposit']);
        $this->assertSame('50000.00', $r2['summary']['paid']);

        [$s] = $this->call('POST', "/bookings/$id/payments", ['payment_type' => 'payment', 'amount' => '50000.01', 'method' => 'cash'], $t);
        $this->assertSame(422, $s, 'overpayment rejected');
        [$s] = $this->call('POST', "/bookings/$id/payments", ['payment_type' => 'payment', 'amount' => '10.555', 'method' => 'cash'], $t);
        $this->assertSame(422, $s, 'max two decimals');
        [$s] = $this->call('POST', "/bookings/$id/payments", ['payment_type' => 'payment', 'amount' => '-5', 'method' => 'cash'], $t);
        $this->assertSame(422, $s);

        // عكس الدفعة بدلاً من حذفها
        [$s] = $this->call('POST', "/payments/{$r2['payment']['id']}/reverse", ['reason' => ''], $t);
        $this->assertSame(422, $s);
        $rev = $this->ok('POST', "/payments/{$r2['payment']['id']}/reverse", ['reason' => 'إدخال خاطئ'], $t)['data'];
        $this->assertSame('70000.00', $rev['summary']['remaining']);
        $this->assertSame('-20000.00', $rev['payment']['amount']);
        $this->assertSame('reversal', $rev['payment']['payment_type']);
        [$s] = $this->call('POST', "/payments/{$r2['payment']['id']}/reverse", ['reason' => 'مرة ثانية'], $t);
        $this->assertSame(409, $s, 'cannot reverse twice');
        $this->assertSame(3, (int) $this->app->db()->query("SELECT COUNT(*) FROM payments WHERE booking_id = '$id'")->fetchColumn(), 'nothing deleted');
        [$s] = $this->call('PUT', "/bookings/$id", ['total_price' => '20000'], $t);
        $this->assertSame(422, $s, 'total cannot go below paid');
    }

    public function testDecimalPrecisionAndIdempotentPayment(): void
    {
        $t = $this->admin();
        $c = $this->center($t);
        $p = $this->product($t)['product'];
        [, $b] = $this->booking($t, $c['id'], [['product_id' => $p['id'], 'quantity' => 1]], 3, 4, ['total_price' => '0.30']);
        $id = $b['data']['id'];
        $this->ok('POST', "/bookings/$id/payments", ['payment_type' => 'payment', 'amount' => '0.10', 'method' => 'cash'], $t);
        $r = $this->ok('POST', "/bookings/$id/payments", ['payment_type' => 'payment', 'amount' => '0.20', 'method' => 'cash'], $t)['data'];
        $this->assertSame('0.00', $r['summary']['remaining'], '0.1 + 0.2 must equal 0.3 exactly');

        [, $b2] = $this->booking($t, $c['id'], [['product_id' => $p['id'], 'quantity' => 1]], 8, 9, ['total_price' => '500']);
        $key = 'pay-' . bin2hex(random_bytes(8));
        [$s1, $d1] = $this->call('POST', "/bookings/{$b2['data']['id']}/payments", ['payment_type' => 'deposit', 'amount' => '100', 'method' => 'cash'], $t, [], ['idempotency-key' => $key]);
        [$s2, $d2, $h2] = $this->call('POST', "/bookings/{$b2['data']['id']}/payments", ['payment_type' => 'deposit', 'amount' => '100', 'method' => 'cash'], $t, [], ['idempotency-key' => $key]);
        $this->assertSame(201, $s1);
        $this->assertSame(201, $s2);
        $this->assertSame('true', $h2['Idempotent-Replayed']);
        $this->assertSame($d1['data']['payment']['id'], $d2['data']['payment']['id']);
        $this->assertSame(1, (int) $this->app->db()->query("SELECT COUNT(*) FROM payments WHERE booking_id = '{$b2['data']['id']}'")->fetchColumn());
    }

    public function testReportsAndCsvExport(): void
    {
        $t = $this->admin();
        $c1 = $this->center($t, 'مركز اليفا');
        $c2 = $this->center($t, 'بنت اليمن');
        $sj = $this->product($t, 'سجاف', 'مغربي', 5)['product'];
        $ks = $this->product($t, 'كوش', 'صنعاني', 5)['product'];
        [, $a] = $this->booking($t, $c1['id'], [['product_id' => $sj['id'], 'quantity' => 1]], 3, 4, ['total_price' => '100000']);
        [, $b] = $this->booking($t, $c1['id'], [['product_id' => $sj['id'], 'quantity' => 1], ['product_id' => $ks['id'], 'quantity' => 1]], 5, 6, ['total_price' => '200000']);
        [, $cc] = $this->booking($t, $c2['id'], [['product_id' => $sj['id'], 'quantity' => 2]], 7, 8, ['total_price' => '50000']);
        $this->ok('POST', "/bookings/{$a['data']['id']}/payments", ['payment_type' => 'deposit', 'amount' => '30000', 'method' => 'cash'], $t);
        $this->ok('POST', "/bookings/{$a['data']['id']}/payments", ['payment_type' => 'payment', 'amount' => '20000', 'method' => 'cash'], $t);
        $this->ok('POST', "/bookings/{$b['data']['id']}/payments", ['payment_type' => 'deposit', 'amount' => '200000', 'method' => 'bank_transfer'], $t);

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Aden')))->format('Y-m-d');
        $daily = $this->ok('GET', "/reports/revenue?period=daily&from=$today&to=$today", [], $t)['data'];
        $this->assertSame('250000.00', $daily['totals']['net']);
        $this->assertSame($today, $daily['rows'][0]['period_key']);
        $monthly = $this->ok('GET', '/reports/revenue?period=monthly&from=' . substr($today, 0, 8) . '01&to=' . $today, [], $t)['data'];
        $this->assertSame(substr($today, 0, 7), $monthly['rows'][0]['period_key']);
        $yearly = $this->ok('GET', '/reports/revenue?period=yearly&from=' . substr($today, 0, 4) . '-01-01&to=' . $today, [], $t)['data'];
        $this->assertSame('250000.00', $yearly['totals']['received']);

        $out = $this->ok('GET', '/reports/outstanding', [], $t)['data'];
        $this->assertSame('100000.00', $out['totals']['remaining']);
        $this->assertSame(2, $out['totals']['count']);

        $future = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Aden')))->modify('+30 days')->format('Y-m-d');
        $byCenter = $this->ok('GET', "/reports/bookings_by_center?from=$today&to=$future", [], $t)['data']['rows'];
        $map = array_column($byCenter, 'bookings_count', 'center_name');
        $this->assertSame(2, $map['مركز اليفا']);
        $this->assertSame(1, $map['بنت اليمن']);
        $byType = $this->ok('GET', "/reports/bookings_by_type?from=$today&to=$future", [], $t)['data']['rows'];
        $this->assertSame(['مغربي', 'صنعاني'], array_column($byType, 'type_name'));
        $top = $this->ok('GET', "/reports/top_products?from=$today&to=$future", [], $t)['data']['rows'];
        $this->assertSame($sj['id'], $top[0]['id']);
        $this->assertSame(4, $top[0]['pieces']);
        $inv = $this->ok('GET', '/reports/inventory_movements', [], $t)['data']['rows'];
        $this->assertCount(2, $inv);

        [$s, $csv, $h] = $this->call('GET', '/reports/outstanding?format=csv', [], $t);
        $this->assertSame(200, $s);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('المتبقي', $csv);
        $this->assertStringContainsString('text/csv', $h['Content-Type']);
    }
}
