<?php
require_once __DIR__ . '/../auth.php';
include __DIR__ . '/header.php';
?>

<div class="max-w-6xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-8">
        <div class="flex items-center justify-between pb-6 mb-6 border-b border-slate-100">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Execution & Synchronization Results</h1>
                <p class="text-sm text-slate-500">Summary of database operations executed across branch servers.</p>
            </div>
            <a href="index.php" class="px-4 py-2 bg-blue-50 text-blue-600 rounded-xl text-sm font-semibold hover:bg-blue-100 transition-colors">
                ← Back to Dashboard
            </a>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-slate-200">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-slate-700 text-xs font-semibold uppercase tracking-wider border-b border-slate-200">
                        <th class="py-3.5 px-4 text-center w-28">Branch Code</th>
                        <th class="py-3.5 px-4">Operation / Query</th>
                        <th class="py-3.5 px-4">Execution Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                    <?php if (isset($_SESSION['cards_status']) && !empty($_SESSION['cards_status'])): ?>
                        <?php foreach ($_SESSION['cards_status'] as $status): ?>
                            <?php
                                $isDryRun  = strpos(strtolower($status['status']), 'dry run') !== false;
                                $isSuccess = (strpos(strtolower($status['status']), 'success') !== false || strpos(strtolower($status['status']), 'copied') !== false || strpos(strtolower($status['status']), 'created') !== false);
                                $isNotice  = strpos(strtolower($status['status']), 'notice') !== false || strpos(strtolower($status['status']), 'already exists') !== false || strpos(strtolower($status['status']), 'skipped') !== false;
                            ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3.5 px-4 text-center font-bold text-slate-900 align-top">
                                    <span class="inline-block px-2.5 py-1 bg-slate-100 text-slate-700 rounded-md text-xs font-mono">
                                        <?= htmlspecialchars($status['bcode'] ?? 'N/A') ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-xs text-slate-700 <?= $isDryRun ? 'whitespace-pre-wrap break-all' : 'max-w-md truncate' ?>" title="<?= htmlspecialchars($status['query'] ?? '') ?>">
                                    <?= htmlspecialchars($status['query'] ?? 'N/A') ?>
                                </td>
                                <td class="py-3.5 px-4 align-top">
                                    <?php if ($isDryRun): ?>
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-sky-50 text-sky-700 border border-sky-200/60 rounded-full text-xs font-medium">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            <?= htmlspecialchars($status['status']) ?>
                                        </span>
                                    <?php elseif ($isSuccess): ?>
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200/60 rounded-full text-xs font-medium">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            <?= htmlspecialchars($status['status']) ?>
                                        </span>
                                    <?php elseif ($isNotice): ?>
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-amber-50 text-amber-700 border border-amber-200/60 rounded-full text-xs font-medium">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                            <?= htmlspecialchars($status['status']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-red-50 text-red-700 border border-red-200/60 rounded-full text-xs font-medium">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            <?= htmlspecialchars($status['status']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="py-8 text-center text-slate-400">No execution results available.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-8 flex justify-center gap-4">
            <a href="index.php" class="px-6 py-3 rounded-xl bg-blue-600 text-white text-sm font-semibold hover:bg-blue-500 transition-colors shadow-sm">
                Return to Dashboard
            </a>
            <a href="new_card.php" class="px-6 py-3 rounded-xl bg-slate-100 text-slate-700 text-sm font-semibold hover:bg-slate-200 transition-colors">
                New Card
            </a>
            <a href="copy_card.php" class="px-6 py-3 rounded-xl bg-slate-100 text-slate-700 text-sm font-semibold hover:bg-slate-200 transition-colors">
                Copy Another Card
            </a>
        </div>
    </div>
</div>
</body>
</html>