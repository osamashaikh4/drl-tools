<?php
// Registry of tools shown on the dashboard and in the top nav.
// To add a new tool: create its folder next to this file and add an entry here.
//   folder      - folder name inside drl-tools (its index.php is the entry page)
//   color       - tailwind color name used for the card accent
//   icon        - svg path data (24x24, stroke icons)
return [
    'commands' => [
        'name'        => 'DRL Commands',
        'description' => 'Run INSERT / UPDATE commands on one or many branch databases at once.',
        'folder'      => 'commands',
        'color'       => 'blue',
        'icon'        => 'M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    ],
    'data-deficit' => [
        'name'        => 'Data Deficit',
        'description' => 'Compare branch data with 253 for a period and fill the missing rows.',
        'folder'      => 'data-deficit',
        'color'       => 'orange',
        'icon'        => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4',
    ],
    'users' => [
        'name'        => 'DRL Users',
        'description' => 'Add, copy, transfer employees and manage their database accounts.',
        'folder'      => 'users',
        'color'       => 'indigo',
        'icon'        => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
    ],
];
