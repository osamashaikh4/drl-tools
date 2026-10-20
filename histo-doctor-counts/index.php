<?php
require_once __DIR__ . '/../bootstrap.php';

$doctors = require __DIR__ . '/doctors.php';

// Departments in sheet order. Histopathology only counts the FINAL CONCLUSION parameter (01030);
// cytology counts any parameter of a CYTO test that names the doctor.
$departments = [
    'HSP'  => ['title' => 'Histopathology', 'ipcode' => '01030'],
    'CYTO' => ['title' => 'Cytology',       'ipcode' => null],
];

if (isset($_GET['dept'])) {
    header('Content-Type: application/json');

    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['error' => 'Logged out']);
        exit();
    }

    // Release the session lock so both departments from one browser run at the same time.
    session_write_close();
    set_time_limit(600);

    $dept = (string) $_GET['dept'];
    $from = (string) ($_GET['from'] ?? '');
    $to   = (string) ($_GET['to'] ?? '');
    $isDate = fn($d) => ($t = DateTime::createFromFormat('!Y-m-d', $d)) && $t->format('Y-m-d') === $d;
    if (!isset($departments[$dept]) || !$isDate($from) || !$isDate($to) || $from > $to) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid department or dates']);
        exit();
    }

    require_once __DIR__ . '/../conn.php';
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not connect to the 253 database']);
        exit();
    }

    // One pass over PIPARAMV1 for every doctor: a column per doctor, same match as the
    // per-doctor query (UPPER(PIPVALUE) LIKE '%WORD%'), so a row naming two doctors counts for both.
    $binds = [':dfrom' => $from, ':dto' => $to, ':dcode' => $dept];
    $cols  = [];
    $anyOf = [];
    foreach ($doctors as $i => $doc) {
        $binds[":m{$i}"] = '%' . strtoupper($doc['match']) . '%';
        $cols[]  = "SUM(CASE WHEN UPPER(pv1.pipvalue) LIKE :m{$i} THEN 1 ELSE 0 END) AS d{$i}";
        $anyOf[] = "UPPER(pv1.pipvalue) LIKE :m{$i}";
    }
    if ($departments[$dept]['ipcode'] !== null) {
        $binds[':ipcode'] = $departments[$dept]['ipcode'];
    }
    $sql = "SELECT pv1.bcode, b.bname, " . implode(', ', $cols) . "
              FROM piparamv1 pv1, dwi, branches b, investigations i
             WHERE pv1.icode = dwi.icode
               AND pv1.bcode = b.bcode
               AND i.icode = dwi.icode
               AND dwi.dcode = :dcode
               " . ($departments[$dept]['ipcode'] !== null ? "AND pv1.ipcode = :ipcode" : "") . "
               AND pv1.piped >= TO_DATE(:dfrom, 'YYYY-MM-DD')
               AND pv1.piped <  TO_DATE(:dto, 'YYYY-MM-DD') + 1
               AND (" . implode(' OR ', $anyOf) . ")
             GROUP BY pv1.bcode, b.bname
             ORDER BY pv1.bcode";

    $started = microtime(true);
    $stmt = @oci_parse($conn, $sql);
    foreach ($binds as $name => $value) {
        oci_bind_by_name($stmt, $name, $binds[$name]);
    }
    if (!$stmt || !@oci_execute($stmt)) {
        $e = oci_error($stmt ?: $conn);
        http_response_code(500);
        echo json_encode(['error' => $e['message'] ?? 'Query failed']);
        exit();
    }

    $rows = [];
    while ($r = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS)) {
        $counts = [];
        foreach ($doctors as $i => $doc) {
            $counts[] = (int) $r['D' . $i];
        }
        $rows[] = ['bcode' => $r['BCODE'], 'bname' => (string) $r['BNAME'], 'counts' => $counts];
    }
    echo json_encode(['rows' => $rows, 'seconds' => round(microtime(true) - $started, 1)]);
    exit();
}

require_login();
include __DIR__ . '/../header.php';

// Default period: last month.
$defaultFrom = date('Y-m-01', strtotime('first day of last month'));
$defaultTo   = date('Y-m-t', strtotime('first day of last month'));
?>

<div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
        <a href="../index.php" class="text-xs font-semibold text-slate-500 hover:text-fuchsia-600 flex items-center gap-1 mb-2">
            ← Back to Dashboard
        </a>
        <h1 class="text-2xl font-bold text-slate-900">Histo Doctor Counts</h1>
        <p class="text-sm text-slate-500">Histopathology and cytology reports per doctor and branch for a period, from the 253 database.</p>
    </div>

    <form id="countsForm" class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label for="from" class="block text-sm font-semibold text-slate-700 mb-1">From <span class="text-red-500">*</span></label>
                <input type="date" id="from" required value="<?= $defaultFrom ?>" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-fuchsia-500 focus:border-fuchsia-500">
            </div>
            <div>
                <label for="to" class="block text-sm font-semibold text-slate-700 mb-1">To (inclusive) <span class="text-red-500">*</span></label>
                <input type="date" id="to" required value="<?= $defaultTo ?>" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-fuchsia-500 focus:border-fuchsia-500">
            </div>
        </div>

        <div>
            <p class="text-sm font-semibold text-slate-700 mb-2">Doctors</p>
            <div class="flex flex-wrap gap-x-5 gap-y-2">
                <?php foreach ($doctors as $i => $doc): ?>
                    <label class="inline-flex items-center gap-2 cursor-pointer text-sm text-slate-700">
                        <input type="checkbox" class="doctor w-4 h-4 rounded text-fuchsia-600 border-slate-300 focus:ring-fuchsia-500" value="<?= $i ?>" checked>
                        <?= htmlspecialchars($doc['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
            <p id="status" class="text-xs text-slate-500 tabular-nums">Each department scans PIPARAMV1 once for the period; a month takes about a minute.</p>
            <div class="flex gap-3">
                <button id="downloadButton" type="button" disabled class="px-5 py-2.5 rounded-xl border border-slate-200 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-100 inline-flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                    Download Excel
                </button>
                <button id="runButton" type="submit" class="px-6 py-2.5 rounded-xl bg-fuchsia-600 text-sm font-semibold text-white shadow-lg shadow-fuchsia-500/20 hover:bg-fuchsia-500 inline-flex items-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed">
                    <span id="runSpinner" class="spinner hidden" aria-hidden="true"></span>
                    <span id="runLabel">Get Counts</span>
                </button>
            </div>
        </div>
    </form>

    <div id="results" class="mt-8 space-y-6"></div>
</div>

<script src="../assets/js/xlsx.full.min.js"></script>
<script>
    $(function() {
        const DOCTORS = <?= json_encode(array_column($doctors, 'name')) ?>;
        const DEPTS = <?= json_encode(array_map(fn($d) => $d['title'], $departments)) ?>; // { HSP: 'Histopathology', CYTO: 'Cytology' }
        const SPINNER = '<span class="spinner" aria-hidden="true"></span>';
        const esc = (s) => $('<span>').text(s).html();
        const fmt = (n) => n.toLocaleString('en-US');

        let report = null; // { from, to, doctors: [index], data: { HSP: rows, CYTO: rows } }

        function setRunning(on) {
            $('#runButton').prop('disabled', on);
            $('#runSpinner').toggleClass('hidden', !on);
            $('#runLabel').text(on ? 'Counting…' : 'Get Counts');
        }

        // Rows of one department for one doctor: only branches where the doctor has reports.
        const rowsFor = (dept, d) => report.data[dept].filter((r) => r.counts[d] > 0).map((r) => ({ bcode: r.bcode, bname: r.bname, count: r.counts[d] }));
        const total = (rows) => rows.reduce((s, r) => s + r.count, 0);

        function deptTable(dept, rows) {
            const body = rows.length
                ? rows.map((r) => `<tr class="border-t border-slate-100"><td class="px-3 py-1.5 font-mono text-slate-500">${esc(r.bcode)}</td><td class="px-3 py-1.5">${esc(r.bname)}</td><td class="px-3 py-1.5 text-right tabular-nums">${fmt(r.count)}</td></tr>`).join('')
                : `<tr><td colspan="3" class="px-3 py-4 text-center text-slate-400">No reports</td></tr>`;
            return `<div class="min-w-0">
                <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-2">${DEPTS[dept]}</h3>
                <div class="overflow-x-auto rounded-xl border border-slate-200">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500 uppercase"><tr><th class="px-3 py-2 text-left w-16">BCode</th><th class="px-3 py-2 text-left">Branch</th><th class="px-3 py-2 text-right w-20">Count</th></tr></thead>
                        <tbody>${body}</tbody>
                        <tfoot class="bg-slate-50 font-semibold"><tr class="border-t border-slate-200"><td></td><td class="px-3 py-2">Total</td><td class="px-3 py-2 text-right tabular-nums">${fmt(total(rows))}</td></tr></tfoot>
                    </table>
                </div>
            </div>`;
        }

        function render() {
            const summary = report.doctors.map((d) => {
                const h = total(rowsFor('HSP', d)), c = total(rowsFor('CYTO', d));
                return `<tr class="border-t border-slate-100"><td class="px-4 py-2 font-semibold"><a href="#doc-${d}" class="hover:text-fuchsia-600">${esc(DOCTORS[d])}</a></td><td class="px-4 py-2 text-right tabular-nums">${fmt(h)}</td><td class="px-4 py-2 text-right tabular-nums">${fmt(c)}</td><td class="px-4 py-2 text-right tabular-nums font-semibold">${fmt(h + c)}</td></tr>`;
            }).join('');
            const cards = report.doctors.map((d) => `
                <section id="doc-${d}" class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm scroll-mt-4">
                    <h2 class="text-base font-bold text-slate-900 mb-4">${esc(DOCTORS[d])}</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">${deptTable('HSP', rowsFor('HSP', d))}${deptTable('CYTO', rowsFor('CYTO', d))}</div>
                </section>`).join('');
            $('#results').html(`
                <section class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
                    <h2 class="text-base font-bold text-slate-900 mb-4">Summary <span class="font-normal text-sm text-slate-500">${esc(report.from)} to ${esc(report.to)}</span></h2>
                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs text-slate-500 uppercase"><tr><th class="px-4 py-2 text-left">Doctor</th><th class="px-4 py-2 text-right">Histopathology</th><th class="px-4 py-2 text-right">Cytology</th><th class="px-4 py-2 text-right">Total</th></tr></thead>
                            <tbody>${summary}</tbody>
                        </table>
                    </div>
                </section>${cards}`);
        }

        $('#countsForm').on('submit', function(e) {
            e.preventDefault();
            const from = $('#from').val(), to = $('#to').val();
            const doctors = $('.doctor:checked').map((_, el) => +el.value).get();
            if (!from || !to || from > to) return iziToast.warning({ title: 'Dates', message: 'Pick a From date on or before the To date', position: 'topRight' });
            if (!doctors.length) return iziToast.warning({ title: 'Doctors', message: 'Tick at least one doctor', position: 'topRight' });

            setRunning(true);
            $('#downloadButton').prop('disabled', true);
            $('#results').empty();
            const started = Date.now();
            const tick = setInterval(() => $('#status').html(SPINNER + ' Counting ' + esc(from) + ' to ' + esc(to) + ' … ' + Math.round((Date.now() - started) / 1000) + 's'), 1000);
            $('#status').html(SPINNER + ' Counting ' + esc(from) + ' to ' + esc(to) + ' …');

            // Both departments run in parallel on the server.
            const calls = Object.keys(DEPTS).map((dept) => $.ajax({ url: 'index.php', data: { dept, from, to }, dataType: 'json', timeout: 600000 }));
            $.when(...calls)
                .done(function(...answers) {
                    const data = {};
                    Object.keys(DEPTS).forEach((dept, i) => { data[dept] = answers[i][0].rows; });
                    report = { from, to, doctors, data };
                    render();
                    $('#downloadButton').prop('disabled', false);
                    $('#status').text('Done in ' + Math.round((Date.now() - started) / 1000) + 's.');
                })
                .fail(function(xhr) {
                    const msg = (xhr.responseJSON && xhr.responseJSON.error) || (xhr.statusText === 'timeout' ? 'The query timed out' : 'The query failed');
                    $('#status').empty().append($('<span class="text-red-600">').text(msg));
                    iziToast.error({ title: 'Failed', message: msg, position: 'topRight' });
                })
                .always(function() { clearInterval(tick); setRunning(false); });
        });

        // Same layout as the monthly sheet: one sheet per doctor, histopathology in A–D, cytology in F–I.
        $('#downloadButton').on('click', function() {
            if (!report) return;
            const wb = XLSX.utils.book_new();
            report.doctors.forEach((d) => {
                const aoa = [['HISTOPATHOLOGY', null, null, null, null, 'CYTOLOGY'], ['BCODE', 'BRANCH NAME', 'DOCTOR', 'COUNTS', null, 'BCODE', 'BRANCH NAME', 'DOCTOR', 'COUNTS']];
                const ws = XLSX.utils.aoa_to_sheet(aoa);
                [['HSP', 0], ['CYTO', 5]].forEach(([dept, c]) => {
                    const rows = rowsFor(dept, d);
                    XLSX.utils.sheet_add_aoa(ws, rows.map((r) => [r.bcode, r.bname, DOCTORS[d], r.count]), { origin: { r: 2, c } });
                    const totalRow = 2 + rows.length; // 0-based row right under the data
                    const countCol = XLSX.utils.encode_col(c + 3);
                    ws[XLSX.utils.encode_cell({ r: totalRow, c: c + 2 })] = { t: 's', v: 'Total' };
                    ws[XLSX.utils.encode_cell({ r: totalRow, c: c + 3 })] = rows.length
                        ? { t: 'n', v: total(rows), f: `SUM(${countCol}3:${countCol}${totalRow})` }
                        : { t: 'n', v: 0 };
                    const range = XLSX.utils.decode_range(ws['!ref']);
                    range.e.r = Math.max(range.e.r, totalRow);
                    range.e.c = Math.max(range.e.c, c + 3);
                    ws['!ref'] = XLSX.utils.encode_range(range);
                });
                ws['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 3 } }, { s: { r: 0, c: 5 }, e: { r: 0, c: 8 } }];
                ws['!cols'] = [{ wch: 7 }, { wch: 34 }, { wch: 26 }, { wch: 9 }, { wch: 4 }, { wch: 7 }, { wch: 34 }, { wch: 26 }, { wch: 9 }];
                XLSX.utils.book_append_sheet(wb, ws, DOCTORS[d].replace(/[\\\/?*\[\]:]/g, ' ').slice(0, 31));
            });
            XLSX.writeFile(wb, 'HistoDRCounts(' + periodLabel(report.from, report.to) + ').xlsx');
        });

        // "SEP-26" for a single calendar month, otherwise the two dates.
        function periodLabel(from, to) {
            const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
            const [fy, fm, fd] = from.split('-').map(Number), [ty, tm, td] = to.split('-').map(Number);
            const lastDay = new Date(ty, tm, 0).getDate();
            if (fy === ty && fm === tm && fd === 1 && td === lastDay) return MONTHS[fm - 1] + '-' + String(fy).slice(2);
            return from + '_' + to;
        }
    });
</script>
</body>

</html>
