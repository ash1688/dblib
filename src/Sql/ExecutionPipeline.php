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
            // Rows are fetched by position and re-keyed by label, so two
            // same-named columns (typical of a JOIN: pets.name, owners.name)
            // both survive instead of the last one silently overwriting the
            // first as FETCH_ASSOC would.
            $columns = $this->columnLabels($statement);
            $rows    = array_map(
                static fn(array $r): array => array_combine($columns, $r),
                $statement->fetchAll(PDO::FETCH_NUM),
            );
            return ExecutionResult::resultSet($sql, $columns, $rows, $durationMs);
        }

        return ExecutionResult::affected($sql, $statement->rowCount(), $durationMs);
    }

    /**
     * One unique label per result column. Plain column names (or aliases)
     * normally; a name that appears more than once is qualified with its table
     * (`owners.name`), and anything still colliding gets a numeric suffix.
     *
     * @return list<string>
     */
    private function columnLabels(\PDOStatement $statement): array
    {
        $metas = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $metas[] = $statement->getColumnMeta($i) ?: [];
        }
        $names  = array_map(static fn(array $m, int $i): string => (string) ($m['name'] ?? "col$i"), $metas, array_keys($metas));
        $counts = array_count_values($names);

        $labels = [];
        $seen   = [];
        foreach ($names as $i => $name) {
            $label = $name;
            if ($counts[$name] > 1 && ($metas[$i]['table'] ?? '') !== '') {
                $label = $metas[$i]['table'] . '.' . $name;
            }
            $base = $label;
            for ($k = 2; isset($seen[$label]); $k++) {
                $label = "{$base} ({$k})";
            }
            $seen[$label] = true;
            $labels[] = $label;
        }
        return $labels;
    }

    private function friendlyError(PDOException $e): string
    {
        // MariaDB's message after the SQLSTATE prefix is already student-readable.
        return $e->getMessage();
    }
}
