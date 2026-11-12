<?php

// أجهزة المزامنة وسجل عمليات Outbox المستلمة وسجل التغييرات للسحب ومفاتيح عدم التكرار
$t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    'up' => [
        "CREATE TABLE sync_devices (
            id CHAR(36) NOT NULL PRIMARY KEY,
            user_id CHAR(36) NOT NULL,
            device_name VARCHAR(120) NULL,
            platform VARCHAR(20) NULL,
            app_version VARCHAR(20) NULL,
            last_seen_at DATETIME NULL,
            last_push_at DATETIME NULL,
            last_pull_at DATETIME NULL,
            last_pull_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sd_user (user_id),
            CONSTRAINT fk_sd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
        ) $t",
        "CREATE TABLE sync_operations (
            id CHAR(36) NOT NULL PRIMARY KEY,
            device_id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,
            entity_type VARCHAR(40) NOT NULL,
            action VARCHAR(20) NOT NULL,
            entity_id CHAR(36) NULL,
            payload JSON NOT NULL,
            base_version INT UNSIGNED NULL,
            status ENUM('applied','rejected','conflict') NOT NULL,
            result JSON NULL,
            error_message VARCHAR(500) NULL,
            client_created_at DATETIME NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_so_device (device_id, received_at),
            KEY idx_so_status (status, received_at),
            KEY idx_so_entity (entity_type, entity_id)
        ) $t",
        "CREATE TABLE change_log (
            seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(40) NOT NULL,
            entity_id CHAR(36) NOT NULL,
            action ENUM('upsert','delete') NOT NULL DEFAULT 'upsert',
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cl_entity (entity_type, entity_id)
        ) $t",
        "CREATE TABLE idempotency_keys (
            idem_key VARCHAR(100) NOT NULL,
            user_id CHAR(36) NOT NULL,
            method VARCHAR(10) NOT NULL,
            path VARCHAR(255) NOT NULL,
            response_status SMALLINT UNSIGNED NOT NULL,
            response_body LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, idem_key)
        ) $t",
    ],
    'down' => [
        'DROP TABLE IF EXISTS idempotency_keys',
        'DROP TABLE IF EXISTS change_log',
        'DROP TABLE IF EXISTS sync_operations',
        'DROP TABLE IF EXISTS sync_devices',
    ],
];
