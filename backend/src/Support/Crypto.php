<?php

declare(strict_types=1);

namespace TurathManager\Support;

/**
 * تشفير الأسرار المخزنة في قاعدة البيانات (مثل رموز واتساب) باستخدام libsodium
 * ومفتاح APP_KEY من ملف .env.
 */
final class Crypto
{
    private string $key;

    public function __construct(string $appKey)
    {
        $raw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : false;
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('APP_KEY غير مضبوط أو غير صالح. شغّل scripts/generate-key.php');
        }
        $this->key = $raw;
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }
        $bin = base64_decode($cipher, true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );
        return $plain === false ? null : $plain;
    }
}
