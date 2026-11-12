<?php

declare(strict_types=1);

namespace TurathManager\Services;

use TurathManager\Auth\CurrentUser;
use TurathManager\Core\App;
use TurathManager\Core\Clock;
use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Uuid;
use TurathManager\Repositories\CenterRepository;
use TurathManager\Support\Audit;
use TurathManager\Support\ChangeLog;
use TurathManager\Support\UploadService;

/**
 * المراكز والمحلات التي تُنفذ فيها الحجوزات.
 */
final class CenterService
{
    private const RULES = [
        'code' => 'required|string|max:30',
        'name' => 'required|string|max:150',
        'whatsapp_number' => 'nullable|phone',
        'location_url' => 'nullable|url|max:500',
        'address' => 'nullable|string|max:500',
        'manager_name' => 'nullable|string|max:150',
        'manager_phone' => 'nullable|phone',
        'manager_email' => 'nullable|email|max:190',
        'assistant_name' => 'nullable|string|max:150',
        'assistant_phone' => 'nullable|phone',
        'handover_officer' => 'nullable|string|max:150',
        'emergency_phone' => 'nullable|phone',
        'working_hours' => 'nullable|string|max:255',
        'status' => 'nullable|in:active,inactive',
        'notes' => 'nullable|string|max:5000',
    ];

    private const LABELS = [
        'code' => 'رقم المركز', 'name' => 'اسم المركز', 'whatsapp_number' => 'رقم واتساب', 'location_url' => 'رابط الموقع',
        'manager_phone' => 'هاتف المدير', 'manager_email' => 'بريد المدير', 'assistant_phone' => 'هاتف المساعد', 'emergency_phone' => 'رقم الطوارئ',
    ];

    private CenterRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new CenterRepository($app->db());
    }

    public function list(?string $search = null, string $status = 'active'): array
    {
        $where = ['1=1'];
        $p = [];
        if ($status === 'archived') {
            $where[] = 'archived_at IS NOT NULL';
        } elseif ($status !== 'all') {
            $where[] = 'archived_at IS NULL';
        }
        if ($search) {
            $like = CenterRepository::like($search);
            $where[] = '(name LIKE ? OR code LIKE ? OR address LIKE ? OR manager_name LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        $rows = $this->repo->all('SELECT * FROM centers WHERE ' . implode(' AND ', $where) . ' ORDER BY name', $p);
        return array_map(fn ($r) => self::out($r) + ['staff' => $this->staff($r['id'])], $rows);
    }

    public function get(string $id): array
    {
        $r = $this->repo->find($id) ?? throw HttpException::notFound('المركز غير موجود');
        return self::out($r) + ['staff' => $this->staff($id)];
    }

    /** المديرون والمساعدون المرتبطون بالمركز */
    public function staff(string $centerId): array
    {
        return $this->repo->all("SELECT u.id, u.full_name, u.phone, u.whatsapp_number, cu.role_in_center
            FROM center_users cu JOIN users u ON u.id = cu.user_id
            WHERE cu.center_id = ? AND u.is_active = 1 AND u.deleted_at IS NULL
            ORDER BY FIELD(cu.role_in_center, 'manager', 'assistant', 'handover', 'staff'), u.full_name", [$centerId]);
    }

    public function save(?string $id, array $input, CurrentUser $actor, ?Request $req = null, ?int $baseVersion = null): array
    {
        $d = $this->app->validator->validate($input, self::RULES, self::LABELS);
        $existing = $id ? $this->repo->find($id) : null;
        if ($this->repo->value('SELECT id FROM centers WHERE code = ? AND id <> ?', [$d['code'], $id ?? ''])) {
            throw HttpException::validation(['code' => 'رقم المركز مستخدم مسبقاً']);
        }
        $now = $this->app->clock->nowDb();
        $d['status'] ??= 'active';
        if ($existing) {
            if (!$this->repo->update($id, $d + ['updated_at' => $now], $baseVersion)) {
                throw HttpException::conflict('تم تعديل المركز من جهاز آخر', ['server' => $this->get($id)], 'version_conflict');
            }
            $action = 'center.updated';
        } else {
            $id = $id && Uuid::isValid($id) ? strtolower($id) : Uuid::v4();
            $this->repo->insert($d + ['id' => $id, 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now]);
            $action = 'center.created';
        }
        $this->app->service(ChangeLog::class)->record('center', $id);
        $this->app->service(Audit::class)->log($actor->id, $action, 'center', $id, ['name' => $d['name']], $req);
        return $this->get($id);
    }

    public function archive(string $id, bool $archive, CurrentUser $actor, ?Request $req = null): array
    {
        $this->get($id);
        if ($archive) {
            $active = (int) $this->repo->value("SELECT COUNT(*) FROM bookings WHERE center_id = ? AND status IN ('confirmed','installed') AND removal_at > ?", [$id, $this->app->clock->nowDb()]);
            if ($active > 0) {
                throw HttpException::conflict("لا يمكن أرشفة مركز لديه {$active} حجز نشط أو قادم");
            }
        }
        $this->repo->update($id, ['archived_at' => $archive ? $this->app->clock->nowDb() : null, 'updated_at' => $this->app->clock->nowDb()]);
        $this->app->service(ChangeLog::class)->record('center', $id);
        $this->app->service(Audit::class)->log($actor->id, $archive ? 'center.archived' : 'center.restored', 'center', $id, [], $req);
        return $this->get($id);
    }

    public function setLogo(string $id, array $file, CurrentUser $actor, ?Request $req = null): array
    {
        $this->get($id);
        $stored = $this->app->service(UploadService::class)->storeImage($file, 'centers');
        $this->repo->update($id, ['logo_path' => $stored['path'], 'updated_at' => $this->app->clock->nowDb()]);
        $this->app->service(ChangeLog::class)->record('center', $id);
        $this->app->service(Audit::class)->log($actor->id, 'center.logo_updated', 'center', $id, [], $req);
        return $this->get($id);
    }

    public static function out(array $r): array
    {
        $o = array_intersect_key($r, array_flip(['id', 'code', 'name', 'whatsapp_number', 'location_url', 'address', 'manager_name', 'manager_phone', 'manager_email',
            'assistant_name', 'assistant_phone', 'handover_officer', 'emergency_phone', 'working_hours', 'status', 'notes']));
        $o['logo_url'] = $r['logo_path'] ? 'media/' . $r['logo_path'] : null;
        $o['archived'] = $r['archived_at'] !== null;
        $o['version'] = (int) $r['version'];
        $o['updated_at'] = Clock::toIso($r['updated_at']);
        return $o;
    }
}
