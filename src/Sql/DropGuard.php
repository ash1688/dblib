<?php

declare(strict_types=1);

namespace Dblib\Sql;

/**
 * The single app-level guard from the spec: reject DROP DATABASE / DROP SCHEMA
 * so a student cannot nuke their own sandbox and lock themselves out.
 *
 * Operates on the masked SQL (comments stripped, string/ident contents blanked)
 * so that comment-smuggling, extra whitespace, and a table literally named
 * `database` are all handled correctly.
 *
 * NOTE (per design spec): MariaDB has no grant that allows dropping tables but
 * not the schema, which is why this lives at the app layer. Re-verify exact
 * grant behaviour at build time.
 */
final class DropGuard
{
    public static function check(SqlInspector $sql): void
    {
        foreach ($sql->statements() as $statement) {
            if (preg_match('/^\s*drop\s+(database|schema)\b/i', $statement) === 1) {
                throw new SqlException(
                    'DROP DATABASE / DROP SCHEMA is blocked — it would delete your whole '
                    . 'sandbox. You can freely DROP and rebuild individual tables instead.'
                );
            }
        }
    }
}
