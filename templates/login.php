<?php
/** @var string $basePath */
/** @var string|null $error */
use Dblib\Support\View;
$title = 'Sign in · dblib';
ob_start(); ?>
<section class="card narrow">
    <h1>Sign in</h1>
    <?php if (!empty($error)): ?>
        <p class="alert error"><?= View::e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= View::e($basePath) ?>/login">
        <?= \Dblib\Support\Csrf::field() ?>
        <label>Student ID or email
            <input type="text" name="identifier" required autofocus
                   autocomplete="username" autocapitalize="none">
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <button type="submit" class="primary">Sign in</button>
    </form>
</section>
<?php $content = ob_get_clean();
require __DIR__ . '/layout.php';
