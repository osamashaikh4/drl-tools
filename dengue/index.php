<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../conn.php';

$branches = [];
if ($conn && ($st = @oci_parse($conn, "SELECT bcode, bname FROM branches ORDER BY bname")) && @oci_execute($st)) {
    while ($row = oci_fetch_array($st, OCI_ASSOC + OCI_RETURN_NULLS)) {
        $branches[$row['BCODE']] = $row['BNAME'];
    }
}

include __DIR__ . '/../header.php';
?>

<div class="max-w-xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
        <a href="../index.php" class="text-xs font-semibold text-slate-500 hover:text-blue-600 flex items-center gap-1 mb-2">
            ← Back to Dashboard
        </a>
        <h1 class="text-2xl font-bold text-slate-900">Dengue Report</h1>
        <p class="text-sm text-slate-500">Positive dengue (SER-21) cases and per-branch counts for a period, from the 253 database, in one Excel file.</p>
    </div>

    <form id="dengueForm" class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm space-y-5">
        <?php if (!$branches): ?>
            <p class="text-sm text-red-600">Could not load the branch list from the 253 database.</p>
        <?php endif; ?>
        <div>
            <label for="bcode" class="block text-sm font-semibold text-slate-700 mb-1">Branches <span class="font-normal text-slate-400">(none picked = all)</span></label>
            <select id="bcode" multiple class="w-full">
                <?php foreach ($branches as $bcode => $bname): ?>
                    <option value="<?= htmlspecialchars($bcode) ?>"><?= htmlspecialchars($bname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="from" class="block text-sm font-semibold text-slate-700 mb-1">From <span class="text-red-500">*</span></label>
            <input type="date" id="from" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>
        <div>
            <label for="to" class="block text-sm font-semibold text-slate-700 mb-1">To (inclusive) <span class="text-red-500">*</span></label>
            <input type="date" id="to" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div class="space-y-2 pt-1">
            <button id="runButton" type="submit" class="w-full px-6 py-3 rounded-xl bg-blue-600 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 hover:bg-blue-500 inline-flex items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed">
                <span id="runSpinner" class="spinner hidden" aria-hidden="true"></span>
                <svg id="runIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                <span id="runLabel">Download Excel</span>
            </button>
            <p id="status" class="text-xs text-slate-500 text-center tabular-nums min-h-4"></p>
        </div>
    </form>
</div>

<script>
    $(function() {
        $('#bcode').select2({
            placeholder: 'All branches',
            width: '100%'
        });

        function setRunning(on) {
            $('#runButton').prop('disabled', on);
            $('#runSpinner').toggleClass('hidden', !on);
            $('#runIcon').toggleClass('hidden', on);
            $('#runLabel').text(on ? 'Preparing…' : 'Download Excel');
        }

        function showError(msg) {
            $('#status').empty().append($('<span class="text-red-600">').text(msg));
            iziToast.error({
                title: 'Failed',
                message: msg,
                position: 'topRight'
            });
        }

        $('#dengueForm').on('submit', async function(e) {
            e.preventDefault();
            const from = $('#from').val(),
                to = $('#to').val();
            if (!from || !to || from > to) return iziToast.warning({
                title: 'Dates',
                message: 'Pick a From date on or before the To date',
                position: 'topRight'
            });

            const body = new FormData();
            body.append('from', from);
            body.append('to', to);
            ($('#bcode').val() || []).forEach((b) => body.append('bcode[]', b));

            setRunning(true);
            const started = Date.now();
            const elapsed = () => Math.round((Date.now() - started) / 1000) + 's';
            const tick = setInterval(() => $('#status').text('Running the queries … ' + elapsed()), 1000);
            $('#status').text('Running the queries …');
            try {
                const res = await fetch('export.php', {
                    method: 'POST',
                    body
                });
                if (!res.ok) {
                    let msg = 'The export failed (HTTP ' + res.status + ')';
                    try {
                        msg = (await res.json()).error || msg;
                    } catch (_) {}
                    if (res.status === 401) msg = 'Signed out, sign in again';
                    return showError(msg);
                }
                // The file name the server chose: filename*=UTF-8''Dengue%20Report%20(…).xlsx
                const cd = res.headers.get('Content-Disposition') || '';
                const m = cd.match(/filename\*=UTF-8''([^;]+)/i);
                const name = m ? decodeURIComponent(m[1]) : 'Dengue Report.xlsx';
                const url = URL.createObjectURL(await res.blob());
                const a = document.createElement('a');
                a.href = url;
                a.download = name;
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(() => URL.revokeObjectURL(url), 10000);
                $('#status').text('Downloaded in ' + elapsed() + '.');
            } catch (err) {
                showError('Could not reach the server');
            } finally {
                clearInterval(tick);
                setRunning(false);
            }
        });
    });
</script>
<?php include __DIR__ . '/../footer.php'; ?>
