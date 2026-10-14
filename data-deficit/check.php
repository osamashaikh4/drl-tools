<?php
require_once __DIR__ . '/../auth.php';

// Release the session lock so the parallel branch checks from one browser run concurrently.
session_write_close();

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/deficit_lib.php';

header('Content-Type: application/json');
installJsonErrorHandler();

$bcode = $_POST['bcode'] ?? '';
$pfrom = $_POST['pfrom'] ?? '';
$pto   = $_POST['pto'] ?? '';

if (!isset($connections[$bcode]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pfrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pto)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit();
}

$ip = $connections[$bcode];
$result = [
    'bcode'     => $bcode,
    'connected' => false,
];

try {
    $branchConn = connectToBranch($ip);

    if (!$branchConn) {
        $e = oci_error();
        $result['error'] = $e['message'] ?? 'Connection failed';
        echo json_encode($result);
        exit();
    }

    $result['connected'] = true;

    $branch = [];
    $local  = [];
    $match  = [];

    foreach (COUNT_TABLES as $key => $def) {
        $branch[$key] = fetchCount($branchConn, $def['table'], $def['datecol'], $bcode, $pfrom, $pto);
        $local[$key]  = fetchCount($conn, $def['table'], $def['datecol'], $bcode, $pfrom, $pto);
        $match[$key]  = ($branch[$key] === $local[$key]);
    }

    oci_close($branchConn);

    $result['branch'] = $branch;
    $result['local']  = $local;
    $result['match']  = $match;
} catch (Throwable $ex) {
    $result['error'] = $ex->getMessage();
}

echo json_encode($result);
