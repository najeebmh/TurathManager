<?php

declare(strict_types=1);

namespace TurathManager\Services;

use TurathManager\Auth\CurrentUser;
use TurathManager\Core\App;
use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Uuid;
use TurathManager\Repositories\CategoryRepository;
use TurathManager\Repositories\CategoryTypeRepository;
use TurathManager\Support\Audit;
use TurathManager\Support\ChangeLog;

/**
 * التصنيفات الرئيسية (مثل سجاف، كوش) وأنواعها الفرعية القابلة للتعديل.
 */
final class CategoryService
{
    private CategoryRepository $cats;
    private CategoryTypeRepository $types;

    public function __construct(private readonly App $app)
    {
        $this->cats = new CategoryRepository($app->db());
        $this->types = new CategoryTypeRepository($app->db());
    }

    public function list(bool $withArchived = false): array
    {
        $cats = $this->cats->all('SELECT * FROM categories' . ($withArchived ? '' : ' WHERE archived_at IS NULL') . ' ORDER BY sort_order, name');
        $types = $this->types->all('SELECT * FROM category_types' . ($withArchived ? '' : ' WHERE archived_at IS NULL') . ' ORDER BY sort_order, name');
        $byCat = [];
        foreach ($types as $t) {
            $byCat[$t['category_id']][] = self::typeOut($t);
        }
        return array_map(static fn ($c) => self::catOut($c) + ['types' => $byCat[$c['id']] ?? []], $cats);
    }

    public function getCategory(string $id): array
    {
        $c = $this->cats->find($id) ?? throw HttpException::notFound('التصنيف غير موجود');
        return self::catOut($c);
    }

    public function getType(string $id): array
    {
        $t = $this->types->find($id) ?? throw HttpException::notFound('النوع غير موجود');
        return self::typeOut($t);
    }

    public function saveCategory(?string $id, array $input, CurrentUser $actor, ?Request $req = null, ?int $baseVersion = null): array
    {
        $d = $this->app->validator->validate($input, [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'sort_order' => 'nullable|int',
        ], ['name' => 'اسم التصنيف', 'sort_order' => 'الترتيب']);
        $now = $this->app->clock->nowDb();
        $existing = $id ? $this->cats->find($id) : null;
        $dup = $this->cats->value('SELECT id FROM categories WHERE name = ? AND id <> ?', [$d['name'], $id ?? '']);
        if ($dup) {
            throw HttpException::validation(['name' => 'يوجد تصنيف بنفس الاسم']);
        }
        if ($existing) {
            if (!$this->cats->update($id, ['name' => $d['name'], 'description' => $d['description'] ?? null, 'sort_order' => $d['sort_order'] ?? 0, 'updated_at' => $now], $baseVersion)) {
                throw HttpException::conflict('تم تعديل التصنيف من جهاز آخر', ['server' => self::catOut($this->cats->find($id))], 'version_conflict');
            }
            $action = 'category.updated';
        } else {
            $id = $id && Uuid::isValid($id) ? strtolower($id) : Uuid::v4();
            $this->cats->insert(['id' => $id, 'name' => $d['name'], 'description' => $d['description'] ?? null, 'sort_order' => $d['sort_order'] ?? 0, 'created_at' => $now, 'updated_at' => $now]);
            $action = 'category.created';
        }
        $this->app->service(ChangeLog::class)->record('category', $id);
        $this->app->service(Audit::class)->log($actor->id, $action, 'category', $id, ['name' => $d['name']], $req);
        return $this->getCategory($id);
    }

    public function archiveCategory(string $id, bool $archive, CurrentUser $actor, ?Request $req = null): array
    {
        $this->getCategory($id);
        $this->cats->update($id, ['archived_at' => $archive ? $this->app->clock->nowDb() : null, 'updated_at' => $this->app->clock->nowDb()]);
        $this->app->service(ChangeLog::class)->record('category', $id);
        $this->app->service(Audit::class)->log($actor->id, $archive ? 'category.archived' : 'category.restored', 'category', $id, [], $req);
        return $this->getCategory($id);
    }

    public function saveType(?string $id, array $input, CurrentUser $actor, ?Request $req = null, ?int $baseVersion = null): array
    {
        $d = $this->app->validator->validate($input, [
            'category_id' => 'required|uuid',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'sort_order' => 'nullable|int',
        ], ['category_id' => 'التصنيف', 'name' => 'اسم النوع']);
        $cat = $this->cats->find($d['category_id']);
        if (!$cat || $cat['archived_at'] !== null) {
            throw HttpException::validation(['category_id' => 'التصنيف غير موجود']);
        }
        $dup = $this->types->value('SELECT id FROM category_types WHERE category_id = ? AND name = ? AND id <> ?', [$d['category_id'], $d['name'], $id ?? '']);
        if ($dup) {
            throw HttpException::validation(['name' => 'يوجد نوع بنفس الاسم في هذا التصنيف']);
        }
        $now = $this->app->clock->nowDb();
        $existing = $id ? $this->types->find($id) : null;
        $data = ['category_id' => $d['category_id'], 'name' => $d['name'], 'description' => $d['description'] ?? null, 'sort_order' => $d['sort_order'] ?? 0, 'updated_at' => $now];
        if ($existing) {
            if ($existing['category_id'] !== $d['category_id'] && $this->types->value('SELECT 1 FROM products WHERE category_type_id = ? LIMIT 1', [$id])) {
                throw HttpException::conflict('لا يمكن نقل نوع مرتبط بمنتجات إلى تصنيف آخر');
            }
            if (!$this->types->update($id, $data, $baseVersion)) {
                throw HttpException::conflict('تم تعديل النوع من جهاز آخر', ['server' => self::typeOut($this->types->find($id))], 'version_conflict');
            }
            $action = 'category_type.updated';
        } else {
            $id = $id && Uuid::isValid($id) ? strtolower($id) : Uuid::v4();
            $this->types->insert($data + ['id' => $id, 'created_at' => $now]);
            $action = 'category_type.created';
        }
        $this->app->service(ChangeLog::class)->record('category_type', $id);
        $this->app->service(Audit::class)->log($actor->id, $action, 'category_type', $id, ['name' => $d['name']], $req);
        return $this->getType($id);
    }

    public function archiveType(string $id, bool $archive, CurrentUser $actor, ?Request $req = null): array
    {
        $this->getType($id);
        $this->types->update($id, ['archived_at' => $archive ? $this->app->clock->nowDb() : null, 'updated_at' => $this->app->clock->nowDb()]);
        $this->app->service(ChangeLog::class)->record('category_type', $id);
        $this->app->service(Audit::class)->log($actor->id, $archive ? 'category_type.archived' : 'category_type.restored', 'category_type', $id, [], $req);
        return $this->getType($id);
    }

    public static function catOut(array $c): array
    {
        return [
            'id' => $c['id'], 'name' => $c['name'], 'description' => $c['description'], 'sort_order' => (int) $c['sort_order'],
            'archived' => $c['archived_at'] !== null, 'version' => (int) $c['version'], 'updated_at' => \TurathManager\Core\Clock::toIso($c['updated_at']),
        ];
    }

    public static function typeOut(array $t): array
    {
        return [
            'id' => $t['id'], 'category_id' => $t['category_id'], 'name' => $t['name'], 'description' => $t['description'],
            'sort_order' => (int) $t['sort_order'], 'archived' => $t['archived_at'] !== null, 'version' => (int) $t['version'],
            'updated_at' => \TurathManager\Core\Clock::toIso($t['updated_at']),
        ];
    }
}
