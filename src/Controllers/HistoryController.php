<?php

declare(strict_types=1);

namespace Dblib\Controllers;

use Dblib\History\QueryHistory;
use Dblib\Http\Request;
use Dblib\Http\Response;
use Dblib\Sandbox\SandboxConnector;

/**
 * Serves the student's command log pane and its export (students only).
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

    /**
     * The log is assessment evidence, so it is append-only: the route stays
     * (old clients may still call it) but always refuses.
     */
    public function clear(Request $request): Response
    {
        return Response::json(['error' => 'The command log cannot be cleared: it is your evidence of the work.'], 403);
    }

    /** Download the whole command log as a runnable .sql file. */
    public function exportSql(Request $request): Response
    {
        if ($this->sandbox->student() === null) {
            return Response::json(['error' => 'Not authenticated.'], 401);
        }

        $entries = (new QueryHistory())->chronological();
        if ($entries === []) {
            return Response::json(['error' => 'Nothing in the command log to export yet.'], 400);
        }

        return Response::download(
            $this->buildSqlFile($entries),
            'dblib-command-log-' . date('Y-m-d') . '.sql',
            'application/sql; charset=utf-8',
        );
    }

    /**
     * @param list<array{sql:string,ok:bool,info:string,at:string}> $entries chronological
     */
    private function buildSqlFile(array $entries): string
    {
        $okCount = count(array_filter($entries, static fn(array $e): bool => $e['ok']));

        $lines = [
            '-- dblib command log: every statement run, in order, with when it ran.',
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
