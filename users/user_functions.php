<?php
date_default_timezone_set("Asia/Karachi");

/**
 * DRY RUN mode: when true, no INSERT/CREATE USER/GRANT is actually executed.
 * Instead the full SQL (with real values inlined) is logged to the results so
 * you can review exactly what WOULD run. Set to false to execute for real.
 */
if (!defined('DRY_RUN')) {
    define('DRY_RUN', false);
}

/**
 * Render a bind-parameter SQL string into a readable preview by inlining the
 * actual values. For display/logging only — NOT used for execution.
 */
function render_sql_preview($sql, $params)
{
    // Replace longer placeholder names first so ":DOB" doesn't clobber ":DOB2" etc.
    uksort($params, function ($a, $b) {
        return strlen($b) - strlen($a);
    });
    foreach ($params as $key => $val) {
        if ($val === null || $val === '') {
            $rep = 'NULL';
        } elseif (is_int($val) || is_float($val)) {
            $rep = (string)$val;
        } else {
            $rep = "'" . str_replace("'", "''", $val) . "'";
        }
        $sql = str_replace($key, $rep, $sql);
    }
    return $sql;
}


function normalize_date_for_oracle($dateStr)
{
    if (empty($dateStr)) return null;
    $ts = strtotime($dateStr);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }
    return $dateStr;
}


function get_branches_list($conn, $connections)
{
    $branches = [];
    if ($conn) {
        $btmt = "SELECT BCODE, BNAME FROM BRANCHES WHERE BCODE NOT IN ('32', '33', '35', '37', '38', '70', '71', '44') ORDER BY BCODE ASC";
        $r = @oci_parse($conn, $btmt);
        if ($r && @oci_execute($r)) {
            while ($row = oci_fetch_array($r, OCI_ASSOC + OCI_RETURN_NULLS)) {
                $branches[$row['BCODE']] = $row['BNAME'];
            }
        }
    }
    if (empty($branches)) {
        foreach (array_keys($connections) as $bcode) {
            $branches[$bcode] = "Branch " . $bcode;
        }
    }
    return $branches;
}


function get_departments_list($conn)
{
    $list = [];
    if ($conn) {
        $sql = "SELECT DCODE, DDESCRIPTION FROM DEPARTMENTS WHERE DCODE IN ('MCB', 'HSP', 'RC', 'CHP', 'HMT', 'BLB', 'PCR') AND DSTATUS = 'Y' ORDER BY DCODE ASC";
        $r = @oci_parse($conn, $sql);
        if ($r && @oci_execute($r)) {
            while ($row = oci_fetch_array($r, OCI_ASSOC)) {
                $list[$row['DCODE']] = $row['DDESCRIPTION'];
            }
        }
    }
    return $list;
}

function get_designations_list($conn)
{
    $list = [];
    if ($conn) {
        $sql = "SELECT EDCODE, EDDESC FROM EDESIG WHERE EDSTATUS = 'Y' ORDER BY EDCODE ASC";
        $r = @oci_parse($conn, $sql);
        if ($r && @oci_execute($r)) {
            while ($row = oci_fetch_array($r, OCI_ASSOC)) {
                $list[$row['EDCODE']] = $row['EDDESC'];
            }
        }
    }
    return $list;
}

function get_estypes_list($conn)
{
    $list = [];
    if ($conn) {
        $sql = "SELECT ESTCODE, ESTDESC FROM ESTYPE WHERE ESTSTATUS = 'Y' ORDER BY ESTCODE ASC";
        $r = @oci_parse($conn, $sql);
        if ($r && @oci_execute($r)) {
            while ($row = oci_fetch_array($r, OCI_ASSOC)) {
                $list[$row['ESTCODE']] = $row['ESTDESC'];
            }
        }
    }
    return $list;
}

function get_employees_list($conn)
{
    $list = [];
    if ($conn) {
        $sql = "SELECT ECODE, ENAME, EUNAME, DCODE FROM EMP WHERE ESTATUS = 'Y' ORDER BY ECODE ASC";
        $r = @oci_parse($conn, $sql);
        if ($r && @oci_execute($r)) {
            while ($row = oci_fetch_array($r, OCI_ASSOC)) {
                $list[] = $row;
            }
        }
    }
    return $list;
}


function connect_branch_db($bcode, $connections, $user = 'drl', $pass = 'drl')
{
    if (!isset($connections[$bcode])) {
        return false;
    }
    $sid = "drl";
    if ($bcode == '13' || $bcode == '24' || $bcode == '27') {
        $sid = "dbnc";
    }
    $bconn = @oci_connect($user, $pass, $connections[$bcode] . '/' . $sid);
    return $bconn ? $bconn : false;
}

/**
 * Check whether an employee (by ECODE) already exists in the EMP table on a
 * branch connection. Returns false when the check can't run (e.g. no live
 * connection during a DRY RUN preview) so the caller proceeds normally.
 */
function user_exists_in_branch($bconn, $ecode)
{
    if (!$bconn || $ecode === null || $ecode === '') {
        return false;
    }
    $sql = "SELECT COUNT(*) AS CNT FROM EMP WHERE ECODE = :ecode";
    $stmt = @oci_parse($bconn, $sql);
    if (!$stmt) {
        return false;
    }
    @oci_bind_by_name($stmt, ':ecode', $ecode);
    if (!@oci_execute($stmt)) {
        return false;
    }
    $row = oci_fetch_array($stmt, OCI_ASSOC);
    return $row && (int)$row['CNT'] > 0;
}

/**
 * Check whether a specific UPRIVS (UPRIV) row already exists for this
 * branch/employee. Identity = BCODE + ECODE + PCCODE + UPVALUE (UPVALUE may be
 * NULL). Returns false when the check can't run (no live connection).
 */
function upriv_row_exists($bconn, $bcode, $ecode, $pccode, $upvalue)
{
    if (!$bconn) {
        return false;
    }
    if ($upvalue === null || $upvalue === '') {
        $sql = "SELECT COUNT(*) AS CNT FROM UPRIVS WHERE BCODE = :bcode AND ECODE = :ecode AND PCCODE = :pccode AND UPVALUE IS NULL";
    } else {
        $sql = "SELECT COUNT(*) AS CNT FROM UPRIVS WHERE BCODE = :bcode AND ECODE = :ecode AND PCCODE = :pccode AND UPVALUE = :upvalue";
    }
    $stmt = @oci_parse($bconn, $sql);
    if (!$stmt) {
        return false;
    }
    @oci_bind_by_name($stmt, ':bcode', $bcode);
    @oci_bind_by_name($stmt, ':ecode', $ecode);
    @oci_bind_by_name($stmt, ':pccode', $pccode);
    if ($upvalue !== null && $upvalue !== '') {
        @oci_bind_by_name($stmt, ':upvalue', $upvalue);
    }
    if (!@oci_execute($stmt)) {
        return false;
    }
    $row = oci_fetch_array($stmt, OCI_ASSOC);
    return $row && (int)$row['CNT'] > 0;
}

/**
 * Check whether a specific UROLES row already exists for this branch/employee.
 * Identity = BCODE + ECODE + RMCODE. Returns false when the check can't run.
 */
function uroles_row_exists($bconn, $bcode, $ecode, $rmcode)
{
    if (!$bconn) {
        return false;
    }
    $sql = "SELECT COUNT(*) AS CNT FROM UROLES WHERE BCODE = :bcode AND ECODE = :ecode AND RMCODE = :rmcode";
    $stmt = @oci_parse($bconn, $sql);
    if (!$stmt) {
        return false;
    }
    @oci_bind_by_name($stmt, ':bcode', $bcode);
    @oci_bind_by_name($stmt, ':ecode', $ecode);
    @oci_bind_by_name($stmt, ':rmcode', $rmcode);
    if (!@oci_execute($stmt)) {
        return false;
    }
    $row = oci_fetch_array($stmt, OCI_ASSOC);
    return $row && (int)$row['CNT'] > 0;
}


function fstartup_row_exists($bconn, $ecode)
{
    if (!$bconn) {
        return false;
    }
    $sql = "SELECT COUNT(*) AS CNT FROM FSTARTUP WHERE ECODE = :ecode";
    $stmt = @oci_parse($bconn, $sql);
    if (!$stmt) {
        return false;
    }

    @oci_bind_by_name($stmt, ':ecode', $ecode);

    if (!@oci_execute($stmt)) {
        return false;
    }

    $row = oci_fetch_array($stmt, OCI_ASSOC);
    return $row && (int)$row['CNT'] > 0;
}

function branch_privs_row_exists($bconn, $bcode, $ecode)
{
    if (!$bconn) {
        return false;
    }
    $sql = "SELECT COUNT(*) AS CNT FROM BRANCH_PRIVS WHERE BCODE = :bcode AND ECODE = :ecode";
    $stmt = @oci_parse($bconn, $sql);
    if (!$stmt) {
        return false;
    }
    @oci_bind_by_name($stmt, ':bcode', $bcode);
    @oci_bind_by_name($stmt, ':ecode', $ecode);
    if (!@oci_execute($stmt)) {
        return false;
    }
    $row = oci_fetch_array($stmt, OCI_ASSOC);
    return $row && (int)$row['CNT'] > 0;
}

function get_lab_upriv_templates()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = require __DIR__ . '/uprivs_data.php';
    return $cache;
}

function resolve_provisioning_for_dcode($dcode)
{
    $dcode = strtoupper(trim((string)$dcode));

    if ($dcode === '') {
        return null;
    }

    // Reception department -> Reception user
    if ($dcode === 'RC') {
        return ['user_type' => 'RECEPTION', 'lab_template' => null];
    }

    $deptMap = [
        'CHP' => 'Chemistry',
        'MCB' => 'Microbiology',
        'HMT' => 'Hemotology',
        'HSP' => 'Histopathology',
        'BLB' => 'BloodBank',
        'PCR' => 'PCR',
    ];

    if (!isset($deptMap[$dcode])) {
        return null;
    }

    $templateName = $deptMap[$dcode];
    $labTemplates = get_lab_upriv_templates();

    if (!isset($labTemplates[$templateName])) {
        return null;
    }

    return ['user_type' => 'LAB', 'lab_template' => $templateName];
}

/**
 * Fixed UPRIV template rows for Reception users (PCCODE, UPVALUE, UPSTATUS)
 * P_RW (modification right) is only added for Incharge users
 */
function get_reception_upriv_template($isIncharge)
{
    $rows = [
        ['PCCODE' => 'PRP',  'UPVALUE' => null, 'UPSTATUS' => 'Y'],
        ['PCCODE' => 'RP',   'UPVALUE' => null, 'UPSTATUS' => 'Y'],
        ['PCCODE' => 'PP',   'UPVALUE' => null, 'UPSTATUS' => 'Y'],
        ['PCCODE' => 'OBP',  'UPVALUE' => null, 'UPSTATUS' => 'Y'],
        ['PCCODE' => 'RPRP', 'UPVALUE' => null, 'UPSTATUS' => 'Y'],
        ['PCCODE' => 'TRCP', 'UPVALUE' => null, 'UPSTATUS' => 'Y'],
    ];

    if ($isIncharge) {
        $rows[] = ['PCCODE' => 'P_RW', 'UPVALUE' => null, 'UPSTATUS' => 'Y'];
    }
    return $rows;
}

/**
 * Department-specific UROLES role sets (base + incharge-only additions),
 * hardcoded from uroles_template:
 *   HSP DEPARTMENT       = RCP; incharge also gets HSPI and RCPI
 *   PCR DEPARTMENT       = MOPS
 *   CHEM DEPARTMENT      = LAB; incharge also gets LABI
 *   MICRO DEPARTMENT     = MCBS and LAB; incharge also gets MCBI
 *   BLOOD BANK DEPARTMENT = BLB; incharge also gets BLBI
 *   RECEPTION            = RCP; incharge also gets RCPI and RCPMP
 * Hemotology isn't listed in uroles_template — treated the same as
 * Chemistry (LAB / LABI) per user direction.
 */
function get_department_uroles_map()
{
    return [
        'Chemistry'      => ['base' => ['LAB'],         'incharge' => ['LABI']],
        'Microbiology'   => ['base' => ['MCBS', 'LAB'], 'incharge' => ['MCBI']],
        'Hemotology'     => ['base' => ['LAB'],         'incharge' => ['LABI']],
        'Histopathology' => ['base' => ['RCP'],         'incharge' => ['HSPI', 'RCPI']],
        'BloodBank'      => ['base' => ['BLB'],         'incharge' => ['BLBI']],
        'PCR'            => ['base' => ['MOPS'],        'incharge' => ['MOPS']],
        'RECEPTION'      => ['base' => ['RCP'],         'incharge' => ['RCPI', 'RCPMP']],
    ];
}

/**
 * UROLES template rows (RMCODE, URSTATUS) for the given user type and
 * department. $deptKey should match the lab_template keys used by
 * get_lab_upriv_templates() (e.g. 'Chemistry', 'Microbiology') for LAB
 * users; ignored for RECEPTION users. Falls back to the generic LAB/LABI
 * role set when the department isn't recognized.
 */
function get_uroles_template($userType, $isIncharge, $deptKey = null)
{
    $map = get_department_uroles_map();

    if ($userType === 'RECEPTION') {
        $entry = $map['RECEPTION'];
    } elseif ($deptKey !== null && isset($map[$deptKey])) {
        $entry = $map[$deptKey];
    } else {
        $entry = ['base' => ['LAB'], 'incharge' => ['LABI']];
    }

    $roles = $entry['base'];
    if ($isIncharge) {
        $roles = array_merge($roles, $entry['incharge']);
    }

    $rows = [];
    foreach ($roles as $rmcode) {
        $rows[] = ['RMCODE' => $rmcode, 'URSTATUS' => 'Y'];
    }
    return $rows;
}

/**
 * Insert UPRIV rows into UPRIVS for the new employee on the target branch
 */
function insert_upriv_rows($bconn, $bcode, $ecode, $rows)
{
    $results = [];
    foreach ($rows as $row) {
        $sql = "INSERT INTO UPRIVS (PCCODE, BCODE, ECODE, UPVALUE, UPSTATUS) VALUES (:pccode, :bcode, :ecode, :upvalue, :upstatus)";
        $pccode  = $row['PCCODE'];
        $upvalue = $row['UPVALUE'];
        $upstatus = $row['UPSTATUS'];
        $binds = [
            ':pccode'   => $pccode,
            ':bcode'    => $bcode,
            ':ecode'    => $ecode,
            ':upvalue'  => $upvalue,
            ':upstatus' => $upstatus,
        ];

        if (upriv_row_exists($bconn, $bcode, $ecode, $pccode, $upvalue)) {
            $results[] = [
                'bcode'  => $bcode,
                'query'  => "UPRIVS (PCCODE: {$pccode}" . ($upvalue ? ", UPVALUE: {$upvalue}" : "") . ")",
                'status' => "UPRIVS row already exists — skipped"
            ];
            continue;
        }

        if (DRY_RUN) {
            $results[] = [
                'bcode'  => $bcode,
                'query'  => render_sql_preview($sql, $binds),
                'status' => "DRY RUN — not executed"
            ];
            continue;
        }

        $stmt = @oci_parse($bconn, $sql);
        if ($stmt) {
            oci_bind_by_name($stmt, ':pccode', $pccode);
            oci_bind_by_name($stmt, ':bcode', $bcode);
            oci_bind_by_name($stmt, ':ecode', $ecode);
            oci_bind_by_name($stmt, ':upvalue', $upvalue, -1);
            oci_bind_by_name($stmt, ':upstatus', $upstatus);

            if (@oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {
                $results[] = [
                    'bcode'  => $bcode,
                    'query'  => "INSERT INTO UPRIVS (PCCODE: {$pccode}" . ($upvalue ? ", UPVALUE: {$upvalue}" : "") . ")",
                    'status' => "UPRIVS row inserted successfully"
                ];
            } else {
                $err = oci_error($stmt);
                $results[] = [
                    'bcode'  => $bcode,
                    'query'  => "INSERT INTO UPRIVS (PCCODE: {$pccode})",
                    'status' => "UPRIVS insertion failed: " . ($err['message'] ?? 'Unknown error')
                ];
            }
        }
    }
    return $results;
}

/**
 * Insert UROLES rows for the new employee on the target branch
 */
function insert_uroles_rows($bconn, $bcode, $ecode, $rows)
{
    $results = [];
    foreach ($rows as $row) {
        $sql = "INSERT INTO UROLES (BCODE, ECODE, RMCODE, URSTATUS) VALUES (:bcode, :ecode, :rmcode, :urstatus)";
        $rmcode = $row['RMCODE'];
        $urstatus = $row['URSTATUS'];
        $binds = [
            ':bcode'    => $bcode,
            ':ecode'    => $ecode,
            ':rmcode'   => $rmcode,
            ':urstatus' => $urstatus,
        ];

        if (uroles_row_exists($bconn, $bcode, $ecode, $rmcode)) {
            $results[] = [
                'bcode'  => $bcode,
                'query'  => "UROLES (RMCODE: {$rmcode})",
                'status' => "UROLES row already exists — skipped"
            ];
            continue;
        }

        if (DRY_RUN) {
            $results[] = [
                'bcode'  => $bcode,
                'query'  => render_sql_preview($sql, $binds),
                'status' => "DRY RUN — not executed"
            ];
            continue;
        }

        $stmt = @oci_parse($bconn, $sql);
        if ($stmt) {
            oci_bind_by_name($stmt, ':bcode', $bcode);
            oci_bind_by_name($stmt, ':ecode', $ecode);
            oci_bind_by_name($stmt, ':rmcode', $rmcode);
            oci_bind_by_name($stmt, ':urstatus', $urstatus);

            if (@oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {
                $results[] = [
                    'bcode'  => $bcode,
                    'query'  => "INSERT INTO UROLES (RMCODE: {$rmcode})",
                    'status' => "UROLES row inserted successfully"
                ];
            } else {
                $err = oci_error($stmt);
                $results[] = [
                    'bcode'  => $bcode,
                    'query'  => "INSERT INTO UROLES (RMCODE: {$rmcode})",
                    'status' => "UROLES insertion failed: " . ($err['message'] ?? 'Unknown error')
                ];
            }
        }
    }
    return $results;
}

function insert_fstartup_row($bconn, $bcode, $ecode, $dcode, $userType)
{
    $results = [];

    $sblock    = ($userType === 'RECEPTION') ? 'LABRECEPTIONPNL' : 'LABTESTSPNL';
    $sstatus   = 'Y';
    $shiftcode = 'A';
    $fseffFrom = strtoupper(date('d-M-y'));

    $sql = "INSERT INTO FSTARTUP (ECODE, DCODE, SBLOCK, SSTATUS, BCODE, SHIFTCODE, FSEFF_FROM) "
        . "VALUES (:ecode, :dcode, :sblock, :sstatus, :bcode, :shiftcode, :fseff_from)";
    $binds = [
        ':ecode'      => $ecode,
        ':dcode'      => $dcode,
        ':sblock'     => $sblock,
        ':sstatus'    => $sstatus,
        ':bcode'      => $bcode,
        ':shiftcode'  => $shiftcode,
        ':fseff_from' => $fseffFrom,
    ];

    if (fstartup_row_exists($bconn, $ecode)) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => "FSTARTUP (SBLOCK: {$sblock})",
            'status' => "FSTARTUP row already exists — skipped"
        ];
        return $results;
    }

    if (DRY_RUN) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => render_sql_preview($sql, $binds),
            'status' => "DRY RUN — not executed"
        ];
        return $results;
    }

    $stmt = @oci_parse($bconn, $sql);
    if ($stmt) {
        oci_bind_by_name($stmt, ':ecode', $ecode);
        oci_bind_by_name($stmt, ':dcode', $dcode);
        oci_bind_by_name($stmt, ':sblock', $sblock);
        oci_bind_by_name($stmt, ':sstatus', $sstatus);
        oci_bind_by_name($stmt, ':bcode', $bcode);
        oci_bind_by_name($stmt, ':shiftcode', $shiftcode);
        oci_bind_by_name($stmt, ':fseff_from', $fseffFrom);

        if (@oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {
            $results[] = [
                'bcode'  => $bcode,
                'query'  => "INSERT INTO FSTARTUP (SBLOCK: {$sblock})",
                'status' => "FSTARTUP row inserted successfully"
            ];
        } else {
            $err = oci_error($stmt);
            $results[] = [
                'bcode'  => $bcode,
                'query'  => "INSERT INTO FSTARTUP (SBLOCK: {$sblock})",
                'status' => "FSTARTUP insertion failed: " . ($err['message'] ?? 'Unknown error')
            ];
        }
    }

    return $results;
}

/**
 * Insert the BRANCH_PRIVS row granting the employee access to the target
 * branch. Columns: BCODE (target branch), ECODE, BPSTATUS ('Y').
 */
function insert_branch_privs_row($bconn, $bcode, $ecode)
{
    $results = [];

    $bpstatus = 'Y';
    $sql = "INSERT INTO BRANCH_PRIVS (BCODE, ECODE, BPSTATUS) VALUES (:bcode, :ecode, :bpstatus)";
    $binds = [
        ':bcode'    => $bcode,
        ':ecode'    => $ecode,
        ':bpstatus' => $bpstatus,
    ];

    if (branch_privs_row_exists($bconn, $bcode, $ecode)) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => "BRANCH_PRIVS (ECODE: {$ecode})",
            'status' => "BRANCH_PRIVS row already exists — skipped"
        ];
        return $results;
    }

    if (DRY_RUN) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => render_sql_preview($sql, $binds),
            'status' => "DRY RUN — not executed"
        ];
        return $results;
    }

    $stmt = @oci_parse($bconn, $sql);
    if ($stmt) {
        oci_bind_by_name($stmt, ':bcode', $bcode);
        oci_bind_by_name($stmt, ':ecode', $ecode);
        oci_bind_by_name($stmt, ':bpstatus', $bpstatus);

        if (@oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {
            $results[] = [
                'bcode'  => $bcode,
                'query'  => "INSERT INTO BRANCH_PRIVS (ECODE: {$ecode})",
                'status' => "BRANCH_PRIVS row inserted successfully"
            ];
        } else {
            $err = oci_error($stmt);
            $results[] = [
                'bcode'  => $bcode,
                'query'  => "INSERT INTO BRANCH_PRIVS (ECODE: {$ecode})",
                'status' => "BRANCH_PRIVS insertion failed: " . ($err['message'] ?? 'Unknown error')
            ];
        }
    }

    return $results;
}


/**
 * Reset an Oracle DB account's password via ALTER USER ... IDENTIFIED BY.
 * Must run on a privileged (system) connection — the drl schema account
 * does not have ALTER USER privileges (same ORA-01031 issue as CREATE USER).
 */
function reset_user_password($adminConn, $bcode, $ecode, $newPassword)
{
    $results = [];

    if (empty($ecode) || empty($newPassword)) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => "ALTER USER ... IDENTIFIED BY",
            'status' => "ECODE and new password are required"
        ];
        return $results;
    }

    // Double-quote the password so special characters (e.g. *, #, $) don't
    // break the statement — an unquoted password with such chars raises ORA-00933.
    $sql = "ALTER USER " . $ecode . ' IDENTIFIED BY "' . str_replace('"', '', $newPassword) . '"';

    if (DRY_RUN) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => $sql,
            'status' => "DRY RUN — not executed"
        ];
        return $results;
    }

    if (!$adminConn) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => $sql,
            'status' => "Privileged (system) connection unavailable — password not reset"
        ];
        return $results;
    }

    $stmt = @oci_parse($adminConn, $sql);
    if ($stmt && @oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => $sql,
            'status' => "Password reset successfully for '{$ecode}'"
        ];
    } else {
        $err = oci_error($stmt ? $stmt : $adminConn);
        $results[] = [
            'bcode'  => $bcode,
            'query'  => $sql,
            'status' => "Password reset failed: " . ($err['message'] ?? 'Unknown error')
        ];
    }

    return $results;
}

/**
 * Unlock a locked Oracle DB account via ALTER USER ... ACCOUNT UNLOCK.
 * Must run on a privileged (system) connection.
 */
function unlock_user_account($adminConn, $bcode, $ecode)
{
    $results = [];

    if (empty($ecode)) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => "ALTER USER ... ACCOUNT UNLOCK",
            'status' => "ECODE is required"
        ];
        return $results;
    }

    $sql = "ALTER USER " . $ecode . " ACCOUNT UNLOCK";

    if (DRY_RUN) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => $sql,
            'status' => "DRY RUN — not executed"
        ];
        return $results;
    }

    if (!$adminConn) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => $sql,
            'status' => "Privileged (system) connection unavailable — account not unlocked"
        ];
        return $results;
    }

    $stmt = @oci_parse($adminConn, $sql);
    if ($stmt && @oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => $sql,
            'status' => "Account unlocked successfully for '{$ecode}'"
        ];
    } else {
        $err = oci_error($stmt ? $stmt : $adminConn);
        $results[] = [
            'bcode'  => $bcode,
            'query'  => $sql,
            'status' => "Account unlock failed: " . ($err['message'] ?? 'Unknown error')
        ];
    }

    return $results;
}

/**
 * Set EMP.ESTATUS for an employee on a given connection. Used after a
 * successful Transfer to deactivate the employee's record on the source
 * branch once they've been copied to the target branch.
 */
function set_emp_estatus($conn, $bcode, $ecode, $status)
{
    $results = [];

    $sql = "UPDATE EMP SET ESTATUS = :estatus WHERE ECODE = :ecode";
    $binds = [':estatus' => $status, ':ecode' => $ecode];

    if (DRY_RUN) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => render_sql_preview($sql, $binds),
            'status' => "DRY RUN — not executed"
        ];
        return $results;
    }

    if (!$conn) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => $sql,
            'status' => "Source branch connection unavailable — ESTATUS not updated"
        ];
        return $results;
    }

    $stmt = @oci_parse($conn, $sql);
    if ($stmt) {
        oci_bind_by_name($stmt, ':estatus', $status);
        oci_bind_by_name($stmt, ':ecode', $ecode);

        if (@oci_execute($stmt, OCI_COMMIT_ON_SUCCESS)) {
            $results[] = [
                'bcode'  => $bcode,
                'query'  => "UPDATE EMP SET ESTATUS = '{$status}' WHERE ECODE = '{$ecode}'",
                'status' => "EMP ESTATUS updated to '{$status}' on source branch"
            ];
        } else {
            $err = oci_error($stmt);
            $results[] = [
                'bcode'  => $bcode,
                'query'  => "UPDATE EMP SET ESTATUS = '{$status}' WHERE ECODE = '{$ecode}'",
                'status' => "EMP ESTATUS update failed: " . ($err['message'] ?? 'Unknown error')
            ];
        }
    }

    return $results;
}

function get_grants_for_user_type($userType, $isIncharge)
{
    if ($userType === 'LAB') {
        return "CONNECT, DDICT, LABTEST_R, LABTEST_W, REPORTING";
    }

    $grants = "CONNECT, DDICT, LABTEST_R, LABTEST_W, REPORTING, RECEPTION";

    if ($isIncharge) {
        $grants .= ", PATIENT_RW";
    }

    return $grants;
}

/**
 * Add a new employee into target branch database
 */
function add_new_user_to_branch($bconn, $bcode, $data, $adminConn = null)
{
    $results = [];

    // 1. Prepare fields for EMP table
    $fields = [
        'ECODE'        => $data['ECODE'],
        'ENAME'        => $data['ENAME'] ?? null,
        'EFNAME'       => $data['EFNAME'] ?? null,
        'TITLE'        => $data['TITLE'] ?? null,
        'SURNAME'      => $data['SURNAME'] ?? null,
        'GENDER'       => $data['GENDER'] ?? null,
        'GRADE'        => $data['GRADE'] ?? null,
        'CNIC'         => $data['CNIC'] ?? null,
        'EDCODE'       => $data['EDCODE'],
        'ESTCODE'      => $data['ESTCODE'],
        'DCODE'        => $data['DCODE'],
        'ESTATUS'      => $data['ESTATUS'] ?? 'A',
        'EREMARKS'     => $data['EREMARKS'] ?? null,
        'EUNAME'       => $data['EUNAME'] ?? null,
        'EDISPLAYNAME' => $data['EDISPLAYNAME'] ?? null,
    ];

    $columns = [];
    $values  = [];
    $params  = [];

    foreach ($fields as $col => $val) {
        if ($val !== null && $val !== '') {
            $columns[] = $col;
            $values[]  = ":" . $col;
            $params[":" . $col] = $val;
        }
    }

    if (!empty($data['DOB'])) {
        $tsDob = strtotime($data['DOB']);
        if ($tsDob !== false && $tsDob > 0) {
            $columns[] = 'DOB';
            $values[]  = ':DOB';
            $params[':DOB'] = strtoupper(date('d-M-y', $tsDob));
        }
    }

    if (!empty($data['DOJ'])) {
        $tsDoj = strtotime($data['DOJ']);
        if ($tsDoj !== false && $tsDoj > 0) {
            $columns[] = 'DOJ';
            $values[]  = ':DOJ';
            $params[':DOJ'] = strtoupper(date('d-M-y', $tsDoj));
        }
    }

    $sql = "INSERT INTO EMP (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ")";

    $empAlreadyExists = user_exists_in_branch($bconn, $data['ECODE']);

    if ($empAlreadyExists) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => "SELECT EMP WHERE ECODE = '{$data['ECODE']}'",
            'status' => "EMP record already exists — skipped insert (rights still applied)"
        ];
    } elseif (DRY_RUN) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => render_sql_preview($sql, $params),
            'status' => "DRY RUN — not executed"
        ];
    } else {
        $stmt = @oci_parse($bconn, $sql);
        if ($stmt) {
            foreach ($params as $paramKey => &$paramVal) {
                oci_bind_by_name($stmt, $paramKey, $paramVal);
            }
            unset($paramVal);

            $execResult = @oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
            if ($execResult) {
                $results[] = [
                    'bcode'  => $bcode,
                    'query'  => "INSERT INTO EMP (ECODE: {$data['ECODE']})",
                    'status' => "EMP record inserted successfully"
                ];
            } else {
                $err = oci_error($stmt);
                $results[] = [
                    'bcode'  => $bcode,
                    'query'  => "INSERT INTO EMP (ECODE: {$data['ECODE']})",
                    'status' => "EMP Insertion failed: " . ($err['message'] ?? 'Unknown error')
                ];
            }
        } else {
            $err = oci_error($bconn);
            $results[] = [
                'bcode'  => $bcode,
                'query'  => "INSERT INTO EMP",
                'status' => "Failed to parse EMP query: " . ($err['message'] ?? 'Unknown error')
            ];
        }
    }

    $userType   = !empty($data['USER_TYPE']) && $data['USER_TYPE'] === 'RECEPTION' ? 'RECEPTION' : 'LAB';
    $isIncharge = !empty($data['IS_INCHARGE']);

    // 2. Insert FSTARTUP startup-panel row (panel depends on user type)
    $results = array_merge($results, insert_fstartup_row($bconn, $bcode, $data['ECODE'], $data['DCODE'], $userType));

    // 3. Insert BRANCH_PRIVS row (branch access grant)
    $results = array_merge($results, insert_branch_privs_row($bconn, $bcode, $data['ECODE']));

    // 4. Insert UPRIV (UPRIVS) and UROLES rows for the selected user type
    if ($userType === 'LAB') {
        // Lab UPRIVS are department-specific; template chosen on the form (LAB_TEMPLATE)
        $labTemplates = get_lab_upriv_templates();
        $uprivRows = $labTemplates[$data['LAB_TEMPLATE'] ?? ''] ?? [];
    } else {
        $uprivRows = get_reception_upriv_template($isIncharge);
    }

    $results = array_merge($results, insert_upriv_rows($bconn, $bcode, $data['ECODE'], $uprivRows));

    $urolesRows = get_uroles_template($userType, $isIncharge, $data['LAB_TEMPLATE'] ?? null);
    $results = array_merge($results, insert_uroles_rows($bconn, $bcode, $data['ECODE'], $urolesRows));

    $username = $data['ECODE'];

    if (!empty($data['create_db_user']) && !empty($username)) {
        $pwd = !empty($data['user_password']) ? $data['user_password'] : $username . '123';
        $grants = get_grants_for_user_type($userType, $isIncharge);
        // Double-quote the password so special characters (e.g. *, #, $) don't
        // break the statement — an unquoted password with such chars raises ORA-00933.
        $grantSql = "GRANT " . $grants . " TO " . $username . ' IDENTIFIED BY "' . str_replace('"', '', $pwd) . '"';

        // CREATE USER + role grants must run as a privileged account (system),
        // not the drl schema account. Fall back to $bconn if no admin conn given.
        $grantConn = $adminConn ?: $bconn;

        if (DRY_RUN) {
            $results[] = [
                'bcode'  => $bcode,
                'query'  => $grantSql,
                'status' => "DRY RUN — not executed"
            ];
        } else {
            $gStmt = @oci_parse($grantConn, $grantSql);
            if ($gStmt && @oci_execute($gStmt, OCI_COMMIT_ON_SUCCESS)) {
                $results[] = [
                    'bcode'  => $bcode,
                    'query'  => $grantSql,
                    'status' => "Database User '{$username}' created & granted {$userType} permissions"
                ];
            } else {
                $err = oci_error($gStmt ? $gStmt : $grantConn);
                $results[] = [
                    'bcode'  => $bcode,
                    'query'  => $grantSql,
                    'status' => "Database User grant notice: " . ($err['message'] ?? 'User may already exist or insufficient privileges')
                ];
            }
        }
    }

    return $results;
}


function get_table_columns($conn, $tableName)
{
    $columns = [];
    $sql = "SELECT column_name FROM user_tab_columns WHERE table_name = UPPER(:tname)";
    $stmt = @oci_parse($conn, $sql);
    if ($stmt) {
        @oci_bind_by_name($stmt, ":tname", $tableName);
        if (@oci_execute($stmt)) {
            while ($row = oci_fetch_array($stmt, OCI_ASSOC)) {
                $columns[] = strtoupper($row['COLUMN_NAME']);
            }
        }
    }
    return $columns;
}

function copy_user_to_branch($mainConn, $bconn, $bcode, $ecode, $dbPassword = '', $userType = 'LAB', $isIncharge = false, $adminConn = null)
{
    $results = [];

    if (!$mainConn) {
        $results[] = ['bcode' => $bcode, 'query' => 'Connect', 'status' => 'Main database connection unavailable'];
        return $results;
    }

    // Fetch employee details from EMP table on main database
    $empSql = "SELECT * FROM EMP WHERE ECODE = :ecode";
    $empStmt = @oci_parse($mainConn, $empSql);
    @oci_bind_by_name($empStmt, ":ecode", $ecode);
    if (!@oci_execute($empStmt)) {
        $err = oci_error($empStmt);
        $results[] = ['bcode' => $bcode, 'query' => "SELECT EMP for {$ecode}", 'status' => 'Failed to fetch user from main DB: ' . ($err['message'] ?? '')];
        return $results;
    }

    $empRow = oci_fetch_array($empStmt, OCI_ASSOC + OCI_RETURN_NULLS);
    if (!$empRow) {
        $results[] = ['bcode' => $bcode, 'query' => "SELECT EMP for {$ecode}", 'status' => "Employee with ECODE '{$ecode}' not found on main branch"];
        return $results;
    }

    // Derive user type from the source employee's department (DCODE): RC =>
    // Reception, other known departments => Lab. This overrides any passed
    // $userType so the grant set always matches the employee's department.
    // Unrecognized departments fall back to Lab.
    $provisioning = resolve_provisioning_for_dcode($empRow['DCODE'] ?? '');
    $userType = $provisioning === null ? 'LAB' : $provisioning['user_type'];

    // Helper closure to replicate table rows from mainConn to bconn
    $copyTableRows = function ($tableName, $whereSql, $whereBinds) use ($mainConn, $bconn, $bcode) {
        $subResults = [];
        $selectSql = "SELECT * FROM {$tableName} WHERE {$whereSql}";
        $sStmt = @oci_parse($mainConn, $selectSql);
        if (!$sStmt) return $subResults;

        foreach ($whereBinds as $pKey => $pVal) {
            @oci_bind_by_name($sStmt, $pKey, $whereBinds[$pKey]);
        }

        if (!@oci_execute($sStmt)) {
            $err = oci_error($sStmt);
            $subResults[] = ['bcode' => $bcode, 'query' => "Fetch {$tableName}", 'status' => "Error reading {$tableName} from main DB: " . ($err['message'] ?? '')];
            return $subResults;
        }

        $targetColumns = get_table_columns($bconn, $tableName);

        $rowCount = 0;
        $successCount = 0;
        $skippedCount = 0;

        while ($row = oci_fetch_array($sStmt, OCI_ASSOC + OCI_RETURN_NULLS)) {
            $rowCount++;

            // Skip privilege/role rows that already exist on the target branch
            // (BCODE is remapped to the target branch below, so check against $bcode)
            if (
                $tableName === 'UPRIVS' &&
                upriv_row_exists($bconn, $bcode, $row['ECODE'] ?? null, $row['PCCODE'] ?? null, $row['UPVALUE'] ?? null)
            ) {
                $skippedCount++;
                continue;
            }
            if (
                $tableName === 'UROLES' &&
                uroles_row_exists($bconn, $bcode, $row['ECODE'] ?? null, $row['RMCODE'] ?? null)
            ) {
                $skippedCount++;
                continue;
            }
            if (
                $tableName === 'FSTARTUP' &&
                fstartup_row_exists($bconn, $row['ECODE'] ?? null)
            ) {
                $skippedCount++;
                continue;
            }

            $cols = [];
            $vals = [];
            $binds = [];
            $idx = 0;

            foreach ($row as $colName => $colVal) {
                // If EPIC in EMP is LONG RAW, skip if null
                if ($colName === 'EPIC' && $colVal === null) {
                    continue;
                }
                // Only include column if target table has it (or targetColumns empty fallback)
                if (!empty($targetColumns) && !in_array(strtoupper($colName), $targetColumns)) {
                    continue;
                }

                // For FSTARTUP / UROLES / UPRIVS, force BCODE to the target branch
                // instead of carrying over the source branch value
                if (in_array($tableName, ['FSTARTUP', 'UROLES', 'UPRIVS'], true) && strtoupper($colName) === 'BCODE') {
                    $colVal = $bcode;
                }

                $cols[] = $colName;
                $bindKey = ":v" . $idx;

                if (in_array(strtoupper($colName), ['DOB', 'DOJ', 'CREATED_DATE', 'UPDATED_DATE']) && !empty($colVal)) {
                    $vals[] = $bindKey;
                    $binds[$bindKey] = strtoupper(date('d-M-y', strtotime($colVal)));
                } else {
                    $vals[] = $bindKey;
                    $binds[$bindKey] = $colVal;
                }
                $idx++;
            }

            if (empty($cols)) continue;

            $insertSql = "INSERT INTO {$tableName} (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $vals) . ")";

            if (DRY_RUN) {
                $subResults[] = [
                    'bcode'  => $bcode,
                    'query'  => render_sql_preview($insertSql, $binds),
                    'status' => "DRY RUN — not executed"
                ];
                $successCount++;
                continue;
            }

            $iStmt = @oci_parse($bconn, $insertSql);
            if ($iStmt) {
                foreach ($binds as $bK => &$bV) {
                    oci_bind_by_name($iStmt, $bK, $bV);
                }
                if (@oci_execute($iStmt, OCI_COMMIT_ON_SUCCESS)) {
                    $successCount++;
                } else {
                    $err = oci_error($iStmt);
                    $subResults[] = ['bcode' => $bcode, 'query' => "INSERT INTO {$tableName}", 'status' => "Insert failed on {$tableName}: " . ($err['message'] ?? '')];
                }
            }
        }

        $summary = DRY_RUN
            ? "DRY RUN — {$successCount} of {$rowCount} records would be copied into {$tableName}"
            : "Copied {$successCount} of {$rowCount} records into {$tableName}";

        if ($skippedCount > 0) {
            $summary .= " ({$skippedCount} already existed — skipped)";
        }

        $subResults[] = [
            'bcode'  => $bcode,
            'query'  => "COPY {$tableName}",
            'status' => $summary
        ];

        return $subResults;
    };

    // 1. Copy EMP row (skip if the employee already exists on the target branch)
    if (user_exists_in_branch($bconn, $ecode)) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => "SELECT EMP WHERE ECODE = '{$ecode}'",
            'status' => "EMP record already exists — skipped copy (roles/privileges still applied)"
        ];
    } else {
        $empRes = $copyTableRows('EMP', 'ECODE = :ecode', [':ecode' => $ecode]);
        $results = array_merge($results, $empRes);
    }

    // Ensure the employee is active on the target branch, whether the EMP
    // row was just copied or already existed (e.g. re-activating a
    // previously deactivated/transferred-out record).
    $results = array_merge($results, set_emp_estatus($bconn, $bcode, $ecode, 'Y'));

    // 2. Copy FSTARTUP rows (matches ECODE or EUNAME)
    $fstartupRes = $copyTableRows('FSTARTUP', 'ECODE = :ecode', [':ecode' => $ecode]);
    $results = array_merge($results, $fstartupRes);

    // 2b. Insert BRANCH_PRIVS row (branch access grant on the target branch)
    $results = array_merge($results, insert_branch_privs_row($bconn, $bcode, $ecode));

    // 3. Copy UROLES rows (matches ECODE or EUNAME)
    $urolesRes = $copyTableRows('UROLES', 'ECODE = :ecode', [':ecode' => $ecode]);
    $results = array_merge($results, $urolesRes);

    // 4. Copy UPRIVS rows (matches ECODE or EUNAME)
    $urpivsRes = $copyTableRows('UPRIVS', 'ECODE = :ecode', [':ecode' => $ecode]);
    $results = array_merge($results, $urpivsRes);

    // 5. Create Oracle User + grant type-based roles in a single combined
    //    statement: GRANT <roles> TO <user> IDENTIFIED BY <pwd>
    $pwd = !empty($dbPassword) ? $dbPassword : strtolower($ecode) . '123';
    $grants = get_grants_for_user_type($userType, $isIncharge);
    // Double-quote the password so special characters (e.g. *, #, $) don't
    // break the statement — an unquoted password with such chars raises ORA-00933.
    $grantSql = "GRANT " . $grants . " TO " . $ecode . ' IDENTIFIED BY "' . str_replace('"', '', $pwd) . '"';

    // CREATE USER + role grants must run as a privileged account (system),
    // not the drl schema account. Fall back to $bconn if no admin conn given.
    $grantConn = $adminConn ?: $bconn;

    if (DRY_RUN) {
        $results[] = [
            'bcode'  => $bcode,
            'query'  => $grantSql,
            'status' => "DRY RUN — not executed"
        ];
    } else {
        $gStmt = @oci_parse($grantConn, $grantSql);
        if ($gStmt && @oci_execute($gStmt, OCI_COMMIT_ON_SUCCESS)) {
            $results[] = [
                'bcode'  => $bcode,
                'query'  => $grantSql,
                'status' => "Created DB User '{$ecode}' & granted {$userType} permissions"
            ];
        } else {
            $err = oci_error($gStmt ? $gStmt : $grantConn);
            $results[] = [
                'bcode'  => $bcode,
                'query'  => $grantSql,
                'status' => "DB User creation notice: " . ($err['message'] ?? 'User may already exist or insufficient privileges')
            ];
        }
    }


    return $results;
}
