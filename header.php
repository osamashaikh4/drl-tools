<?php
require_once __DIR__ . '/bootstrap.php';

$base        = tools_base();
$currentTool = current_tool();
$pageTitle   = $pageTitle ?? (isset($TOOLS[$currentTool]) ? $TOOLS[$currentTool]['name'] : 'DRL Tools');
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= $base ?>assets/css/select2.min.css">
    <link rel="stylesheet" type="text/css" href="<?= $base ?>assets/css/iziToast.css" />
    <script src="<?= $base ?>assets/js/tailwind.js"></script>
    <script>
        // hover: utilities only apply on devices with a real hover pointer (no sticky hover on touch).
        tailwind.config = { future: { hoverOnlyWhenSupported: true } };
    </script>
    <style>
        :root {
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
        }

        /* Pressable things scale down slightly so a click feels acknowledged. */
        button:not(:disabled),
        .press {
            transition-property: color, background-color, border-color, box-shadow, opacity, transform;
            transition-duration: 160ms;
            transition-timing-function: var(--ease-out);
        }

        button:not(:disabled):active {
            transform: scale(0.97);
        }

        .press:active {
            transform: scale(0.98);
        }

        /* Progress fills with transform only (no layout); constant progress uses linear. */
        .progress-bar {
            transform-origin: left;
            transition: transform 200ms linear;
        }

        /* A fast spinner makes the wait feel shorter. */
        .spinner {
            display: inline-block;
            width: 0.75rem;
            height: 0.75rem;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 9999px;
            vertical-align: -0.1em;
            animation: spin 600ms linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* A result row fades in when it arrives. */
        .row-in {
            animation: row-in 200ms var(--ease-out);
        }

        @keyframes row-in {
            from {
                opacity: 0.25;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .spinner {
                animation-duration: 1.6s;
            }

            *, ::before, ::after {
                transition-property: color, background-color, border-color, box-shadow, opacity !important;
                scroll-behavior: auto !important;
            }

            button:not(:disabled):active,
            .press:active {
                transform: none;
            }
        }
    </style>
    <script src="<?= $base ?>assets/js/jquery.js"></script>
    <script src="<?= $base ?>assets/js/select2.min.js"></script>
    <script src="<?= $base ?>assets/js/iziToast.js"></script>
</head>

<body class="min-h-full font-sans antialiased text-slate-800 bg-slate-50">
    <?php if (is_logged_in()): ?>
        <header class="bg-slate-900 text-white">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-14 items-center gap-4">
                    <a href="<?= $base ?>index.php" class="flex items-center gap-2 shrink-0">
                        <img class="h-8 w-auto bg-white rounded p-0.5" src="<?= $base ?>drlogo.png" alt="DRL Logo">
                        <span class="font-bold tracking-tight hidden sm:inline-block">DRL Tools</span>
                    </a>

                    <nav class="flex gap-1 overflow-x-auto">
                        <?php foreach ($TOOLS as $slug => $tool): ?>
                            <a href="<?= $base . $tool['folder'] ?>/index.php" class="<?= $currentTool === $tool['folder'] ? 'bg-white/15 text-white font-semibold' : 'text-slate-300 hover:text-white hover:bg-white/10' ?> px-3 py-1.5 rounded-lg text-sm whitespace-nowrap transition-colors">
                                <?= htmlspecialchars($tool['name']) ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <a href="<?= $base ?>logout.php" class="shrink-0 inline-flex items-center rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-500 transition-colors">
                        Logout
                    </a>
                </div>
            </div>
        </header>
    <?php endif; ?>
