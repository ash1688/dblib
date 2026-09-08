<?php

declare(strict_types=1);

namespace Dblib\Backup;

use PDO;

/**
 * Server-level SQL dump for the backup bundle: every table of a database as
 * DROP + CREATE + chunked INSERTs, plus the scoped MySQL users that own the
 * student sandboxes (password hashes and grants, so they come back intact).
 *
 * Every statement is followed by a STMT_END marker line. The `mariadb` CLI
 * treats it as a comment, and cli/restore_backup.php splits on it, which
 * avoids parsing SQL to find statement boundaries in data that may contain
 * semicolons.
 *
 * Separate from ExportController's student-facing dump on purpose: this one
 * runs on the privileged provisioning connection with no guards, so it must
 * never be reachable from a student session.
 */
final class SqlDumper
{
    public const STMT_END = '-- dblib:stmt-end';

    public function __construct(private readonly PDO $server)
    {
    }

    /** Full dump of one database, including CREATE DATABASE + USE. */
    public function database(string $name): string
    {
        $q = self::ident($name);
        $this->server->exec("USE {$q}");

        $out = "-- ==== Database: {$name} ====\n"
             . "CREATE DATABASE IF NOT EXISTS {$q} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n" . self::STMT_END . "\n"
             . "USE {$q};\n" . self::STMT_END . "\n"
             . "SET FOREIGN_KEY_CHECKS=0;\n" . self::STMT_END . "\n\n";

        foreach ($this->tables() as $table) {
            $qt = self::ident($table);
            $create = $this->server->query("SHOW CREATE TABLE {$qt}")->fetch(PDO::FETCH_ASSOC);
            $out .= "-- ---- {$name}.{$table} ----\n"
                  . "DROP TABLE IF EXISTS {$qt};\n" . self::STMT_END . "\n"
                  . ($create['Create Table'] ?? '') . ";\n" . self::STMT_END . "\n";

            $stmt = $this->server->query("SELECT * FROM {$qt}");
            $cols = null;
            $chunk = [];
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $cols ??= array_keys($row);
                $chunk[] = '  (' . implode(', ', array_map(
                    fn($v): string => $v === null ? 'NULL' : $this->server->quote((string) $v),
                    array_values($row),
                )) . ')';
                if (count($chunk) === 100) {
                    $out .= $this->insert($qt, $cols, $chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== [] && $cols !== null) {
                $out .= $this->insert($qt, $cols, $chunk);
            }
            $out .= "\n";
        }

        return $out . "SET FOREIGN_KEY_CHECKS=1;\n" . self::STMT_END . "\n\n";
    }

    /**
     * CREATE USER (with the stored password hash) + GRANTs for the given
     * accounts. Users are dropped first so a restore onto a server that already
     * has them ends up with the backed-up password, not the old one.
     *
     * @param list<array{db_user:string,db_host:string}> $accounts
     */
    public function users(array $accounts): string
    {
        $out = "-- ==== Sandbox MySQL accounts ====\n";
        foreach ($accounts as $a) {
            $who = $this->server->quote($a['db_user']) . '@' . $this->server->quote($a['db_host']);
            try {
                $create = $this->server->query("SHOW CREATE USER {$who}")->fetchColumn();
            } catch (\PDOException) {
                $create = false;
            }
            if (!is_string($create) || $create === '') {
                $out .= "-- (skipped {$who}: account not found on server)\n";
                continue;
            }
            $out .= "DROP USER IF EXISTS {$who};\n" . self::STMT_END . "\n"
                  . $create . ";\n" . self::STMT_END . "\n";
            foreach ($this->server->query("SHOW GRANTS FOR {$who}")->fetchAll(PDO::FETCH_COLUMN) as $grant) {
                $out .= $grant . ";\n" . self::STMT_END . "\n";
            }
        }
        return $out . "FLUSH PRIVILEGES;\n" . self::STMT_END . "\n\n";
    }

    /**
     * Split a dump produced by this class back into executable statements.
     *
     * @return list<string>
     */
    public static function statements(string $dump): array
    {
        $parts = explode(self::STMT_END, $dump);
        $stmts = [];
        foreach ($parts as $part) {
            // Drop comment-only lines so an all-comment chunk yields nothing.
            $lines = array_filter(explode("\n", $part), static fn(string $l): bool =>
                trim($l) !== '' && !str_starts_with(ltrim($l), '-- '));
            $sql = trim(implode("\n", $lines));
            if ($sql !== '') {
                $stmts[] = rtrim($sql, ';');
            }
        }
        return $stmts;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return $this->server->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')
                            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param list<string> $cols @param list<string> $rows */
    private function insert(string $qt, array $cols, array $rows): string
    {
        $c = implode(', ', array_map([self::class, 'ident'], $cols));
        return "INSERT INTO {$qt} ({$c}) VALUES\n" . implode(",\n", $rows) . ";\n" . self::STMT_END . "\n";
    }

    private static function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
