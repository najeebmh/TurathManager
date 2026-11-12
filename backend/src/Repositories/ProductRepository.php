<?php

declare(strict_types=1);

namespace TurathManager\Repositories;

final class ProductRepository extends Repository
{
    protected const TABLE = 'products';
    protected const COLUMNS = ['id', 'code', 'name', 'category_id', 'category_type_id', 'owner_center_id', 'description', 'price', 'quantity_total', 'status', 'notes', 'created_by', 'created_at', 'updated_at', 'archived_at'];
    protected const VERSIONED = true;
}
