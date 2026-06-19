<?php
/**
 * Standalone assertions for the safety-critical guards — no DB needed.
 * Run:  php tests/guard_test.php
 *
 * These cover the bypasses called out in the design review: comment-smuggled
 * DROP DATABASE, whitespace tricks, semicolon-batching, and false positives on
 * identifiers/strings that merely contain the word "database".
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Dblib\Sql\DropGuard;
use Dblib\Sql\SingleStatementGuard;
use Dblib\Sql\SqlException;
use Dblib\Sql\SqlInspector;

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

/** True if running the two guards rejects the SQL. */
function rejected(string $sql): bool
{
    try {
        $inspector = new SqlInspector($sql);
        SingleStatementGuard::check($inspector);
        DropGuard::check($inspector);
        return false;
    } catch (SqlException) {
        return true;
    }
}

echo "DROP DATABASE / SCHEMA must be blocked:\n";
check('plain drop database',            rejected('DROP DATABASE foo'));
check('lowercase',                      rejected('drop database foo'));
check('mixed case schema',              rejected('DrOp ScHeMa foo'));
check('extra whitespace',               rejected("DROP    DATABASE  foo"));
check('newlines/tabs',                  rejected("DROP\n\tDATABASE foo"));
check('block-comment smuggling',        rejected('DROP/**/DATABASE foo'));
check('comment between words',          rejected("DROP/* hi */DATABASE foo"));
check('leading line comment',           rejected("-- nuke\nDROP DATABASE foo"));
check('if exists',                      rejected('DROP DATABASE IF EXISTS foo'));

echo "\nMulti-statement must be blocked:\n";
check('two statements',                 rejected('SELECT 1; SELECT 2'));
check('drop smuggled after select',     rejected('SELECT 1; DROP DATABASE foo'));
check('trailing semicolon is fine',     !rejected('SELECT 1;'));
check('empty query',                    rejected('   '));

echo "\nLegitimate statements must pass:\n";
check('select',                         !rejected('SELECT * FROM students'));
check('drop table (allowed)',           !rejected('DROP TABLE orders'));
check('drop table if exists',           !rejected('DROP TABLE IF EXISTS orders'));
check('identifier named database',      !rejected('DROP TABLE `database`'));
check('string contains drop database',  !rejected("INSERT INTO logs(msg) VALUES('drop database x')"));
check('create table',                   !rejected('CREATE TABLE t (id INT)'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
