<?php

declare(strict_types=1);

namespace TurathManager\Services;

use TurathManager\Core\App;
use TurathManager\Repositories\Generic;

/**
 * المهام الخلفية المجدولة (تعمل عبر Cron أو Task Scheduler وليس من المتصفح):
 * توليد التذكيرات، إرسال طابور Push وواتساب مع إعادة المحاولة، وتنظيف الرموز المنتهية.
 */
final class SchedulerService
{
    public const MAX_ATTEMPTS = 5;
    /** دقائق الانتظار بين المحاولات */
    public const BACKOFF = [1, 5, 15, 60, 180];

    private Generic $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new Generic($app->db());
    }

    public function runAll(): array
    {
        return [
            'reminders' => $this->runJob('reminders', fn () => $this->app->service(NotificationService::class)->generateReminders()),
            'delivery' => $this->runJob('delivery', fn () => $this->processQueue()),
            'cleanup' => $this->runJob('cleanup', fn () => $this->cleanup()),
        ];
    }

    /** قفل بسيط عبر قاعدة البيانات يمنع تشغيل نفس المهمة مرتين بالتوازي */
    public function runJob(string $name, callable $fn): mixed
    {
        $now = $this->app->clock->nowDb();
        $lockUntil = $this->app->clock->now()->modify('+10 minutes')->format('Y-m-d H:i:s');
        $this->repo->exec('INSERT IGNORE INTO scheduled_jobs (job_name) VALUES (?)', [$name]);
        $got = $this->repo->exec('UPDATE scheduled_jobs SET locked_until = ?, last_started_at = ? WHERE job_name = ? AND (locked_until IS NULL OR locked_until < ?)', [$lockUntil, $now, $name, $now]);
        if ($got === 0) {
            return ['skipped' => 'locked'];
        }
        try {
            $result = $fn();
            $this->repo->exec("UPDATE scheduled_jobs SET locked_until = NULL, last_finished_at = ?, last_status = 'ok', last_error = NULL, runs = runs + 1 WHERE job_name = ?", [$this->app->clock->nowDb(), $name]);
            return $result;
        } catch (\Throwable $e) {
            $this->repo->exec("UPDATE scheduled_jobs SET locked_until = NULL, last_finished_at = ?, last_status = 'error', last_error = ?, runs = runs + 1 WHERE job_name = ?", [$this->app->clock->nowDb(), mb_substr($e->getMessage(), 0, 500), $name]);
            $this->app->logger->error('Scheduled job failed', ['job' => $name, 'error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    public function processQueue(int $limit = 50): array
    {
        $now = $this->app->clock->nowDb();
        $stats = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0, 'cancelled' => 0, 'stale' => 0];
        // رسائل بقيت في حالة "جاري الإرسال" بسبب انقطاع: لا يُعاد إرسالها تلقائياً لتجنب التكرار
        $stats['stale'] = $this->repo->exec("UPDATE notification_logs SET status = 'failed', last_error = 'انقطع الإرسال ونتيجته غير معروفة - لم يُعد الإرسال لتجنب التكرار', updated_at = ?
            WHERE status = 'sending' AND updated_at < ?", [$now, $this->app->clock->now()->modify('-15 minutes')->format('Y-m-d H:i:s')]);

        $rows = $this->repo->all("SELECT l.*, n.type, n.booking_id, n.data FROM notification_logs l JOIN notifications n ON n.id = l.notification_id
            WHERE l.status = 'queued' AND (l.next_attempt_at IS NULL OR l.next_attempt_at <= ?) ORDER BY l.id LIMIT $limit", [$now]);
        $notifications = $this->app->service(NotificationService::class);
        foreach ($rows as $log) {
            $claimed = $this->repo->exec("UPDATE notification_logs SET status = 'sending', attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = 'queued'", [$this->app->clock->nowDb(), $log['id']]);
            if ($claimed === 0) {
                continue;
            }
            $data = json_decode((string) $log['data'], true) ?: [];
            if ($log['booking_id'] && in_array($log['type'], ['installation_upcoming', 'removal_upcoming'], true)
                && !$notifications->reminderStillValid($log['type'], $log['booking_id'], $data)) {
                $this->finish($log['id'], 'cancelled', 'تغير موعد الحجز أو أُلغي');
                $stats['cancelled']++;
                continue;
            }
            $payload = json_decode((string) $log['request_payload'], true) ?: [];
            try {
                $res = $log['channel'] === 'whatsapp'
                    ? $this->app->service(WhatsAppService::class)->sendTemplate((string) $log['destination'], (string) $payload['template'], (string) ($payload['language'] ?? 'ar'), (array) ($payload['params'] ?? []))
                    : $this->app->service(PushService::class)->sendToUser((string) $log['user_id'], (string) ($payload['title'] ?? ''), (string) ($payload['body'] ?? ''), ['booking_id' => $payload['booking_id'] ?? null, 'link' => $log['booking_id'] ? '#/bookings/' . $log['booking_id'] : null]);
            } catch (\Throwable $e) {
                $res = ['ok' => false, 'transient' => true, 'error' => mb_substr($e->getMessage(), 0, 450), 'message_id' => null];
            }
            if ($res['ok']) {
                $this->repo->exec("UPDATE notification_logs SET status = 'sent', provider_message_id = ?, sent_at = ?, last_error = NULL, response_payload = ?, updated_at = ? WHERE id = ?", [
                    $log['channel'] === 'whatsapp' ? $res['message_id'] : null, $this->app->clock->nowDb(),
                    json_encode($res['response'] ?? ['message_id' => $res['message_id']], JSON_UNESCAPED_UNICODE), $this->app->clock->nowDb(), $log['id'],
                ]);
                $stats['sent']++;
            } elseif (!empty($res['skipped'])) {
                $this->finish($log['id'], 'skipped', $res['error']);
                $stats['skipped']++;
            } elseif ($res['transient'] && (int) $log['attempts'] + 1 < self::MAX_ATTEMPTS) {
                $wait = self::BACKOFF[min((int) $log['attempts'], count(self::BACKOFF) - 1)];
                $this->repo->exec("UPDATE notification_logs SET status = 'queued', next_attempt_at = ?, last_error = ?, updated_at = ? WHERE id = ?", [
                    $this->app->clock->now()->modify("+$wait minutes")->format('Y-m-d H:i:s'), $res['error'], $this->app->clock->nowDb(), $log['id'],
                ]);
                $stats['retry']++;
            } else {
                $this->finish($log['id'], 'failed', $res['error']);
                $stats['failed']++;
                if ($log['channel'] === 'whatsapp') {
                    $notifications->systemEvent('whatsapp_failed', ['error' => (string) $res['error']], 'whatsapp_failed:log:' . $log['id']);
                }
            }
        }
        return $stats;
    }

    private function finish(int|string $id, string $status, ?string $error): void
    {
        $this->repo->exec('UPDATE notification_logs SET status = ?, last_error = ?, updated_at = ? WHERE id = ?', [$status, $error, $this->app->clock->nowDb(), $id]);
    }

    public function cleanup(): array
    {
        $now = $this->app->clock->nowDb();
        return [
            'expired_tokens' => $this->repo->exec('DELETE FROM access_tokens WHERE expires_at < ? OR (revoked_at IS NOT NULL AND revoked_at < ?)', [$now, $this->app->clock->now()->modify('-30 days')->format('Y-m-d H:i:s')]),
            'old_login_attempts' => $this->repo->exec('DELETE FROM login_attempts WHERE created_at < ?', [$this->app->clock->now()->modify('-90 days')->format('Y-m-d H:i:s')]),
            'old_idempotency' => $this->repo->exec('DELETE FROM idempotency_keys WHERE created_at < ?', [$this->app->clock->now()->modify('-30 days')->format('Y-m-d H:i:s')]),
        ];
    }

    public function status(): array
    {
        return $this->repo->all('SELECT job_name, last_started_at, last_finished_at, last_status, last_error, runs FROM scheduled_jobs ORDER BY job_name');
    }
}
