<?php

declare(strict_types=1);

namespace TurathManager\Core;

/**
 * نظام توجيه بسيط. كل مسار يحدد صلاحيته صراحة:
 * PUBLIC = دون تسجيل دخول، AUTH = أي مستخدم مسجل، أو اسم صلاحية محددة.
 */
final class Router
{
    public const PUBLIC = '@public';
    public const AUTH = '@auth';

    /** @var list<array{method:string,pattern:string,regex:string,handler:array{0:class-string,1:string},access:string}> */
    private array $routes = [];

    /** @param array{0:class-string,1:string} $handler */
    public function add(string $method, string $pattern, array $handler, string $access): void
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'regex' => $regex, 'handler' => $handler, 'access' => $access];
    }

    public function get(string $p, array $h, string $a): void
    {
        $this->add('GET', $p, $h, $a);
    }

    public function post(string $p, array $h, string $a): void
    {
        $this->add('POST', $p, $h, $a);
    }

    public function put(string $p, array $h, string $a): void
    {
        $this->add('PUT', $p, $h, $a);
    }

    public function delete(string $p, array $h, string $a): void
    {
        $this->add('DELETE', $p, $h, $a);
    }

    /** @return array{route:array,params:array<string,string>} */
    public function match(string $method, string $path): array
    {
        $allowed = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m)) {
                if ($route['method'] !== $method) {
                    $allowed = true;
                    continue;
                }
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                return ['route' => $route, 'params' => array_map('urldecode', $params)];
            }
        }
        if ($allowed) {
            throw new HttpException(405, 'طريقة الطلب غير مسموحة', 'method_not_allowed');
        }
        throw HttpException::notFound('المسار غير موجود');
    }

    /** @return list<array{method:string,pattern:string,access:string}> */
    public function all(): array
    {
        return array_map(static fn ($r) => ['method' => $r['method'], 'pattern' => $r['pattern'], 'access' => $r['access']], $this->routes);
    }
}
