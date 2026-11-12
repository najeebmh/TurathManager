<?php

declare(strict_types=1);

namespace TurathManager\Controllers;

use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Response;
use TurathManager\Services\CenterService;
use TurathManager\Services\CustomerService;

final class CenterController extends Controller
{
    public function index(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(CenterService::class)->list($r->query('search'), (string) ($r->query('status') ?? 'active'))]);
    }

    public function show(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(CenterService::class)->get($this->id($r))]);
    }

    public function store(Request $r): Response
    {
        return Response::created(['data' => $this->app->service(CenterService::class)->save($r->body['id'] ?? null, $r->body, $this->user($r), $r)]);
    }

    public function update(Request $r): Response
    {
        $s = $this->app->service(CenterService::class);
        $id = $this->id($r);
        $s->get($id);
        return Response::json(['data' => $s->save($id, $r->body, $this->user($r), $r, isset($r->body['version']) ? (int) $r->body['version'] : null)]);
    }

    public function archive(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(CenterService::class)->archive($this->id($r), (bool) ($r->body['archived'] ?? true), $this->user($r), $r)]);
    }

    public function logo(Request $r): Response
    {
        $file = $r->files['image'] ?? null;
        if (!is_array($file)) {
            throw HttpException::validation(['image' => 'يجب اختيار صورة']);
        }
        return Response::json(['data' => $this->app->service(CenterService::class)->setLogo($this->id($r), $file, $this->user($r), $r)]);
    }

    public function customers(Request $r): Response
    {
        [$limit, $offset] = $this->paging($r);
        return Response::json($this->app->service(CustomerService::class)->list($r->query('search'), (string) ($r->query('status') ?? 'active'), $limit, $offset));
    }

    public function customer(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(CustomerService::class)->get($this->id($r), true)]);
    }

    public function customerByPhone(Request $r): Response
    {
        $phone = \TurathManager\Core\Validator::normalizePhone((string) $r->query('phone'), $this->app->config['app']['default_country_code']);
        return Response::json(['data' => $phone ? $this->app->service(CustomerService::class)->findByPhone($phone) : null]);
    }

    public function storeCustomer(Request $r): Response
    {
        return Response::created(['data' => $this->app->service(CustomerService::class)->save($r->body['id'] ?? null, $r->body, $this->user($r), $r)]);
    }

    public function updateCustomer(Request $r): Response
    {
        $s = $this->app->service(CustomerService::class);
        $id = $this->id($r);
        $s->get($id);
        return Response::json(['data' => $s->save($id, $r->body, $this->user($r), $r, isset($r->body['version']) ? (int) $r->body['version'] : null)]);
    }

    public function archiveCustomer(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(CustomerService::class)->archive($this->id($r), (bool) ($r->body['archived'] ?? true), $this->user($r), $r)]);
    }
}
