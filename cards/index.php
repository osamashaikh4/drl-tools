<?php
require_once __DIR__ . '/../auth.php';
include __DIR__ . '/header.php';

$actions = [
    [
        'title' => 'New Card',
        'tag'   => 'New Entry',
        'note'  => 'Card + holder + family members',
        'link'  => 'new_card.php',
        'cta'   => 'Create Card',
        'color' => 'emerald',
        'icon'  => 'M12 4v16m8-8H4',
    ],
    [
        'title' => 'Copy Card',
        'tag'   => 'Replication',
        'note'  => 'One branch to many branches',
        'link'  => 'copy_card.php',
        'cta'   => 'Copy Card',
        'color' => 'indigo',
        'icon'  => 'M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z',
    ],
];
?>

<div class="max-w-7xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
    <div class="bg-gradient-to-r from-emerald-600 via-teal-600 to-emerald-700 rounded-3xl p-8 text-white shadow-xl mb-10 relative overflow-hidden">
        <div class="relative z-10 max-w-3xl">
            <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight">DRL Cards</h1>
            <p class="mt-3 text-base sm:text-lg text-emerald-100 font-normal">
                Create discount cards with their holders on branches, or copy an existing card from one branch to many.
            </p>
        </div>
        <div class="absolute right-0 bottom-0 opacity-15 pointer-events-none transform translate-x-10 translate-y-10">
            <img src="../drlogo.png" class="h-80 w-auto" alt="">
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <?php foreach ($actions as $a): $c = $a['color']; ?>
            <div class="group bg-white rounded-3xl p-8 border border-slate-200/80 shadow-sm hover:shadow-xl hover:border-<?= $c ?>-300 transition-all duration-300 flex flex-col justify-between relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-<?= $c ?>-50 rounded-bl-full -mr-8 -mt-8 transition-transform group-hover:scale-110"></div>

                <div class="relative z-10">
                    <div class="w-14 h-14 bg-<?= $c ?>-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-<?= $c ?>-500/30 mb-6 group-hover:scale-105 transition-transform">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $a['icon'] ?>" />
                        </svg>
                    </div>
                    <span class="text-xs font-bold uppercase tracking-wider text-<?= $c ?>-600 bg-<?= $c ?>-50 px-3 py-1 rounded-full"><?= $a['tag'] ?></span>
                    <h3 class="text-2xl font-bold text-slate-900 mt-3 mb-3"><?= $a['title'] ?></h3>
                </div>

                <div class="relative z-10 pt-4 border-t border-slate-100 flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-400"><?= $a['note'] ?></span>
                    <a href="<?= $a['link'] ?>" class="inline-flex items-center gap-2 font-semibold text-sm text-<?= $c ?>-600 group-hover:text-<?= $c ?>-700 group-hover:translate-x-1 transition-all">
                        <?= $a['cta'] ?>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</body>

</html>
