<?php

declare(strict_types=1);

namespace TurathManager\Core;

/**
 * استجابة HTTP: JSON افتراضياً، أو محتوى خام (CSV/ملفات).
 */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public mixed $data = null,
        public array $headers = [],
        public ?string $raw = null,
        public ?string $filePath = null,
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, $data);
    }

    public static function created(mixed $data): self
    {
        return new self(201, $data);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public static function csv(string $filename, array $header, iterable $rows): self
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header, ',', '"', '\\');
        foreach ($rows as $row) {
            $row = array_map(static function ($v) {
                $s = (string) $v;
                return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
            }, array_values((array) $row));
            fputcsv($fh, $row, ',', '"', '\\');
        }
        rewind($fh);
        $content = (string) stream_get_contents($fh);
        fclose($fh);
        return new self(200, null, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], $content);
    }

    public static function file(string $path, string $mime): self
    {
        return new self(200, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ], null, $path);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        if ($this->filePath !== null) {
            header('Content-Length: ' . filesize($this->filePath));
            readfile($this->filePath);
            return;
        }
        if ($this->raw !== null) {
            echo $this->raw;
            return;
        }
        if ($this->status === 204) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
