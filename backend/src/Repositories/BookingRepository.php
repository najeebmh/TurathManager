<?php

declare(strict_types=1);

namespace TurathManager\Repositories;

final class BookingRepository extends Repository
{
    protected const TABLE = 'bookings';
    protected const COLUMNS = ['id', 'booking_no', 'customer_id', 'center_id', 'status', 'install_at', 'removal_at', 'address', 'total_price', 'notes', 'source', 'origin_device_id', 'client_created_at', 'cancelled_at', 'cancel_reason', 'created_by', 'updated_by', 'created_at', 'updated_at', 'archived_at'];
    protected const VERSIONED = true;
}
