<?php

declare(strict_types=1);

namespace Dblib\Controllers;

use Dblib\Accounts\AccountService;
use Dblib\Accounts\AccountValidationException;
use Dblib\Auth\Auth;
use Dblib\Http\Request;
use Dblib\Http\Response;

/**
 * Self-service account actions for any logged-in user (student or teacher).
 */
final class AccountController
{
    /** Change your own password: verify the current one, then set the new. */
    public function changePassword(Request $request): Response
    {
        $user = (new Auth())->user();
        if ($user === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $current = (string) $request->input('current', '');
        $new     = (string) $request->input('new', '');
        $confirm = (string) $request->input('confirm', '');

        if ($new !== $confirm) {
            $this->flash('error', 'New password and confirmation do not match.');
        } elseif (!password_verify($current, $user['password_hash'])) {
            $this->flash('error', 'Your current password is incorrect.');
        } else {
            try {
                (new AccountService())->setPassword((int) $user['id'], $new);
                $this->flash('ok', 'Password changed.');
            } catch (AccountValidationException $e) {
                $this->flash('error', $e->getMessage());
            }
        }

        return Response::redirect($request->basePath() . '/');
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}
