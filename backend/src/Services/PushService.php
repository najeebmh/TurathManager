<?php

declare(strict_types=1);

namespace TurathManager\Services;

use TurathManager\Auth\CurrentUser;
use TurathManager\Core\App;
use TurathManager\Core\HttpException;
use TurathManager\Core\Uuid;
use TurathManager\Repositories\Generic;
use TurathManager\Support\HttpClient;

/**
 * إشعارات Push عبر Firebase Cloud Messaging (HTTP v1).
 * مفتاح حساب الخدمة يبقى على الخادم فقط (FCM_SERVICE_ACCOUNT_FILE).
 */
final class PushService
{
    private Generic $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new Generic($app->db());
    }

    public function isConfigured(): bool
    {
        $f = $this->app->config['fcm']['service_account_file'];
        return $this->app->config['fcm']['project_id'] !== '' && $f !== '' && is_readable($f);
    }

    public function register(CurrentUser $u, array $input): array
    {
        $d = $this->app->validator->validate($input, [
            'device_id' => 'required|uuid',
            'platform' => 'required|in:android,ios,web,windows',
            'token' => 'required|string|max:512',
        ], ['device_id' => 'معرف الجهاز', 'platform' => 'المنصة', 'token' => 'رمز الإشعارات']);
        $hash = hash('sha256', $d['token']);
        $now = $this->app->clock->nowDb();
        $existing = $this->repo->one('SELECT id FROM push_devices WHERE token_hash = ?', [$hash]);
        if ($existing) {
            $this->repo->exec('UPDATE push_devices SET user_id = ?, device_id = ?, platform = ?, is_active = 1, last_seen_at = ?, updated_at = ? WHERE id = ?', [$u->id, $d['device_id'], $d['platform'], $now, $now, $existing['id']]);
            $id = $existing['id'];
        } else {
            $this->repo->exec('UPDATE push_devices SET is_active = 0, updated_at = ? WHERE device_id = ? AND user_id = ?', [$now, $d['device_id'], $u->id]);
            $id = Uuid::v4();
            $this->repo->exec('INSERT INTO push_devices (id, user_id, device_id, platform, token, token_hash, is_active, last_seen_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)', [
                $id, $u->id, $d['device_id'], $d['platform'], $d['token'], $hash, $now, $now, $now,
            ]);
        }
        return ['id' => $id, 'fcm_configured' => $this->isConfigured()];
    }

    public function unregister(CurrentUser $u, string $deviceId): void
    {
        if (!Uuid::isValid($deviceId)) {
            throw HttpException::notFound();
        }
        $this->repo->exec('UPDATE push_devices SET is_active = 0, updated_at = ? WHERE device_id = ? AND user_id = ?', [$this->app->clock->nowDb(), $deviceId, $u->id]);
    }

    /** @return array{ok:bool,transient:bool,error:?string,skipped?:bool,message_id:?string} */
    public function sendToUser(string $userId, string $title, string $body, array $data = []): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'transient' => false, 'skipped' => true, 'error' => 'FCM غير مضبوط على الخادم', 'message_id' => null];
        }
        $devices = $this->repo->all('SELECT id, token FROM push_devices WHERE user_id = ? AND is_active = 1', [$userId]);
        if (!$devices) {
            return ['ok' => false, 'transient' => false, 'skipped' => true, 'error' => 'لا توجد أجهزة مسجلة للمستخدم', 'message_id' => null];
        }
        $token = $this->accessToken();
        if ($token === null) {
            return ['ok' => false, 'transient' => true, 'error' => 'تعذر الحصول على رمز وصول FCM', 'message_id' => null];
        }
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($this->app->config['fcm']['project_id']) . '/messages:send';
        $sent = 0;
        $transient = false;
        $lastError = null;
        $ids = [];
        foreach ($devices as $dev) {
            $msg = ['message' => [
                'token' => $dev['token'],
                'notification' => ['title' => $title, 'body' => mb_substr($body, 0, 500)],
                'data' => array_map('strval', array_filter($data, static fn ($v) => $v !== null)),
                'android' => ['priority' => 'HIGH', 'notification' => ['channel_id' => 'turath_bookings', 'icon' => 'ic_stat_notify']],
            ]];
            $r = $this->app->service(HttpClient::class)->request('POST', $url, ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'], json_encode($msg, JSON_UNESCAPED_UNICODE));
            $json = json_decode($r['body'], true);
            if ($r['status'] === 200 && isset($json['name'])) {
                $sent++;
                $ids[] = $json['name'];
                continue;
            }
            $status = (string) ($json['error']['status'] ?? '');
            if (in_array($status, ['NOT_FOUND', 'UNREGISTERED'], true) || $r['status'] === 404) {
                $this->repo->exec('UPDATE push_devices SET is_active = 0, updated_at = ? WHERE id = ?', [$this->app->clock->nowDb(), $dev['id']]);
            } elseif ($r['status'] === 0 || $r['status'] >= 500 || $r['status'] === 429) {
                $transient = true;
            }
            $lastError = mb_substr((string) ($json['error']['message'] ?? $r['error'] ?? ('HTTP ' . $r['status'])), 0, 450);
        }
        return ['ok' => $sent > 0, 'transient' => $sent === 0 && $transient, 'error' => $sent > 0 ? null : $lastError, 'message_id' => $ids ? implode(',', $ids) : null];
    }

    private function accessToken(): ?string
    {
        $cache = $this->app->config['paths']['storage'] . '/cache/fcm_token.json';
        if (is_file($cache)) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c) && ($c['expires'] ?? 0) > time() + 60) {
                return $c['token'];
            }
        }
        $sa = json_decode((string) file_get_contents($this->app->config['fcm']['service_account_file']), true);
        if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) {
            return null;
        }
        $b64 = static fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64(json_encode([
            'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
        ]));
        if (!openssl_sign("$header.$claims", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $r = $this->app->service(HttpClient::class)->request('POST', 'https://oauth2.googleapis.com/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$header.$claims." . $b64($sig),
        ]));
        $json = json_decode($r['body'], true);
        if ($r['status'] !== 200 || empty($json['access_token'])) {
            return null;
        }
        @file_put_contents($cache, json_encode(['token' => $json['access_token'], 'expires' => $now + (int) ($json['expires_in'] ?? 3600)]), LOCK_EX);
        @chmod($cache, 0600);
        return $json['access_token'];
    }
}
