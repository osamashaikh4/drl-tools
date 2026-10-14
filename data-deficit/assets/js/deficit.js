(function () {
    const CONCURRENCY = 5;
    const rows = Array.from(document.querySelectorAll('#resultsBody tr'));
    const runBtn = document.getElementById('runCheck');
    const progressEl = document.getElementById('progress');
    const resultsBody = document.getElementById('resultsBody');
    const modal = document.getElementById('resultModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalBody = document.getElementById('modalBody');

    function showModal(title, message, variant) {
        modalTitle.textContent = title;
        modalTitle.className = variant === 'error' ? 'text-base font-bold text-red-600' : 'text-base font-bold text-slate-900';
        modalBody.textContent = message;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function hideModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    document.getElementById('modalClose').addEventListener('click', hideModal);
    document.getElementById('modalOk').addEventListener('click', hideModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) hideModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) hideModal();
    });

    function fmtCount(local, branch, deficit) {
        if (local === undefined || branch === undefined) return '&mdash;';
        const cls = deficit ? 'text-red-600 font-bold' : 'text-emerald-600';
        return `<span class="${cls}">${local} / ${branch}</span>`;
    }

    async function checkBranch(row) {
        const bcode = row.dataset.bcode;
        const statusCell = row.querySelector('.status-cell');
        const piCell = row.querySelector('.pi-cell');
        const pbCell = row.querySelector('.pb-cell');
        const pcCell = row.querySelector('.pc-cell');
        const actionCell = row.querySelector('.action-cell');

        statusCell.textContent = 'Checking…';
        statusCell.className = 'px-4 py-2.5 status-cell text-amber-500';

        try {
            const resp = await fetch('check.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    bcode: bcode,
                    pfrom: document.getElementById('pfrom').value,
                    pto: document.getElementById('pto').value,
                }),
            });
            const data = await resp.json();

            if (!data.connected) {
                statusCell.textContent = 'Not Connecting';
                statusCell.className = 'px-4 py-2.5 status-cell text-red-600 font-semibold';
                statusCell.title = data.error || '';
                piCell.innerHTML = pbCell.innerHTML = pcCell.innerHTML = '&mdash;';
                actionCell.innerHTML = '';
                return;
            }

            if (data.error) {
                statusCell.textContent = 'Error';
                statusCell.className = 'px-4 py-2.5 status-cell text-red-600 font-semibold';
                statusCell.title = data.error;
                actionCell.innerHTML = '';
                return;
            }

            statusCell.textContent = 'Connected';
            statusCell.className = 'px-4 py-2.5 status-cell text-emerald-600 font-semibold';
            statusCell.title = '';

            // A deficit only exists when 253 has fewer rows than the branch.
            // If 253 has the same or more, there is nothing to fill.
            const piDeficit = data.local.p_investigations < data.branch.p_investigations;
            const pbDeficit = data.local.pbilling < data.branch.pbilling;
            const pcDeficit = data.local.PBCANCEL < data.branch.PBCANCEL;

            piCell.innerHTML = fmtCount(data.local.p_investigations, data.branch.p_investigations, piDeficit);
            pbCell.innerHTML = fmtCount(data.local.pbilling, data.branch.pbilling, pbDeficit);
            pcCell.innerHTML = fmtCount(data.local.PBCANCEL, data.branch.PBCANCEL, pcDeficit);

            let actions = '';
            // PBILLING is filled by Group A, so a billing shortfall offers it too.
            if (piDeficit || pbDeficit) {
                actions += `<button class="fill-btn text-xs font-semibold text-white bg-orange-600 hover:bg-orange-500 px-2.5 py-1 rounded mr-1" data-bcode="${bcode}" data-group="A">Fill Group A</button>`;
            }
            if (pcDeficit) {
                actions += `<button class="fill-btn text-xs font-semibold text-white bg-purple-600 hover:bg-purple-500 px-2.5 py-1 rounded" data-bcode="${bcode}" data-group="B">Fill Group B</button>`;
            }
            const allEqual = data.local.p_investigations === data.branch.p_investigations
                && data.local.pbilling === data.branch.pbilling
                && data.local.PBCANCEL === data.branch.PBCANCEL;
            actionCell.innerHTML = actions
                || `<span class="text-xs text-slate-400">${allEqual ? 'In sync' : 'No deficit (253 has more)'}</span>`;
        } catch (err) {
            statusCell.textContent = 'Error';
            statusCell.className = 'px-4 py-2.5 status-cell text-red-600 font-semibold';
            statusCell.title = err.message;
            actionCell.innerHTML = '';
        }
    }

    async function runAll() {
        runBtn.disabled = true;
        let done = 0;
        progressEl.textContent = `0 / ${rows.length}`;
        let idx = 0;

        async function worker() {
            while (idx < rows.length) {
                const row = rows[idx++];
                await checkBranch(row);
                done++;
                progressEl.textContent = `${done} / ${rows.length}`;
            }
        }

        await Promise.all(Array.from({ length: CONCURRENCY }, worker));
        runBtn.disabled = false;
    }

    runBtn.addEventListener('click', runAll);

    resultsBody.addEventListener('click', async (e) => {
        const btn = e.target.closest('.fill-btn');
        if (!btn) return;

        const bcode = btn.dataset.bcode;
        const group = btn.dataset.group;
        const dryRun = document.getElementById('dryrun').checked;
        const tables = window.FILL_GROUPS[group];

        // A dry run writes nothing, so it needs no confirmation.
        if (!dryRun && !confirm(`Fill Group ${group} (${tables.join(', ')}) for branch ${bcode}?\n\nThis will INSERT the missing rows into these tables on 253.`)) {
            return;
        }

        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = dryRun ? 'Checking…' : 'Filling…';

        const row = btn.closest('tr');
        const verb = dryRun ? 'Dry run' : 'Filling';
        const state = tables.map((t) => ({ table: t, line: 'waiting', sql: '', inserts: [], omitted: 0 }));

        const render = (title, variant) => {
            const summary = state.map((s) => `${s.table}: ${s.line}`).join('\n');
            const detail = state
                .filter((s) => s.sql || s.inserts.length)
                .map((s) => {
                    let block = `\n===== ${s.table} =====\n-- SELECT\n${s.sql};\n`;
                    if (s.inserts.length) {
                        block += `\n-- INSERT${dryRun ? ' (dry run, not executed)' : ''}\n` + s.inserts.map((q) => q + ';').join('\n') + '\n';
                    }
                    if (s.omitted) {
                        block += `-- ${s.omitted} more insert(s) not shown, see logs/queries.log\n`;
                    }
                    return block;
                })
                .join('');
            const modalOpenAndScrolled = !modal.classList.contains('hidden') && modalBody.scrollTop > 0;
            const scroll = modalBody.scrollTop;
            showModal(title, summary + (detail ? '\n' + detail : ''), variant);
            if (modalOpenAndScrolled) modalBody.scrollTop = scroll;
        };

        render(`${verb} — branch ${bcode}`);

        for (const s of state) {
            s.line = 'running…';
            render(`${verb} — branch ${bcode}`);

            try {
                const resp = await fetch('fill.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        bcode: bcode,
                        group: group,
                        table: s.table,
                        dryrun: dryRun ? '1' : '0',
                        pfrom: document.getElementById('pfrom').value,
                        pto: document.getElementById('pto').value,
                    }),
                });
                const body = await resp.text();
                let data;
                try {
                    data = JSON.parse(body);
                } catch (parseErr) {
                    throw new Error(`HTTP ${resp.status}, response was not JSON`
                        + (dryRun ? '' : ' (this table may already be committed; check logs/queries.log)')
                        + `:\n${body.slice(0, 1500) || '(empty response)'}`);
                }

                if (!data.ok) {
                    s.line = `FAILED after ${data.seconds}s — ${data.error}`;
                    render(`${verb} failed — branch ${bcode}`, 'error');
                    btn.disabled = false;
                    btn.textContent = originalText;
                    return;
                }

                s.sql = data.sql || '';
                s.inserts = data.inserts || [];
                s.omitted = data.insertsOmitted || 0;
                if (!s.sql) {
                    s.line = `no missing EMRs, nothing to check (${data.seconds}s)`;
                    render(`${verb} — branch ${bcode}`);
                    continue;
                }

                s.line = dryRun
                    ? `would insert ${data.found} (${data.seconds}s)`
                    : `${data.inserted} inserted`
                        + (data.skipped ? `, ${data.skipped} skipped (already present)` : '')
                        + ` — ${data.found} found in ${data.seconds}s`;
            } catch (err) {
                s.line = `FAILED — ${err.message}`;
                render(`${verb} failed — branch ${bcode}`, 'error');
                btn.disabled = false;
                btn.textContent = originalText;
                return;
            }

            render(`${verb} — branch ${bcode}`);
        }

        if (dryRun) {
            render(`Dry run complete — branch ${bcode} (nothing was inserted)`);
            btn.disabled = false;
            btn.textContent = originalText;
            return;
        }

        render(`Fill complete — branch ${bcode}`);
        checkBranch(row);
    });
})();
