<?php

declare(strict_types=1);

namespace Dblib\History;

/**
 * Per-session query history — the re-run/tweak stream, like phpMyAdmin's.
 *
 * Lives in the PHP session: ephemeral, scoped to one login, never written to
 * the database. This is deliberately NOT the "persistent server-side audit log"
 * the design rules out — it vanishes when the session ends and no teacher can
 * see it. Console runs and GUI builder actions both record here, so the student
 * sees one uniform stream of runnable SQL.
 */
final class QueryHistory
{
    private const KEY = 'query_history';
    private const MAX = 100;

    public function record(string $sql, bool $ok, string $info): void
    {
        $sql = trim($sql);
        if ($sql === '') {
            return;
        }

        $_SESSION[self::KEY] ??= [];
        $_SESSION[self::KEY][] = [
            'sql'  => $sql,
            'ok'   => $ok,
            'info' => $info,
            'at'   => date('H:i:s'),
        ];

        // Bound session growth — keep only the most recent MAX entries.
        if (count($_SESSION[self::KEY]) > self::MAX) {
            $_SESSION[self::KEY] = array_slice($_SESSION[self::KEY], -self::MAX);
        }
    }

    /** @return list<array{sql:string,ok:bool,info:string,at:string}> newest first */
    public function all(): array
    {
        return array_reverse($_SESSION[self::KEY] ?? []);
    }

    public function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }
}
