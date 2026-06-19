<?php

declare(strict_types=1);

namespace Dblib\Controllers;

use Dblib\Auth\Auth;
use Dblib\Http\Request;
use Dblib\Http\Response;
use Dblib\Support\View;

final class DashboardController
{
    public function index(Request $request): Response
    {
        $auth = new Auth();
        $user = $auth->user();

        if ($user === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        // Teachers land on their workspace; students on their sandbox dashboard.
        if ($user['role'] === Auth::ROLE_TEACHER) {
            return Response::redirect($request->basePath() . '/teacher');
        }

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return Response::html(View::render('dashboard_student', [
            'basePath' => $request->basePath(),
            'user'     => $user,
            'flash'    => is_array($flash) ? $flash : null,
        ]));
    }
}
