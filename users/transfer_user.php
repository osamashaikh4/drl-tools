<?php
require_once __DIR__ . '/../auth.php';

include_once __DIR__ . '/conn.php';
include_once __DIR__ . '/user_functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['ecode']) && isset($_POST['source_bcode']) && isset($_POST['target_bcode'])) {
        $ecode        = trim($_POST['ecode']);
        $sourceBcode  = trim($_POST['source_bcode']);
        $targetBcode  = trim($_POST['target_bcode']);
        $userPassword = trim($_POST['user_password'] ?? '');
        $isIncharge   = isset($_POST['IS_INCHARGE']) ? true : false;

        if (empty($ecode) || empty($sourceBcode) || empty($targetBcode)) {
            $errorMsg = "Please select an employee, source branch, and target branch.";
        } elseif ($sourceBcode === $targetBcode) {
            $errorMsg = "Source and target branch must be different.";
        } elseif (!isset($connections[$targetBcode])) {
            $errorMsg = "Invalid target branch selected.";
        } else {
            $allStatus = [];

            // Resolve the source connection: either the Main Branch or a specific branch.
            $sourceConn = null;
            if ($sourceBcode === 'MAIN') {
                $sourceConn = $main_conn;
            } elseif (isset($connections[$sourceBcode])) {
                $sourceConn = connect_branch_db($sourceBcode, $connections);
            }

            $targetConn = connect_branch_db($targetBcode, $connections);

            if (!$sourceConn) {
                $allStatus[] = ['bcode' => $sourceBcode, 'query' => 'Connect', 'status' => 'Source branch database connection failed'];
            }
            if (!$targetConn) {
                $allStatus[] = ['bcode' => $targetBcode, 'query' => 'Connect', 'status' => 'Target branch database connection failed'];
            }

            if ($sourceConn && $targetConn) {
                // Privileged connection (system) for CREATE USER + role grants on the target.
                $adminConn = null;
                if (!DRY_RUN) {
                    $adminConn = connect_branch_db($targetBcode, $connections, $admin_user, $admin_pass);
                    if (!$adminConn) {
                        $e = oci_error();
                        $allStatus[] = ['bcode' => $targetBcode, 'query' => "Connect as {$admin_user}", 'status' => "Privileged (system) connection failed — grants will fail: " . ($e['message'] ?? 'unknown error')];
                    }
                }

                $copyResults = copy_user_to_branch($sourceConn, $targetConn, $targetBcode, $ecode, $userPassword, 'LAB', $isIncharge, $adminConn);
                $allStatus = array_merge($allStatus, $copyResults);

                // Only deactivate the source record once we've verified the
                // employee now actually exists on the target branch.
                if (DRY_RUN) {
                    $allStatus = array_merge($allStatus, set_emp_estatus($sourceConn, $sourceBcode, $ecode, 'N'));
                } elseif (user_exists_in_branch($targetConn, $ecode)) {
                    $allStatus = array_merge($allStatus, set_emp_estatus($sourceConn, $sourceBcode, $ecode, 'N'));
                } else {
                    $allStatus[] = ['bcode' => $sourceBcode, 'query' => "UPDATE EMP SET ESTATUS = 'N'", 'status' => "Skipped — could not verify employee on target branch, source ESTATUS left unchanged"];
                }
            }

            $_SESSION['users_status'] = $allStatus;
            header("Location: status.php");
            exit();
        }
    }
}

include __DIR__ . '/header.php';

$branches = get_branches_list($conn, $connections);
?>

<div class="max-w-4xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <a href="index.php" class="text-xs font-semibold text-slate-500 hover:text-blue-600 flex items-center gap-1 mb-2">
                ← Back to Dashboard
            </a>
            <h1 class="text-2xl font-bold text-slate-900">Transfer Employee</h1>
            <p class="text-sm text-slate-500">Move an employee from one branch to another and deactivate their old record.</p>
        </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <form action="transfer_user.php" method="POST" class="space-y-6">
        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-teal-100 text-teal-600 text-xs flex items-center justify-center font-bold">1</span>
                Select Source Branch
            </h2>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Source Branch <span class="text-red-500">*</span></label>
                <select required class="source_branch_select w-full" name="source_bcode">
                    <option value="">Select source branch</option>
                    <?php foreach ($branches as $bcode => $bname): ?>
                        <option value="<?= htmlspecialchars($bcode) ?>" <?= $bcode == '01' ? 'selected' : '' ?>><?= htmlspecialchars($bcode . ' - ' . $bname) ?></option>
                    <?php endforeach; ?>
                </select>
                <!-- <p class="text-xs text-slate-500 mt-1.5">The employee currently belongs to this branch — their record here will be set to inactive after a successful transfer.</p> -->
            </div>
        </div>

        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-teal-100 text-teal-600 text-xs flex items-center justify-center font-bold">2</span>
                Select Employee
            </h2>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Employee<span class="text-red-500">*</span></label>
                <select required disabled class="user_select w-full" name="ecode">
                    <option value="">Select a source branch first...</option>
                </select>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-teal-100 text-teal-600 text-xs flex items-center justify-center font-bold">3</span>
                Select Target Branch
            </h2>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Target Branch <span class="text-red-500">*</span></label>
                <select required class="target_branch_select w-full" name="target_bcode">
                    <option value="">Select target branch...</option>
                    <?php foreach ($branches as $bcode => $bname): ?>
                        <option value="<?= htmlspecialchars($bcode) ?>"><?= htmlspecialchars($bcode . ' - ' . $bname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-teal-100 text-teal-600 text-xs flex items-center justify-center font-bold">4</span>
                Account Credentials
            </h2>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Password <span class="text-red-500">*</span></label>
                <input required type="password" name="user_password" placeholder="" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
            </div>
        </div>

        <div class="flex items-center justify-end gap-4 pt-4">
            <a href="index.php" class="px-6 py-3 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-colors">
                Cancel
            </a>
            <button type="submit" class="px-8 py-3 rounded-xl bg-teal-600 text-sm font-semibold text-white shadow-lg shadow-teal-500/20 hover:bg-teal-500 transition flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4 4m-4-4l4-4" />
                </svg>
                Transfer Employee
            </button>
        </div>
    </form>
</div>

<script>
    $(document).ready(function() {
        $('.user_select').select2({
            placeholder: "Select a source branch first...",
            allowClear: true
        });
        $('.source_branch_select').select2({
            placeholder: "Select source branch",
        });
        $('.target_branch_select').select2({
            placeholder: "Select target branch",
        });

        var $employee = $('.user_select');

        function loadEmployeesForSource(bcode, silent) {
            $employee.prop('disabled', true).empty()
                .append(new Option('Loading employees...', '', true, true))
                .trigger('change');

            if (!bcode) {
                $employee.empty().append(new Option('Select a source branch first...', '', true, true)).trigger('change');
                return;
            }

            $.getJSON('ajax_employees.php', {
                bcode: bcode
            }, function(data) {
                $employee.empty().append(new Option('Search or select employee...', '', true, true));
                data.forEach(function(emp) {
                    $employee.append(new Option(emp.ename, emp.ecode, false, false));
                });
                $employee.prop('disabled', false).trigger('change');

                if (!silent && window.iziToast) {
                    iziToast.info({
                        title: 'Employees loaded',
                        message: data.length + ' employee(s) found on this branch',
                        position: 'topRight',
                        timeout: 2500
                    });
                }
            }).fail(function() {
                $employee.empty().append(new Option('Could not load employees', '', true, true)).prop('disabled', false).trigger('change');
                if (window.iziToast) {
                    iziToast.error({
                        title: 'Load failed',
                        message: 'Could not load employees from the selected branch',
                        position: 'topRight'
                    });
                }
            });
        }

        $('.source_branch_select').on('change', function() {
            loadEmployeesForSource($(this).val(), false);
        });

        // Auto-load employees for the pre-selected default source branch.
        loadEmployeesForSource($('.source_branch_select').val(), true);

        // Keep target branch from matching the chosen source branch.
        $('.source_branch_select').on('change', function() {
            var sourceVal = $(this).val();
            var $target = $('.target_branch_select');
            $target.find('option').prop('disabled', false);
            if (sourceVal && sourceVal !== 'MAIN') {
                $target.find('option[value="' + sourceVal + '"]').prop('disabled', true);
                if ($target.val() === sourceVal) {
                    $target.val('').trigger('change');
                }
            }
        });
    });
</script>
</body>

</html>