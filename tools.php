<?php
// Registry of tools shown on the dashboard and in the top nav.
// To add a new tool: create its folder next to this file and add an entry here.
//   folder      - folder name inside drl-tools (its index.php is the entry page)
//   link        - optional entry page used instead of folder/index.php (for root-level pages)
//   color       - tailwind color name used for the card accent
//   icon        - svg path data (24x24, stroke icons)
return [
    'commands' => [
        'name'        => 'Commands',
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
        'name'        => 'Users',
        'description' => 'Add, copy, transfer employees and manage their database accounts.',
        'folder'      => 'users',
        'color'       => 'indigo',
        'icon'        => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
    ],
    'cards' => [
        'name'        => 'Cards',
        'description' => 'Create discount cards with holders, or copy cards from one branch to many.',
        'folder'      => 'cards',
        'color'       => 'emerald',
        'icon'        => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
    ],
    'histo-doctor-counts' => [
        'name'        => 'Histo Doctor Counts',
        'description' => 'Histopathology and cytology report counts per doctor and branch, with an Excel export.',
        'folder'      => 'histo-doctor-counts',
        'color'       => 'fuchsia',
        'icon'        => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    ],
    'vm-replication' => [
        'name'        => 'VM Replication',
        'description' => 'Hyper-V replication health of every VM on the hosts, via Get-VMReplication.',
        'folder'      => 'vm-replication',
        'color'       => 'violet',
        'icon'        => 'M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01',
    ],
    'zk-time' => [
        'name'        => 'ZK Devices',
        'description' => 'ZKTeco attendance machines: clock sync, users, attendance log export and maintenance.',
        'folder'      => 'zk-time',
        'color'       => 'rose',
        'icon'        => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    ],
    'network' => [
        'name'        => 'Network Status',
        'description' => 'Ping, listener and database checks for every branch server with response speed.',
        'folder'      => null,
        'link'        => 'network_status.php',
        'color'       => 'teal',
        'icon'        => 'M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0',
    ],
];
