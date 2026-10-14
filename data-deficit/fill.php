<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/deficit_lib.php';

header('Content-Type: application/json');
installJsonErrorHandler();

set_time_limit(600);

$bcode  = $_POST['bcode'] ?? '';
$group  = $_POST['group'] ?? '';
$table  = $_POST['table'] ?? '';
$pfrom  = $_POST['pfrom'] ?? '';
$pto    = $_POST['pto'] ?? '';
$dryRun = ($_POST['dryrun'] ?? '') === '1';

$groupTables = $group === 'A' ? GROUP_A_TABLES : ($group === 'B' ? GROUP_B_TABLES : []);

if (
    !isset($connections[$bcode]) ||
    !in_array($table, $groupTables, true) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pfrom) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pto)
) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit();
}

$started = microtime(true);

try {
    $dblink = branchDbLink($bcode);

    $key   = "{$bcode}|{$pfrom}|{$pto}";
    $saved = $_SESSION['deficit_emrs'][$key] ?? null;

    $emrs = [];
    if (FILL_TABLES[$table]['filter']['type'] === 'emr') {
        if ($saved === null) {
            $saved = fetchPatientBioEmrs($conn, $dblink, $bcode, $pfrom, $pto);
            $_SESSION['deficit_emrs'][$key] = $saved;
        }
        $emrs = $saved;
    }

    $result = fillTable($conn, $table, $dblink, $bcode, $pfrom, $pto, $dryRun, $emrs);

    if ($table === 'PATIENT_BIO') {
        $_SESSION['deficit_emrs'][$key] = array_values(array_unique(array_merge($saved ?? [], $result['foundEmrs'])));
    }

    $result['emrs'] = count($emrs);
    unset($result['foundEmrs']);

    if ($dryRun) {
        oci_rollback($conn);
    } else {
        oci_commit($conn);
    }

    $committed = !$dryRun;

    echo jsonResponse([
        'ok'      => true,
        'table'   => $table,
        'dryrun'  => $dryRun,
        'seconds' => round(microtime(true) - $started, 1),
    ] + $result);
} catch (Throwable $ex) {
    if (empty($committed)) {
        @oci_rollback($conn);
    }

    logQuery("-- FAILED {$table} (bcode {$bcode}): " . $ex->getMessage());

    echo jsonResponse([
        'ok'      => false,
        'table'   => $table,
        'seconds' => round(microtime(true) - $started, 1),
        'error'   => $ex->getMessage(),
    ]);
}
