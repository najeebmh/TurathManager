<?php

declare(strict_types=1);

namespace TurathManager\Repositories;

final class CenterRepository extends Repository
{
    protected const TABLE = 'centers';
    protected const COLUMNS = ['id', 'code', 'name', 'whatsapp_number', 'location_url', 'address', 'manager_name', 'manager_phone', 'manager_email', 'assistant_name', 'assistant_phone', 'handover_officer', 'emergency_phone', 'working_hours', 'logo_path', 'status', 'notes', 'created_by', 'created_at', 'updated_at', 'archived_at'];
    protected const VERSIONED = true;
}
