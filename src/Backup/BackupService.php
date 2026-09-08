<?php

declare(strict_types=1);

namespace Dblib\Backup;

use Dblib\Database\MetadataConnection;
use Dblib\Support\Config;
use PDO;

/**
 * Builds the one-file backup a teacher downloads from the dashboard:
 *
 *   dblib-backup.sql   metadata DB + every sandbox DB + sandbox MySQL accounts
 *   credential.key     the AES key that decrypts stored sandbox passwords
 *   manifest.json      what was captured and from where
 *   README.txt         how to restore (cli/restore_backup.php)
 *
 * Together these are everything needed to stand the service up on another
 * host. The key is the irreplaceable part, which is why the controller demands
 * a password re-check before handing the bundle out.
 */
final class BackupService
{
    public const SQL_FILE      = 'dblib-backup.sql';
    public const KEY_FILE      = 'credential.key';
    public const MANIFEST_FILE = 'manifest.json';

    /**
     * @return array{filename:string, bytes:string, databases:list<string>, accounts:int}
     */
    public function create(): array
    {
        $server  = MetadataConnection::server();
        $metaDb  = (string) Config::get('db.metadata_db', 'dblib_meta');
        $prefix  = (string) Config::get('app.student_db_prefix', 'dblib_stu_');
        $keyFile = (string) Config::get('crypto.key_file');

        $key = is_file($keyFile) ? (string) file_get_contents($keyFile) : '';
        if (trim($key) === '') {
            throw new \RuntimeException("Encryption key not readable at {$keyFile}; refusing to make a backup without it.");
        }

        $dumper    = new SqlDumper($server);
        $databases = $this->databases($server, $metaDb, $prefix);

        $sql = "-- dblib full backup\n"
             . '-- Generated: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
             . '-- Databases: ' . implode(', ', $databases) . "\n"
             . "-- Restore with: php cli/restore_backup.php --file=<this bundle>\n\n"
             . "SET NAMES utf8mb4;\n" . SqlDumper::STMT_END . "\n\n";
        foreach ($databases as $db) {
            $sql .= $dumper->database($db);
        }

        $accounts = MetadataConnection::get()
            ->query('SELECT db_user, db_host FROM student_sandboxes ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
        $sql .= $dumper->users($accounts);

        $stamp    = gmdate('Y-m-d_His');
        $manifest = [
            'app'          => 'dblib',
            'generated_at' => gmdate(DATE_ATOM),
            'metadata_db'  => $metaDb,
            'db_prefix'    => $prefix,
            'databases'    => $databases,
            'accounts'     => count($accounts),
            'files'        => [self::SQL_FILE, self::KEY_FILE],
        ];

        $tar = new TarArchive();
        $tar->add(self::SQL_FILE, $sql);
        $tar->add(self::KEY_FILE, $key);
        $tar->add(self::MANIFEST_FILE, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $tar->add('README.txt', $this->readme());

        return [
            'filename'  => "dblib-backup-{$stamp}.tar.gz",
            'bytes'     => $tar->toGzip(),
            'databases' => $databases,
            'accounts'  => count($accounts),
        ];
    }

    /**
     * Metadata DB first, then every sandbox DB by prefix. Prefix match rather
     * than the student_sandboxes table so a DB whose metadata row was lost
     * still gets captured.
     *
     * @return list<string>
     */
    private function databases(PDO $server, string $metaDb, string $prefix): array
    {
        $stmt = $server->prepare('SHOW DATABASES LIKE ?');
        $stmt->execute([str_replace(['%', '_'], ['\%', '\_'], $prefix) . '%']);
        $sandboxes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        sort($sandboxes);
        return array_values(array_unique(array_merge([$metaDb], $sandboxes)));
    }

    private function readme(): string
    {
        return <<<'TXT'
        dblib backup bundle
        ===================

        dblib-backup.sql  Every database (metadata + student sandboxes) and the
                          scoped MySQL accounts, as plain SQL.
        credential.key    The AES-256-GCM key that decrypts the sandbox passwords
                          stored in the metadata DB. Without it every student's
                          stored database credential is unrecoverable. Keep this
                          bundle somewhere private.

        Restore onto a fresh dblib install (Docker or XAMPP):

          1. Install dblib as normal (Docker: docker compose up; XAMPP: config +
             migrate). Any key already generated there is replaced by the one in
             this bundle when you pass --replace-key.
          2. Copy this bundle onto the host, then run from the app directory:

                 php cli/restore_backup.php --file=dblib-backup-....tar.gz --replace-key

             In Docker:
                 docker compose cp dblib-backup-....tar.gz app:/tmp/backup.tar.gz
                 docker compose exec app php cli/restore_backup.php --file=/tmp/backup.tar.gz --replace-key

          3. Sign in with the same teacher account as before.

        The SQL is also readable by the mariadb/mysql CLI directly if you would
        rather restore by hand:  mariadb -u root -p < dblib-backup.sql
        then copy credential.key to the path in config.php ('crypto.key_file').

        Moving between Docker and XAMPP: sandbox MySQL accounts restore with the
        host they were created for. Docker creates them for '%', which works on
        XAMPP too. XAMPP creates them for '127.0.0.1', which the Docker app
        (connecting from another container) cannot use; reset those students'
        sandboxes from the teacher panel after such a move.
        TXT;
    }
}
