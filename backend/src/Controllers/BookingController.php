<?php

declare(strict_types=1);

namespace TurathManager\Controllers;

use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Response;
use TurathManager\Services\BookingService;
use TurathManager\Services\CalendarService;
use TurathManager\Services\HandoverService;
use TurathManager\Services\PaymentService;

final class BookingController extends Controller
{
    public function index(Request $r): Response
    {
        [$limit, $offset] = $this->paging($r);
        return Response::json($this->app->service(BookingService::class)->list($r->query, $this->user($r), $limit, $offset));
    }

    public function show(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(BookingService::class)->get($this->id($r), $this->user($r))]);
    }

    public function store(Request $r): Response
    {
        return Response::created(['data' => $this->app->service(BookingService::class)->create($r->body, $this->user($r), $r)]);
    }

    public function update(Request $r): Response
    {
        $body = $r->body;
        $version = isset($body['version']) ? (int) $body['version'] : null;
        unset($body['version']);
        return Response::json(['data' => $this->app->service(BookingService::class)->update($this->id($r), $body, $this->user($r), $r, $version)]);
    }

    public function cancel(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(BookingService::class)->cancel($this->id($r), (string) ($r->body['reason'] ?? ''), $this->user($r), $r)]);
    }

    public function confirm(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(BookingService::class)->confirm($this->id($r), $this->user($r), $r)]);
    }

    public function archive(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(BookingService::class)->archive($this->id($r), (bool) ($r->body['archived'] ?? true), $this->user($r), $r)]);
    }

    public function conflicts(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(BookingService::class)->openConflicts($this->user($r))]);
    }

    public function itemImage(Request $r): Response
    {
        $file = $r->files['image'] ?? null;
        if (!is_array($file)) {
            throw HttpException::validation(['image' => 'يجب اختيار صورة']);
        }
        return Response::created(['data' => $this->app->service(BookingService::class)->addItemImage($this->id($r, 'itemId'), $file, $this->user($r), $r->header('idempotency-key'))]);
    }

    public function handover(Request $r): Response
    {
        $type = (string) $r->param('type');
        $map = ['install' => 'delivery', 'remove' => 'return', 'delivery' => 'delivery', 'return' => 'return'];
        if (!isset($map[$type])) {
            throw HttpException::notFound();
        }
        return Response::created(['data' => $this->app->service(HandoverService::class)->record($this->id($r, 'itemId'), $map[$type], $r->body, $this->user($r), $r)]);
    }

    public function handoverImage(Request $r): Response
    {
        $file = $r->files['image'] ?? null;
        if (!is_array($file)) {
            throw HttpException::validation(['image' => 'يجب اختيار صورة']);
        }
        return Response::created(['data' => $this->app->service(HandoverService::class)->addImage($this->id($r), $file, $this->user($r), $r->header('idempotency-key'))]);
    }

    public function calendar(Request $r): Response
    {
        $center = $r->query('center_id');
        return Response::json(['data' => $this->app->service(CalendarService::class)->events(
            (string) $r->query('from'),
            (string) $r->query('to'),
            $this->user($r),
            is_string($center) && $center !== '' ? $center : null,
            $r->query('include_cancelled', '1') === '1'
        )]);
    }

    public function dashboard(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(CalendarService::class)->dashboard($this->user($r))]);
    }

    public function payments(Request $r): Response
    {
        $s = $this->app->service(BookingService::class);
        $s->load($this->id($r), $this->user($r));
        return Response::json(['data' => $this->app->service(PaymentService::class)->forBooking($this->id($r))]);
    }

    public function addPayment(Request $r): Response
    {
        return Response::created(['data' => $this->app->service(PaymentService::class)->add($this->id($r), $r->body, $this->user($r), $r)]);
    }

    public function reversePayment(Request $r): Response
    {
        return Response::created(['data' => $this->app->service(PaymentService::class)->reverse($this->id($r), (string) ($r->body['reason'] ?? ''), $this->user($r), $r)]);
    }

    public function payment(Request $r): Response
    {
        $p = $this->app->service(PaymentService::class)->get($this->id($r));
        $this->app->service(BookingService::class)->load($p['booking_id'], $this->user($r));
        return Response::json(['data' => $p]);
    }
}
