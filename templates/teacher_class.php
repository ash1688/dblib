<?php
/** @var string $basePath */
/** @var array<string,mixed> $user */
/** @var array<string,mixed> $class */
/** @var list<array<string,mixed>> $students */
/** @var array{type:string,message:string}|null $flash */
/** @var list<array{student_id:string,status:string,detail:string}>|null $import */
/** @var list<array<string,mixed>> $seeds */
/** @var array{seed:string,reset:bool,rows:list<array<string,mixed>>}|null $seedResults */
use Dblib\Support\View;
$title = View::e($class['name']) . ' · dblib';
$cid = (int) $class['id'];
ob_start(); ?>
<p class="muted"><a href="<?= View::e($basePath) ?>/teacher">← All classes</a></p>
<section class="card">
    <h1><?= View::e($class['name']) ?></h1>

    <?php if ($flash !== null): ?>
        <p class="alert <?= $flash['type'] === 'ok' ? 'ok-box' : 'error' ?>"><?= View::e($flash['message']) ?></p>
    <?php endif; ?>

    <?php if ($import !== null): ?>
        <h2>Import results</h2>
        <div class="grid-wrap">
            <table class="grid">
                <thead><tr><th>Student ID</th><th>Status</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($import as $r): ?>
                    <tr>
                        <td><?= View::e($r['student_id']) ?></td>
                        <td><span class="badge badge-<?= View::e($r['status']) ?>"><?= View::e($r['status']) ?></span></td>
                        <td><?= View::e($r['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted">Copy any temporary passwords now — they are shown once.</p>
    <?php endif; ?>

    <h2>Students (<?= count($students) ?>)</h2>
    <?php if ($students === []): ?>
        <p class="muted">No students in this class yet.</p>
    <?php else: ?>
        <div class="grid-wrap">
            <table class="grid">
                <thead><tr><th>Student ID</th><th>Name</th><th>Email</th><th>Created</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($students as $s): ?>
                    <tr>
                        <td><?= View::e($s['student_id']) ?></td>
                        <td><?= View::e($s['display_name']) ?></td>
                        <td><?= View::e($s['email']) ?></td>
                        <td><?= View::e($s['created_at']) ?></td>
                        <td class="wb-row-actions">
                            <a class="link" href="<?= View::e($basePath) ?>/db?user_id=<?= (int) $s['id'] ?>">Open DB</a>
                            <form method="post" action="<?= View::e($basePath) ?>/teacher/student/reset-password"
                                  class="inline" onsubmit="return confirm('Reset <?= View::e($s['student_id']) ?>\'s password to a new temporary one?');">
                                <input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
                                <input type="hidden" name="class_id" value="<?= $cid ?>">
                                <button type="submit" class="link">Reset pw</button>
                            </form>
                            <form method="post" action="<?= View::e($basePath) ?>/teacher/student/delete"
                                  class="inline" onsubmit="return confirm('Remove <?= View::e($s['student_id']) ?> and delete their sandbox?');">
                                <input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
                                <input type="hidden" name="class_id" value="<?= $cid ?>">
                                <button type="submit" class="link danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="two-col">
    <section class="card">
        <h2>Add a student</h2>
        <form method="post" action="<?= View::e($basePath) ?>/teacher/student/create">
            <input type="hidden" name="class_id" value="<?= $cid ?>">
            <label>Student ID *
                <input type="text" name="student_id" required autocapitalize="none">
            </label>
            <label>Name
                <input type="text" name="name">
            </label>
            <label>Email (optional)
                <input type="email" name="email">
            </label>
            <label>Password (blank = generate)
                <input type="text" name="password" autocomplete="off">
            </label>
            <button type="submit" class="primary">Enrol student</button>
        </form>
    </section>

    <section class="card">
        <h2>Import roster (CSV)</h2>
        <p class="muted">Header row with <code>student_id</code> (required), and
           optionally <code>name</code>, <code>email</code>, <code>password</code>.
           Missing passwords are generated. Existing IDs are skipped.</p>
        <form method="post" action="<?= View::e($basePath) ?>/teacher/student/import" enctype="multipart/form-data">
            <input type="hidden" name="class_id" value="<?= $cid ?>">
            <label>CSV file
                <input type="file" name="roster" accept=".csv,text/csv" required>
            </label>
            <button type="submit" class="primary">Import</button>
        </form>
    </section>
</div>

<section class="card">
    <h2>Seeds (starter datasets)</h2>
    <p class="muted">A seed is a SQL script (schema + sample data) you push to the
       whole class. Default is reset-then-load: each student's tables are dropped,
       then the script runs — so everyone gets an identical dataset.</p>

    <?php if ($seedResults !== null): ?>
        <h3>Applied “<?= View::e($seedResults['seed']) ?>”
            <?= $seedResults['reset'] ? '(reset-then-load)' : '(load only)' ?></h3>
        <div class="grid-wrap">
            <table class="grid">
                <thead><tr><th>Student ID</th><th>Result</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($seedResults['rows'] as $r): ?>
                    <tr>
                        <td><?= View::e($r['student_id']) ?></td>
                        <td><span class="badge badge-<?= $r['ok'] ? 'created' : 'error' ?>">
                            <?= $r['ok'] ? 'ok' : 'error' ?></span></td>
                        <td><?= $r['ok']
                            ? View::e($r['statements']) . ' statement(s) run'
                            : View::e($r['error']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($seeds === []): ?>
        <p class="muted">No seeds for this class yet.</p>
    <?php else: ?>
        <div class="grid-wrap">
            <table class="grid">
                <thead><tr><th>Name</th><th>Size</th><th>On enrol</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($seeds as $s): ?>
                    <tr>
                        <td><?= View::e($s['name']) ?></td>
                        <td><?= (int) $s['script_length'] ?> chars</td>
                        <td><?= $s['apply_on_enrol'] ? 'yes' : '—' ?></td>
                        <td class="wb-row-actions">
                            <form method="post" action="<?= View::e($basePath) ?>/teacher/seed/apply" class="inline">
                                <input type="hidden" name="seed_id" value="<?= (int) $s['id'] ?>">
                                <label class="inline-check"><input type="checkbox" name="reset" value="1" checked> reset</label>
                                <button type="submit" class="link"
                                    onclick="return confirm('Apply “<?= View::e($s['name']) ?>” to all <?= count($students) ?> student(s)?');">Apply to class</button>
                            </form>
                            <form method="post" action="<?= View::e($basePath) ?>/teacher/seed/delete" class="inline"
                                  onsubmit="return confirm('Delete this seed?');">
                                <input type="hidden" name="seed_id" value="<?= (int) $s['id'] ?>">
                                <button type="submit" class="link danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h3>New seed</h3>
    <form method="post" action="<?= View::e($basePath) ?>/teacher/seed/create">
        <input type="hidden" name="class_id" value="<?= $cid ?>">
        <label>Name
            <input type="text" name="name" required placeholder="e.g. Library schema v1">
        </label>
        <label>SQL script (multiple statements, separated by <code>;</code>)
            <textarea name="script" rows="8" spellcheck="false"
                placeholder="CREATE TABLE books (...);&#10;INSERT INTO books VALUES (...);"></textarea>
        </label>
        <label class="inline-check">
            <input type="checkbox" name="apply_on_enrol" value="1"> Apply automatically to new students on enrolment
        </label>
        <button type="submit" class="primary">Save seed</button>
    </form>
</section>
<?php $content = ob_get_clean();
require __DIR__ . '/layout.php';
