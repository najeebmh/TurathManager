<?php

declare(strict_types=1);

namespace TurathManager\Core;

/**
 * قارئ بسيط لملف .env دون الاعتماد على مكتبات خارجية.
 * القيم الموجودة مسبقاً في بيئة النظام لها الأولوية.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }
        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
        $_ENV[$key] = $value;
    }
}
