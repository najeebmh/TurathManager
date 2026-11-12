<?php

declare(strict_types=1);

namespace TurathManager\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * مصدر الوقت. يخزن النظام التواريخ بتوقيت UTC ويعرضها بتوقيت Asia/Aden.
 * يمكن تثبيت الوقت في الاختبارات.
 */
final class Clock
{
    private ?DateTimeImmutable $fixed = null;

    public function __construct(private readonly string $localTz = 'Asia/Aden')
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->fixed ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function nowDb(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    public function freeze(?DateTimeImmutable $at): void
    {
        $this->fixed = $at?->setTimezone(new DateTimeZone('UTC'));
    }

    public function localTz(): DateTimeZone
    {
        return new DateTimeZone($this->localTz);
    }

    /** تحويل نص تاريخ (ISO مع منطقة زمنية، أو محلي بتوقيت المحل) إلى صيغة قاعدة البيانات UTC */
    public function toDb(string $value): string
    {
        $dt = new DateTimeImmutable($value, $this->localTz());
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** تحويل قيمة قاعدة البيانات UTC إلى ISO 8601 */
    public static function toIso(?string $db): ?string
    {
        if ($db === null || $db === '') {
            return null;
        }
        return (new DateTimeImmutable($db, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
