<?php

declare(strict_types=1);

namespace Dblib\Accounts;

use Dblib\Database\MetadataConnection;
use PDO;

/**
 * Single validated path for creating accounts. Both the CLIs and (later) the
 * teacher UI go through here, so the role/identity rules are enforced in one
 * place rather than re-checked at every call site:
 *
 *   * a STUDENT must have a student_id (their login); email is optional
 *   * a TEACHER must have an email (their login); they have no student_id
 *
 * A DB CHECK constraint (see migrate.php) backs these same rules at the storage
 * layer as defence in depth.
 */
final class AccountService
{
    private const MIN_PASSWORD_LEN = 6;
    private const MAX_STUDENT_ID_LEN = 32;

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? MetadataConnection::get();
    }

    /**
     * Create (or update the password/name of) a teacher.
     *
     * @throws AccountValidationException
     * @return int the user id
     */
    public function createTeacher(string $email, string $password, ?string $displayName = null): int
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new AccountValidationException('A teacher needs a valid email address.');
        }
        $this->assertPassword($password);

        $this->db()->prepare(
            'INSERT INTO users (email, student_id, password_hash, role, display_name)
             VALUES (:email, NULL, :hash, "teacher", :name)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash),
                                     display_name  = VALUES(display_name)'
        )->execute([
            'email' => $email,
            'hash'  => password_hash($password, PASSWORD_DEFAULT),
            'name'  => $displayName,
        ]);

        return $this->idByColumn('email', $email);
    }

    /**
     * Create (or update the password/name/email of) a student.
     *
     * @throws AccountValidationException
     * @return int the user id
     */
    public function createStudent(
        string $studentId,
        string $password,
        ?string $displayName = null,
        ?string $email = null,
        ?int $classId = null
    ): int {
        $studentId = trim($studentId);
        if ($studentId === '') {
            throw new AccountValidationException('A student needs a student ID.');
        }
        if (strlen($studentId) > self::MAX_STUDENT_ID_LEN) {
            throw new AccountValidationException(
                'Student ID must be at most ' . self::MAX_STUDENT_ID_LEN . ' characters.'
            );
        }
        $this->assertPassword($password);

        $email = $email !== null ? trim($email) : null;
        if ($email === '') {
            $email = null;
        }
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new AccountValidationException('That email address is not valid.');
        }

        $this->db()->prepare(
            'INSERT INTO users (student_id, email, password_hash, role, display_name, class_id)
             VALUES (:sid, :email, :hash, "student", :name, :cid)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash),
                                     display_name  = VALUES(display_name),
                                     email          = VALUES(email),
                                     class_id       = VALUES(class_id)'
        )->execute([
            'sid'   => $studentId,
            'email' => $email,
            'hash'  => password_hash($password, PASSWORD_DEFAULT),
            'name'  => $displayName,
            'cid'   => $classId,
        ]);

        return $this->idByColumn('student_id', $studentId);
    }

    /** @return int|null the user id of an existing student, or null */
    public function findStudentByStudentId(string $studentId): ?int
    {
        $stmt = $this->db()->prepare(
            "SELECT id FROM users WHERE role = 'student' AND student_id = ? LIMIT 1"
        );
        $stmt->execute([trim($studentId)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Delete a user row. Its sandbox metadata row cascades; drop the MySQL
     *  objects via Provisioner::deprovisionForUser() first. */
    public function deleteByUserId(int $userId): void
    {
        $this->db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }

    /**
     * Set (reset/change) a user's login password. Only the app-login password
     * changes — the student's MySQL sandbox credential is untouched.
     *
     * @throws AccountValidationException
     */
    public function setPassword(int $userId, string $newPassword): void
    {
        $this->assertPassword($newPassword);
        $this->db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    }

    private function assertPassword(string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LEN) {
            throw new AccountValidationException(
                'Password must be at least ' . self::MIN_PASSWORD_LEN . ' characters.'
            );
        }
    }

    /** Resolve the id after an upsert that updated (not inserted) the row. */
    private function idByColumn(string $column, string $value): int
    {
        $stmt = $this->db()->prepare("SELECT id FROM users WHERE {$column} = ? LIMIT 1");
        $stmt->execute([$value]);
        return (int) $stmt->fetchColumn();
    }
}
