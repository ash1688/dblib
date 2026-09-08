<?php

declare(strict_types=1);

namespace Dblib\History;

use Dblib\Database\MetadataConnection;
use PDO;

/**
 * Per-student command log — every statement they ran, in order, with its
 * outcome. Console runs and GUI builder actions both record here, so the
 * student sees one uniform stream of runnable SQL.
 *
 * Persisted in the metadata DB (query_log) rather than the PHP session, because
 * the log is the student's assessment evidence: it must survive logging out,
 * a session timeout over lunch, and a redeploy mid-lesson. It stays private to
 * the student who wrote it (no teacher view), and it cannot be cleared from
 * the UI, which is what lets it count as evidence.
 */
final class QueryHistory
{
    /** Most recent entries shown in the console pane (the export has everything). */
    private const PANE_LIMIT = 300;

    private ?int $userId;

    public function __construct(?int $userId = null)
    {
        $this->userId = $userId ?? (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
    }

    public function record(string $sql, bool $ok, string $info): void
    {
        $sql = trim($sql);
        if ($sql === '' || $this->userId === null) {
            return;
        }
        MetadataConnection::get()
            ->prepare('INSERT INTO query_log (user_id, sql_text, ok, info) VALUES (?, ?, ?, ?)')
            ->execute([$this->userId, $sql, $ok ? 1 : 0, mb_substr($info, 0, 255)]);
    }

    /**
     * Newest first, capped for the on-screen pane.
     *
     * @return list<array{sql:string,ok:bool,info:string,at:string}>
     */
    public function all(): array
    {
        return $this->fetch('DESC', self::PANE_LIMIT);
    }

    /**
     * Every entry, oldest first: the replayable export.
     *
     * @return list<array{sql:string,ok:bool,info:string,at:string}>
     */
    public function chronological(): array
    {
        return $this->fetch('ASC', null);
    }

    /** @return list<array{sql:string,ok:bool,info:string,at:string}> */
    private function fetch(string $direction, ?int $limit): array
    {
        if ($this->userId === null) {
            return [];
        }
        $sql = "SELECT sql_text, ok, info, ran_at FROM query_log WHERE user_id = ? ORDER BY id {$direction}"
             . ($limit !== null ? ' LIMIT ' . $limit : '');
        $stmt = MetadataConnection::get()->prepare($sql);
        $stmt->execute([$this->userId]);

        return array_map(static fn(array $r): array => [
            'sql'  => (string) $r['sql_text'],
            'ok'   => (bool) $r['ok'],
            'info' => (string) $r['info'],
            'at'   => (string) $r['ran_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
