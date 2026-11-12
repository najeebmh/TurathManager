<?php

// المستخدمون والأدوار والصلاحيات ورموز الوصول وسجل التدقيق والإعدادات
$t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    'up' => [
        "CREATE TABLE users (
            id CHAR(36) NOT NULL PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            username VARCHAR(60) NOT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(20) NULL,
            whatsapp_number VARCHAR(20) NULL,
            whatsapp_opt_in TINYINT(1) NOT NULL DEFAULT 0,
            whatsapp_opt_in_at DATETIME NULL,
            password_hash VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            must_change_password TINYINT(1) NOT NULL DEFAULT 0,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            UNIQUE KEY uq_users_username (username),
            UNIQUE KEY uq_users_email (email),
            KEY idx_users_active (is_active)
        ) $t",
        "CREATE TABLE roles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(60) NOT NULL,
            label VARCHAR(100) NOT NULL,
            description VARCHAR(255) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_roles_code (code)
        ) $t",
        "CREATE TABLE permissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(80) NOT NULL,
            label VARCHAR(150) NOT NULL,
            group_name VARCHAR(60) NOT NULL,
            UNIQUE KEY uq_permissions_code (code)
        ) $t",
        "CREATE TABLE role_permissions (
            role_id INT UNSIGNED NOT NULL,
            permission_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        ) $t",
        "CREATE TABLE user_roles (
            user_id CHAR(36) NOT NULL,
            role_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, role_id),
            CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
        ) $t",
        "CREATE TABLE access_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id CHAR(36) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            device_id CHAR(36) NULL,
            device_name VARCHAR(120) NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            UNIQUE KEY uq_tokens_hash (token_hash),
            KEY idx_tokens_user (user_id),
            CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
        ) $t",
        "CREATE TABLE login_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_login_user_time (username, created_at),
            KEY idx_login_ip_time (ip, created_at)
        ) $t",
        "CREATE TABLE audit_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id CHAR(36) NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(60) NULL,
            entity_id VARCHAR(64) NULL,
            details JSON NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_user (user_id),
            KEY idx_audit_entity (entity_type, entity_id),
            KEY idx_audit_action_time (action, created_at),
            KEY idx_audit_time (created_at)
        ) $t",
        "CREATE TABLE app_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by CHAR(36) NULL
        ) $t",
    ],
    'down' => [
        'DROP TABLE IF EXISTS app_settings',
        'DROP TABLE IF EXISTS audit_logs',
        'DROP TABLE IF EXISTS login_attempts',
        'DROP TABLE IF EXISTS access_tokens',
        'DROP TABLE IF EXISTS user_roles',
        'DROP TABLE IF EXISTS role_permissions',
        'DROP TABLE IF EXISTS permissions',
        'DROP TABLE IF EXISTS roles',
        'DROP TABLE IF EXISTS users',
    ],
];
