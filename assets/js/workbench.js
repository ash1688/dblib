/* dblib GUI workbench. Every builder action posts structured params to /db/*,
 * the server turns them into SQL, and we display that exact SQL next to the
 * result — the GUI is only a SQL generator. */
(function () {
    'use strict';

    const wb = document.querySelector('.workbench');
    const BASE = wb.dataset.base;
    const TYPES = JSON.parse(wb.dataset.types);
    // Non-empty when a teacher is acting on a student's sandbox; threaded into
    // every request so the server resolves (and authorises) the right sandbox.
    const TARGET = wb.dataset.targetUser || '';

    const elTables = document.getElementById('wb-tables');
    const elView = document.getElementById('wb-view');
    const elEvidence = document.getElementById('wb-evidence');
    const elSql = document.getElementById('wb-sql');
    const elOutcome = document.getElementById('wb-outcome');
    const elMeta = document.getElementById('wb-evidence-meta');

    const state = { table: null, page: 1, perPage: 25 };

    // ---- helpers ------------------------------------------------------------
    function withTarget(path) {
        if (!TARGET) return path;
        return path + (path.includes('?') ? '&' : '?') + 'user_id=' + encodeURIComponent(TARGET);
    }
    async function api(method, path, body) {
        const opt = { method, headers: {} };
        if (body !== undefined) {
            opt.headers['Content-Type'] = 'application/json';
            opt.body = JSON.stringify(body);
        }
        // user_id rides in the query string for both GET and POST; the server
        // reads it from $_GET, so the JSON body stays purely the builder params.
        const res = await fetch(BASE + withTarget(path), opt);
        return res.json();
    }
    const getJSON = (p) => api('GET', p);
    const postJSON = (p, b) => api('POST', p, b);

    function esc(v) {
        if (v === null || v === undefined) return '';
        return String(v).replace(/[&<>"']/g, (s) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]));
    }
    const cell = (v) => (v === null || v === undefined) ? '<span class="null">NULL</span>' : esc(v);

    function showEvidence(sql, outcomeHtml, meta) {
        elEvidence.hidden = false;
        elSql.textContent = sql || '(no SQL generated)';
        elOutcome.innerHTML = outcomeHtml || '';
        elMeta.textContent = meta || '';
    }

    function outcome(data) {
        if (!data || data.ok === false || data.error) {
            return `<p class="alert error">${esc(data && data.error || 'Unknown error.')}</p>`;
        }
        const msg = data.affectedRows > 0
            ? `${data.affectedRows} row(s) affected`
            : 'Statement executed successfully';
        return `<p class="ok">${msg} · ${data.durationMs} ms</p>`;
    }

    function gridHtml(grid) {
        if (!grid || !grid.columns.length) return '<p class="muted">No columns.</p>';
        const head = grid.columns.map((c) => `<th>${esc(c)}</th>`).join('');
        const body = grid.rows.map((r) =>
            '<tr>' + grid.columns.map((c) => `<td>${cell(r[c])}</td>`).join('') + '</tr>').join('');
        return `<div class="grid-wrap"><table class="grid"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
    }

    // ---- sidebar ------------------------------------------------------------
    async function loadTables(select) {
        const data = await getJSON('/db/tables');
        const tables = data.tables || [];
        elTables.innerHTML = tables.length
            ? tables.map((t) => `<li><button type="button" class="wb-table-btn${t === state.table ? ' active' : ''}" data-table="${esc(t)}">${esc(t)}</button></li>`).join('')
            : '<li class="muted">No tables yet.</li>';
        if (select && tables.includes(select)) openTable(select, 1);
    }

    // ---- browse a table -----------------------------------------------------
    async function openTable(name, page) {
        state.table = name;
        state.page = page || 1;
        const data = await getJSON(`/db/table?name=${encodeURIComponent(name)}&page=${state.page}&per_page=${state.perPage}`);
        if (data.error) { elView.innerHTML = `<p class="alert error">${esc(data.error)}</p>`; return; }

        document.querySelectorAll('.wb-table-btn').forEach((b) =>
            b.classList.toggle('active', b.dataset.table === name));
        renderTableView(data);
    }

    // Shared toolbar + Browse/Structure tabs; tab bodies render into #wb-content.
    function renderTableView(data) {
        const { table } = data;
        elView.innerHTML = `
            <div class="wb-toolbar">
                <h2>${esc(table)}</h2>
                <div class="wb-actions">
                    <button type="button" class="primary" id="wb-insert">Insert row</button>
                    <button type="button" id="wb-export">Export CSV</button>
                    <button type="button" id="wb-refresh">Refresh</button>
                    <button type="button" class="danger" id="wb-drop">Drop table</button>
                </div>
            </div>
            <div class="wb-tabs">
                <button type="button" class="wb-tab active" data-tab="browse">Browse</button>
                <button type="button" class="wb-tab" data-tab="structure">Structure</button>
            </div>
            <div id="wb-content"></div>`;

        document.getElementById('wb-insert').onclick = () => showRowForm('insert', data, null);
        document.getElementById('wb-export').onclick = () => exportCsv(table);
        document.getElementById('wb-refresh').onclick = () => openTable(table, data.page);
        document.getElementById('wb-drop').onclick = () => dropTable(table);
        elView.querySelectorAll('.wb-tab').forEach((b) =>
            b.onclick = () => {
                elView.querySelectorAll('.wb-tab').forEach((x) => x.classList.toggle('active', x === b));
                b.dataset.tab === 'structure' ? renderStructure(data) : renderBrowse(data);
            });

        renderBrowse(data);
    }

    function renderBrowse(data) {
        const { table, grid, page, pages } = data;
        const head = grid.columns.map((c) => `<th>${esc(c)}</th>`).join('') + '<th></th>';
        const rows = grid.rows.map((r, i) => {
            const cells = grid.columns.map((c) => `<td>${cell(r[c])}</td>`).join('');
            return `<tr>${cells}<td class="wb-row-actions">
                <button type="button" class="link" data-edit="${i}">Edit</button>
                <button type="button" class="link danger" data-del="${i}">Delete</button></td></tr>`;
        }).join('');

        document.getElementById('wb-content').innerHTML = `
            <div class="grid-wrap"><table class="grid"><thead><tr>${head}</tr></thead><tbody>
                ${rows || `<tr><td colspan="${grid.columns.length + 1}" class="muted">No rows yet.</td></tr>`}
            </tbody></table></div>
            <div class="wb-pager">
                <button type="button" id="wb-prev" ${page <= 1 ? 'disabled' : ''}>← Prev</button>
                <span class="muted">Page ${page} / ${pages}</span>
                <button type="button" id="wb-next" ${page >= pages ? 'disabled' : ''}>Next →</button>
                <span class="wb-pager-size">
                    <label class="muted">Rows
                        <select id="wb-per-page">
                            ${(data.perPageOptions || [10, 25, 50, 100]).map((n) =>
                                `<option value="${n}" ${n === data.perPage ? 'selected' : ''}>${n}</option>`).join('')}
                        </select>
                    </label>
                </span>
            </div>`;

        document.getElementById('wb-prev').onclick = () => openTable(table, page - 1);
        document.getElementById('wb-next').onclick = () => openTable(table, page + 1);
        document.getElementById('wb-per-page').onchange = (e) => {
            state.perPage = +e.target.value;
            openTable(table, 1);   // reset to page 1 when page size changes
        };
        elView.querySelectorAll('[data-edit]').forEach((b) =>
            b.onclick = () => showRowForm('edit', data, grid.rows[+b.dataset.edit]));
        elView.querySelectorAll('[data-del]').forEach((b) =>
            b.onclick = () => deleteRow(data, grid.rows[+b.dataset.del]));

        showEvidence(data.sql, gridHtml(grid), `${data.total} row(s) · page ${page}/${pages}`);
    }

    function renderStructure(data) {
        const { table, columns, primaryKey } = data;
        const rows = columns.map((c) => `<tr>
            <td>${esc(c.name)}</td>
            <td>${esc(c.type)}</td>
            <td>${c.nullable ? 'YES' : 'NO'}</td>
            <td>${esc(c.key) || '—'}</td>
            <td>${c.default === null ? '<span class="null">NULL</span>' : esc(c.default)}</td>
            <td>${esc(c.extra) || '—'}</td></tr>`).join('');

        document.getElementById('wb-content').innerHTML = `
            <div class="grid-wrap"><table class="grid">
                <thead><tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>
                <tbody>${rows}</tbody></table></div>
            <p class="muted">${primaryKey.length ? 'Primary key: ' + primaryKey.map(esc).join(', ') : 'No primary key.'}</p>`;

        // The runnable SQL that yields this view — shown as evidence/teaching aid.
        showEvidence('SHOW COLUMNS FROM `' + table + '`', '', `${columns.length} column(s)`);
    }

    // ---- insert / edit form -------------------------------------------------
    function showRowForm(mode, ctx, row) {
        const isEdit = mode === 'edit';
        const fields = ctx.columns.map((c, i) => {
            const cur = row ? row[c.name] : null;
            let initMode = 'value';
            if (isEdit) {
                if (cur === null) initMode = 'null';
            } else if (c.extra === 'auto_increment' || c.default !== null) {
                initMode = 'default';
            }
            const opts = [
                '<option value="value">Value</option>',
                c.nullable ? '<option value="null">NULL</option>' : '',
                !isEdit ? '<option value="default">Default</option>' : '',
            ].join('');
            const val = (cur === null || cur === undefined) ? '' : esc(cur);
            return `<div class="wb-field">
                <label>${esc(c.name)} <span class="muted">${esc(c.type)}${c.key === 'PRI' ? ' · PK' : ''}</span></label>
                <div class="wb-field-input">
                    <input type="text" id="wb-in-${i}" value="${val}">
                    <select id="wb-mode-${i}" data-init="${initMode}">${opts}</select>
                </div></div>`;
        }).join('');

        elView.innerHTML = `<div class="wb-toolbar"><h2>${isEdit ? 'Edit row' : 'Insert row'} · ${esc(ctx.table)}</h2></div>
            <form id="wb-row-form">${fields}
                <div class="wb-form-actions">
                    <button type="submit" class="primary">${isEdit ? 'Save changes' : 'Insert'}</button>
                    <button type="button" id="wb-cancel">Cancel</button>
                </div></form>`;

        ctx.columns.forEach((c, i) => {
            const sel = document.getElementById(`wb-mode-${i}`);
            const inp = document.getElementById(`wb-in-${i}`);
            const want = sel.dataset.init;
            sel.value = sel.querySelector(`option[value="${want}"]`) ? want : 'value';
            const sync = () => { inp.disabled = sel.value !== 'value'; };
            sel.onchange = sync;
            sync();
        });
        document.getElementById('wb-cancel').onclick = () => openTable(ctx.table, state.page);
        document.getElementById('wb-row-form').onsubmit = (e) => {
            e.preventDefault();
            isEdit ? submitEdit(ctx, row) : submitInsert(ctx);
        };
    }

    function collectFields(columns) {
        return columns.map((c, i) => ({
            column: c.name,
            mode: document.getElementById(`wb-mode-${i}`).value,
            value: document.getElementById(`wb-in-${i}`).value,
        }));
    }

    // WHERE for edit/delete: prefer the primary key, else match the full row.
    function whereFor(ctx, row) {
        const keys = ctx.primaryKey.length ? ctx.primaryKey : ctx.columns.map((c) => c.name);
        return keys.map((k) => row[k] === null
            ? { column: k, isNull: true }
            : { column: k, value: String(row[k]) });
    }

    async function submitInsert(ctx) {
        const data = await postJSON('/db/insert', { table: ctx.table, values: collectFields(ctx.columns) });
        showEvidence(data.sql, outcome(data), '');
        if (data.ok) openTable(ctx.table, state.page);
    }

    async function submitEdit(ctx, row) {
        const data = await postJSON('/db/update', {
            table: ctx.table,
            set: collectFields(ctx.columns),
            where: whereFor(ctx, row),
        });
        showEvidence(data.sql, outcome(data), '');
        if (data.ok) openTable(ctx.table, state.page);
    }

    async function deleteRow(ctx, row) {
        if (!confirm('Delete this row?')) return;
        const data = await postJSON('/db/delete', { table: ctx.table, where: whereFor(ctx, row) });
        showEvidence(data.sql, outcome(data), '');
        if (data.ok) openTable(ctx.table, state.page);
    }

    // Export the whole table as CSV (server runs SELECT * with no LIMIT).
    async function exportCsv(table) {
        const params = { table };
        if (TARGET) params.user_id = TARGET;
        const res = await fetch(BASE + '/export/csv', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params),
        });
        if (!(res.headers.get('Content-Type') || '').includes('text/csv')) {
            const err = await res.json().catch(() => ({ error: 'Export failed.' }));
            alert(err.error || 'Export failed.');
            return;
        }
        const url = URL.createObjectURL(await res.blob());
        const a = document.createElement('a');
        a.href = url; a.download = `${table}.csv`;
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(url);
    }

    async function dropTable(table) {
        if (!confirm(`Drop table "${table}"? This permanently deletes the table and its data.`)) return;
        const data = await postJSON('/db/drop-table', { table });
        showEvidence(data.sql, outcome(data), '');
        if (data.ok) { state.table = null; elView.innerHTML = '<p class="muted">Table dropped.</p>'; loadTables(); }
    }

    // ---- create table -------------------------------------------------------
    function showCreateTable() {
        state.table = null;
        document.querySelectorAll('.wb-table-btn').forEach((b) => b.classList.remove('active'));
        const typeOpts = TYPES.map((t) => `<option value="${esc(t)}">${esc(t)}</option>`).join('');
        elView.innerHTML = `<div class="wb-toolbar"><h2>Create table</h2></div>
            <form id="wb-create-form">
                <label>Table name <input type="text" id="wb-ct-name" required autocapitalize="none"></label>
                <table class="grid wb-cols">
                    <thead><tr><th>Column</th><th>Type</th><th>Null</th><th>PK</th><th>Auto&nbsp;inc.</th><th></th></tr></thead>
                    <tbody id="wb-ct-rows"></tbody>
                </table>
                <button type="button" class="link" id="wb-add-col">＋ Add column</button>
                <div class="wb-form-actions">
                    <button type="submit" class="primary">Create table</button>
                    <button type="button" id="wb-ct-cancel">Cancel</button>
                </div>
            </form>`;
        document.getElementById('wb-add-col').onclick = () => addColumnRow(false, typeOpts);
        document.getElementById('wb-ct-cancel').onclick = () =>
            state.table ? openTable(state.table, 1) : (elView.innerHTML = '<p class="muted">Pick a table on the left, or create a new one.</p>');
        document.getElementById('wb-create-form').onsubmit = submitCreateTable;
        addColumnRow(true, typeOpts); // seed an "id INT, PK, auto-increment" first row
    }

    function addColumnRow(seed, typeOpts) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" class="ct-name" value="${seed ? 'id' : ''}" autocapitalize="none"></td>
            <td><select class="ct-type">${typeOpts}</select></td>
            <td class="center"><input type="checkbox" class="ct-null"></td>
            <td class="center"><input type="checkbox" class="ct-pk" ${seed ? 'checked' : ''}></td>
            <td class="center"><input type="checkbox" class="ct-ai" ${seed ? 'checked' : ''}></td>
            <td><button type="button" class="link danger ct-remove">✕</button></td>`;
        document.getElementById('wb-ct-rows').appendChild(tr);
        tr.querySelector('.ct-remove').onclick = () => tr.remove();
    }

    async function submitCreateTable(e) {
        e.preventDefault();
        const table = document.getElementById('wb-ct-name').value.trim();
        const columns = [...document.querySelectorAll('#wb-ct-rows tr')].map((tr) => ({
            name: tr.querySelector('.ct-name').value.trim(),
            type: tr.querySelector('.ct-type').value,
            nullable: tr.querySelector('.ct-null').checked,
            primary: tr.querySelector('.ct-pk').checked,
            autoIncrement: tr.querySelector('.ct-ai').checked,
        })).filter((c) => c.name !== '');
        const data = await postJSON('/db/create-table', { table, columns });
        showEvidence(data.sql, outcome(data), '');
        if (data.ok) loadTables(table);
    }

    // ---- wire up ------------------------------------------------------------
    elTables.addEventListener('click', (e) => {
        const b = e.target.closest('.wb-table-btn');
        if (b) openTable(b.dataset.table, 1);
    });
    document.getElementById('wb-new-table').onclick = showCreateTable;

    loadTables();
})();
