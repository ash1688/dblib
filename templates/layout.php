<?php
/** @var string $basePath */
/** @var string $title */
/** @var string $content */
/** @var array<string,mixed>|null $user */
use Dblib\Support\View;
$user = $user ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= View::e(\Dblib\Support\Csrf::token()) ?>">
    <title><?= View::e($title ?? 'dblib') ?></title>
    <link rel="stylesheet" href="<?= View::e($basePath) ?>/assets/css/app.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= View::e($basePath) ?>/">dblib</a>
    <?php if ($user !== null): ?>
        <nav>
            <span class="who"><?= View::e($user['display_name'] ?: ($user['email'] ?: $user['student_id'])) ?> · <?= View::e($user['role']) ?></span>
            <form method="post" action="<?= View::e($basePath) ?>/logout" class="inline">
                <?= \Dblib\Support\Csrf::field() ?>
                <button type="submit" class="link">Log out</button>
            </form>
        </nav>
    <?php endif; ?>
</header>
<main class="container">
    <?= $content ?>
</main>
</body>
</html>
