<?php

declare(strict_types=1);

namespace Dblib\Controllers;

use Dblib\Accounts\AccountService;
use Dblib\Accounts\AccountValidationException;
use Dblib\Auth\Auth;
use Dblib\Backup\BackupService;
use Dblib\Classroom\ClassService;
use Dblib\Crypto\CredentialCipher;
use Dblib\Database\MetadataConnection;
use Dblib\Enrollment\StudentEnroller;
use Dblib\Http\Request;
use Dblib\Http\Response;
use Dblib\Provisioning\Provisioner;
use Dblib\Sandbox\SandboxConnector;
use Dblib\Seeds\SeedRunner;
use Dblib\Seeds\SeedService;
use Dblib\Sql\ExecutionPipeline;
use Dblib\Sql\SqlException;
use Dblib\Support\Config;
use Dblib\Support\View;

/**
 * Teacher workspace: manage classes, create students manually, and import a
 * roster CSV. Student creation (single or bulk) funnels through StudentEnroller.
 */
final class TeacherController
{
    private ClassService $classes;
    private SeedService $seeds;

    public function __construct()
    {
        $this->classes = new ClassService();
        $this->seeds = new SeedService();
    }

    public function dashboard(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        return Response::html(View::render('teacher_dashboard', [
            'basePath' => $request->basePath(),
            'user'     => $teacher,
            'classes'  => $this->classes->forTeacher((int) $teacher['id']),
            'teachers' => (new AccountService())->teachers(),
            'flash'    => $this->takeFlash(),
        ]));
    }

    public function createClass(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            $this->flash('error', 'Class name cannot be empty.');
            return Response::redirect($request->basePath() . '/teacher');
        }

        $classId = $this->classes->create($name, (int) $teacher['id']);
        $this->flash('ok', "Class \"{$name}\" created.");
        return Response::redirect($request->basePath() . '/teacher/class?id=' . $classId);
    }

    public function showClass(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $class = $this->classes->findForTeacher((int) $request->input('id', '0'), (int) $teacher['id']);
        if ($class === null) {
            return Response::html(View::render('layout_error', [
                'basePath' => $request->basePath(),
                'user'     => $teacher,
                'message'  => 'Class not found (or not one of yours).',
            ]), 404);
        }

        return Response::html(View::render('teacher_class', [
            'basePath'    => $request->basePath(),
            'user'        => $teacher,
            'class'       => $class,
            'students'    => $this->classes->studentsInClass((int) $class['id']),
            'seeds'       => $this->seeds->forClass((int) $class['id']),
            'flash'       => $this->takeFlash(),
            'import'      => $this->takeImportResults(),
            'seedResults' => $this->takeSeedResults(),
        ]));
    }

    public function createSeed(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $classId = (int) $request->input('class_id', '0');
        $class = $this->classes->findForTeacher($classId, (int) $teacher['id']);
        if ($class === null) {
            return Response::redirect($request->basePath() . '/teacher');
        }

        $name   = trim((string) $request->input('name', ''));
        $script = (string) $request->input('script', '');
        $onEnrol = $request->input('apply_on_enrol', '') !== '';

        if ($name === '' || trim($script) === '') {
            $this->flash('error', 'A seed needs a name and a SQL script.');
        } else {
            $this->seeds->create($classId, $name, $script, $onEnrol);
            $this->flash('ok', "Seed \"{$name}\" saved.");
        }
        return Response::redirect($request->basePath() . '/teacher/class?id=' . $classId);
    }

    public function applySeed(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $seed = $this->seeds->findForTeacher((int) $request->input('seed_id', '0'), (int) $teacher['id']);
        if ($seed === null) {
            return Response::redirect($request->basePath() . '/teacher');
        }

        // Teacher chooses the mode: reset-then-load (default) or append.
        $reset = $request->input('mode', 'reset') !== 'append';
        $runner = new SeedRunner(new SandboxConnector());
        $_SESSION['seed_results'] = [
            'seed'    => $seed['name'],
            'reset'   => $reset,
            'rows'    => $runner->applyToClass((int) $seed['class_id'], (string) $seed['sql_script'], $reset),
        ];
        return Response::redirect($request->basePath() . '/teacher/class?id=' . (int) $seed['class_id']);
    }

    public function deleteSeed(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $seed = $this->seeds->findForTeacher((int) $request->input('seed_id', '0'), (int) $teacher['id']);
        if ($seed !== null) {
            $this->seeds->delete((int) $seed['id']);
            $this->flash('ok', "Seed \"{$seed['name']}\" deleted.");
            return Response::redirect($request->basePath() . '/teacher/class?id=' . (int) $seed['class_id']);
        }
        return Response::redirect($request->basePath() . '/teacher');
    }

    /**
     * Create a colleague's teacher account. Any signed-in teacher can do this
     * (small-department trust model). Refuses an email that's already taken —
     * AccountService::createTeacher() upserts, and this path must never let one
     * teacher quietly reset another's password.
     */
    public function createTeacher(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $email    = trim((string) $request->input('email', ''));
        $name     = trim((string) $request->input('name', '')) ?: null;
        $password = (string) $request->input('password', '');

        $accounts = new AccountService();
        if ($accounts->emailInUse($email)) {
            $this->flash('error', "An account with the email {$email} already exists.");
            return Response::redirect($request->basePath() . '/teacher');
        }

        $generated = $password === '';
        if ($generated) {
            $password = StudentEnroller::temporaryPassword();
        }

        try {
            $accounts->createTeacher($email, $password, $name);
            $shown = $generated
                ? " Temporary password: {$password} — give it to them now; it isn't shown again."
                : '';
            $this->flash('ok', "Teacher account {$email} created.{$shown}");
        } catch (AccountValidationException $e) {
            $this->flash('error', 'Could not create the account: ' . $e->getMessage());
        } catch (\PDOException $e) {
            $this->flash('error', 'Database error while creating the account.');
        }

        return Response::redirect($request->basePath() . '/teacher');
    }

    /**
     * Reset a colleague's password to a fresh temporary one (the recovery path
     * when a teacher forgets theirs). Your own password changes go through the
     * Change password form — resetting yourself to a random temp is refused.
     */
    public function resetTeacherPassword(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $targetId = (int) $request->input('user_id', '0');
        if ($targetId === (int) $teacher['id']) {
            $this->flash('error', 'Use the Change password form for your own account.');
            return Response::redirect($request->basePath() . '/teacher');
        }

        $stmt = MetadataConnection::get()->prepare(
            "SELECT id, email FROM users WHERE id = ? AND role = 'teacher' LIMIT 1"
        );
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();

        if ($target === false) {
            $this->flash('error', 'Teacher account not found.');
        } else {
            $temp = StudentEnroller::temporaryPassword();
            (new AccountService())->setPassword((int) $target['id'], $temp);
            $this->flash('ok', "New temporary password for {$target['email']}: {$temp} — give it to them now; it isn't shown again.");
        }

        return Response::redirect($request->basePath() . '/teacher');
    }

    /**
     * Full-service backup download: every database plus the credential key,
     * bundled for cli/restore_backup.php. Because the bundle contains the key,
     * the teacher must re-enter their password. A session left open on a
     * classroom PC must not be enough to walk off with everything.
     */
    public function backup(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $password = (string) $request->input('password', '');
        if ($password === '' || !password_verify($password, (string) $teacher['password_hash'])) {
            $this->flash('error', 'Backup not downloaded: that password did not match your account.');
            return Response::redirect($request->basePath() . '/teacher');
        }

        try {
            $bundle = (new BackupService())->create();
        } catch (\Throwable $e) {
            error_log('dblib backup failed: ' . $e->getMessage());
            $this->flash('error', 'Backup failed on the server. The error has been logged.');
            return Response::redirect($request->basePath() . '/teacher');
        }

        return Response::download($bundle['bytes'], $bundle['filename'], 'application/gzip');
    }

    public function createStudent(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $classId = (int) $request->input('class_id', '0');
        $class = $this->classes->findForTeacher($classId, (int) $teacher['id']);
        if ($class === null) {
            return Response::redirect($request->basePath() . '/teacher');
        }

        $studentId = trim((string) $request->input('student_id', ''));
        $name      = trim((string) $request->input('name', '')) ?: null;
        $email     = trim((string) $request->input('email', '')) ?: null;
        $password  = (string) $request->input('password', '');

        try {
            $result = $this->enroller()->enroll($studentId, $password, $name, $email, $classId);
            $shown  = $password === '' ? " Temporary password: {$result['password']}" : '';
            $this->flash('ok', "Student {$studentId} enrolled.{$shown}");
        } catch (AccountValidationException $e) {
            $this->flash('error', "Could not enroll {$studentId}: " . $e->getMessage());
        } catch (\PDOException $e) {
            $this->flash('error', $this->humanizeDbError($e, $studentId));
        }

        return Response::redirect($request->basePath() . '/teacher/class?id=' . $classId);
    }

    public function importRoster(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $classId = (int) $request->input('class_id', '0');
        $class = $this->classes->findForTeacher($classId, (int) $teacher['id']);
        if ($class === null) {
            return Response::redirect($request->basePath() . '/teacher');
        }

        $file = $_FILES['roster'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flash('error', 'No CSV file uploaded (or the upload failed).');
            return Response::redirect($request->basePath() . '/teacher/class?id=' . $classId);
        }

        $results = $this->processRoster((string) $file['tmp_name'], $classId);
        $_SESSION['import_results'] = $results;
        return Response::redirect($request->basePath() . '/teacher/class?id=' . $classId);
    }

    public function deleteStudent(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $userId  = (int) $request->input('user_id', '0');
        $classId = (int) $request->input('class_id', '0');

        // Authorize: the student must sit in a class this teacher owns.
        $stmt = MetadataConnection::get()->prepare(
            'SELECT u.id, u.student_id FROM users u
             JOIN classes c ON c.id = u.class_id
             WHERE u.id = ? AND u.role = "student" AND c.teacher_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, (int) $teacher['id']]);
        $student = $stmt->fetch();

        if ($student !== false) {
            (new Provisioner($this->cipher()))->deprovisionForUser($userId);
            (new AccountService())->deleteByUserId($userId);
            $this->flash('ok', "Removed student {$student['student_id']}.");
        } else {
            $this->flash('error', 'Student not found in your classes.');
        }

        return Response::redirect($request->basePath() . '/teacher/class?id=' . $classId);
    }

    public function resetStudentPassword(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $classId = (int) $request->input('class_id', '0');
        $student = $this->ownedStudent((int) $request->input('user_id', '0'), (int) $teacher['id']);
        if ($student === null) {
            return Response::redirect($request->basePath() . '/teacher');
        }

        $temp = StudentEnroller::temporaryPassword();
        (new AccountService())->setPassword((int) $student['id'], $temp);
        $this->flash('ok', "New temporary password for {$student['student_id']}: {$temp} — give it to them now; it isn't shown again.");

        return Response::redirect($request->basePath() . '/teacher/class?id=' . $classId);
    }

    /**
     * The teacher's personal demo sandbox: a database of their own (provisioned
     * exactly like a student's, scoped MySQL user and all) so they can show the
     * class how the workbench works without touching a real student's data.
     *
     * Provisions on first use, then hands off to the same GUI workbench students
     * use — the teacher reaches it by targeting their own user id.
     */
    public function demo(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $teacherId = (int) $teacher['id'];

        // Provision once. Re-provisioning would rotate the stored password out of
        // sync with the (unchanged) MySQL user, so only do it when absent.
        if (!$this->hasSandbox($teacherId)) {
            try {
                (new Provisioner($this->cipher()))->provisionForUser($teacherId, 'demo');
            } catch (\PDOException $e) {
                $this->flash('error', 'Could not set up your demo database. Try again.');
                return Response::redirect($request->basePath() . '/teacher');
            }
        }

        return Response::redirect($request->basePath() . '/db?user_id=' . $teacherId);
    }

    /**
     * Open a SQL console onto one of the teacher's students' sandboxes — the
     * "fix it when the student can't" capability. Runs as the student's own
     * scoped user, so it's full admin over that one database and nothing else.
     */
    public function studentConsole(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::redirect($request->basePath() . '/login');
        }

        $student = $this->ownedStudent((int) $request->input('user_id', '0'), (int) $teacher['id']);
        if ($student === null) {
            return Response::html(View::render('layout_error', [
                'basePath' => $request->basePath(),
                'user'     => $teacher,
                'message'  => 'Student not found (or not in one of your classes).',
            ]), 404);
        }

        return Response::html(View::render('teacher_console', [
            'basePath' => $request->basePath(),
            'user'     => $teacher,
            'student'  => $student,
        ]));
    }

    public function runStudentSql(Request $request): Response
    {
        $teacher = $this->teacher();
        if ($teacher === null) {
            return Response::json(['error' => 'Not authenticated.'], 401);
        }

        $student = $this->ownedStudent((int) $request->input('user_id', '0'), (int) $teacher['id']);
        if ($student === null) {
            return Response::json(['error' => 'Student not found in your classes.'], 403);
        }

        $sql = (string) $request->input('sql', '');
        try {
            $pdo = (new SandboxConnector())->connect($student);
            $result = (new ExecutionPipeline($pdo))->run($sql);
        } catch (SqlException $e) {
            return Response::json(['error' => $e->getMessage(), 'sql' => trim($sql)]);
        }

        return Response::json([
            'sql'          => $result->sql,
            'isResultSet'  => $result->isResultSet,
            'columns'      => $result->columns,
            'rows'         => $result->rows,
            'affectedRows' => $result->affectedRows,
            'durationMs'   => round($result->durationMs, 2),
        ]);
    }

    // ---- helpers ------------------------------------------------------------

    /** Has a sandbox already been provisioned for this user id? */
    private function hasSandbox(int $userId): bool
    {
        $stmt = MetadataConnection::get()->prepare(
            'SELECT 1 FROM student_sandboxes WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Load a student row only if they sit in a class this teacher owns.
     *
     * @return array<string,mixed>|null
     */
    private function ownedStudent(int $userId, int $teacherId): ?array
    {
        $stmt = MetadataConnection::get()->prepare(
            'SELECT u.* FROM users u
             JOIN classes c ON c.id = u.class_id
             WHERE u.id = ? AND u.role = "student" AND c.teacher_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $teacherId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Parse and enroll one CSV roster. Expected header columns (case/spacing
     * insensitive): student_id (required), name, email, password (optional).
     *
     * @return list<array{student_id:string, status:string, detail:string}>
     */
    private function processRoster(string $tmpPath, int $classId): array
    {
        $handle = fopen($tmpPath, 'rb');
        if ($handle === false) {
            return [['student_id' => '', 'status' => 'error', 'detail' => 'Could not read the uploaded file.']];
        }

        $map = null;            // header name => column index
        $results = [];
        $accounts = new AccountService();
        $enroller = $this->enroller();
        $rowNum = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if ($map === null) {
                $map = $this->headerMap($row);
                if (!isset($map['student_id'])) {
                    fclose($handle);
                    return [['student_id' => '', 'status' => 'error',
                             'detail' => 'CSV needs a header row with at least a "student_id" column.']];
                }
                continue;
            }
            if ($this->rowIsBlank($row)) {
                continue;
            }

            $studentId = trim((string) ($row[$map['student_id']] ?? ''));
            $name      = isset($map['name'])     ? (trim((string) ($row[$map['name']] ?? '')) ?: null) : null;
            $email     = isset($map['email'])    ? (trim((string) ($row[$map['email']] ?? '')) ?: null) : null;
            $password  = isset($map['password']) ? (string) ($row[$map['password']] ?? '') : '';

            if ($studentId === '') {
                $results[] = ['student_id' => "(row {$rowNum})", 'status' => 'skipped', 'detail' => 'Blank student_id.'];
                continue;
            }
            if ($accounts->findStudentByStudentId($studentId) !== null) {
                $results[] = ['student_id' => $studentId, 'status' => 'skipped', 'detail' => 'Already exists — left unchanged.'];
                continue;
            }

            try {
                $r = $enroller->enroll($studentId, $password, $name, $email, $classId);
                $results[] = ['student_id' => $studentId, 'status' => 'created',
                              'detail' => 'Password: ' . $r['password']];
            } catch (AccountValidationException $e) {
                $results[] = ['student_id' => $studentId, 'status' => 'error', 'detail' => $e->getMessage()];
            } catch (\PDOException $e) {
                $results[] = ['student_id' => $studentId, 'status' => 'error', 'detail' => $this->humanizeDbError($e, $studentId)];
            }
        }
        fclose($handle);

        if ($results === []) {
            $results[] = ['student_id' => '', 'status' => 'error', 'detail' => 'No data rows found in the CSV.'];
        }
        return $results;
    }

    /** @param list<string> $header @return array<string,int> */
    private function headerMap(array $header): array
    {
        $aliases = [
            'student_id' => 'student_id', 'studentid' => 'student_id', 'student id' => 'student_id', 'id' => 'student_id',
            'name' => 'name', 'display_name' => 'name', 'full name' => 'name',
            'email' => 'email', 'e-mail' => 'email',
            'password' => 'password', 'pass' => 'password',
        ];
        $map = [];
        foreach ($header as $index => $label) {
            $key = strtolower(trim((string) $label));
            if (isset($aliases[$key])) {
                $map[$aliases[$key]] = $index;
            }
        }
        return $map;
    }

    /** @param list<string> $row */
    private function rowIsBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }

    private function humanizeDbError(\PDOException $e, string $studentId): string
    {
        if (str_contains($e->getMessage(), 'uq_users_email')) {
            return "Email already in use (student {$studentId}).";
        }
        if (str_contains($e->getMessage(), 'Duplicate')) {
            return "Duplicate value for student {$studentId}.";
        }
        return "Database error for student {$studentId}.";
    }

    private function enroller(): StudentEnroller
    {
        // Wired with seed services so apply-on-enrolment seeds load into new sandboxes.
        return new StudentEnroller(
            new AccountService(),
            new Provisioner($this->cipher()),
            $this->seeds,
            new SeedRunner(new SandboxConnector()),
        );
    }

    private function cipher(): CredentialCipher
    {
        return new CredentialCipher((string) Config::get('crypto.key_file'));
    }

    /** @return array<string,mixed>|null */
    private function teacher(): ?array
    {
        $user = (new Auth())->user();
        return ($user !== null && $user['role'] === Auth::ROLE_TEACHER) ? $user : null;
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    /** @return array{type:string,message:string}|null */
    private function takeFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return is_array($flash) ? $flash : null;
    }

    /** @return list<array{student_id:string,status:string,detail:string}>|null */
    private function takeImportResults(): ?array
    {
        $results = $_SESSION['import_results'] ?? null;
        unset($_SESSION['import_results']);
        return is_array($results) ? $results : null;
    }

    /** @return array{seed:string,reset:bool,rows:list<array<string,mixed>>}|null */
    private function takeSeedResults(): ?array
    {
        $results = $_SESSION['seed_results'] ?? null;
        unset($_SESSION['seed_results']);
        return is_array($results) ? $results : null;
    }
}
