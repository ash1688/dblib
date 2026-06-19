<?php

declare(strict_types=1);

namespace Dblib\Controllers;

use Dblib\Http\Request;
use Dblib\Http\Response;
use Dblib\Sandbox\SandboxConnector;
use Dblib\Sql\ExecutionPipeline;
use Dblib\Sql\Identifier;
use Dblib\Sql\SqlException;

/**
 * CSV export of query results (students only). The query runs through the same
 * ExecutionPipeline as everything else — so the guards apply and the export can
 * only ever read the student's own sandbox.
 *
 * Two inputs: `table` exports a whole table (server builds `SELECT * FROM ...`,
 * no LIMIT — the data browser paginates for viewing, but export is complete);
 * `sql` exports the exact query a student ran in the console.
 */
final class ExportController
{
    private SandboxConnector $sandbox;

    public function __construct()
    {
        $this->sandbox = new SandboxConnector();
    }

    public function csv(Request $request): Response
    {
        // Resolves to the logged-in student, or a teacher's owned target student.
        $student = $this->sandbox->resolve($request);
        if ($student === null) {
            return Response::json(['error' => 'Not authorised for this sandbox.'], 403);
        }

        $table = trim((string) $request->input('table', ''));
        $sql   = (string) $request->input('sql', '');

        try {
            $pdo   = $this->sandbox->connect($student);
            $query = $table !== '' ? 'SELECT * FROM ' . Identifier::quote($table) : $sql;
            $result = (new ExecutionPipeline($pdo))->run($query);
        } catch (SqlException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }

        if (!$result->isResultSet) {
            return Response::json(['error' => 'Only query results (SELECT) can be exported to CSV.'], 400);
        }

        $filename = ($table !== '' ? $table : 'query-results') . '.csv';
        return Response::download($this->toCsv($result->columns, $result->rows), $filename);
    }

    /**
     * @param list<string> $columns
     * @param list<array<string,mixed>> $rows
     */
    private function toCsv(array $columns, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? null;
                $line[] = $value === null ? '' : (string) $value;
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        // UTF-8 BOM so Excel opens accented data correctly.
        return "\xEF\xBB\xBF" . $csv;
    }
}
