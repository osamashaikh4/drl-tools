<?php
$pageTitle = 'DRL Cards';
include __DIR__ . '/../header.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$cardLinks = [
    'index.php'     => ['Dashboard', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 00-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
    'new_card.php'  => ['New Card', 'M12 4v16m8-8H4'],
    'copy_card.php'  => ['Copy Card', 'M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z'],
    'check_card.php' => ['Check Card', 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'],
];
?>
<style>
    .select2-container--default .select2-selection--multiple,
    .select2-container--default .select2-selection--single {
        border-color: #cbd5e1 !important;
        border-radius: 0.375rem !important;
        min-height: 42px !important;
        padding: 4px 6px !important;
    }

    .select2-container .select2-selection--single .select2-selection__rendered {
        line-height: 30px !important;
    }
</style>
<div class="bg-white border-b border-slate-200 sticky top-0 z-50 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-4 h-14">
            <span class="font-bold text-slate-900 tracking-tight hidden md:inline-block shrink-0">DRL Cards</span>
            <nav class="flex space-x-1 sm:space-x-2 overflow-x-auto">
                <?php foreach ($cardLinks as $file => [$label, $icon]): ?>
                    <a href="<?= $file ?>" class="<?= $currentPage === $file ? 'bg-blue-50 text-blue-600 font-semibold' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50' ?> px-3 py-2 rounded-lg text-sm whitespace-nowrap transition-colors flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $icon ?>" />
                        </svg>
                        <?= $label ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>
</div>
