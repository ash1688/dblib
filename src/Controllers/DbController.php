<?php

declare(strict_types=1);

namespace Dblib\Controllers;

use Dblib\Auth\Auth;
use Dblib\History\QueryHistory;
use Dblib\Http\Request;
use Dblib\Http\Response;
use Dblib\Sandbox\SandboxConnector;
use Dblib\Schema\SchemaInspector;
use Dblib\Sql\ExecutionPipeline;
use Dblib\Sql\SqlBuilder;
use Dblib\Sql\SqlException;
use Dblib\Support\View;
use PDO;

/**
 * The student GUI workbench. Every builder action (create/drop table,
 * insert/update/delete row, browse) is turned into SQL by SqlBuilder and run
 * through the same ExecutionPipeline as the console — so the guards apply and
 * the exact SQL is returned alongside the result as evidence.
 */
final class DbController
{
    private const PER_PAGE = 25;
    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    private SandboxConnector $sandbox;

    public function __construct()
    {
        $this->sandbox = new SandboxConnector();
    }

    public function workbench(Request $request): Response
    {
        $student = $this->sandbox->resolve($request);
        if ($student === null) {
            // Teachers reaching here without a valid target go back to their list.
            $dest = (new Auth())->check() ? '/teacher' : '/login';
            return Response::redirect($request->basePath() . $dest);
        }

        $self = $this->sandbox->student() !== null;   // is the actor the student themselves?
        $label = $student['display_name'] ?: $student['student_id'];

        return Response::html(View::render('db', [
            'basePath'   => $request->basePath(),
            'user'       => $student,
            'types'      => SqlBuilder::catalog(),
            // Teacher-on-student context: thread the target id and show a banner.
            'targetUser' => $self ? '' : (string) $student['id'],
            'banner'     => $self ? null : "You are editing {$label}’s database (" . $student['student_id'] . '). Changes are live.',
            'consoleUrl' => $self
                ? $request->basePath() . '/console'
                : $request->basePath() . '/teacher/student/console?user_id=' . (int) $student['id'],
            'backUrl'    => $self ? null : $request->basePath() . '/teacher',
        ]));
    }

    public function tables(Request $request): Response
    {
        return $this->withSandbox($request, static function (PDO $pdo): Response {
            return Response::json(['tables' => (new SchemaInspector($pdo))->tables()]);
        });
    }

    /** Column metadata + first/Nth page of rows for one table. */
    public function table(Request $request): Response
    {
        $name = (string) $request->input('name', '');
        $page = max(1, (int) $request->input('page', '1'));
        $perPage = (int) $request->input('per_page', (string) self::PER_PAGE);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::PER_PAGE;
        }

        return $this->withSandbox($request, function (PDO $pdo) use ($name, $page, $perPage): Response {
            try {
                $inspector = new SchemaInspector($pdo);
                $columns = $inspector->columns($name);
                $total   = $inspector->rowCount($name);

                $pages  = max(1, (int) ceil($total / $perPage));
                $page   = min($page, $pages);
                $offset = ($page - 1) * $perPage;

                $builder = new SqlBuilder($pdo);
                $result  = (new ExecutionPipeline($pdo))->run($builder->browse($name, $perPage, $offset));
            } catch (SqlException $e) {
                return Response::json(['error' => $e->getMessage()], 400);
            }

            $primaryKey = [];
            foreach ($columns as $c) {
                if ($c['key'] === 'PRI') {
                    $primaryKey[] = $c['name'];
                }
            }
            $gridColumns = $result->columns !== []
                ? $result->columns
                : array_map(static fn(array $c): string => $c['name'], $columns);

            return Response::json([
                'table'      => $name,
                'columns'    => $columns,
                'primaryKey' => $primaryKey,
                'sql'        => $result->sql,
                'grid'       => ['columns' => $gridColumns, 'rows' => $result->rows],
                'page'       => $page,
                'pages'      => $pages,
                'total'      => $total,
                'perPage'    => $perPage,
                'perPageOptions' => self::PER_PAGE_OPTIONS,
            ]);
        });
    }

    public function createTable(Request $request): Response
    {
        $body = $this->jsonBody();
        return $this->buildAndRun($request, static fn(SqlBuilder $b): string =>
            $b->createTable((string) ($body['table'] ?? ''), $body['columns'] ?? []));
    }

    public function dropTable(Request $request): Response
    {
        $body = $this->jsonBody();
        return $this->buildAndRun($request, static fn(SqlBuilder $b): string =>
            $b->dropTable((string) ($body['table'] ?? '')));
    }

    public function insert(Request $request): Response
    {
        $body = $this->jsonBody();
        return $this->buildAndRun($request, static fn(SqlBuilder $b): string =>
            $b->insert((string) ($body['table'] ?? ''), $body['values'] ?? []));
    }

    public function update(Request $request): Response
    {
        $body = $this->jsonBody();
        return $this->buildAndRun($request, static fn(SqlBuilder $b): string =>
            $b->update((string) ($body['table'] ?? ''), $body['set'] ?? [], $body['where'] ?? []));
    }

    public function delete(Request $request): Response
    {
        $body = $this->jsonBody();
        return $this->buildAndRun($request, static fn(SqlBuilder $b): string =>
            $b->delete((string) ($body['table'] ?? ''), $body['where'] ?? []));
    }

    // ---- plumbing -----------------------------------------------------------

    /**
     * Build a statement, run it through the pipeline, and return a uniform
     * {ok, sql, ...} envelope. Generation errors (bad identifier/type) and
     * execution errors both come back as {ok:false, sql, error}.
     *
     * @param callable(SqlBuilder):string $build
     */
    private function buildAndRun(Request $request, callable $build): Response
    {
        // Record into the session history only when the student acts on their own
        // sandbox — a teacher's edits shouldn't land in anyone's history pane.
        $recordHistory = $this->sandbox->student() !== null;

        return $this->withSandbox($request, function (PDO $pdo) use ($build, $recordHistory): Response {
            $history = $recordHistory ? new QueryHistory() : null;
            $sql = '';
            try {
                $sql    = $build(new SqlBuilder($pdo));
                $result = (new ExecutionPipeline($pdo))->run($sql);
            } catch (SqlException $e) {
                $history?->record($sql, false, $e->getMessage());
                return Response::json(['ok' => false, 'sql' => $sql, 'error' => $e->getMessage()]);
            }

            $history?->record($result->sql, true, $result->isResultSet
                ? count($result->rows) . ' row(s)'
                : $result->affectedRows . ' row(s) affected');

            return Response::json([
                'ok'           => true,
                'sql'          => $result->sql,
                'isResultSet'  => $result->isResultSet,
                'columns'      => $result->columns,
                'rows'         => $result->rows,
                'affectedRows' => $result->affectedRows,
                'durationMs'   => round($result->durationMs, 2),
            ]);
        });
    }

    /** @param callable(PDO):Response $fn */
    private function withSandbox(Request $request, callable $fn): Response
    {
        $student = $this->sandbox->resolve($request);
        if ($student === null) {
            return Response::json(['error' => 'Not authorised for this sandbox.'], 403);
        }
        try {
            $pdo = $this->sandbox->connect($student);
        } catch (SqlException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
        return $fn($pdo);
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw  = file_get_contents('php://input') ?: 'null';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
