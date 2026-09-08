<?php
/**
 * Standalone assertions for the backup bundle format — no DB needed.
 * Run:  php tests/backup_test.php
 *
 * Covers the tar round-trip (odd sizes, empty and binary entries) and the
 * statement splitter that cli/restore_backup.php relies on, including data
 * that contains semicolons, comment markers and newlines.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Dblib\Backup\SqlDumper;
use Dblib\Backup\TarArchive;

$pass = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  ok  : {$label}\n";
    } else {
        $fail++;
        echo "  FAIL: {$label}\n";
    }
}

echo "TarArchive\n";
$tar = new TarArchive();
$binary = random_bytes(1500);            // not a multiple of 512
$tar->add('a.sql', "SELECT 1;\n");
$tar->add('dir/empty.txt', '');
$tar->add('blob.bin', $binary);
$tar->add('exact.bin', str_repeat('x', 1024)); // exact multiple of 512
$gz = $tar->toGzip(1_700_000_000);

$back = TarArchive::fromGzip($gz)->files();
check('all entries survive the round trip', array_keys($back) === ['a.sql', 'dir/empty.txt', 'blob.bin', 'exact.bin']);
check('text entry intact', $back['a.sql'] === "SELECT 1;\n");
check('empty entry intact', $back['dir/empty.txt'] === '');
check('binary entry intact (odd size)', $back['blob.bin'] === $binary);
check('entry of exactly 2 blocks intact', $back['exact.bin'] === str_repeat('x', 1024));
check('gzip magic present', str_starts_with($gz, "\x1f\x8b"));

$raw = gzdecode($gz);
check('ustar magic in first header', substr($raw, 257, 5) === 'ustar');
$sum = 0;
$hdr = substr($raw, 0, 512);
for ($i = 0; $i < 512; $i++) {
    $sum += ($i >= 148 && $i < 156) ? 32 : ord($hdr[$i]);
}
check('header checksum matches ustar rule', (int) octdec(trim(substr($hdr, 148, 8), "\0 ")) === $sum);
check('archive ends with two zero blocks', substr($raw, -1024) === str_repeat("\0", 1024));

$threw = false;
try {
    TarArchive::fromGzip('not gzip at all');
} catch (\RuntimeException) {
    $threw = true;
}
check('non-gzip input throws', $threw);

$threw = false;
try {
    (new TarArchive())->add('../escape.txt', '');
} catch (\InvalidArgumentException) {
    $threw = true;
}
check('path traversal names rejected', $threw);

echo "SqlDumper::statements\n";
$end = SqlDumper::STMT_END;
$dump = "-- dblib full backup\n-- Generated: now\n\n"
      . "SET NAMES utf8mb4;\n{$end}\n\n"
      . "-- ==== Database: x ====\n"
      . "CREATE DATABASE IF NOT EXISTS `x`;\n{$end}\n"
      . "INSERT INTO `t` (`c`) VALUES\n  ('a; b'),\n  ('-- looks like a comment'),\n  ('line1\\nline2');\n{$end}\n"
      . "-- trailing comment only\n{$end}\n"
      . "FLUSH PRIVILEGES;\n{$end}\n";
$stmts = SqlDumper::statements($dump);
check('four real statements found', count($stmts) === 4);
check('header comments dropped', $stmts[0] === 'SET NAMES utf8mb4');
check('semicolon inside a value is preserved', str_contains($stmts[2], "'a; b'"));
check('value that looks like a comment is preserved', str_contains($stmts[2], "'-- looks like a comment'"));
check('multi-line INSERT kept as one statement', substr_count($stmts[2], "\n") === 3);
check('trailing semicolon stripped', !str_ends_with($stmts[3], ';'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
