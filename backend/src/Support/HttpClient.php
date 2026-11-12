<?php

declare(strict_types=1);

namespace TurathManager\Support;

/**
 * عميل HTTP قابل للاستبدال في الاختبارات.
 */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,error:?string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array;
}
