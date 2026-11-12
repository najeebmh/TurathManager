<?php

declare(strict_types=1);

namespace TurathManager\Core;

/**
 * استثناء HTTP برسالة عربية آمنة للعرض.
 */
class HttpException extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $errorCode = 'error',
        public readonly array $details = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function notFound(string $message = 'العنصر غير موجود'): self
    {
        return new self(404, $message, 'not_found');
    }

    public static function forbidden(string $message = 'ليست لديك صلاحية لتنفيذ هذه العملية'): self
    {
        return new self(403, $message, 'forbidden');
    }

    public static function unauthorized(string $message = 'يجب تسجيل الدخول'): self
    {
        return new self(401, $message, 'unauthorized');
    }

    /** @param array<string,mixed> $details */
    public static function conflict(string $message, array $details = [], string $code = 'conflict'): self
    {
        return new self(409, $message, $code, $details);
    }

    /** @param array<string,string> $errors */
    public static function validation(array $errors, string $message = 'البيانات المدخلة غير صحيحة'): self
    {
        return new self(422, $message, 'validation_error', ['errors' => $errors]);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, $message, 'bad_request');
    }
}
