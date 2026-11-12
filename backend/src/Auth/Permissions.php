<?php

declare(strict_types=1);

namespace TurathManager\Auth;

/**
 * المرجع الوحيد للصلاحيات والأدوار الافتراضية.
 */
final class Permissions
{
    /** @return array<string,array{label:string,group:string}> */
    public static function catalog(): array
    {
        return [
            'manage_users' => ['label' => 'إدارة المستخدمين', 'group' => 'المستخدمون'],
            'manage_permissions' => ['label' => 'إدارة الأدوار والصلاحيات', 'group' => 'المستخدمون'],
            'view_products' => ['label' => 'عرض الموديلات', 'group' => 'الأصناف'],
            'manage_products' => ['label' => 'إدارة الموديلات', 'group' => 'الأصناف'],
            'manage_categories' => ['label' => 'إدارة التصنيفات والأنواع', 'group' => 'الأصناف'],
            'manage_inventory' => ['label' => 'تسجيل حركات المخزون', 'group' => 'الأصناف'],
            'view_customers' => ['label' => 'عرض العملاء', 'group' => 'العملاء'],
            'manage_customers' => ['label' => 'إدارة العملاء', 'group' => 'العملاء'],
            'view_centers' => ['label' => 'عرض المراكز', 'group' => 'المراكز'],
            'manage_centers' => ['label' => 'إدارة المراكز', 'group' => 'المراكز'],
            'view_bookings' => ['label' => 'عرض حجوزات المراكز المرتبطة', 'group' => 'الحجوزات'],
            'view_all_bookings' => ['label' => 'عرض جميع الحجوزات', 'group' => 'الحجوزات'],
            'create_booking' => ['label' => 'إنشاء حجز', 'group' => 'الحجوزات'],
            'edit_booking' => ['label' => 'تعديل حجز', 'group' => 'الحجوزات'],
            'cancel_booking' => ['label' => 'إلغاء حجز', 'group' => 'الحجوزات'],
            'resolve_conflicts' => ['label' => 'معالجة التعارضات', 'group' => 'الحجوزات'],
            'manage_handovers' => ['label' => 'تسجيل التسليم والاستلام', 'group' => 'العمليات'],
            'force_early_removal' => ['label' => 'تسجيل فك قبل الموعد', 'group' => 'العمليات'],
            'manage_payments' => ['label' => 'تسجيل الدفعات', 'group' => 'الحسابات'],
            'reverse_payments' => ['label' => 'عكس الدفعات', 'group' => 'الحسابات'],
            'view_financial_reports' => ['label' => 'عرض التقارير المالية', 'group' => 'الحسابات'],
            'view_reports' => ['label' => 'عرض التقارير التشغيلية', 'group' => 'التقارير'],
            'manage_notifications' => ['label' => 'إدارة مستلمي الإشعارات', 'group' => 'الإشعارات'],
            'manage_templates' => ['label' => 'إدارة قوالب الرسائل', 'group' => 'الإشعارات'],
            'manage_whatsapp' => ['label' => 'إدارة إعدادات واتساب', 'group' => 'الإشعارات'],
            'view_audit_logs' => ['label' => 'عرض سجل التدقيق', 'group' => 'النظام'],
            'manage_backups' => ['label' => 'إدارة النسخ الاحتياطي', 'group' => 'النظام'],
            'manage_settings' => ['label' => 'إدارة إعدادات النظام', 'group' => 'النظام'],
        ];
    }

    /** @return array<string,array{label:string,description:string,permissions:list<string>}> */
    public static function defaultRoles(): array
    {
        $all = array_keys(self::catalog());
        return [
            'admin' => [
                'label' => 'مدير النظام',
                'description' => 'صلاحيات كاملة',
                'permissions' => $all,
            ],
            'manager' => [
                'label' => 'مدير',
                'description' => 'إدارة العمليات اليومية والحجوزات والحسابات',
                'permissions' => array_values(array_diff($all, ['manage_users', 'manage_permissions', 'manage_backups', 'manage_whatsapp', 'view_audit_logs', 'manage_settings'])),
            ],
            'assistant' => [
                'label' => 'مساعد',
                'description' => 'إنشاء الحجوزات وتسجيل التسليم والاستلام',
                'permissions' => ['view_products', 'view_customers', 'manage_customers', 'view_centers', 'view_bookings', 'create_booking', 'edit_booking', 'manage_handovers'],
            ],
        ];
    }
}
