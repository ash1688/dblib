<?php
/**
 * Front controller. Every web request enters here (see .htaccess).
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Dblib\Http\Request;
use Dblib\Http\Router;
use Dblib\Controllers\AccountController;
use Dblib\Controllers\AuthController;
use Dblib\Controllers\DashboardController;
use Dblib\Controllers\ConsoleController;
use Dblib\Controllers\DbController;
use Dblib\Controllers\ExportController;
use Dblib\Controllers\HistoryController;
use Dblib\Controllers\TeacherController;

session_start();

$request = Request::capture();
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
$router->post('/db/create-table',  [DbController::class, 'createTable']);
$router->post('/db/drop-table',    [DbController::class, 'dropTable']);
$router->post('/db/insert',        [DbController::class, 'insert']);
$router->post('/db/update',        [DbController::class, 'update']);
$router->post('/db/delete',        [DbController::class, 'delete']);

$router->post('/export/csv',       [ExportController::class, 'csv']);

$router->get('/history',           [HistoryController::class, 'list']);
$router->get('/history/export',    [HistoryController::class, 'exportSql']);
$router->post('/history/clear',    [HistoryController::class, 'clear']);

$router->get('/teacher',                [TeacherController::class, 'dashboard']);
$router->post('/teacher/class/create',  [TeacherController::class, 'createClass']);
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
