<?php
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

// Release the session lock so the parallel branch requests from one browser run concurrently.
session_write_close();

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/command_functions.php';

set_time_limit(600);

$statements = split_sql_statements((string)($_POST['command'] ?? ''));
if (!$statements) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a command.']);
    exit();
}

$kinds = [];
foreach ($statements as $i => $sql) {
    $kinds[$i] = statement_kind($sql);
    if ($kinds[$i] === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Statement ' . ($i + 1) . ' is not allowed. Only SELECT, INSERT and UPDATE can be run.']);
        exit();
    }
}

// Without a branch, just report how the script was split so the page can lay out the results.
$bcode = (string)($_POST['bcode'] ?? '');
if ($bcode === '') {
    echo json_encode(['statements' => array_map(fn($sql, $kind) => ['sql' => $sql, 'kind' => $kind], $statements, $kinds)]);
    exit();
}

if (!isset($connections[$bcode])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown branch ' . $bcode]);
    exit();
}

$bconn = commands_connect_branch($bcode, $connections);
if (!$bconn) {
    $e = oci_error();
    echo json_encode(['bcode' => $bcode, 'connected' => false, 'error' => $e['message'] ?? 'Connection failed']);
    exit();
}

$results = [];
foreach ($statements as $i => $sql) {
    $results[] = $kinds[$i] === 'select' ? run_select($bconn, $sql) : run_dml($bconn, $sql);
}
oci_close($bconn);

echo json_encode(['bcode' => $bcode, 'connected' => true, 'results' => $results], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
