<?php
// REAL HARDWARE test for adding a punch: talks to an actual machine from devices.php.
// Dry run unless --write is given. Use a test user and a time with no other punches.
//   php zk-time/tests/punch_hardware_test.php <device key> <user ID> "<Y-m-d H:i:s>" [punch=0] [verify=1] [--write]
require __DIR__ . '/../zk_functions.php';

$write   = in_array('--write', $argv, true);
$args    = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--write'));
$devices = require __DIR__ . '/../devices.php';
if (count($args) < 3 || !isset($devices[$args[0]])) {
    exit("usage: php punch_hardware_test.php <device key> <user ID> \"<Y-m-d H:i:s>\" [punch=0] [verify=1] [--write]\n");
}
$p = zk_punch_input(['userid' => $args[1], 'time' => $args[2], 'punch' => $args[3] ?? '0', 'verify' => $args[4] ?? '1']);
if (is_string($p)) {
    exit("invalid: $p\n");
}
$ip = $devices[$args[0]]['ip'];
echo ($write ? 'WRITE' : 'DRY RUN') . " on {$args[0]} ({$ip}): " . json_encode($p) . "\n";
$r = zk_add_punch(fn() => zk_open($ip, !empty($devices[$args[0]]['tcp'])), $p, !$write);
zk_log_punch(['by' => 'cli-hardware-test', 'device' => $args[0], 'ip' => $ip, 'dry' => !$write] + $p + ['result' => $r]);
echo json_encode($r, JSON_PRETTY_PRINT), "\n";
exit($r['ok'] ? 0 : 1);
