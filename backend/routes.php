<?php

declare(strict_types=1);

use TurathManager\Controllers\AuthController as A;
use TurathManager\Controllers\BookingController as B;
use TurathManager\Controllers\CatalogController as C;
use TurathManager\Controllers\CenterController as CE;
use TurathManager\Controllers\HealthController;
use TurathManager\Controllers\NotificationController as N;
use TurathManager\Controllers\ReportController as R;
use TurathManager\Controllers\SyncController as S;
use TurathManager\Controllers\SystemController as SY;
use TurathManager\Controllers\UserController as U;
use TurathManager\Controllers\WhatsAppController as W;
use TurathManager\Core\Router;

/**
 * جميع مسارات REST API. كل مسار يحدد صلاحيته صراحة ويتحقق منها الخادم قبل التنفيذ.
 */
return static function (Router $r): void {
    $pub = Router::PUBLIC;
    $auth = Router::AUTH;

    // عام
    $r->get('/health', [HealthController::class, 'show'], $pub);
    $r->get('/settings/public', [SY::class, 'publicSettings'], $pub);
    $r->get('/media/{folder}/{y}/{m}/{file}', [SY::class, 'media'], $pub);
    $r->get('/webhooks/whatsapp', [W::class, 'verify'], $pub);
    $r->post('/webhooks/whatsapp', [W::class, 'webhook'], $pub);

    // المصادقة
    $r->post('/auth/login', [A::class, 'login'], $pub);
    $r->get('/auth/me', [A::class, 'me'], $auth);
    $r->post('/auth/logout', [A::class, 'logout'], $auth);
    $r->post('/auth/change-password', [A::class, 'changePassword'], $auth);

    // المستخدمون والأدوار
    $r->get('/users', [U::class, 'index'], 'manage_users');
    $r->post('/users', [U::class, 'store'], 'manage_users');
    $r->get('/users/{id}', [U::class, 'show'], 'manage_users');
    $r->put('/users/{id}', [U::class, 'update'], 'manage_users');
    $r->post('/users/{id}/activate', [U::class, 'activate'], 'manage_users');
    $r->post('/users/{id}/deactivate', [U::class, 'deactivate'], 'manage_users');
    $r->post('/users/{id}/reset-password', [U::class, 'resetPassword'], 'manage_users');
    $r->get('/roles', [U::class, 'roles'], 'manage_users');
    $r->get('/permissions', [U::class, 'permissions'], 'manage_permissions');
    $r->post('/roles', [U::class, 'storeRole'], 'manage_permissions');
    $r->put('/roles/{id}', [U::class, 'updateRole'], 'manage_permissions');
    $r->delete('/roles/{id}', [U::class, 'deleteRole'], 'manage_permissions');

    // التصنيفات والمنتجات والمخزون
    $r->get('/categories', [C::class, 'categories'], 'view_products');
    $r->post('/categories', [C::class, 'storeCategory'], 'manage_categories');
    $r->put('/categories/{id}', [C::class, 'updateCategory'], 'manage_categories');
    $r->post('/categories/{id}/archive', [C::class, 'archiveCategory'], 'manage_categories');
    $r->post('/category-types', [C::class, 'storeType'], 'manage_categories');
    $r->put('/category-types/{id}', [C::class, 'updateType'], 'manage_categories');
    $r->post('/category-types/{id}/archive', [C::class, 'archiveType'], 'manage_categories');
    $r->get('/products', [C::class, 'products'], 'view_products');
    $r->post('/products', [C::class, 'storeProduct'], 'manage_products');
    $r->get('/products/{id}', [C::class, 'product'], 'view_products');
    $r->put('/products/{id}', [C::class, 'updateProduct'], 'manage_products');
    $r->delete('/products/{id}', [C::class, 'deleteProduct'], 'manage_products');
    $r->post('/products/{id}/archive', [C::class, 'archiveProduct'], 'manage_products');
    $r->get('/products/{id}/movements', [C::class, 'movements'], 'view_products');
    $r->post('/products/{id}/movements', [C::class, 'addMovement'], 'manage_inventory');
    $r->post('/products/{id}/images', [C::class, 'uploadImage'], 'manage_products');
    $r->delete('/products/{id}/images/{imageId}', [C::class, 'deleteImage'], 'manage_products');
    $r->post('/products/{id}/images/{imageId}/primary', [C::class, 'primaryImage'], 'manage_products');

    // المراكز والعملاء
    $r->get('/centers', [CE::class, 'index'], 'view_centers');
    $r->post('/centers', [CE::class, 'store'], 'manage_centers');
    $r->get('/centers/{id}', [CE::class, 'show'], 'view_centers');
    $r->put('/centers/{id}', [CE::class, 'update'], 'manage_centers');
    $r->post('/centers/{id}/archive', [CE::class, 'archive'], 'manage_centers');
    $r->post('/centers/{id}/logo', [CE::class, 'logo'], 'manage_centers');
    $r->get('/customers', [CE::class, 'customers'], 'view_customers');
    $r->get('/customers/lookup', [CE::class, 'customerByPhone'], 'view_customers');
    $r->post('/customers', [CE::class, 'storeCustomer'], 'manage_customers');
    $r->get('/customers/{id}', [CE::class, 'customer'], 'view_customers');
    $r->put('/customers/{id}', [CE::class, 'updateCustomer'], 'manage_customers');
    $r->post('/customers/{id}/archive', [CE::class, 'archiveCustomer'], 'manage_customers');

    // الحجوزات والتقويم والتسليم
    $r->get('/dashboard', [B::class, 'dashboard'], $auth);
    $r->get('/calendar', [B::class, 'calendar'], 'view_bookings');
    $r->get('/bookings', [B::class, 'index'], 'view_bookings');
    $r->post('/bookings', [B::class, 'store'], 'create_booking');
    $r->get('/bookings/conflicts', [B::class, 'conflicts'], 'resolve_conflicts');
    $r->get('/bookings/{id}', [B::class, 'show'], 'view_bookings');
    $r->put('/bookings/{id}', [B::class, 'update'], 'edit_booking');
    $r->post('/bookings/{id}/cancel', [B::class, 'cancel'], 'cancel_booking');
    $r->post('/bookings/{id}/confirm', [B::class, 'confirm'], 'resolve_conflicts');
    $r->post('/bookings/{id}/archive', [B::class, 'archive'], 'edit_booking');
    $r->post('/booking-items/{itemId}/images', [B::class, 'itemImage'], 'edit_booking');
    $r->post('/booking-items/{itemId}/{type}', [B::class, 'handover'], 'manage_handovers');
    $r->post('/handovers/{id}/images', [B::class, 'handoverImage'], 'manage_handovers');

    // الحسابات والتقارير
    $r->get('/bookings/{id}/payments', [B::class, 'payments'], 'view_bookings');
    $r->post('/bookings/{id}/payments', [B::class, 'addPayment'], 'manage_payments');
    $r->get('/payments/{id}', [B::class, 'payment'], 'view_bookings');
    $r->post('/payments/{id}/reverse', [B::class, 'reversePayment'], 'reverse_payments');
    $r->get('/reports/{type}', [R::class, 'show'], $auth);

    // الإشعارات
    $r->get('/notifications', [N::class, 'index'], $auth);
    $r->post('/notifications/read-all', [N::class, 'readAll'], $auth);
    $r->post('/notifications/{id}/read', [N::class, 'read'], $auth);
    $r->get('/notification-templates', [N::class, 'templates'], 'manage_templates');
    $r->put('/notification-templates/{id}', [N::class, 'updateTemplate'], 'manage_templates');
    $r->get('/notification-subscriptions', [N::class, 'subscriptions'], 'manage_notifications');
    $r->put('/notification-subscriptions', [N::class, 'setSubscriptions'], 'manage_notifications');
    $r->get('/notification-logs', [N::class, 'logs'], 'manage_notifications');
    $r->post('/push-devices', [N::class, 'registerPush'], $auth);
    $r->delete('/push-devices/{deviceId}', [N::class, 'unregisterPush'], $auth);

    // واتساب
    $r->get('/whatsapp/settings', [W::class, 'settings'], 'manage_whatsapp');
    $r->put('/whatsapp/settings', [W::class, 'save'], 'manage_whatsapp');
    $r->post('/whatsapp/test-connection', [W::class, 'test'], 'manage_whatsapp');
    $r->post('/whatsapp/sync-templates', [W::class, 'syncTemplates'], 'manage_whatsapp');
    $r->post('/whatsapp/send-test', [W::class, 'sendTest'], 'manage_whatsapp');

    // المزامنة
    $r->post('/sync/push', [S::class, 'push'], $auth);
    $r->get('/sync/pull', [S::class, 'pull'], $auth);
    $r->get('/sync/status', [S::class, 'status'], $auth);

    // النظام
    $r->get('/settings', [SY::class, 'settings'], 'manage_settings');
    $r->put('/settings', [SY::class, 'saveSettings'], 'manage_settings');
    $r->post('/settings/logo', [SY::class, 'uploadLogo'], 'manage_settings');
    $r->get('/audit-logs', [SY::class, 'audit'], 'view_audit_logs');
    $r->get('/backups', [SY::class, 'backups'], 'manage_backups');
    $r->post('/backups', [SY::class, 'createBackup'], 'manage_backups');
    $r->get('/backups/{id}/download', [SY::class, 'downloadBackup'], 'manage_backups');
    $r->get('/jobs', [SY::class, 'jobs'], 'manage_settings');
};
