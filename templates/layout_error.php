<?php
/** @var string $basePath */
/** @var string $message */
use Dblib\Support\View;
$title = 'Not found · dblib';
$user = $user ?? null;
ob_start(); ?>
<section class="card">
    <h1>Hmm.</h1>
    <p class="alert error"><?= View::e($message) ?></p>
    <a class="button primary" href="<?= View::e($basePath) ?>/teacher">Back to dashboard</a>
</section>
<?php $content = ob_get_clean();
require __DIR__ . '/layout.php';
