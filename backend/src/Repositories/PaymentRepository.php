<?php

declare(strict_types=1);

namespace TurathManager\Repositories;

final class PaymentRepository extends Repository
{
    protected const TABLE = 'payments';
    protected const COLUMNS = ['id', 'booking_id', 'receipt_no', 'payment_type', 'amount', 'method', 'paid_at', 'notes', 'reverses_payment_id', 'reversed_at', 'created_by', 'created_at'];
    protected const VERSIONED = false;
}
