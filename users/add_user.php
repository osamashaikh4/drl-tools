<?php
require_once __DIR__ . '/../auth.php';

include_once __DIR__ . '/conn.php';
include_once __DIR__ . '/user_functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['ECODE']) && isset($_POST['bcode'])) {
        $targetBranches = (array)$_POST['bcode'];
        $formData = [
            'ECODE'          => trim($_POST['ECODE']),
            'ENAME'          => trim($_POST['ENAME'] ?? ''),
            'EFNAME'         => trim($_POST['EFNAME'] ?? ''),
            'TITLE'          => trim($_POST['TITLE'] ?? ''),
            'SURNAME'        => trim($_POST['SURNAME'] ?? ''),
            'GENDER'         => trim($_POST['GENDER'] ?? ''),
            'DOB'            => trim($_POST['DOB'] ?? ''),
            'DOJ'            => trim($_POST['DOJ'] ?? ''),
            'GRADE'          => trim($_POST['GRADE'] ?? ''),
            'CNIC'           => trim($_POST['CNIC'] ?? ''),
            'EDCODE'         => trim($_POST['EDCODE'] ?? ''),
            'ESTCODE'        => 'P',
            'DCODE'          => trim($_POST['DCODE'] ?? ''),
            'ESTATUS'        => trim($_POST['ESTATUS'] ?? 'Y'),
            'EREMARKS'       => 'DRL User Management',
            'EUNAME'         => trim($_POST['EUNAME'] ?? ''),
            'EDISPLAYNAME'   => trim($_POST['EDISPLAYNAME'] ?? ''),
            'create_db_user' => isset($_POST['create_db_user']) ? true : false,
            'user_password'  => trim($_POST['user_password'] ?? ''),
            'IS_INCHARGE'    => isset($_POST['IS_INCHARGE']) ? true : false,
        ];

        $provisioning = resolve_provisioning_for_dcode($formData['DCODE']);
        $formData['USER_TYPE']    = $provisioning['user_type'] ?? 'LAB';
        $formData['LAB_TEMPLATE'] = $provisioning['lab_template'] ?? '';

        if (empty($formData['ECODE']) || empty($formData['EDCODE']) || empty($formData['DCODE'])) {
            $errorMsg = "ECODE, EDCODE (Designation), and DCODE (Department) are required fields.";
        } else {
            $allStatus = [];
            foreach ($targetBranches as $bcode) {
                if (!empty($bcode) && isset($connections[$bcode])) {
                    $bconn = connect_branch_db($bcode, $connections);
                    if (!$bconn && !DRY_RUN) {
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
                        // In DRY_RUN the connection is unused; still preview the queries.
                        $res = add_new_user_to_branch($bconn, $bcode, $formData, $adminConn);
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

$branches     = get_branches_list($conn, $connections);
$departments  = get_departments_list($conn);
$designations = get_designations_list($conn);
// $estypes      = get_estypes_list($conn);
?>

<div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <a href="index.php" class="text-xs font-semibold text-slate-500 hover:text-drl-600 flex items-center gap-1 mb-2">
                ← Back to Dashboard
            </a>
            <h1 class="text-2xl font-bold text-slate-900">Add New User / Employee</h1>
            <p class="text-sm text-slate-500">Fill out employee profile details to register into selected branch databases.</p>
        </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <form action="add_user.php" method="POST" class="space-y-8">
        <!-- Section 1: Branch Selection -->
        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-drl-100 text-drl-600 text-xs flex items-center justify-center font-bold">1</span>
                Branch Selection
            </h2>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">Target Branch <span class="text-red-500">*</span></label>
                <select required class="branches_select w-full" multiple="multiple" name="bcode[]">
                    <?php foreach ($branches as $bcode => $bname): ?>
                        <option value="<?= htmlspecialchars($bcode) ?>"><?= htmlspecialchars($bcode . ' - ' . $bname) ?></option>
                    <?php endforeach; ?>
                </select>
                <!-- <p class="text-xs text-slate-500 mt-1">Select one or multiple branches where this employee will be added.</p> -->
            </div>
        </div>

        <!-- Section 2: Personal Information -->
        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-drl-100 text-drl-600 text-xs flex items-center justify-center font-bold">2</span>
                Personal Information
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">ECODE <span class="text-red-500">*</span></label>
                    <input type="text" name="ECODE" required placeholder="" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Title</label>
                    <select name="TITLE" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500 bg-white">
                        <option value="">Select Title</option>
                        <option value="Mr.">Mr.</option>
                        <option value="Mrs.">Mrs.</option>
                        <option value="Ms.">Ms.</option>
                        <option value="Dr.">Dr.</option>
                        <option value="Prof.">Prof.</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Full Name (ENAME)</label>
                    <input type="text" name="ENAME" placeholder="" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Father Name (EFNAME)</label>
                    <input type="text" name="EFNAME" placeholder="" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Surname</label>
                    <input type="text" name="SURNAME" placeholder="" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Gender</label>
                    <select name="GENDER" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500 bg-white">
                        <option value="M">Male</option>
                        <option value="F">Female</option>
                        <option value="O">Other</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">CNIC</label>
                    <input type="text" name="CNIC" placeholder="41303-6964121-5" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Date of Birth (DOB)</label>
                    <input type="date" name="DOB" placeholder="YYYY-MM-DD" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Date of Joining (DOJ)</label>
                    <input type="date" name="DOJ" placeholder="YYYY-MM-DD" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500">
                </div>
            </div>
        </div>

        <!-- Section 3: Employment Details -->
        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-drl-100 text-drl-600 text-xs flex items-center justify-center font-bold">3</span>
                Employment & Organizational Details
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Department (DCODE) <span class="text-red-500">*</span></label>
                    <select name="DCODE" required class="tag_select w-full">
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dcode => $dname): ?>
                            <option value="<?= htmlspecialchars($dcode) ?>"><?= htmlspecialchars($dname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Designation (EDCODE) <span class="text-red-500">*</span></label>
                    <select name="EDCODE" required class="tag_select w-full">
                        <option value="">Select Designation</option>
                        <?php foreach ($designations as $edcode => $edname): ?>
                            <option value="<?= htmlspecialchars($edcode) ?>"><?= htmlspecialchars($edname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Status Type (ESTCODE) <span class="text-red-500">*</span></label>
                    <select name="ESTCODE" required class="tag_select w-full">
                        <option value="">Select Status</option>
                        <?php foreach (($estypes ?? []) as $estcode => $estname): ?>
                            <option value="<?= htmlspecialchars($estcode) ?>"><?= htmlspecialchars($estname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div> -->

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Grade</label>
                    <select name="GRADE" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500 bg-white">
                        <option value="">Select Grade</option>
                        <?php for ($g = 1; $g <= 22; $g++): ?>
                            <option value="BPS-<?= $g ?>">BPS-<?= $g ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <!-- Status (ESTATUS) dropdown hidden — defaults to 'Y' (Active) in the POST handler
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Status (ESTATUS)</label>
                    <select name="ESTATUS" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500 bg-white">
                        <option value="Y">Active</option>
                        <option value="N">Inactive</option>
                    </select>
                </div>
                -->
            </div>
        </div>

        <!-- Section 4: Account & Database Credentials -->
        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-drl-100 text-drl-600 text-xs flex items-center justify-center font-bold">4</span>
                System & Database Account
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Username (EUNAME)</label>
                    <input type="text" name="EUNAME" placeholder="" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Display Name (EDISPLAYNAME)</label>
                    <input type="text" name="EDISPLAYNAME" placeholder="" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Password</label>
                    <input type="password" name="user_password" placeholder="" class="w-full rounded-lg border-slate-300 border text-sm p-2.5 focus:ring-2 focus:ring-drl-500 focus:border-drl-500">
                </div>

                <!-- Incharge checkbox hidden for now -->
                <div class="flex items-end pb-2.5">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="IS_INCHARGE" value="1" class="w-4 h-4 rounded text-drl-600 border-slate-300 focus:ring-drl-500">
                        <span class="text-sm font-semibold text-slate-800">Incharge</span>
                    </label>
                </div>


                <!-- <div class="sm:col-span-2 md:col-span-3">
                    <p class="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg p-2.5">
                        User Type and Lab privileges are determined automatically from the selected <span class="font-mono font-semibold text-slate-700">Department (DCODE)</span> — <span class="font-mono font-semibold text-slate-700">RC</span> is provisioned as a Reception user; any other department as a Lab user with its department-specific privileges.
                    </p>
                </div> -->

                <div class="sm:col-span-2 md:col-span-3 pt-2 invisible h-0 w-0">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="create_db_user" value="1" checked class="w-4 h-4 rounded text-drl-600 border-slate-300 focus:ring-drl-500">
                        <!-- <span class="text-sm font-semibold text-slate-800">Create Oracle Database User account with EUNAME / ECODE</span> -->
                    </label>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-4 pt-4">
            <a href="index.php" class="px-6 py-3 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-100 transition-colors">
                Cancel
            </a>
            <button type="submit" class="px-8 py-3 rounded-xl bg-drl-600 text-sm font-semibold text-white shadow-lg shadow-drl-500/20 hover:bg-drl-500 transition">
                Create User
            </button>
        </div>
    </form>
</div>

<script>
    $(document).ready(function() {
        $('.branches_select').select2({
            placeholder: "Select target branches",
        });
        $('.tag_select').select2({
            tags: true,
            placeholder: "Select",
            createTag: function(params) {
                var term = $.trim(params.term);
                if (term === '') {
                    return null;
                }
                return {
                    id: term,
                    text: term,
                    newTag: true
                };
            }
        });
    });
</script>
</body>

</html>