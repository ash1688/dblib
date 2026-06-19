<?php

declare(strict_types=1);

namespace Dblib\Sql;

/**
 * Outcome of one run through the pipeline. Either a result set (SELECT/SHOW/…)
 * or an affected-row count (INSERT/UPDATE/DDL). Always carries the exact SQL
 * that produced it — that pairing is the evidence artifact.
 */
final class ExecutionResult
{
    private function __construct(
        public readonly string $sql,
        public readonly bool $isResultSet,
        /** @var list<string> */
        public readonly array $columns,
        /** @var list<array<string,mixed>> */
        public readonly array $rows,
        public readonly int $affectedRows,
        public readonly float $durationMs,
    ) {
    }

    /**
     * @param list<string> $columns
     * @param list<array<string,mixed>> $rows
     */
    public static function resultSet(string $sql, array $columns, array $rows, float $durationMs): self
    {
        return new self($sql, true, $columns, $rows, count($rows), $durationMs);
    }

    public static function affected(string $sql, int $affectedRows, float $durationMs): self
    {
        return new self($sql, false, [], [], $affectedRows, $durationMs);
    }
}
