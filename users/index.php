<?php
require_once __DIR__ . '/../auth.php';
include __DIR__ . '/header.php';

include_once __DIR__ . '/conn.php';
include_once __DIR__ . '/user_functions.php';

$branches = get_branches_list($conn, $connections);
?>

<div class="max-w-7xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <!-- Hero / Welcome Banner -->
    <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-700 rounded-3xl p-8 text-white shadow-xl mb-10 relative overflow-hidden">
        <div class="relative z-10 max-w-3xl">
            <!-- <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-white/20 text-white backdrop-blur-sm mb-4">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                Connected to Main Branch (10.1.52.253/drl)
            </span> -->
            <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight">DRL Employee & User Management</h1>
            <p class="mt-3 text-base sm:text-lg text-blue-10/90 text-blue-100 font-normal">
                Manage branch employee profiles, replicate user permissions across Oracle database clusters, and run system tasks seamlessly.
            </p>
        </div>
        <div class="absolute right-0 bottom-0 opacity-15 pointer-events-none transform translate-x-10 translate-y-10">
            <img src="../drlogo.png" class="h-80 w-auto" alt="Background Logo">
        </div>
    </div>

    <!-- Main Action Options Grid -->
    <div class="mb-12">
        <h2 class="text-xl font-bold text-slate-900 mb-6 flex items-center gap-2">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
            </svg>
            Choose Management Action
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-8">
            <!-- Option 1: Add New User Card -->
            <div class="group bg-white rounded-3xl p-8 border border-slate-200/80 shadow-sm hover:shadow-xl hover:border-blue-300 transition-all duration-300 flex flex-col justify-between relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-blue-50 rounded-bl-full -mr-8 -mt-8 transition-transform group-hover:scale-110"></div>

                <div class="relative z-10">
                    <div class="w-14 h-14 bg-blue-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-blue-500/30 mb-6 group-hover:scale-105 transition-transform">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                        </svg>
                    </div>
                    <span class="text-xs font-bold uppercase tracking-wider text-blue-600 bg-blue-50 px-3 py-1 rounded-full">New Entry</span>
                    <h3 class="text-2xl font-bold text-slate-900 mt-3 mb-3">Add New User</h3>
                    <!-- <p class="text-slate-600 text-sm leading-relaxed mb-6">
                        Create a fresh employee entry specifying branch type and standard details: <span class="font-mono text-xs text-slate-700 bg-slate-100 px-1 py-0.5 rounded">ECODE</span>, <span class="font-mono text-xs text-slate-700 bg-slate-100 px-1 py-0.5 rounded">ENAME</span>, <span class="font-mono text-xs text-slate-700 bg-slate-100 px-1 py-0.5 rounded">DESIGNATION</span>, <span class="font-mono text-xs text-slate-700 bg-slate-100 px-1 py-0.5 rounded">DEPARTMENT</span>, <span class="font-mono text-xs text-slate-700 bg-slate-100 px-1 py-0.5 rounded">STATUS</span>, dates & photo.
                    </p> -->
                </div>

                <div class="relative z-10 pt-4 border-t border-slate-100 flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-400">Complete EMP Schema Form</span>
                    <a href="add_user.php" class="inline-flex items-center gap-2 font-semibold text-sm text-blue-600 group-hover:text-blue-700 group-hover:translate-x-1 transition-all">
                        Create User
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </a>
                </div>
            </div>

            <!-- Option 2: Copy Existing User Card -->
            <div class="group bg-white rounded-3xl p-8 border border-slate-200/80 shadow-sm hover:shadow-xl hover:border-indigo-300 transition-all duration-300 flex flex-col justify-between relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-indigo-50 rounded-bl-full -mr-8 -mt-8 transition-transform group-hover:scale-110"></div>

                <div class="relative z-10">
                    <div class="w-14 h-14 bg-indigo-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-500/30 mb-6 group-hover:scale-105 transition-transform">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span class="text-xs font-bold uppercase tracking-wider text-indigo-600 bg-indigo-50 px-3 py-1 rounded-full">Replication</span>
                    <h3 class="text-2xl font-bold text-slate-900 mt-3 mb-3">Copy Existing User</h3>
                    <!-- <p class="text-slate-600 text-sm leading-relaxed mb-6">
                        Select an existing employee from the Main Branch and replicate their data across <span class="font-mono text-xs text-slate-700 bg-slate-100 px-1 py-0.5 rounded">EMP</span>, <span class="font-mono text-xs text-slate-700 bg-slate-100 px-1 py-0.5 rounded">FSTARTUP</span>, <span class="font-mono text-xs text-slate-700 bg-slate-100 px-1 py-0.5 rounded">UROLES</span>, and <span class="font-mono text-xs text-slate-700 bg-slate-100 px-1 py-0.5 rounded">URPIVS</span> tables into selected branch databases, automatically creating the DB user.
                    </p> -->
                </div>

                <div class="relative z-10 pt-4 border-t border-slate-100 flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-400">Multi-Table Replication</span>
                    <a href="copy_user.php" class="inline-flex items-center gap-2 font-semibold text-sm text-indigo-600 group-hover:text-indigo-700 group-hover:translate-x-1 transition-all">
                        Copy User
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </a>
                </div>
            </div>

            <!-- Option 3: Transfer Employee Card -->
            <div class="group bg-white rounded-3xl p-8 border border-slate-200/80 shadow-sm hover:shadow-xl hover:border-teal-300 transition-all duration-300 flex flex-col justify-between relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-teal-50 rounded-bl-full -mr-8 -mt-8 transition-transform group-hover:scale-110"></div>

                <div class="relative z-10">
                    <div class="w-14 h-14 bg-teal-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-teal-500/30 mb-6 group-hover:scale-105 transition-transform">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4 4m-4-4l4-4" />
                        </svg>
                    </div>
                    <span class="text-xs font-bold uppercase tracking-wider text-teal-600 bg-teal-50 px-3 py-1 rounded-full">Relocation</span>
                    <h3 class="text-2xl font-bold text-slate-900 mt-3 mb-3">Transfer Employee</h3>
                </div>

                <div class="relative z-10 pt-4 border-t border-slate-100 flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-400">Copy & Deactivate Source</span>
                    <a href="transfer_user.php" class="inline-flex items-center gap-2 font-semibold text-sm text-teal-600 group-hover:text-teal-700 group-hover:translate-x-1 transition-all">
                        Transfer
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </a>
                </div>
            </div>

            <!-- Option 3: Manage User Account Card -->
            <div class="group bg-white rounded-3xl p-8 border border-slate-200/80 shadow-sm hover:shadow-xl hover:border-purple-300 transition-all duration-300 flex flex-col justify-between relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-purple-50 rounded-bl-full -mr-8 -mt-8 transition-transform group-hover:scale-110"></div>

                <div class="relative z-10">
                    <div class="w-14 h-14 bg-purple-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-purple-500/30 mb-6 group-hover:scale-105 transition-transform">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 10-8 0v4h8z" />
                        </svg>
                    </div>
                    <span class="text-xs font-bold uppercase tracking-wider text-purple-600 bg-purple-50 px-3 py-1 rounded-full">Account Access</span>
                    <h3 class="text-2xl font-bold text-slate-900 mt-3 mb-3">Manage User Account</h3>
                </div>

                <div class="relative z-10 pt-4 border-t border-slate-100 flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-400">Reset Password / Unlock</span>
                    <a href="manage_user.php" class="inline-flex items-center gap-2 font-semibold text-sm text-purple-600 group-hover:text-purple-700 group-hover:translate-x-1 transition-all">
                        Manage
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick System Overview -->
    <!-- <div class="bg-white rounded-3xl p-8 border border-slate-200/80 shadow-sm">
        <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-bold text-slate-900">Configured Branch Network</h3>
                <p class="text-xs text-slate-500">Active branches available for database synchronization</p>
            </div>
            <a href="execute_sql.php" class="text-xs font-semibold text-blue-600 hover:text-blue-700 flex items-center gap-1">
                Run Direct SQL Commands →
            </a>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            <?php foreach ($branches as $bcode => $bname): ?>
                <div class="p-3 bg-slate-50 border border-slate-100 rounded-xl text-center hover:bg-blue-50/50 hover:border-blue-200 transition-colors">
                    <span class="text-xs font-bold text-slate-400 block mb-0.5">Code <?= htmlspecialchars($bcode) ?></span>
                    <span class="text-xs font-semibold text-slate-700 truncate block" title="<?= htmlspecialchars($bname) ?>"><?= htmlspecialchars($bname) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div> -->
</div>
</body>

</html>