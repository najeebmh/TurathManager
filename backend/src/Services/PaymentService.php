<?php

declare(strict_types=1);

namespace TurathManager\Services;

use TurathManager\Auth\CurrentUser;
use TurathManager\Core\App;
use TurathManager\Core\Clock;
use TurathManager\Core\Database;
use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Uuid;
use TurathManager\Repositories\PaymentRepository;
use TurathManager\Support\Audit;
use TurathManager\Support\ChangeLog;
use TurathManager\Support\Sequence;

/**
 * الدفعات المالية: عربون ودفعات متعددة واسترداد. لا حذف ولا تعديل؛
 * التصحيح يتم بقيد عكسي موثق. الحسابات بدقة السنتات (أعداد صحيحة) لتجنب أخطاء الفاصلة العشرية.
 */
final class PaymentService
{
    public const TYPES = ['deposit' => 'عربون', 'payment' => 'دفعة', 'refund' => 'استرداد', 'reversal' => 'قيد عكسي'];
    public const METHODS = ['cash' => 'نقداً', 'bank_transfer' => 'تحويل بنكي', 'mobile_wallet' => 'محفظة إلكترونية', 'card' => 'بطاقة', 'other' => 'أخرى'];

    private PaymentRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new PaymentRepository($app->db());
    }

    public static function cents(string|float|int|null $v): int
    {
        return (int) round(((float) ($v ?? 0)) * 100);
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** @return array<string,mixed> */
    public function summary(string $bookingId): array
    {
        $b = $this->repo->one('SELECT total_price FROM bookings WHERE id = ?', [$bookingId]) ?? throw HttpException::notFound('الحجز غير موجود');
        $rows = $this->repo->all('SELECT payment_type, amount FROM payments WHERE booking_id = ?', [$bookingId]);
        $paid = 0;
        $deposit = 0;
        foreach ($rows as $r) {
            $paid += self::cents($r['amount']);
            if ($r['payment_type'] === 'deposit') {
                $deposit += self::cents($r['amount']);
            }
        }
        $total = $b['total_price'] === null ? null : self::cents($b['total_price']);
        return [
            'total_price' => $total === null ? null : self::money($total),
            'deposit' => self::money($deposit),
            'paid' => self::money($paid),
            'remaining' => $total === null ? null : self::money($total - $paid),
        ];
    }

    public function forBooking(string $bookingId): array
    {
        $rows = $this->repo->all('SELECT p.*, u.full_name AS created_by_name FROM payments p LEFT JOIN users u ON u.id = p.created_by WHERE p.booking_id = ? ORDER BY p.paid_at, p.seq', [$bookingId]);
        return ['summary' => $this->summary($bookingId), 'items' => array_map([self::class, 'out'], $rows)];
    }

    public function get(string $id): array
    {
        $r = $this->repo->one('SELECT p.*, u.full_name AS created_by_name FROM payments p LEFT JOIN users u ON u.id = p.created_by WHERE p.id = ?', [$id]) ?? throw HttpException::notFound('الدفعة غير موجودة');
        return self::out($r);
    }

    /** @param array<string,mixed> $input */
    public function add(string $bookingId, array $input, CurrentUser $actor, ?Request $req = null): array
    {
        $booking = $this->app->service(BookingService::class)->load($bookingId, $actor);
        $d = $this->app->validator->validate($input, [
            'id' => 'nullable|uuid',
            'payment_type' => 'required|in:deposit,payment,refund',
            'amount' => 'required|decimal|min:0.01',
            'method' => 'required|in:' . implode(',', array_keys(self::METHODS)),
            'paid_at' => 'nullable|datetime',
            'notes' => 'nullable|string|max:500',
        ], ['payment_type' => 'نوع الدفعة', 'amount' => 'المبلغ', 'method' => 'طريقة الدفع', 'paid_at' => 'تاريخ الدفع']);
        $id = $d['id'] ?? Uuid::v4();
        if ($existing = $this->repo->find($id)) {
            if ($existing['booking_id'] !== $bookingId) {
                throw HttpException::conflict('معرف الدفعة مستخدم مسبقاً');
            }
            return $this->forBooking($bookingId) + ['payment' => $this->get($id)];
        }
        Database::transaction($this->app->db(), function () use ($id, $bookingId, $booking, $d, $actor) {
            $this->repo->one('SELECT id FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            $s = $this->summary($bookingId);
            if ($s['total_price'] === null) {
                throw HttpException::validation(['amount' => 'يجب تحديد السعر الإجمالي للحجز قبل تسجيل الدفعات']);
            }
            $amount = self::cents($d['amount']);
            $paid = self::cents($s['paid']);
            $total = self::cents($s['total_price']);
            if ($d['payment_type'] === 'refund') {
                if ($amount > $paid) {
                    throw HttpException::validation(['amount' => 'مبلغ الاسترداد أكبر من المدفوع (' . $s['paid'] . ')']);
                }
                $amount = -$amount;
            } else {
                if ($booking['status'] === 'cancelled') {
                    throw HttpException::conflict('لا يمكن تسجيل دفعة لحجز ملغي');
                }
                if ($paid + $amount > $total) {
                    throw HttpException::validation(['amount' => 'المبلغ يتجاوز المتبقي (' . self::money($total - $paid) . ')']);
                }
            }
            $this->repo->insert([
                'id' => $id, 'booking_id' => $bookingId, 'receipt_no' => $this->nextReceiptNo(), 'payment_type' => $d['payment_type'],
                'amount' => self::money($amount), 'method' => $d['method'], 'paid_at' => $d['paid_at'] ?? $this->app->clock->nowDb(),
                'notes' => $d['notes'] ?? null, 'created_by' => $actor->id, 'created_at' => $this->app->clock->nowDb(),
            ]);
            $this->repo->exec('UPDATE bookings SET updated_at = ?, version = version + 1 WHERE id = ?', [$this->app->clock->nowDb(), $bookingId]);
            $this->app->service(ChangeLog::class)->record('booking', $bookingId);
        });
        $this->app->service(Audit::class)->log($actor->id, 'payment.created', 'payment', $id, ['booking_id' => $bookingId, 'type' => $d['payment_type'], 'amount' => $d['amount']], $req);
        return $this->forBooking($bookingId) + ['payment' => $this->get($id)];
    }

    public function reverse(string $paymentId, string $reason, CurrentUser $actor, ?Request $req = null): array
    {
        $orig = $this->repo->find($paymentId) ?? throw HttpException::notFound('الدفعة غير موجودة');
        $this->app->service(BookingService::class)->load($orig['booking_id'], $actor);
        $reason = trim($reason);
        if ($reason === '') {
            throw HttpException::validation(['reason' => 'سبب العكس مطلوب']);
        }
        if ($orig['payment_type'] === 'reversal') {
            throw HttpException::conflict('لا يمكن عكس قيد عكسي');
        }
        $id = Uuid::v4();
        Database::transaction($this->app->db(), function () use ($orig, $id, $reason, $actor) {
            $locked = $this->repo->one('SELECT reversed_at FROM payments WHERE id = ? FOR UPDATE', [$orig['id']]);
            if ($locked['reversed_at'] !== null) {
                throw HttpException::conflict('تم عكس هذه الدفعة مسبقاً');
            }
            $now = $this->app->clock->nowDb();
            $this->repo->insert([
                'id' => $id, 'booking_id' => $orig['booking_id'], 'receipt_no' => $this->nextReceiptNo(), 'payment_type' => 'reversal',
                'amount' => self::money(-self::cents($orig['amount'])), 'method' => $orig['method'], 'paid_at' => $now,
                'notes' => mb_substr('عكس السند ' . $orig['receipt_no'] . ': ' . $reason, 0, 500), 'reverses_payment_id' => $orig['id'],
                'created_by' => $actor->id, 'created_at' => $now,
            ]);
            $this->repo->exec('UPDATE payments SET reversed_at = ? WHERE id = ?', [$now, $orig['id']]);
            $this->repo->exec('UPDATE bookings SET updated_at = ?, version = version + 1 WHERE id = ?', [$now, $orig['booking_id']]);
            $this->app->service(ChangeLog::class)->record('booking', $orig['booking_id']);
        });
        $this->app->service(Audit::class)->log($actor->id, 'payment.reversed', 'payment', $paymentId, ['reversal_id' => $id, 'reason' => $reason, 'amount' => $orig['amount']], $req);
        return $this->forBooking($orig['booking_id']) + ['payment' => $this->get($id)];
    }

    private function nextReceiptNo(): string
    {
        $year = $this->app->clock->now()->setTimezone($this->app->clock->localTz())->format('Y');
        return sprintf('RC-%s-%06d', $year, Sequence::next($this->app->db(), 'receipt', $year));
    }

    public static function out(array $r): array
    {
        return [
            'id' => $r['id'], 'booking_id' => $r['booking_id'], 'receipt_no' => $r['receipt_no'],
            'payment_type' => $r['payment_type'], 'type_label' => self::TYPES[$r['payment_type']],
            'amount' => $r['amount'], 'method' => $r['method'], 'method_label' => self::METHODS[$r['method']],
            'paid_at' => Clock::toIso($r['paid_at']), 'notes' => $r['notes'], 'reverses_payment_id' => $r['reverses_payment_id'],
            'reversed' => $r['reversed_at'] !== null, 'created_by_name' => $r['created_by_name'] ?? null, 'created_at' => Clock::toIso($r['created_at']),
        ];
    }
}
