<?php

declare(strict_types=1);

namespace TurathManager\Support;

use TurathManager\Core\App;

/**
 * إعدادات النظام غير السرية المخزنة في app_settings.
 */
final class Settings
{
    public const EDITABLE = ['shop_name', 'shop_logo', 'installation_reminder_hours', 'removal_reminder_hours'];

    public function __construct(private readonly App $app)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $st = $this->app->db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    }

    /** @return array<string,?string> */
    public function all(): array
    {
        $rows = $this->app->db()->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            if (in_array($r['setting_key'], self::EDITABLE, true)) {
                $out[$r['setting_key']] = $r['setting_value'];
            }
        }
        return $out;
    }

    public function set(string $key, ?string $value, ?string $userId): void
    {
        $this->app->db()->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at, updated_by) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)')
            ->execute([$key, $value, $this->app->clock->nowDb(), $userId]);
    }
}
