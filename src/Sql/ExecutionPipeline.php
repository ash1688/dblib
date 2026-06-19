<?php

declare(strict_types=1);

namespace Dblib\Sql;

use PDO;
use PDOException;

/**
 * THE single execution pipeline. Both the GUI builders and the hand-typed SQL
 * console funnel through run(). The GUI is just a SQL generator; nothing reaches
 * the database except through here, so the guards apply uniformly.
 */
final class ExecutionPipeline
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * Validate, execute, and capture one statement.
     *
     * @throws SqlException on guard rejection or database error.
     */
    public function run(string $rawSql): ExecutionResult
    {
        $inspector = new SqlInspector($rawSql);

        // Guards run on the masked/structured view, in order.
        SingleStatementGuard::check($inspector);
        DropGuard::check($inspector);

        $sql   = trim($rawSql);
        $start = hrtime(true);

        try {
            $statement = $this->connection->query($sql);
        } catch (PDOException $e) {
            throw new SqlException($this->friendlyError($e), 0, $e);
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        if ($statement->columnCount() > 0) {
            $rows    = $statement->fetchAll(PDO::FETCH_ASSOC);
            $columns = $rows !== [] ? array_keys($rows[0]) : $this->columnsFromMeta($statement);
            return ExecutionResult::resultSet($sql, $columns, $rows, $durationMs);
        }

        return ExecutionResult::affected($sql, $statement->rowCount(), $durationMs);
    }

    /** @return list<string> */
    private function columnsFromMeta(\PDOStatement $statement): array
    {
        $columns = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $meta = $statement->getColumnMeta($i);
            $columns[] = $meta['name'] ?? "col$i";
        }
        return $columns;
    }

    private function friendlyError(PDOException $e): string
    {
        // MariaDB's message after the SQLSTATE prefix is already student-readable.
        return $e->getMessage();
    }
}
