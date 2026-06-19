<?php

declare(strict_types=1);

namespace Dblib\Seeds;

use Dblib\Classroom\ClassService;
use Dblib\Sandbox\SandboxConnector;
use Dblib\Schema\SchemaInspector;
use Dblib\Sql\ExecutionPipeline;
use Dblib\Sql\Identifier;
use Dblib\Sql\SqlException;
use Dblib\Sql\SqlInspector;
use PDO;

/**
 * Applies a seed script to student sandboxes — through the SAME execution
 * pipeline students use. The script is split into individual statements (so the
 * single-statement guard and DROP DATABASE guard apply to each) and run in
 * order against each student's own scoped connection.
 *
 * Default mode is reset-then-load (drop the student's existing tables first) so
 * the class shares one identical dataset.
 */
final class SeedRunner
{
    public function __construct(private readonly SandboxConnector $connector)
    {
    }

    /**
     * @param array<string,mixed> $student a user row (needs at least 'id')
     * @return array{ok:bool, statements:int, error:?string}
     */
    public function applyToStudent(array $student, string $script, bool $reset): array
    {
        try {
            $pdo = $this->connector->connect($student);
        } catch (SqlException $e) {
            return ['ok' => false, 'statements' => 0, 'error' => $e->getMessage()];
        }

        $pipeline = new ExecutionPipeline($pdo);
        $ran = 0;

        try {
            if ($reset) {
                $this->resetTables($pdo, $pipeline);
            }
            foreach ((new SqlInspector($script))->rawStatements() as $statement) {
                $pipeline->run($statement);
                $ran++;
            }
        } catch (SqlException $e) {
            return ['ok' => false, 'statements' => $ran, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'statements' => $ran, 'error' => null];
    }

    /**
     * Apply a seed to every student in a class.
     *
     * @return list<array{student_id:string, ok:bool, statements:int, error:?string}>
     */
    public function applyToClass(int $classId, string $script, bool $reset): array
    {
        $results = [];
        foreach ((new ClassService())->studentsInClass($classId) as $student) {
            $outcome = $this->applyToStudent($student, $script, $reset);
            $results[] = [
                'student_id' => (string) $student['student_id'],
                'ok'         => $outcome['ok'],
                'statements' => $outcome['statements'],
                'error'      => $outcome['error'],
            ];
        }
        return $results;
    }

    /** Drop every table in the student's sandbox, FK checks off so order is moot. */
    private function resetTables(PDO $pdo, ExecutionPipeline $pipeline): void
    {
        $tables = (new SchemaInspector($pdo))->tables();
        if ($tables === []) {
            return;
        }
        $pipeline->run('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pipeline->run('DROP TABLE ' . Identifier::quote($table));
        }
        $pipeline->run('SET FOREIGN_KEY_CHECKS = 1');
    }
}
