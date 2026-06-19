<?php
/**
 * Remove a student: tear down their sandbox (drop db + scoped user) and delete
 * the account. Looks the student up by student ID or email.
 *
 *   php cli/delete_student.php --student-id=S1234567
 *   php cli/delete_student.php --email=alice@dblib.local
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Dblib\Accounts\AccountService;
use Dblib\Crypto\CredentialCipher;
use Dblib\Database\MetadataConnection;
use Dblib\Provisioning\Provisioner;
use Dblib\Support\Config;

$opts = getopt('', ['student-id::', 'email::']);
$studentId = is_string($opts['student-id'] ?? null) ? $opts['student-id'] : null;
$email     = is_string($opts['email'] ?? null) ? $opts['email'] : null;

if (($studentId === null || $studentId === '') && ($email === null || $email === '')) {
    fwrite(STDERR, "Usage: php cli/delete_student.php (--student-id=ID | --email=a@b.c)\n");
    exit(1);
}

$pdo = MetadataConnection::get();
if ($studentId !== null && $studentId !== '') {
    $stmt = $pdo->prepare("SELECT id, student_id, email FROM users WHERE role = 'student' AND student_id = ? LIMIT 1");
    $stmt->execute([$studentId]);
} else {
    $stmt = $pdo->prepare("SELECT id, student_id, email FROM users WHERE role = 'student' AND email = ? LIMIT 1");
    $stmt->execute([$email]);
}
$user = $stmt->fetch();

if ($user === false) {
    fwrite(STDERR, "No matching student found.\n");
    exit(1);
}

$cipher = new CredentialCipher((string) Config::get('crypto.key_file'));
$hadSandbox = (new Provisioner($cipher))->deprovisionForUser((int) $user['id']);
(new AccountService())->deleteByUserId((int) $user['id']);

$label = $user['student_id'] ?? $user['email'];
echo "Deleted student {$label}" . ($hadSandbox ? " (sandbox torn down).\n" : " (no sandbox existed).\n");
