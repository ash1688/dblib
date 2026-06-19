<?php
/** @var string $basePath */
/** @var array<string,mixed> $user */
/** @var list<string> $types */
/** @var string $targetUser */
/** @var string|null $banner */
/** @var string $consoleUrl */
/** @var string|null $backUrl */
use Dblib\Support\View;
$title = 'My database · dblib';
ob_start(); ?>
<?php if (!empty($banner)): ?>
    <p class="muted"><a href="<?= View::e($backUrl) ?>">← Back to class</a></p>
    <p class="alert ok-box"><?= View::e($banner) ?></p>
<?php endif; ?>
<div class="workbench"
     data-base="<?= View::e($basePath) ?>"
     data-target-user="<?= View::e($targetUser) ?>"
     data-types='<?= View::e(json_encode($types)) ?>'>

    <aside class="wb-side">
        <div class="wb-side-head">
            <strong>Tables</strong>
            <button type="button" class="link" id="wb-new-table">＋ New</button>
        </div>
        <ul class="wb-tables" id="wb-tables"><li class="muted">Loading…</li></ul>
        <p class="wb-side-foot">
            <a href="<?= View::e($consoleUrl) ?>">SQL console →</a>
        </p>
    </aside>

    <main class="wb-main">
        <section class="card wb-evidence" id="wb-evidence" hidden>
            <div class="wb-evidence-head">
                <h2>Generated SQL</h2>
                <span id="wb-evidence-meta" class="muted"></span>
            </div>
            <pre class="ran-sql" id="wb-sql"></pre>
            <div id="wb-outcome"></div>
        </section>

        <section class="card" id="wb-view">
            <p class="muted">Pick a table on the left, or create a new one.</p>
        </section>
    </main>
</div>

<script src="<?= View::e($basePath) ?>/assets/js/workbench.js" defer></script>
<?php $content = ob_get_clean();
require __DIR__ . '/layout.php';
