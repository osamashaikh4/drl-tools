<?php
require_once __DIR__ . '/../auth.php';

include_once __DIR__ . '/conn.php';
include_once __DIR__ . '/user_functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['ecode']) && isset($_POST['bcode'])) {
        $ecode       = trim($_POST['ecode']);
        $bcode       = trim($_POST['bcode']);
        $action      = isset($_POST['do_unlock']) ? 'unlock' : 'reset';
        $newPassword = trim($_POST['new_password'] ?? '');

        if (empty($ecode) || empty($bcode) || !isset($connections[$bcode])) {
            $errorMsg = "Please select both a branch and a user.";
        } elseif ($action === 'reset' && empty($newPassword)) {
            $errorMsg = "Please enter a new password to reset.";
        } else {
            $allStatus = [];

            // ALTER USER requires a privileged account (system), not the drl schema account.
            $adminConn = null;
            if (!DRY_RUN) {
                $adminConn = connect_branch_db($bcode, $connections, $admin_user, $admin_pass);
                if (!$adminConn) {
                    $e = oci_error();
                    $allStatus[] = ['bcode' => $bcode, 'query' => "Connect as {$admin_user}", 'status' => "Privileged (system) connection failed — operation will fail: " . ($e['message'] ?? 'unknown error')];
                }
            }

            if ($action === 'unlock') {
                $res = unlock_user_account($adminConn, $bcode, $ecode);
            } else {
                $res = reset_user_password($adminConn, $bcode, $ecode, $newPassword);
            }
            $allStatus = array_merge($allStatus, $res);

            $_SESSION['users_status'] = $allStatus;
            header("Location: status.php");
            exit();
        }
    }
}

// Refill the form after a rejected submit so nothing has to be picked again (except the password).
$old = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
function chosen($key, $value)
{
    global $old;
    return (string)($old[$key] ?? '') === (string)$value ? 'selected' : '';
}

include __DIR__ . '/header.php';

$branches  = get_branches_list($conn, $connections);
?>

<div class="max-w-4xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <a href="index.php" class="text-xs font-semibold text-slate-500 hover:text-blue-600 flex items-center gap-1 mb-2">
                ← Back to Dashboard
            </a>
            <h1 class="text-2xl font-bold text-slate-900">Manage User Account</h1>
            <p class="text-sm text-slate-500">Reset a database password or unlock a locked account on a branch.</p>
        </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <form id="manageForm" action="manage_user.php" method="POST" class="space-y-6">
        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-purple-100 text-purple-600 text-xs flex items-center justify-center font-bold">1</span>
                Select Branch
            </h2>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Branch <span class="text-red-500">*</span></label>
                <select required class="source_branch_select w-full" name="bcode">
                    <option value="">Select branch</option>
                    <?php foreach ($branches as $bcode => $bname): ?>
                        <option value="<?= htmlspecialchars($bcode) ?>" <?= ($old ? chosen('bcode', $bcode) : ($bcode == '01' ? 'selected' : '')) ?>><?= htmlspecialchars($bcode . ' - ' . $bname) ?></option>
                    <?php endforeach; ?>
                </select>
                <!-- <p class="text-xs text-slate-500 mt-1.5">Employees below are looked up from this branch, and the operation runs against it.</p> -->
            </div>
        </div>

        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-purple-100 text-purple-600 text-xs flex items-center justify-center font-bold">2</span>
                Select Employee
            </h2>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Employee<span class="text-red-500">*</span></label>
                <select required disabled class="user_select w-full" name="ecode" data-old="<?= htmlspecialchars((string)($old['ecode'] ?? '')) ?>">
                    <option value="">Select a branch first...</option>
                </select>
                <p id="employeeHint" class="text-xs text-slate-500 mt-1.5 tabular-nums"></p>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-purple-100 text-purple-600 text-xs flex items-center justify-center font-bold">3</span>
                Reset Password
            </h2>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">New Password</label>
                <input type="password" id="newPassword" name="new_password" placeholder="Only required to reset password" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                <p id="passwordHint" class="hidden text-xs text-red-600 mt-1.5">Enter a new password to reset.</p>
                <!-- <p class="text-xs text-slate-500 mt-1.5">Runs <span class="font-mono text-slate-700">ALTER USER &lt;ECODE&gt; IDENTIFIED BY &lt;password&gt;</span> on each selected branch.</p> -->
            </div>
        </div>

        <div class="flex items-center justify-end gap-4 pt-4">
            <a href="index.php" class="press px-6 py-3 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-colors">
                Cancel
            </a>
            <button type="submit" name="do_unlock" value="1" data-busy="Unlocking…" class="action-btn px-8 py-3 rounded-xl bg-amber-500 text-sm font-semibold text-white shadow-lg shadow-amber-500/20 hover:bg-amber-400 transition flex items-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed">
                <span class="btn-spinner spinner hidden" aria-hidden="true"></span>
                <svg class="btn-icon w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" />
                </svg>
                <span class="btn-label">Unlock Account</span>
            </button>
            <button type="submit" name="do_reset" value="1" data-busy="Resetting…" class="action-btn px-8 py-3 rounded-xl bg-purple-600 text-sm font-semibold text-white shadow-lg shadow-purple-500/20 hover:bg-purple-500 transition flex items-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed">
                <span class="btn-spinner spinner hidden" aria-hidden="true"></span>
                <svg class="btn-icon w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                </svg>
                <span class="btn-label">Reset Password</span>
            </button>
        </div>
    </form>
</div>

<script>
    $(document).ready(function() {
        $('.user_select').select2({
            placeholder: "Select a branch first...",
            allowClear: true
        });
        $('.source_branch_select').select2({
            placeholder: "Select branch",
        });

        var $employee = $('.user_select');
        var $hint = $('#employeeHint');
        var SPINNER = '<span class="spinner" aria-hidden="true"></span>';
        var loadToken = 0; // the newest branch wins; late answers for an older pick are ignored
        var restoreEcode = $employee.data('old') || ''; // reselect after a rejected submit, once

        function loadEmployeesForSource(bcode) {
            var token = ++loadToken;
            $employee.prop('disabled', true).empty()
                .append(new Option(bcode ? 'Loading employees...' : 'Select a branch first...', '', true, true))
                .trigger('change');

            if (!bcode) {
                $hint.text('');
                return;
            }
            $hint.html(SPINNER + ' Loading employees from branch ' + $('<span>').text(bcode).html() + '…');

            $.getJSON('ajax_employees.php', { bcode: bcode })
                .done(function(data) {
                    if (token !== loadToken) return;
                    // Build every option at once, labelled with the ECODE so namesakes can be told apart.
                    var frag = document.createDocumentFragment();
                    frag.appendChild(new Option('Search or select employee...', '', true, true));
                    data.forEach(function(emp) {
                        frag.appendChild(new Option(emp.ename + ' (' + emp.ecode + ')', emp.ecode, false, false));
                    });
                    $employee.empty()[0].appendChild(frag);
                    if (restoreEcode && $employee.find('option[value="' + $.escapeSelector(restoreEcode) + '"]').length) {
                        $employee.val(restoreEcode);
                    }
                    restoreEcode = '';
                    $employee.prop('disabled', false).trigger('change');
                    $hint.text(data.length.toLocaleString('en-US') + ' employee' + (data.length === 1 ? '' : 's') + ' on branch ' + bcode + '.');
                })
                .fail(function() {
                    if (token !== loadToken) return;
                    $employee.empty().append(new Option('Could not load employees', '', true, true)).trigger('change');
                    $hint.empty()
                        .append($('<span class="text-red-600">').text('Could not load employees from branch ' + bcode + '. '))
                        .append('<button type="button" id="retryEmployees" class="font-semibold text-purple-700 hover:text-purple-600 underline underline-offset-2">Retry</button>');
                    iziToast.error({ title: 'Load failed', message: 'Could not load employees from the selected branch', position: 'topRight' });
                });
        }
        $hint.on('click', '#retryEmployees', function() { loadEmployeesForSource($('.source_branch_select').val()); });

        $('.source_branch_select').on('change', function() {
            loadEmployeesForSource($(this).val());
        });
        loadEmployeesForSource($('.source_branch_select').val());

        var form = document.getElementById('manageForm');
        var password = document.getElementById('newPassword');
        var passwordHint = document.getElementById('passwordHint');

        // Enter must not submit: the first button is Unlock, so Enter in the password box would unlock instead of reset.
        form.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.matches('input:not([type="checkbox"]):not([type="submit"])')) e.preventDefault();
        });
        password.addEventListener('input', function() { passwordHint.classList.add('hidden'); });

        function setBusy(button) {
            document.querySelectorAll('.action-btn').forEach(function(b) {
                var on = !!button;
                b.disabled = on;
                b.querySelector('.btn-spinner').classList.toggle('hidden', !(on && b === button));
                b.querySelector('.btn-icon').classList.toggle('hidden', on && b === button);
                var label = b.querySelector('.btn-label');
                if (!label.dataset.idle) label.dataset.idle = label.textContent;
                label.textContent = on && b === button ? b.dataset.busy : label.dataset.idle;
            });
        }

        form.addEventListener('submit', function(e) {
            var button = e.submitter;
            if (!button || button.disabled) return e.preventDefault();

            // Reset needs a password: say so here instead of a round trip to the server.
            if (button.name === 'do_reset' && !password.value.trim()) {
                e.preventDefault();
                passwordHint.classList.remove('hidden');
                password.focus();
                return;
            }

            // A disabled button is left out of the submitted data, and the server reads do_unlock / do_reset
            // to know which action to run, so carry the clicked button's name in a hidden field first.
            form.querySelectorAll('input[data-submitter]').forEach(function(i) { i.remove(); });
            var carry = document.createElement('input');
            carry.type = 'hidden';
            carry.name = button.name;
            carry.value = button.value;
            carry.dataset.submitter = '1';
            form.appendChild(carry);
            setBusy(button);
        });
        // Coming back with the browser's Back button can restore the page mid-action.
        window.addEventListener('pageshow', function() {
            form.querySelectorAll('input[data-submitter]').forEach(function(i) { i.remove(); });
            setBusy(null);
        });
    });
</script>
</body>

</html>