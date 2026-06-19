<?php
/** @var string $basePath */
/** @var array<string,mixed> $user */
/** @var array<string,mixed> $student */
use Dblib\Support\View;
$label = $student['display_name'] ?: $student['student_id'];
$title = 'Editing ' . View::e($label) . ' · dblib';
ob_start(); ?>
<p class="muted"><a href="<?= View::e($basePath) ?>/teacher">← All classes</a></p>

<section class="card">
    <h1>Student database</h1>
    <p class="alert ok-box">
        You are working directly in <strong><?= View::e($label) ?></strong>’s
        sandbox (<?= View::e($student['student_id']) ?>). Changes are live — the
        same guards apply (<code>DROP DATABASE</code> blocked, one statement per run).
    </p>
    <form id="sql-form">
        <textarea id="sql" name="sql" rows="6" spellcheck="false"
                  placeholder="SELECT * FROM ..."></textarea>
        <button type="submit" class="primary">Run</button>
    </form>
</section>

<section id="output" class="card" hidden>
    <h2>Result</h2>
    <pre id="ran-sql" class="ran-sql"></pre>
    <div id="result"></div>
</section>

<script>
const basePath = <?= json_encode($basePath) ?>;
const userId = <?= (int) $student['id'] ?>;
const form = document.getElementById('sql-form');
const output = document.getElementById('output');
const ranSql = document.getElementById('ran-sql');
const result = document.getElementById('result');

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const sql = document.getElementById('sql').value;
    const res = await fetch(basePath + '/teacher/student/console/run', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ user_id: userId, sql }),
    });
    const data = await res.json();
    output.hidden = false;
    ranSql.textContent = data.sql || sql;

    if (data.error) {
        result.innerHTML = '<p class="alert error"></p>';
        result.querySelector('p').textContent = data.error;
        return;
    }
    if (data.isResultSet) {
        result.innerHTML = renderTable(data.columns, data.rows) +
            `<p class="muted">${data.rows.length} row(s) · ${data.durationMs} ms</p>`;
    } else {
        result.innerHTML =
            `<p class="ok">${data.affectedRows} row(s) affected · ${data.durationMs} ms</p>`;
    }
});

function renderTable(columns, rows) {
    if (!columns.length) return '<p class="muted">No columns.</p>';
    const head = columns.map(c => `<th>${escapeHtml(c)}</th>`).join('');
    const body = rows.map(r =>
        '<tr>' + columns.map(c => `<td>${escapeHtml(r[c])}</td>`).join('') + '</tr>'
    ).join('');
    return `<div class="grid-wrap"><table class="grid"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
}

function escapeHtml(v) {
    if (v === null || v === undefined) return '<span class="null">NULL</span>';
    return String(v).replace(/[&<>"']/g, s => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]
    ));
}
</script>
<?php $content = ob_get_clean();
require __DIR__ . '/layout.php';
