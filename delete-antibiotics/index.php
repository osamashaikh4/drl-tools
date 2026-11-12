<?php
require_once __DIR__ . '/../auth.php';

include __DIR__ . '/../header.php';
?>

<div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
        <a href="../index.php" class="text-xs font-semibold text-slate-500 hover:text-blue-600 flex items-center gap-1 mb-2">
            ← Back to Dashboard
        </a>
        <h1 class="text-2xl font-bold text-slate-900">Delete Antibiotics</h1>
        <p class="text-sm text-slate-500 mt-1">
            Finds the PIPARAMVDTL rows of each lab no on its branch, copies them to
            <span class="font-medium text-slate-700">deleted antibiotics.xlsx</span> and then deletes them.
        </p>
    </div>

    <form id="findForm" class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm space-y-4">
        <div>
            <label for="labNos" class="block text-sm font-semibold text-slate-700 mb-1">Lab Nos</label>
            <textarea id="labNos" rows="6" spellcheck="false"
                class="w-full rounded-lg border-slate-300 border text-sm p-2.5 font-mono"></textarea>
        </div>
        <button id="findButton" type="submit" class="w-full px-6 py-3 rounded-xl bg-blue-600 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 hover:bg-blue-500 inline-flex items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed">
            <span id="findSpinner" class="spinner hidden" aria-hidden="true"></span>
            <span id="findLabel">Find Rows</span>
        </button>
    </form>

    <div id="results" class="hidden mt-8 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p id="summary" class="text-sm text-slate-700 tabular-nums"></p>
            <button id="deleteButton" type="button" class="px-5 py-2.5 rounded-xl bg-red-600 text-sm font-semibold text-white shadow-lg shadow-red-500/20 hover:bg-red-500 inline-flex items-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed">
                <span id="deleteSpinner" class="spinner hidden" aria-hidden="true"></span>
                <span id="deleteLabel">Delete</span>
            </button>
        </div>
        <div id="labList" class="space-y-4"></div>
    </div>
</div>

<script>
    $(function() {
        let found = null; // {lab_no: row count} of the last preview, what Delete will remove

        const badge = {
            found: 'bg-blue-100 text-blue-700',
            none: 'bg-slate-100 text-slate-600',
            invalid: 'bg-amber-100 text-amber-700',
            error: 'bg-red-100 text-red-700',
            deleted: 'bg-emerald-100 text-emerald-700',
        };

        async function post(data) {
            const body = new FormData();
            Object.entries(data).forEach(([k, v]) => body.append(k, v));
            let res;
            try {
                res = await fetch('api.php', {
                    method: 'POST',
                    body
                });
            } catch (_) {
                throw new Error('Could not reach the server');
            }
            let json = null;
            try {
                json = await res.json();
            } catch (_) {}
            if (res.status === 401) throw new Error('Signed out, sign in again');
            if (!res.ok || !json) throw new Error((json && json.error) || 'The request failed (HTTP ' + res.status + ')');
            return json;
        }

        function showError(msg) {
            iziToast.error({
                title: 'Failed',
                message: msg,
                position: 'topRight'
            });
        }

        function setBusy(which, on, label) {
            $('#' + which + 'Button').prop('disabled', on);
            $('#' + which + 'Spinner').toggleClass('hidden', !on);
            if (label) $('#' + which + 'Label').text(label);
        }

        function labCard(lab, columns) {
            const card = $('<div class="row-in bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">');
            const head = $('<div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-slate-100">').appendTo(card);
            $('<span class="font-mono font-semibold text-slate-900">').text(lab.lab_no).appendTo(head);
            if (lab.bcode) $('<span class="text-xs text-slate-500">').text('Branch ' + lab.bcode).appendTo(head);
            $('<span class="text-xs font-semibold rounded-full px-2 py-0.5">').addClass(badge[lab.status] || badge.none).text(lab.message).appendTo(head);

            if (lab.rows && lab.rows.length) {
                const wrap = $('<div class="overflow-x-auto">').appendTo(card);
                const table = $('<table class="min-w-full text-xs">').appendTo(wrap);
                const tr = $('<tr class="bg-slate-50 text-slate-600">').appendTo($('<thead>').appendTo(table));
                columns.forEach((c) => $('<th class="px-3 py-2 text-left font-semibold whitespace-nowrap">').text(c).appendTo(tr));
                const tbody = $('<tbody class="divide-y divide-slate-100">').appendTo(table);
                lab.rows.forEach((row) => {
                    const r = $('<tr>').appendTo(tbody);
                    columns.forEach((c) => $('<td class="px-3 py-1.5 whitespace-nowrap font-mono">').text(row[c] ?? '').appendTo(r));
                });
            }
            return card;
        }

        // Editing the lab nos after a preview means Delete has to wait for a new one.
        $('#labNos').on('input', function() {
            if (found) {
                found = null;
                $('#deleteButton').prop('disabled', true);
                $('#deleteLabel').text('Find the rows again');
            }
        });

        $('#findForm').on('submit', async function(e) {
            e.preventDefault();
            const text = $('#labNos').val().trim();
            if (!text) return iziToast.warning({
                title: 'Lab Nos',
                message: 'Enter at least one lab no',
                position: 'topRight'
            });

            setBusy('find', true, 'Finding…');
            found = null;
            try {
                const data = await post({
                    action: 'preview',
                    lab_nos: text
                });
                const list = $('#labList').empty();
                const counts = {};
                let rows = 0;
                data.labs.forEach((lab) => {
                    list.append(labCard(lab, data.columns));
                    if (lab.status === 'found') {
                        counts[lab.lab_no] = lab.rows.length;
                        rows += lab.rows.length;
                    }
                });
                const labs = Object.keys(counts).length;
                const other = data.labs.length - labs;
                $('#summary').text(rows + ' row(s) in ' + labs + ' of ' + data.labs.length + ' lab no(s)' + (other ? ', ' + other + ' with nothing to delete' : ''));
                found = labs ? counts : null;
                $('#deleteButton').prop('disabled', !labs);
                $('#deleteLabel').text(labs ? 'Delete ' + rows + ' row(s)' : 'Nothing to delete');
                $('#results').removeClass('hidden');
            } catch (err) {
                showError(err.message);
            } finally {
                setBusy('find', false, 'Find Rows');
            }
        });

        $('#deleteButton').on('click', async function() {
            if (!found) return;
            const labs = Object.keys(found).length;
            const rows = Object.values(found).reduce((a, b) => a + b, 0);
            if (!confirm('Copy ' + rows + ' row(s) of ' + labs + ' lab no(s) to deleted antibiotics.xlsx and delete them from PIPARAMVDTL?')) return;

            const label = $('#deleteLabel').text();
            setBusy('delete', true, 'Deleting…');
            $('#findButton').prop('disabled', true);
            try {
                const data = await post({
                    action: 'delete',
                    labs: JSON.stringify(found)
                });
                found = null;
                const list = $('#labList').empty();
                let ok = 0,
                    okRows = 0;
                data.labs.forEach((lab) => {
                    list.append(labCard(lab, []));
                    if (lab.status === 'deleted') {
                        ok++;
                        okRows += lab.count;
                    }
                });
                const failed = data.labs.length - ok;
                $('#summary').text(okRows + ' row(s) of ' + ok + ' lab no(s) saved to Excel and deleted' + (failed ? ', ' + failed + ' failed' : ''));
                $('#deleteButton').prop('disabled', true);
                $('#deleteLabel').text('Done');
                if (data.error) showError(data.error);
                else if (failed) iziToast.warning({
                    title: 'Partly done',
                    message: failed + ' lab no(s) were not deleted',
                    position: 'topRight'
                });
                else iziToast.success({
                    title: 'Deleted',
                    message: okRows + ' row(s) saved to deleted antibiotics.xlsx and deleted',
                    position: 'topRight'
                });
            } catch (err) {
                showError(err.message);
                $('#deleteLabel').text(label);
                $('#deleteButton').prop('disabled', false);
            } finally {
                $('#deleteSpinner').addClass('hidden');
                $('#findButton').prop('disabled', false);
            }
        });
    });
</script>
<?php include __DIR__ . '/../footer.php'; ?>
