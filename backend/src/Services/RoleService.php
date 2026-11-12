<?php

declare(strict_types=1);

namespace TurathManager\Services;

use TurathManager\Auth\CurrentUser;
use TurathManager\Auth\Permissions;
use TurathManager\Core\App;
use TurathManager\Core\Database;
use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Repositories\Generic;
use TurathManager\Support\Audit;

/**
 * الأدوار والصلاحيات القابلة للتخصيص.
 */
final class RoleService
{
    private Generic $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new Generic($app->db());
    }

    /** @return list<array<string,mixed>> */
    public function permissions(): array
    {
        return $this->repo->all('SELECT code, label, group_name FROM permissions ORDER BY group_name, id');
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $roles = $this->repo->all('SELECT r.id, r.code, r.label, r.description, r.is_system,
            (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS users_count FROM roles r ORDER BY r.id');
        foreach ($roles as &$role) {
            $role['is_system'] = (bool) $role['is_system'];
            $role['users_count'] = (int) $role['users_count'];
            $role['permissions'] = array_column($this->repo->all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.code', [$role['id']]), 'code');
        }
        return $roles;
    }

    public function get(int $id): array
    {
        foreach ($this->list() as $r) {
            if ((int) $r['id'] === $id) {
                return $r;
            }
        }
        throw HttpException::notFound('الدور غير موجود');
    }

    public function create(array $input, CurrentUser $actor, Request $req): array
    {
        $d = $this->app->validator->validate($input, [
            'code' => 'required|string|min:2|max:60',
            'label' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
        ], ['code' => 'رمز الدور', 'label' => 'اسم الدور', 'permissions' => 'الصلاحيات']);
        if (!preg_match('/^[a-z0-9_]+$/', $d['code'])) {
            throw HttpException::validation(['code' => 'رمز الدور يقبل الحروف الإنجليزية الصغيرة والأرقام و _ فقط']);
        }
        if ($this->repo->value('SELECT id FROM roles WHERE code = ?', [$d['code']])) {
            throw HttpException::validation(['code' => 'رمز الدور مستخدم مسبقاً']);
        }
        $id = Database::transaction($this->app->db(), function () use ($d) {
            $this->repo->exec('INSERT INTO roles (code, label, description, is_system, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)', [
                $d['code'], $d['label'], $d['description'] ?? null, $this->app->clock->nowDb(), $this->app->clock->nowDb(),
            ]);
            $id = (int) $this->app->db()->lastInsertId();
            $this->setPermissions($id, $d['permissions'] ?? []);
            return $id;
        });
        $this->app->service(Audit::class)->log($actor->id, 'role.created', 'role', (string) $id, ['code' => $d['code'], 'permissions' => $d['permissions'] ?? []], $req);
        return $this->get($id);
    }

    public function update(int $id, array $input, CurrentUser $actor, Request $req): array
    {
        $before = $this->get($id);
        $d = $this->app->validator->validate($input, [
            'label' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
        ], ['label' => 'اسم الدور'], true);
        $hasPerms = array_key_exists('permissions', $input);
        if ($hasPerms) {
            $d['permissions'] = is_array($input['permissions']) ? $input['permissions'] : throw HttpException::validation(['permissions' => 'الصلاحيات يجب أن تكون قائمة']);
        }
        if ($before['code'] === 'admin' && $hasPerms) {
            throw HttpException::conflict('لا يمكن تعديل صلاحيات دور مدير النظام');
        }
        Database::transaction($this->app->db(), function () use ($id, $d, $hasPerms) {
            if (isset($d['label'])) {
                $this->repo->exec('UPDATE roles SET label = ?, updated_at = ? WHERE id = ?', [$d['label'], $this->app->clock->nowDb(), $id]);
            }
            if (array_key_exists('description', $d)) {
                $this->repo->exec('UPDATE roles SET description = ? WHERE id = ?', [$d['description'], $id]);
            }
            if ($hasPerms) {
                $this->setPermissions($id, $d['permissions']);
            }
        });
        $after = $this->get($id);
        if ($before['permissions'] !== $after['permissions']) {
            $this->app->service(Audit::class)->log($actor->id, 'role.permissions_changed', 'role', (string) $id, [
                'added' => array_values(array_diff($after['permissions'], $before['permissions'])),
                'removed' => array_values(array_diff($before['permissions'], $after['permissions'])),
            ], $req);
        }
        return $after;
    }

    public function delete(int $id, CurrentUser $actor, Request $req): void
    {
        $role = $this->get($id);
        if ($role['is_system']) {
            throw HttpException::conflict('لا يمكن حذف دور أساسي من أدوار النظام');
        }
        if ($role['users_count'] > 0) {
            throw HttpException::conflict('لا يمكن حذف دور مرتبط بمستخدمين');
        }
        $this->repo->exec('DELETE FROM roles WHERE id = ?', [$id]);
        $this->app->service(Audit::class)->log($actor->id, 'role.deleted', 'role', (string) $id, ['code' => $role['code']], $req);
    }

    /** @param list<mixed> $codes */
    private function setPermissions(int $roleId, array $codes): void
    {
        $codes = array_values(array_unique(array_map('strval', $codes)));
        $unknown = array_diff($codes, array_keys(Permissions::catalog()));
        if ($unknown) {
            throw HttpException::validation(['permissions' => 'صلاحيات غير معروفة: ' . implode(', ', $unknown)]);
        }
        $this->repo->exec('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
        $st = $this->app->db()->prepare('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = ?');
        foreach ($codes as $code) {
            $st->execute([$roleId, $code]);
        }
    }
}
