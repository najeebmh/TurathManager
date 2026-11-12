<?php

declare(strict_types=1);

namespace TurathManager\Support;

use TurathManager\Core\App;
use TurathManager\Core\HttpException;
use TurathManager\Core\Uuid;

/**
 * حماية رفع الصور: التحقق من الحجم والنوع الحقيقي (finfo) وأبعاد الصورة،
 * وتوليد اسم عشوائي، وإعادة ترميز الصورة عند توفر GD لإزالة أي محتوى مضمّن.
 */
final class UploadService
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string,mixed> $file عنصر من $_FILES
     * @return array{path:string,mime:string,size:int,width:int,height:int}
     */
    public function storeImage(array $file, string $folder, ?string $fileId = null): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw HttpException::badRequest('ملف غير صالح');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw HttpException::validation(['file' => 'فشل رفع الملف (رمز ' . (int) $file['error'] . ')']);
        }
        $tmp = (string) $file['tmp_name'];
        $isTest = $this->app->config['app']['env'] === 'testing';
        if (!$isTest && !is_uploaded_file($tmp)) {
            throw HttpException::badRequest('ملف غير صالح');
        }
        $size = (int) filesize($tmp);
        $max = (int) $this->app->config['uploads']['max_bytes'];
        if ($size <= 0 || $size > $max) {
            throw HttpException::validation(['file' => 'حجم الصورة يجب ألا يتجاوز ' . round($max / 1048576, 1) . ' ميجابايت']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        $allowed = $this->app->config['uploads']['allowed_mime'];
        if (!isset($allowed[$mime])) {
            throw HttpException::validation(['file' => 'نوع الملف غير مسموح. المسموح: JPG, PNG, WEBP']);
        }
        $info = @getimagesize($tmp);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] > 10000 || $info[1] > 10000) {
            throw HttpException::validation(['file' => 'الملف ليس صورة صالحة']);
        }
        if (!preg_match('/^[a-z_]+$/', $folder)) {
            throw new \InvalidArgumentException('folder');
        }

        $id = $fileId ?? Uuid::v4();
        $rel = $folder . '/' . gmdate('Y/m') . '/' . $id . '.' . $allowed[$mime];
        $dest = $this->app->config['paths']['uploads'] . '/' . $rel;
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        if (!$this->reencode($tmp, $dest, $mime)) {
            if ($isTest ? !copy($tmp, $dest) : !move_uploaded_file($tmp, $dest)) {
                throw new \RuntimeException('تعذر حفظ الملف');
            }
        }
        return ['path' => $rel, 'mime' => $mime, 'size' => (int) filesize($dest), 'width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    private function reencode(string $src, string $dest, string $mime): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default => false,
        };
        if ($img === false) {
            return false;
        }
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($img, $dest, 85),
            'image/png' => (static function () use ($img, $dest) {
                imagesavealpha($img, true);
                return imagepng($img, $dest, 6);
            })(),
            'image/webp' => imagewebp($img, $dest, 85),
        };
        imagedestroy($img);
        return $ok;
    }

    /** المسار المطلق لملف داخل مجلد الرفع مع منع Path Traversal */
    public function absolute(string $rel): ?string
    {
        if (!preg_match('#^[a-z_]+/\d{4}/\d{2}/[0-9a-f\-]{36}\.(jpg|png|webp)$#', $rel)) {
            return null;
        }
        $path = $this->app->config['paths']['uploads'] . '/' . $rel;
        return is_file($path) ? $path : null;
    }
}
