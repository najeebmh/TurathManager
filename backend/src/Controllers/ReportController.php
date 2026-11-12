<?php

declare(strict_types=1);

namespace TurathManager\Controllers;

use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Response;
use TurathManager\Services\ReportService;

/**
 * التقارير. الصيغة: JSON افتراضياً، أو CSV (يفتح في Excel) عبر format=csv.
 * الطباعة / PDF تتم من الواجهة بصفحة طباعة RTL.
 */
final class ReportController extends Controller
{
    private const HEADERS = [
        'revenue' => ['period_key' => 'الفترة', 'received' => 'المقبوض', 'refunded' => 'المسترد', 'net' => 'الصافي', 'payments_count' => 'عدد الدفعات'],
        'outstanding' => ['booking_no' => 'رقم الحجز', 'customer_name' => 'العميل', 'customer_phone' => 'الهاتف', 'center_name' => 'المركز', 'install_at' => 'التركيب', 'total_price' => 'الإجمالي', 'paid' => 'المدفوع', 'remaining' => 'المتبقي'],
        'bookings_by_center' => ['center_name' => 'المركز', 'bookings_count' => 'عدد الحجوزات', 'cancelled_count' => 'الملغاة', 'total_value' => 'القيمة'],
        'bookings_by_type' => ['category_name' => 'التصنيف', 'type_name' => 'النوع', 'bookings_count' => 'عدد الحجوزات', 'pieces' => 'عدد القطع'],
        'top_products' => ['code' => 'الرقم', 'name' => 'الموديل', 'category_name' => 'التصنيف', 'bookings_count' => 'عدد الحجوزات', 'pieces' => 'عدد القطع'],
        'inventory_movements' => ['created_at' => 'التاريخ', 'code' => 'الرقم', 'product_name' => 'الموديل', 'label' => 'الحركة', 'quantity' => 'الكمية', 'balance_after' => 'الرصيد', 'reason' => 'السبب', 'created_by_name' => 'بواسطة'],
        'damaged_items' => ['created_at' => 'التاريخ', 'code' => 'الرقم', 'product_name' => 'الموديل', 'condition' => 'الحالة', 'damaged_quantity' => 'الكمية', 'booking_no' => 'الحجز', 'customer_name' => 'العميل', 'notes' => 'ملاحظات'],
    ];

    public function show(Request $r): Response
    {
        $type = (string) $r->param('type');
        $user = $this->user($r);
        if (in_array($type, ReportService::FINANCIAL, true)) {
            $user->require('view_financial_reports');
        } elseif (in_array($type, ReportService::OPERATIONAL, true)) {
            $user->require('view_reports');
        } else {
            throw HttpException::notFound('التقرير غير موجود');
        }
        $s = $this->app->service(ReportService::class);
        $from = $r->query('from') ?: null;
        $to = $r->query('to') ?: null;
        $center = $r->query('center_id') ?: null;
        $result = match ($type) {
            'revenue' => $s->revenue((string) ($r->query('period') ?? 'daily'), $from, $to, $center),
            'outstanding' => $s->outstanding($center),
            'bookings_by_center' => $s->bookingsByCenter($from, $to),
            'bookings_by_type' => $s->bookingsByType($from, $to),
            'top_products' => $s->topProducts($from, $to),
            'inventory_movements' => $s->inventoryMovements($from, $to, $r->query('product_id') ?: null),
            'damaged_items' => $s->damagedItems($from, $to),
        };
        if ($r->query('format') === 'csv') {
            $h = self::HEADERS[$type];
            $rows = array_map(static fn ($row) => array_map(static fn ($k) => $row[$k] ?? '', array_keys($h)), $result['rows']);
            return Response::csv($type . '-' . gmdate('Ymd') . '.csv', array_values($h), $rows);
        }
        return Response::json(['data' => $result]);
    }
}
