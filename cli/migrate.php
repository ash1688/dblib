<?php
/**
 * Create the metadata database + schema, and (optionally) a first teacher.
 *
 *   php cli/migrate.php
 *   php cli/migrate.php --teacher=teacher@dblib.local --password=secret123
 *
 * Idempotent: safe to run repeatedly.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Dblib\Accounts\AccountService;
use Dblib\Accounts\AccountValidationException;
use Dblib\Database\MetadataConnection;
use Dblib\Support\Config;

$opts = getopt('', ['teacher::', 'password::', 'name::']);

$metaDb = (string) Config::get('db.metadata_db', 'dblib_meta');

// 1. Create the metadata database itself (server-level connection).
$server = MetadataConnection::server();
$server->exec("CREATE DATABASE IF NOT EXISTS `{$metaDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Database `{$metaDb}` ready.\n";

// 2. Apply the schema.
$pdo = MetadataConnection::get();
$schema = file_get_contents(__DIR__ . '/../sql/metadata_schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Could not read sql/metadata_schema.sql\n");
    exit(1);
}
foreach (splitStatements($schema) as $statement) {
    try {
        $pdo->exec($statement);
    } catch (\PDOException $e) {
        // Tolerate "already exists" on re-run; surface anything else.
        if (!str_contains($e->getMessage(), 'already exists')
            && !str_contains($e->getMessage(), 'Duplicate')) {
            throw $e;
        }
    }
}
echo "Schema applied.\n";

// 2b. In-place upgrades for databases created before a column existed.
// Each is idempotent; "duplicate"/"exists" errors on re-run are ignored.
$upgrades = [
    'ALTER TABLE users ADD COLUMN student_id VARCHAR(32) NULL AFTER email',
    'ALTER TABLE users ADD UNIQUE KEY uq_users_student_id (student_id)',
    'ALTER TABLE users MODIFY email VARCHAR(190) NULL',
    // Storage-layer backstop for the app-level identity rules in AccountService:
    // students must carry a student_id, teachers must carry an email.
    'ALTER TABLE users ADD CONSTRAINT chk_users_identity CHECK ('
        . "(role = 'student' AND student_id IS NOT NULL) OR "
        . "(role = 'teacher' AND email IS NOT NULL))",
    // Seeds may auto-apply to new students on enrolment.
    'ALTER TABLE seeds ADD COLUMN apply_on_enrol TINYINT(1) NOT NULL DEFAULT 0',
];
foreach ($upgrades as $sql) {
    try {
        $pdo->exec($sql);
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
        if (!str_contains($msg, 'Duplicate')
            && !str_contains($msg, 'exists')
            && !str_contains($msg, 'check that column/key exists')) {
            throw $e;
        }
    }
}
echo "Upgrades applied.\n";

// 3. Optional first teacher.
$teacherEmail = $opts['teacher'] ?? null;
if ($teacherEmail !== null) {
    $password = $opts['password'] ?? null;
    if (!is_string($password) || $password === '') {
        fwrite(STDERR, "--password is required when creating a teacher.\n");
        exit(1);
    }
    $name = is_string($opts['name'] ?? null) ? $opts['name'] : 'Teacher';

    try {
        (new AccountService())->createTeacher((string) $teacherEmail, $password, $name);
    } catch (AccountValidationException $e) {
        fwrite(STDERR, 'Cannot create teacher: ' . $e->getMessage() . "\n");
        exit(1);
    }
    echo "Teacher account ready: {$teacherEmail}\n";
}

echo "Done.\n";

/**
 * Split a multi-statement SQL file on top-level semicolons. Strips `--` line
 * comments first so they don't swallow the statement that follows them. The
 * schema file contains no procedure bodies, so this is sufficient here.
 *
 * @return list<string>
 */
function splitStatements(string $sql): array
{
    // Drop full-line comments and blank lines.
    $lines = [];
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        if (preg_match('/^\s*--/', $line) === 1) {
            continue;
        }
        $lines[] = $line;
    }
    $clean = implode("\n", $lines);

    $out = [];
    foreach (explode(';', $clean) as $chunk) {
        $trimmed = trim($chunk);
        if ($trimmed !== '') {
            $out[] = $trimmed;
        }
    }
    return $out;
}
