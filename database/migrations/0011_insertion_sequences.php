<?php

// تسلسل إدراج ثابت لترتيب السجلات التي تُنشأ في نفس الثانية (حركات المخزون، عناصر الحجز، الدفعات)
return [
    'up' => [
        'ALTER TABLE inventory_movements ADD COLUMN seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ADD UNIQUE KEY uq_inv_seq (seq)',
        'ALTER TABLE booking_items ADD COLUMN seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ADD UNIQUE KEY uq_bi_seq (seq)',
        'ALTER TABLE payments ADD COLUMN seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ADD UNIQUE KEY uq_pay_seq (seq)',
    ],
    'down' => [
        'ALTER TABLE payments DROP INDEX uq_pay_seq, DROP COLUMN seq',
        'ALTER TABLE booking_items DROP INDEX uq_bi_seq, DROP COLUMN seq',
        'ALTER TABLE inventory_movements DROP INDEX uq_inv_seq, DROP COLUMN seq',
    ],
];
