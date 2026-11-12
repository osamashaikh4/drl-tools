<?php
// JSON endpoints of Delete Antibiotics.
//   POST action=preview  lab_nos=<text>               -> the PIPARAMVDTL rows of each lab no
//   POST action=delete   labs=<json {lab_no: count}>  -> copies the rows to deleted antibiotics.xlsx, then deletes them
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

function da_reply(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit();
}

if (!is_logged_in()) {
    da_reply(['error' => 'Logged out'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    da_reply(['error' => 'POST only'], 405);
}
session_write_close();
set_time_limit(600);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/lib.php';

$action = (string) ($_POST['action'] ?? '');

if ($action === 'preview') {
    $labNos = da_split_lab_nos((string) ($_POST['lab_nos'] ?? ''));
    if (!$labNos) {
        da_reply(['error' => 'Enter at least one lab no'], 400);
    }
    if (count($labNos) > 500) {
        da_reply(['error' => 'At most 500 lab nos at a time'], 400);
    }

    // One connection per branch, lab nos kept in the order they were typed.
    $results = [];
    $byBranch = [];
    foreach ($labNos as $i => $lab) {
        $bcode = da_branch_of($lab, $connections);
        $results[$i] = ['lab_no' => $lab, 'bcode' => $bcode, 'rows' => [], 'status' => 'invalid',
            'message' => preg_match('/^\d{7,20}$/', $lab) ? 'Branch ' . substr($lab, 4, 2) . ' is not in the branch list' : 'Not a lab no'];
        if ($bcode !== null) {
            $byBranch[$bcode][] = $i;
        }
    }
    foreach ($byBranch as $bcode => $indexes) {
        $bconn = da_connect_branch($bcode, $connections);
        foreach ($indexes as $i) {
            if (!$bconn) {
                $results[$i]['status']  = 'error';
                $results[$i]['message'] = "Could not connect to branch {$bcode} ({$connections[$bcode]})";
                continue;
            }
            try {
                $rows = da_fetch_rows($bconn, $results[$i]['lab_no']);
                $results[$i]['rows']    = $rows;
                $results[$i]['status']  = $rows ? 'found' : 'none';
                $results[$i]['message'] = $rows ? count($rows) . ' row(s)' : 'No rows in PIPARAMVDTL';
            } catch (RuntimeException $e) {
                $results[$i]['status']  = 'error';
                $results[$i]['message'] = $e->getMessage();
            }
        }
        if ($bconn) {
            @oci_rollback($bconn);
            @oci_close($bconn);
        }
    }
    da_reply(['columns' => DA_COLUMNS, 'labs' => array_values($results)]);
}

if ($action === 'delete') {
    $wanted = json_decode((string) ($_POST['labs'] ?? ''), true);
    if (!is_array($wanted) || !$wanted || count($wanted) > 500) {
        da_reply(['error' => 'Nothing to delete, find the rows first'], 400);
    }

    $results  = [];
    $byBranch = [];
    foreach ($wanted as $lab => $count) {
        $lab   = (string) $lab;
        $bcode = da_branch_of($lab, $connections);
        if ($bcode === null || !is_int($count) || $count < 1) {
            $results[$lab] = ['lab_no' => $lab, 'status' => 'error', 'message' => 'Not a lab no that was found'];
            continue;
        }
        $results[$lab] = ['lab_no' => $lab, 'bcode' => $bcode, 'status' => 'pending', 'message' => '', 'count' => $count];
        $byBranch[$bcode][] = $lab;
    }

    // Only one delete at a time touches the workbook.
    $lock = @fopen(DA_LOCK, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        da_reply(['error' => 'Could not lock deleted antibiotics.xlsx'], 500);
    }

    // 1. On every branch: read the rows again and delete them, without committing yet.
    //    A lab no whose rows changed since the preview is left alone.
    $open    = [];   // bcode => connection with uncommitted deletes
    $toSave  = [];   // bcode => rows that will be written to the workbook
    foreach ($byBranch as $bcode => $labs) {
        $bconn = da_connect_branch($bcode, $connections);
        if (!$bconn) {
            foreach ($labs as $lab) {
                $results[$lab]['status']  = 'error';
                $results[$lab]['message'] = "Could not connect to branch {$bcode} ({$connections[$bcode]})";
            }
            continue;
        }
        $deletedHere = [];
        try {
            foreach ($labs as $lab) {
                $rows = da_fetch_rows($bconn, $lab);
                if (count($rows) !== $results[$lab]['count']) {
                    $results[$lab]['status']  = 'error';
                    $results[$lab]['message'] = 'Found ' . count($rows) . ' row(s) now instead of ' . $results[$lab]['count'] . ', find the rows again';
                    continue;
                }
                $n = da_delete_rows($bconn, $lab);
                if ($n !== count($rows)) {
                    throw new RuntimeException("Lab no {$lab}: deleted {$n} row(s) but read " . count($rows));
                }
                $deletedHere[$lab] = $rows;
            }
        } catch (RuntimeException $e) {
            @oci_rollback($bconn);
            @oci_close($bconn);
            foreach ($labs as $lab) {
                if ($results[$lab]['status'] === 'pending') {
                    $results[$lab]['status']  = 'error';
                    $results[$lab]['message'] = 'Branch ' . $bcode . ': ' . $e->getMessage() . ' (nothing deleted on this branch)';
                }
            }
            continue;
        }
        if ($deletedHere) {
            $open[$bcode]   = $bconn;
            $toSave[$bcode] = $deletedHere;
        } else {
            @oci_rollback($bconn);
            @oci_close($bconn);
        }
    }

    // 2. Copy the rows to the workbook. If that fails nothing is deleted anywhere.
    $saveError = null;
    if ($toSave) {
        $allRows = [];
        foreach ($toSave as $labs) {
            foreach ($labs as $rows) {
                array_push($allRows, ...$rows);
            }
        }
        try {
            da_append_to_workbook($allRows);
        } catch (Throwable $e) {
            $saveError = $e->getMessage();
        }
    }

    // 3. Commit (or, when the workbook could not be saved, roll back) each branch.
    foreach ($open as $bcode => $bconn) {
        $ok = $saveError === null && @oci_commit($bconn);
        $commitError = $saveError === null && !$ok ? da_oci_message($bconn) : null;
        if (!$ok) {
            @oci_rollback($bconn);
        }
        foreach ($toSave[$bcode] as $lab => $rows) {
            if ($ok) {
                $results[$lab]['status']  = 'deleted';
                $results[$lab]['message'] = count($rows) . ' row(s) saved to Excel and deleted';
            } elseif ($saveError !== null) {
                $results[$lab]['status']  = 'error';
                $results[$lab]['message'] = $saveError . ' (nothing deleted)';
            } else {
                $results[$lab]['status']  = 'error';
                $results[$lab]['message'] = 'Saved to Excel but the delete could not be committed: ' . $commitError;
            }
        }
        @oci_close($bconn);
    }

    flock($lock, LOCK_UN);
    fclose($lock);

    da_reply(['labs' => array_values($results), 'error' => $saveError]);
}

da_reply(['error' => 'Unknown action'], 400);
