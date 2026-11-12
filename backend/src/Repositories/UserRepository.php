<?php

declare(strict_types=1);

namespace TurathManager\Repositories;

final class UserRepository extends Repository
{
    protected const TABLE = 'users';
    protected const COLUMNS = ['id', 'full_name', 'username', 'email', 'phone', 'whatsapp_number', 'whatsapp_opt_in', 'whatsapp_opt_in_at', 'password_hash', 'is_active', 'must_change_password', 'last_login_at', 'created_at', 'updated_at', 'deleted_at'];
    protected const VERSIONED = true;
}
