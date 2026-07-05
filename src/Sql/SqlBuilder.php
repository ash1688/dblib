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
     * Base datatypes offered in the create-table builder, mapped to their size
     * parameter (null = none, 'length' = one integer, 'decimal' = precision,scale).
     * A submitted base type is validated against these keys and any size is built
     * from validated integers, so the generated type fragment can't inject SQL.
     */
    private const TYPE_SPECS = [
        'INT'          => null,
        'INT UNSIGNED' => null,
        'TINYINT'      => null,
        'SMALLINT'     => null,
        'BIGINT'       => null,
        'FLOAT'        => null,
        'DOUBLE'       => null,
        'BOOLEAN'      => null,
        'DECIMAL'      => 'decimal',
        'CHAR'         => 'length',
        'VARCHAR'      => 'length',
        'TEXT'         => null,
        'DATE'         => null,
        'TIME'         => null,
        'DATETIME'     => null,
        'TIMESTAMP'    => null,
        'YEAR'         => null,
    ];

    /**
     * Referential actions accepted for ON DELETE / ON UPDATE. Validated as an
     * allow-list so the action fragment is always one of these exact strings.
     */
    private const FK_ACTIONS = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'];

    /** Bounds for the 'length' types. */
    private const LENGTH_BOUNDS = [
        'CHAR'    => ['min' => 1, 'max' => 255,   'default' => 1],
        'VARCHAR' => ['min' => 1, 'max' => 65535, 'default' => 255],
    ];

    /**
     * UI catalogue for the builder dropdown: each base type with how its size
     * field should behave (which the front-end uses to show/hint the input).
     *
     * @return list<array{type:string,param:?string,default:string,placeholder:string}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::TYPE_SPECS as $type => $param) {
            $default = '';
            $placeholder = '';
            if ($param === 'length') {
                $default = (string) self::LENGTH_BOUNDS[$type]['default'];
                $placeholder = 'length, e.g. ' . $default;
            } elseif ($param === 'decimal') {
                $default = '10,2';
                $placeholder = 'precision,scale, e.g. 10,2';
            }
            $out[] = ['type' => $type, 'param' => $param, 'default' => $default, 'placeholder' => $placeholder];
        }
        return $out;
    }

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<array{name:string,type:string,size?:string,nullable?:bool,primary?:bool,autoIncrement?:bool}> $columns
     */
    public function createTable(string $table, array $columns): string
    {
        if ($columns === []) {
            throw new SqlException('Add at least one column.');
        }

        $defs = [];
        $primary = [];
        foreach ($columns as $col) {
            $sqlType = $this->columnType((string) ($col['type'] ?? ''), (string) ($col['size'] ?? ''));
            $line = Identifier::quote((string) ($col['name'] ?? '')) . ' ' . $sqlType;
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

    /**
     * ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY. The constraint is named
     * fk_{table}_{column} so students can see which link it is; every part goes
     * through Identifier::quote and the actions through the FK_ACTIONS allow-list.
     */
    public function addForeignKey(
        string $table,
        string $column,
        string $refTable,
        string $refColumn,
        string $onDelete,
        string $onUpdate
    ): string {
        return 'ALTER TABLE ' . Identifier::quote($table)
            . ' ADD CONSTRAINT ' . Identifier::quote('fk_' . $table . '_' . $column)
            . ' FOREIGN KEY (' . Identifier::quote($column) . ')'
            . ' REFERENCES ' . Identifier::quote($refTable) . ' (' . Identifier::quote($refColumn) . ')'
            . ' ON DELETE ' . $this->fkAction($onDelete)
            . ' ON UPDATE ' . $this->fkAction($onUpdate);
    }

    public function dropForeignKey(string $table, string $constraint): string
    {
        return 'ALTER TABLE ' . Identifier::quote($table)
            . ' DROP FOREIGN KEY ' . Identifier::quote($constraint);
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

    private function fkAction(string $action): string
    {
        $action = strtoupper(trim($action));
        if (!in_array($action, self::FK_ACTIONS, true)) {
            throw new SqlException("Unknown referential action \"{$action}\".");
        }
        return $action;
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

    /**
     * Validate a base type from the allow-list and build its full type string,
     * applying a validated size/precision where the type takes one. The size is
     * never interpolated raw — only integers that passed range checks.
     */
    private function columnType(string $base, string $size): string
    {
        if (!array_key_exists($base, self::TYPE_SPECS)) {
            throw new SqlException("Unknown column type \"{$base}\".");
        }
        $param = self::TYPE_SPECS[$base];

        if ($param === null) {
            return $base;
        }
        if ($param === 'decimal') {
            [$precision, $scale] = $this->parseDecimal($size);
            return "DECIMAL({$precision},{$scale})";
        }
        return "{$base}(" . $this->parseLength($base, $size) . ')';
    }

    private function parseLength(string $base, string $size): int
    {
        $bounds = self::LENGTH_BOUNDS[$base];
        $size = trim($size);
        if ($size === '') {
            return $bounds['default'];
        }
        if (!ctype_digit($size)) {
            throw new SqlException("{$base} length must be a whole number.");
        }
        $n = (int) $size;
        if ($n < $bounds['min'] || $n > $bounds['max']) {
            throw new SqlException("{$base} length must be between {$bounds['min']} and {$bounds['max']}.");
        }
        return $n;
    }

    /** @return array{0:int,1:int} [precision, scale] */
    private function parseDecimal(string $size): array
    {
        $size = trim($size);
        if ($size === '') {
            return [10, 2];
        }
        $parts = array_map('trim', explode(',', $size));
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            throw new SqlException('DECIMAL needs precision,scale — e.g. 10,2.');
        }
        $precision = (int) $parts[0];
        $scale = (int) $parts[1];
        if ($precision < 1 || $precision > 65) {
            throw new SqlException('DECIMAL precision must be between 1 and 65.');
        }
        if ($scale < 0 || $scale > 30) {
            throw new SqlException('DECIMAL scale must be between 0 and 30.');
        }
        if ($scale > $precision) {
            throw new SqlException('DECIMAL scale cannot exceed its precision.');
        }
        return [$precision, $scale];
    }
}
