<?php
require_once __DIR__ . '/../auth.php';

include_once __DIR__ . '/conn.php';
include_once __DIR__ . '/card_functions.php';

set_time_limit(600);

$old = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
function old($key, $default = '')
{
    global $old;
    return htmlspecialchars(trim((string)($old[$key] ?? $default)));
}
function old_selected($key, $value, $default = '')
{
    global $old;
    return (string)($old[$key] ?? $default) === (string)$value ? 'selected' : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetBranches = (array)($_POST['bcode'] ?? []);
    $dryRun         = isset($_POST['dry_run']);
    $v              = fn($k) => trim((string)($_POST[$k] ?? ''));
    $iccode         = $v('ICCODE');
    $audit          = card_audit_values();

    // Family member rows; blank rows are ignored.
    $family = [];
    foreach ((array)($_POST['family'] ?? []) as $f) {
        $f = array_map(fn($x) => trim((string)$x), (array)$f);
        if (($f['ICHENAME'] ?? '') !== '') {
            $family[] = $f;
        }
    }

    if ($iccode === '' || $v('IC_CCODE') === '' || $v('ICHENAME') === '' || empty($targetBranches)) {
        $errorMsg = "Category, Card Code, Holder Name and at least one target branch are required.";
    } elseif (!preg_match('/^[A-Za-z0-9\-\/_.]+$/', $iccode)) {
        $errorMsg = "Card Code may only contain letters, numbers and - / _ .";
    } elseif ($family && strlen($iccode) > 17) {
        $errorMsg = "Card Code can be at most 17 characters when family members are added (they get the code plus -01, -02 …).";
    } else {
        foreach ($family as $f) {
            if (($f['RCODE'] ?? '') === '') {
                $errorMsg = "Select a relation for every family member.";
                break;
            }
        }
    }

    if (empty($errorMsg)) {
        $card = [
            'IC_CCODE'     => $v('IC_CCODE'),
            'ICCODE'       => $iccode,
            'ICFORM_NO'    => $v('ICFORM_NO'),
            'ICISSUE_DATE' => card_form_date($v('ICISSUE_DATE')),
            'ICEXPIRY'     => card_form_date($v('ICEXPIRY')),
            'ICSTATUS'     => $v('ICSTATUS') ?: 'Y',
            'ICEU'         => $audit['user'],
            'ICED'         => $audit['date'],
            'ICET'         => $audit['time'],
            'ICEIP'        => $audit['ip'],
            'ICEPC'        => $audit['pc'],
            'ICREMARKS'    => $v('ICREMARKS'),
        ];

        $stamp = [
            'ICHSTATUS' => 'Y',
            'ICHEU'     => $audit['user'],
            'ICHED'     => $audit['date'],
            'ICHET'     => $audit['time'],
            'ICHEIP'    => $audit['ip'],
            'ICHEP'     => $audit['pc'],
        ];

        // The cardholder (Self) uses the card code as its holder code.
        $holders = [[
            'ICCODE'          => $iccode,
            'RCODE'           => 'SL',
            'ICHECODE'        => $iccode,
            'ICHENAME'        => $v('ICHENAME'),
            'ICHFHNAME'       => $v('ICHFHNAME'),
            'ICHSURNAME'      => $v('ICHSURNAME'),
            'EDCODE'          => $v('EDCODE'),
            'ODCODE'          => $v('ODCODE'),
            'ICHBPS'          => $v('ICHBPS'),
            'ICHCNIC_BFORM'   => $v('ICHCNIC_BFORM'),
            'ICHGENDER'       => $v('ICHGENDER'),
            'ICHMSTATUS'      => $v('ICHMSTATUS'),
            'ICHCONTACT_NO'   => $v('ICHCONTACT_NO'),
            'ICHEMERGENCYNO'  => $v('ICHEMERGENCYNO'),
            'ICHLANDLINE'     => $v('ICHLANDLINE'),
            'ICHEMAIL'        => $v('ICHEMAIL'),
            'ICHDOB'          => card_form_date($v('ICHDOB')),
            'ICHDOREGULAR'    => card_form_date($v('ICHDOREGULAR')),
            'ICHDORETIREMENT' => card_form_date($v('ICHDORETIREMENT')),
            'ICHBLOODGROUP'   => $v('ICHBLOODGROUP'),
        ] + $stamp];

        $relationNames = [];
        foreach (card_query_all($main_conn, "SELECT RCODE, RNAME FROM RELATIONS") as $r) {
            $relationNames[$r['RCODE']] = $r['RNAME'];
        }

        foreach ($family as $i => $f) {
            $gender    = card_family_gender($f['RCODE'], $f['ICHGENDER'] ?? '', $v('ICHGENDER'));
            $holders[] = [
                'ICCODE'        => $iccode,
                'RCODE'         => $f['RCODE'],
                'ICHECODE'      => $iccode . '-' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT),
                'ICHENAME'      => card_family_name($f['RCODE'], $gender, $f['ICHENAME'], $relationNames[$f['RCODE']] ?? ''),
                'ICHGENDER'     => $gender,
                'ICHCNIC_BFORM' => $f['ICHCNIC_BFORM'] ?? '',
                'ICHCONTACT_NO' => $f['ICHCONTACT_NO'] ?? '',
                'ICHDOB'        => card_form_date($f['ICHDOB'] ?? ''),
            ] + $stamp;
        }

        $allStatus = [];
        foreach ($targetBranches as $bcode) {
            if (!isset($connections[$bcode])) {
                continue;
            }
            $bconn = card_connect_branch($bcode, $connections);
            if (!$bconn) {
                $allStatus[] = ['bcode' => $bcode, 'query' => 'Connect', 'status' => 'Branch database connection failed'];
                continue;
            }
            $allStatus = array_merge($allStatus, card_write_to_branch($main_conn, $bconn, $bcode, $card, $holders, $dryRun));
            oci_close($bconn);
        }

        $_SESSION['cards_status'] = $allStatus;
        header("Location: status.php");
        exit();
    }
}

include __DIR__ . '/header.php';

$branches     = card_branches_list($conn, $connections);
$categories   = [];
foreach (card_query_all($main_conn, "SELECT IC_CCODE, IC_CNAME, CCODE FROM ICARDS_CATEGORY WHERE NVL(IC_CSTATUS, 'Y') = 'Y' ORDER BY IC_CCODE") as $r) {
    $categories[$r['IC_CCODE']] = $r['IC_CCODE'] . ' - ' . $r['IC_CNAME'] . ' (' . $r['CCODE'] . ')';
}
$relations    = [];
foreach (card_query_all($main_conn, "SELECT RCODE, RNAME FROM RELATIONS WHERE RCODE <> 'SL' AND NVL(RSTATUS, 'Y') = 'Y' ORDER BY RORDER") as $r) {
    $relations[$r['RCODE']] = $r['RNAME'];
}
$designations = card_lookup_options($main_conn, 'EDESIG', 'EDDESC', "WHERE NVL(EDSTATUS, 'Y') = 'Y'");
$departments  = card_lookup_options($main_conn, 'ODEPARTMENTS', 'ODDESCRIPTION', "WHERE NVL(ODSTATUS, 'Y') = 'Y'");
$bloodGroups  = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

$familyRows = [];
foreach ((array)($old['family'] ?? []) as $f) {
    if (trim((string)($f['ICHENAME'] ?? '')) !== '') {
        $familyRows[] = $f;
    }
}

$input  = 'w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500';
$label  = 'block text-sm font-semibold text-slate-700 mb-1';
$step   = 'w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 text-xs flex items-center justify-center font-bold';
$panel  = 'bg-white rounded-2xl p-6 border border-slate-200 shadow-sm';
$title  = 'text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2';
?>

<div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <a href="index.php" class="text-xs font-semibold text-slate-500 hover:text-blue-600 flex items-center gap-1 mb-2">
                ← Back to Dashboard
            </a>
            <h1 class="text-2xl font-bold text-slate-900">New Card</h1>
            <p class="text-sm text-slate-500">Create a card with its holder and family members on one or more branches.</p>
        </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <form action="new_card.php" method="POST" class="space-y-6">
        <div class="<?= $panel ?>">
            <h2 class="<?= $title ?>"><span class="<?= $step ?>">1</span> Target Branch(es)</h2>
            <select required class="branches_select w-full" multiple="multiple" name="bcode[]">
                <?php foreach ($branches as $bcode => $bname): ?>
                    <option value="<?= htmlspecialchars($bcode) ?>" <?= in_array((string)$bcode, (array)($old['bcode'] ?? []), true) ? 'selected' : '' ?>><?= htmlspecialchars($bcode . ' - ' . $bname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="<?= $panel ?>">
            <h2 class="<?= $title ?>"><span class="<?= $step ?>">2</span> Card Details</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                <div>
                    <label class="<?= $label ?>">Category <span class="text-red-500">*</span></label>
                    <select required name="IC_CCODE" class="tag_select w-full">
                        <option value=""></option>
                        <?php foreach ($categories as $code => $name): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= old_selected('IC_CCODE', $code) ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="<?= $label ?>">Card Code <span class="text-red-500">*</span></label>
                    <input required type="text" name="ICCODE" id="ICCODE" maxlength="20" value="<?= old('ICCODE') ?>" placeholder="e.g. A-R-NG-1027" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Form No</label>
                    <input type="text" name="ICFORM_NO" maxlength="20" value="<?= old('ICFORM_NO') ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Issue Date</label>
                    <input type="date" name="ICISSUE_DATE" value="<?= old('ICISSUE_DATE', date('Y-m-d')) ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Expiry Date</label>
                    <input type="date" name="ICEXPIRY" value="<?= old('ICEXPIRY') ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Status</label>
                    <select name="ICSTATUS" class="<?= $input ?>">
                        <option value="Y" <?= old_selected('ICSTATUS', 'Y', 'Y') ?>>Active (Y)</option>
                        <option value="N" <?= old_selected('ICSTATUS', 'N', 'Y') ?>>Inactive (N)</option>
                    </select>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <label class="<?= $label ?>">Remarks</label>
                    <input type="text" name="ICREMARKS" maxlength="2000" value="<?= old('ICREMARKS') ?>" class="<?= $input ?>">
                </div>
            </div>
        </div>

        <div class="<?= $panel ?>">
            <h2 class="<?= $title ?>"><span class="<?= $step ?>">3</span> Card Holder (Self)</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                <div>
                    <label class="<?= $label ?>">Name <span class="text-red-500">*</span></label>
                    <input required type="text" name="ICHENAME" maxlength="1000" value="<?= old('ICHENAME') ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Father / Husband Name</label>
                    <input type="text" name="ICHFHNAME" maxlength="100" value="<?= old('ICHFHNAME') ?>" placeholder="e.g. S/o Muhammad Saleh" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Surname</label>
                    <input type="text" name="ICHSURNAME" maxlength="50" value="<?= old('ICHSURNAME') ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Designation</label>
                    <select name="EDCODE" class="tag_select w-full">
                        <option value=""></option>
                        <?php foreach ($designations as $code => $name): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= old_selected('EDCODE', $code) ?>><?= htmlspecialchars($name . ' (' . $code . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="<?= $label ?>">Department</label>
                    <select name="ODCODE" class="tag_select w-full">
                        <option value=""></option>
                        <?php foreach ($departments as $code => $name): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= old_selected('ODCODE', $code) ?>><?= htmlspecialchars($name . ' (' . $code . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="<?= $label ?>">BPS</label>
                    <input type="text" name="ICHBPS" maxlength="2" value="<?= old('ICHBPS') ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">CNIC</label>
                    <input type="text" name="ICHCNIC_BFORM" maxlength="50" value="<?= old('ICHCNIC_BFORM') ?>" placeholder="41204-3577687-9" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Gender</label>
                    <select name="ICHGENDER" class="<?= $input ?>">
                        <option value=""></option>
                        <option value="M" <?= old_selected('ICHGENDER', 'M') ?>>Male</option>
                        <option value="F" <?= old_selected('ICHGENDER', 'F') ?>>Female</option>
                    </select>
                </div>
                <div>
                    <label class="<?= $label ?>">Marital Status</label>
                    <select name="ICHMSTATUS" class="<?= $input ?>">
                        <option value=""></option>
                        <option value="M" <?= old_selected('ICHMSTATUS', 'M') ?>>Married</option>
                        <option value="S" <?= old_selected('ICHMSTATUS', 'S') ?>>Single</option>
                    </select>
                </div>
                <div>
                    <label class="<?= $label ?>">Contact No</label>
                    <input type="text" name="ICHCONTACT_NO" maxlength="30" value="<?= old('ICHCONTACT_NO') ?>" placeholder="0300-1234567" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Emergency No</label>
                    <input type="text" name="ICHEMERGENCYNO" maxlength="30" value="<?= old('ICHEMERGENCYNO') ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Landline</label>
                    <input type="text" name="ICHLANDLINE" maxlength="30" value="<?= old('ICHLANDLINE') ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Email</label>
                    <input type="email" name="ICHEMAIL" maxlength="100" value="<?= old('ICHEMAIL') ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Blood Group</label>
                    <select name="ICHBLOODGROUP" class="<?= $input ?>">
                        <option value=""></option>
                        <?php foreach ($bloodGroups as $bg): ?>
                            <option value="<?= $bg ?>" <?= old_selected('ICHBLOODGROUP', $bg) ?>><?= $bg ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="<?= $label ?>">Date of Birth</label>
                    <input type="date" name="ICHDOB" value="<?= old('ICHDOB') ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Date of Regular</label>
                    <input type="date" name="ICHDOREGULAR" value="<?= old('ICHDOREGULAR') ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="<?= $label ?>">Date of Retirement</label>
                    <input type="date" name="ICHDORETIREMENT" value="<?= old('ICHDORETIREMENT') ?>" class="<?= $input ?>">
                </div>
            </div>
        </div>

        <div class="<?= $panel ?>">
            <h2 class="<?= $title ?> justify-between">
                <span class="flex items-center gap-2"><span class="<?= $step ?>">4</span> Family Members</span>
                <button type="button" id="addFamily" class="text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 px-3 py-1.5 rounded-lg">+ Add Member</button>
            </h2>
            <p class="text-xs text-slate-500 mb-4">Holder codes are generated as <span class="font-mono text-slate-700" id="codePreview">CARDCODE-01, -02 …</span> in the order below. Enter the name only — the relation is added in front automatically (e.g. <span class="font-mono text-slate-700">Wife= Farzana</span>).</p>
            <div id="familyRows" class="space-y-3"></div>
            <p id="noFamily" class="text-sm text-slate-400">No family members added.</p>
        </div>

        <div class="flex items-center justify-between gap-4 pt-4">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="dry_run" value="1" <?= isset($old['dry_run']) ? 'checked' : '' ?> class="w-4 h-4 rounded text-emerald-600 border-slate-300 focus:ring-emerald-500">
                <span class="text-sm font-semibold text-slate-700">Dry run <span class="font-normal text-slate-500">(show the inserts, change nothing)</span></span>
            </label>
            <div class="flex items-center gap-4">
                <a href="index.php" class="px-6 py-3 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-colors">
                    Cancel
                </a>
                <button type="submit" class="px-8 py-3 rounded-xl bg-emerald-600 text-sm font-semibold text-white shadow-lg shadow-emerald-500/20 hover:bg-emerald-500 transition flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Create Card
                </button>
            </div>
        </div>
    </form>
</div>

<template id="familyTemplate">
    <div class="family-row grid grid-cols-1 sm:grid-cols-12 gap-3 items-end p-3 rounded-xl border border-slate-200 bg-slate-50">
        <div class="sm:col-span-1">
            <label class="block text-xs font-semibold text-slate-500 mb-1">Code</label>
            <div class="row-code font-mono text-xs text-slate-700 py-2.5">-01</div>
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold text-slate-600 mb-1">Relation <span class="text-red-500">*</span></label>
            <select data-name="RCODE" class="<?= $input ?> bg-white">
                <option value=""></option>
                <?php foreach ($relations as $code => $name): ?>
                    <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sm:col-span-3">
            <label class="block text-xs font-semibold text-slate-600 mb-1">Name <span class="text-red-500">*</span></label>
            <input type="text" data-name="ICHENAME" maxlength="990" placeholder="e.g. Farzana" class="<?= $input ?> bg-white">
            <p class="saved-as text-xs text-slate-500 mt-1 font-mono truncate"></p>
        </div>
        <div class="sm:col-span-1">
            <label class="block text-xs font-semibold text-slate-600 mb-1">Gender</label>
            <select data-name="ICHGENDER" class="<?= $input ?> bg-white">
                <option value=""></option>
                <option value="M">M</option>
                <option value="F">F</option>
            </select>
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold text-slate-600 mb-1">CNIC / B-Form</label>
            <input type="text" data-name="ICHCNIC_BFORM" maxlength="50" class="<?= $input ?> bg-white">
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold text-slate-600 mb-1">Date of Birth</label>
            <input type="date" data-name="ICHDOB" class="<?= $input ?> bg-white">
        </div>
        <div class="sm:col-span-1 flex justify-end">
            <button type="button" class="remove-family text-red-600 hover:bg-red-50 rounded-lg px-2.5 py-2 text-sm font-semibold" title="Remove">&times;</button>
        </div>
    </div>
</template>

<script>
    $(document).ready(function() {
        $('.branches_select').select2({ placeholder: "Select target branches" });
        $('.tag_select').select2({ placeholder: "Select", allowClear: true });

        const $rows = $('#familyRows');
        const template = document.getElementById('familyTemplate');

        // Renumber names (family[i][...]) and the generated holder codes after any add/remove.
        function renumber() {
            const code = $('#ICCODE').val().trim() || 'CARDCODE';
            $rows.children('.family-row').each(function(i) {
                const suffix = '-' + String(i + 1).padStart(2, '0');
                $(this).find('.row-code').text(suffix).attr('title', code + suffix);
                $(this).find('[data-name]').each(function() {
                    this.name = 'family[' + i + '][' + this.dataset.name + ']';
                });
            });
            $('#codePreview').text(code + '-01, ' + code + '-02 …');
            $('#noFamily').toggle($rows.children().length === 0);
        }

        // Same rules as card_family_gender() / card_family_name() in card_functions.php.
        const impliedGender = { FA: 'M', CS: 'M', MO: 'F', CD: 'F' };
        const prefixes = { FA: 'Father', MO: 'Mother', CS: 'Son', CD: 'Daughter' };

        function rowGender($row) {
            const rcode = $row.find('[data-name="RCODE"]').val();
            const g = $row.find('[data-name="ICHGENDER"]').val();
            if (g) return g;
            if (impliedGender[rcode]) return impliedGender[rcode];
            const holder = $('select[name="ICHGENDER"]').val();
            return rcode === 'SP' && holder ? (holder === 'M' ? 'F' : 'M') : '';
        }

        function updateSavedAs($row) {
            const $rel = $row.find('[data-name="RCODE"]');
            const rcode = $rel.val();
            const name = $row.find('[data-name="ICHENAME"]').val().trim()
                .replace(/^\s*(father|mother|son|daughter|wife|husband|spouse)\s*=\s*/i, '');
            const prefix = rcode === 'SP' ? (rowGender($row) === 'M' ? 'Husband' : 'Wife')
                : (prefixes[rcode] || (rcode ? $rel.find('option:selected').text() : ''));
            $row.find('.saved-as').text(name && prefix ? 'Saved as: ' + prefix + '= ' + name : '');
        }

        function addRow(values) {
            const $row = $(template.content.firstElementChild.cloneNode(true));
            Object.entries(values || {}).forEach(function([k, v]) {
                $row.find('[data-name="' + k + '"]').val(v);
            });
            $rows.append($row);
            updateSavedAs($row);
            renumber();
        }

        $('#addFamily').on('click', function() { addRow(); });
        $rows.on('click', '.remove-family', function() {
            $(this).closest('.family-row').remove();
            renumber();
        });
        $('#ICCODE').on('input', renumber);

        // Picking a relation fills in its gender (spouse: opposite of the holder) unless one is already chosen.
        $rows.on('change', '[data-name="RCODE"]', function() {
            const $row = $(this).closest('.family-row');
            const $g = $row.find('[data-name="ICHGENDER"]');
            if (!$g.val()) $g.val(rowGender($row));
            updateSavedAs($row);
        });
        $rows.on('input change', '[data-name="ICHENAME"], [data-name="ICHGENDER"]', function() {
            updateSavedAs($(this).closest('.family-row'));
        });
        $('select[name="ICHGENDER"]').on('change', function() {
            $rows.children('.family-row').each(function() { updateSavedAs($(this)); });
        });

        <?php foreach ($familyRows as $f): ?>
            addRow(<?= json_encode(array_map('strval', (array)$f), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
        <?php endforeach; ?>
        renumber();
    });
</script>
</body>

</html>
