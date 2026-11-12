<?php

declare(strict_types=1);

namespace TurathManager\Tests;

use TurathManager\Services\SchedulerService;
use TurathManager\Support\HttpClient;

/**
 * المرحلة 10: WhatsApp Business Cloud API (ردود Meta محاكاة عبر عميل HTTP وهمي).
 */
final class WhatsAppTest extends TestCase
{
    private FakeHttpClient $http;
    private string $t;
    private string $mgrId;
    private array $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttpClient();
        $this->app->bind(HttpClient::class, $this->http);
        $this->t = $this->admin();
        $this->c = $this->center($this->t);
        [$this->mgrId] = $this->user(['manager'], [$this->c['id'] => 'manager'], null, ['whatsapp_number' => '+967777000111', 'whatsapp_opt_in' => 1]);
        [$noConsent] = $this->user(['manager'], [$this->c['id'] => 'manager'], null, ['whatsapp_number' => '+967777000222', 'whatsapp_opt_in' => 0]);
        $this->ok('PUT', '/whatsapp/settings', [
            'business_account_id' => '1234567890', 'phone_number_id' => '9876543210', 'access_token' => 'EAAG-secret-token',
            'app_secret' => 'app-secret-xyz', 'verify_token' => 'verify-me', 'mode' => 'test', 'is_enabled' => true,
            'test_recipients' => ['777000111'],
        ], $this->t);
        $this->ok('PUT', '/notification-subscriptions', ['event_type' => 'booking_created', 'channel' => 'whatsapp', 'user_ids' => [$this->mgrId, $noConsent]], $this->t);
    }

    private function makeBooking(): array
    {
        $p = $this->product($this->t)['product'];
        [$s, $b] = $this->booking($this->t, $this->c['id'], [['product_id' => $p['id'], 'quantity' => 1]]);
        $this->assertSame(201, $s);
        return $b['data'];
    }

    public function testSecretsAreEncryptedAndNeverReturned(): void
    {
        [, $d] = $this->call('GET', '/whatsapp/settings', [], $this->t);
        $json = json_encode($d);
        $this->assertStringNotContainsString('EAAG-secret-token', $json);
        $this->assertStringNotContainsString('app-secret-xyz', $json);
        $this->assertTrue($d['data']['access_token_set']);
        $row = $this->app->db()->query('SELECT access_token_enc FROM whatsapp_settings WHERE id = 1')->fetchColumn();
        $this->assertStringNotContainsString('EAAG', (string) $row);
        $audit = $this->app->db()->query("SELECT details FROM audit_logs WHERE action = 'whatsapp.settings_updated'")->fetchColumn();
        $this->assertStringNotContainsString('EAAG-secret-token', (string) $audit);
    }

    public function testConnectionTestUsesRealResponse(): void
    {
        $this->http->push(200, ['id' => '9876543210', 'display_phone_number' => '+1 555 0100', 'verified_name' => 'Turath', 'quality_rating' => 'GREEN']);
        $r = $this->ok('POST', '/whatsapp/test-connection', [], $this->t)['data'];
        $this->assertTrue($r['ok']);
        $this->assertSame('Bearer EAAG-secret-token', $this->http->requests[0]['headers']['Authorization']);
        $this->assertStringContainsString('/9876543210?fields=', $this->http->requests[0]['url']);
        $this->http->push(401, ['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]]);
        $r = $this->ok('POST', '/whatsapp/test-connection', [], $this->t)['data'];
        $this->assertFalse($r['ok']);
        $this->assertSame('error', $this->ok('GET', '/whatsapp/settings', [], $this->t)['data']['connection_status']);
    }

    public function testSendSuccessThenWebhookDeliveredAndNoResend(): void
    {
        $this->makeBooking();
        $logs = $this->app->db()->query("SELECT * FROM notification_logs WHERE channel = 'whatsapp'")->fetchAll();
        $this->assertCount(1, $logs, 'only the consenting user is queued');
        $this->assertSame('+967777000111', $logs[0]['destination']);

        $this->http->push(200, ['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.ABC123']]]);
        $stats = $this->app->service(SchedulerService::class)->processQueue();
        $this->assertSame(1, $stats['sent']);
        $body = json_decode($this->http->requests[0]['body'], true);
        $this->assertSame('967777000111', $body['to']);
        $this->assertSame('template', $body['type']);
        $this->assertSame('booking_confirmation', $body['template']['name']);
        $this->assertCount(4, $body['template']['components'][0]['parameters']);

        $again = $this->app->service(SchedulerService::class)->processQueue();
        $this->assertSame(0, $again['sent']);
        $this->assertCount(1, $this->http->requests, 'a sent message is never re-sent');

        $payload = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.ABC123', 'status' => 'delivered', 'timestamp' => (string) time()]]]]]]]]);
        [$s] = $this->call('POST', '/webhooks/whatsapp', [], null, [], ['x-hub-signature-256' => 'sha256=bad']);
        $this->assertSame(403, $s);
        $req = new \TurathManager\Core\Request('POST', '/webhooks/whatsapp', [], json_decode($payload, true), ['x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $payload, 'app-secret-xyz')], [], '1.1.1.1', $payload);
        $this->assertSame(200, $this->app->handle($req)->status);
        $this->assertSame('delivered', $this->app->db()->query("SELECT status FROM notification_logs WHERE provider_message_id = 'wamid.ABC123'")->fetchColumn());
        // حالة أقدم (sent) لا تخفض الحالة
        $older = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.ABC123', 'status' => 'sent']]]]]]]]);
        $this->app->handle(new \TurathManager\Core\Request('POST', '/webhooks/whatsapp', [], [], ['x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $older, 'app-secret-xyz')], [], '1.1.1.1', $older));
        $this->assertSame('delivered', $this->app->db()->query("SELECT status FROM notification_logs WHERE provider_message_id = 'wamid.ABC123'")->fetchColumn());
    }

    public function testTransientFailureRetriesWithoutDuplicates(): void
    {
        $this->makeBooking();
        $sched = $this->app->service(SchedulerService::class);
        $this->http->push(503, ['error' => ['message' => 'Service unavailable', 'code' => 131000]]);
        $this->assertSame(1, $sched->processQueue()['retry']);
        $this->assertSame(0, $sched->processQueue()['sent'], 'waits for backoff');
        $this->app->clock->freeze(new \DateTimeImmutable('+2 minutes'));
        $this->http->push(200, ['messages' => [['id' => 'wamid.RETRY1']]]);
        $this->assertSame(1, $sched->processQueue()['sent']);
        $this->assertSame(0, $sched->processQueue()['sent']);
        $this->app->clock->freeze(null);
        $this->assertCount(2, $this->http->requests);
        $row = $this->app->db()->query("SELECT status, attempts FROM notification_logs WHERE channel = 'whatsapp'")->fetch();
        $this->assertSame('sent', $row['status']);
        $this->assertSame(2, (int) $row['attempts']);
    }

    public function testPermanentFailureIsRecordedAndNotifiesAdmins(): void
    {
        $this->makeBooking();
        $this->http->push(400, ['error' => ['message' => 'Template name does not exist', 'code' => 132001]]);
        $stats = $this->app->service(SchedulerService::class)->processQueue();
        $this->assertSame(1, $stats['failed']);
        $this->assertStringContainsString('132001', (string) $this->app->db()->query("SELECT last_error FROM notification_logs WHERE channel = 'whatsapp'")->fetchColumn());
        $this->assertSame(1, (int) $this->app->db()->query("SELECT COUNT(*) FROM notifications WHERE type = 'whatsapp_failed'")->fetchColumn());
    }

    public function testTestModeSkipsNonAllowedNumbersAndProductionRequiresApprovedUtility(): void
    {
        $this->ok('PUT', '/whatsapp/settings', ['mode' => 'test', 'is_enabled' => true, 'phone_number_id' => '9876543210', 'business_account_id' => '1234567890', 'test_recipients' => ['777999999']], $this->t);
        $this->makeBooking();
        $stats = $this->app->service(SchedulerService::class)->processQueue();
        $this->assertSame(1, $stats['skipped']);
        $this->assertCount(0, $this->http->requests);

        $this->ok('PUT', '/whatsapp/settings', ['mode' => 'production', 'is_enabled' => true, 'phone_number_id' => '9876543210', 'business_account_id' => '1234567890'], $this->t);
        $this->http->push(200, ['data' => [['name' => 'booking_confirmation', 'status' => 'APPROVED', 'category' => 'UTILITY', 'language' => 'ar']]]);
        $sync = $this->ok('POST', '/whatsapp/sync-templates', [], $this->t)['data'];
        $this->assertSame('APPROVED', $sync[0]['status']);
        $this->assertSame('NOT_FOUND', $sync[1]['status']);
        $this->makeBooking();
        $this->http->push(200, ['messages' => [['id' => 'wamid.PROD1']]]);
        $this->assertSame(1, $this->app->service(SchedulerService::class)->processQueue()['sent']);
    }

    public function testWebhookVerification(): void
    {
        [$s, $d] = $this->call('GET', '/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345');
        $this->assertSame(200, $s);
        $this->assertSame('12345', $d);
        [$s] = $this->call('GET', '/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345');
        $this->assertSame(403, $s);
    }
}
