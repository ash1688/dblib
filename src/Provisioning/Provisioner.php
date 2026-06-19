<?php

declare(strict_types=1);

namespace Dblib\Provisioning;

use Dblib\Crypto\CredentialCipher;
use Dblib\Database\MetadataConnection;
use Dblib\Support\Config;
use PDO;

/**
 * Creates a student's sandbox: a dedicated database + a scoped MySQL user with
 * ALL PRIVILEGES on that database only. The scoped user has no global grants,
 * so cross-DB access, CREATE DATABASE/USER, GRANT, and file ops are all blocked
 * for free (see design spec — grants do the heavy lifting).
 *
 * Idempotent-ish and best-effort transactional: the metadata row is written
 * last, so a half-built sandbox can be detected and re-provisioned.
 */
final class Provisioner
{
    public function __construct(private readonly CredentialCipher $cipher)
    {
    }

    /**
     * Provision a sandbox for an existing user row.
     *
     * @param string $identifier human-facing handle the sandbox is named after
     *                           (the student ID); sanitised to [a-z0-9_].
     * @return array{db_name:string, db_user:string} the created identifiers
     */
    public function provisionForUser(int $userId, string $identifier): array
    {
        $dbPrefix   = (string) Config::get('app.student_db_prefix', 'dblib_stu_');
        $userPrefix = (string) Config::get('app.student_user_prefix', 'stu_');
        $host       = (string) Config::get('app.student_user_host', '127.0.0.1');

        // Internal user id is appended so distinct student IDs that sanitise to
        // the same slug (e.g. "S-12" and "S 12") can never collide.
        $slug    = $this->slug($identifier) . '_' . $userId;
        $dbName  = $dbPrefix . $slug;
        $dbUser  = substr($userPrefix . $slug, 0, 32); // MySQL username max length
        $password = $this->generatePassword();

        $server = MetadataConnection::server();

        // Identifiers are validated to [a-z0-9_] by slug(), so back-tick quoting
        // is safe here; values still go through a prepared statement below.
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $stmt = $server->prepare("CREATE USER IF NOT EXISTS ?@? IDENTIFIED BY ?");
        $stmt->execute([$dbUser, $host, $password]);

        $server->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO " . $server->quote($dbUser) . '@' . $server->quote($host));
        $server->exec('FLUSH PRIVILEGES');

        // Metadata row written last — its presence means provisioning completed.
        $meta = MetadataConnection::get();
        $meta->prepare(
            'INSERT INTO student_sandboxes (user_id, db_name, db_user, db_host, cred_ciphertext)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE db_name = VALUES(db_name), db_user = VALUES(db_user),
                                     db_host = VALUES(db_host), cred_ciphertext = VALUES(cred_ciphertext)'
        )->execute([$userId, $dbName, $dbUser, $host, $this->cipher->encrypt($password)]);

        return ['db_name' => $dbName, 'db_user' => $dbUser];
    }

    /**
     * Tear down a student's sandbox: drop the database, drop the scoped MySQL
     * user, and remove the metadata row. Safe to call when partially or never
     * provisioned (each step is best-effort). Returns false if there was no
     * sandbox row to act on.
     */
    public function deprovisionForUser(int $userId): bool
    {
        $meta = MetadataConnection::get();
        $stmt = $meta->prepare('SELECT db_name, db_user, db_host FROM student_sandboxes WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $sandbox = $stmt->fetch();
        if ($sandbox === false) {
            return false;
        }

        $server = MetadataConnection::server();
        // Identifiers came from slug() ([a-z0-9_]) at provision time, so
        // back-tick quoting the db name is safe; the user is quoted as a string.
        $server->exec("DROP DATABASE IF EXISTS `{$sandbox['db_name']}`");
        $server->exec(
            'DROP USER IF EXISTS ' . $server->quote($sandbox['db_user']) . '@' . $server->quote($sandbox['db_host'])
        );
        $server->exec('FLUSH PRIVILEGES');

        $meta->prepare('DELETE FROM student_sandboxes WHERE user_id = ?')->execute([$userId]);
        return true;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $value) ?? '');
        return trim($slug, '_') ?: 'student';
    }

    private function generatePassword(int $bytes = 18): string
    {
        // URL-safe, no characters needing SQL escaping.
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'Aa'), '=');
    }
}
