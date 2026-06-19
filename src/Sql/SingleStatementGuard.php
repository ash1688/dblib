<?php

declare(strict_types=1);

namespace Dblib\Sql;

/**
 * Enforces ONE statement per run.
 *
 * This is what keeps the DROP DATABASE guard un-bypassable (no `;`-smuggling)
 * and makes the "SQL + result" evidence exact. See dblib-design.md.
 */
final class SingleStatementGuard
{
    public static function check(SqlInspector $sql): void
    {
        $count = $sql->statementCount();

        if ($count === 0) {
            throw new SqlException('Empty query — type a SQL statement to run.');
        }

        if ($count > 1) {
            throw new SqlException(
                'Only one statement can be run at a time. '
                . 'Remove the extra ";" and run each statement separately.'
            );
        }
    }
}
