<?php

declare(strict_types=1);

namespace Dblib\Seeds;

use Dblib\Database\MetadataConnection;
use PDO;

/**
 * CRUD for seed scripts. A seed belongs to a class; reads that a teacher can act
 * on are scoped through the owning class's teacher_id so one teacher can't touch
 * another's seeds.
 */
final class SeedService
{
    private function db(): PDO
    {
        return MetadataConnection::get();
    }

    public function create(int $classId, string $name, string $script, bool $applyOnEnrol): int
    {
        $this->db()->prepare(
            'INSERT INTO seeds (class_id, name, sql_script, apply_on_enrol) VALUES (?, ?, ?, ?)'
        )->execute([$classId, $name, $script, $applyOnEnrol ? 1 : 0]);
        return (int) $this->db()->lastInsertId();
    }

    /** @return list<array<string,mixed>> seeds for a class (without the script body) */
    public function forClass(int $classId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, class_id, name, apply_on_enrol, created_at,
                    CHAR_LENGTH(sql_script) AS script_length
             FROM seeds WHERE class_id = ? ORDER BY name'
        );
        $stmt->execute([$classId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null the seed, only if its class belongs to this teacher */
    public function findForTeacher(int $seedId, int $teacherId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT s.* FROM seeds s
             JOIN classes c ON c.id = s.class_id
             WHERE s.id = ? AND c.teacher_id = ? LIMIT 1'
        );
        $stmt->execute([$seedId, $teacherId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> seeds for a class flagged to auto-apply on enrolment */
    public function forEnrol(int $classId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, name, sql_script FROM seeds WHERE class_id = ? AND apply_on_enrol = 1'
        );
        $stmt->execute([$classId]);
        return $stmt->fetchAll();
    }

    public function delete(int $seedId): void
    {
        $this->db()->prepare('DELETE FROM seeds WHERE id = ?')->execute([$seedId]);
    }
}
