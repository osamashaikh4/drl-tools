<?php
require_once __DIR__ . '/../auth.php';

include_once __DIR__ . '/../conn.php';
include_once __DIR__ . '/card_functions.php';
include __DIR__ . '/header.php';

$branches = card_branches_list($conn, $connections);
$iccode   = trim($_GET['iccode'] ?? '');
?>

<div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
        <a href="index.php" class="text-xs font-semibold text-slate-500 hover:text-blue-600 flex items-center gap-1 mb-2">
            ← Back to Dashboard
        </a>
        <h1 class="text-2xl font-bold text-slate-900">Check Card</h1>
        <p class="text-sm text-slate-500">Find which branches have a card number.</p>
    </div>

    <form id="checkForm" class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm mb-6 flex flex-wrap items-end gap-4">
        <div class="flex-1 min-w-[220px]">
            <label for="iccode" class="block text-sm font-semibold text-slate-700 mb-1">Card Number</label>
            <input required type="text" id="iccode" name="iccode" maxlength="20" value="<?= htmlspecialchars($iccode) ?>" placeholder="e.g. A-R-NG-370" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
        </div>
        <button id="runCheck" type="submit" class="px-6 py-2.5 rounded-lg bg-sky-600 hover:bg-sky-500 text-sm font-semibold text-white shadow disabled:opacity-60 disabled:cursor-not-allowed">Check All Branches</button>
        <span id="progress" class="text-xs text-slate-500 pb-3 tabular-nums"></span>
        <div id="progressTrack" class="basis-full h-1 rounded-full bg-slate-100 overflow-hidden opacity-0 transition-opacity">
            <div id="progressBar" class="progress-bar h-full bg-sky-600" style="transform: scaleX(0)"></div>
        </div>
    </form>

    <div id="summary" class="hidden mb-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4"><div class="text-xs font-bold uppercase tracking-wider text-slate-500">Found on</div><div class="text-2xl font-bold tabular-nums text-emerald-600" id="sumFound">0</div></div>
        <div class="bg-white rounded-xl border border-slate-200 p-4"><div class="text-xs font-bold uppercase tracking-wider text-slate-500">Not found on</div><div class="text-2xl font-bold tabular-nums text-slate-700" id="sumMissing">0</div></div>
        <div class="bg-white rounded-xl border border-slate-200 p-4"><div class="text-xs font-bold uppercase tracking-wider text-slate-500">Not connecting</div><div class="text-2xl font-bold tabular-nums text-red-600" id="sumDown">0</div></div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 text-left">Branch</th>
                    <th class="px-4 py-3 text-left">Result</th>
                    <th class="px-4 py-3 text-left">Card Holder</th>
                    <th class="px-4 py-3 text-left">Category</th>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-left">Expiry</th>
                    <th class="px-4 py-3 text-left">Holders</th>
                </tr>
            </thead>
            <tbody id="resultsBody" class="divide-y divide-slate-100">
                <?php foreach ($branches as $bcode => $bname): ?>
                    <tr class="hover:bg-slate-50" data-bcode="<?= htmlspecialchars($bcode) ?>">
                        <td class="px-4 py-2.5 font-semibold text-slate-800 whitespace-nowrap"><?= htmlspecialchars($bcode . ' - ' . $bname) ?></td>
                        <td class="px-4 py-2.5 result-cell text-slate-400">&mdash;</td>
                        <td class="px-4 py-2.5 holder-cell"></td>
                        <td class="px-4 py-2.5 category-cell"></td>
                        <td class="px-4 py-2.5 status-cell"></td>
                        <td class="px-4 py-2.5 expiry-cell whitespace-nowrap"></td>
                        <td class="px-4 py-2.5 holders-cell"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    (function() {
        const CONCURRENCY = 5;
        const rows = Array.from(document.querySelectorAll('#resultsBody tr'));
        const form = document.getElementById('checkForm');
        const input = document.getElementById('iccode');
        const runBtn = document.getElementById('runCheck');
        const progressEl = document.getElementById('progress');
        const progressTrack = document.getElementById('progressTrack');
        const progressBar = document.getElementById('progressBar');
        const SPINNER = '<span class="spinner" aria-hidden="true"></span>';
        const EMPTY = { 'holder-cell': '', 'category-cell': '', 'status-cell': '', 'expiry-cell': '', 'holders-cell': '' };
        const totals = { found: 0, missing: 0, down: 0 };

        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        function setCells(row, values) {
            Object.entries(values).forEach(([cls, html]) => { row.querySelector('.' + cls).innerHTML = html; });
        }

        function updateSummary() {
            document.getElementById('summary').classList.remove('hidden');
            document.getElementById('sumFound').textContent = totals.found;
            document.getElementById('sumMissing').textContent = totals.missing;
            document.getElementById('sumDown').textContent = totals.down;
        }

        async function checkBranch(row, iccode) {
            const bcode = row.dataset.bcode;
            setCells(row, Object.assign({ 'result-cell': '<span class="text-amber-500">' + SPINNER + ' Checking…</span>' }, EMPTY));

            try {
                const resp = await fetch('ajax_check_card.php?' + new URLSearchParams({ bcode: bcode, iccode: iccode }));
                const data = await resp.json();

                if (!data.connected) {
                    totals.down++;
                    setCells(row, { 'result-cell': '<span class="text-red-600 font-semibold" title="' + esc(data.error) + '">Not connecting</span>' });
                    return;
                }
                if (!data.found) {
                    totals.missing++;
                    setCells(row, { 'result-cell': '<span class="text-slate-500">Not found</span>' });
                    return;
                }

                totals.found++;
                const c = data.card;
                const active = String(c.ICSTATUS || '').toUpperCase() === 'Y';
                const holderList = data.holders.map((h) =>
                    '<div class="whitespace-nowrap"><span class="font-mono text-slate-500">' + esc(h.ICHECODE) + '</span> · ' + esc(h.RNAME || h.RCODE) + ' · ' + esc(h.ICHENAME) + '</div>'
                ).join('');

                setCells(row, {
                    'result-cell': '<span class="inline-flex px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-semibold">Found</span>'
                        + (c.ICCODE !== iccode ? ' <span class="font-mono text-xs text-slate-500">' + esc(c.ICCODE) + '</span>' : ''),
                    'holder-cell': esc(c.HOLDER),
                    'category-cell': esc(c.IC_CCODE + (c.IC_CNAME ? ' - ' + c.IC_CNAME : '')),
                    'status-cell': active ? '<span class="text-emerald-600 font-semibold">Active</span>' : '<span class="text-red-600 font-semibold">' + esc(c.ICSTATUS || 'blank') + '</span>',
                    'expiry-cell': esc(c.EXPIRY || ''),
                    'holders-cell': data.holders.length
                        ? '<details><summary class="cursor-pointer text-sky-700 font-semibold">' + data.holders.length + ' holder' + (data.holders.length === 1 ? '' : 's') + '</summary><div class="mt-1 text-xs space-y-0.5">' + holderList + '</div></details>'
                        : '<span class="text-amber-600">none</span>',
                });
            } catch (err) {
                totals.down++;
                setCells(row, { 'result-cell': '<span class="text-red-600 font-semibold" title="' + esc(err.message) + '">Error</span>' });
            } finally {
                updateSummary();
                // Fade the branch's result in when it arrives.
                row.classList.remove('row-in');
                void row.offsetWidth;
                row.classList.add('row-in');
            }
        }

        let hideBarTimer;

        async function runAll(iccode) {
            runBtn.disabled = true;
            totals.found = totals.missing = totals.down = 0;
            updateSummary();

            let idx = 0, done = 0;
            progressEl.textContent = `0 / ${rows.length}`;
            clearTimeout(hideBarTimer);
            progressTrack.classList.remove('opacity-0');
            progressBar.style.transform = 'scaleX(0)';
            // Clear the previous card's results so stale rows can't be mistaken for this one.
            rows.forEach((row) => setCells(row, Object.assign({ 'result-cell': '<span class="text-slate-400">Waiting…</span>' }, EMPTY)));

            async function worker() {
                while (idx < rows.length) {
                    await checkBranch(rows[idx++], iccode);
                    progressEl.textContent = `${++done} / ${rows.length}`;
                    progressBar.style.transform = `scaleX(${done / rows.length})`;
                }
            }

            await Promise.all(Array.from({ length: CONCURRENCY }, worker));
            runBtn.disabled = false;
            // Let the full bar register for a moment, then fade it away.
            hideBarTimer = setTimeout(() => progressTrack.classList.add('opacity-0'), 400);
        }

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const iccode = input.value.trim();
            if (!iccode || runBtn.disabled) return;
            // Keep the card number in the URL so the result can be shared or reloaded.
            history.replaceState(null, '', '?iccode=' + encodeURIComponent(iccode));
            runAll(iccode);
        });

        if (input.value.trim()) {
            runAll(input.value.trim());
        }
    })();
</script>
</body>

</html>
