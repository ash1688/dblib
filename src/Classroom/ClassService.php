<?php

declare(strict_types=1);

namespace Dblib\Classroom;

use Dblib\Database\MetadataConnection;
use PDO;

/**
 * Classes and their rosters. One class is owned by one teacher; one student
 * belongs to one class. Teachers only ever see their own classes — every read
 * here is scoped by teacher_id so the controller can't accidentally leak across
 * teachers.
 */
final class ClassService
{
    private function db(): PDO
    {
        return MetadataConnection::get();
    }

    public function create(string $name, int $teacherId): int
    {
        $this->db()->prepare('INSERT INTO classes (name, teacher_id) VALUES (?, ?)')
            ->execute([$name, $teacherId]);
        return (int) $this->db()->lastInsertId();
    }

    /** @return list<array<string,mixed>> classes owned by the teacher, with student counts */
    public function forTeacher(int $teacherId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT c.id, c.name, c.created_at, COUNT(u.id) AS student_count
             FROM classes c
             LEFT JOIN users u ON u.class_id = c.id AND u.role = "student"
             WHERE c.teacher_id = ?
             GROUP BY c.id, c.name, c.created_at
             ORDER BY c.name'
        );
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null the class, only if owned by this teacher */
    public function findForTeacher(int $classId, int $teacherId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, name, teacher_id, created_at FROM classes WHERE id = ? AND teacher_id = ? LIMIT 1'
        );
        $stmt->execute([$classId, $teacherId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> students in the class */
    public function studentsInClass(int $classId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, student_id, email, display_name, created_at
             FROM users WHERE role = "student" AND class_id = ?
             ORDER BY student_id'
        );
        $stmt->execute([$classId]);
        return $stmt->fetchAll();
    }
}
