<?php
require_once __DIR__ . "/../auth.php";

include __DIR__ . "/../conn.php";
include __DIR__ . "/command_functions.php";

$branches = [];
$r = oci_parse($conn, "SELECT BCODE, BNAME FROM BRANCHES WHERE BCODE NOT IN
    ('32', '33', '35', '37', '38', '70', '71', '44') ORDER BY BCODE ASC");
oci_execute($r);
while ($row = oci_fetch_assoc($r)) {
    if (isset($connections[$row['BCODE']])) {
        $branches[] = ['code' => $row['BCODE'], 'name' => $row['BNAME']];
    }
}

$schema = commands_schema($conn);

include __DIR__ . "/header.php";
?>
<link rel="stylesheet" href="../assets/codemirror/codemirror.min.css">
<link rel="stylesheet" href="../assets/codemirror/show-hint.min.css">
<script src="../assets/codemirror/codemirror.min.js"></script>
<script src="../assets/codemirror/sql.min.js"></script>
<script src="../assets/codemirror/show-hint.min.js"></script>
<script src="../assets/codemirror/sql-hint.min.js"></script>
<style>
    .CodeMirror {
        height: 100%;
        background: #fafafa;
        font-size: 14px;
        line-height: 1.6;
    }

    .CodeMirror pre.CodeMirror-line,
    .CodeMirror pre.CodeMirror-line-like {
        padding: 0 20px;
    }

    .CodeMirror-lines {
        padding: 14px 0;
    }

    .CodeMirror-hints {
        z-index: 60;
        font-size: 13px;
        max-height: 16em;
    }

    /* Branch picker opens from its button, not from the middle of the screen. */
    #branchPopover {
        transform-origin: top right;
        transition: opacity 150ms var(--ease-out), transform 150ms var(--ease-out);
    }

    #branchPopover[data-state="closed"] {
        opacity: 0;
        transform: scale(0.97);
        pointer-events: none;
    }

    /* Result cells select like a spreadsheet: active cell outlined, the rest of the range tinted. */
    #results td {
        cursor: cell;
        scroll-margin-top: 2.5rem; /* keep the active cell clear of the sticky header */
    }

    #results td.cell-in-range {
        background: var(--drl-50);
    }

    #results td.cell-selected {
        box-shadow: inset 0 0 0 2px var(--drl);
        background: var(--drl-50);
    }

    .grid-table th,
    .grid-table td {
        white-space: nowrap;
        max-width: 28rem;
        overflow: hidden;
        text-overflow: ellipsis;
    }
</style>

<div class="flex h-[calc(100vh-3.5rem)] bg-white">
    <!-- Rail with the button that shows / hides the tables sidebar (collapsed by default). -->
    <div class="w-12 shrink-0 flex flex-col items-center py-3 border-r border-slate-200">
        <button id="sidebarToggle" type="button" aria-controls="tableSidebar" aria-expanded="false" title="Show tables" class="w-8 h-8 flex items-center justify-center rounded-md text-slate-500 hover:text-slate-900 hover:bg-slate-100">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <rect x="3" y="4" width="18" height="16" rx="2" stroke-width="1.75" />
                <path stroke-linecap="round" stroke-width="1.75" d="M9 4v16" />
            </svg>
        </button>
    </div>

    <!-- Sidebar: tables and their columns; clicking a name inserts it into the editor. -->
    <aside id="tableSidebar" class="hidden w-56 shrink-0 flex-col border-r border-slate-200">
        <div class="px-5 h-14 flex items-center border-b border-slate-200">
            <h1 class="text-lg font-semibold text-slate-900">SQL Editor</h1>
        </div>
        <div class="p-4">
            <div class="relative">
                <svg class="w-4 h-4 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                <input id="tableSearch" type="text" placeholder="Search tables..." class="w-full rounded-md border border-slate-300 pl-8 pr-3 py-1.5 text-sm placeholder:text-slate-400 focus:outline-none focus:ring-1 focus:ring-drl-500 focus:border-drl-500">
            </div>
        </div>
        <div class="px-5 pb-2 text-xs font-medium tracking-wider text-slate-500 uppercase">Tables <span id="tableCount" class="tabular-nums"></span></div>
        <div id="tableList" class="flex-1 overflow-y-auto pb-4 text-sm"></div>
    </aside>

    <main id="workArea" class="flex-1 min-w-0 flex flex-col">
        <div class="flex-1 min-h-0 relative">
            <textarea id="command" placeholder="Write a SELECT, INSERT or UPDATE…"></textarea>
        </div>

        <!-- Drag to resize the results; double-click resets; arrow keys when focused. -->
        <div id="resizeHandle" role="separator" aria-orientation="horizontal" aria-label="Resize results" tabindex="0" title="Drag to resize · double-click to reset"
            class="group relative h-0 shrink-0 cursor-row-resize focus:outline-none">
            <div class="absolute inset-x-0 -top-1.5 h-3 z-10"></div>
            <div class="absolute inset-x-0 -top-px h-[3px] bg-transparent group-hover:bg-drl-400 group-focus-visible:bg-drl-500 transition-colors"></div>
        </div>

        <!-- Results panel -->
        <section id="resultsPane" style="height: 42%" class="shrink-0 border-t border-slate-200 flex flex-col">
            <div class="relative h-12 shrink-0 flex items-center justify-between gap-3 px-4 border-b border-slate-200">
                <div class="flex items-center gap-3 min-w-0 self-stretch">
                    <span class="h-full flex items-center text-sm font-medium text-slate-900 border-b-2 border-slate-900">Results</span>
                    <span id="resultSummary" class="text-xs text-slate-500 truncate tabular-nums"></span>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <div class="relative">
                        <button id="branchButton" type="button" class="h-8 flex items-center gap-2 rounded-md border border-slate-300 bg-white pl-3 pr-2 text-sm hover:bg-slate-50">
                            <span class="text-slate-400">branches</span>
                            <span id="branchLabel" class="text-slate-900 tabular-nums">None</span>
                            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                        </button>
                        <div id="branchPopover" data-state="closed" class="absolute right-0 top-10 z-50 w-80 rounded-lg border border-slate-200 bg-white shadow-xl">
                            <div class="p-2 border-b border-slate-100 flex items-center gap-2">
                                <input id="branchSearch" type="text" placeholder="Find branch..." class="flex-1 rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-drl-500 focus:border-drl-500">
                                <button type="button" data-select="all" class="text-xs font-medium text-drl-700 hover:bg-drl-50 rounded px-2 py-1.5">All</button>
                                <button type="button" data-select="none" class="text-xs font-medium text-slate-600 hover:bg-slate-100 rounded px-2 py-1.5">None</button>
                            </div>
                            <div id="branchList" class="max-h-72 overflow-y-auto py-1"></div>
                        </div>
                    </div>
                    <button id="runButton" type="button" class="h-8 flex items-center gap-2 rounded-md bg-drl-600 hover:bg-drl-500 px-3 text-sm font-medium text-white disabled:opacity-60 disabled:cursor-not-allowed">
                        <span id="runLabel">Run</span>
                        <kbd class="text-[11px] font-sans text-drl-100">Ctrl ↵</kbd>
                    </button>
                </div>
                <div id="progressTrack" class="absolute left-0 right-0 bottom-0 h-0.5 overflow-hidden opacity-0 transition-opacity">
                    <div id="progressBar" class="progress-bar h-full bg-drl-500" style="transform: scaleX(0)"></div>
                </div>
            </div>
            <div id="results" class="flex-1 min-h-0 overflow-auto">
                <div id="emptyState" class="p-6 text-sm text-slate-600">
                    Click <span class="inline-block px-1.5 py-0.5 rounded border border-slate-300 bg-slate-50 text-slate-700">Run</span> to execute your query.
                </div>
            </div>
        </section>
    </main>
</div>

<script>
    (function() {
        const CONCURRENCY = 5;
        const schema = <?= json_encode($schema ?: new stdClass()) ?>;
        const branches = <?= json_encode($branches) ?>;
        const branchName = Object.fromEntries(branches.map((b) => [b.code, b.name]));
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const SPINNER = '<span class="spinner" aria-hidden="true"></span>';

        // ---------- Editor ----------
        const editor = CodeMirror.fromTextArea(document.getElementById('command'), {
            mode: 'text/x-plsql',
            lineNumbers: false,
            lineWrapping: true,
            extraKeys: { 'Ctrl-Space': 'autocomplete', 'Ctrl-Enter': () => run(), 'Cmd-Enter': () => run() },
            hintOptions: { tables: schema, completeSingle: false },
        });

        // Column suggestions follow the table of the statement under the cursor (INSERT INTO x / UPDATE x / FROM x).
        function statementTable() {
            const text = editor.getValue();
            const pos = editor.indexFromPos(editor.getCursor());
            const start = text.lastIndexOf(';', pos - 1) + 1;
            const end = text.indexOf(';', pos);
            const stmt = text.slice(start, end === -1 ? text.length : end);
            const m = stmt.match(/\b(?:INSERT\s+INTO|UPDATE|FROM)\s+([A-Za-z0-9_$#]+)/i);
            const table = m ? m[1].toUpperCase() : null;
            return table && schema[table] ? table : null;
        }

        editor.on('inputRead', function(cm, change) {
            if (cm.state.completionActive || !/^[\w$#.]$/.test(change.text.join(''))) return;
            const tokenType = cm.getTokenAt(cm.getCursor()).type || '';
            if (/string|number|comment/.test(tokenType)) return;
            cm.options.hintOptions.defaultTable = statementTable();
            cm.showHint();
        });
        editor.on('cursorActivity', function(cm) {
            cm.options.hintOptions.defaultTable = statementTable();
        });

        function insertAtCursor(text) {
            editor.replaceSelection(text);
            editor.focus();
        }

        // ---------- Sidebar ----------
        const tableList = document.getElementById('tableList');
        const tableNames = Object.keys(schema).sort();

        function renderTables(filter) {
            const f = filter.trim().toUpperCase();
            const names = f ? tableNames.filter((t) => t.includes(f)) : tableNames;
            document.getElementById('tableCount').textContent = '(' + names.length + ')';
            tableList.innerHTML = names.map((t) =>
                '<div class="table-item" data-table="' + esc(t) + '">'
                + '<div class="flex items-center hover:bg-slate-100">'
                + '<button type="button" class="toggle px-2 py-1.5 text-slate-400 hover:text-slate-700" title="Show columns">'
                + '<svg class="chev w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></button>'
                + '<button type="button" class="insert flex-1 text-left py-1.5 pr-4 font-mono text-[13px] text-slate-700 truncate" title="Insert ' + esc(t) + '">' + esc(t) + '</button>'
                + '</div><div class="cols hidden pl-9 pb-1"></div></div>'
            ).join('') || '<p class="px-5 py-2 text-slate-400">No tables match.</p>';
        }

        tableList.addEventListener('click', (e) => {
            const item = e.target.closest('.table-item');
            if (!item) return;
            const table = item.dataset.table;
            if (e.target.closest('.insert')) return insertAtCursor(table);
            if (e.target.closest('.col')) return insertAtCursor(e.target.closest('.col').dataset.col);
            if (e.target.closest('.toggle')) {
                const cols = item.querySelector('.cols');
                if (!cols.innerHTML) {
                    cols.innerHTML = schema[table].map((c) => '<button type="button" class="col block w-full text-left py-1 pr-4 font-mono text-xs text-slate-500 hover:text-slate-900 truncate" data-col="' + esc(c) + '">' + esc(c) + '</button>').join('');
                }
                cols.classList.toggle('hidden');
                item.querySelector('.chev').classList.toggle('rotate-90');
            }
        });
        document.getElementById('tableSearch').addEventListener('input', (e) => renderTables(e.target.value));
        renderTables('');

        // Collapsed unless the user opened it last time. No animation: it's toggled often.
        const SIDEBAR_KEY = 'commands.sidebarOpen';
        const sidebar = document.getElementById('tableSidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');

        function setSidebar(open, focusSearch = true) {
            sidebar.classList.toggle('hidden', !open);
            sidebar.classList.toggle('flex', open);
            sidebarToggle.setAttribute('aria-expanded', String(open));
            sidebarToggle.title = open ? 'Hide tables' : 'Show tables';
            sidebarToggle.classList.toggle('bg-slate-100', open);
            sidebarToggle.classList.toggle('text-slate-900', open);
            try { localStorage.setItem(SIDEBAR_KEY, open ? '1' : '0'); } catch (e) {}
            editor.refresh(); // the editor's width changed
            if (open && focusSearch) document.getElementById('tableSearch').focus();
        }

        sidebarToggle.addEventListener('click', () => setSidebar(sidebar.classList.contains('hidden')));
        let sidebarOpen = false;
        try { sidebarOpen = localStorage.getItem(SIDEBAR_KEY) === '1'; } catch (e) {}
        if (sidebarOpen) setSidebar(true, false);

        // ---------- Branch picker ----------
        const STORE_KEY = 'commands.branches';
        let selected = new Set();
        try {
            const saved = JSON.parse(localStorage.getItem(STORE_KEY) || '[]');
            saved.filter((c) => branchName[c]).forEach((c) => selected.add(c));
        } catch (e) {}

        const popover = document.getElementById('branchPopover');
        const branchList = document.getElementById('branchList');

        function saveBranches() {
            try { localStorage.setItem(STORE_KEY, JSON.stringify([...selected])); } catch (e) {}
            const n = selected.size;
            document.getElementById('branchLabel').textContent = n === 0 ? 'None' : n === branches.length ? 'All (' + n + ')' : n === 1 ? [...selected][0] + ' - ' + branchName[[...selected][0]] : n + ' selected';
        }

        function renderBranches(filter) {
            const f = filter.trim().toLowerCase();
            branchList.innerHTML = branches
                .filter((b) => !f || (b.code + ' ' + b.name).toLowerCase().includes(f))
                .map((b) => '<label class="flex items-center gap-2.5 px-3 py-1.5 text-sm hover:bg-slate-50 cursor-pointer">'
                    + '<input type="checkbox" value="' + esc(b.code) + '" ' + (selected.has(b.code) ? 'checked' : '') + ' class="h-4 w-4 rounded border-slate-300 text-drl-600 focus:ring-drl-500">'
                    + '<span class="font-mono text-xs text-slate-500 tabular-nums">' + esc(b.code) + '</span><span class="truncate text-slate-800">' + esc(b.name) + '</span></label>')
                .join('') || '<p class="px-3 py-2 text-sm text-slate-400">No branches match.</p>';
        }

        function setPopover(open) {
            popover.dataset.state = open ? 'open' : 'closed';
            if (open) {
                renderBranches(document.getElementById('branchSearch').value);
                document.getElementById('branchSearch').focus();
            }
        }

        document.getElementById('branchButton').addEventListener('click', () => setPopover(popover.dataset.state !== 'open'));
        document.addEventListener('click', (e) => {
            // Run can open the picker itself (no branch chosen), so its own click must not close it again.
            if (popover.dataset.state === 'open' && !e.target.closest('#branchPopover, #branchButton, #runButton')) setPopover(false);
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && popover.dataset.state === 'open') setPopover(false);
        });
        branchList.addEventListener('change', (e) => {
            e.target.checked ? selected.add(e.target.value) : selected.delete(e.target.value);
            saveBranches();
        });
        popover.addEventListener('click', (e) => {
            const which = e.target.dataset.select;
            if (!which) return;
            selected = new Set(which === 'all' ? branches.map((b) => b.code) : []);
            saveBranches();
            renderBranches(document.getElementById('branchSearch').value);
        });
        document.getElementById('branchSearch').addEventListener('input', (e) => renderBranches(e.target.value));
        saveBranches();

        // ---------- Running ----------
        const runButton = document.getElementById('runButton');
        const results = document.getElementById('results');
        const summary = document.getElementById('resultSummary');
        const progressTrack = document.getElementById('progressTrack');
        const progressBar = document.getElementById('progressBar');
        let hideBarTimer;

        async function post(data) {
            const resp = await fetch('run.php', { method: 'POST', body: new URLSearchParams(data) });
            const body = await resp.json().catch(() => ({ error: 'HTTP ' + resp.status + ': response was not JSON' }));
            if (!resp.ok && !body.error) body.error = 'HTTP ' + resp.status;
            return body;
        }

        function cell(v) {
            if (v === null) return '<td class="px-3 py-1.5 text-slate-400 italic" data-null>NULL</td>';
            return '<td class="px-3 py-1.5" title="' + esc(v) + '">' + esc(v) + '</td>';
        }

        // One result block per statement; SELECT rows from every branch go into one grid.
        function buildSections(statements) {
            results.innerHTML = '<div id="messages"></div>' + statements.map((s, i) => {
                const label = s.kind === 'select' ? 'SELECT' : s.sql.trim().split(/\s+/)[0].toUpperCase();
                return '<section class="stmt border-b border-slate-200" data-i="' + i + '" data-kind="' + s.kind + '">'
                    + '<div class="sticky left-0 flex items-center gap-2 px-4 py-2 bg-slate-50 border-b border-slate-100 text-xs">'
                    + (statements.length > 1 ? '<span class="text-slate-400 tabular-nums">#' + (i + 1) + '</span>' : '')
                    + '<span class="font-semibold ' + (s.kind === 'select' ? 'text-drl-700' : 'text-amber-700') + '">' + label + '</span>'
                    + '<span class="font-mono text-slate-500 truncate max-w-[60ch]" title="' + esc(s.sql) + '">' + esc(s.sql.replace(/\s+/g, ' ')) + '</span>'
                    + '<span class="stmt-count ml-auto text-slate-500 tabular-nums"></span></div>'
                    + (s.kind === 'select'
                        ? '<table class="grid-table text-xs font-mono"><thead class="sticky top-0 bg-white shadow-[0_1px_0_#e2e8f0]"><tr></tr></thead><tbody></tbody></table><div class="notes px-4 text-xs text-slate-500"></div>'
                        : '<table class="w-full text-sm"><thead class="text-xs text-slate-500"><tr><th class="px-4 py-1.5 text-left font-medium w-48">Branch</th><th class="px-4 py-1.5 text-left font-medium">Status</th><th class="px-4 py-1.5 text-right font-medium w-24">Rows</th></tr></thead><tbody class="divide-y divide-slate-100"></tbody></table>')
                    + '</section>';
            }).join('');
        }

        function addMessage(html) {
            document.getElementById('messages').insertAdjacentHTML('beforeend', '<div class="px-4 py-1.5 text-xs border-b border-slate-100 row-in">' + html + '</div>');
        }

        function addBranchResult(bcode, data, totals) {
            const who = '<span class="font-mono tabular-nums">' + esc(bcode) + '</span> ' + esc(branchName[bcode] || '');
            if (!data.connected) {
                totals.down++;
                addMessage('<span class="text-red-600 font-medium">Not connecting:</span> ' + who + ' <span class="text-slate-400">' + esc(data.error || '') + '</span>');
                return;
            }
            data.results.forEach((r, i) => {
                const sec = results.querySelector('.stmt[data-i="' + i + '"]');
                const tbody = sec.querySelector('tbody');
                if (sec.dataset.kind === 'select') {
                    if (!r.ok) {
                        totals.failed++;
                        sec.querySelector('.notes').insertAdjacentHTML('beforeend', '<p class="py-1 text-red-600">' + who + ': ' + esc(r.error) + '</p>');
                        return;
                    }
                    const head = sec.querySelector('thead tr');
                    if (!head.children.length) {
                        head.innerHTML = '<th class="px-3 py-2 text-left font-semibold text-slate-600">BRANCH</th>' + r.columns.map((c) => '<th class="px-3 py-2 text-left font-semibold text-slate-600">' + esc(c) + '</th>').join('');
                    }
                    tbody.insertAdjacentHTML('beforeend', r.rows.map((row) =>
                        '<tr class="border-b border-slate-100 hover:bg-slate-50 row-in"><td class="px-3 py-1.5 text-slate-500" title="' + esc(branchName[bcode]) + '">' + esc(bcode) + '</td>' + row.map(cell).join('') + '</tr>'
                    ).join(''));
                    sec.rows = (sec.rows || 0) + r.rows.length;
                    sec.querySelector('.stmt-count').textContent = sec.rows + ' row' + (sec.rows === 1 ? '' : 's');
                    if (r.truncated) {
                        sec.querySelector('.notes').insertAdjacentHTML('beforeend', '<p class="py-1">' + who + ': showing the first <?= COMMANDS_MAX_ROWS ?> rows.</p>');
                    }
                } else {
                    const tone = !r.ok ? 'text-red-700 bg-red-50 border-red-200' : r.skipped ? 'text-amber-700 bg-amber-50 border-amber-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200';
                    if (!r.ok) totals.failed++;
                    tbody.insertAdjacentHTML('beforeend', '<tr class="hover:bg-slate-50 row-in"><td class="px-4 py-1.5">' + who + '</td>'
                        + '<td class="px-4 py-1.5"><span class="inline-block px-2 py-0.5 rounded-full border text-xs ' + tone + '">' + esc(r.status) + '</span></td>'
                        + '<td class="px-4 py-1.5 text-right tabular-nums">' + (r.affected ?? '') + '</td></tr>');
                }
            });
        }

        async function run() {
            if (runButton.disabled) return;
            const command = editor.getValue();
            const codes = branches.map((b) => b.code).filter((c) => selected.has(c));

            if (!command.trim()) return iziToast.error({ title: 'Error', message: 'Enter a command.' });
            if (!codes.length) {
                setPopover(true);
                return iziToast.error({ title: 'Error', message: 'Select at least one branch.' });
            }

            const plan = await post({ command: command });
            if (plan.error) return iziToast.error({ title: 'Error', message: plan.error });

            // Writes go to live branch databases, so confirm them; plain SELECTs run straight away.
            const writes = plan.statements.filter((s) => s.kind === 'dml').length;
            if (writes && !confirm('Run ' + writes + ' INSERT/UPDATE statement' + (writes === 1 ? '' : 's') + ' on ' + codes.length + ' branch' + (codes.length === 1 ? '' : 'es') + '?')) return;

            runButton.disabled = true;
            selectCell(null);
            document.getElementById('runLabel').innerHTML = SPINNER + ' Running';
            buildSections(plan.statements);
            const totals = { down: 0, failed: 0 };

            let idx = 0, done = 0;
            clearTimeout(hideBarTimer);
            progressTrack.classList.remove('opacity-0');
            progressBar.style.transform = 'scaleX(0)';
            summary.textContent = '0 / ' + codes.length + ' branches';

            async function worker() {
                while (idx < codes.length) {
                    const bcode = codes[idx++];
                    let data;
                    try {
                        data = await post({ command: command, bcode: bcode });
                        if (data.error && data.connected === undefined) data = { connected: false, error: data.error };
                    } catch (err) {
                        data = { connected: false, error: err.message };
                    }
                    addBranchResult(bcode, data, totals);
                    done++;
                    progressBar.style.transform = 'scaleX(' + done / codes.length + ')';
                    summary.textContent = done + ' / ' + codes.length + ' branches';
                }
            }
            await Promise.all(Array.from({ length: CONCURRENCY }, worker));

            summary.textContent = codes.length + ' branch' + (codes.length === 1 ? '' : 'es')
                + (totals.down ? ' · ' + totals.down + ' not connecting' : '')
                + (totals.failed ? ' · ' + totals.failed + ' failed' : '');
            runButton.disabled = false;
            document.getElementById('runLabel').textContent = 'Run';
            hideBarTimer = setTimeout(() => progressTrack.classList.add('opacity-0'), 400);
        }

        runButton.addEventListener('click', run);

        // ---------- Cell selection, keyboard navigation and copy (like SQL Developer) ----------
        // Click a cell, move with the arrow keys, extend a block with Shift+Arrow or Shift+click, Ctrl+A for the
        // whole table. Ctrl+C copies the block tab-separated (Ctrl+Shift+C adds the column headers). Copying uses
        // the native copy event, so it also works when the page is opened over plain http (no Clipboard API there).
        let sel = null; // { tbody, anchor: [row, col], active: [row, col] }
        let painted = [];
        let copyWithHeaders = false;
        let copiedTimer;

        const cellAt = (tbody, r, c) => (tbody.rows[r] ? tbody.rows[r].cells[c] : null);
        const posOf = (td) => [td.parentElement.sectionRowIndex, td.cellIndex];

        function paint() {
            painted.forEach((td) => td.classList.remove('cell-selected', 'cell-in-range'));
            painted = [];
            if (!sel) return;
            const [r1, r2] = [Math.min(sel.anchor[0], sel.active[0]), Math.max(sel.anchor[0], sel.active[0])];
            const [c1, c2] = [Math.min(sel.anchor[1], sel.active[1]), Math.max(sel.anchor[1], sel.active[1])];
            for (let r = r1; r <= r2; r++) {
                for (let c = c1; c <= c2; c++) {
                    const td = cellAt(sel.tbody, r, c);
                    if (td) { td.classList.add('cell-in-range'); painted.push(td); }
                }
            }
            const active = cellAt(sel.tbody, sel.active[0], sel.active[1]);
            if (active) {
                active.classList.add('cell-selected');
                painted.push(active);
                active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        }

        function selectCell(td, extend = false) {
            if (!td) { sel = null; return paint(); }
            const tbody = td.closest('tbody');
            if (extend && sel && sel.tbody === tbody) {
                sel.active = posOf(td);
            } else {
                sel = { tbody: tbody, anchor: posOf(td), active: posOf(td) };
            }
            paint();
        }

        // Shift+click would otherwise start a text selection instead of extending the block.
        results.addEventListener('mousedown', (e) => {
            if (e.shiftKey && e.target.closest('td') && sel) e.preventDefault();
        });
        results.addEventListener('click', (e) => {
            const td = e.target.closest('td');
            if (td && results.contains(td) && td.closest('tbody')) selectCell(td, e.shiftKey);
        });
        document.addEventListener('click', (e) => {
            if (sel && !e.target.closest('#results')) selectCell(null);
        });

        const typingSomewhere = () => !!document.activeElement.closest('input, textarea, .CodeMirror');

        document.addEventListener('keydown', (e) => {
            if (!sel || !document.body.contains(sel.tbody) || typingSomewhere()) return;

            if (e.key === 'Escape') return selectCell(null);
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c') {
                copyWithHeaders = e.shiftKey;
                if (e.shiftKey) { e.preventDefault(); document.execCommand('copy'); } // Ctrl+Shift+C has no native copy
                return;
            }
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
                const rows = sel.tbody.rows.length, cols = sel.tbody.rows[0].cells.length;
                sel.anchor = [0, 0];
                sel.active = [rows - 1, cols - 1];
                e.preventDefault();
                return paint();
            }

            const moves = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] };
            if (!moves[e.key]) return;
            e.preventDefault();
            const [dr, dc] = moves[e.key];
            const rows = sel.tbody.rows.length;
            const cols = sel.tbody.rows[sel.active[0]].cells.length;
            // Ctrl+Arrow jumps to the edge, like a spreadsheet.
            const r = e.ctrlKey ? (dr < 0 ? 0 : dr > 0 ? rows - 1 : sel.active[0]) : Math.max(0, Math.min(rows - 1, sel.active[0] + dr));
            const c = e.ctrlKey ? (dc < 0 ? 0 : dc > 0 ? cols - 1 : sel.active[1]) : Math.max(0, Math.min(cols - 1, sel.active[1] + dc));
            sel.active = [r, c];
            if (!e.shiftKey) sel.anchor = [r, c];
            paint();
        });

        document.addEventListener('copy', (e) => {
            if (!sel || !document.body.contains(sel.tbody)) return;
            // Leave normal copying alone: highlighted text, or typing in the editor / an input.
            if (!window.getSelection().isCollapsed || typingSomewhere()) return;

            const [r1, r2] = [Math.min(sel.anchor[0], sel.active[0]), Math.max(sel.anchor[0], sel.active[0])];
            const [c1, c2] = [Math.min(sel.anchor[1], sel.active[1]), Math.max(sel.anchor[1], sel.active[1])];
            const single = r1 === r2 && c1 === c2 && !copyWithHeaders;
            const text = (td) => {
                if (!td || td.hasAttribute('data-null')) return '';
                const v = td.textContent.trim();
                return single ? v : v.replace(/[\t\r\n]+/g, ' '); // keep the tab-separated grid intact
            };
            const lines = [];
            if (copyWithHeaders) {
                const ths = sel.tbody.closest('table').tHead.rows[0].cells;
                lines.push(Array.from({ length: c2 - c1 + 1 }, (_, i) => text(ths[c1 + i])).join('\t'));
            }
            for (let r = r1; r <= r2; r++) {
                lines.push(Array.from({ length: c2 - c1 + 1 }, (_, i) => text(cellAt(sel.tbody, r, c1 + i))).join('\t'));
            }
            copyWithHeaders = false;

            e.clipboardData.setData('text/plain', lines.join('\n'));
            e.preventDefault();

            // Keyboard action: instant feedback, no animation.
            const before = summary.dataset.text ?? summary.textContent;
            summary.dataset.text = before;
            summary.innerHTML = '<span class="text-drl-700 font-medium">Copied</span>';
            clearTimeout(copiedTimer);
            copiedTimer = setTimeout(() => {
                summary.textContent = summary.dataset.text;
                delete summary.dataset.text;
            }, 1200);
        });

        // ---------- Resizable results pane ----------
        const RESULTS_KEY = 'commands.resultsHeight';
        const MIN_RESULTS = 240; // px
        const MIN_EDITOR = 120; // px
        const workArea = document.getElementById('workArea');
        const resultsPane = document.getElementById('resultsPane');
        const handle = document.getElementById('resizeHandle');

        function setResultsHeight(px, save = true) {
            const max = workArea.clientHeight - MIN_EDITOR;
            // If the window is too short for both, the results minimum wins.
            const h = Math.round(Math.max(MIN_RESULTS, Math.min(px, max)));
            resultsPane.style.height = h + 'px';
            handle.setAttribute('aria-valuenow', String(h));
            if (save) {
                try { localStorage.setItem(RESULTS_KEY, String(h)); } catch (e) {}
            }
            editor.refresh();
        }

        // Pointer capture keeps the drag going even when the pointer leaves the thin handle.
        handle.addEventListener('pointerdown', (e) => {
            if (e.button !== 0) return;
            e.preventDefault();
            handle.setPointerCapture(e.pointerId);
            document.body.style.cursor = 'row-resize';
            document.body.style.userSelect = 'none';
        });
        handle.addEventListener('pointermove', (e) => {
            if (!handle.hasPointerCapture(e.pointerId)) return;
            setResultsHeight(workArea.getBoundingClientRect().bottom - e.clientY, false);
        });
        const endDrag = (e) => {
            if (!handle.hasPointerCapture(e.pointerId)) return;
            handle.releasePointerCapture(e.pointerId);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            setResultsHeight(resultsPane.getBoundingClientRect().height);
        };
        handle.addEventListener('pointerup', endDrag);
        handle.addEventListener('pointercancel', endDrag);

        handle.addEventListener('dblclick', () => {
            try { localStorage.removeItem(RESULTS_KEY); } catch (e) {}
            setResultsHeight(workArea.clientHeight * 0.42, false);
        });
        handle.addEventListener('keydown', (e) => {
            const step = e.shiftKey ? 96 : 24;
            if (e.key === 'ArrowUp') setResultsHeight(resultsPane.offsetHeight + step);
            else if (e.key === 'ArrowDown') setResultsHeight(resultsPane.offsetHeight - step);
            else return;
            e.preventDefault();
        });

        // Restore the saved size, and keep it within bounds when the window changes size.
        let savedHeight = null;
        try { savedHeight = parseInt(localStorage.getItem(RESULTS_KEY), 10) || null; } catch (e) {}
        setResultsHeight(savedHeight || workArea.clientHeight * 0.42, false);
        window.addEventListener('resize', () => setResultsHeight(resultsPane.offsetHeight, false));
    })();
</script>
<?php include __DIR__ . "/../footer.php"; ?>
