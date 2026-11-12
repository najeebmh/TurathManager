<?php

declare(strict_types=1);

namespace TurathManager\Services;

use TurathManager\Auth\CurrentUser;
use TurathManager\Core\App;
use TurathManager\Core\Clock;
use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Repositories\Generic;
use TurathManager\Support\Audit;
use TurathManager\Support\Crypto;
use TurathManager\Support\HttpClient;
use TurathManager\Support\Settings;

/**
 * تكامل رسمي مع WhatsApp Business Cloud API من Meta.
 * الأسرار تُقرأ من .env أو تُخزن مشفرة في قاعدة البيانات، ولا تُرسل أبداً إلى الواجهة.
 */
final class WhatsAppService
{
    /** رموز أخطاء Meta المؤقتة التي تستحق إعادة المحاولة */
    public const TRANSIENT_CODES = [1, 2, 4, 17, 341, 80007, 130429, 131000, 131016, 131056, 133004];

    private Generic $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new Generic($app->db());
    }

    private function crypto(): ?Crypto
    {
        try {
            return new Crypto((string) $this->app->config['app']['key']);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** @return array<string,mixed> الإعدادات الكاملة للاستخدام الداخلي فقط */
    public function config(): array
    {
        $row = $this->repo->one('SELECT * FROM whatsapp_settings WHERE id = 1') ?? [];
        $c = $this->crypto();
        $env = $this->app->config['whatsapp'];
        return [
            'business_account_id' => $row['business_account_id'] ?? null,
            'phone_number_id' => $row['phone_number_id'] ?? null,
            'access_token' => ($c?->decrypt($row['access_token_enc'] ?? null)) ?: ($env['access_token'] ?: null),
            'app_secret' => ($c?->decrypt($row['app_secret_enc'] ?? null)) ?: ($env['app_secret'] ?: null),
            'verify_token' => ($c?->decrypt($row['verify_token_enc'] ?? null)) ?: ($env['verify_token'] ?: null),
            'mode' => $row['mode'] ?? 'test',
            'is_enabled' => (bool) ($row['is_enabled'] ?? false),
            'connection_status' => $row['connection_status'] ?? 'not_configured',
            'last_checked_at' => $row['last_checked_at'] ?? null,
            'last_error' => $row['last_error'] ?? null,
        ];
    }

    /** إعدادات آمنة للعرض: الأسرار تظهر كمؤشر "مضبوط" فقط */
    public function publicSettings(): array
    {
        $c = $this->config();
        return [
            'business_account_id' => $c['business_account_id'],
            'phone_number_id' => $c['phone_number_id'],
            'access_token_set' => $c['access_token'] !== null,
            'app_secret_set' => $c['app_secret'] !== null,
            'verify_token_set' => $c['verify_token'] !== null,
            'mode' => $c['mode'],
            'is_enabled' => $c['is_enabled'],
            'connection_status' => $c['connection_status'],
            'last_checked_at' => Clock::toIso($c['last_checked_at']),
            'last_error' => $c['last_error'],
            'test_recipients' => array_values(array_filter(explode(',', (string) $this->app->service(Settings::class)->get('whatsapp_test_recipients', '')))),
            'webhook_path' => '/api/webhooks/whatsapp',
        ];
    }

    public function saveSettings(array $input, CurrentUser $actor, ?Request $req = null): array
    {
        $d = $this->app->validator->validate($input, [
            'business_account_id' => 'nullable|string|max:64',
            'phone_number_id' => 'nullable|string|max:64',
            'access_token' => 'nullable|string|max:1000',
            'app_secret' => 'nullable|string|max:200',
            'verify_token' => 'nullable|string|max:200',
            'mode' => 'required|in:test,production',
            'is_enabled' => 'nullable|bool',
            'test_recipients' => 'nullable|array',
        ], ['mode' => 'وضع التشغيل']);
        foreach (['business_account_id', 'phone_number_id'] as $k) {
            if (!empty($d[$k]) && !ctype_digit($d[$k])) {
                throw HttpException::validation([$k => 'المعرف يجب أن يتكون من أرقام فقط']);
            }
        }
        $c = $this->crypto();
        $secretsProvided = array_filter(['access_token', 'app_secret', 'verify_token'], static fn ($k) => !empty($d[$k]));
        if ($secretsProvided && $c === null) {
            throw HttpException::conflict('يجب ضبط APP_KEY في ملف .env قبل حفظ الأسرار');
        }
        $sets = ['business_account_id = ?', 'phone_number_id = ?', 'mode = ?', 'is_enabled = ?', 'updated_by = ?', 'updated_at = ?'];
        $params = [$d['business_account_id'] ?? null, $d['phone_number_id'] ?? null, $d['mode'], !empty($d['is_enabled']) ? 1 : 0, $actor->id, $this->app->clock->nowDb()];
        foreach ($secretsProvided as $k) {
            $sets[] = "{$k}_enc = ?";
            $params[] = $c->encrypt($d[$k]);
        }
        $this->repo->exec('UPDATE whatsapp_settings SET ' . implode(', ', $sets) . ' WHERE id = 1', $params);
        if (array_key_exists('test_recipients', $d)) {
            $nums = [];
            foreach ((array) ($d['test_recipients'] ?? []) as $n) {
                $p = \TurathManager\Core\Validator::normalizePhone((string) $n, $this->app->config['app']['default_country_code']);
                if ($p === null) {
                    throw HttpException::validation(['test_recipients' => 'رقم اختبار غير صالح: ' . $n]);
                }
                $nums[] = $p;
            }
            $this->app->service(Settings::class)->set('whatsapp_test_recipients', implode(',', array_unique($nums)), $actor->id);
        }
        $this->app->service(Audit::class)->log($actor->id, 'whatsapp.settings_updated', 'whatsapp_settings', '1', ['mode' => $d['mode'], 'secrets_updated' => array_values($secretsProvided)], $req);
        return $this->publicSettings();
    }

    private function graph(string $path): string
    {
        return rtrim($this->app->config['whatsapp']['graph_url'], '/') . '/' . $this->app->config['whatsapp']['graph_version'] . '/' . ltrim($path, '/');
    }

    /** @return array{status:int,json:?array,error:?string} */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $c = $this->config();
        if (!$c['access_token']) {
            return ['status' => 0, 'json' => null, 'error' => 'رمز الوصول غير مضبوط'];
        }
        $resp = $this->app->service(HttpClient::class)->request($method, $this->graph($path), [
            'Authorization' => 'Bearer ' . $c['access_token'],
            'Content-Type' => 'application/json',
        ], $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null);
        $json = json_decode($resp['body'], true);
        return ['status' => $resp['status'], 'json' => is_array($json) ? $json : null, 'error' => $resp['error']];
    }

    public function testConnection(CurrentUser $actor, ?Request $req = null): array
    {
        $c = $this->config();
        if (!$c['phone_number_id']) {
            throw HttpException::validation(['phone_number_id' => 'Phone Number ID مطلوب']);
        }
        $r = $this->call('GET', $c['phone_number_id'] . '?fields=display_phone_number,verified_name,quality_rating');
        $ok = $r['status'] === 200 && isset($r['json']['id']);
        $err = $ok ? null : mb_substr((string) ($r['json']['error']['message'] ?? $r['error'] ?? ('HTTP ' . $r['status'])), 0, 500);
        $this->repo->exec('UPDATE whatsapp_settings SET connection_status = ?, last_checked_at = ?, last_error = ? WHERE id = 1', [
            $ok ? 'connected' : 'error', $this->app->clock->nowDb(), $err,
        ]);
        $this->app->service(Audit::class)->log($actor->id, 'whatsapp.connection_test', 'whatsapp_settings', '1', ['ok' => $ok], $req);
        return [
            'ok' => $ok,
            'error' => $err,
            'phone' => $ok ? [
                'display_phone_number' => $r['json']['display_phone_number'] ?? null,
                'verified_name' => $r['json']['verified_name'] ?? null,
                'quality_rating' => $r['json']['quality_rating'] ?? null,
            ] : null,
        ];
    }

    /** مزامنة حالة القوالب وتصنيفها من Meta */
    public function syncTemplates(CurrentUser $actor): array
    {
        $c = $this->config();
        if (!$c['business_account_id']) {
            throw HttpException::validation(['business_account_id' => 'WhatsApp Business Account ID مطلوب']);
        }
        $r = $this->call('GET', $c['business_account_id'] . '/message_templates?fields=name,status,category,language&limit=200');
        if ($r['status'] !== 200 || !isset($r['json']['data'])) {
            throw HttpException::conflict('تعذر جلب القوالب من Meta: ' . ($r['json']['error']['message'] ?? $r['error'] ?? ('HTTP ' . $r['status'])));
        }
        $remote = [];
        foreach ($r['json']['data'] as $t) {
            $remote[$t['name'] . '|' . ($t['language'] ?? '')] = $t;
            $remote[$t['name'] . '|*'] ??= $t;
        }
        $updated = [];
        foreach ($this->repo->all("SELECT id, whatsapp_template_name, whatsapp_language FROM notification_templates WHERE channel = 'whatsapp'") as $tpl) {
            $t = $remote[$tpl['whatsapp_template_name'] . '|' . $tpl['whatsapp_language']] ?? $remote[$tpl['whatsapp_template_name'] . '|*'] ?? null;
            $status = $t ? strtoupper((string) $t['status']) : 'NOT_FOUND';
            $this->repo->exec('UPDATE notification_templates SET meta_status = ?, whatsapp_category = COALESCE(?, whatsapp_category) WHERE id = ?', [$status, $t['category'] ?? null, $tpl['id']]);
            $updated[] = ['name' => $tpl['whatsapp_template_name'], 'status' => $status, 'category' => $t['category'] ?? null];
        }
        $this->app->service(Audit::class)->log($actor->id, 'whatsapp.templates_synced', 'notification_template', null, ['templates' => $updated]);
        return $updated;
    }

    /**
     * إرسال قالب. لا يُعتبر ناجحاً إلا بعد استلام معرف رسالة من Meta.
     * @return array{ok:bool,transient:bool,message_id:?string,error:?string,skipped?:bool,response:?array}
     */
    public function sendTemplate(string $to, string $template, string $language, array $params): array
    {
        $c = $this->config();
        if (!$c['is_enabled'] || !$c['phone_number_id'] || !$c['access_token']) {
            return ['ok' => false, 'transient' => false, 'skipped' => true, 'message_id' => null, 'error' => 'واتساب غير مفعل أو غير مضبوط', 'response' => null];
        }
        if ($c['mode'] === 'test') {
            $allowed = array_filter(explode(',', (string) $this->app->service(Settings::class)->get('whatsapp_test_recipients', '')));
            if (!in_array($to, $allowed, true)) {
                return ['ok' => false, 'transient' => false, 'skipped' => true, 'message_id' => null, 'error' => 'وضع الاختبار: الرقم ليس ضمن أرقام الاختبار المسموحة', 'response' => null];
            }
        } else {
            $tpl = $this->repo->one("SELECT meta_status, whatsapp_category FROM notification_templates WHERE channel = 'whatsapp' AND whatsapp_template_name = ? LIMIT 1", [$template]);
            if (!$tpl || $tpl['meta_status'] !== 'APPROVED' || strtoupper((string) $tpl['whatsapp_category']) !== 'UTILITY') {
                return ['ok' => false, 'transient' => false, 'skipped' => true, 'message_id' => null, 'error' => 'القالب غير معتمد من Meta كقالب Utility', 'response' => null];
            }
        }
        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($to, '+'),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
                'components' => $params ? [[
                    'type' => 'body',
                    'parameters' => array_map(static fn ($p) => ['type' => 'text', 'text' => mb_substr((string) $p, 0, 1000) ?: '-'], $params),
                ]] : [],
            ],
        ];
        $r = $this->call('POST', $c['phone_number_id'] . '/messages', $body);
        $msgId = $r['json']['messages'][0]['id'] ?? null;
        if (($r['status'] >= 200 && $r['status'] < 300) && $msgId) {
            return ['ok' => true, 'transient' => false, 'message_id' => (string) $msgId, 'error' => null, 'response' => $r['json']];
        }
        $code = (int) ($r['json']['error']['code'] ?? 0);
        $transient = $r['status'] === 0 || $r['status'] >= 500 || $r['status'] === 429 || in_array($code, self::TRANSIENT_CODES, true);
        $error = mb_substr((string) ($r['json']['error']['message'] ?? $r['error'] ?? ('HTTP ' . $r['status'])), 0, 450) . ($code ? " (code $code)" : '');
        return ['ok' => false, 'transient' => $transient, 'message_id' => null, 'error' => $error, 'response' => $r['json']];
    }

    public function verifyWebhook(array $query): ?string
    {
        $c = $this->config();
        $mode = $query['hub_mode'] ?? $query['hub.mode'] ?? null;
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = $query['hub_challenge'] ?? $query['hub.challenge'] ?? null;
        if ($mode === 'subscribe' && $c['verify_token'] && hash_equals($c['verify_token'], $token) && is_string($challenge)) {
            return $challenge;
        }
        return null;
    }

    public function validSignature(string $raw, ?string $header): bool
    {
        $secret = $this->config()['app_secret'];
        if (!$secret || !$header || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $header);
    }

    /** معالجة Webhook: تحديث حالات الرسائل دون خفض الحالة (read > delivered > sent) */
    public function handleWebhook(string $raw, ?string $signature): bool
    {
        $valid = $this->validSignature($raw, $signature);
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return false;
        }
        $this->repo->exec('INSERT INTO whatsapp_webhook_events (payload, signature_valid, created_at) VALUES (?, ?, ?)', [$raw, $valid ? 1 : 0, $this->app->clock->nowDb()]);
        $eventId = (int) $this->app->db()->lastInsertId();
        if (!$valid) {
            return false;
        }
        $rank = ['queued' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $st) {
                    $msgId = (string) ($st['id'] ?? '');
                    $status = (string) ($st['status'] ?? '');
                    if ($msgId === '' || !isset($rank[$status])) {
                        continue;
                    }
                    $log = $this->repo->one('SELECT id, status, notification_id FROM notification_logs WHERE provider_message_id = ?', [$msgId]);
                    if (!$log) {
                        continue;
                    }
                    if ($status !== 'failed' && $rank[$status] <= ($rank[$log['status']] ?? 0)) {
                        continue;
                    }
                    $ts = isset($st['timestamp']) ? gmdate('Y-m-d H:i:s', (int) $st['timestamp']) : $this->app->clock->nowDb();
                    $err = $status === 'failed' ? mb_substr((string) ($st['errors'][0]['title'] ?? $st['errors'][0]['message'] ?? 'فشل التسليم'), 0, 450) : null;
                    $this->repo->exec('UPDATE notification_logs SET status = ?, delivered_at = IF(? IN (\'delivered\',\'read\') AND delivered_at IS NULL, ?, delivered_at),
                        last_error = COALESCE(?, last_error), response_payload = ?, updated_at = ? WHERE id = ?', [
                        $status, $status, $ts, $err, json_encode($st, JSON_UNESCAPED_UNICODE), $this->app->clock->nowDb(), $log['id'],
                    ]);
                    if ($status === 'failed') {
                        $this->app->service(NotificationService::class)->systemEvent('whatsapp_failed', ['error' => $err], 'whatsapp_failed:log:' . $log['id']);
                    }
                }
            }
        }
        $this->repo->exec('UPDATE whatsapp_webhook_events SET processed_at = ? WHERE id = ?', [$this->app->clock->nowDb(), $eventId]);
        return true;
    }
}
