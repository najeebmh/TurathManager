<?php

declare(strict_types=1);

namespace TurathManager\Core;

/**
 * تمثيل طلب HTTP مستقل عن المتغيرات العامة لتسهيل الاختبار.
 */
final class Request
{
    /** @var array<string,mixed> */
    public array $attributes = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $headers أسماء الرؤوس بحروف صغيرة
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly array $files = [],
        public readonly string $ip = '127.0.0.1',
        public readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $pos = strpos($uri, '/api/');
        $path = $pos !== false ? substr($uri, $pos + 4) : ($uri === '/api' ? '/' : $uri);
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $raw = (string) file_get_contents('php://input');
        $body = [];
        if (str_contains($headers['content-type'] ?? '', 'application/json')) {
            $decoded = $raw !== '' ? json_decode($raw, true) : [];
            if ($raw !== '' && !is_array($decoded)) {
                throw HttpException::badRequest('صيغة JSON غير صحيحة');
            }
            $body = $decoded ?? [];
        } else {
            $body = $_POST;
        }

        return new self($method, $path, $_GET, $body, $headers, $_FILES, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $raw);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('authorization') ?? '';
        if (preg_match('/^Bearer\s+([A-Za-z0-9._\-]+)$/', $h, $m)) {
            return $m[1];
        }
        return null;
    }

    public function param(string $key): ?string
    {
        return $this->attributes['params'][$key] ?? null;
    }
}
