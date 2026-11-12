<?php

declare(strict_types=1);

namespace TurathManager\Repositories;

final class CategoryTypeRepository extends Repository
{
    protected const TABLE = 'category_types';
    protected const COLUMNS = ['id', 'category_id', 'name', 'description', 'sort_order', 'created_at', 'updated_at', 'archived_at'];
    protected const VERSIONED = true;
}
