<?php

declare(strict_types=1);

namespace TurathManager\Auth;

use TurathManager\Core\App;
use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Support\Audit;

/**
 * المصادقة برموز وصول Bearer. يُخزن في قاعدة البيانات SHA-256 للرمز فقط.
 * لا تُستخدم ملفات تعريف الارتباط (Cookies) لذلك لا ينطبق هجوم CSRF.
 */
final class AuthService
{
    public const MAX_FAILURES_PER_USER = 5;
    public const MAX_FAILURES_PER_IP = 20;
    public const LOCK_MINUTES = 15;
    public const MIN_PASSWORD = 8;

    public function __construct(private readonly App $app)
    {
    }

    /** @return array{token:string,expires_at:string,user:array<string,mixed>} */
    public function login(string $username, string $password, Request $request, ?string $deviceId = null, ?string $deviceName = null): array
    {
        $db = $this->app->db();
        $username = mb_strtolower(trim($username));
        $since = $this->app->clock->now()->modify('-' . self::LOCK_MINUTES . ' minutes')->format('Y-m-d H:i:s');

        $st = $db->prepare('SELECT
            (SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND created_at >= ?) AS u,
            (SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?) AS i');
        $st->execute([$username, $since, $request->ip, $since]);
        $counts = $st->fetch();
        if ((int) $counts['u'] >= self::MAX_FAILURES_PER_USER || (int) $counts['i'] >= self::MAX_FAILURES_PER_IP) {
            $this->app->service(Audit::class)->log(null, 'auth.login_locked', 'user', null, ['username' => $username], $request);
            throw new HttpException(429, 'تم إيقاف محاولات الدخول مؤقتاً بسبب كثرة المحاولات الفاشلة. حاول بعد ' . self::LOCK_MINUTES . ' دقيقة', 'too_many_attempts');
        }

        $st = $db->prepare('SELECT * FROM users WHERE username = ? AND deleted_at IS NULL');
        $st->execute([$username]);
        $user = $st->fetch() ?: null;

        // التحقق بثبات زمني تقريبي حتى عند عدم وجود المستخدم
        $hash = $user['password_hash'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        $valid = password_verify($password, $hash) && $user !== null;

        $db->prepare('INSERT INTO login_attempts (username, ip, success, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$username, $request->ip, $valid ? 1 : 0, $this->app->clock->nowDb()]);

        if (!$valid) {
            $this->app->service(Audit::class)->log($user['id'] ?? null, 'auth.login_failed', 'user', $user['id'] ?? null, ['username' => $username], $request);
            throw new HttpException(401, 'اسم المستخدم أو كلمة المرور غير صحيحة', 'invalid_credentials');
        }
        if ((int) $user['is_active'] !== 1) {
            $this->app->service(Audit::class)->log($user['id'], 'auth.login_inactive', 'user', $user['id'], [], $request);
            throw new HttpException(403, 'الحساب معطل. تواصل مع مدير النظام', 'account_disabled');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        $token = bin2hex(random_bytes(32));
        $expires = $this->app->clock->now()->modify('+' . (int) $this->app->config['app']['token_ttl_hours'] . ' hours')->format('Y-m-d H:i:s');
        $db->prepare('INSERT INTO access_tokens (user_id, token_hash, device_id, device_name, ip, user_agent, created_at, last_used_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $user['id'], hash('sha256', $token), $deviceId, $deviceName !== null ? mb_substr($deviceName, 0, 120) : null,
                $request->ip, mb_substr((string) $request->header('user-agent'), 0, 255), $this->app->clock->nowDb(), $this->app->clock->nowDb(), $expires,
            ]);
        $db->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([$this->app->clock->nowDb(), $user['id']]);
        $this->app->service(Audit::class)->log($user['id'], 'auth.login', 'user', $user['id'], ['device_id' => $deviceId], $request);

        $current = $this->loadUser($user['id'], (int) $db->lastInsertId());
        return [
            'token' => $token,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($expires . ' UTC')),
            'user' => $this->profile($current),
        ];
    }

    public function authenticate(Request $request): CurrentUser
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw HttpException::unauthorized();
        }
        $db = $this->app->db();
        $st = $db->prepare('SELECT t.id, t.user_id, t.last_used_at FROM access_tokens t
            JOIN users u ON u.id = t.user_id
            WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > ? AND u.is_active = 1 AND u.deleted_at IS NULL');
        $now = $this->app->clock->nowDb();
        $st->execute([hash('sha256', $token), $now]);
        $row = $st->fetch();
        if (!$row) {
            throw new HttpException(401, 'انتهت الجلسة أو رمز الدخول غير صالح', 'unauthorized');
        }
        if ($row['last_used_at'] === null || strtotime($row['last_used_at'] . ' UTC') < $this->app->clock->now()->getTimestamp() - 300) {
            $db->prepare('UPDATE access_tokens SET last_used_at = ? WHERE id = ?')->execute([$now, $row['id']]);
        }
        return $this->loadUser($row['user_id'], (int) $row['id']);
    }

    public function loadUser(string $userId, int $tokenId = 0): CurrentUser
    {
        $db = $this->app->db();
        $st = $db->prepare('SELECT id, full_name, username, must_change_password FROM users WHERE id = ?');
        $st->execute([$userId]);
        $u = $st->fetch();
        if (!$u) {
            throw HttpException::unauthorized();
        }
        $st = $db->prepare('SELECT DISTINCT p.code FROM user_roles ur
            JOIN role_permissions rp ON rp.role_id = ur.role_id
            JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ? ORDER BY p.code');
        $st->execute([$userId]);
        $perms = $st->fetchAll(\PDO::FETCH_COLUMN);
        $st = $db->prepare('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.code');
        $st->execute([$userId]);
        $roles = $st->fetchAll(\PDO::FETCH_COLUMN);
        $st = $db->prepare('SELECT cu.center_id FROM center_users cu JOIN centers c ON c.id = cu.center_id WHERE cu.user_id = ? AND c.archived_at IS NULL');
        $st->execute([$userId]);
        $centers = $st->fetchAll(\PDO::FETCH_COLUMN);
        return new CurrentUser($u['id'], $u['full_name'], $u['username'], $perms, $roles, $centers, $tokenId, (bool) $u['must_change_password']);
    }

    /** @return array<string,mixed> */
    public function profile(CurrentUser $u): array
    {
        return [
            'id' => $u->id,
            'full_name' => $u->fullName,
            'username' => $u->username,
            'roles' => $u->roles,
            'permissions' => $u->permissions,
            'center_ids' => $u->centerIds,
            'must_change_password' => $u->mustChangePassword,
        ];
    }

    public function logout(CurrentUser $user, Request $request): void
    {
        $this->app->db()->prepare('UPDATE access_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')
            ->execute([$this->app->clock->nowDb(), $user->tokenId]);
        $this->app->service(Audit::class)->log($user->id, 'auth.logout', 'user', $user->id, [], $request);
    }

    public function changePassword(CurrentUser $user, string $current, string $new, Request $request): void
    {
        $db = $this->app->db();
        $st = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$user->id]);
        if (!password_verify($current, (string) $st->fetchColumn())) {
            throw HttpException::validation(['current_password' => 'كلمة المرور الحالية غير صحيحة']);
        }
        self::assertStrongPassword($new);
        $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = ?, version = version + 1 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $this->app->clock->nowDb(), $user->id]);
        $db->prepare('UPDATE access_tokens SET revoked_at = ? WHERE user_id = ? AND id <> ? AND revoked_at IS NULL')
            ->execute([$this->app->clock->nowDb(), $user->id, $user->tokenId]);
        $this->app->service(Audit::class)->log($user->id, 'auth.password_changed', 'user', $user->id, [], $request);
    }

    public static function assertStrongPassword(string $password, string $field = 'new_password'): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw HttpException::validation([$field => 'كلمة المرور يجب ألا تقل عن ' . self::MIN_PASSWORD . ' أحرف']);
        }
        if (!preg_match('/\pL/u', $password) || !preg_match('/\d/', $password)) {
            throw HttpException::validation([$field => 'كلمة المرور يجب أن تحتوي على حروف وأرقام']);
        }
    }
}
