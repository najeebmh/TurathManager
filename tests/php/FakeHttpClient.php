<?php

declare(strict_types=1);

namespace TurathManager\Tests;

use TurathManager\Support\HttpClient;

/**
 * عميل HTTP وهمي لمحاكاة ردود Meta و FCM في الاختبارات.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method:string,url:string,headers:array,body:?string}> */
    public array $requests = [];
    /** @var list<array{status:int,body:string,error:?string}|callable> */
    public array $queue = [];

    public function push(int $status, array|string $body, ?string $error = null): void
    {
        $this->queue[] = ['status' => $status, 'body' => is_array($body) ? json_encode($body) : $body, 'error' => $error];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $next = array_shift($this->queue);
        if ($next === null) {
            return ['status' => 0, 'body' => '', 'error' => 'no fake response queued'];
        }
        return is_callable($next) ? $next($method, $url, $body) : $next;
    }
}
