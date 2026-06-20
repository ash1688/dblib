<?php
/**
 * Exit 0 once the database accepts a connection with the provisioning account,
 * 1 otherwise. Used by the entrypoint's wait loop. Standalone (reads env
 * directly) so it doesn't depend on the app bootstrap / config being in place.
 */

declare(strict_types=1);

$host = getenv('DBLIB_DB_HOST') ?: 'db';
$port = (int) (getenv('DBLIB_DB_PORT') ?: 3306);
$user = getenv('DBLIB_DB_ADMIN_USER') ?: 'dblib_admin';
$pass = getenv('DBLIB_DB_ADMIN_PASS') ?: 'dblib_admin_pw';

try {
    new PDO("mysql:host={$host};port={$port}", $user, $pass, [
        PDO::ATTR_TIMEOUT => 3,
    ]);
    exit(0);
} catch (\Throwable $e) {
    exit(1);
}
