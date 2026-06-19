<?php
/** @var string $basePath */
use Dblib\Support\View;
$title = 'SQL console · dblib';
$user = $user ?? null;
ob_start(); ?>
<section class="card">
    <h1>SQL console</h1>
    <p class="muted">One statement per run. <code>DROP DATABASE</code> is blocked;
       everything else in your sandbox is fair game.</p>
    <form id="sql-form">
        <textarea id="sql" name="sql" rows="6" spellcheck="false"
                  placeholder="SELECT * FROM ..."></textarea>
        <button type="submit" class="primary">Run</button>
    </form>
</section>

<section id="output" class="card" hidden>
    <div class="wb-evidence-head">
        <h2>Result</h2>
        <button type="button" id="export-csv" class="ghost button" hidden>Download CSV</button>
    </div>
    <pre id="ran-sql" class="ran-sql"></pre>
    <div id="result"></div>
</section>

<section class="card" id="history">
    <div class="wb-evidence-head">
        <h2>History <span class="muted">(this session)</span></h2>
        <span class="history-tools">
            <button type="button" id="history-export" class="link">Download .sql</button>
            <button type="button" id="history-clear" class="link">Clear</button>
        </span>
    </div>
    <ul id="history-list" class="history-list"><li class="muted">No queries yet.</li></ul>
</section>

<script>
const basePath = <?= json_encode($basePath) ?>;
const form = document.getElementById('sql-form');
const output = document.getElementById('output');
const ranSql = document.getElementById('ran-sql');
const result = document.getElementById('result');
const exportBtn = document.getElementById('export-csv');
let lastResultSql = null;

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const sql = document.getElementById('sql').value;
    const res = await fetch(basePath + '/console/run', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ sql }),
    });
    const data = await res.json();
    output.hidden = false;
    ranSql.textContent = data.sql || sql;
    exportBtn.hidden = true;
    lastResultSql = null;

    if (data.error) {
        result.innerHTML = '<p class="alert error"></p>';
        result.querySelector('p').textContent = data.error;
        return;
    }
    if (data.isResultSet) {
        const note = data.truncated
            ? `<p class="alert ok-box">Showing first ${data.shownRows} of ${data.totalRows} rows. Add a <code>LIMIT</code>, or use Download CSV for the full set.</p>`
            : '';
        result.innerHTML = renderTable(data.columns, data.rows) +
            `<p class="muted">${data.rows.length} row(s) · ${data.durationMs} ms</p>` + note;
        lastResultSql = data.sql;       // only result sets are exportable
        exportBtn.hidden = false;
    } else {
        result.innerHTML =
            `<p class="ok">${data.affectedRows} row(s) affected · ${data.durationMs} ms</p>`;
    }
    loadHistory();
});

exportBtn.addEventListener('click', async () => {
    if (lastResultSql === null) return;
    const res = await fetch(basePath + '/export/csv', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ sql: lastResultSql }),
    });
    if (!(res.headers.get('Content-Type') || '').includes('text/csv')) {
        const err = await res.json().catch(() => ({ error: 'Export failed.' }));
        alert(err.error || 'Export failed.');
        return;
    }
    triggerDownload(await res.blob(), 'query-results.csv');
});

function triggerDownload(blob, name) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
}

// ---- session history ----
const historyList = document.getElementById('history-list');
const sqlInput = document.getElementById('sql');

async function loadHistory() {
    const res = await fetch(basePath + '/history');
    const data = await res.json();
    const entries = data.entries || [];
    if (!entries.length) {
        historyList.innerHTML = '<li class="muted">No queries yet.</li>';
        return;
    }
    historyList.innerHTML = entries.map((e, i) => `
        <li class="history-item ${e.ok ? '' : 'failed'}" data-i="${i}">
            <code class="history-sql"></code>
            <span class="history-meta">${escapeHtml(e.at)} · ${escapeHtml(e.info)}</span>
        </li>`).join('');
    // Set SQL text safely and wire clicks (load into editor = tweak).
    historyList.querySelectorAll('.history-item').forEach((li) => {
        const entry = entries[+li.dataset.i];
        li.querySelector('.history-sql').textContent = entry.sql;
        li.title = 'Click to load into the editor';
        li.addEventListener('click', () => {
            sqlInput.value = entry.sql;
            sqlInput.focus();
            sqlInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });
}

document.getElementById('history-clear').addEventListener('click', async () => {
    await fetch(basePath + '/history/clear', { method: 'POST' });
    loadHistory();
});

document.getElementById('history-export').addEventListener('click', async () => {
    const res = await fetch(basePath + '/history/export');
    if ((res.headers.get('Content-Type') || '').includes('json')) {
        const err = await res.json().catch(() => ({ error: 'Export failed.' }));
        alert(err.error || 'Export failed.');
        return;
    }
    triggerDownload(await res.blob(), 'dblib-session.sql');
});

loadHistory();

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
