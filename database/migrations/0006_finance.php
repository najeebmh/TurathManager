<?php

// الدفعات (غير قابلة للحذف أو التعديل، التصحيح يتم بقيد عكسي) والمصروفات
$t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    'up' => [
        "CREATE TABLE payments (
            id CHAR(36) NOT NULL PRIMARY KEY,
            booking_id CHAR(36) NOT NULL,
            receipt_no VARCHAR(30) NOT NULL,
            payment_type ENUM('deposit','payment','refund','reversal') NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            method ENUM('cash','bank_transfer','mobile_wallet','card','other') NOT NULL DEFAULT 'cash',
            paid_at DATETIME NOT NULL,
            notes VARCHAR(500) NULL,
            reverses_payment_id CHAR(36) NULL,
            reversed_at DATETIME NULL,
            created_by CHAR(36) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payments_receipt (receipt_no),
            UNIQUE KEY uq_payments_reverses (reverses_payment_id),
            KEY idx_payments_booking (booking_id),
            KEY idx_payments_paid_at (paid_at),
            CONSTRAINT chk_payments_amount CHECK (amount <> 0),
            CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
            CONSTRAINT fk_payments_reverses FOREIGN KEY (reverses_payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
            CONSTRAINT fk_payments_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
        ) $t",
        "CREATE TABLE expenses (
            id CHAR(36) NOT NULL PRIMARY KEY,
            center_id CHAR(36) NULL,
            category VARCHAR(100) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            spent_at DATETIME NOT NULL,
            notes VARCHAR(500) NULL,
            created_by CHAR(36) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            KEY idx_expenses_time (spent_at),
            CONSTRAINT chk_expenses_amount CHECK (amount > 0),
            CONSTRAINT fk_expenses_center FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE RESTRICT
        ) $t",
    ],
    'down' => [
        'DROP TABLE IF EXISTS expenses',
        'DROP TABLE IF EXISTS payments',
    ],
];
