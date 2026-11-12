<?php

declare(strict_types=1);

namespace TurathManager\Controllers;

use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Response;
use TurathManager\Services\SyncService;

final class SyncController extends Controller
{
    public function push(Request $r): Response
    {
        $device = (string) ($r->body['device_id'] ?? $r->header('x-device-id') ?? '');
        $ops = $r->body['operations'] ?? null;
        if (!is_array($ops)) {
            throw HttpException::validation(['operations' => 'قائمة العمليات مطلوبة']);
        }
        $info = array_intersect_key((array) ($r->body['device'] ?? []), array_flip(['device_name', 'platform', 'app_version']));
        return Response::json(['results' => $this->app->service(SyncService::class)->push($this->user($r), $device, array_values($ops), $r, $info)]);
    }

    public function pull(Request $r): Response
    {
        $device = (string) ($r->query('device_id') ?? $r->header('x-device-id') ?? '');
        return Response::json($this->app->service(SyncService::class)->pull($this->user($r), $device, max(0, (int) $r->query('since', 0)), (int) $r->query('limit', 300)));
    }

    public function status(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(SyncService::class)->status($this->user($r))]);
    }
}
