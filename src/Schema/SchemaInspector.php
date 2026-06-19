<?php

declare(strict_types=1);

namespace Dblib\Schema;

use Dblib\Sql\Identifier;
use PDO;

/**
 * Read-only introspection of the student's own sandbox: table list, column
 * metadata, primary keys, row counts. Used to drive the GUI (build insert/edit
 * forms, locate the PK for edit/delete, paginate). These are app-internal
 * reads, kept separate from the evidence pipeline that students' actions flow
 * through.
 */
final class SchemaInspector
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> */
    public function tables(): array
    {
        $rows = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
        return array_map(static fn(array $r): string => (string) $r[0], $rows);
    }

    /**
     * @return list<array{name:string,type:string,nullable:bool,key:string,default:?string,extra:string}>
     */
    public function columns(string $table): array
    {
        $rows = $this->pdo->query('SHOW COLUMNS FROM ' . Identifier::quote($table))
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $r): array => [
            'name'     => (string) $r['Field'],
            'type'     => (string) $r['Type'],
            'nullable' => $r['Null'] === 'YES',
            'key'      => (string) $r['Key'],
            'default'  => $r['Default'] !== null ? (string) $r['Default'] : null,
            'extra'    => (string) $r['Extra'],
        ], $rows);
    }

    public function rowCount(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . Identifier::quote($table))->fetchColumn();
    }
}
