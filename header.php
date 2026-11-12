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
    <?php $sidebarShown = is_logged_in(); ?>
    <?php if ($sidebarShown):
        $onDashboard = $currentTool === null && basename($_SERVER['SCRIPT_NAME']) === 'index.php';
        $navItems = ['dashboard' => [
            'name'   => 'Dashboard',
            'href'   => $base . 'index.php',
            'color'  => 'blue',
            'icon'   => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
            'active' => $onDashboard,
        ]];
        foreach ($TOOLS as $slug => $tool) {
            $navItems[$slug] = [
                'name'   => $tool['name'],
                'href'   => $base . ($tool['link'] ?? $tool['folder'] . '/index.php'),
                'color'  => $tool['color'],
                'icon'   => $tool['icon'],
                'active' => isset($tool['link'])
                    ? $currentTool === null && basename($_SERVER['SCRIPT_NAME']) === $tool['link']
                    : $currentTool === $tool['folder'],
            ];
        } ?>
        <style>
            /* Sidebar: full width (16rem) or collapsed to an icon rail (4.5rem) on desktop; a drawer on mobile. */
            .sb {
                width: 16rem;
                transform: translateX(-100%);
                transition: transform 240ms var(--ease-out), width 200ms var(--ease-out);
            }

            html.sb-open .sb {
                transform: none;
            }

            .sb-backdrop {
                opacity: 0;
                pointer-events: none;
                transition: opacity 200ms var(--ease-out);
            }

            html.sb-open .sb-backdrop {
                opacity: 1;
                pointer-events: auto;
            }

            .sb-label {
                transition: opacity 150ms var(--ease-out);
            }

            @media (min-width: 1024px) {
                .sb {
                    transform: none;
                }

                .sb-main {
                    padding-left: 16rem;
                    transition: padding-left 200ms var(--ease-out);
                }

                html.sb-collapsed .sb {
                    width: 4.5rem;
                }

                html.sb-collapsed .sb-main {
                    padding-left: 4.5rem;
                }

                html.sb-collapsed .sb-label {
                    opacity: 0;
                    pointer-events: none;
                }

                /* Collapsed, the toggle sits over the logo and shows when the top row is hovered. */
                html.sb-collapsed .sb-toggle {
                    position: absolute;
                    left: 1rem;
                    top: 0;
                    bottom: 0;
                    margin: auto 0;
                    opacity: 0;
                }

                html.sb-collapsed .sb-top:hover .sb-toggle,
                html.sb-collapsed .sb-toggle:focus-visible {
                    opacity: 1;
                }

                html.sb-collapsed .sb-top:hover .sb-logo,
                html.sb-collapsed .sb-top:has(.sb-toggle:focus-visible) .sb-logo {
                    opacity: 0;
                }

                .sb-backdrop {
                    display: none;
                }
            }

            /* No slide on page load when the saved state is applied. */
            html.sb-no-anim .sb,
            html.sb-no-anim .sb-main,
            html.sb-no-anim .sb-label {
                transition: none;
            }
        </style>
        <script>
            // Apply the saved collapsed state before the sidebar paints.
            (function() {
                const html = document.documentElement;
                html.classList.add('sb-no-anim');
                try {
                    if (localStorage.getItem('drl-sidebar-collapsed') === '1') html.classList.add('sb-collapsed');
                } catch (_) {}
            })();
        </script>

        <!-- Mobile top bar -->
        <div class="lg:hidden relative z-30 flex items-center justify-between h-14 px-4 bg-slate-900 text-white">
            <button type="button" id="sbOpen" class="p-2 -ml-2 rounded-lg text-slate-300 hover:text-white hover:bg-white/10" aria-label="Open menu">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <a href="<?= $base ?>index.php" class="flex items-center gap-2">
                <img class="h-8 w-auto bg-white rounded p-0.5" src="<?= $base ?>drlogo.png" alt="DRL Logo">
                <span class="font-bold tracking-tight">DRL Tools</span>
            </a>
            <span class="w-10"></span>
        </div>

        <div id="sbBackdrop" class="sb-backdrop lg:hidden fixed inset-0 z-40 bg-slate-900/50"></div>

        <aside class="sb fixed inset-y-0 left-0 z-50 flex flex-col bg-slate-900 text-white overflow-hidden">
            <div class="sb-top relative flex items-center h-16 px-4 gap-3 shrink-0 border-b border-white/10">
                <a href="<?= $base ?>index.php" class="flex items-center gap-3 min-w-0">
                    <img class="sb-logo h-9 w-9 shrink-0 object-contain bg-white rounded-lg p-0.5 transition-opacity duration-150" src="<?= $base ?>drlogo.png" alt="DRL Logo">
                    <span class="sb-label font-bold tracking-tight whitespace-nowrap">DRL Tools</span>
                </a>
                <button type="button" id="sbCollapse" title="Collapse sidebar" aria-label="Collapse sidebar" class="sb-toggle hidden lg:flex ml-auto w-9 h-9 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:text-white bg-slate-900 hover:bg-white/10 transition-opacity duration-150">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <rect x="3.5" y="4.5" width="17" height="15" rx="2" stroke-width="2" />
                        <path stroke-linecap="round" stroke-width="2" d="M9.5 4.5v15" />
                    </svg>
                </button>
                <button type="button" id="sbClose" class="lg:hidden ml-auto p-2 -mr-2 rounded-lg text-slate-300 hover:text-white hover:bg-white/10" aria-label="Close menu">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto overflow-x-hidden py-3 px-3 space-y-1" style="scrollbar-width: thin; scrollbar-color: rgb(255 255 255 / 0.15) transparent;">
                <?php foreach ($navItems as $item): $c = $item['color']; ?>
                    <a href="<?= htmlspecialchars($item['href']) ?>" title="<?= htmlspecialchars($item['name']) ?>"
                        class="<?= $item['active'] ? 'bg-white/15 text-white font-semibold' : 'text-slate-300 hover:text-white hover:bg-white/10' ?> flex items-center gap-3 h-10 px-2.5 rounded-lg text-sm whitespace-nowrap transition-colors"
                        <?= $item['active'] ? 'aria-current="page"' : '' ?>>
                        <span class="w-7 h-7 shrink-0 rounded-md flex items-center justify-center <?= $item['active'] ? "bg-{$c}-600 text-white" : "text-{$c}-400" ?>">
                            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $item['icon'] ?>" />
                            </svg>
                        </span>
                        <span class="sb-label truncate"><?= htmlspecialchars($item['name']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="shrink-0 border-t border-white/10 p-3 space-y-1">
                <a href="<?= $base ?>logout.php" title="Logout" class="flex items-center gap-3 h-10 px-2.5 rounded-lg text-sm text-red-300 hover:text-white hover:bg-red-600 whitespace-nowrap transition-colors">
                    <span class="w-7 h-7 shrink-0 flex items-center justify-center">
                        <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                    </span>
                    <span class="sb-label">Logout</span>
                </a>
            </div>
        </aside>

        <script>
            (function() {
                const html = document.documentElement;
                const collapseBtn = document.getElementById('sbCollapse');
                const sync = () => {
                    const collapsed = html.classList.contains('sb-collapsed');
                    collapseBtn.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
                    collapseBtn.setAttribute('aria-label', collapseBtn.title);
                    collapseBtn.setAttribute('aria-expanded', String(!collapsed));
                };
                sync();
                // Turn transitions back on once the saved state has painted.
                requestAnimationFrame(() => requestAnimationFrame(() => html.classList.remove('sb-no-anim')));

                collapseBtn.addEventListener('click', () => {
                    const collapsed = html.classList.toggle('sb-collapsed');
                    try {
                        localStorage.setItem('drl-sidebar-collapsed', collapsed ? '1' : '0');
                    } catch (_) {}
                    sync();
                    // Charts and select2 boxes size to their container, so tell them it changed.
                    setTimeout(() => window.dispatchEvent(new Event('resize')), 220);
                });
                document.getElementById('sbOpen').addEventListener('click', () => html.classList.add('sb-open'));
                document.getElementById('sbClose').addEventListener('click', () => html.classList.remove('sb-open'));
                document.getElementById('sbBackdrop').addEventListener('click', () => html.classList.remove('sb-open'));
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') html.classList.remove('sb-open');
                });
            })();
        </script>

        <div class="sb-main">
    <?php endif; ?>
