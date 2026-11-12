<?php

declare(strict_types=1);

namespace TurathManager\Tests;

/**
 * المرحلة 3: المستخدمون والصلاحيات وسجل التدقيق ومنع الوصول غير المصرح.
 */
final class AuthTest extends TestCase
{
    public function testLoginLogoutAndAudit(): void
    {
        [$id, $token] = $this->user(['admin'], [], 'boss');
        $me = $this->ok('GET', '/auth/me', [], $token);
        $this->assertSame('boss', $me['user']['username']);
        $this->assertContains('manage_users', $me['user']['permissions']);
        [$s] = $this->call('POST', '/auth/logout', [], $token);
        $this->assertSame(204, $s);
        [$s] = $this->call('GET', '/auth/me', [], $token);
        $this->assertSame(401, $s);
        $actions = $this->app->db()->query("SELECT action FROM audit_logs WHERE user_id = '$id' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['auth.login', 'auth.logout'], $actions);
        $hash = $this->app->db()->query("SELECT token_hash FROM access_tokens WHERE user_id = '$id'")->fetchColumn();
        $this->assertSame(hash('sha256', $token), $hash, 'only the token hash is stored');
    }

    public function testWrongPasswordAndLockout(): void
    {
        $this->user(['assistant'], [], 'sara');
        for ($i = 0; $i < 5; $i++) {
            [$s, $d] = $this->call('POST', '/auth/login', ['username' => 'sara', 'password' => 'wrong']);
            $this->assertSame(401, $s);
        }
        [$s, $d] = $this->call('POST', '/auth/login', ['username' => 'sara', 'password' => 'Passw0rd99']);
        $this->assertSame(429, $s, 'account locked after 5 failures even with the right password');
        $this->assertSame(1, (int) $this->app->db()->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login_locked'")->fetchColumn());
    }

    public function testAdminManagesManagersAndAssistantsWithCenters(): void
    {
        $admin = $this->admin();
        $c1 = $this->center($admin, 'مركز اليفا');
        $c2 = $this->center($admin, 'بنت اليمن');
        $mgr = $this->ok('POST', '/users', [
            'full_name' => 'مدير اليفا', 'username' => 'mgr1', 'password' => 'Manager123', 'phone' => '771111111', 'email' => 'm@example.com',
            'roles' => ['manager'], 'centers' => [['center_id' => $c1['id'], 'role_in_center' => 'manager'], ['center_id' => $c2['id'], 'role_in_center' => 'manager']],
        ], $admin)['data'];
        $this->assertSame('+967771111111', $mgr['phone']);
        $this->assertCount(2, $mgr['centers']);
        $this->assertTrue($mgr['must_change_password']);

        $upd = $this->ok('PUT', "/users/{$mgr['id']}", ['full_name' => 'مدير المركزين', 'phone' => '0772222222', 'email' => 'new@example.com'], $admin)['data'];
        $this->assertSame('مدير المركزين', $upd['full_name']);
        $this->assertSame('+967772222222', $upd['phone']);

        [$s, $d] = $this->call('POST', '/users', ['full_name' => 'x', 'username' => 'mgr1', 'password' => 'Manager123', 'roles' => ['manager']], $admin);
        $this->assertSame(422, $s);
        [$s, $d] = $this->call('POST', '/users', ['full_name' => 'x', 'username' => 'weak', 'password' => 'short', 'roles' => ['manager']], $admin);
        $this->assertSame(422, $s);

        [$s, $login] = $this->call('POST', '/auth/login', ['username' => 'mgr1', 'password' => 'Manager123']);
        $this->assertSame(200, $s);
        $this->assertTrue($login['user']['must_change_password']);
        $this->ok('POST', '/auth/change-password', ['current_password' => 'Manager123', 'new_password' => 'NewPass456'], $login['token']);
        [$s] = $this->call('POST', '/auth/login', ['username' => 'mgr1', 'password' => 'NewPass456']);
        $this->assertSame(200, $s);

        $reset = $this->ok('POST', "/users/{$mgr['id']}/reset-password", [], $admin)['data'];
        [$s] = $this->call('POST', '/auth/login', ['username' => 'mgr1', 'password' => $reset['temporary_password']]);
        $this->assertSame(200, $s);

        $this->ok('POST', "/users/{$mgr['id']}/deactivate", [], $admin);
        [$s, $d] = $this->call('POST', '/auth/login', ['username' => 'mgr1', 'password' => $reset['temporary_password']]);
        $this->assertSame(403, $s);
        $this->ok('POST', "/users/{$mgr['id']}/activate", [], $admin);
        [$s] = $this->call('POST', '/auth/login', ['username' => 'mgr1', 'password' => $reset['temporary_password']]);
        $this->assertSame(200, $s);
    }

    public function testAssistantCannotAccessUserManagementOrFinancialReports(): void
    {
        $admin = $this->admin();
        $c = $this->center($admin);
        [, $assistant] = $this->user(['assistant'], [$c['id'] => 'assistant']);
        foreach ([
            ['GET', '/users'], ['POST', '/users'], ['GET', '/roles'], ['GET', '/permissions'], ['POST', '/roles'],
            ['GET', '/reports/revenue'], ['GET', '/reports/outstanding'], ['GET', '/reports/top_products'],
            ['GET', '/audit-logs'], ['GET', '/backups'], ['POST', '/backups'], ['GET', '/whatsapp/settings'],
            ['POST', '/products'], ['POST', '/categories'], ['POST', '/centers'],
        ] as [$m, $path]) {
            [$s] = $this->call($m, $path, [], $assistant);
            $this->assertSame(403, $s, "assistant must be forbidden: $m $path");
        }
        [$s] = $this->call('GET', '/products', [], $assistant);
        $this->assertSame(200, $s);
        [$s] = $this->call('GET', '/bookings', [], $assistant);
        $this->assertSame(200, $s);
    }

    public function testCustomRolesAndPermissionChangesAreAudited(): void
    {
        [$adminId, $admin] = $this->user(['admin']);
        $role = $this->ok('POST', '/roles', ['code' => 'accountant', 'label' => 'محاسب', 'permissions' => ['view_financial_reports']], $admin)['data'];
        [$uid, $tok] = $this->user(['assistant']);
        $this->ok('PUT', "/users/$uid", ['roles' => ['assistant', 'accountant']], $admin);
        [$s] = $this->call('GET', '/reports/revenue', [], $tok);
        $this->assertSame(200, $s, 'permissions are re-evaluated on every request');
        $this->ok('PUT', "/roles/{$role['id']}", ['permissions' => []], $admin);
        [$s] = $this->call('GET', '/reports/revenue', [], $tok);
        $this->assertSame(403, $s);
        $actions = $this->app->db()->query("SELECT action FROM audit_logs WHERE action IN ('role.created','role.permissions_changed','user.roles_changed')")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('role.created', $actions);
        $this->assertContains('role.permissions_changed', $actions);
        $this->assertContains('user.roles_changed', $actions);
        [$s] = $this->call('DELETE', "/roles/{$role['id']}", [], $admin);
        $this->assertSame(409, $s, 'role in use cannot be deleted');
        [$s] = $this->call('PUT', '/roles/1', ['permissions' => []], $admin);
        $this->assertSame(409, $s, 'admin role permissions are locked');
    }

    public function testLastAdminCannotBeDisabledAndManagerCannotGrantAdmin(): void
    {
        [$adminId, $admin] = $this->user(['admin']);
        [$s] = $this->call('POST', "/users/$adminId/deactivate", [], $admin);
        $this->assertSame(409, $s);
        $managers = $this->app->db()->query("SELECT id FROM roles WHERE code = 'manager'")->fetchColumn();
        $this->app->db()->exec("INSERT INTO role_permissions (role_id, permission_id) SELECT $managers, id FROM permissions WHERE code = 'manage_users'");
        [$mid, $mgr] = $this->user(['manager']);
        [$aid] = $this->user(['assistant']);
        [$s] = $this->call('PUT', "/users/$aid", ['roles' => ['admin']], $mgr);
        $this->assertSame(403, $s);
        $this->app->db()->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = $managers AND p.code = 'manage_users'");
    }

    public function testCreateAdminScriptRefusesSecondAdminAndWeakPasswords(): void
    {
        $this->user(['admin']);
        $env = 'DB_DATABASE=' . $GLOBALS['TM_TEST_DB'];
        $cmd = "echo 'Strong12345' | $env php " . escapeshellarg(dirname(__DIR__, 2) . '/scripts/create-admin.php') . ' --username=second --full-name=Second --password-stdin 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(1, $code, implode("\n", $out));
        $cmd = "echo 'weak' | $env php " . escapeshellarg(dirname(__DIR__, 2) . '/scripts/create-admin.php') . ' --username=second --full-name=Second --password-stdin --additional 2>&1';
        exec($cmd, $out2, $code2);
        $this->assertSame(1, $code2);
        $cmd = "echo 'Strong12345' | $env php " . escapeshellarg(dirname(__DIR__, 2) . '/scripts/create-admin.php') . ' --username=second --full-name=Second --password-stdin --additional 2>&1';
        exec($cmd, $out3, $code3);
        $this->assertSame(0, $code3, implode("\n", $out3));
        [$s] = $this->call('POST', '/auth/login', ['username' => 'second', 'password' => 'Strong12345']);
        $this->assertSame(200, $s);
    }
}
