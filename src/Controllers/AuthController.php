<?php

declare(strict_types=1);

namespace Dblib\Controllers;

use Dblib\Auth\Auth;
use Dblib\Http\Request;
use Dblib\Http\Response;
use Dblib\Support\View;

final class AuthController
{
    public function showLogin(Request $request): Response
    {
        return Response::html(View::render('login', [
            'basePath' => $request->basePath(),
            'error'    => null,
        ]));
    }

    public function login(Request $request): Response
    {
        $auth = new Auth();
        $user = $auth->attempt(
            (string) $request->input('identifier', ''),
            (string) $request->input('password', ''),
        );

        if ($user === null) {
            return Response::html(View::render('login', [
                'basePath' => $request->basePath(),
                'error'    => 'Invalid email or password.',
            ]), 401);
        }

        return Response::redirect($request->basePath() . '/');
    }

    public function logout(Request $request): Response
    {
        (new Auth())->logout();
        return Response::redirect($request->basePath() . '/login');
    }
}
