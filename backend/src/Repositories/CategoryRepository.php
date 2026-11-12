<?php

declare(strict_types=1);

namespace TurathManager\Repositories;

final class CategoryRepository extends Repository
{
    protected const TABLE = 'categories';
    protected const COLUMNS = ['id', 'name', 'description', 'sort_order', 'created_at', 'updated_at', 'archived_at'];
    protected const VERSIONED = true;
}
