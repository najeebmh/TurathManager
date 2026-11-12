<?php

declare(strict_types=1);

// يطبع مفتاح APP_KEY عشوائياً لوضعه في ملف .env
if (PHP_SAPI !== 'cli') {
    exit(1);
}
echo 'APP_KEY=base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) . PHP_EOL;
