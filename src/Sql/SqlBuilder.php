<?php

declare(strict_types=1);

namespace Dblib\Sql;

use PDO;

/**
 * Generates SQL strings for the GUI builders. Every value is escaped with
 * PDO::quote() on the student's own connection and every identifier through
 * Identifier::quote(), so the output is a safe, complete statement that runs
 * through the same ExecutionPipeline as hand-typed SQL — and is shown to the
 * student verbatim as the evidence of what the GUI did.
 *
 * The GUI is a SQL *generator*; this class is that generator.
 */
final class SqlBuilder
{
    /**
     * Datatypes offered in the create-table builder. This is the (previously
     * deferred) starter list — extend here to add more. Submitted types are
     * validated against this allow-list, so the type string can't inject SQL.
     *
     * @var list<string>
     */
    public const TYPES = [
        'INT', 'INT UNSIGNED', 'BIGINT', 'DECIMAL(10,2)',
        'VARCHAR(255)', 'VARCHAR(50)', 'TEXT',
        'DATE', 'DATETIME', 'TIMESTAMP', 'BOOLEAN',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<array{name:string,type:string,nullable?:bool,primary?:bool,autoIncrement?:bool}> $columns
     */
    public function createTable(string $table, array $columns): string
    {
        if ($columns === []) {
            throw new SqlException('Add at least one column.');
        }

        $defs = [];
        $primary = [];
        foreach ($columns as $col) {
            $type = (string) ($col['type'] ?? '');
            if (!in_array($type, self::TYPES, true)) {
                throw new SqlException("Unknown column type \"{$type}\".");
            }
            $line = Identifier::quote((string) ($col['name'] ?? '')) . ' ' . $type;
            $line .= !empty($col['nullable']) ? ' NULL' : ' NOT NULL';
            if (!empty($col['autoIncrement'])) {
                $line .= ' AUTO_INCREMENT';
            }
            $defs[] = $line;
            if (!empty($col['primary'])) {
                $primary[] = Identifier::quote((string) ($col['name'] ?? ''));
            }
        }
        if ($primary !== []) {
            $defs[] = 'PRIMARY KEY (' . implode(', ', $primary) . ')';
        }

        return 'CREATE TABLE ' . Identifier::quote($table)
            . " (\n  " . implode(",\n  ", $defs) . "\n)";
    }

    public function dropTable(string $table): string
    {
        return 'DROP TABLE ' . Identifier::quote($table);
    }

    public function browse(string $table, int $limit, int $offset): string
    {
        $limit  = max(1, $limit);
        $offset = max(0, $offset);
        return 'SELECT * FROM ' . Identifier::quote($table) . " LIMIT {$offset}, {$limit}";
    }

    /**
     * @param list<array{column:string,mode:string,value?:string|null}> $values
     */
    public function insert(string $table, array $values): string
    {
        $cols = [];
        $lits = [];
        foreach ($values as $v) {
            if (($v['mode'] ?? '') === 'default') {
                continue; // omit the column → let MySQL apply its default
            }
            $cols[] = Identifier::quote((string) $v['column']);
            $lits[] = $this->literal($v);
        }
        if ($cols === []) {
            throw new SqlException('No values to insert.');
        }
        return 'INSERT INTO ' . Identifier::quote($table)
            . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $lits) . ')';
    }

    /**
     * @param list<array{column:string,mode:string,value?:string|null}> $set
     * @param list<array{column:string,value?:string|null,isNull?:bool}> $where
     */
    public function update(string $table, array $set, array $where): string
    {
        $assignments = [];
        foreach ($set as $v) {
            $assignments[] = Identifier::quote((string) $v['column']) . ' = ' . $this->literal($v);
        }
        if ($assignments === []) {
            throw new SqlException('No columns to update.');
        }
        return 'UPDATE ' . Identifier::quote($table)
            . ' SET ' . implode(', ', $assignments)
            . ' WHERE ' . $this->whereClause($where) . ' LIMIT 1';
    }

    /**
     * @param list<array{column:string,value?:string|null,isNull?:bool}> $where
     */
    public function delete(string $table, array $where): string
    {
        return 'DELETE FROM ' . Identifier::quote($table)
            . ' WHERE ' . $this->whereClause($where) . ' LIMIT 1';
    }

    /** @param array{mode?:string,value?:string|null} $v */
    private function literal(array $v): string
    {
        if (($v['mode'] ?? '') === 'null') {
            return 'NULL';
        }
        return $this->pdo->quote((string) ($v['value'] ?? ''));
    }

    /** @param list<array{column:string,value?:string|null,isNull?:bool}> $where */
    private function whereClause(array $where): string
    {
        if ($where === []) {
            // Guard against a builder bug ever producing an unscoped UPDATE/DELETE.
            throw new SqlException('Refusing to build a statement with no WHERE clause.');
        }
        $parts = [];
        foreach ($where as $w) {
            $col = Identifier::quote((string) $w['column']);
            $parts[] = !empty($w['isNull'])
                ? "{$col} IS NULL"
                : "{$col} = " . $this->pdo->quote((string) ($w['value'] ?? ''));
        }
        return implode(' AND ', $parts);
    }
}
