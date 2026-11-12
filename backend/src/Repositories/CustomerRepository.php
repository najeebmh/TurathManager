<?php

declare(strict_types=1);

namespace TurathManager\Repositories;

final class CustomerRepository extends Repository
{
    protected const TABLE = 'customers';
    protected const COLUMNS = ['id', 'full_name', 'phone', 'phone_alt', 'address', 'notes', 'created_by', 'created_at', 'updated_at', 'archived_at'];
    protected const VERSIONED = true;
}
