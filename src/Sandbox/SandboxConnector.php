<?php

declare(strict_types=1);

namespace Dblib\Sandbox;

use Dblib\Auth\Auth;
use Dblib\Crypto\CredentialCipher;
use Dblib\Database\MetadataConnection;
use Dblib\Database\StudentConnection;
use Dblib\Http\Request;
use Dblib\Sql\SqlException;
use Dblib\Support\Config;
use PDO;

/**
 * Resolves the logged-in student and opens a connection AS them (scoped MySQL
 * user, pinned to their own db). Shared by the SQL console and the GUI
 * workbench so both run against the same per-request, grant-isolated sandbox.
 */
final class SandboxConnector
{
    /** @return array<string,mixed>|null the logged-in student user row */
    public function student(): ?array
    {
        $user = (new Auth())->user();
        return ($user !== null && $user['role'] === Auth::ROLE_STUDENT) ? $user : null;
    }

    /**
     * Resolve which student's sandbox a request targets:
     *   - a logged-in student → their own sandbox;
     *   - a logged-in teacher with ?user_id=X → that student's sandbox, but only
     *     if the student sits in a class the teacher owns.
     * Returns null if nobody is authorised for the target.
     *
     * @return array<string,mixed>|null the target student's user row
     */
    public function resolve(Request $request): ?array
    {
        $user = (new Auth())->user();
        if ($user === null) {
            return null;
        }
        if ($user['role'] === Auth::ROLE_STUDENT) {
            return $user;
        }
        if ($user['role'] === Auth::ROLE_TEACHER) {
            $targetId = (int) $request->input('user_id', '0');
            if ($targetId <= 0) {
                return null;
            }
            $stmt = MetadataConnection::get()->prepare(
                'SELECT u.* FROM users u
                 JOIN classes c ON c.id = u.class_id
                 WHERE u.id = ? AND u.role = "student" AND c.teacher_id = ? LIMIT 1'
            );
            $stmt->execute([$targetId, $user['id']]);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $student
     * @throws SqlException if the student has no provisioned sandbox
     */
    public function connect(array $student): PDO
    {
        $stmt = MetadataConnection::get()->prepare(
            'SELECT db_name, db_user, cred_ciphertext FROM student_sandboxes WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$student['id']]);
        $sandbox = $stmt->fetch();

        if ($sandbox === false) {
            throw new SqlException('Your sandbox has not been provisioned yet. Ask your teacher.');
        }

        $cipher   = new CredentialCipher((string) Config::get('crypto.key_file'));
        $password = $cipher->decrypt($sandbox['cred_ciphertext']);

        return StudentConnection::open($sandbox['db_user'], $password, $sandbox['db_name']);
    }
}
