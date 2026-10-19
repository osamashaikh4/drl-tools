<?php
require_once __DIR__ . '/auth.php';

$pageTitle = 'DRL Tools';
include __DIR__ . '/header.php';
?>

<div class="max-w-7xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-700 rounded-3xl p-8 text-white shadow-xl mb-10 relative overflow-hidden">
        <div class="relative z-10 max-w-3xl">
            <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight">DRL Tools</h1>
            <p class="mt-3 text-base sm:text-lg text-blue-100 font-normal">
                All DRL branch database tools in one place.
            </p>
        </div>
        <div class="absolute right-0 bottom-0 opacity-15 pointer-events-none transform translate-x-10 translate-y-10">
            <img src="./drlogo.png" class="h-80 w-auto" alt="">
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">
        <?php foreach ($TOOLS as $slug => $tool): $c = $tool['color']; ?>
            <a href="<?= htmlspecialchars($tool['link'] ?? $tool['folder'] . '/index.php') ?>" class="press group bg-white rounded-3xl p-8 border border-slate-200/80 shadow-sm hover:shadow-xl hover:border-<?= $c ?>-300 transition duration-200 flex flex-col justify-between relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-<?= $c ?>-50 rounded-bl-full -mr-8 -mt-8 transition-transform group-hover:scale-110"></div>

                <div class="relative z-10">
                    <div class="w-14 h-14 bg-<?= $c ?>-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-<?= $c ?>-500/30 mb-6 group-hover:scale-105 transition-transform">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $tool['icon'] ?>" />
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-slate-900 mb-2"><?= htmlspecialchars($tool['name']) ?></h3>
                    <p class="text-slate-600 text-sm leading-relaxed mb-6"><?= htmlspecialchars($tool['description']) ?></p>
                </div>

                <div class="relative z-10 pt-4 border-t border-slate-100 flex items-center justify-end">
                    <span class="inline-flex items-center gap-2 font-semibold text-sm text-<?= $c ?>-600 group-hover:translate-x-1 transition">
                        Open
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
