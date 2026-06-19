<?php

declare(strict_types=1);

namespace Dblib\Enrollment;

use Dblib\Accounts\AccountService;
use Dblib\Provisioning\Provisioner;
use Dblib\Seeds\SeedRunner;
use Dblib\Seeds\SeedService;

/**
 * One operation a teacher actually performs: enroll a student = create the
 * validated account AND provision their sandbox. The CLI, the manual-create
 * form, and the CSV roster import all go through here so a student is born the
 * same way regardless of entry point.
 *
 * When seed services are supplied and the student joins a class, any seeds that
 * class has flagged "apply on enrolment" are loaded into the fresh sandbox.
 */
final class StudentEnroller
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly Provisioner $provisioner,
        private readonly ?SeedService $seeds = null,
        private readonly ?SeedRunner $seedRunner = null,
    ) {
    }

    /**
     * @param string|null $password null/blank → a temporary password is generated
     * @return array{user_id:int, password:string, db_name:string, db_user:string}
     */
    public function enroll(
        string $studentId,
        ?string $password,
        ?string $name = null,
        ?string $email = null,
        ?int $classId = null,
    ): array {
        $password = ($password === null || $password === '') ? self::temporaryPassword() : $password;

        $userId  = $this->accounts->createStudent($studentId, $password, $name, $email, $classId);
        $sandbox = $this->provisioner->provisionForUser($userId, $studentId);

        if ($classId !== null && $this->seeds !== null && $this->seedRunner !== null) {
            foreach ($this->seeds->forEnrol($classId) as $seed) {
                // Best-effort: a fresh sandbox is empty, so load (no reset needed).
                $this->seedRunner->applyToStudent(['id' => $userId], (string) $seed['sql_script'], false);
            }
        }

        return [
            'user_id'  => $userId,
            'password' => $password,
            'db_name'  => $sandbox['db_name'],
            'db_user'  => $sandbox['db_user'],
        ];
    }

    /** Readable temporary password (no ambiguous characters like 0/O, 1/l/I). */
    public static function temporaryPassword(int $length = 9): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
