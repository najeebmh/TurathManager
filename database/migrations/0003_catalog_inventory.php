<?php

// التصنيفات والأنواع الفرعية والمنتجات (الموديلات) وصورها وحركة المخزون
$t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    'up' => [
        "CREATE TABLE categories (
            id CHAR(36) NOT NULL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(500) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            UNIQUE KEY uq_categories_name (name)
        ) $t",
        "CREATE TABLE category_types (
            id CHAR(36) NOT NULL PRIMARY KEY,
            category_id CHAR(36) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(500) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            UNIQUE KEY uq_types_cat_name (category_id, name),
            CONSTRAINT fk_types_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
        ) $t",
        "CREATE TABLE products (
            id CHAR(36) NOT NULL PRIMARY KEY,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(150) NOT NULL,
            category_id CHAR(36) NOT NULL,
            category_type_id CHAR(36) NULL,
            owner_center_id CHAR(36) NULL,
            description TEXT NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            quantity_total INT NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_by CHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            UNIQUE KEY uq_products_code (code),
            KEY idx_products_name (name),
            KEY idx_products_cat (category_id, category_type_id),
            KEY idx_products_price (price),
            CONSTRAINT chk_products_qty CHECK (quantity_total >= 0),
            CONSTRAINT chk_products_price CHECK (price >= 0),
            CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
            CONSTRAINT fk_products_type FOREIGN KEY (category_type_id) REFERENCES category_types(id) ON DELETE RESTRICT,
            CONSTRAINT fk_products_owner FOREIGN KEY (owner_center_id) REFERENCES centers(id) ON DELETE RESTRICT
        ) $t",
        "CREATE TABLE product_images (
            id CHAR(36) NOT NULL PRIMARY KEY,
            product_id CHAR(36) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            mime VARCHAR(50) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            width INT UNSIGNED NULL,
            height INT UNSIGNED NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_by CHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            KEY idx_pimg_product (product_id, deleted_at),
            CONSTRAINT fk_pimg_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
        ) $t",
        "CREATE TABLE inventory_movements (
            id CHAR(36) NOT NULL PRIMARY KEY,
            product_id CHAR(36) NOT NULL,
            movement_type ENUM('initial','purchase','adjustment_in','adjustment_out','damage','maintenance_out','maintenance_in','loss','disposal') NOT NULL,
            quantity INT NOT NULL,
            balance_after INT NOT NULL,
            reason VARCHAR(255) NULL,
            reference_type VARCHAR(40) NULL,
            reference_id CHAR(36) NULL,
            created_by CHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_inv_product_time (product_id, created_at),
            KEY idx_inv_type_time (movement_type, created_at),
            CONSTRAINT chk_inv_qty CHECK (quantity <> 0),
            CONSTRAINT fk_inv_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
        ) $t",
    ],
    'down' => [
        'DROP TABLE IF EXISTS inventory_movements',
        'DROP TABLE IF EXISTS product_images',
        'DROP TABLE IF EXISTS products',
        'DROP TABLE IF EXISTS category_types',
        'DROP TABLE IF EXISTS categories',
    ],
];
