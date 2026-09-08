<?php
/**
 * Front controller. Every web request enters here (see .htaccess).
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Dblib\Http\Request;
use Dblib\Http\Router;
use Dblib\Support\Csrf;
use Dblib\Controllers\AccountController;
use Dblib\Controllers\AuthController;
use Dblib\Controllers\DashboardController;
use Dblib\Controllers\ConsoleController;
use Dblib\Controllers\DbController;
use Dblib\Controllers\ExportController;
use Dblib\Controllers\HistoryController;
use Dblib\Controllers\TeacherController;

// Harden the session cookie: not readable by JS, not sent cross-site (a second
// layer behind the CSRF tokens), and HTTPS-only when the connection is secure.
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                         || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    'use_strict_mode' => true,
]);

$request = Request::capture();

// CSRF: every state-changing request must carry the session token.
if ($request->method === 'POST' && !Csrf::verify()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden — invalid or missing security token. Reload the page and try again.";
    return;
}

$router  = new Router($request->basePath());

$router->get('/',            [DashboardController::class, 'index']);
$router->get('/login',       [AuthController::class, 'showLogin']);
$router->post('/login',      [AuthController::class, 'login']);
$router->post('/logout',     [AuthController::class, 'logout']);
$router->post('/account/password', [AccountController::class, 'changePassword']);

$router->get('/console',     [ConsoleController::class, 'show']);
$router->post('/console/run',[ConsoleController::class, 'run']);

// GUI workbench (student) — every builder action funnels through the pipeline.
$router->get('/db',                [DbController::class, 'workbench']);
$router->get('/db/tables',         [DbController::class, 'tables']);
$router->get('/db/table',          [DbController::class, 'table']);
$router->get('/db/columns',        [DbController::class, 'columns']);
$router->post('/db/create-table',  [DbController::class, 'createTable']);
$router->post('/db/drop-table',    [DbController::class, 'dropTable']);
$router->post('/db/insert',        [DbController::class, 'insert']);
$router->post('/db/update',        [DbController::class, 'update']);
$router->post('/db/delete',        [DbController::class, 'delete']);
$router->post('/db/add-foreign-key',  [DbController::class, 'addForeignKey']);
$router->post('/db/drop-foreign-key', [DbController::class, 'dropForeignKey']);
$router->post('/db/run-sql',          [DbController::class, 'runSql']);

$router->post('/export/csv',       [ExportController::class, 'csv']);
$router->post('/export/sql',       [ExportController::class, 'sqlDump']);

$router->get('/history',           [HistoryController::class, 'list']);
$router->get('/history/export',    [HistoryController::class, 'exportSql']);
$router->post('/history/clear',    [HistoryController::class, 'clear']);

$router->get('/teacher',                [TeacherController::class, 'dashboard']);
$router->get('/teacher/demo',           [TeacherController::class, 'demo']);
$router->post('/teacher/class/create',  [TeacherController::class, 'createClass']);
$router->post('/teacher/create-teacher',[TeacherController::class, 'createTeacher']);
$router->post('/teacher/reset-teacher-password', [TeacherController::class, 'resetTeacherPassword']);
$router->post('/teacher/backup',        [TeacherController::class, 'backup']);
$router->get('/teacher/class',          [TeacherController::class, 'showClass']);
$router->post('/teacher/student/create',[TeacherController::class, 'createStudent']);
$router->post('/teacher/student/import',[TeacherController::class, 'importRoster']);
$router->post('/teacher/student/delete',[TeacherController::class, 'deleteStudent']);
$router->post('/teacher/student/reset-password', [TeacherController::class, 'resetStudentPassword']);
$router->get('/teacher/student/console',     [TeacherController::class, 'studentConsole']);
$router->post('/teacher/student/console/run',[TeacherController::class, 'runStudentSql']);
$router->post('/teacher/seed/create',   [TeacherController::class, 'createSeed']);
$router->post('/teacher/seed/apply',    [TeacherController::class, 'applySeed']);
$router->post('/teacher/seed/delete',   [TeacherController::class, 'deleteSeed']);

$router->dispatch($request);
