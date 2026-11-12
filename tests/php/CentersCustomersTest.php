<?php

declare(strict_types=1);

namespace TurathManager\Tests;

/**
 * المرحلة 5: المراكز والعملاء وتعدد المراكز.
 */
final class CentersCustomersTest extends TestCase
{
    public function testCentersWithManagersAndAssistants(): void
    {
        $t = $this->admin();
        $alifa = $this->ok('POST', '/centers', [
            'name' => 'مركز اليفا', 'code' => 'ALF', 'whatsapp_number' => '777000111', 'location_url' => 'https://maps.google.com/?q=15.35,44.2',
            'address' => 'صنعاء - شارع حدة', 'manager_name' => 'أحمد', 'manager_phone' => '777000222', 'manager_email' => 'a@alifa.ye',
            'assistant_name' => 'سارة', 'assistant_phone' => '777000333', 'handover_officer' => 'علي', 'emergency_phone' => '777000444',
            'working_hours' => '9 ص - 11 م', 'notes' => 'ملاحظة',
        ], $t)['data'];
        $this->assertSame('+967777000111', $alifa['whatsapp_number']);
        $bint = $this->center($t, 'بنت اليمن', 'BNY');
        [$s] = $this->call('POST', '/centers', ['name' => 'مكرر', 'code' => 'ALF'], $t);
        $this->assertSame(422, $s);
        [$s] = $this->call('POST', '/centers', ['name' => 'رابط خاطئ', 'code' => 'BAD', 'location_url' => 'javascript:alert(1)'], $t);
        $this->assertSame(422, $s);

        [$m1] = $this->user(['manager'], [$alifa['id'] => 'manager'], null, ['full_name' => 'مدير اليفا']);
        [$a1] = $this->user(['assistant'], [$alifa['id'] => 'assistant'], null, ['full_name' => 'مساعدة اليفا']);
        [$m2] = $this->user(['manager'], [$bint['id'] => 'manager', $alifa['id'] => 'staff'], null, ['full_name' => 'مدير بنت اليمن']);
        [$a2] = $this->user(['assistant'], [$bint['id'] => 'assistant'], null, ['full_name' => 'مساعدة بنت اليمن']);

        $alifaStaff = $this->ok('GET', "/centers/{$alifa['id']}", [], $t)['data']['staff'];
        $this->assertSame(['manager', 'assistant', 'staff'], array_column($alifaStaff, 'role_in_center'));
        $bintStaff = $this->ok('GET', "/centers/{$bint['id']}", [], $t)['data']['staff'];
        $this->assertSame(['مدير بنت اليمن', 'مساعدة بنت اليمن'], array_column($bintStaff, 'full_name'));

        $found = $this->ok('GET', '/centers?search=' . urlencode('حدة'), [], $t)['data'];
        $this->assertSame(['ALF'], array_column($found, 'code'));
        $this->ok('POST', "/centers/{$bint['id']}/archive", [], $t);
        $this->assertCount(1, $this->ok('GET', '/centers', [], $t)['data']);
    }

    public function testCustomersAreNotDuplicatedAndShowBookings(): void
    {
        $t = $this->admin();
        $cust = $this->ok('POST', '/customers', ['full_name' => 'فاطمة علي', 'phone' => '0777 123 456', 'address' => 'صنعاء'], $t)['data'];
        $this->assertSame('+967777123456', $cust['phone']);
        [$s, $d] = $this->call('POST', '/customers', ['full_name' => 'فاطمة', 'phone' => '+967777123456'], $t);
        $this->assertSame(409, $s);
        $this->assertSame($cust['id'], $d['error']['details']['existing_customer_id']);
        $this->assertSame($cust['id'], $this->ok('GET', '/customers/lookup?phone=777123456', [], $t)['data']['id']);
        $this->assertSame(1, $this->ok('GET', '/customers?search=' . urlencode('فاطمة'), [], $t)['total']);
        $this->assertSame(1, $this->ok('GET', '/customers?search=777123', [], $t)['total']);

        $c = $this->center($t);
        $p = $this->product($t)['product'];
        [$s, $b] = $this->booking($t, $c['id'], [['product_id' => $p['id'], 'quantity' => 1]], 5, 6, ['customer' => ['full_name' => 'فاطمة علي', 'phone' => '777123456']]);
        $this->assertSame(201, $s);
        $this->assertSame($cust['id'], $b['data']['customer_id'], 'existing customer reused by phone');
        $this->assertSame(1, (int) $this->app->db()->query('SELECT COUNT(*) FROM customers')->fetchColumn());
        $full = $this->ok('GET', "/customers/{$cust['id']}", [], $t)['data'];
        $this->assertCount(1, $full['bookings']);
        $this->assertSame(1, $full['bookings_count']);
    }
}
