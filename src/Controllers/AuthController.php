<?php

declare(strict_types=1);

namespace Dblib\Controllers;

use Dblib\Auth\Auth;
use Dblib\Auth\LoginThrottle;
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
        $identifier = (string) $request->input('identifier', '');
        $throttle = new LoginThrottle();

        if ($throttle->isLockedOut($identifier)) {
            return Response::html(View::render('login', [
                'basePath' => $request->basePath(),
                'error'    => 'Too many failed attempts. Please wait a few minutes and try again.',
            ]), 429);
        }

        $user = (new Auth())->attempt($identifier, (string) $request->input('password', ''));

        if ($user === null) {
            $throttle->recordFailure($identifier);
            return Response::html(View::render('login', [
                'basePath' => $request->basePath(),
                'error'    => 'Invalid login or password.',
            ]), 401);
        }

        $throttle->clear($identifier);
        return Response::redirect($request->basePath() . '/');
    }

    public function logout(Request $request): Response
    {
        (new Auth())->logout();
        return Response::redirect($request->basePath() . '/login');
    }
}
