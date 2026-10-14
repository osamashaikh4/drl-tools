<?php
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode([]);
    exit();
}

include_once __DIR__ . '/conn.php';
include_once __DIR__ . '/user_functions.php';

$bcode = trim($_GET['bcode'] ?? '');

if ($bcode === '' || $bcode === 'MAIN') {
    $srcConn = $main_conn;
} elseif (isset($connections[$bcode])) {
    $srcConn = connect_branch_db($bcode, $connections);
} else {
    $srcConn = false;
}

if (!$srcConn) {
    echo json_encode([]);
    exit();
}

$employees = get_employees_list($srcConn);

$out = [];
foreach ($employees as $emp) {
    $out[] = ['ecode' => $emp['ECODE'], 'ename' => $emp['ENAME']];
}

echo json_encode($out);
