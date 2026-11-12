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
use TurathManager\Repositories\ProductRepository;
use TurathManager\Support\Audit;
use TurathManager\Support\ChangeLog;
use TurathManager\Support\UploadService;

/**
 * الموديلات (المنتجات) وصورها وحركة المخزون.
 * الكمية لا تتغير إلا عبر حركة مخزون مسجلة.
 */
final class ProductService
{
    /** إشارة كل نوع حركة: +1 إضافة، -1 نقص */
    public const MOVEMENT_SIGNS = [
        'initial' => 1, 'purchase' => 1, 'adjustment_in' => 1, 'maintenance_in' => 1,
        'adjustment_out' => -1, 'damage' => -1, 'maintenance_out' => -1, 'loss' => -1, 'disposal' => -1,
    ];

    public const MOVEMENT_LABELS = [
        'initial' => 'رصيد افتتاحي', 'purchase' => 'إضافة / شراء', 'adjustment_in' => 'تسوية بالزيادة', 'maintenance_in' => 'عودة من الصيانة',
        'adjustment_out' => 'تسوية بالنقص', 'damage' => 'تلف', 'maintenance_out' => 'إرسال للصيانة', 'loss' => 'فقدان', 'disposal' => 'إتلاف / استبعاد',
    ];

    private const LABELS = [
        'code' => 'رقم القطعة', 'name' => 'اسم المنتج', 'category_id' => 'التصنيف', 'category_type_id' => 'النوع الفرعي',
        'price' => 'السعر', 'initial_quantity' => 'الكمية', 'status' => 'حالة المنتج', 'owner_center_id' => 'المركز المالك',
    ];

    private ProductRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new ProductRepository($app->db());
    }

    /** @param array<string,mixed> $f */
    public function list(array $f, int $limit = 50, int $offset = 0): array
    {
        $where = ['1=1'];
        $p = [];
        $status = $f['status'] ?? 'active';
        if ($status === 'archived') {
            $where[] = 'p.archived_at IS NOT NULL';
        } elseif ($status !== 'all') {
            $where[] = 'p.archived_at IS NULL';
        }
        if (!empty($f['search'])) {
            $where[] = '(p.name LIKE ? OR p.code LIKE ?)';
            $like = ProductRepository::like((string) $f['search']);
            array_push($p, $like, $like);
        }
        foreach (['category_id' => 'p.category_id', 'category_type_id' => 'p.category_type_id'] as $k => $col) {
            if (!empty($f[$k]) && Uuid::isValid((string) $f[$k])) {
                $where[] = "$col = ?";
                $p[] = $f[$k];
            }
        }
        $sort = match ($f['sort'] ?? '') {
            'price_asc' => 'p.price ASC', 'price_desc' => 'p.price DESC',
            'qty_asc' => 'p.quantity_total ASC', 'qty_desc' => 'p.quantity_total DESC',
            'code' => 'p.code ASC', 'newest' => 'p.created_at DESC',
            default => 'p.name ASC',
        };
        $w = implode(' AND ', $where);
        $total = (int) $this->repo->value("SELECT COUNT(*) FROM products p WHERE $w", $p);
        $rows = $this->repo->all("SELECT p.*, c.name AS category_name, t.name AS type_name,
                (SELECT file_path FROM product_images i WHERE i.product_id = p.id AND i.deleted_at IS NULL ORDER BY i.is_primary DESC, i.sort_order, i.created_at LIMIT 1) AS primary_image
            FROM products p JOIN categories c ON c.id = p.category_id LEFT JOIN category_types t ON t.id = p.category_type_id
            WHERE $w ORDER BY $sort LIMIT $limit OFFSET $offset", $p);
        $avail = $this->app->service(AvailabilityService::class);
        $data = array_map(function ($r) use ($avail) {
            $o = self::out($r);
            $o['reserved_now'] = $avail->reservedNow($r['id']);
            $o['available_now'] = max(0, $o['quantity_total'] - $o['reserved_now']);
            return $o;
        }, $rows);
        return ['data' => $data, 'total' => $total];
    }

    public function get(string $id): array
    {
        $r = $this->repo->one('SELECT p.*, c.name AS category_name, t.name AS type_name FROM products p
            JOIN categories c ON c.id = p.category_id LEFT JOIN category_types t ON t.id = p.category_type_id WHERE p.id = ?', [$id]);
        if (!$r) {
            throw HttpException::notFound('المنتج غير موجود');
        }
        $o = self::out($r);
        $avail = $this->app->service(AvailabilityService::class);
        $o['reserved_now'] = $avail->reservedNow($id);
        $o['available_now'] = max(0, $o['quantity_total'] - $o['reserved_now']);
        $o['images'] = array_map(static fn ($i) => [
            'id' => $i['id'], 'url' => 'media/' . $i['file_path'], 'is_primary' => (bool) $i['is_primary'], 'width' => $i['width'], 'height' => $i['height'],
        ], $this->repo->all('SELECT * FROM product_images WHERE product_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, sort_order, created_at', [$id]));
        return $o;
    }

    public function details(string $id): array
    {
        $o = $this->get($id);
        $o['movements'] = $this->movements($id);
        $o['schedule'] = array_map(static function ($s) {
            $s['install_at'] = Clock::toIso($s['install_at']);
            $s['removal_at'] = Clock::toIso($s['removal_at']);
            $s['quantity'] = (int) $s['quantity'];
            return $s;
        }, $this->app->service(AvailabilityService::class)->schedule($id));
        return $o;
    }

    public function movements(string $productId, int $limit = 100): array
    {
        return array_map(static fn ($m) => [
            'id' => $m['id'], 'movement_type' => $m['movement_type'], 'label' => self::MOVEMENT_LABELS[$m['movement_type']] ?? $m['movement_type'],
            'quantity' => (int) $m['quantity'], 'balance_after' => (int) $m['balance_after'], 'reason' => $m['reason'],
            'reference_type' => $m['reference_type'], 'reference_id' => $m['reference_id'],
            'created_by_name' => $m['created_by_name'], 'created_at' => Clock::toIso($m['created_at']),
        ], $this->repo->all("SELECT m.*, u.full_name AS created_by_name FROM inventory_movements m LEFT JOIN users u ON u.id = m.created_by
            WHERE m.product_id = ? ORDER BY m.created_at DESC, m.balance_after LIMIT $limit", [$productId]));
    }

    public function create(array $input, CurrentUser $actor, ?Request $req = null): array
    {
        $d = $this->validate($input, false);
        $id = isset($input['id']) && Uuid::isValid((string) $input['id']) ? strtolower((string) $input['id']) : Uuid::v4();
        if ($this->repo->find($id)) {
            return $this->get($id);
        }
        $qty = (int) ($d['initial_quantity'] ?? 0);
        $now = $this->app->clock->nowDb();
        Database::transaction($this->app->db(), function () use ($d, $id, $qty, $now, $actor) {
            $this->repo->insert([
                'id' => $id, 'code' => $d['code'], 'name' => $d['name'], 'category_id' => $d['category_id'],
                'category_type_id' => $d['category_type_id'] ?? null, 'owner_center_id' => $d['owner_center_id'] ?? null,
                'description' => $d['description'] ?? null, 'price' => $d['price'] ?? '0.00', 'quantity_total' => 0,
                'status' => $d['status'] ?? 'active', 'notes' => $d['notes'] ?? null, 'created_by' => $actor->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($qty > 0) {
                $this->insertMovement($id, 'initial', $qty, 'رصيد افتتاحي عند إضافة المنتج', $actor->id);
            }
            $this->app->service(ChangeLog::class)->record('product', $id);
        });
        $this->app->service(Audit::class)->log($actor->id, 'product.created', 'product', $id, ['code' => $d['code'], 'quantity' => $qty], $req);
        return $this->get($id);
    }

    public function update(string $id, array $input, CurrentUser $actor, ?Request $req = null, ?int $baseVersion = null): array
    {
        $before = $this->repo->find($id) ?? throw HttpException::notFound('المنتج غير موجود');
        if (array_key_exists('quantity_total', $input) || array_key_exists('initial_quantity', $input)) {
            throw HttpException::validation(['quantity' => 'لا يمكن تعديل الكمية مباشرة. استخدم حركة المخزون']);
        }
        $d = $this->validate($input + ['code' => $before['code'], 'name' => $before['name'], 'category_id' => $before['category_id']], true, $id);
        $data = array_intersect_key($d, array_flip(['code', 'name', 'category_id', 'category_type_id', 'owner_center_id', 'description', 'price', 'status', 'notes']));
        $data['updated_at'] = $this->app->clock->nowDb();
        if (!$this->repo->update($id, $data, $baseVersion)) {
            throw HttpException::conflict('تم تعديل المنتج من جهاز آخر', ['server' => $this->get($id)], 'version_conflict');
        }
        $this->app->service(ChangeLog::class)->record('product', $id);
        $changes = [];
        foreach ($data as $k => $v) {
            if ($k !== 'updated_at' && (string) $before[$k] !== (string) $v) {
                $changes[$k] = ['from' => $before[$k], 'to' => $v];
            }
        }
        $this->app->service(Audit::class)->log($actor->id, 'product.updated', 'product', $id, $changes, $req);
        return $this->get($id);
    }

    public function archive(string $id, bool $archive, CurrentUser $actor, ?Request $req = null): array
    {
        $this->repo->find($id) ?? throw HttpException::notFound('المنتج غير موجود');
        if ($archive) {
            $active = (int) $this->repo->value("SELECT COUNT(*) FROM booking_items bi JOIN bookings b ON b.id = bi.booking_id
                WHERE bi.product_id = ? AND bi.status IN ('reserved','installed') AND b.status IN ('confirmed','installed') AND bi.removal_at > ?", [$id, $this->app->clock->nowDb()]);
            if ($active > 0) {
                throw HttpException::conflict("لا يمكن أرشفة منتج عليه {$active} حجز نشط أو قادم");
            }
        }
        $this->repo->update($id, ['archived_at' => $archive ? $this->app->clock->nowDb() : null, 'updated_at' => $this->app->clock->nowDb()]);
        $this->app->service(ChangeLog::class)->record('product', $id);
        $this->app->service(Audit::class)->log($actor->id, $archive ? 'product.archived' : 'product.restored', 'product', $id, [], $req);
        return $this->get($id);
    }

    /** حذف نهائي مسموح فقط لمنتج لم يرتبط بأي حجز أو حركة غير افتتاحية؛ وإلا يجب الأرشفة */
    public function delete(string $id, CurrentUser $actor, ?Request $req = null): void
    {
        $this->repo->find($id) ?? throw HttpException::notFound('المنتج غير موجود');
        if ($this->repo->value('SELECT 1 FROM booking_items WHERE product_id = ? LIMIT 1', [$id])) {
            throw HttpException::conflict('لا يمكن حذف منتج مرتبط بحجوزات سابقة. يمكنك أرشفته بدلاً من ذلك', [], 'has_bookings');
        }
        throw HttpException::conflict('الحذف النهائي غير متاح للحفاظ على سجل المخزون. استخدم الأرشفة', [], 'use_archive');
    }

    public function addMovement(string $productId, array $input, CurrentUser $actor, ?Request $req = null): array
    {
        $d = $this->app->validator->validate($input, [
            'movement_type' => 'required|in:' . implode(',', array_diff(array_keys(self::MOVEMENT_SIGNS), ['initial'])),
            'quantity' => 'required|int|min:1|max:100000',
            'reason' => 'required|string|max:255',
        ], ['movement_type' => 'نوع الحركة', 'quantity' => 'الكمية', 'reason' => 'السبب']);
        $movementId = Database::transaction($this->app->db(), function () use ($productId, $d, $actor) {
            $p = $this->repo->find($productId, true) ?? throw HttpException::notFound('المنتج غير موجود');
            $delta = self::MOVEMENT_SIGNS[$d['movement_type']] * $d['quantity'];
            $newTotal = (int) $p['quantity_total'] + $delta;
            if ($newTotal < 0) {
                throw HttpException::validation(['quantity' => 'الكمية المطلوب خصمها أكبر من الرصيد الحالي (' . $p['quantity_total'] . ')']);
            }
            if ($delta < 0) {
                $peak = $this->app->service(AvailabilityService::class)->peakReserved($productId, $this->app->clock->nowDb(), null);
                if ($newTotal < $peak) {
                    throw HttpException::conflict("لا يمكن خفض الرصيد إلى {$newTotal} لأن هناك {$peak} قطعة محجوزة في فترة قادمة. عدّل الحجوزات أولاً", ['peak_reserved' => $peak], 'reserved_stock');
                }
            }
            $mid = $this->insertMovement($productId, $d['movement_type'], $delta, $d['reason'], $actor->id);
            $this->app->service(ChangeLog::class)->record('product', $productId);
            return $mid;
        });
        $this->app->service(Audit::class)->log($actor->id, 'inventory.movement', 'product', $productId, $d + ['movement_id' => $movementId], $req);
        return $this->get($productId);
    }

    /** يجب استدعاؤها داخل Transaction */
    public function insertMovement(string $productId, string $type, int $delta, ?string $reason, ?string $userId, ?string $refType = null, ?string $refId = null): string
    {
        $db = $this->app->db();
        $db->prepare('UPDATE products SET quantity_total = quantity_total + ?, version = version + 1, updated_at = ? WHERE id = ?')
            ->execute([$delta, $this->app->clock->nowDb(), $productId]);
        $balance = (int) $this->repo->value('SELECT quantity_total FROM products WHERE id = ?', [$productId]);
        $id = Uuid::v4();
        $db->prepare('INSERT INTO inventory_movements (id, product_id, movement_type, quantity, balance_after, reason, reference_type, reference_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $productId, $type, $delta, $balance, $reason, $refType, $refId, $userId, $this->app->clock->nowDb()]);
        return $id;
    }

    public function addImage(string $productId, array $file, CurrentUser $actor, ?string $imageId = null, ?Request $req = null): array
    {
        $p = $this->repo->find($productId) ?? throw HttpException::notFound('المنتج غير موجود');
        if ($imageId !== null) {
            if (!Uuid::isValid($imageId)) {
                throw HttpException::validation(['id' => 'معرف الصورة غير صالح']);
            }
            $imageId = strtolower($imageId);
            $existing = $this->repo->one('SELECT product_id FROM product_images WHERE id = ?', [$imageId]);
            if ($existing) {
                if ($existing['product_id'] !== $productId) {
                    throw HttpException::conflict('معرف الصورة مستخدم لمنتج آخر');
                }
                return $this->get($productId);
            }
        }
        $stored = $this->app->service(UploadService::class)->storeImage($file, 'products', $imageId);
        $id = $imageId ?? basename($stored['path'], '.' . pathinfo($stored['path'], PATHINFO_EXTENSION));
        $hasPrimary = (bool) $this->repo->value('SELECT 1 FROM product_images WHERE product_id = ? AND is_primary = 1 AND deleted_at IS NULL', [$productId]);
        $this->repo->exec('INSERT INTO product_images (id, product_id, file_path, mime, size_bytes, width, height, sort_order, is_primary, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, $productId, $stored['path'], $stored['mime'], $stored['size'], $stored['width'], $stored['height'],
            (int) $this->repo->value('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$productId]), $hasPrimary ? 0 : 1, $actor->id, $this->app->clock->nowDb(),
        ]);
        $this->repo->update($productId, ['updated_at' => $this->app->clock->nowDb()]);
        $this->app->service(ChangeLog::class)->record('product', $productId);
        $this->app->service(Audit::class)->log($actor->id, 'product.image_added', 'product', $productId, ['image_id' => $id, 'code' => $p['code']], $req);
        return $this->get($productId);
    }

    public function removeImage(string $productId, string $imageId, CurrentUser $actor, ?Request $req = null): array
    {
        $img = $this->repo->one('SELECT * FROM product_images WHERE id = ? AND product_id = ? AND deleted_at IS NULL', [$imageId, $productId]) ?? throw HttpException::notFound('الصورة غير موجودة');
        Database::transaction($this->app->db(), function () use ($img, $productId) {
            $this->repo->exec('UPDATE product_images SET deleted_at = ?, is_primary = 0 WHERE id = ?', [$this->app->clock->nowDb(), $img['id']]);
            if ((int) $img['is_primary'] === 1) {
                $this->repo->exec('UPDATE product_images SET is_primary = 1 WHERE product_id = ? AND deleted_at IS NULL ORDER BY sort_order, created_at LIMIT 1', [$productId]);
            }
            $this->repo->update($productId, ['updated_at' => $this->app->clock->nowDb()]);
            $this->app->service(ChangeLog::class)->record('product', $productId);
        });
        $this->app->service(Audit::class)->log($actor->id, 'product.image_removed', 'product', $productId, ['image_id' => $imageId], $req);
        return $this->get($productId);
    }

    public function setPrimaryImage(string $productId, string $imageId, CurrentUser $actor): array
    {
        $this->repo->one('SELECT id FROM product_images WHERE id = ? AND product_id = ? AND deleted_at IS NULL', [$imageId, $productId]) ?? throw HttpException::notFound('الصورة غير موجودة');
        Database::transaction($this->app->db(), function () use ($productId, $imageId) {
            $this->repo->exec('UPDATE product_images SET is_primary = (id = ?) WHERE product_id = ?', [$imageId, $productId]);
            $this->repo->update($productId, ['updated_at' => $this->app->clock->nowDb()]);
            $this->app->service(ChangeLog::class)->record('product', $productId);
        });
        return $this->get($productId);
    }

    private function validate(array $input, bool $partial, ?string $id = null): array
    {
        $d = $this->app->validator->validate($input, [
            'code' => 'required|string|max:50',
            'name' => 'required|string|max:150',
            'category_id' => 'required|uuid',
            'category_type_id' => 'nullable|uuid',
            'owner_center_id' => 'nullable|uuid',
            'description' => 'nullable|string|max:5000',
            'price' => 'nullable|decimal|min:0',
            'initial_quantity' => 'nullable|int|min:0|max:100000',
            'status' => 'nullable|in:active,inactive',
            'notes' => 'nullable|string|max:5000',
        ], self::LABELS, $partial);
        $cat = $this->repo->one('SELECT id, archived_at FROM categories WHERE id = ?', [$d['category_id']]);
        if (!$cat || ($cat['archived_at'] !== null && !$partial)) {
            throw HttpException::validation(['category_id' => 'التصنيف غير موجود']);
        }
        if (!empty($d['category_type_id'])) {
            $type = $this->repo->one('SELECT category_id FROM category_types WHERE id = ?', [$d['category_type_id']]);
            if (!$type || $type['category_id'] !== $d['category_id']) {
                throw HttpException::validation(['category_type_id' => 'النوع الفرعي لا يتبع التصنيف المختار']);
            }
        }
        if (!empty($d['owner_center_id']) && !$this->repo->value('SELECT 1 FROM centers WHERE id = ?', [$d['owner_center_id']])) {
            throw HttpException::validation(['owner_center_id' => 'المركز غير موجود']);
        }
        if ($this->repo->value('SELECT id FROM products WHERE code = ? AND id <> ?', [$d['code'], $id ?? (string) ($input['id'] ?? '')])) {
            throw HttpException::validation(['code' => 'رقم القطعة مستخدم لمنتج آخر']);
        }
        return $d;
    }

    public static function out(array $r): array
    {
        return [
            'id' => $r['id'], 'code' => $r['code'], 'name' => $r['name'],
            'category_id' => $r['category_id'], 'category_name' => $r['category_name'] ?? null,
            'category_type_id' => $r['category_type_id'], 'type_name' => $r['type_name'] ?? null,
            'owner_center_id' => $r['owner_center_id'], 'description' => $r['description'],
            'price' => $r['price'], 'quantity_total' => (int) $r['quantity_total'], 'status' => $r['status'], 'notes' => $r['notes'],
            'archived' => $r['archived_at'] !== null, 'version' => (int) $r['version'],
            'primary_image' => isset($r['primary_image']) && $r['primary_image'] ? 'media/' . $r['primary_image'] : null,
            'created_at' => Clock::toIso($r['created_at']), 'updated_at' => Clock::toIso($r['updated_at']),
        ];
    }
}
