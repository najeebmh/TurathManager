<?php

// البيانات المرجعية: الصلاحيات، الأدوار الافتراضية، قوالب الإشعارات، الإعدادات الأساسية.
// لا تُنشأ أي حسابات أو بيانات تجريبية هنا.
use TurathManager\Auth\Permissions;

return [
    'up' => static function (PDO $pdo): void {
        $insPerm = $pdo->prepare('INSERT INTO permissions (code, label, group_name) VALUES (?, ?, ?)');
        foreach (Permissions::catalog() as $code => $p) {
            $insPerm->execute([$code, $p['label'], $p['group']]);
        }
        $insRole = $pdo->prepare('INSERT INTO roles (code, label, description, is_system) VALUES (?, ?, ?, 1)');
        $link = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = ?');
        foreach (Permissions::defaultRoles() as $code => $role) {
            $insRole->execute([$code, $role['label'], $role['description']]);
            $roleId = (int) $pdo->lastInsertId();
            foreach ($role['permissions'] as $perm) {
                $link->execute([$roleId, $perm]);
            }
        }

        $tpl = $pdo->prepare('INSERT INTO notification_templates
            (template_key, channel, title, body, whatsapp_template_name, whatsapp_language, whatsapp_category, whatsapp_params, meta_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $inApp = [
            'booking_created' => ['حجز جديد {booking_no}', 'تم تسجيل حجز للعميل {customer_name} ({category} - {item_name}) في {center_name}. التركيب: {installation_date} - الفك: {removal_date}.'],
            'installation_upcoming' => ['اقتراب موعد التركيب {booking_no}', 'موعد تركيب {item_name} للعميل {customer_name} في {center_name} بتاريخ {installation_date}. العنوان: {address}.'],
            'removal_upcoming' => ['موعد الفك غداً {booking_no}', 'موعد فك {item_name} للعميل {customer_name} في {center_name} بتاريخ {removal_date}.'],
            'booking_updated' => ['تعديل الحجز {booking_no}', 'تم تعديل حجز العميل {customer_name}. التركيب: {installation_date} - الفك: {removal_date}.'],
            'booking_cancelled' => ['إلغاء الحجز {booking_no}', 'تم إلغاء حجز العميل {customer_name} في {center_name}.'],
            'booking_conflict' => ['تعارض في الحجز', 'يوجد تعارض في حجز العميل {customer_name} ({item_name}) يحتاج إلى معالجة.'],
            'sync_failed' => ['فشل المزامنة', 'فشلت مزامنة بعض العمليات من الجهاز {device_name}.'],
            'whatsapp_failed' => ['فشل إرسال واتساب', 'تعذر إرسال رسالة واتساب: {error}.'],
        ];
        foreach ($inApp as $key => [$title, $body]) {
            $tpl->execute([$key, 'in_app', $title, $body, null, null, null, null, null]);
        }
        $wa = [
            'booking_created' => ['booking_confirmation', 'تأكيد حجز', 'تم تأكيد حجز رقم {{1}} للعميل {{2}} في {{3}}. التركيب: {{4}}.', 'booking_no,customer_name,center_name,installation_date'],
            'installation_upcoming' => ['installation_reminder', 'تذكير التركيب', 'تذكير: موعد تركيب {{1}} للحجز {{2}} في {{3}} بتاريخ {{4}}.', 'item_name,booking_no,center_name,installation_date'],
            'removal_upcoming' => ['removal_reminder', 'تذكير الفك', 'تذكير: موعد فك {{1}} للحجز {{2}} في {{3}} بتاريخ {{4}}.', 'item_name,booking_no,center_name,removal_date'],
        ];
        foreach ($wa as $key => [$name, $title, $body, $params]) {
            $tpl->execute([$key, 'whatsapp', $title, $body, $name, 'ar', 'UTILITY', $params, 'unknown']);
        }

        $set = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ([
            'shop_name' => 'Turath Manager',
            'shop_logo' => '',
            'installation_reminder_hours' => '24',
            'removal_reminder_hours' => '24',
        ] as $k => $v) {
            $set->execute([$k, $v]);
        }
        $pdo->exec("INSERT INTO whatsapp_settings (id, mode, is_enabled, connection_status) VALUES (1, 'test', 0, 'not_configured')");
    },
    'down' => [
        'DELETE FROM whatsapp_settings',
        'DELETE FROM app_settings',
        'DELETE FROM notification_templates',
        'DELETE FROM role_permissions',
        'DELETE FROM roles WHERE is_system = 1',
        'DELETE FROM permissions',
    ],
];
