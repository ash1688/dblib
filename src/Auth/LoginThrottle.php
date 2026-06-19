<?php

declare(strict_types=1);

namespace Dblib\Auth;

use Dblib\Database\MetadataConnection;

/**
 * Basic login rate limiting: after too many failed attempts for one login
 * identifier within a rolling window, further attempts are refused until the
 * old ones age out. Keyed by identifier (not IP) so it protects an individual
 * account from guessing without locking out a whole shared-IP classroom; the
 * cooldown is temporary, so a forced lockout self-heals.
 */
final class LoginThrottle
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;

    public function isLockedOut(string $identifier): bool
    {
        if (trim($identifier) === '') {
            return false;
        }
        $pdo = MetadataConnection::get();

        // Prune anything older than the window so the table stays small.
        $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)');

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE identifier = ? AND attempted_at >= (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)'
        );
        $stmt->execute([$identifier]);
        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public function recordFailure(string $identifier): void
    {
        if (trim($identifier) === '') {
            return;
        }
        MetadataConnection::get()
            ->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')
            ->execute([$identifier]);
    }

    /** Clear the counter on a successful login. */
    public function clear(string $identifier): void
    {
        MetadataConnection::get()
            ->prepare('DELETE FROM login_attempts WHERE identifier = ?')
            ->execute([$identifier]);
    }

    public function windowMinutes(): int
    {
        return self::WINDOW_MINUTES;
    }
}
