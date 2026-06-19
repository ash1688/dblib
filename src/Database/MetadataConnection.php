<?php

declare(strict_types=1);

namespace Dblib\Database;

use Dblib\Support\Config;
use PDO;

/**
 * Connection to dblib's own metadata schema (accounts, classes, sandboxes,
 * seeds) AND the provisioning account used to CREATE DATABASE / CREATE USER.
 *
 * This is the privileged account — dedicated to dblib and locked down. It is
 * NEVER the connection a student's SQL runs against (that is StudentConnection).
 */
final class MetadataConnection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $host    = Config::get('db.host', '127.0.0.1');
        $port    = (int) Config::get('db.port', 3306);
        $schema  = Config::get('db.metadata_db', 'dblib_meta');
        $charset = Config::get('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$schema};charset={$charset}";

        self::$instance = new PDO(
            $dsn,
            (string) Config::get('db.admin_user', 'root'),
            (string) Config::get('db.admin_pass', ''),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        );

        return self::$instance;
    }

    /**
     * Server-level connection (no default schema) for provisioning DDL such as
     * CREATE DATABASE before the metadata schema exists.
     */
    public static function server(): PDO
    {
        $host    = Config::get('db.host', '127.0.0.1');
        $port    = (int) Config::get('db.port', 3306);
        $charset = Config::get('db.charset', 'utf8mb4');

        return new PDO(
            "mysql:host={$host};port={$port};charset={$charset}",
            (string) Config::get('db.admin_user', 'root'),
            (string) Config::get('db.admin_pass', ''),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }
}
