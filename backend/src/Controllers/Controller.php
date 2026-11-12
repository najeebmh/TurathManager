<?php

declare(strict_types=1);

namespace TurathManager\Controllers;

use TurathManager\Auth\CurrentUser;
use TurathManager\Core\App;
use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Uuid;

/**
 * المتحكم الأساسي: يربط الطلب بالخدمات دون منطق أعمال.
 */
abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function user(Request $r): CurrentUser
    {
        return App::user($r);
    }

    protected function id(Request $r, string $key = 'id'): string
    {
        $id = (string) $r->param($key);
        if (!Uuid::isValid($id)) {
            throw HttpException::notFound();
        }
        return strtolower($id);
    }

    /** @return array{0:int,1:int} [limit, offset] */
    protected function paging(Request $r, int $default = 50, int $max = 200): array
    {
        $limit = max(1, min($max, (int) ($r->query('limit') ?? $default)));
        $page = max(1, (int) ($r->query('page') ?? 1));
        return [$limit, ($page - 1) * $limit];
    }
}
