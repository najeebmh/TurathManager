<?php

declare(strict_types=1);

namespace TurathManager\Controllers;

use TurathManager\Auth\AuthService;
use TurathManager\Core\Request;
use TurathManager\Core\Response;

final class AuthController extends Controller
{
    public function login(Request $r): Response
    {
        $d = $this->app->validator->validate($r->body, [
            'username' => 'required|string|max:60',
            'password' => 'required|string|max:200',
            'device_id' => 'nullable|uuid',
            'device_name' => 'nullable|string|max:120',
        ], ['username' => 'اسم المستخدم', 'password' => 'كلمة المرور']);
        return Response::json($this->app->service(AuthService::class)->login($d['username'], (string) $r->input('password'), $r, $d['device_id'] ?? null, $d['device_name'] ?? null));
    }

    public function me(Request $r): Response
    {
        return Response::json(['user' => $this->app->service(AuthService::class)->profile($this->user($r))]);
    }

    public function logout(Request $r): Response
    {
        $this->app->service(AuthService::class)->logout($this->user($r), $r);
        return Response::noContent();
    }

    public function changePassword(Request $r): Response
    {
        $this->app->validator->validate($r->body, [
            'current_password' => 'required|string|max:200',
            'new_password' => 'required|string|max:200',
        ], ['current_password' => 'كلمة المرور الحالية', 'new_password' => 'كلمة المرور الجديدة']);
        $this->app->service(AuthService::class)->changePassword($this->user($r), (string) $r->input('current_password'), (string) $r->input('new_password'), $r);
        return Response::json(['message' => 'تم تغيير كلمة المرور']);
    }
}
