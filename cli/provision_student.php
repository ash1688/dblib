<?php
/**
 * Create a student account AND auto-provision their sandbox (db + scoped user).
 * Mirrors what the teacher "manual user creation" flow will do in the UI.
 * Students log in with their student ID; email is optional.
 *
 *   php cli/provision_student.php --student-id=S1234567 --password=pw123
 *   php cli/provision_student.php --student-id=S1234567 --password=pw --name="Alice Smith" --email=alice@college.ac.uk
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Dblib\Accounts\AccountService;
use Dblib\Accounts\AccountValidationException;
use Dblib\Crypto\CredentialCipher;
use Dblib\Enrollment\StudentEnroller;
use Dblib\Provisioning\Provisioner;
use Dblib\Support\Config;

$opts = getopt('', ['student-id::', 'password::', 'name::', 'email::']);
$studentId = $opts['student-id'] ?? null;

if (!is_string($studentId)) {
    fwrite(STDERR, "Usage: php cli/provision_student.php --student-id=ID [--password=pw] [--name=Name] [--email=a@b.c]\n");
    exit(1);
}
$password = is_string($opts['password'] ?? null) ? $opts['password'] : null;
$name     = is_string($opts['name'] ?? null) ? $opts['name'] : null;
$email    = is_string($opts['email'] ?? null) ? $opts['email'] : null;

$enroller = new StudentEnroller(
    new AccountService(),
    new Provisioner(new CredentialCipher((string) Config::get('crypto.key_file'))),
);

try {
    $result = $enroller->enroll($studentId, $password, $name, $email);
} catch (AccountValidationException $e) {
    fwrite(STDERR, 'Cannot create student: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Student ready: {$studentId}\n";
echo "  database : {$result['db_name']}\n";
echo "  db user  : {$result['db_user']}\n";
echo "  password : {$result['password']}\n";
echo "  login    : {$studentId} / (password above)\n";
