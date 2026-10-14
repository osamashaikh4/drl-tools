<?php
require_once __DIR__ . '/../auth.php';

include_once __DIR__ . '/conn.php';
include_once __DIR__ . '/user_functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['ecode']) && isset($_POST['bcode'])) {
        $sourceEcode    = trim($_POST['ecode']);
        $targetBranches = (array)$_POST['bcode'];
        $userPassword   = trim($_POST['user_password'] ?? '');
        $isIncharge     = isset($_POST['IS_INCHARGE']) ? true : false;

        // User type is derived from the source employee's department (DCODE)
        // inside copy_user_to_branch(); this passed value is just a fallback.
        $userType       = 'LAB';

        if (empty($sourceEcode) || empty($targetBranches) || empty($userPassword)) {
            $errorMsg = "Please select both a user and at least one target branch.";
        } else {
            $allStatus = [];
            foreach ($targetBranches as $bcode) {
                if (!empty($bcode) && isset($connections[$bcode])) {
                    $bconn = connect_branch_db($bcode, $connections);
                    if (!$bconn) {
                        $allStatus[] = ['bcode' => $bcode, 'query' => 'Connect', 'status' => 'Branch database connection failed'];
                    } else {
                        // Privileged connection (system) for CREATE USER + role grants.
                        $adminConn = null;
                        if (!DRY_RUN) {
                            $adminConn = connect_branch_db($bcode, $connections, $admin_user, $admin_pass);
                            if (!$adminConn) {
                                $e = oci_error();
                                $allStatus[] = ['bcode' => $bcode, 'query' => "Connect as {$admin_user}", 'status' => "Privileged (system) connection failed — grants will fail: " . ($e['message'] ?? 'unknown error')];
                            }
                        }
                        $res = copy_user_to_branch($main_conn, $bconn, $bcode, $sourceEcode, $userPassword, $userType, $isIncharge, $adminConn);
                        $allStatus = array_merge($allStatus, $res);
                    }
                }
            }
            $_SESSION['users_status'] = $allStatus;
            header("Location: status.php");
            exit();
        }
    }
}

include __DIR__ . '/header.php';

$branches  = get_branches_list($conn, $connections);
$employees = get_employees_list($main_conn);
?>

<div class="max-w-4xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <a href="index.php" class="text-xs font-semibold text-slate-500 hover:text-blue-600 flex items-center gap-1 mb-2">
                ← Back to Dashboard
            </a>
            <h1 class="text-2xl font-bold text-slate-900">Copy Existing User</h1>
            <p class="text-sm text-slate-500">Replicate user profile, roles, and privileges.</p>
        </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <form action="copy_user.php" method="POST" class="space-y-6">
        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 text-xs flex items-center justify-center font-bold">1</span>
                Select Employee
            </h2>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Employee<span class="text-red-500">*</span></label>
                <?php if (!empty($employees)): ?>
                    <select required class="user_select w-full" name="ecode">
                        <option value="">Search or select employee...</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= htmlspecialchars($emp['ECODE']) ?>">
                                <?= htmlspecialchars($emp['ENAME']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" name="ecode" required placeholder="Enter ECODE manually (e.g. 1042)" class="w-full rounded-lg border-slate-300 border text-sm p-3 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <p class="text-xs text-amber-600 mt-1">Note: Could not query employee list automatically from main branch database.</p>
                <?php endif; ?>
                <!-- <p class="text-xs text-slate-500 mt-2">Data will be pulled from <span class="font-mono text-slate-700 font-semibold">EMP</span>, <span class="font-mono text-slate-700 font-semibold">FSTARTUP</span>, <span class="font-mono text-slate-700 font-semibold">UROLES</span>, and <span class="font-mono text-slate-700 font-semibold">URPIVS</span> tables on the main branch.</p> -->
            </div>
        </div>

        <!-- Card 2: Target Branch Selection -->
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
                <!-- <p class="text-xs text-slate-500 mt-1.5">You can select one or multiple target branches to replicate this user simultaneously.</p> -->
            </div>
        </div>

        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 text-xs flex items-center justify-center font-bold">3</span>
                Account Credentials
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Password <span class="text-red-500">*</span></label>
                    <input required type="password" name="user_password" placeholder="" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <!-- Incharge checkbox hidden for now -->
                <!-- <div class="flex items-end pb-2.5">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="IS_INCHARGE" value="1" class="w-4 h-4 rounded text-indigo-600 border-slate-300 focus:ring-indigo-500">
                        <span class="text-sm font-semibold text-slate-800">Incharge</span>
                    </label>
                </div> -->


                <!-- <div class="sm:col-span-2">
                    <p class="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg p-2.5">
                        User Type is determined automatically from the source employee's <span class="font-mono font-semibold text-slate-700">Department (DCODE)</span> — <span class="font-mono font-semibold text-slate-700">RC</span> is treated as a Reception user; any other department as a Lab user.
                    </p>
                </div> -->
            </div>
        </div>

        <div class="flex items-center justify-end gap-4 pt-4">
            <a href="index.php" class="px-6 py-3 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-colors">
                Cancel
            </a>
            <button type="submit" class="px-8 py-3 rounded-xl bg-indigo-600 text-sm font-semibold text-white shadow-lg shadow-indigo-500/20 hover:bg-indigo-500 transition-all flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                Copy User
            </button>
        </div>
    </form>
</div>

<script>
    $(document).ready(function() {
        $('.user_select').select2({
            placeholder: "Search employee",
            allowClear: true
        });
        $('.branches_select').select2({
            placeholder: "Select target branches",
        });
    });
</script>
</body>

</html>