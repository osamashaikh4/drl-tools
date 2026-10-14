<?php
require_once __DIR__ . '/../auth.php';

include_once __DIR__ . '/conn.php';
include_once __DIR__ . '/card_functions.php';

set_time_limit(600);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sourceBcode    = trim($_POST['source_bcode'] ?? '');
    $iccodes        = array_values(array_filter(array_map('trim', (array)($_POST['iccode'] ?? []))));
    $targetBranches = array_values(array_diff((array)($_POST['bcode'] ?? []), [$sourceBcode]));
    $dryRun         = isset($_POST['dry_run']);

    if ($sourceBcode === '' || empty($iccodes) || empty($targetBranches)) {
        $errorMsg = "Please select a source branch, at least one card and at least one target branch (other than the source).";
    } elseif (!($srcConn = card_connect_branch($sourceBcode, $connections))) {
        $errorMsg = "Could not connect to source branch {$sourceBcode}.";
    } else {
        $allStatus = [];

        // Read every selected card once from the source.
        $cards = [];
        foreach ($iccodes as $iccode) {
            $data = card_fetch($srcConn, $iccode);
            if ($data === null) {
                $allStatus[] = ['bcode' => $sourceBcode, 'query' => "ICARDS ICCODE = {$iccode}", 'status' => "Failed: card {$iccode} not found on source branch"];
            } else {
                $cards[] = $data;
            }
        }

        foreach ($targetBranches as $bcode) {
            if (empty($cards) || !isset($connections[$bcode])) {
                continue;
            }
            $bconn = card_connect_branch($bcode, $connections);
            if (!$bconn) {
                $allStatus[] = ['bcode' => $bcode, 'query' => 'Connect', 'status' => 'Branch database connection failed'];
                continue;
            }
            foreach ($cards as $data) {
                $allStatus = array_merge($allStatus, card_write_to_branch($srcConn, $bconn, $bcode, $data['card'], $data['holders'], $dryRun));
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
?>

<div class="max-w-4xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <a href="index.php" class="text-xs font-semibold text-slate-500 hover:text-blue-600 flex items-center gap-1 mb-2">
                ← Back to Dashboard
            </a>
            <h1 class="text-2xl font-bold text-slate-900">Copy Card</h1>
            <p class="text-sm text-slate-500">Copy cards with all their holders from one branch into one or more branches.</p>
        </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <form action="copy_card.php" method="POST" class="space-y-6">
        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 text-xs flex items-center justify-center font-bold">1</span>
                Source
            </h2>
            <div class="space-y-5">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Source Branch <span class="text-red-500">*</span></label>
                    <select required class="source_branch_select w-full" name="source_bcode">
                        <option value=""></option>
                        <?php foreach ($branches as $bcode => $bname): ?>
                            <option value="<?= htmlspecialchars($bcode) ?>"><?= htmlspecialchars($bcode . ' - ' . $bname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Card(s) <span class="text-red-500">*</span></label>
                    <select required disabled multiple="multiple" class="card_select w-full" name="iccode[]"></select>
                    <p id="cardHint" class="text-xs text-slate-500 mt-1.5">Select a source branch to load its cards.</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 text-xs flex items-center justify-center font-bold">2</span>
                Select Target Branch(es)
            </h2>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Target Branch <span class="text-red-500">*</span></label>
                <select required class="branches_select w-full" multiple="multiple" name="bcode[]">
                    <?php foreach ($branches as $bcode => $bname): ?>
                        <option value="<?= htmlspecialchars($bcode) ?>"><?= htmlspecialchars($bcode . ' - ' . $bname) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-slate-500 mt-1.5">Cards that already exist on a target are left unchanged; only their missing holders are added.</p>
            </div>
        </div>

        <div class="flex items-center justify-between gap-4 pt-4">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="dry_run" value="1" class="w-4 h-4 rounded text-indigo-600 border-slate-300 focus:ring-indigo-500">
                <span class="text-sm font-semibold text-slate-700">Dry run <span class="font-normal text-slate-500">(show the inserts, change nothing)</span></span>
            </label>
            <div class="flex items-center gap-4">
                <a href="index.php" class="px-6 py-3 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-colors">
                    Cancel
                </a>
                <button type="submit" class="px-8 py-3 rounded-xl bg-indigo-600 text-sm font-semibold text-white shadow-lg shadow-indigo-500/20 hover:bg-indigo-500 transition flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    Copy Card
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    $(document).ready(function() {
        const $cards = $('.card_select');
        const $targets = $('.branches_select');
        const $hint = $('#cardHint');

        $('.source_branch_select').select2({ placeholder: "Select source branch" });
        $cards.select2({ placeholder: "Search cards by code or holder name" });
        $targets.select2({ placeholder: "Select target branches" });

        $('.source_branch_select').on('change', function() {
            const bcode = $(this).val();

            // The source cannot also be a target.
            $targets.find('option').prop('disabled', false);
            if (bcode) {
                $targets.find('option[value="' + bcode + '"]').prop('disabled', true).prop('selected', false);
            }
            $targets.trigger('change');

            $cards.empty().prop('disabled', true).trigger('change');
            if (!bcode) {
                $hint.text('Select a source branch to load its cards.');
                return;
            }
            $hint.text('Loading cards from branch ' + bcode + '…');

            $.getJSON('ajax_cards.php', { bcode: bcode })
                .done(function(cards) {
                    cards.forEach(function(c) {
                        $cards.append(new Option(c.text, c.id));
                    });
                    $cards.prop('disabled', false).trigger('change');
                    $hint.text(cards.length + ' cards on branch ' + bcode + '.');
                })
                .fail(function(xhr) {
                    const msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Could not load cards.';
                    $hint.text(msg);
                    iziToast.error({ title: 'Error', message: msg });
                });
        });
    });
</script>
</body>

</html>
