<?php

// عمليات التسليم (عند التركيب) والاستلام (عند الفك) وتقارير حالة القطع وصورها
$t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    'up' => [
        "CREATE TABLE handovers (
            id CHAR(36) NOT NULL PRIMARY KEY,
            booking_id CHAR(36) NOT NULL,
            booking_item_id CHAR(36) NOT NULL,
            handover_type ENUM('delivery','return') NOT NULL,
            occurred_at DATETIME NOT NULL,
            delivered_by_name VARCHAR(150) NOT NULL,
            received_by_name VARCHAR(150) NOT NULL,
            item_condition ENUM('excellent','good','fair','damaged','missing') NOT NULL,
            notes TEXT NULL,
            signature_data MEDIUMTEXT NULL,
            early_reason VARCHAR(255) NULL,
            recorded_by CHAR(36) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_handover_item_type (booking_item_id, handover_type),
            KEY idx_handover_booking (booking_id, occurred_at),
            CONSTRAINT fk_ho_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
            CONSTRAINT fk_ho_item FOREIGN KEY (booking_item_id) REFERENCES booking_items(id) ON DELETE RESTRICT,
            CONSTRAINT fk_ho_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
        ) $t",
        "CREATE TABLE handover_images (
            id CHAR(36) NOT NULL PRIMARY KEY,
            handover_id CHAR(36) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            mime VARCHAR(50) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            created_by CHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_hoi_handover (handover_id),
            CONSTRAINT fk_hoi_handover FOREIGN KEY (handover_id) REFERENCES handovers(id) ON DELETE RESTRICT
        ) $t",
        "CREATE TABLE item_condition_reports (
            id CHAR(36) NOT NULL PRIMARY KEY,
            handover_id CHAR(36) NOT NULL,
            booking_item_id CHAR(36) NOT NULL,
            product_id CHAR(36) NOT NULL,
            phase ENUM('before_delivery','after_return') NOT NULL,
            item_condition ENUM('excellent','good','fair','damaged','missing') NOT NULL,
            damaged_quantity INT UNSIGNED NOT NULL DEFAULT 0,
            damage_notes TEXT NULL,
            created_by CHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_icr_product (product_id, item_condition),
            KEY idx_icr_time (created_at),
            CONSTRAINT fk_icr_handover FOREIGN KEY (handover_id) REFERENCES handovers(id) ON DELETE RESTRICT,
            CONSTRAINT fk_icr_item FOREIGN KEY (booking_item_id) REFERENCES booking_items(id) ON DELETE RESTRICT,
            CONSTRAINT fk_icr_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
        ) $t",
    ],
    'down' => [
        'DROP TABLE IF EXISTS item_condition_reports',
        'DROP TABLE IF EXISTS handover_images',
        'DROP TABLE IF EXISTS handovers',
    ],
];
