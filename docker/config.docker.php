<?php
/**
 * Docker config — env-driven so docker-compose owns the credentials. The
 * entrypoint copies this to config/config.php on first boot (if none is
 * mounted). Every value falls back to a sane compose default.
 */

declare(strict_types=1);

return [
    'app' => [
        // Default OFF in containers — flip with DBLIB_DEBUG=true for local dev.
        'debug' => filter_var(getenv('DBLIB_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),

        'student_db_prefix'   => getenv('DBLIB_STUDENT_DB_PREFIX') ?: 'dblib_stu_',
        'student_user_prefix' => getenv('DBLIB_STUDENT_USER_PREFIX') ?: 'stu_',

        // In compose the app connects from a SEPARATE container, so MariaDB sees
        // the scoped student users coming from another host. '%' lets them log
        // in from anywhere on the compose network (the network itself is the
        // boundary). Override with DBLIB_STUDENT_USER_HOST if you pin the app.
        'student_user_host'   => getenv('DBLIB_STUDENT_USER_HOST') ?: '%',
    ],

    'db' => [
        // 'db' is the compose service name, resolved over the internal network.
        'host'        => getenv('DBLIB_DB_HOST') ?: 'db',
        'port'        => (int) (getenv('DBLIB_DB_PORT') ?: 3306),
        'metadata_db' => getenv('DBLIB_DB_NAME') ?: 'dblib_meta',
        'charset'     => 'utf8mb4',

        // Provisioning account (CREATE DATABASE + CREATE USER + GRANT). Created
        // remotely-accessible by docker/initdb (root stays localhost-only).
        'admin_user'  => getenv('DBLIB_DB_ADMIN_USER') ?: 'dblib_admin',
        'admin_pass'  => getenv('DBLIB_DB_ADMIN_PASS') ?: 'dblib_admin_pw',
    ],

    'crypto' => [
        // Lives on a named volume, OUTSIDE the web root, persisted across
        // restarts. Losing it makes stored student passwords unrecoverable.
        'key_file' => getenv('DBLIB_KEY_FILE') ?: '/var/dblib-secrets/credential.key',
    ],
];
