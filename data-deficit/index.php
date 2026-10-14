<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/deficit_lib.php';
include __DIR__ . '/header.php';

$prevMonthTs = strtotime('first day of last month');
$defaultFrom = date('Y-m-01', $prevMonthTs);
$defaultTo   = date('Y-m-t', $prevMonthTs);

$branchNames = loadBranchNames($conn);
?>

<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mb-6">
        <h1 class="text-xl font-bold text-slate-900 mb-1">Branch Data Deficit Check</h1>
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">From</label>
                <input type="date" id="pfrom" value="<?= htmlspecialchars($defaultFrom) ?>" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">To</label>
                <input type="date" id="pto" value="<?= htmlspecialchars($defaultTo) ?>" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <button id="runCheck" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm px-5 py-2.5 rounded-lg shadow">Check All Branches</button>
            <label class="hidden items-center gap-2 pb-2.5 cursor-pointer">
                <input type="checkbox" id="dryrun" class="h-4 w-4 rounded border-slate-300 text-blue-600">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-600">Dry run (count only, no inserts)</span>
            </label>
            <span id="progress" class="text-xs text-slate-500"></span>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 text-left">Branch</th>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-right">p_investigations (253 / branch)</th>
                    <th class="px-4 py-3 text-right">pbilling (253 / branch)</th>
                    <th class="px-4 py-3 text-right">PBCANCEL (253 / branch)</th>
                    <th class="px-4 py-3 text-left">Action</th>
                </tr>
            </thead>
            <tbody id="resultsBody">
                <?php foreach ($connections as $bcode => $ip): ?>
                    <tr id="row-<?= htmlspecialchars($bcode) ?>" data-bcode="<?= htmlspecialchars($bcode) ?>">
                        <td class="px-4 py-2.5 font-semibold text-slate-800"><?= htmlspecialchars($branchNames[$bcode] ?? $bcode) ?></td>
                        <td class="px-4 py-2.5 status-cell text-slate-400">Pending&hellip;</td>
                        <td class="px-4 py-2.5 text-right pi-cell">&mdash;</td>
                        <td class="px-4 py-2.5 text-right pb-cell">&mdash;</td>
                        <td class="px-4 py-2.5 text-right pc-cell">&mdash;</td>
                        <td class="px-4 py-2.5 action-cell"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="resultModal" class="hidden fixed inset-0 bg-slate-900/50 items-center justify-center px-4 z-50">
    <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-5xl w-full p-6">
        <div class="flex items-start justify-between mb-3 gap-4">
            <h3 id="modalTitle" class="text-base font-bold text-slate-900"></h3>
            <button id="modalClose" type="button" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <pre id="modalBody" class="text-xs text-slate-700 whitespace-pre-wrap break-words font-mono max-h-[70vh] overflow-auto bg-slate-50 border border-slate-200 rounded-lg p-3"></pre>
        <div class="mt-5 flex justify-end">
            <button id="modalOk" type="button" class="bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold px-4 py-2 rounded-lg">OK</button>
        </div>
    </div>
</div>

<script>
    window.FILL_GROUPS = <?= json_encode(['A' => GROUP_A_TABLES, 'B' => GROUP_B_TABLES]) ?>;
</script>
<script src="assets/js/deficit.js"></script>
<?php include __DIR__ . '/../footer.php'; ?>