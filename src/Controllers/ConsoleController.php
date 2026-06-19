<?php

declare(strict_types=1);

namespace Dblib\Controllers;

use Dblib\History\QueryHistory;
use Dblib\Http\Request;
use Dblib\Http\Response;
use Dblib\Sandbox\SandboxConnector;
use Dblib\Sql\ExecutionPipeline;
use Dblib\Sql\SqlException;
use Dblib\Support\View;

/**
 * The SQL console: hand-typed SQL feeding the one execution pipeline. The GUI
 * workbench posts builder-generated SQL to the same pipeline (see DbController),
 * so both share guards and the "SQL + result" evidence shape.
 */
final class ConsoleController
{
    /** Cap rows sent to the browser so a huge SELECT can't choke the page.
     *  The query still runs in full; use CSV export for the complete set. */
    private const MAX_DISPLAY_ROWS = 500;

    private SandboxConnector $sandbox;

    public function __construct()
    {
        $this->sandbox = new SandboxConnector();
    }

    public function show(Request $request): Response
    {
        if ($this->sandbox->student() === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        return Response::html(View::render('console', [
            'basePath' => $request->basePath(),
        ]));
    }

    public function run(Request $request): Response
    {
        $student = $this->sandbox->student();
        if ($student === null) {
            return Response::json(['error' => 'Not authenticated.'], 401);
        }

        $sql = (string) $request->input('sql', '');
        $history = new QueryHistory();

        try {
            $pdo = $this->sandbox->connect($student);
            $result = (new ExecutionPipeline($pdo))->run($sql);
        } catch (SqlException $e) {
            $history->record($sql, false, $e->getMessage());
            return Response::json(['error' => $e->getMessage(), 'sql' => trim($sql)]);
        }

        $history->record($result->sql, true, $result->isResultSet
            ? count($result->rows) . ' row(s)'
            : $result->affectedRows . ' row(s) affected');

        $rows = $result->rows;
        $totalRows = count($rows);
        $truncated = $result->isResultSet && $totalRows > self::MAX_DISPLAY_ROWS;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_DISPLAY_ROWS);
        }

        return Response::json([
            'sql'          => $result->sql,
            'isResultSet'  => $result->isResultSet,
            'columns'      => $result->columns,
            'rows'         => $rows,
            'affectedRows' => $result->affectedRows,
            'durationMs'   => round($result->durationMs, 2),
            'truncated'    => $truncated,
            'totalRows'    => $totalRows,
            'shownRows'    => count($rows),
        ]);
    }
}
