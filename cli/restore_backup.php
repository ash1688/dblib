<?php
/**
 * Restore a dashboard backup bundle (dblib-backup-*.tar.gz) onto this install.
 *
 *   php cli/restore_backup.php --file=/path/to/dblib-backup-....tar.gz [--replace-key] [--dry-run]
 *
 * Replays the bundle's SQL on the provisioning connection (each table is
 * DROP + CREATE'd, so same-named databases here are overwritten by the backup's
 * copy) and installs the bundled credential key at config 'crypto.key_file'.
 *
 * The key is checked FIRST: if one already exists here and differs from the
 * bundle's, nothing runs unless --replace-key is given, because restored
 * sandbox credentials are only readable with the key they were encrypted under.
 *
 * --dry-run parses the bundle and reports what would happen without touching
 * the database or the key.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Dblib\Backup\BackupService;
use Dblib\Backup\SqlDumper;
use Dblib\Backup\TarArchive;
use Dblib\Database\MetadataConnection;
use Dblib\Support\Config;

$opts = getopt('', ['file:', 'replace-key', 'dry-run']);
$file = $opts['file'] ?? null;
if (!is_string($file) || $file === '') {
    fwrite(STDERR, "Usage: php cli/restore_backup.php --file=<bundle.tar.gz> [--replace-key] [--dry-run]\n");
    exit(2);
}
if (!is_file($file)) {
    fwrite(STDERR, "Bundle not found: {$file}\n");
    exit(2);
}
$replaceKey = array_key_exists('replace-key', $opts);
$dryRun     = array_key_exists('dry-run', $opts);

// 1. Unpack and sanity-check the bundle.
try {
    $archive = TarArchive::fromGzip((string) file_get_contents($file));
} catch (\RuntimeException $e) {
    fwrite(STDERR, "Cannot read bundle: {$e->getMessage()}\n");
    exit(1);
}
$files = $archive->files();
foreach ([BackupService::SQL_FILE, BackupService::KEY_FILE] as $required) {
    if (!isset($files[$required])) {
        fwrite(STDERR, "Bundle is missing {$required}; is this a dblib backup?\n");
        exit(1);
    }
}
$manifest = json_decode($files[BackupService::MANIFEST_FILE] ?? '{}', true);
if (is_array($manifest)) {
    echo 'Bundle generated: ' . ($manifest['generated_at'] ?? 'unknown') . "\n";
    echo 'Databases:        ' . implode(', ', $manifest['databases'] ?? []) . "\n";
    echo 'Sandbox accounts: ' . ($manifest['accounts'] ?? '?') . "\n";
}

$bundledKey = trim($files[BackupService::KEY_FILE]);
if (base64_decode($bundledKey, true) === false || strlen((string) base64_decode($bundledKey, true)) !== 32) {
    fwrite(STDERR, "Bundled credential.key is malformed; refusing to restore.\n");
    exit(1);
}

// 2. Key policy, decided before any SQL runs.
$keyFile    = (string) Config::get('crypto.key_file');
$currentKey = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : null;
$keyAction  = 'install';
if ($currentKey !== null) {
    if (hash_equals($currentKey, $bundledKey)) {
        $keyAction = 'keep (identical)';
    } elseif ($replaceKey) {
        $keyAction = 'REPLACE existing key';
    } else {
        $keyAction = 'REFUSED: a different key exists (needs --replace-key)';
    }
}

$statements = SqlDumper::statements($files[BackupService::SQL_FILE]);
echo 'SQL statements:   ' . count($statements) . "\n";
echo "Key:              {$keyAction} -> {$keyFile}\n";

$refused = str_starts_with($keyAction, 'REFUSED');
if ($dryRun) {
    echo "Dry run: nothing changed.\n";
    exit($refused ? 1 : 0);
}
if ($refused) {
    fwrite(STDERR, "\nA different credential key already exists at {$keyFile}.\n"
        . "Restoring this bundle's databases without its key would leave every restored\n"
        . "sandbox credential undecryptable. Re-run with --replace-key to overwrite it\n"
        . "(any sandboxes encrypted under the current key will then be unreadable).\n");
    exit(1);
}

// 3. Replay the SQL on one server-level connection (SET FOREIGN_KEY_CHECKS and
//    USE are per-session, so everything must go through the same handle).
$server = MetadataConnection::server();
$done = 0;
foreach ($statements as $sql) {
    try {
        $server->exec($sql);
        $done++;
    } catch (\PDOException $e) {
        $head = preg_replace('/\s+/', ' ', substr($sql, 0, 120));
        fwrite(STDERR, "\nFailed after {$done} statements at: {$head}...\n{$e->getMessage()}\n");
        fwrite(STDERR, "The key was NOT changed. Fix the cause and re-run; the dump is idempotent.\n");
        exit(1);
    }
}
echo "Replayed {$done} statements.\n";

// 4. Install the key last, once the data it protects is in place.
if ($keyAction !== 'keep (identical)') {
    $dir = dirname($keyFile);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        fwrite(STDERR, "Could not create key directory {$dir}.\n");
        exit(1);
    }
    if (file_put_contents($keyFile, $bundledKey) === false) {
        fwrite(STDERR, "Could not write key to {$keyFile}. Databases were restored; copy credential.key there by hand.\n");
        exit(1);
    }
    @chmod($keyFile, 0644);
    echo "Installed credential key at {$keyFile}.\n";
}

echo "Restore complete. Sign in with the teacher account from the backup.\n";
