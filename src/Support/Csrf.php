<?php

declare(strict_types=1);

namespace Dblib\Support;

/**
 * Per-session CSRF token. The same token is embedded in every state-changing
 * form (hidden `_token` field) and AJAX call (`X-CSRF-Token` header), and
 * verified centrally on every POST in the front controller. Session-based, so
 * it requires the user's own session cookie — which a cross-site forgery can't
 * read.
 */
final class Csrf
{
    private const KEY = 'csrf_token';

    /** Get-or-create the session token. */
    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    /** Verify the token sent with the current request (header or form field). */
    public static function verify(): bool
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_token'] ?? '');
        $real = $_SESSION[self::KEY] ?? '';
        return is_string($sent) && $sent !== '' && $real !== '' && hash_equals($real, $sent);
    }

    /** Hidden input for HTML forms. */
    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}
