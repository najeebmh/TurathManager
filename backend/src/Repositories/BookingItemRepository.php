<?php

declare(strict_types=1);

namespace TurathManager\Repositories;

final class BookingItemRepository extends Repository
{
    protected const TABLE = 'booking_items';
    protected const COLUMNS = ['id', 'booking_id', 'product_id', 'quantity', 'unit_price', 'install_at', 'removal_at', 'status', 'notes', 'installed_at', 'removed_at', 'created_at', 'updated_at'];
    protected const VERSIONED = false;
}
