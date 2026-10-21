<?php
// The CCTV tool's devices with their logins, as JSON, for worker.py. Command line only.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
require __DIR__ . '/../cctv/isapi.php';

$out = [];
foreach (array_keys($CCTV['devices'] ?? []) as $i) {
    $d = cctv_device($i);
    $out[$d['ip']] = ['user' => $d['user'], 'password' => $d['password'], 'name' => $d['name']];
}
echo json_encode($out);
