<?php

declare(strict_types=1);

namespace TurathManager\Services;

use TurathManager\Auth\CurrentUser;
use TurathManager\Core\App;
use TurathManager\Core\Clock;
use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Uuid;
use TurathManager\Repositories\Generic;
use TurathManager\Support\Audit;
use TurathManager\Support\Settings;

/**
 * نظام الإشعارات: إنشاء إشعارات داخلية مع منع التكرار (dedupe_key)،
 * وتحديد المستلمين حسب اشتراكات يديرها المدير، ووضع رسائل Push وواتساب في طابور الإرسال.
 */
final class NotificationService
{
    public const EVENTS = [
        'booking_created' => 'تسجيل حجز',
        'installation_upcoming' => 'اقتراب موعد التركيب',
        'removal_upcoming' => 'موعد الفك قبل يوم',
        'booking_updated' => 'تعديل الحجز',
        'booking_cancelled' => 'إلغاء الحجز',
        'booking_conflict' => 'تعارض المواعيد',
        'sync_failed' => 'فشل المزامنة',
        'whatsapp_failed' => 'فشل إرسال واتساب',
    ];
    public const CHANNELS = ['in_app' => 'داخل النظام', 'push' => 'إشعار الجوال/سطح المكتب', 'whatsapp' => 'واتساب'];
    public const VARIABLES = ['customer_name', 'customer_phone', 'category', 'item_name', 'center_name', 'address', 'installation_date', 'removal_date', 'booking_no'];

    private Generic $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new Generic($app->db());
    }

    public static function render(string $template, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', static fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $template) ?? $template;
    }

    public function localDate(?string $db): string
    {
        if (!$db) {
            return '';
        }
        $d = new \DateTimeImmutable($db, new \DateTimeZone('UTC'));
        return $d->setTimezone($this->app->clock->localTz())->format('Y-m-d h:i A');
    }

    /** متغيرات القالب لحجز */
    public function bookingVars(string $bookingId): ?array
    {
        $b = $this->repo->one('SELECT b.*, c.full_name AS customer_name, c.phone AS customer_phone, ce.name AS center_name, ce.address AS center_address
            FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN centers ce ON ce.id = b.center_id WHERE b.id = ?', [$bookingId]);
        if (!$b) {
            return null;
        }
        $items = $this->repo->all("SELECT p.name, ca.name AS category, bi.quantity FROM booking_items bi JOIN products p ON p.id = bi.product_id JOIN categories ca ON ca.id = p.category_id
            WHERE bi.booking_id = ? AND bi.status <> 'cancelled' ORDER BY bi.seq", [$bookingId]);
        return [
            'booking' => $b,
            'vars' => [
                'booking_no' => $b['booking_no'] ?? 'مؤقت',
                'customer_name' => $b['customer_name'],
                'customer_phone' => $b['customer_phone'],
                'category' => implode('، ', array_unique(array_column($items, 'category'))),
                'item_name' => implode('، ', array_map(static fn ($i) => $i['name'] . ((int) $i['quantity'] > 1 ? ' ×' . $i['quantity'] : ''), $items)),
                'center_name' => $b['center_name'],
                'address' => $b['address'] ?: ($b['center_address'] ?? ''),
                'installation_date' => $this->localDate($b['install_at']),
                'removal_date' => $this->localDate($b['removal_at']),
            ],
        ];
    }

    public function bookingEvent(string $type, string $bookingId, string $variant, array $extraData = []): ?string
    {
        $ctx = $this->bookingVars($bookingId);
        if (!$ctx) {
            return null;
        }
        if (in_array($type, ['booking_updated', 'booking_cancelled'], true)) {
            $this->cancelStaleReminders($bookingId);
        }
        return $this->dispatch($type, $ctx['vars'], "$type:$bookingId:$variant", $bookingId, $ctx['booking']['center_id'], $extraData);
    }

    /**
     * إنشاء إشعار مرة واحدة فقط لكل مفتاح dedupe، وتوزيعه على المستلمين.
     * @param array<string,mixed> $vars
     */
    public function dispatch(string $type, array $vars, string $dedupeKey, ?string $bookingId = null, ?string $centerId = null, array $data = []): ?string
    {
        $tpl = $this->repo->one("SELECT * FROM notification_templates WHERE template_key = ? AND channel = 'in_app' AND is_active = 1", [$type]);
        if (!$tpl) {
            return null;
        }
        $dedupeKey = mb_substr($dedupeKey, 0, 190);
        if ($this->repo->value('SELECT id FROM notifications WHERE dedupe_key = ?', [$dedupeKey])) {
            return null;
        }
        $id = Uuid::v4();
        $now = $this->app->clock->nowDb();
        $title = mb_substr(self::render($tpl['title'], $vars), 0, 255);
        $body = self::render($tpl['body'], $vars);
        try {
            $this->repo->exec('INSERT INTO notifications (id, type, title, body, booking_id, link, dedupe_key, data, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $id, $type, $title, $body, $bookingId, $bookingId ? '#/bookings/' . $bookingId : null, $dedupeKey,
                json_encode($data + ['vars' => $vars], JSON_UNESCAPED_UNICODE), $now,
            ]);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }

        $recipients = $this->recipients($type, $centerId);
        $insRecipient = $this->app->db()->prepare('INSERT IGNORE INTO notification_recipients (notification_id, user_id, created_at) VALUES (?, ?, ?)');
        foreach ($recipients['in_app'] as $uid) {
            $insRecipient->execute([$id, $uid, $now]);
        }
        foreach ($recipients['push'] as $uid) {
            $this->enqueue($id, $uid, 'push', null, ['title' => $title, 'body' => $body, 'booking_id' => $bookingId]);
        }
        $waTpl = $this->repo->one("SELECT * FROM notification_templates WHERE template_key = ? AND channel = 'whatsapp' AND is_active = 1", [$type]);
        if ($waTpl && $recipients['whatsapp']) {
            $in = implode(',', array_fill(0, count($recipients['whatsapp']), '?'));
            $users = $this->repo->all("SELECT id, whatsapp_number FROM users WHERE id IN ($in) AND whatsapp_opt_in = 1 AND whatsapp_number IS NOT NULL AND is_active = 1 AND deleted_at IS NULL", $recipients['whatsapp']);
            $params = array_map(static fn ($k) => (string) ($vars[trim($k)] ?? ''), array_filter(explode(',', (string) $waTpl['whatsapp_params'])));
            foreach ($users as $u) {
                $this->enqueue($id, $u['id'], 'whatsapp', $u['whatsapp_number'], [
                    'template' => $waTpl['whatsapp_template_name'], 'language' => $waTpl['whatsapp_language'] ?: 'ar', 'params' => $params,
                ]);
            }
        }
        return $id;
    }

    /** @return array{in_app:list<string>,push:list<string>,whatsapp:list<string>} */
    public function recipients(string $type, ?string $centerId): array
    {
        $rows = $this->repo->all("SELECT s.user_id, s.channel FROM notification_subscriptions s JOIN users u ON u.id = s.user_id
            WHERE s.event_type = ? AND (s.center_scope = '*' OR s.center_scope = ?) AND u.is_active = 1 AND u.deleted_at IS NULL", [$type, $centerId ?? '']);
        $out = ['in_app' => [], 'push' => [], 'whatsapp' => []];
        foreach ($rows as $r) {
            $out[$r['channel']][] = $r['user_id'];
        }
        $configured = (int) $this->repo->value('SELECT COUNT(*) FROM notification_subscriptions WHERE event_type = ?', [$type]);
        if ($configured === 0) {
            // قبل أن يحدد المدير المستلمين: يصل الإشعار الداخلي لمديري النظام فقط
            $out['in_app'] = array_column($this->repo->all("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                WHERE r.code = 'admin' AND u.is_active = 1 AND u.deleted_at IS NULL"), 'id');
        }
        return array_map(static fn ($l) => array_values(array_unique($l)), $out);
    }

    private function enqueue(string $notificationId, ?string $userId, string $channel, ?string $destination, array $payload): void
    {
        $this->app->db()->prepare('INSERT IGNORE INTO notification_logs (notification_id, user_id, channel, destination, status, attempts, next_attempt_at, idempotency_key, request_payload, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)')->execute([
            $notificationId, $userId, $channel, $destination, 'queued', $this->app->clock->nowDb(),
            "$notificationId:$userId:$channel", json_encode($payload, JSON_UNESCAPED_UNICODE), $this->app->clock->nowDb(), $this->app->clock->nowDb(),
        ]);
    }

    /** إلغاء رسائل التذكير المعلقة التي لم تعد تطابق مواعيد الحجز الحالية */
    public function cancelStaleReminders(string $bookingId): int
    {
        $rows = $this->repo->all("SELECT l.id, n.type, n.data, b.status FROM notification_logs l JOIN notifications n ON n.id = l.notification_id JOIN bookings b ON b.id = n.booking_id
            WHERE n.booking_id = ? AND n.type IN ('installation_upcoming','removal_upcoming') AND l.status = 'queued'", [$bookingId]);
        $n = 0;
        foreach ($rows as $r) {
            if (!$this->reminderStillValid($r['type'], $bookingId, json_decode((string) $r['data'], true) ?: [], $r['status'])) {
                $this->repo->exec("UPDATE notification_logs SET status = 'cancelled', last_error = ?, updated_at = ? WHERE id = ? AND status = 'queued'", ['تغير موعد الحجز أو أُلغي', $this->app->clock->nowDb(), $r['id']]);
                $n++;
            }
        }
        return $n;
    }

    public function reminderStillValid(string $type, string $bookingId, array $data, ?string $bookingStatus = null): bool
    {
        $bookingStatus ??= (string) $this->repo->value('SELECT status FROM bookings WHERE id = ?', [$bookingId]);
        if (in_array($bookingStatus, ['cancelled', 'removed', 'conflict'], true)) {
            return false;
        }
        $at = $data['at'] ?? null;
        if (!$at) {
            return true;
        }
        $col = $type === 'installation_upcoming' ? 'install_at' : 'removal_at';
        $status = $type === 'installation_upcoming' ? "('reserved')" : "('reserved','installed')";
        return (bool) $this->repo->value("SELECT 1 FROM booking_items WHERE booking_id = ? AND $col = ? AND status IN $status LIMIT 1", [$bookingId, $at]);
    }

    /** توليد تذكيرات التركيب والفك (تُستدعى من المهام المجدولة) */
    public function generateReminders(): array
    {
        $settings = $this->app->service(Settings::class);
        $now = $this->app->clock->now();
        $created = ['installation_upcoming' => 0, 'removal_upcoming' => 0];
        $defs = [
            'installation_upcoming' => ['install_at', "('reserved')", (int) $settings->get('installation_reminder_hours', '24')],
            'removal_upcoming' => ['removal_at', "('reserved','installed')", (int) $settings->get('removal_reminder_hours', '24')],
        ];
        foreach ($defs as $type => [$col, $statuses, $hours]) {
            $until = $now->modify('+' . max(1, $hours) . ' hours')->format('Y-m-d H:i:s');
            $rows = $this->repo->all("SELECT DISTINCT bi.booking_id, bi.$col AS at FROM booking_items bi JOIN bookings b ON b.id = bi.booking_id
                WHERE b.status IN ('confirmed','installed') AND bi.status IN $statuses AND bi.$col > ? AND bi.$col <= ?", [$now->format('Y-m-d H:i:s'), $until]);
            foreach ($rows as $r) {
                if ($this->bookingEvent($type, $r['booking_id'], $r['at'], ['at' => $r['at']])) {
                    $created[$type]++;
                }
            }
        }
        return $created;
    }

    public function systemEvent(string $type, array $vars, string $dedupeKey): ?string
    {
        return $this->dispatch($type, $vars, $dedupeKey);
    }

    // ===== واجهات المستخدم =====

    public function forUser(CurrentUser $u, bool $unreadOnly = false, int $limit = 50, int $offset = 0): array
    {
        $logo = $this->app->service(Settings::class)->get('shop_logo');
        $where = 'r.user_id = ?' . ($unreadOnly ? ' AND r.read_at IS NULL' : '');
        $rows = $this->repo->all("SELECT n.id, n.type, n.title, n.body, n.booking_id, n.link, n.created_at, r.read_at
            FROM notification_recipients r JOIN notifications n ON n.id = r.notification_id WHERE $where ORDER BY n.created_at DESC LIMIT $limit OFFSET $offset", [$u->id]);
        return [
            'data' => array_map(static fn ($r) => [
                'id' => $r['id'], 'type' => $r['type'], 'type_label' => self::EVENTS[$r['type']] ?? $r['type'], 'title' => $r['title'], 'body' => $r['body'],
                'booking_id' => $r['booking_id'], 'link' => $r['link'], 'logo' => $logo ?: null, 'is_read' => $r['read_at'] !== null,
                'read_at' => Clock::toIso($r['read_at']), 'created_at' => Clock::toIso($r['created_at']),
            ], $rows),
            'unread' => (int) $this->repo->value('SELECT COUNT(*) FROM notification_recipients WHERE user_id = ? AND read_at IS NULL', [$u->id]),
        ];
    }

    public function markRead(CurrentUser $u, ?string $notificationId): int
    {
        if ($notificationId === null) {
            return $this->repo->exec('UPDATE notification_recipients SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [$this->app->clock->nowDb(), $u->id]);
        }
        return $this->repo->exec('UPDATE notification_recipients SET read_at = ? WHERE user_id = ? AND notification_id = ? AND read_at IS NULL', [$this->app->clock->nowDb(), $u->id, $notificationId]);
    }

    public function templates(): array
    {
        return array_map(static fn ($t) => array_merge($t, ['event_label' => self::EVENTS[$t['template_key']] ?? $t['template_key'], 'is_active' => (bool) $t['is_active'], 'id' => (int) $t['id']]),
            $this->repo->all('SELECT id, template_key, channel, title, body, whatsapp_template_name, whatsapp_language, whatsapp_category, whatsapp_params, meta_status, is_active, updated_at FROM notification_templates ORDER BY template_key, channel'));
    }

    public function updateTemplate(int $id, array $input, CurrentUser $actor, ?Request $req = null): array
    {
        $tpl = $this->repo->one('SELECT * FROM notification_templates WHERE id = ?', [$id]) ?? throw HttpException::notFound('القالب غير موجود');
        $d = $this->app->validator->validate($input, [
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:2000',
            'is_active' => 'nullable|bool',
            'whatsapp_template_name' => 'nullable|string|max:100',
            'whatsapp_language' => 'nullable|string|max:10',
            'whatsapp_params' => 'nullable|string|max:500',
        ], ['title' => 'العنوان', 'body' => 'النص']);
        preg_match_all('/\{([a-z_]+)\}/', $d['title'] . ' ' . $d['body'], $m);
        $unknown = array_diff(array_unique($m[1]), self::VARIABLES);
        if ($unknown) {
            throw HttpException::validation(['body' => 'متغيرات غير معروفة: ' . implode(', ', $unknown)]);
        }
        if ($tpl['channel'] === 'whatsapp') {
            if (!empty($d['whatsapp_template_name']) && !preg_match('/^[a-z0-9_]+$/', $d['whatsapp_template_name'])) {
                throw HttpException::validation(['whatsapp_template_name' => 'اسم قالب واتساب يقبل حروفاً إنجليزية صغيرة وأرقاماً و _']);
            }
            $params = array_filter(array_map('trim', explode(',', (string) ($d['whatsapp_params'] ?? $tpl['whatsapp_params']))));
            if (array_diff($params, self::VARIABLES)) {
                throw HttpException::validation(['whatsapp_params' => 'متغيرات غير معروفة في معاملات القالب']);
            }
        }
        $this->repo->exec('UPDATE notification_templates SET title = ?, body = ?, is_active = ?, whatsapp_template_name = COALESCE(?, whatsapp_template_name),
            whatsapp_language = COALESCE(?, whatsapp_language), whatsapp_params = COALESCE(?, whatsapp_params), updated_by = ?, updated_at = ? WHERE id = ?', [
            $d['title'], $d['body'], array_key_exists('is_active', $d) && $d['is_active'] !== null ? (int) $d['is_active'] : (int) $tpl['is_active'],
            $tpl['channel'] === 'whatsapp' ? ($d['whatsapp_template_name'] ?? null) : null,
            $tpl['channel'] === 'whatsapp' ? ($d['whatsapp_language'] ?? null) : null,
            $tpl['channel'] === 'whatsapp' ? ($d['whatsapp_params'] ?? null) : null,
            $actor->id, $this->app->clock->nowDb(), $id,
        ]);
        $this->app->service(Audit::class)->log($actor->id, 'template.updated', 'notification_template', (string) $id, ['key' => $tpl['template_key'], 'channel' => $tpl['channel']], $req);
        foreach ($this->templates() as $t) {
            if ((int) $t['id'] === $id) {
                return $t;
            }
        }
        return [];
    }

    public function subscriptions(): array
    {
        return [
            'events' => self::EVENTS,
            'channels' => self::CHANNELS,
            'items' => $this->repo->all('SELECT s.id, s.event_type, s.user_id, s.channel, s.center_scope, u.full_name FROM notification_subscriptions s JOIN users u ON u.id = s.user_id ORDER BY s.event_type, u.full_name'),
        ];
    }

    /** استبدال مستلمي حدث وقناة محددين */
    public function setSubscriptions(array $input, CurrentUser $actor, ?Request $req = null): array
    {
        $d = $this->app->validator->validate($input, [
            'event_type' => 'required|in:' . implode(',', array_keys(self::EVENTS)),
            'channel' => 'required|in:' . implode(',', array_keys(self::CHANNELS)),
            'user_ids' => 'nullable|array',
            'center_scope' => 'nullable|string|max:36',
        ], ['event_type' => 'نوع الإشعار', 'channel' => 'طريقة الإشعار']);
        $scope = $d['center_scope'] ?? '*';
        if ($scope !== '*' && !Uuid::isValid($scope)) {
            throw HttpException::validation(['center_scope' => 'المركز غير صالح']);
        }
        $ids = array_values(array_unique(array_filter(array_map('strval', $d['user_ids'] ?? []), [Uuid::class, 'isValid'])));
        $db = $this->app->db();
        $db->beginTransaction();
        try {
            $this->repo->exec('DELETE FROM notification_subscriptions WHERE event_type = ? AND channel = ? AND center_scope = ?', [$d['event_type'], $d['channel'], $scope]);
            $st = $db->prepare('INSERT INTO notification_subscriptions (event_type, user_id, channel, center_scope, created_at) SELECT ?, id, ?, ?, ? FROM users WHERE id = ? AND deleted_at IS NULL');
            foreach ($ids as $uid) {
                $st->execute([$d['event_type'], $d['channel'], $scope, $this->app->clock->nowDb(), $uid]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $this->app->service(Audit::class)->log($actor->id, 'notifications.recipients_changed', 'notification_subscription', $d['event_type'], ['channel' => $d['channel'], 'users' => $ids, 'scope' => $scope], $req);
        return $this->subscriptions();
    }

    public function logs(int $limit = 100): array
    {
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'channel' => $r['channel'], 'status' => $r['status'], 'attempts' => (int) $r['attempts'], 'destination' => $r['destination'],
            'last_error' => $r['last_error'], 'provider_message_id' => $r['provider_message_id'], 'title' => $r['title'], 'type' => $r['type'],
            'user_name' => $r['full_name'], 'created_at' => Clock::toIso($r['created_at']), 'sent_at' => Clock::toIso($r['sent_at']), 'delivered_at' => Clock::toIso($r['delivered_at']),
        ], $this->repo->all("SELECT l.*, n.title, n.type, u.full_name FROM notification_logs l JOIN notifications n ON n.id = l.notification_id LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT $limit"));
    }
}
