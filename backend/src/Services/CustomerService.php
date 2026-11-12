<?php

declare(strict_types=1);

namespace TurathManager\Services;

use TurathManager\Auth\CurrentUser;
use TurathManager\Core\App;
use TurathManager\Core\Clock;
use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Uuid;
use TurathManager\Repositories\CustomerRepository;
use TurathManager\Support\Audit;
use TurathManager\Support\ChangeLog;

/**
 * العملاء. رقم الهاتف يُخزن بصيغة E.164 وهو فريد لمنع تكرار السجلات.
 */
final class CustomerService
{
    private const RULES = [
        'full_name' => 'required|string|max:150',
        'phone' => 'required|phone',
        'phone_alt' => 'nullable|phone',
        'address' => 'nullable|string|max:500',
        'notes' => 'nullable|string|max:5000',
    ];
    private const LABELS = ['full_name' => 'اسم العميل', 'phone' => 'الهاتف', 'phone_alt' => 'هاتف بديل', 'address' => 'العنوان'];

    private CustomerRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new CustomerRepository($app->db());
    }

    public function list(?string $search, string $status = 'active', int $limit = 50, int $offset = 0): array
    {
        $where = [$status === 'archived' ? 'c.archived_at IS NOT NULL' : ($status === 'all' ? '1=1' : 'c.archived_at IS NULL')];
        $p = [];
        if ($search) {
            $like = CustomerRepository::like($search);
            $digits = preg_replace('/\D/', '', $search);
            $where[] = '(c.full_name LIKE ? OR c.phone LIKE ? OR c.phone_alt LIKE ?' . ($digits !== '' ? ' OR c.phone LIKE ?' : '') . ')';
            array_push($p, $like, $like, $like);
            if ($digits !== '') {
                $p[] = CustomerRepository::like(ltrim($digits, '0'));
            }
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->repo->value("SELECT COUNT(*) FROM customers c WHERE $w", $p);
        $rows = $this->repo->all("SELECT c.*, (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id) AS bookings_count
            FROM customers c WHERE $w ORDER BY c.full_name LIMIT $limit OFFSET $offset", $p);
        return ['data' => array_map([self::class, 'out'], $rows), 'total' => $total];
    }

    public function get(string $id, bool $withBookings = false): array
    {
        $r = $this->repo->one('SELECT c.*, (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id) AS bookings_count FROM customers c WHERE c.id = ?', [$id])
            ?? throw HttpException::notFound('العميل غير موجود');
        $o = self::out($r);
        if ($withBookings) {
            $o['bookings'] = array_map(static fn ($b) => [
                'id' => $b['id'], 'booking_no' => $b['booking_no'], 'status' => $b['status'], 'center_name' => $b['center_name'],
                'install_at' => Clock::toIso($b['install_at']), 'removal_at' => Clock::toIso($b['removal_at']), 'total_price' => $b['total_price'],
            ], $this->repo->all('SELECT b.*, ce.name AS center_name FROM bookings b JOIN centers ce ON ce.id = b.center_id WHERE b.customer_id = ? ORDER BY b.install_at DESC', [$id]));
        }
        return $o;
    }

    public function findByPhone(string $phone): ?array
    {
        $r = $this->repo->one('SELECT c.*, 0 AS bookings_count FROM customers c WHERE c.phone = ?', [$phone]);
        return $r ? self::out($r) : null;
    }

    public function save(?string $id, array $input, CurrentUser $actor, ?Request $req = null, ?int $baseVersion = null): array
    {
        $d = $this->app->validator->validate($input, self::RULES, self::LABELS);
        $dup = $this->repo->one('SELECT id, full_name FROM customers WHERE phone = ? AND id <> ?', [$d['phone'], $id ?? '']);
        if ($dup) {
            throw HttpException::conflict('يوجد عميل مسجل بنفس رقم الهاتف: ' . $dup['full_name'], ['existing_customer_id' => $dup['id']], 'duplicate_customer');
        }
        $now = $this->app->clock->nowDb();
        $existing = $id ? $this->repo->find($id) : null;
        if ($existing) {
            if (!$this->repo->update($id, $d + ['updated_at' => $now], $baseVersion)) {
                throw HttpException::conflict('تم تعديل بيانات العميل من جهاز آخر', ['server' => $this->get($id)], 'version_conflict');
            }
            $action = 'customer.updated';
        } else {
            $id = $id && Uuid::isValid($id) ? strtolower($id) : Uuid::v4();
            $this->repo->insert($d + ['id' => $id, 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now]);
            $action = 'customer.created';
        }
        $this->app->service(ChangeLog::class)->record('customer', $id);
        $this->app->service(Audit::class)->log($actor->id, $action, 'customer', $id, [], $req);
        return $this->get($id);
    }

    /**
     * يستخدمها الحجز: يعيد العميل الموجود بنفس الهاتف أو ينشئ عميلاً جديداً.
     * @param array<string,mixed> $input
     */
    public function findOrCreate(array $input, CurrentUser $actor): string
    {
        $d = $this->app->validator->validate($input, self::RULES, self::LABELS);
        $existing = $this->repo->one('SELECT id FROM customers WHERE phone = ?', [$d['phone']]);
        if ($existing) {
            return $existing['id'];
        }
        $id = isset($input['id']) && Uuid::isValid((string) $input['id']) ? strtolower((string) $input['id']) : Uuid::v4();
        if ($this->repo->find($id)) {
            return $id;
        }
        $now = $this->app->clock->nowDb();
        $this->repo->insert($d + ['id' => $id, 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now]);
        $this->app->service(ChangeLog::class)->record('customer', $id);
        return $id;
    }

    public function archive(string $id, bool $archive, CurrentUser $actor, ?Request $req = null): array
    {
        $this->get($id);
        $this->repo->update($id, ['archived_at' => $archive ? $this->app->clock->nowDb() : null, 'updated_at' => $this->app->clock->nowDb()]);
        $this->app->service(ChangeLog::class)->record('customer', $id);
        $this->app->service(Audit::class)->log($actor->id, $archive ? 'customer.archived' : 'customer.restored', 'customer', $id, [], $req);
        return $this->get($id);
    }

    public static function out(array $r): array
    {
        return [
            'id' => $r['id'], 'full_name' => $r['full_name'], 'phone' => $r['phone'], 'phone_alt' => $r['phone_alt'],
            'address' => $r['address'], 'notes' => $r['notes'], 'bookings_count' => (int) ($r['bookings_count'] ?? 0),
            'archived' => $r['archived_at'] !== null, 'version' => (int) $r['version'],
            'created_at' => Clock::toIso($r['created_at']), 'updated_at' => Clock::toIso($r['updated_at']),
        ];
    }
}
