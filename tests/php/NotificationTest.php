<?php

declare(strict_types=1);

namespace TurathManager\Tests;

use TurathManager\Services\NotificationService;
use TurathManager\Services\SchedulerService;

/**
 * المرحلة 9: الإشعارات الداخلية والقوالب ومنع التكرار والتذكيرات المجدولة.
 */
final class NotificationTest extends TestCase
{
    public function testBookingNotifiesOnlySelectedRecipients(): void
    {
        [$adminId, $t] = $this->user(['admin']);
        $c = $this->center($t);
        [$mId, $mTok] = $this->user(['manager'], [$c['id'] => 'manager']);
        [$aId, $aTok] = $this->user(['assistant'], [$c['id'] => 'assistant']);
        $this->ok('PUT', '/notification-subscriptions', ['event_type' => 'booking_created', 'channel' => 'in_app', 'user_ids' => [$mId]], $t);
        $p = $this->product($t)['product'];
        [, $b] = $this->booking($t, $c['id'], [['product_id' => $p['id'], 'quantity' => 1]]);

        $mine = $this->ok('GET', '/notifications', [], $mTok);
        $this->assertSame(1, $mine['unread']);
        $n = $mine['data'][0];
        $this->assertSame('booking_created', $n['type']);
        $this->assertStringContainsString($b['data']['booking_no'], $n['title']);
        $this->assertStringContainsString('أم محمد', $n['body']);
        $this->assertSame('#/bookings/' . $b['data']['id'], $n['link']);
        $this->assertSame(0, $this->ok('GET', '/notifications', [], $aTok)['unread'], 'assistant not selected');
        $this->assertSame(0, $this->ok('GET', '/notifications', [], $t)['unread'], 'admin not selected once recipients configured');

        [$s] = $this->call('POST', "/notifications/{$n['id']}/read", [], $mTok);
        $this->assertSame(204, $s);
        $after = $this->ok('GET', '/notifications', [], $mTok);
        $this->assertSame(0, $after['unread']);
        $this->assertTrue($after['data'][0]['is_read']);
    }

    public function testDefaultRecipientsAreAdminsAndDedupe(): void
    {
        [$adminId, $t] = $this->user(['admin']);
        $c = $this->center($t);
        $p = $this->product($t)['product'];
        [, $b] = $this->booking($t, $c['id'], [['product_id' => $p['id'], 'quantity' => 1]]);
        $this->assertSame(1, $this->ok('GET', '/notifications', [], $t)['unread']);
        $svc = $this->app->service(NotificationService::class);
        $this->assertNull($svc->bookingEvent('booking_created', $b['data']['id'], 'v1'), 'same event is not duplicated');
        $this->assertSame(1, (int) $this->app->db()->query("SELECT COUNT(*) FROM notifications WHERE type = 'booking_created'")->fetchColumn());
    }

    public function testRemindersAndRescheduleCancelsOldReminder(): void
    {
        [$adminId, $t] = $this->user(['admin']);
        $c = $this->center($t);
        [$uid] = $this->user(['manager'], [$c['id'] => 'manager'], null, ['whatsapp_number' => '+967777000999', 'whatsapp_opt_in' => 1]);
        foreach (['installation_upcoming', 'removal_upcoming'] as $e) {
            $this->ok('PUT', '/notification-subscriptions', ['event_type' => $e, 'channel' => 'in_app', 'user_ids' => [$uid]], $t);
            $this->ok('PUT', '/notification-subscriptions', ['event_type' => $e, 'channel' => 'push', 'user_ids' => [$uid]], $t);
        }
        $p = $this->product($t)['product'];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        [$s, $b] = $this->call('POST', '/bookings', ['center_id' => $c['id'], 'customer' => ['full_name' => 'x', 'phone' => '777444555'],
            'install_at' => $now->modify('+5 hours')->format('c'), 'removal_at' => $now->modify('+20 hours')->format('c'),
            'items' => [['product_id' => $p['id'], 'quantity' => 1]]], $t);
        $this->assertSame(201, $s);

        $sched = $this->app->service(SchedulerService::class);
        $first = $sched->runAll();
        $this->assertSame(['installation_upcoming' => 1, 'removal_upcoming' => 1], $first['reminders']);
        $second = $sched->runAll();
        $this->assertSame(['installation_upcoming' => 0, 'removal_upcoming' => 0], $second['reminders'], 'no duplicate reminders');
        $this->assertSame(2, (int) $this->app->db()->query("SELECT COUNT(*) FROM notification_logs WHERE channel = 'push' AND status = 'skipped'")->fetchColumn(), 'push skipped: FCM not configured — never claimed as sent');

        // إعادة جدولة: تذكير الفك القديم في الطابور يُلغى ولا يُرسل
        $this->app->db()->exec("UPDATE notification_logs SET status = 'queued' WHERE channel = 'push'");
        $this->ok('PUT', "/bookings/{$b['data']['id']}", ['removal_at' => $now->modify('+3 days')->format('c'), 'install_at' => $now->modify('+5 hours')->format('c')], $t);
        $cancelled = $this->app->db()->query("SELECT n.type FROM notification_logs l JOIN notifications n ON n.id = l.notification_id WHERE l.status = 'cancelled'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['removal_upcoming'], $cancelled);
        $third = $sched->runAll();
        $this->assertSame(0, $third['reminders']['removal_upcoming'], 'new removal date is outside the reminder window');

        // قبل يوم من الموعد الجديد يُنشأ تذكير جديد
        $this->app->clock->freeze($now->modify('+2 days +6 hours'));
        $fourth = $sched->runAll();
        $this->assertSame(1, $fourth['reminders']['removal_upcoming']);
        $this->app->clock->freeze(null);
    }

    public function testTemplatesAreEditableWithKnownVariablesOnly(): void
    {
        $t = $this->admin();
        $tpls = $this->ok('GET', '/notification-templates', [], $t)['data'];
        $created = array_values(array_filter($tpls, static fn ($x) => $x['template_key'] === 'booking_created' && $x['channel'] === 'in_app'))[0];
        [$s] = $this->call('PUT', "/notification-templates/{$created['id']}", ['title' => 'حجز {unknown_var}', 'body' => 'x'], $t);
        $this->assertSame(422, $s);
        $upd = $this->ok('PUT', "/notification-templates/{$created['id']}", ['title' => 'حجز جديد للعميلة {customer_name}', 'body' => 'في {center_name} - {installation_date}'], $t)['data'];
        $this->assertSame('حجز جديد للعميلة {customer_name}', $upd['title']);
        $c = $this->center($t, 'مركز اليفا');
        $p = $this->product($t)['product'];
        $this->booking($t, $c['id'], [['product_id' => $p['id'], 'quantity' => 1]]);
        $n = $this->ok('GET', '/notifications', [], $t)['data'][0];
        $this->assertSame('حجز جديد للعميلة أم محمد', $n['title']);
        $this->assertStringStartsWith('في مركز اليفا - ', $n['body']);
    }
}
