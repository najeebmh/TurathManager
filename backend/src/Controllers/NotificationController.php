<?php

declare(strict_types=1);

namespace TurathManager\Controllers;

use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Response;
use TurathManager\Services\NotificationService;
use TurathManager\Services\PushService;

final class NotificationController extends Controller
{
    public function index(Request $r): Response
    {
        [$limit, $offset] = $this->paging($r, 30, 100);
        return Response::json($this->app->service(NotificationService::class)->forUser($this->user($r), $r->query('unread') === '1', $limit, $offset));
    }

    public function read(Request $r): Response
    {
        $this->app->service(NotificationService::class)->markRead($this->user($r), $this->id($r));
        return Response::noContent();
    }

    public function readAll(Request $r): Response
    {
        $n = $this->app->service(NotificationService::class)->markRead($this->user($r), null);
        return Response::json(['updated' => $n]);
    }

    public function templates(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(NotificationService::class)->templates(), 'variables' => NotificationService::VARIABLES]);
    }

    public function updateTemplate(Request $r): Response
    {
        $id = (string) $r->param('id');
        if (!ctype_digit($id)) {
            throw HttpException::notFound();
        }
        return Response::json(['data' => $this->app->service(NotificationService::class)->updateTemplate((int) $id, $r->body, $this->user($r), $r)]);
    }

    public function subscriptions(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(NotificationService::class)->subscriptions()]);
    }

    public function setSubscriptions(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(NotificationService::class)->setSubscriptions($r->body, $this->user($r), $r)]);
    }

    public function logs(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(NotificationService::class)->logs()]);
    }

    public function registerPush(Request $r): Response
    {
        return Response::created(['data' => $this->app->service(PushService::class)->register($this->user($r), $r->body)]);
    }

    public function unregisterPush(Request $r): Response
    {
        $this->app->service(PushService::class)->unregister($this->user($r), (string) $r->param('deviceId'));
        return Response::noContent();
    }
}
