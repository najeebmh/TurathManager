<?php

// سجل النسخ الاحتياطية وحالة المهام المجدولة
$t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    'up' => [
        "CREATE TABLE backups (
            id CHAR(36) NOT NULL PRIMARY KEY,
            file_name VARCHAR(190) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            checksum_sha256 CHAR(64) NULL,
            status ENUM('running','completed','failed') NOT NULL,
            error_message VARCHAR(500) NULL,
            created_by CHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            UNIQUE KEY uq_backups_file (file_name)
        ) $t",
        "CREATE TABLE scheduled_jobs (
            job_name VARCHAR(60) NOT NULL PRIMARY KEY,
            locked_until DATETIME NULL,
            last_started_at DATETIME NULL,
            last_finished_at DATETIME NULL,
            last_status VARCHAR(20) NULL,
            last_error VARCHAR(500) NULL,
            runs INT UNSIGNED NOT NULL DEFAULT 0
        ) $t",
    ],
    'down' => [
        'DROP TABLE IF EXISTS scheduled_jobs',
        'DROP TABLE IF EXISTS backups',
    ],
];
