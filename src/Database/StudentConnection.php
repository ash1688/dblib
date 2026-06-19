<?php

declare(strict_types=1);

namespace Dblib\Database;

use Dblib\Support\Config;
use PDO;

/**
 * Per-request connection AS a student's own scoped MySQL user, pinned to their
 * own database. This is the connection the execution pipeline runs against, so
 * MySQL grants — not app logic — enforce sandbox isolation. Connect / run /
 * disconnect per request; no pooling (see design spec).
 */
final class StudentConnection
{
    public static function open(string $dbUser, string $plaintextPassword, string $dbName): PDO
    {
        $host    = Config::get('db.host', '127.0.0.1');
        $port    = (int) Config::get('db.port', 3306);
        $charset = Config::get('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";

        return new PDO(
            $dsn,
            $dbUser,
            $plaintextPassword,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        );
    }
}
