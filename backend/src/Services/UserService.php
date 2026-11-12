<?php

declare(strict_types=1);

namespace TurathManager\Services;

use TurathManager\Auth\AuthService;
use TurathManager\Auth\CurrentUser;
use TurathManager\Core\App;
use TurathManager\Core\Database;
use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Uuid;
use TurathManager\Repositories\UserRepository;
use TurathManager\Support\Audit;

/**
 * إدارة حسابات المستخدمين (مديرون ومساعدون) وربطهم بالأدوار والمراكز.
 */
final class UserService
{
    private const LABELS = [
        'full_name' => 'الاسم', 'username' => 'اسم المستخدم', 'email' => 'البريد الإلكتروني', 'phone' => 'الهاتف',
        'whatsapp_number' => 'رقم واتساب', 'password' => 'كلمة المرور', 'roles' => 'الأدوار', 'centers' => 'المراكز',
    ];

    private UserRepository $users;

    public function __construct(private readonly App $app)
    {
        $this->users = new UserRepository($app->db());
    }

    /** @return list<array<string,mixed>> */
    public function list(?string $search = null, ?string $status = null): array
    {
        $sql = 'SELECT id FROM users WHERE deleted_at IS NULL';
        $params = [];
        if ($search) {
            $sql .= ' AND (full_name LIKE ? OR username LIKE ? OR phone LIKE ? OR email LIKE ?)';
            $like = UserRepository::like($search);
            array_push($params, $like, $like, $like, $like);
        }
        if ($status === 'active' || $status === 'inactive') {
            $sql .= ' AND is_active = ?';
            $params[] = $status === 'active' ? 1 : 0;
        }
        $sql .= ' ORDER BY full_name';
        return array_map(fn ($r) => $this->get($r['id']), $this->users->all($sql, $params));
    }

    /** @return array<string,mixed> */
    public function get(string $id): array
    {
        $u = $this->users->one('SELECT id, full_name, username, email, phone, whatsapp_number, whatsapp_opt_in, whatsapp_opt_in_at, is_active, must_change_password, last_login_at, created_at, updated_at, version FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$u) {
            throw HttpException::notFound('المستخدم غير موجود');
        }
        $u['is_active'] = (bool) $u['is_active'];
        $u['whatsapp_opt_in'] = (bool) $u['whatsapp_opt_in'];
        $u['must_change_password'] = (bool) $u['must_change_password'];
        $u['roles'] = $this->users->all('SELECT r.id, r.code, r.label FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.label', [$id]);
        $u['centers'] = $this->users->all('SELECT c.id, c.name, c.code, cu.role_in_center FROM center_users cu JOIN centers c ON c.id = cu.center_id WHERE cu.user_id = ? ORDER BY c.name', [$id]);
        return $u;
    }

    /** @param array<string,mixed> $input */
    public function create(array $input, CurrentUser $actor, Request $req): array
    {
        $d = $this->app->validator->validate($input, [
            'full_name' => 'required|string|max:150',
            'username' => 'required|string|min:3|max:60',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|phone',
            'whatsapp_number' => 'nullable|phone',
            'whatsapp_opt_in' => 'nullable|bool',
            'password' => 'required|string|max:200',
            'roles' => 'required|array',
            'centers' => 'nullable|array',
        ], self::LABELS);
        $d['username'] = mb_strtolower($d['username']);
        if (!preg_match('/^[a-z0-9._\-]+$/', $d['username'])) {
            throw HttpException::validation(['username' => 'اسم المستخدم يقبل الحروف الإنجليزية والأرقام و . _ - فقط']);
        }
        AuthService::assertStrongPassword((string) $input['password'], 'password');
        $this->assertUnique($d['username'], $d['email'] ?? null);

        $id = Uuid::v4();
        $now = $this->app->clock->nowDb();
        Database::transaction($this->app->db(), function () use ($d, $id, $now, $actor, $input) {
            $this->users->insert([
                'id' => $id,
                'full_name' => $d['full_name'],
                'username' => $d['username'],
                'email' => $d['email'] ?? null,
                'phone' => $d['phone'] ?? null,
                'whatsapp_number' => $d['whatsapp_number'] ?? null,
                'whatsapp_opt_in' => !empty($d['whatsapp_opt_in']) ? 1 : 0,
                'whatsapp_opt_in_at' => !empty($d['whatsapp_opt_in']) ? $now : null,
                'password_hash' => password_hash((string) $input['password'], PASSWORD_DEFAULT),
                'is_active' => 1,
                'must_change_password' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->syncRoles($id, $d['roles'], $actor);
            $this->syncCenters($id, $d['centers'] ?? []);
        });
        $this->app->service(Audit::class)->log($actor->id, 'user.created', 'user', $id, ['username' => $d['username'], 'roles' => $d['roles']], $req);
        return $this->get($id);
    }

    /** @param array<string,mixed> $input */
    public function update(string $id, array $input, CurrentUser $actor, Request $req): array
    {
        $before = $this->get($id);
        $d = $this->app->validator->validate($input, [
            'full_name' => 'required|string|max:150',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|phone',
            'whatsapp_number' => 'nullable|phone',
            'whatsapp_opt_in' => 'nullable|bool',
            'roles' => 'nullable|array',
            'centers' => 'nullable|array',
        ], self::LABELS, true);
        if (array_key_exists('email', $d) && $d['email'] !== null) {
            $exists = $this->users->value('SELECT id FROM users WHERE email = ? AND id <> ?', [$d['email'], $id]);
            if ($exists) {
                throw HttpException::validation(['email' => 'البريد الإلكتروني مستخدم مسبقاً']);
            }
        }
        $now = $this->app->clock->nowDb();
        Database::transaction($this->app->db(), function () use ($id, $d, $now, $actor, $before) {
            $data = array_intersect_key($d, array_flip(['full_name', 'email', 'phone', 'whatsapp_number']));
            if (array_key_exists('whatsapp_opt_in', $d)) {
                $data['whatsapp_opt_in'] = $d['whatsapp_opt_in'] ? 1 : 0;
                if ($d['whatsapp_opt_in'] && !$before['whatsapp_opt_in']) {
                    $data['whatsapp_opt_in_at'] = $now;
                }
            }
            $data['updated_at'] = $now;
            $this->users->update($id, $data);
            if (isset($d['roles'])) {
                $this->syncRoles($id, $d['roles'], $actor);
            }
            if (array_key_exists('centers', $d)) {
                $this->syncCenters($id, $d['centers'] ?? []);
            }
        });
        $after = $this->get($id);
        $audit = $this->app->service(Audit::class);
        $audit->log($actor->id, 'user.updated', 'user', $id, ['fields' => array_keys($d)], $req);
        if (array_column($before['roles'], 'code') !== array_column($after['roles'], 'code')) {
            $audit->log($actor->id, 'user.roles_changed', 'user', $id, ['from' => array_column($before['roles'], 'code'), 'to' => array_column($after['roles'], 'code')], $req);
        }
        return $after;
    }

    public function setActive(string $id, bool $active, CurrentUser $actor, Request $req): array
    {
        $this->get($id);
        if ($id === $actor->id && !$active) {
            throw HttpException::conflict('لا يمكنك تعطيل حسابك الحالي');
        }
        if (!$active) {
            $this->assertNotLastAdmin($id);
        }
        Database::transaction($this->app->db(), function () use ($id, $active) {
            $this->users->update($id, ['is_active' => $active ? 1 : 0, 'updated_at' => $this->app->clock->nowDb()]);
            if (!$active) {
                $this->users->exec('UPDATE access_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [$this->app->clock->nowDb(), $id]);
            }
        });
        $this->app->service(Audit::class)->log($actor->id, $active ? 'user.activated' : 'user.deactivated', 'user', $id, [], $req);
        return $this->get($id);
    }

    /** إعادة تعيين كلمة المرور: تُعاد كلمة مؤقتة مرة واحدة ويُجبر المستخدم على تغييرها */
    public function resetPassword(string $id, CurrentUser $actor, Request $req): array
    {
        $this->get($id);
        $temp = $this->temporaryPassword();
        Database::transaction($this->app->db(), function () use ($id, $temp) {
            $this->users->update($id, [
                'password_hash' => password_hash($temp, PASSWORD_DEFAULT),
                'must_change_password' => 1,
                'updated_at' => $this->app->clock->nowDb(),
            ]);
            $this->users->exec('UPDATE access_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [$this->app->clock->nowDb(), $id]);
        });
        $this->app->service(Audit::class)->log($actor->id, 'user.password_reset', 'user', $id, [], $req);
        return ['temporary_password' => $temp];
    }

    private function temporaryPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $s = '';
        for ($i = 0; $i < 8; $i++) {
            $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $s . random_int(1000, 9999);
    }

    private function assertUnique(string $username, ?string $email): void
    {
        if ($this->users->value('SELECT id FROM users WHERE username = ?', [$username])) {
            throw HttpException::validation(['username' => 'اسم المستخدم مستخدم مسبقاً']);
        }
        if ($email !== null && $this->users->value('SELECT id FROM users WHERE email = ?', [$email])) {
            throw HttpException::validation(['email' => 'البريد الإلكتروني مستخدم مسبقاً']);
        }
    }

    /** @param list<mixed> $roleCodes */
    private function syncRoles(string $userId, array $roleCodes, CurrentUser $actor): void
    {
        $codes = array_values(array_unique(array_map('strval', $roleCodes)));
        if (!$codes) {
            throw HttpException::validation(['roles' => 'يجب اختيار دور واحد على الأقل']);
        }
        $in = implode(',', array_fill(0, count($codes), '?'));
        $roles = $this->users->all("SELECT id, code FROM roles WHERE code IN ($in)", $codes);
        if (count($roles) !== count($codes)) {
            throw HttpException::validation(['roles' => 'دور غير معروف']);
        }
        $current = $this->users->all('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$userId]);
        $currentCodes = array_column($current, 'code');
        $touchesAdmin = in_array('admin', $codes, true) !== in_array('admin', $currentCodes, true);
        if ($touchesAdmin && !$actor->can('manage_permissions')) {
            throw HttpException::forbidden('منح أو سحب دور مدير النظام يتطلب صلاحية إدارة الصلاحيات');
        }
        if (in_array('admin', $currentCodes, true) && !in_array('admin', $codes, true)) {
            $this->assertNotLastAdmin($userId);
        }
        $this->users->exec('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
        $st = $this->app->db()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        foreach ($roles as $role) {
            $st->execute([$userId, $role['id']]);
        }
    }

    /** @param list<mixed> $centers */
    private function syncCenters(string $userId, array $centers): void
    {
        $this->users->exec('DELETE FROM center_users WHERE user_id = ?', [$userId]);
        $st = $this->app->db()->prepare('INSERT INTO center_users (center_id, user_id, role_in_center, created_at) VALUES (?, ?, ?, ?)');
        $seen = [];
        foreach ($centers as $c) {
            $centerId = is_array($c) ? (string) ($c['center_id'] ?? $c['id'] ?? '') : (string) $c;
            $role = is_array($c) ? (string) ($c['role_in_center'] ?? 'staff') : 'staff';
            if (!Uuid::isValid($centerId) || !in_array($role, ['manager', 'assistant', 'handover', 'staff'], true)) {
                throw HttpException::validation(['centers' => 'بيانات المراكز غير صحيحة']);
            }
            if (isset($seen[$centerId])) {
                continue;
            }
            if (!$this->users->value('SELECT id FROM centers WHERE id = ? AND archived_at IS NULL', [$centerId])) {
                throw HttpException::validation(['centers' => 'المركز غير موجود']);
            }
            $seen[$centerId] = true;
            $st->execute([$centerId, $userId, $role, $this->app->clock->nowDb()]);
        }
    }

    private function assertNotLastAdmin(string $userId): void
    {
        $others = (int) $this->users->value("SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
            WHERE r.code = 'admin' AND u.is_active = 1 AND u.deleted_at IS NULL AND u.id <> ?", [$userId]);
        $isAdmin = (bool) $this->users->value("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.code = 'admin'", [$userId]);
        if ($isAdmin && $others === 0) {
            throw HttpException::conflict('لا يمكن تعطيل أو سحب دور آخر مدير نظام نشط');
        }
    }
}
