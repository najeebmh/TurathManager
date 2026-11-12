<?php

declare(strict_types=1);

namespace TurathManager\Support;

use TurathManager\Core\App;

final class CurlHttpClient implements HttpClient
{
    public function __construct(?App $app = null)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $resp === false ? curl_error($ch) : null;
        curl_close($ch);
        return ['status' => $status, 'body' => $resp === false ? '' : (string) $resp, 'error' => $error];
    }
}
