<?php
/** @var string $basePath */
/** @var array<string,mixed> $user */
/** @var array{type:string,message:string}|null $flash */
use Dblib\Support\View;
$title = 'My database · dblib';
$flash = $flash ?? null;
ob_start(); ?>
<section class="card">
    <h1>My database</h1>
    <?php if ($flash !== null): ?>
        <p class="alert <?= $flash['type'] === 'ok' ? 'ok-box' : 'error' ?>"><?= View::e($flash['message']) ?></p>
    <?php endif; ?>
    <p class="muted">Signed in as
        <?= View::e($user['display_name'] ?: $user['student_id']) ?>
        (Student ID: <?= View::e($user['student_id']) ?>).</p>
    <p>Your sandbox is yours to explore. Build tables and rows with the GUI, or
       type SQL by hand — either way you see the exact SQL and its result side by
       side.</p>
    <p class="dash-actions">
        <a class="primary button" href="<?= View::e($basePath) ?>/db">Open my database</a>
        <a class="button ghost" href="<?= View::e($basePath) ?>/console">SQL console</a>
    </p>
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
