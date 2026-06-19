<?php

declare(strict_types=1);

namespace Dblib\Controllers;

use Dblib\History\QueryHistory;
use Dblib\Http\Request;
use Dblib\Http\Response;
use Dblib\Sandbox\SandboxConnector;

/**
 * Serves the per-session query history pane (students only).
 */
final class HistoryController
{
    private SandboxConnector $sandbox;

    public function __construct()
    {
        $this->sandbox = new SandboxConnector();
    }

    public function list(Request $request): Response
    {
        if ($this->sandbox->student() === null) {
            return Response::json(['error' => 'Not authenticated.'], 401);
        }
        return Response::json(['entries' => (new QueryHistory())->all()]);
    }

    public function clear(Request $request): Response
    {
        if ($this->sandbox->student() === null) {
            return Response::json(['error' => 'Not authenticated.'], 401);
        }
        (new QueryHistory())->clear();
        return Response::json(['ok' => true]);
    }

    /** Download the session's SQL stream as a runnable .sql file. */
    public function exportSql(Request $request): Response
    {
        if ($this->sandbox->student() === null) {
            return Response::json(['error' => 'Not authenticated.'], 401);
        }

        // all() is newest-first; replay order is chronological.
        $entries = array_reverse((new QueryHistory())->all());
        if ($entries === []) {
            return Response::json(['error' => 'No history to export yet.'], 400);
        }

        return Response::download($this->buildSqlFile($entries), 'dblib-session.sql', 'application/sql; charset=utf-8');
    }

    /**
     * @param list<array{sql:string,ok:bool,info:string,at:string}> $entries chronological
     */
    private function buildSqlFile(array $entries): string
    {
        $okCount = count(array_filter($entries, static fn(array $e): bool => $e['ok']));

        $lines = [
            '-- dblib session SQL export',
            '-- Generated ' . date('Y-m-d H:i:s'),
            "-- {$okCount} successful statement(s); failed attempts are commented out.",
            '',
        ];

        foreach ($entries as $e) {
            if ($e['ok']) {
                $lines[] = "-- {$e['at']}  ({$e['info']})";
                $sql = rtrim($e['sql']);
                $lines[] = str_ends_with($sql, ';') ? $sql : $sql . ';';
            } else {
                $lines[] = "-- {$e['at']}  FAILED: {$e['info']}";
                foreach (preg_split('/\r?\n/', $e['sql']) as $line) {
                    $lines[] = '-- ' . $line;
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }
}
