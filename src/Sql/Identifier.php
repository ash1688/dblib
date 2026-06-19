<?php

declare(strict_types=1);

namespace Dblib\Sql;

/**
 * Safe identifier quoting for builder-generated SQL. Table and column names
 * coming from the GUI are restricted to [A-Za-z0-9_] and back-tick quoted, so
 * a name can never inject SQL or break out of the quoting (the allowed charset
 * excludes the back-tick itself). Values are never quoted here — those go
 * through PDO::quote() in SqlBuilder.
 */
final class Identifier
{
    public static function quote(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new SqlException(
                "Invalid name \"{$name}\". Use only letters, numbers, and underscores."
            );
        }
        return '`' . $name . '`';
    }
}
