<?php
/** @var string $basePath */
/** @var array<string,mixed> $user */
/** @var list<array<string,mixed>> $classes */
/** @var list<array<string,mixed>> $teachers */
/** @var array{type:string,message:string}|null $flash */
use Dblib\Support\View;
$title = 'Teacher · dblib';
ob_start(); ?>
<section class="card">
    <h1>Your classes</h1>
    <?php if ($flash !== null): ?>
        <p class="alert <?= $flash['type'] === 'ok' ? 'ok-box' : 'error' ?>"><?= View::e($flash['message']) ?></p>
    <?php endif; ?>

    <?php if ($classes === []): ?>
        <p class="muted">No classes yet. Create one to start enrolling students.</p>
    <?php else: ?>
        <div class="grid-wrap">
            <table class="grid">
                <thead><tr><th>Class</th><th>Students</th><th>Created</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($classes as $c): ?>
                    <tr>
                        <td><?= View::e($c['name']) ?></td>
                        <td><?= (int) $c['student_count'] ?></td>
                        <td><?= View::e($c['created_at']) ?></td>
                        <td><a href="<?= View::e($basePath) ?>/teacher/class?id=<?= (int) $c['id'] ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Demo database</h2>
    <p class="muted">Your own sandbox to show the class how the workbench works —
        create tables, add rows, run SQL — without touching any student's data.</p>
    <a class="button primary" href="<?= View::e($basePath) ?>/teacher/demo">Open demo database</a>
</section>

<section class="card narrow-form">
    <h2>New class</h2>
    <form method="post" action="<?= View::e($basePath) ?>/teacher/class/create">
        <?= \Dblib\Support\Csrf::field() ?>
        <label>Class name
            <input type="text" name="name" required placeholder="e.g. Databases — Group A">
        </label>
        <button type="submit" class="primary">Create class</button>
    </form>
</section>

<section class="card">
    <h2>Teachers</h2>
    <div class="grid-wrap">
        <table class="grid">
            <thead><tr><th>Email</th><th>Name</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($teachers as $t): ?>
                <tr>
                    <td><?= View::e($t['email']) ?><?= (int) $t['id'] === (int) $user['id'] ? ' <span class="muted">(you)</span>' : '' ?></td>
                    <td><?= View::e($t['display_name'] ?? '') ?: '—' ?></td>
                    <td><?= View::e($t['created_at']) ?></td>
                    <td>
                        <?php if ((int) $t['id'] !== (int) $user['id']): ?>
                            <form method="post" action="<?= View::e($basePath) ?>/teacher/reset-teacher-password" class="inline"
                                  onsubmit="return confirm('Reset the password for <?= View::e($t['email']) ?>? Their current password stops working immediately.');">
                                <?= \Dblib\Support\Csrf::field() ?>
                                <input type="hidden" name="user_id" value="<?= (int) $t['id'] ?>">
                                <button type="submit" class="link">Reset password</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="post" action="<?= View::e($basePath) ?>/teacher/create-teacher" class="narrow-form">
        <h3>New teacher account</h3>
        <?= \Dblib\Support\Csrf::field() ?>
        <label>Email (their login)
            <input type="email" name="email" required placeholder="e.g. nat@college.ac.uk">
        </label>
        <label>Display name
            <input type="text" name="name" placeholder="e.g. Nat">
        </label>
        <label>Password <span class="muted">— leave blank to generate a temporary one</span>
            <input type="password" name="password" autocomplete="new-password" minlength="6" placeholder="min. 6 characters">
        </label>
        <button type="submit" class="primary">Create teacher</button>
    </form>
</section>

<section class="card narrow-form">
    <h2>Change password</h2>
    <form method="post" action="<?= View::e($basePath) ?>/account/password">
        <?= \Dblib\Support\Csrf::field() ?>
        <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
        <label>New password <input type="password" name="new" required autocomplete="new-password"></label>
        <label>Confirm new password <input type="password" name="confirm" required autocomplete="new-password"></label>
        <button type="submit" class="primary">Change password</button>
    </form>
</section>
<?php $content = ob_get_clean();
require __DIR__ . '/layout.php';
