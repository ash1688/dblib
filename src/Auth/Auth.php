<?php

declare(strict_types=1);

namespace Dblib\Auth;

use Dblib\Database\MetadataConnection;

/**
 * Session-backed authentication over the app-owned users table.
 *
 * Kept deliberately thin so it could later defer to a shared suite-wide auth
 * service (see design spec) without rewriting callers.
 */
final class Auth
{
    public const ROLE_TEACHER = 'teacher';
    public const ROLE_STUDENT = 'student';

    /**
     * Authenticate by login identifier: a student_id (students) or an email
     * (teachers). One field serves both roles.
     *
     * @return array<string,mixed>|null
     */
    public function attempt(string $identifier, string $password): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $pdo = MetadataConnection::get();
        // Two placeholders, not one reused name: native prepares (emulation off)
        // reject the same named parameter appearing twice.
        $stmt = $pdo->prepare(
            'SELECT * FROM users WHERE student_id = :sid OR email = :email LIMIT 1'
        );
        $stmt->execute(['sid' => $identifier, 'email' => $identifier]);
        $user = $stmt->fetch();

        if ($user === false || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];

        return $user;
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if ($id === null) {
            return null;
        }
        $stmt = MetadataConnection::get()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user === false ? null : $user;
    }

    public function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
