<?php

declare(strict_types=1);

namespace TurathManager\Controllers;

use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Response;
use TurathManager\Services\RoleService;
use TurathManager\Services\UserService;

final class UserController extends Controller
{
    public function index(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(UserService::class)->list($r->query('search'), $r->query('status'))]);
    }

    public function show(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(UserService::class)->get($this->id($r))]);
    }

    public function store(Request $r): Response
    {
        return Response::created(['data' => $this->app->service(UserService::class)->create($r->body, $this->user($r), $r)]);
    }

    public function update(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(UserService::class)->update($this->id($r), $r->body, $this->user($r), $r)]);
    }

    public function activate(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(UserService::class)->setActive($this->id($r), true, $this->user($r), $r)]);
    }

    public function deactivate(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(UserService::class)->setActive($this->id($r), false, $this->user($r), $r)]);
    }

    public function resetPassword(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(UserService::class)->resetPassword($this->id($r), $this->user($r), $r)]);
    }

    public function roles(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(RoleService::class)->list()]);
    }

    public function permissions(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(RoleService::class)->permissions()]);
    }

    public function storeRole(Request $r): Response
    {
        return Response::created(['data' => $this->app->service(RoleService::class)->create($r->body, $this->user($r), $r)]);
    }

    public function updateRole(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(RoleService::class)->update($this->roleId($r), $r->body, $this->user($r), $r)]);
    }

    public function deleteRole(Request $r): Response
    {
        $this->app->service(RoleService::class)->delete($this->roleId($r), $this->user($r), $r);
        return Response::noContent();
    }

    private function roleId(Request $r): int
    {
        $id = (string) $r->param('id');
        if (!ctype_digit($id)) {
            throw HttpException::notFound();
        }
        return (int) $id;
    }
}
