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

    /**
     * Foreign keys defined on the table, with their referential actions.
     * information_schema only shows the student's own schema, so this stays
     * within the sandbox like the other reads here.
     *
     * @return list<array{constraint:string,column:string,refTable:string,refColumn:string,onDelete:string,onUpdate:string}>
     */
    public function foreignKeys(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                    rc.DELETE_RULE, rc.UPDATE_RULE
             FROM information_schema.KEY_COLUMN_USAGE kcu
             JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME  = kcu.CONSTRAINT_NAME
             WHERE kcu.TABLE_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION'
        );
        $stmt->execute([$table]);

        return array_map(static fn(array $r): array => [
            'constraint' => (string) $r['CONSTRAINT_NAME'],
            'column'     => (string) $r['COLUMN_NAME'],
            'refTable'   => (string) $r['REFERENCED_TABLE_NAME'],
            'refColumn'  => (string) $r['REFERENCED_COLUMN_NAME'],
            'onDelete'   => (string) $r['DELETE_RULE'],
            'onUpdate'   => (string) $r['UPDATE_RULE'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function rowCount(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . Identifier::quote($table))->fetchColumn();
    }
}
