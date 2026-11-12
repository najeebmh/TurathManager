<?php

// المراكز والمحلات وربط المستخدمين بها والعملاء
$t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    'up' => [
        "CREATE TABLE centers (
            id CHAR(36) NOT NULL PRIMARY KEY,
            code VARCHAR(30) NOT NULL,
            name VARCHAR(150) NOT NULL,
            whatsapp_number VARCHAR(20) NULL,
            location_url VARCHAR(500) NULL,
            address VARCHAR(500) NULL,
            manager_name VARCHAR(150) NULL,
            manager_phone VARCHAR(20) NULL,
            manager_email VARCHAR(190) NULL,
            assistant_name VARCHAR(150) NULL,
            assistant_phone VARCHAR(20) NULL,
            handover_officer VARCHAR(150) NULL,
            emergency_phone VARCHAR(20) NULL,
            working_hours VARCHAR(255) NULL,
            logo_path VARCHAR(255) NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_by CHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            UNIQUE KEY uq_centers_code (code),
            KEY idx_centers_name (name),
            KEY idx_centers_status (status, archived_at)
        ) $t",
        "CREATE TABLE center_users (
            center_id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,
            role_in_center ENUM('manager','assistant','handover','staff') NOT NULL DEFAULT 'staff',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (center_id, user_id),
            KEY idx_cu_user (user_id),
            CONSTRAINT fk_cu_center FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE RESTRICT,
            CONSTRAINT fk_cu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
        ) $t",
        "CREATE TABLE customers (
            id CHAR(36) NOT NULL PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            phone_alt VARCHAR(20) NULL,
            address VARCHAR(500) NULL,
            notes TEXT NULL,
            created_by CHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            UNIQUE KEY uq_customers_phone (phone),
            KEY idx_customers_name (full_name)
        ) $t",
    ],
    'down' => [
        'DROP TABLE IF EXISTS customers',
        'DROP TABLE IF EXISTS center_users',
        'DROP TABLE IF EXISTS centers',
    ],
];
