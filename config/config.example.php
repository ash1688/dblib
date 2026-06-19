<?php
/**
 * Copy this file to config/config.php and edit for your environment.
 * config/config.php is gitignored (it names the provisioning account).
 */

return [
    'app' => [
        'debug' => true,

        // Identifier prefixes for provisioned student sandboxes.
        'student_db_prefix'   => 'dblib_stu_',
        'student_user_prefix' => 'stu_',
        // MySQL host the scoped student users are created for. This MUST match
        // how the app connects (db.host below): MariaDB treats 'localhost'
        // (socket/pipe) and '127.0.0.1' (TCP) as different host identities, so a
        // mismatch causes "Access denied". With db.host = 127.0.0.1 (PDO uses
        // TCP), use '127.0.0.1' here too. For a separate app/DB host, use the
        // app server's address as MariaDB sees it (or '%').
        'student_user_host'   => '127.0.0.1',
    ],

    'db' => [
        'host'        => '127.0.0.1',
        'port'        => 3306,
        'metadata_db' => 'dblib_meta',
        'charset'     => 'utf8mb4',

        // Provisioning / metadata account. Needs CREATE DATABASE + CREATE USER +
        // GRANT. On a stock XAMPP this is root with an empty password — fine for
        // local dev, lock it down before any shared deployment.
        'admin_user'  => 'root',
        'admin_pass'  => '',
    ],

    'crypto' => [
        // Key file MUST live outside the web root. Generate with: php cli/genkey.php
        'key_file' => 'C:\\xampp\\dblib-secrets\\credential.key',
    ],
];
