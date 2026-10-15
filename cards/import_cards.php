<?php
require_once __DIR__ . '/../auth.php';

include_once __DIR__ . '/conn.php';
include_once __DIR__ . '/card_functions.php';

set_time_limit(1800);

const IMPORT_RELATIONS = ['FA', 'MO', 'SP', 'CS', 'CD'];

// Lookups the page resolves names against (branch 01 / 254 has the fullest lists).
$designations = card_lookup_options($main_conn, 'EDESIG', 'EDDESC', "WHERE NVL(EDSTATUS, 'Y') = 'Y'");
$departments  = [];
foreach (card_query_all($main_conn, "SELECT ODCODE, ODDESCRIPTION, ODABBR FROM ODEPARTMENTS WHERE NVL(ODSTATUS, 'Y') = 'Y'") as $r) {
    $departments[$r['ODCODE']] = ['name' => $r['ODDESCRIPTION'], 'abbr' => $r['ODABBR']];
}
$categories = [];
foreach (card_query_all($main_conn, "SELECT IC_CCODE, IC_CNAME, CCODE FROM ICARDS_CATEGORY WHERE NVL(IC_CSTATUS, 'Y') = 'Y' ORDER BY IC_CCODE") as $r) {
    $categories[$r['IC_CCODE']] = $r['IC_CCODE'] . ' - ' . $r['IC_CNAME'] . ' (' . $r['CCODE'] . ')';
}

// Checks one card from the page again on the server. Returns [card, holders] or an error message.
function import_build_card(array $c, string $category, array $audit, array $designations, array $departments)
{
    $str = fn($v, $max) => mb_substr(trim((string)($v ?? '')), 0, $max);
    $date = fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v . ' 00:00:00' : null;

    $iccode = $str($c['ICCODE'] ?? '', 20);
    $holder = (array)($c['holder'] ?? []);
    $family = array_values(array_filter((array)($c['family'] ?? []), fn($f) => in_array($f['RCODE'] ?? '', IMPORT_RELATIONS, true) && trim((string)($f['name'] ?? '')) !== ''));

    if ($iccode === '' || !preg_match('/^[A-Za-z0-9\-\/_.]+$/', $iccode)) {
        return "card code '" . $iccode . "' is missing or has characters other than letters, numbers and - / _ .";
    }
    if ($family && strlen($iccode) > 17) {
        return "card code {$iccode} is longer than 17 characters, so family member codes (-01, -02 …) would not fit";
    }
    if ($str($holder['ICHENAME'] ?? '', 990) === '') {
        return "card {$iccode} has no holder name";
    }

    $gender = in_array($holder['ICHGENDER'] ?? '', ['M', 'F'], true) ? $holder['ICHGENDER'] : '';
    $edcode = isset($designations[$holder['EDCODE'] ?? '']) ? $holder['EDCODE'] : null;
    $odcode = isset($departments[$holder['ODCODE'] ?? '']) ? $holder['ODCODE'] : null;

    $card = [
        'IC_CCODE'     => $category,
        'ICCODE'       => $iccode,
        'ICFORM_NO'    => $str($c['ICFORM_NO'] ?? '', 20),
        'ICISSUE_DATE' => $date($c['ICISSUE_DATE'] ?? null),
        'ICEXPIRY'     => $date($c['ICEXPIRY'] ?? null),
        'ICSTATUS'     => 'Y',
        'ICEU'         => $audit['user'],
        'ICED'         => $audit['date'],
        'ICET'         => $audit['time'],
        'ICEIP'        => $audit['ip'],
        'ICEPC'        => $audit['pc'],
        'ICREMARKS'    => 'Imported from Excel',
    ];
    $stamp = ['ICHSTATUS' => 'Y', 'ICHEU' => $audit['user'], 'ICHED' => $audit['date'], 'ICHET' => $audit['time'], 'ICHEIP' => $audit['ip'], 'ICHEP' => $audit['pc']];

    $holders = [[
        'ICCODE'          => $iccode,
        'RCODE'           => 'SL',
        'ICHECODE'        => $iccode,
        'ICHENAME'        => $str($holder['ICHENAME'], 990),
        'ICHFHNAME'       => $str($holder['ICHFHNAME'] ?? '', 100),
        'EDCODE'          => $edcode,
        'ODCODE'          => $odcode,
        'ICHBPS'          => $str($holder['ICHBPS'] ?? '', 2),
        'ICHCNIC_BFORM'   => $str($holder['ICHCNIC_BFORM'] ?? '', 50),
        'ICHGENDER'       => $gender,
        'ICHCONTACT_NO'   => $str($holder['ICHCONTACT_NO'] ?? '', 30),
        'ICHEMERGENCYNO'  => $str($holder['ICHEMERGENCYNO'] ?? '', 30),
        'ICHLANDLINE'     => $str($holder['ICHLANDLINE'] ?? '', 30),
        'ICHDOB'          => $date($holder['ICHDOB'] ?? null),
        'ICHDORETIREMENT' => $date($holder['ICHDORETIREMENT'] ?? null),
    ] + $stamp];

    foreach ($family as $i => $f) {
        $fGender   = card_family_gender($f['RCODE'], in_array($f['ICHGENDER'] ?? '', ['M', 'F'], true) ? $f['ICHGENDER'] : '', $gender);
        $holders[] = [
            'ICCODE'    => $iccode,
            'RCODE'     => $f['RCODE'],
            'ICHECODE'  => $iccode . '-' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT),
            'ICHENAME'  => card_family_name($f['RCODE'], $fGender, $str($f['name'], 980)),
            'ICHGENDER' => $fGender,
        ] + $stamp;
    }

    return [$card, $holders];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload  = json_decode((string)($_POST['payload'] ?? ''), true);
    $category = trim((string)($_POST['IC_CCODE'] ?? ''));
    $dryRun   = isset($_POST['dry_run']);
    $targetBranches = isset($_POST['all_branches'])
        ? array_map('strval', array_keys(card_branches_list($conn, $connections)))
        : (array)($_POST['bcode'] ?? []);

    if (!is_array($payload) || empty($payload)) {
        $errorMsg = 'Nothing to import — load a sheet and check the preview first.';
    } elseif (!isset($categories[$category])) {
        $errorMsg = 'Choose a category for the imported cards.';
    } elseif (empty($targetBranches)) {
        $errorMsg = 'Choose at least one target branch, or All branches.';
    } else {
        $audit     = card_audit_values();
        $allStatus = [];
        $ready     = [];
        $seen      = [];
        foreach ($payload as $c) {
            $built = import_build_card((array)$c, $category, $audit, $designations, $departments);
            if (is_string($built)) {
                $allStatus[] = ['bcode' => '—', 'query' => 'Excel row ' . (int)($c['row'] ?? 0), 'status' => 'Failed: skipped, ' . $built];
                continue;
            }
            $code = strtoupper($built[0]['ICCODE']);
            if (isset($seen[$code])) {
                $allStatus[] = ['bcode' => '—', 'query' => 'Excel row ' . (int)($c['row'] ?? 0), 'status' => "Notice: card {$built[0]['ICCODE']} appears twice in the sheet — skipped the second one"];
                continue;
            }
            $seen[$code] = true;
            $ready[] = $built;
        }

        foreach ($targetBranches as $bcode) {
            if (!$ready || !isset($connections[$bcode])) {
                continue;
            }
            $bconn = card_connect_branch($bcode, $connections);
            if (!$bconn) {
                $allStatus[] = ['bcode' => $bcode, 'query' => 'Connect', 'status' => 'Branch database connection failed'];
                continue;
            }
            foreach ($ready as [$card, $holders]) {
                $allStatus = array_merge($allStatus, card_write_to_branch($main_conn, $bconn, $bcode, $card, $holders, $dryRun));
            }
            oci_close($bconn);
        }

        $_SESSION['cards_status'] = $allStatus;
        header("Location: status.php");
        exit();
    }
}

include __DIR__ . '/header.php';

$branches = card_branches_list($conn, $connections);
$panel = 'bg-white rounded-2xl p-6 border border-slate-200 shadow-sm';
$title = 'text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2';
$step  = 'w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 text-xs flex items-center justify-center font-bold';
?>
<style>
    .map-table th,
    .map-table td {
        border-right: 1px solid #e2e8f0;
        white-space: nowrap;
    }

    .preview-table td {
        vertical-align: top;
    }

    .warn {
        color: #b45309;
    }
</style>

<div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
        <a href="index.php" class="text-xs font-semibold text-slate-500 hover:text-blue-600 flex items-center gap-1 mb-2">← Back to Dashboard</a>
        <h1 class="text-2xl font-bold text-slate-900">Import Cards from Excel</h1>
        <p class="text-sm text-slate-500">Load a sheet, tell us what each column is, check the preview, then create the cards on one or more branches.</p>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <form id="importForm" action="import_cards.php" method="POST" class="space-y-6">
        <input type="hidden" name="payload" id="payload">

        <!-- 1. File -->
        <div class="<?= $panel ?>">
            <h2 class="<?= $title ?>"><span class="<?= $step ?>">1</span> Excel Sheet</h2>
            <div class="flex flex-wrap items-center gap-4">
                <label class="press inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-slate-300 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                    Choose file
                    <input type="file" id="file" accept=".xlsx,.xls,.csv" class="sr-only">
                </label>
                <span id="fileName" class="text-sm text-slate-500">No file chosen. The file is read in your browser and is not uploaded.</span>
                <label id="sheetPicker" class="hidden items-center gap-2 text-sm text-slate-700">Sheet
                    <select id="sheet" class="rounded-lg border border-slate-300 text-sm p-2"></select>
                </label>
                <label id="headerToggle" class="hidden items-center gap-2 text-sm text-slate-700 cursor-pointer">
                    <input type="checkbox" id="hasHeader" class="w-4 h-4 rounded border-slate-300"> First row is a header
                </label>
            </div>
        </div>

        <!-- 2. Mapping -->
        <div id="mappingPanel" class="<?= $panel ?> hidden">
            <h2 class="<?= $title ?>"><span class="<?= $step ?>">2</span> What is in each column?</h2>
            <p class="text-xs text-slate-500 mb-3">A row with a <b>Card Code</b> starts a new card. Rows under it (until the next code) add family members and extra phone numbers. Guesses are filled in from the data — change any that are wrong.</p>
            <div class="overflow-x-auto rounded-lg border border-slate-200">
                <table class="map-table w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-600">
                        <tr id="mapHead"></tr>
                    </thead>
                    <tbody id="mapBody"></tbody>
                </table>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-6 text-sm text-slate-700">
                <label class="inline-flex items-center gap-2">Father / husband name stored as
                    <select id="fhPrefix" class="rounded-lg border border-slate-300 text-sm p-1.5">
                        <option value="auto">S/o (D/o for women)</option>
                        <option value="S/o">S/o</option>
                        <option value="D/o">D/o</option>
                        <option value="W/o">W/o</option>
                        <option value="">name only</option>
                    </select>
                </label>
                <span class="text-xs text-slate-500">A year on its own (e.g. 2029) is stored as 31 Dec of that year.</span>
            </div>
        </div>

        <!-- 3. Preview -->
        <div id="previewPanel" class="<?= $panel ?> hidden">
            <h2 class="<?= $title ?> justify-between">
                <span class="flex items-center gap-2"><span class="<?= $step ?>">3</span> Preview</span>
                <span id="previewSummary" class="text-sm font-normal text-slate-500 tabular-nums"></span>
            </h2>
            <div class="overflow-x-auto rounded-lg border border-slate-200 max-h-[480px] overflow-y-auto">
                <table class="preview-table w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-600 sticky top-0">
                        <tr>
                            <th class="px-3 py-2 text-left">Row</th>
                            <th class="px-3 py-2 text-left">Card Code</th>
                            <th class="px-3 py-2 text-left">Holder</th>
                            <th class="px-3 py-2 text-left">Father / Husband</th>
                            <th class="px-3 py-2 text-left">Designation</th>
                            <th class="px-3 py-2 text-left">Dept / BPS</th>
                            <th class="px-3 py-2 text-left">CNIC / Phones</th>
                            <th class="px-3 py-2 text-left">Dates</th>
                            <th class="px-3 py-2 text-left">Family</th>
                        </tr>
                    </thead>
                    <tbody id="previewBody" class="divide-y divide-slate-100"></tbody>
                </table>
            </div>
        </div>

        <!-- 4. Where -->
        <div id="targetPanel" class="<?= $panel ?> hidden">
            <h2 class="<?= $title ?> justify-between">
                <span class="flex items-center gap-2"><span class="<?= $step ?>">4</span> Category &amp; Branches</span>
                <label class="inline-flex items-center gap-2 cursor-pointer text-sm font-semibold text-slate-700">
                    <input type="checkbox" id="allBranches" name="all_branches" value="1" class="w-4 h-4 rounded text-emerald-600 border-slate-300 focus:ring-emerald-500">
                    All branches <span class="font-normal text-slate-500 tabular-nums">(<?= count($branches) ?>)</span>
                </label>
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Category <span class="text-red-500">*</span></label>
                    <select name="IC_CCODE" id="category" class="w-full rounded-lg border border-slate-300 text-sm p-2.5 bg-white">
                        <option value="">Select</option>
                        <?php foreach ($categories as $code => $name): ?>
                            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="targetPicker" class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Target Branch(es) <span class="text-red-500">*</span></label>
                    <select class="branches_select w-full" multiple="multiple" name="bcode[]">
                        <?php foreach ($branches as $bcode => $bname): ?>
                            <option value="<?= htmlspecialchars($bcode) ?>"><?= htmlspecialchars($bcode . ' - ' . $bname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p class="text-xs text-slate-500 mt-3">Each branch is checked first: a card that already exists there is not inserted again, and only its missing holders are added.</p>
        </div>

        <div id="actions" class="hidden flex items-center justify-between gap-4 pt-2">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" id="dryRun" name="dry_run" value="1" checked class="w-4 h-4 rounded text-emerald-600 border-slate-300 focus:ring-emerald-500">
                <span class="text-sm font-semibold text-slate-700">Dry run <span class="font-normal text-slate-500">(show the inserts, change nothing)</span></span>
            </label>
            <div class="flex items-center gap-4">
                <a href="index.php" class="press px-6 py-3 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-colors">Cancel</a>
                <button id="importButton" type="submit" class="px-8 py-3 rounded-xl bg-emerald-600 text-sm font-semibold text-white shadow-lg shadow-emerald-500/20 hover:bg-emerald-500 transition flex items-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed">
                    <span id="importSpinner" class="spinner hidden" aria-hidden="true"></span>
                    <span id="importLabel">Import Cards</span>
                </button>
            </div>
        </div>
    </form>
</div>

<script src="../assets/js/xlsx.full.min.js"></script>
<script>
    $(document).ready(function() {
        const designations = <?= json_encode($designations ?: new stdClass(), JSON_HEX_TAG) ?>;
        const departments = <?= json_encode($departments ?: new stdClass(), JSON_HEX_TAG) ?>;
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const norm = (s) => String(s ?? '').trim().replace(/\s+/g, ' ').toUpperCase();

        // What a column can be. "repeat" fields also read the rows under a card.
        const FIELDS = [
            ['', 'Ignore'],
            ['code', 'Card Code (starts a card)'],
            ['form', 'Form No'],
            ['name', 'Holder Name'],
            ['father', 'Father / Husband Name'],
            ['designation', 'Designation (name or code)'],
            ['department', 'Department (name, abbreviation or code)'],
            ['bps', 'BPS'],
            ['cnic', 'CNIC'],
            ['phone', 'Phone numbers (repeat)'],
            ['family', 'Family member — NAME=RELATION (repeat)'],
            ['gender', 'Holder gender (M/F)'],
            ['dob', 'Date of birth'],
            ['retire', 'Retirement (year or date)'],
            ['expiry', 'Card expiry (year or date)'],
            ['issue', 'Card issue date'],
        ];
        const RELATIONS = { WIFE: ['SP', 'F'], HUSBAND: ['SP', 'M'], SPOUSE: ['SP', ''], SON: ['CS', 'M'], DAUGHTER: ['CD', 'F'], FATHER: ['FA', 'M'], MOTHER: ['MO', 'F'] };
        const REL_LABEL = { FA: 'Father', MO: 'Mother', CS: 'Son', CD: 'Daughter' };

        let workbook = null;
        let grid = [];      // rows of the chosen sheet (arrays of cell values)
        let mapping = [];   // field per column
        let cards = [];     // built from grid + mapping

        $('.branches_select').select2({ placeholder: 'Select target branches' });
        function applyAllBranches() {
            const all = $('#allBranches').is(':checked');
            $('.branches_select').prop('disabled', all).trigger('change.select2');
            $('#targetPicker').toggleClass('opacity-50 pointer-events-none', all);
        }
        $('#allBranches').on('change', applyAllBranches);

        // ---------- Reading the file ----------
        $('#file').on('change', function() {
            const file = this.files[0];
            if (!file) return;
            $('#fileName').text(file.name);
            const reader = new FileReader();
            reader.onload = (e) => {
                try {
                    workbook = XLSX.read(new Uint8Array(e.target.result), { type: 'array', cellDates: true });
                } catch (err) {
                    iziToast.error({ title: 'Could not read the file', message: err.message, position: 'topRight' });
                    return;
                }
                const names = workbook.SheetNames;
                $('#sheet').html(names.map((n) => '<option>' + esc(n) + '</option>').join(''));
                // Start on the first sheet that has data.
                const first = names.find((n) => XLSX.utils.sheet_to_json(workbook.Sheets[n], { header: 1, blankrows: false }).length) || names[0];
                $('#sheet').val(first);
                $('#sheetPicker, #headerToggle').removeClass('hidden').addClass('inline-flex');
                loadSheet(true);
            };
            reader.readAsArrayBuffer(file);
        });
        $('#sheet').on('change', () => loadSheet(true));
        $('#hasHeader').on('change', () => loadSheet(false));

        function loadSheet(guessHeader) {
            grid = XLSX.utils.sheet_to_json(workbook.Sheets[$('#sheet').val()], { header: 1, raw: true, defval: null, blankrows: true });
            const width = grid.reduce((m, r) => Math.max(m, r.length), 0);
            grid = grid.map((r) => Array.from({ length: width }, (_, i) => (r[i] === undefined ? null : r[i])));
            if (guessHeader) {
                // A header row is all text and has nothing that looks like a card code.
                const r0 = grid[0] || [];
                $('#hasHeader').prop('checked', r0.length > 0 && r0.every((v) => v === null || typeof v === 'string') && !r0.some((v) => looksLikeCode(v)));
            }
            mapping = guessMapping();
            renderMapping();
            rebuild();
        }

        const dataRows = () => ($('#hasHeader').is(':checked') ? grid.slice(1) : grid).map((r, i) => ({ cells: r, row: i + ($('#hasHeader').is(':checked') ? 2 : 1) }));
        const colName = (i) => XLSX.utils.encode_col(i);

        // ---------- Guessing what each column is ----------
        function looksLikeCode(v) { return typeof v === 'string' && /^[A-Za-z0-9]+(-[A-Za-z0-9]+){1,}$/.test(v.trim()) && /\d/.test(v) && /[A-Za-z]/.test(v); }
        const isCnic = (v) => /^\d{5}-?\d{7}-?\d$/.test(String(v ?? '').trim());
        const isPhone = (v) => /^(\+?92|0)3\d{2}-?\d{7}$/.test(String(v ?? '').replace(/\s/g, ''));
        const isFamily = (v) => typeof v === 'string' && /=/.test(v) && !!(parseFamily(v) || {}).rcode;
        const isYear = (v) => (typeof v === 'number' && v >= 1950 && v <= 2100 && Number.isInteger(v)) || /^(19|20)\d{2}$/.test(String(v ?? '').trim());
        const designationCode = (v) => {
            const n = norm(v);
            if (!n) return null;
            if (designations[n] !== undefined) return n;
            return Object.keys(designations).find((k) => norm(designations[k]) === n) || null;
        };
        const departmentCode = (v) => {
            const n = norm(v);
            if (!n) return null;
            if (departments[n]) return n;
            return Object.keys(departments).find((k) => norm(departments[k].name) === n || norm(departments[k].abbr) === n) || null;
        };

        function guessMapping() {
            const rows = dataRows().map((r) => r.cells);
            const width = grid[0] ? grid[0].length : 0;
            const used = new Set();
            const out = Array(width).fill('');
            const share = (i, test) => {
                const vals = rows.map((r) => r[i]).filter((v) => v !== null && String(v).trim() !== '');
                return vals.length ? vals.filter(test).length / vals.length : 0;
            };
            const claim = (field, test, min = 0.8) => {
                if (used.has(field)) return;
                for (let i = 0; i < width; i++) {
                    if (!out[i] && share(i, test) >= min) { out[i] = field; used.add(field); return; }
                }
            };
            claim('code', looksLikeCode);
            claim('cnic', isCnic);
            claim('phone', isPhone);
            claim('family', isFamily);
            claim('designation', (v) => designationCode(v) !== null, 0.6);
            claim('retire', isYear);
            claim('bps', (v) => /^\d{1,2}$/.test(String(v).trim()) && Number(v) >= 1 && Number(v) <= 22);
            // The first two text columns after the code are usually holder and father names.
            const codeCol = out.indexOf('code');
            for (let i = codeCol + 1, n = 0; codeCol >= 0 && i < width && n < 2; i++) {
                if (!out[i] && share(i, (v) => typeof v === 'string' && /^[A-Za-z][A-Za-z .'-]+$/.test(v.trim())) >= 0.8) {
                    out[i] = n === 0 ? 'name' : 'father';
                    n++;
                }
            }
            return out;
        }

        function renderMapping() {
            const rows = dataRows().filter((r) => r.cells.some((v) => v !== null && String(v).trim() !== '')).slice(0, 5);
            const header = $('#hasHeader').is(':checked') ? grid[0] : null;
            $('#mapHead').html(mapping.map((f, i) =>
                '<th class="px-3 py-2 text-left align-top">'
                + '<div class="font-semibold text-slate-700">' + colName(i) + (header && header[i] ? ' <span class="font-normal text-slate-500">' + esc(header[i]) + '</span>' : '') + '</div>'
                + '<select data-col="' + i + '" class="map-select mt-1 rounded-md border border-slate-300 text-xs p-1.5 bg-white ' + (f ? 'text-emerald-800 border-emerald-300 bg-emerald-50' : 'text-slate-500') + '">'
                + FIELDS.map(([k, label]) => '<option value="' + k + '"' + (k === f ? ' selected' : '') + '>' + esc(label) + '</option>').join('')
                + '</select></th>'
            ).join(''));
            $('#mapBody').html(rows.map((r) => '<tr class="border-t border-slate-100">' + r.cells.map((v) => '<td class="px-3 py-1.5 text-xs text-slate-600 max-w-[14rem] truncate" title="' + esc(show(v)) + '">' + esc(show(v)) + '</td>').join('') + '</tr>').join(''));
            $('#mappingPanel').removeClass('hidden');
        }
        $('#mapHead').on('change', '.map-select', function() {
            const col = Number(this.dataset.col);
            const field = this.value;
            // Each field (except Ignore) belongs to one column only.
            if (field) mapping = mapping.map((f, i) => (f === field && i !== col ? '' : f));
            mapping[col] = field;
            renderMapping();
            rebuild();
        });
        $('#fhPrefix').on('change', rebuild);

        // ---------- Turning rows into cards ----------
        function show(v) {
            if (v instanceof Date) return toYmd(v);
            return v === null || v === undefined ? '' : String(v);
        }
        function toYmd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
        // A date cell, a year (→ 31 Dec), or text like 2029-01-31 / 31/01/2029.
        function toDate(v) {
            if (v === null || v === undefined || String(v).trim() === '') return null;
            if (v instanceof Date) return toYmd(v);
            const s = String(v).trim();
            if (isYear(s)) return s + '-12-31';
            let m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
            if (m) return m[1] + '-' + m[2].padStart(2, '0') + '-' + m[3].padStart(2, '0');
            m = s.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/);
            if (m) return m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
            return undefined; // present but not understood
        }
        // "SOFIA=WIFE" or "WIFE= SOFIA" -> { name, rcode, gender, relation }
        function parseFamily(v) {
            const parts = String(v).split('=').map((p) => p.trim()).filter(Boolean);
            if (parts.length !== 2) return null;
            const [a, b] = parts;
            if (RELATIONS[norm(b)]) return { name: a, relation: norm(b), rcode: RELATIONS[norm(b)][0], gender: RELATIONS[norm(b)][1] };
            if (RELATIONS[norm(a)]) return { name: b, relation: norm(a), rcode: RELATIONS[norm(a)][0], gender: RELATIONS[norm(a)][1] };
            return { name: a, relation: b, rcode: null, gender: '' };
        }

        function rebuild() {
            const col = (f) => mapping.indexOf(f);
            const get = (cells, f) => (col(f) >= 0 ? cells[col(f)] : null);
            cards = [];
            let current = null;
            if (col('code') < 0) {
                $('#previewPanel, #targetPanel, #actions').addClass('hidden');
                return;
            }
            dataRows().forEach(({ cells, row }) => {
                if (!cells.some((v) => v !== null && String(v).trim() !== '')) return;
                const code = show(get(cells, 'code')).trim();
                if (code) {
                    current = { row: row, ICCODE: code, warnings: [], phones: [], family: [], holder: {} };
                    const h = current.holder;
                    h.ICHENAME = show(get(cells, 'name')).trim();
                    h.fatherRaw = show(get(cells, 'father')).trim();
                    const dRaw = show(get(cells, 'designation')).trim();
                    h.EDCODE = designationCode(dRaw);
                    if (dRaw && !h.EDCODE) current.warnings.push('designation "' + dRaw + '" not found — left empty');
                    h.designationName = h.EDCODE ? designations[h.EDCODE] : '';
                    const oRaw = show(get(cells, 'department')).trim();
                    h.ODCODE = departmentCode(oRaw);
                    if (oRaw && !h.ODCODE) current.warnings.push('department "' + oRaw + '" not found — left empty');
                    h.ICHBPS = show(get(cells, 'bps')).trim();
                    h.ICHCNIC_BFORM = show(get(cells, 'cnic')).trim();
                    const g = norm(get(cells, 'gender'));
                    h.ICHGENDER = g.startsWith('M') ? 'M' : g.startsWith('F') ? 'F' : '';
                    current.form = show(get(cells, 'form')).trim();
                    [['dob', 'ICHDOB', 'date of birth'], ['retire', 'ICHDORETIREMENT', 'retirement'], ['expiry', 'ICEXPIRY', 'expiry'], ['issue', 'ICISSUE_DATE', 'issue date']].forEach(([f, key, label]) => {
                        const d = toDate(get(cells, f));
                        if (d === undefined) current.warnings.push(label + ' "' + show(get(cells, f)) + '" not understood — left empty');
                        current[key] = d || null;
                    });
                    if (!h.ICHENAME) current.warnings.push('no holder name — this card will be skipped');
                    cards.push(current);
                } else if (!current) {
                    return; // rows before the first card
                }
                const phone = show(get(cells, 'phone')).trim();
                if (phone) current.phones.push(phone);
                const fam = get(cells, 'family');
                if (fam !== null && String(fam).trim() !== '') {
                    const f = parseFamily(fam);
                    if (!f || !f.rcode) current.warnings.push('family "' + show(fam) + '" has no known relation (Wife, Husband, Son, Daughter, Father, Mother) — skipped');
                    else current.family.push(f);
                }
            });

            // Finishing touches that need the whole card.
            const codes = {};
            cards.forEach((c) => {
                const h = c.holder;
                // A wife means the holder is a man, a husband means a woman (unless a gender column says otherwise).
                if (!h.ICHGENDER) {
                    if (c.family.some((f) => f.relation === 'WIFE')) h.ICHGENDER = 'M';
                    else if (c.family.some((f) => f.relation === 'HUSBAND')) h.ICHGENDER = 'F';
                }
                const pref = $('#fhPrefix').val() === 'auto' ? (h.ICHGENDER === 'F' ? 'D/o' : 'S/o') : $('#fhPrefix').val();
                h.ICHFHNAME = h.fatherRaw ? (pref && !/^(S|D|W)\/o\b/i.test(h.fatherRaw) ? pref + ' ' + h.fatherRaw : h.fatherRaw) : '';
                [h.ICHCONTACT_NO, h.ICHEMERGENCYNO, h.ICHLANDLINE] = [c.phones[0] || '', c.phones[1] || '', c.phones[2] || ''];
                if (c.phones.length > 3) c.warnings.push((c.phones.length - 3) + ' extra phone number(s) not stored');
                if (c.family.length && c.ICCODE.length > 17) c.warnings.push('card code too long for family member codes — this card will be skipped');
                const k = c.ICCODE.toUpperCase();
                if (codes[k]) c.warnings.push('card code also on row ' + codes[k] + ' — only the first is imported');
                else codes[k] = c.row;
            });
            renderPreview();
        }

        function familyLabel(f, holderGender) {
            const g = f.gender || (f.rcode === 'SP' ? (holderGender === 'M' ? 'F' : holderGender === 'F' ? 'M' : '') : '');
            const prefix = f.rcode === 'SP' ? (g === 'M' ? 'Husband' : 'Wife') : REL_LABEL[f.rcode];
            return prefix.toUpperCase() + '=' + f.name;
        }

        function renderPreview() {
            const skipped = cards.filter((c) => !c.holder.ICHENAME).length;
            const withWarnings = cards.filter((c) => c.warnings.length).length;
            const members = cards.reduce((n, c) => n + c.family.length, 0);
            $('#previewSummary').html(cards.length + ' card' + (cards.length === 1 ? '' : 's') + ' · ' + members + ' family member' + (members === 1 ? '' : 's')
                + (withWarnings ? ' · <span class="warn">' + withWarnings + ' with warnings</span>' : '') + (skipped ? ' · <span class="text-red-600">' + skipped + ' will be skipped</span>' : ''));
            $('#previewBody').html(cards.map((c) => {
                const h = c.holder;
                return '<tr class="' + (c.warnings.length ? 'bg-amber-50/60' : '') + '">'
                    + '<td class="px-3 py-2 text-xs text-slate-400 tabular-nums">' + c.row + '</td>'
                    + '<td class="px-3 py-2 font-mono text-xs">' + esc(c.ICCODE) + (c.form ? '<div class="text-slate-400">form ' + esc(c.form) + '</div>' : '') + '</td>'
                    + '<td class="px-3 py-2">' + esc(h.ICHENAME || '—') + (h.ICHGENDER ? ' <span class="text-xs text-slate-400">(' + h.ICHGENDER + ')</span>' : '') + '</td>'
                    + '<td class="px-3 py-2">' + esc(h.ICHFHNAME) + '</td>'
                    + '<td class="px-3 py-2">' + esc(h.designationName) + (h.EDCODE ? ' <span class="text-xs text-slate-400 font-mono">' + esc(h.EDCODE) + '</span>' : '') + '</td>'
                    + '<td class="px-3 py-2 text-xs">' + esc(h.ODCODE ? departments[h.ODCODE].name : '') + (h.ICHBPS ? '<div>BPS ' + esc(h.ICHBPS) + '</div>' : '') + '</td>'
                    + '<td class="px-3 py-2 text-xs font-mono">' + esc(h.ICHCNIC_BFORM) + c.phones.slice(0, 3).map((p) => '<div>' + esc(p) + '</div>').join('') + '</td>'
                    + '<td class="px-3 py-2 text-xs">' + [['Retire', h.ICHDORETIREMENT || c.ICHDORETIREMENT], ['Expiry', c.ICEXPIRY], ['Issued', c.ICISSUE_DATE], ['DOB', c.ICHDOB]].filter(([, d]) => d).map(([l, d]) => '<div>' + l + ' ' + esc(d) + '</div>').join('') + '</td>'
                    + '<td class="px-3 py-2 text-xs">' + c.family.map((f) => '<div>' + esc(familyLabel(f, h.ICHGENDER)) + '</div>').join('')
                    + c.warnings.map((w) => '<div class="warn">⚠ ' + esc(w) + '</div>').join('') + '</td>'
                    + '</tr>';
            }).join('') || '<tr><td colspan="9" class="px-3 py-6 text-center text-slate-400">No rows with a card code.</td></tr>');
            $('#previewPanel, #targetPanel, #actions').toggleClass('hidden', cards.length === 0);
            $('#actions').toggleClass('flex', cards.length > 0);
        }

        // ---------- Submitting ----------
        const form = document.getElementById('importForm');
        form.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.matches('input:not([type="checkbox"]):not([type="submit"]):not([type="file"])')) e.preventDefault();
        });
        const importButton = document.getElementById('importButton');
        function setImporting(on) {
            importButton.disabled = on;
            document.getElementById('importSpinner').classList.toggle('hidden', !on);
            document.getElementById('importLabel').textContent = on ? (document.getElementById('dryRun').checked ? 'Checking…' : 'Importing…') : 'Import Cards';
        }
        form.addEventListener('submit', function(e) {
            if (importButton.disabled) return e.preventDefault();
            const ready = cards.filter((c) => c.holder.ICHENAME);
            const problem = !ready.length ? 'There are no cards to import.'
                : !$('#category').val() ? 'Choose a category for the imported cards.'
                : (!$('#allBranches').is(':checked') && !($('.branches_select').val() || []).length) ? 'Choose at least one target branch, or All branches.'
                : null;
            if (problem) {
                e.preventDefault();
                iziToast.error({ title: 'Not yet', message: problem, position: 'topRight' }); // bottom-right would cover the Import button
                return;
            }
            const writing = !document.getElementById('dryRun').checked;
            const where = $('#allBranches').is(':checked') ? 'all branches' : ($('.branches_select').val() || []).length + ' branch(es)';
            if (writing && !confirm('Import ' + ready.length + ' card(s) into ' + where + '? Cards that already exist on a branch are not inserted again.')) {
                e.preventDefault();
                return;
            }
            document.getElementById('payload').value = JSON.stringify(ready.map((c) => ({
                row: c.row,
                ICCODE: c.ICCODE,
                ICFORM_NO: c.form,
                ICISSUE_DATE: c.ICISSUE_DATE,
                ICEXPIRY: c.ICEXPIRY,
                holder: Object.assign({}, c.holder, { ICHDOB: c.ICHDOB, ICHDORETIREMENT: c.ICHDORETIREMENT }),
                family: c.family.map((f) => ({ RCODE: f.rcode, name: f.name, ICHGENDER: f.gender })),
            })));
            setImporting(true);
        });
        window.addEventListener('pageshow', function() { setImporting(false); });
    });
</script>
</body>

</html>
