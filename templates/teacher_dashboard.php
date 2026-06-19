<?php
/** @var string $basePath */
/** @var array<string,mixed> $user */
/** @var list<array<string,mixed>> $classes */
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

<section class="card narrow-form">
    <h2>New class</h2>
    <form method="post" action="<?= View::e($basePath) ?>/teacher/class/create">
        <label>Class name
            <input type="text" name="name" required placeholder="e.g. Databases — Group A">
        </label>
        <button type="submit" class="primary">Create class</button>
    </form>
</section>

<section class="card narrow-form">
    <h2>Change password</h2>
    <form method="post" action="<?= View::e($basePath) ?>/account/password">
        <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
        <label>New password <input type="password" name="new" required autocomplete="new-password"></label>
        <label>Confirm new password <input type="password" name="confirm" required autocomplete="new-password"></label>
        <button type="submit" class="primary">Change password</button>
    </form>
</section>
<?php $content = ob_get_clean();
require __DIR__ . '/layout.php';
