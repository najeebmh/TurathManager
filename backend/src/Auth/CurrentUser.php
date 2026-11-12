<?php

declare(strict_types=1);

namespace TurathManager\Auth;

use TurathManager\Core\HttpException;

/**
 * المستخدم المصادق عليه في الطلب الحالي مع صلاحياته ومراكزه.
 */
final class CurrentUser
{
    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     * @param list<string> $centerIds
     */
    public function __construct(
        public readonly string $id,
        public readonly string $fullName,
        public readonly string $username,
        public readonly array $permissions,
        public readonly array $roles,
        public readonly array $centerIds,
        public readonly int $tokenId,
        public readonly bool $mustChangePassword = false,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            throw HttpException::forbidden();
        }
    }

    public function canAccessCenter(string $centerId): bool
    {
        return $this->can('view_all_bookings') || in_array($centerId, $this->centerIds, true);
    }
}
