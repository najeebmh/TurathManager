<?php

declare(strict_types=1);

namespace TurathManager\Controllers;

use TurathManager\Core\Clock;
use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Response;
use TurathManager\Repositories\Generic;
use TurathManager\Services\BackupService;
use TurathManager\Services\SchedulerService;
use TurathManager\Support\Audit;
use TurathManager\Support\Settings;
use TurathManager\Support\UploadService;

final class SystemController extends Controller
{
    /** تقديم الصور المرفوعة. أسماء الملفات UUID عشوائية غير قابلة للتخمين */
    public function media(Request $r): Response
    {
        $rel = $r->param('folder') . '/' . $r->param('y') . '/' . $r->param('m') . '/' . $r->param('file');
        $path = $this->app->service(UploadService::class)->absolute($rel);
        if ($path === null) {
            throw HttpException::notFound('الملف غير موجود');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw HttpException::notFound('الملف غير موجود');
        }
        return Response::file($path, $mime);
    }

    public function publicSettings(Request $r): Response
    {
        $s = $this->app->service(Settings::class);
        return Response::json(['data' => ['shop_name' => $s->get('shop_name'), 'shop_logo' => $s->get('shop_logo') ?: null, 'timezone' => $this->app->config['app']['timezone']]]);
    }

    public function settings(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(Settings::class)->all()]);
    }

    public function saveSettings(Request $r): Response
    {
        $d = $this->app->validator->validate($r->body, [
            'shop_name' => 'required|string|max:150',
            'installation_reminder_hours' => 'nullable|int|min:1|max:168',
            'removal_reminder_hours' => 'nullable|int|min:1|max:168',
        ], ['shop_name' => 'اسم المحل']);
        $s = $this->app->service(Settings::class);
        foreach ($d as $k => $v) {
            if ($v !== null) {
                $s->set($k, (string) $v, $this->user($r)->id);
            }
        }
        $this->app->service(Audit::class)->log($this->user($r)->id, 'settings.updated', 'app_settings', null, $d, $r);
        return Response::json(['data' => $s->all()]);
    }

    public function uploadLogo(Request $r): Response
    {
        $file = $r->files['image'] ?? null;
        if (!is_array($file)) {
            throw HttpException::validation(['image' => 'يجب اختيار صورة']);
        }
        $stored = $this->app->service(UploadService::class)->storeImage($file, 'branding');
        $this->app->service(Settings::class)->set('shop_logo', 'media/' . $stored['path'], $this->user($r)->id);
        $this->app->service(Audit::class)->log($this->user($r)->id, 'settings.logo_updated', 'app_settings', null, [], $r);
        return Response::json(['data' => $this->app->service(Settings::class)->all()]);
    }

    public function audit(Request $r): Response
    {
        [$limit, $offset] = $this->paging($r, 100, 500);
        $repo = new Generic($this->app->db());
        $where = ['1=1'];
        $p = [];
        foreach (['action' => 'a.action LIKE ?', 'entity_type' => 'a.entity_type = ?', 'user_id' => 'a.user_id = ?', 'entity_id' => 'a.entity_id = ?'] as $k => $sql) {
            if ($r->query($k)) {
                $where[] = $sql;
                $p[] = $k === 'action' ? $r->query($k) . '%' : $r->query($k);
            }
        }
        $rows = $repo->all('SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE ' . implode(' AND ', $where) . " ORDER BY a.id DESC LIMIT $limit OFFSET $offset", $p);
        return Response::json(['data' => array_map(static fn ($a) => [
            'id' => (int) $a['id'], 'user_name' => $a['full_name'], 'action' => $a['action'], 'entity_type' => $a['entity_type'], 'entity_id' => $a['entity_id'],
            'details' => json_decode((string) $a['details'], true), 'ip' => $a['ip'], 'created_at' => Clock::toIso($a['created_at']),
        ], $rows)]);
    }

    public function backups(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(BackupService::class)->list()]);
    }

    public function createBackup(Request $r): Response
    {
        $b = $this->app->service(BackupService::class)->create($this->user($r)->id);
        $this->app->service(Audit::class)->log($this->user($r)->id, 'backup.created', 'backup', $b['id'], ['file' => $b['file_name']], $r);
        return Response::created(['data' => $b]);
    }

    public function downloadBackup(Request $r): Response
    {
        $path = $this->app->service(BackupService::class)->path($this->id($r));
        $this->app->service(Audit::class)->log($this->user($r)->id, 'backup.downloaded', 'backup', $this->id($r), [], $r);
        $resp = Response::file($path, 'application/gzip');
        $resp->headers['Content-Disposition'] = 'attachment; filename="' . basename($path) . '"';
        $resp->headers['Cache-Control'] = 'no-store';
        return $resp;
    }

    public function jobs(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(SchedulerService::class)->status()]);
    }
}
