<?php
// Accounts that can sign in to DRL Tools.
//   tools  - '*' for every tool, or a list of tool keys from tools.php this account may open.
//   except - optional, with '*': tool keys this account may not open.
$USERS = [
    'itdrl' => ['pass' => 'itdrl992$!', 'tools' => '*', 'except' => ['vm-replication', 'zk-time', 'net-scan', 'cctv', 'router-tracker', 'activity']],
    'sys'   => ['pass' => 'Test123$',   'tools' => ['vm-replication']],
    'root'  => ['pass' => 'E0iLbnUHfTNPmvDN', 'tools' => ['zk-time', 'net-scan', 'cctv', 'router-tracker', 'activity']],
];
