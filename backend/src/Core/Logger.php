<?php

declare(strict_types=1);

namespace TurathManager\Core;

/**
 * مسجل بسيط يكتب ملفات يومية ويحجب القيم الحساسة.
 */
final class Logger
{
    private const SENSITIVE = ['password', 'pass', 'token', 'secret', 'authorization', 'access_token', 'app_secret', 'verify_token', 'current_password', 'new_password'];

    public function __construct(private readonly string $dir)
    {
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($level),
            self::redactString($message),
            $context ? json_encode(self::redact($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($this->dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public function info(string $m, array $c = []): void
    {
        $this->log('info', $m, $c);
    }

    public function warning(string $m, array $c = []): void
    {
        $this->log('warning', $m, $c);
    }

    public function error(string $m, array $c = []): void
    {
        $this->log('error', $m, $c);
    }

    /** @param array<mixed> $data */
    public static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::SENSITIVE, true)) {
                $data[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            } elseif (is_string($v)) {
                $data[$k] = self::redactString($v);
            }
        }
        return $data;
    }

    public static function redactString(string $s): string
    {
        $s = preg_replace('/(password|pwd)=([^;\s]*)/i', '$1=[REDACTED]', $s) ?? $s;
        return preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/', 'Bearer [REDACTED]', $s) ?? $s;
    }
}
