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
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

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
        const opt = { method, headers: { 'X-CSRF-Token': CSRF } };
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
    async function openTable(name, page, tab) {
        state.table = name;
        state.page = page || 1;
        const data = await getJSON(`/db/table?name=${encodeURIComponent(name)}&page=${state.page}&per_page=${state.perPage}`);
        if (data.error) { elView.innerHTML = `<p class="alert error">${esc(data.error)}</p>`; return; }

        document.querySelectorAll('.wb-table-btn').forEach((b) =>
            b.classList.toggle('active', b.dataset.table === name));
        renderTableView(data, tab);
    }

    // Shared toolbar + Browse/Structure tabs; tab bodies render into #wb-content.
    function renderTableView(data, tab) {
        const { table } = data;
        const onStructure = tab === 'structure';
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
                <button type="button" class="wb-tab${onStructure ? '' : ' active'}" data-tab="browse">Browse</button>
                <button type="button" class="wb-tab${onStructure ? ' active' : ''}" data-tab="structure">Structure</button>
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

        onStructure ? renderStructure(data) : renderBrowse(data);
    }

    function renderBrowse(data) {
        const { table, grid, page, pages } = data;
        const pkSet = new Set(data.primaryKey || []);
        const fkSet = new Set((data.foreignKeys || []).map((f) => f.column));
        const headClass = (c) => pkSet.has(c) ? ' class="col-pk"' : (fkSet.has(c) ? ' class="col-fk"' : '');
        const head = grid.columns.map((c) => `<th${headClass(c)}>${esc(c)}</th>`).join('') + '<th></th>';
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
        const fks = data.foreignKeys || [];
        const fkByColumn = {};
        fks.forEach((fk) => (fkByColumn[fk.column] = fkByColumn[fk.column] || []).push(fk));

        // Existing FK(s) on the column, or an "Add" link that opens the modal.
        const fkCell = (c) => {
            const own = fkByColumn[c.name] || [];
            if (!own.length) return `<button type="button" class="link" data-addfk="${esc(c.name)}">＋ Add</button>`;
            return own.map((fk) => `<span class="wb-fk">→ ${esc(fk.refTable)}.${esc(fk.refColumn)}
                <span class="muted">ON DELETE ${esc(fk.onDelete)} · ON UPDATE ${esc(fk.onUpdate)}</span>
                <button type="button" class="link danger" data-dropfk="${esc(fk.constraint)}" title="Remove foreign key">✕</button></span>`).join('<br>');
        };

        // PK blue, FK orange; a column that is both (junction tables) shows PK
        // blue — its FK-ness is already visible in the orange Foreign key cell.
        const nameClass = (c) => c.key === 'PRI' ? 'col-pk' : (fkByColumn[c.name] ? 'col-fk' : '');
        const rows = columns.map((c) => `<tr>
            <td class="${nameClass(c)}">${esc(c.name)}</td>
            <td>${esc(c.type)}</td>
            <td>${c.nullable ? 'YES' : 'NO'}</td>
            <td>${esc(c.key) || '—'}</td>
            <td>${c.default === null ? '<span class="null">NULL</span>' : esc(c.default)}</td>
            <td>${esc(c.extra) || '—'}</td>
            <td>${fkCell(c)}</td></tr>`).join('');

        document.getElementById('wb-content').innerHTML = `
            <div class="grid-wrap"><table class="grid">
                <thead><tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th><th>Foreign key</th></tr></thead>
                <tbody>${rows}</tbody></table></div>
            <p class="muted">${primaryKey.length ? 'Primary key: ' + primaryKey.map(esc).join(', ') : 'No primary key.'}
                <span class="wb-key-legend">· <span class="col-pk">●</span> primary key · <span class="col-fk">●</span> foreign key</span></p>`;

        elView.querySelectorAll('[data-addfk]').forEach((b) =>
            b.onclick = () => showFkModal(data, b.dataset.addfk));
        elView.querySelectorAll('[data-dropfk]').forEach((b) =>
            b.onclick = () => removeForeignKey(data, fks.find((f) => f.constraint === b.dataset.dropfk)));

        // The runnable SQL that yields this view — shown as evidence/teaching aid.
        showEvidence('SHOW COLUMNS FROM `' + table + '`', '', `${columns.length} column(s)`);
    }

    // ---- foreign keys ---------------------------------------------------------
    // Explainers keyed by referential action; ON DELETE and ON UPDATE get their
    // own wording because "the row is deleted" and "the value changes" read
    // differently to a student.
    const FK_DELETE_HELP = {
        'RESTRICT':  'Blocks the delete — a row in the referenced table cannot be deleted while rows here still point at it. The safe default.',
        'CASCADE':   'The delete ripples down — deleting a row in the referenced table also deletes every row here that points at it.',
        'SET NULL':  'Rows here are kept but unlinked — this column becomes NULL when the referenced row is deleted. The column must allow NULL.',
        'NO ACTION': 'In MySQL/MariaDB this behaves the same as RESTRICT — the delete is blocked while rows here still point at it.',
    };
    const FK_UPDATE_HELP = {
        'RESTRICT':  'Blocks changing the referenced value while rows here point at it. The safe default.',
        'CASCADE':   'If the referenced value changes, this column updates automatically to match.',
        'SET NULL':  'If the referenced value changes, this column becomes NULL. The column must allow NULL.',
        'NO ACTION': 'In MySQL/MariaDB this behaves the same as RESTRICT.',
    };

    function showFkModal(ctx, columnName) {
        const col = ctx.columns.find((c) => c.name === columnName);
        const actionOpts = Object.keys(FK_DELETE_HELP).map((a) =>
            `<option value="${a}" ${a === 'RESTRICT' ? 'selected' : ''}>${a}</option>`).join('');

        const overlay = document.createElement('div');
        overlay.className = 'wb-modal-overlay';
        overlay.innerHTML = `
            <div class="wb-modal" role="dialog" aria-modal="true" aria-label="Add foreign key">
                <h2>Add foreign key</h2>
                <p class="muted"><code>${esc(ctx.table)}.${esc(col.name)}</code> <span class="muted">(${esc(col.type)})</span> will only accept values that exist in the column you pick below.</p>
                <div id="wb-fk-error"></div>
                <label>References table
                    <select id="wb-fk-table"><option value="">Loading…</option></select>
                </label>
                <label>References column
                    <select id="wb-fk-column" disabled><option value="">Pick a table first</option></select>
                </label>
                <label>ON DELETE <span class="muted">— when the referenced row is deleted</span>
                    <select id="wb-fk-del">${actionOpts}</select>
                </label>
                <p class="wb-fk-help" id="wb-fk-del-help"></p>
                <label>ON UPDATE <span class="muted">— when the referenced value changes</span>
                    <select id="wb-fk-upd">${actionOpts}</select>
                </label>
                <p class="wb-fk-help" id="wb-fk-upd-help"></p>
                <div class="wb-form-actions">
                    <button type="button" class="primary" id="wb-fk-save">Add foreign key</button>
                    <button type="button" id="wb-fk-cancel">Cancel</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        const $ = (id) => overlay.querySelector('#' + id);
        const onKey = (e) => { if (e.key === 'Escape') close(); };
        const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey); };
        document.addEventListener('keydown', onKey);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
        $('wb-fk-cancel').onclick = close;

        const showError = (msg) => {
            $('wb-fk-error').innerHTML = msg ? `<p class="alert error">${esc(msg)}</p>` : '';
        };

        // SET NULL only works on a nullable column — warn at the point of choice.
        const helpFor = (help, action) => help[action] +
            (action === 'SET NULL' && !col.nullable
                ? ` ⚠ ${col.name} is NOT NULL, so this will fail — allow NULL on the column first.` : '');
        const syncHelp = () => {
            $('wb-fk-del-help').textContent = helpFor(FK_DELETE_HELP, $('wb-fk-del').value);
            $('wb-fk-upd-help').textContent = helpFor(FK_UPDATE_HELP, $('wb-fk-upd').value);
        };
        $('wb-fk-del').onchange = syncHelp;
        $('wb-fk-upd').onchange = syncHelp;
        syncHelp();

        getJSON('/db/tables').then((d) => {
            $('wb-fk-table').innerHTML = '<option value="">— choose a table —</option>' +
                (d.tables || []).map((t) => `<option value="${esc(t)}">${esc(t)}</option>`).join('');
        });

        $('wb-fk-table').onchange = async () => {
            const t = $('wb-fk-table').value;
            const colSel = $('wb-fk-column');
            colSel.disabled = true;
            if (!t) { colSel.innerHTML = '<option value="">Pick a table first</option>'; return; }
            colSel.innerHTML = '<option value="">Loading…</option>';
            const d = await getJSON('/db/columns?name=' + encodeURIComponent(t));
            if (d.error) { showError(d.error); return; }
            colSel.innerHTML = (d.columns || []).map((c) =>
                `<option value="${esc(c.name)}">${esc(c.name)} — ${esc(c.type)}${c.key === 'PRI' ? ' · PK' : ''}</option>`).join('');
            // Default to the primary key: that's what an FK points at 99% of the time.
            const pk = (d.columns || []).findIndex((c) => c.key === 'PRI');
            if (pk >= 0) colSel.selectedIndex = pk;
            colSel.disabled = false;
            showError('');
        };

        $('wb-fk-save').onclick = async () => {
            const refTable = $('wb-fk-table').value;
            const refColumn = $('wb-fk-column').value;
            if (!refTable || !refColumn) { showError('Pick the table and column to reference.'); return; }
            const data = await postJSON('/db/add-foreign-key', {
                table: ctx.table,
                column: col.name,
                refTable,
                refColumn,
                onDelete: $('wb-fk-del').value,
                onUpdate: $('wb-fk-upd').value,
            });
            showEvidence(data.sql, outcome(data), '');
            if (data.ok) { close(); openTable(ctx.table, state.page, 'structure'); }
            else showError(data.error || 'Adding the foreign key failed.');
        };
    }

    async function removeForeignKey(ctx, fk) {
        if (!fk) return;
        if (!confirm(`Remove foreign key "${fk.constraint}" (${ctx.table}.${fk.column} → ${fk.refTable}.${fk.refColumn})?`)) return;
        const data = await postJSON('/db/drop-foreign-key', { table: ctx.table, constraint: fk.constraint });
        showEvidence(data.sql, outcome(data), '');
        if (data.ok) openTable(ctx.table, state.page, 'structure');
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

    // POST an export endpoint and save the response as a file download.
    // Errors come back as JSON; anything else is the file itself.
    async function downloadExport(path, params, filename) {
        if (TARGET) params.user_id = TARGET;
        const res = await fetch(BASE + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
            body: new URLSearchParams(params),
        });
        if ((res.headers.get('Content-Type') || '').includes('application/json')) {
            const err = await res.json().catch(() => ({ error: 'Export failed.' }));
            alert(err.error || 'Export failed.');
            return;
        }
        const url = URL.createObjectURL(await res.blob());
        const a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(url);
    }

    // Export the whole table as CSV (server runs SELECT * with no LIMIT).
    const exportCsv = (table) => downloadExport('/export/csv', { table }, `${table}.csv`);

    // Every table + its data as one runnable .sql backup.
    const exportAllSql = () =>
        downloadExport('/export/sql', {}, `database-${new Date().toISOString().slice(0, 10)}.sql`);

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
        // TYPES is the catalogue: [{type, param, default, placeholder}, ...]
        const typeOpts = TYPES.map((t) =>
            `<option value="${esc(t.type)}" data-param="${esc(t.param || '')}" data-default="${esc(t.default)}" data-ph="${esc(t.placeholder)}">${esc(t.type)}</option>`).join('');
        elView.innerHTML = `<div class="wb-toolbar"><h2>Create table</h2></div>
            <form id="wb-create-form">
                <label>Table name <input type="text" id="wb-ct-name" required autocapitalize="none"></label>
                <table class="grid wb-cols">
                    <thead><tr><th>Column</th><th>Type</th><th>Size</th><th>Null</th><th>PK</th><th>Auto&nbsp;inc.</th><th></th></tr></thead>
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
            <td><input type="text" class="ct-size" placeholder=""></td>
            <td class="center"><input type="checkbox" class="ct-null"></td>
            <td class="center"><input type="checkbox" class="ct-pk" ${seed ? 'checked' : ''}></td>
            <td class="center"><input type="checkbox" class="ct-ai" ${seed ? 'checked' : ''}></td>
            <td><button type="button" class="link danger ct-remove">✕</button></td>`;
        document.getElementById('wb-ct-rows').appendChild(tr);
        tr.querySelector('.ct-remove').onclick = () => tr.remove();

        // Size applies to VARCHAR/CHAR/DECIMAL. The field stays editable; picking
        // such a type pre-fills a sensible default and a hint, and switching to a
        // type that takes no size just clears it (the server ignores size there).
        const sel = tr.querySelector('.ct-type');
        const size = tr.querySelector('.ct-size');
        const syncSize = () => {
            const opt = sel.options[sel.selectedIndex];
            const param = opt.dataset.param;
            if (param) {
                size.placeholder = opt.dataset.ph;
                if (!size.value) size.value = opt.dataset.default;
            } else {
                size.placeholder = 'no size';
                size.value = '';
            }
        };
        sel.onchange = syncSize;
        syncSize();
    }

    async function submitCreateTable(e) {
        e.preventDefault();
        const table = document.getElementById('wb-ct-name').value.trim();
        const columns = [...document.querySelectorAll('#wb-ct-rows tr')].map((tr) => ({
            name: tr.querySelector('.ct-name').value.trim(),
            type: tr.querySelector('.ct-type').value,
            size: tr.querySelector('.ct-size').value.trim(),
            nullable: tr.querySelector('.ct-null').checked,
            primary: tr.querySelector('.ct-pk').checked,
            autoIncrement: tr.querySelector('.ct-ai').checked,
        })).filter((c) => c.name !== '');
        const data = await postJSON('/db/create-table', { table, columns });
        showEvidence(data.sql, outcome(data), '');
        if (data.ok) loadTables(table);
    }

    // ---- query wizard ---------------------------------------------------------
    // Keyword blocks the student combines into a statement. The chips in order
    // ARE the query, so clause order becomes something you can see and fix by
    // dragging. Each block kind renders different inputs:
    //   table     → dropdown of the sandbox's tables
    //   join      → table ▾ ON column ▾ = column ▾ (ON pre-filled from foreign keys)
    //   columns   → free text + an "add column" dropdown that appends to it
    //   condition → column ▾ + operator ▾ + free-text value
    //   orderby   → column ▾ + ASC/DESC ▾
    //   set       → column ▾ = free-text value
    //   text      → free text only
    // With more than one table on the canvas, column dropdowns switch to
    // table.column so students see why qualification matters in a join.
    const WIZ_BLOCKS = [
        { kw: 'SELECT',      kind: 'columns',   ph: '* or column1, column2', hint: 'Which columns to show — * means every column.' },
        { kw: 'FROM',        kind: 'table',     hint: 'The table to read from.' },
        { kw: 'JOIN',        kind: 'join',      hint: 'Add a second table, matching rows where the ON columns are equal. Only rows with a match on both sides are kept (inner join).' },
        { kw: 'LEFT JOIN',   kind: 'join',      hint: 'Like JOIN, but keeps every row from the table(s) before it even when nothing matches — the missing side shows as NULL.' },
        { kw: 'WHERE',       kind: 'condition', hint: 'Keep only the rows that match this condition.' },
        { kw: 'AND',         kind: 'condition', hint: 'A second condition that must ALSO be true.' },
        { kw: 'OR',          kind: 'condition', hint: 'An alternative condition — either may be true.' },
        { kw: 'ORDER BY',    kind: 'orderby',   hint: 'Sort the results by a column.' },
        { kw: 'LIMIT',       kind: 'text',      ph: 'e.g. 10', hint: 'Show at most this many rows.' },
        { kw: 'UPDATE',      kind: 'table',     hint: 'Change existing rows in this table (needs SET).' },
        { kw: 'SET',         kind: 'set',       hint: 'What UPDATE should change: column = value.' },
        { kw: 'DELETE FROM', kind: 'table',     hint: 'Remove rows from this table (add a WHERE!).' },
    ];
    const WIZ_OPS = ['=', '!=', '>', '<', '>=', '<=', 'LIKE'];
    let wiz = [];          // canvas model: [{kw, kind, ph, value, col, op, dir, lcol, rcol}]
    let wizTables = [];    // table names for the table dropdowns
    let wizColumns = [];   // union of columns of every table on the canvas (qualified when > 1 table)
    const wizColCache = {}; // table → { columns: [names], fks: [{column, refTable, refColumn}] }

    function showWizard() {
        state.table = null;
        wiz = [];
        document.querySelectorAll('.wb-table-btn').forEach((b) => b.classList.remove('active'));

        const palette = WIZ_BLOCKS.map((b) =>
            `<button type="button" class="wiz-block" draggable="true" data-kw="${esc(b.kw)}" title="${esc(b.hint)}">${esc(b.kw)}</button>`).join('');

        elView.innerHTML = `
            <div class="wb-toolbar"><h2>Query wizard</h2></div>
            <p class="muted">Click or drag a block onto the canvas, fill in its text, and drag chips to reorder.
                The SQL builds live below — run it when it reads right.</p>
            <div class="wiz-palette">${palette}</div>
            <div class="wiz-canvas" id="wiz-canvas"></div>
            <div id="wiz-warn"></div>
            <pre class="ran-sql wiz-preview" id="wiz-preview"></pre>
            <div class="wb-form-actions">
                <button type="button" class="primary" id="wiz-run">Run query</button>
                <button type="button" id="wiz-clear">Clear</button>
            </div>`;

        getJSON('/db/tables').then((d) => {
            wizTables = d.tables || [];
            renderWizCanvas(); // fill table dropdowns once the list arrives
        });

        elView.querySelectorAll('.wiz-block').forEach((btn) => {
            btn.onclick = () => wizAdd(btn.dataset.kw, null);
            btn.ondragstart = (e) => e.dataTransfer.setData('text/plain', 'new:' + btn.dataset.kw);
        });

        const canvas = document.getElementById('wiz-canvas');
        canvas.ondragover = (e) => { e.preventDefault(); canvas.classList.add('drag-over'); };
        canvas.ondragleave = () => canvas.classList.remove('drag-over');
        canvas.ondrop = (e) => { e.preventDefault(); canvas.classList.remove('drag-over'); wizDrop(e, null); };

        document.getElementById('wiz-run').onclick = runWizard;
        document.getElementById('wiz-clear').onclick = () => { wiz = []; renderWizCanvas(); syncWiz(); };

        renderWizCanvas();
        syncWiz();
    }

    function wizAdd(kw, at) {
        const blk = WIZ_BLOCKS.find((b) => b.kw === kw);
        if (!blk) return;
        const item = { kw: blk.kw, kind: blk.kind, ph: blk.ph || '', value: '', col: '', op: '=', dir: 'ASC', lcol: '', rcol: '' };
        at === null ? wiz.push(item) : wiz.splice(at, 0, item);
        renderWizCanvas();
        syncWiz();
    }

    // Every table currently named on the canvas (FROM / JOIN / UPDATE / DELETE), in order.
    function wizCanvasTables() {
        return [...new Set(wiz.filter((b) => (b.kind === 'table' || b.kind === 'join') && b.value).map((b) => b.value))];
    }

    // Columns offered in the dropdowns = union of the columns of every table
    // on the canvas (fetched once per table, then cached). With one table they
    // are bare names; with two or more they become table.column, which is
    // also what a JOIN's ON clause needs.
    async function refreshWizColumns() {
        const tables = wizCanvasTables();
        await Promise.all(tables.map(async (t) => {
            if (!wizColCache[t]) {
                const d = await getJSON('/db/columns?name=' + encodeURIComponent(t));
                wizColCache[t] = {
                    columns: (d.columns || []).map((c) => c.name),
                    fks: (d.foreignKeys || []).map((f) => ({ column: f.column, refTable: f.refTable, refColumn: f.refColumn })),
                };
            }
        }));
        const qualify = tables.length > 1;
        wizColumns = [...new Set(tables.flatMap((t) =>
            (wizColCache[t] ? wizColCache[t].columns : []).map((c) => qualify ? `${t}.${c}` : c)))];
        wiz.filter((b) => b.kind === 'join' && b.value && !b.lcol && !b.rcol).forEach(wizSuggestOn);
        renderWizCanvas();
    }

    // Pre-fill a JOIN's ON from a declared foreign key between the joined table
    // and any table that comes before it on the canvas, in either direction.
    // Students can still change it, but the common case needs no typing.
    function wizSuggestOn(b) {
        const others = wizCanvasTables().filter((t) => t !== b.value);
        const own = wizColCache[b.value];
        for (const t of others) {
            const fk = own && own.fks.find((f) => f.refTable === t);
            if (fk) { b.lcol = `${t}.${fk.refColumn}`; b.rcol = `${b.value}.${fk.column}`; return; }
            const back = wizColCache[t] && wizColCache[t].fks.find((f) => f.refTable === b.value);
            if (back) { b.lcol = `${t}.${back.column}`; b.rcol = `${b.value}.${back.refColumn}`; return; }
        }
    }

    function wizDrop(e, target) {
        const d = e.dataTransfer.getData('text/plain');
        if (d.startsWith('new:')) {
            wizAdd(d.slice(4), target);
        } else if (d.startsWith('move:')) {
            const from = +d.slice(5);
            const item = wiz.splice(from, 1)[0];
            let to = target === null ? wiz.length : target;
            if (target !== null && from < target) to--; // removal shifted the target left
            wiz.splice(to, 0, item);
            renderWizCanvas();
            syncWiz();
        }
    }

    // Reusable <option> builders. A previously chosen value that no longer
    // exists (table dropped, column renamed) is kept as an extra option so the
    // chip doesn't silently lose it.
    function wizOptions(list, selected, placeholder) {
        return `<option value="">${esc(placeholder)}</option>`
            + (selected && !list.includes(selected) ? `<option value="${esc(selected)}" selected>${esc(selected)}</option>` : '')
            + list.map((v) => `<option value="${esc(v)}"${v === selected ? ' selected' : ''}>${esc(v)}</option>`).join('');
    }

    function wizChipBody(b) {
        const val = `<input type="text" value="${esc(b.value)}" placeholder="${esc(b.ph)}" autocapitalize="none">`;
        const colPh = wizColumns.length ? 'column…' : 'pick a table first';
        switch (b.kind) {
            case 'table':
                return `<select class="wiz-in" data-f="value">${wizOptions(wizTables, b.value, 'table…')}</select>`;
            case 'join': {
                // ON needs qualified names even if only this table is on the canvas yet.
                const qcols = wizCanvasTables().flatMap((t) =>
                    (wizColCache[t] ? wizColCache[t].columns : []).map((c) => `${t}.${c}`));
                const onPh = qcols.length ? 'table.column…' : 'pick tables first';
                return `<select class="wiz-in" data-f="value">${wizOptions(wizTables, b.value, 'table…')}</select>`
                    + `<span class="wiz-kw">ON</span>`
                    + `<select class="wiz-in" data-f="lcol">${wizOptions(qcols, b.lcol, onPh)}</select>`
                    + `<span class="wiz-kw">=</span>`
                    + `<select class="wiz-in" data-f="rcol">${wizOptions(qcols, b.rcol, onPh)}</select>`;
            }
            case 'columns':
                return val + `<select class="wiz-addcol" title="Append a column">${wizOptions(['*', ...wizColumns], '', 'add ▾')}</select>`;
            case 'condition':
                return `<select class="wiz-in" data-f="col">${wizOptions(wizColumns, b.col, colPh)}</select>`
                    + `<select class="wiz-in wiz-op" data-f="op">${wizOptions(WIZ_OPS, b.op, 'op…')}</select>`
                    + `<input type="text" value="${esc(b.value)}" placeholder="100 or 'text'" autocapitalize="none">`;
            case 'orderby':
                return `<select class="wiz-in" data-f="col">${wizOptions(wizColumns, b.col, colPh)}</select>`
                    + `<select class="wiz-in" data-f="dir">${wizOptions(['ASC', 'DESC'], b.dir, 'ASC')}</select>`;
            case 'set':
                return `<select class="wiz-in" data-f="col">${wizOptions(wizColumns, b.col, colPh)}</select>`
                    + `<span class="wiz-kw">=</span>`
                    + `<input type="text" value="${esc(b.value)}" placeholder="5 or 'text'" autocapitalize="none">`;
            default: // 'text'
                return val;
        }
    }

    function renderWizCanvas() {
        const canvas = document.getElementById('wiz-canvas');
        if (!canvas) return; // wizard not on screen
        canvas.innerHTML = wiz.length ? '' : '<span class="muted">Drop blocks here, or click one above.</span>';
        wiz.forEach((b, i) => {
            const chip = document.createElement('span');
            chip.className = 'wiz-chip';
            chip.draggable = true;
            chip.innerHTML = `<span class="wiz-kw">${esc(b.kw)}</span>` + wizChipBody(b)
                + `<button type="button" class="link danger" title="Remove block">✕</button>`;

            const inp = chip.querySelector('input');
            if (inp) {
                const fit = () => { inp.style.width = Math.max(10, inp.value.length + 2) + 'ch'; };
                fit();
                inp.oninput = () => { b.value = inp.value; fit(); syncWiz(); };
            }
            chip.querySelectorAll('select.wiz-in').forEach((sel) => {
                sel.onchange = () => {
                    b[sel.dataset.f] = sel.value;
                    syncWiz();
                    if (sel.dataset.f === 'value' && (b.kind === 'table' || b.kind === 'join')) {
                        if (b.kind === 'join') { b.lcol = ''; b.rcol = ''; } // re-suggest ON for the new table
                        refreshWizColumns(); // new table → new column lists
                    }
                };
            });
            const addCol = chip.querySelector('select.wiz-addcol');
            if (addCol) {
                addCol.onchange = () => {
                    const c = addCol.value;
                    if (c) {
                        // '*' (or starting fresh) replaces; otherwise append with a comma.
                        b.value = (c === '*' || !b.value.trim() || b.value.trim() === '*') ? c : b.value.trim() + ', ' + c;
                        renderWizCanvas();
                        syncWiz();
                    }
                };
            }
            // Typing or picking inside the chip must not start a chip drag.
            chip.querySelectorAll('input, select').forEach((el) => {
                el.onfocus = () => { chip.draggable = false; };
                el.onblur = () => { chip.draggable = true; };
            });

            chip.querySelector('button.danger').onclick = () => {
                const droppedTable = b.kind === 'table' || b.kind === 'join';
                wiz.splice(i, 1);
                renderWizCanvas();
                syncWiz();
                if (droppedTable) refreshWizColumns(); // fewer tables → maybe back to bare column names
            };
            chip.ondragstart = (e) => e.dataTransfer.setData('text/plain', 'move:' + i);
            chip.ondragover = (e) => { e.preventDefault(); e.stopPropagation(); };
            chip.ondrop = (e) => { e.preventDefault(); e.stopPropagation(); wizDrop(e, i); }; // drop on a chip = insert before it
            canvas.appendChild(chip);
        });
    }

    function wizSql() {
        return wiz.map((b) => {
            const v = b.value.trim();
            switch (b.kind) {
                case 'join':
                    return [b.kw, b.value, (b.lcol || b.rcol) ? 'ON' : '', b.lcol, (b.lcol || b.rcol) ? '=' : '', b.rcol]
                        .filter(Boolean).join(' ');
                case 'condition':
                    return [b.kw, b.col, (b.col || v) ? b.op : '', v].filter(Boolean).join(' ');
                case 'orderby':
                    return b.col ? `${b.kw} ${b.col} ${b.dir}` : b.kw;
                case 'set':
                    return (b.col || v) ? `${b.kw} ${b.col} = ${v}`.replace(/\s+/g, ' ').trim() : b.kw;
                default:
                    return v ? `${b.kw} ${v}` : b.kw;
            }
        }).join(' ');
    }

    function syncWiz() {
        document.getElementById('wiz-preview').textContent = wiz.length ? wizSql() : '-- your query appears here';
        const risky = wiz.some((b) => b.kw === 'UPDATE' || b.kw === 'DELETE FROM');
        const hasWhere = wiz.some((b) => b.kw === 'WHERE');
        const joinNoOn = wiz.some((b) => b.kind === 'join' && b.value && !(b.lcol && b.rcol));
        document.getElementById('wiz-warn').innerHTML = risky && !hasWhere
            ? '<p class="alert error">⚠ UPDATE / DELETE without a WHERE affects every row in the table.</p>'
            : joinNoOn
                ? '<p class="alert error">⚠ A JOIN needs an ON: pick the two columns that link the tables (e.g. orders.customer_id = customers.id).</p>'
                : '';
    }

    async function runWizard() {
        if (!wiz.length) return;
        const data = await postJSON('/db/run-sql', { sql: wizSql() });
        if (data.ok && data.isResultSet) {
            showEvidence(data.sql, gridHtml({ columns: data.columns, rows: data.rows }),
                `${data.rows.length} row(s) · ${data.durationMs} ms`);
        } else {
            showEvidence(data.sql || wizSql(), outcome(data), '');
        }
        loadTables(); // the wizard can change data/tables; keep the sidebar honest
    }

    // ---- wire up ------------------------------------------------------------
    elTables.addEventListener('click', (e) => {
        const b = e.target.closest('.wb-table-btn');
        if (b) openTable(b.dataset.table, 1);
    });
    document.getElementById('wb-new-table').onclick = showCreateTable;
    document.getElementById('wb-wizard').onclick = showWizard;
    document.getElementById('wb-export-all').onclick = exportAllSql;

    loadTables();
})();
