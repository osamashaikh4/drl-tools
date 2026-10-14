<?php

const COUNT_TABLES = [
    'p_investigations' => ['table' => 'p_investigations', 'datecol' => 'PIED'],
    'pbilling'         => ['table' => 'pbilling',         'datecol' => 'PBED'],
    'PBCANCEL'         => ['table' => 'PBCANCEL',         'datecol' => 'PBCED'],
];

const MODAL_INSERT_LIMIT = 200;

const SESSION_DATE_FMT = 'DD-MON-RRRR HH24:MI:SS';

const FILL_TABLES = [
    'LOGINBATCH' => [
        'columns' => ['LIBCODE', 'LIBUSER', 'LIBSDATE', 'LIBSTIME', 'LIBSTATUS', 'LIBEPC', 'LIBIIP'],
        'filter'  => ['type' => 'range', 'datecol' => 'LIBSDATE', 'bcodeCol' => 'LIBCODE', 'mode' => 'like'],
    ],
    'LOGOUTBATCH' => [
        'columns' => ['LIBCODE', 'LIBUSER', 'LOBVOUCHERNO', 'LOBDATE', 'LOBTIME', 'LOBSAMOUNT', 'LOBCINH', 'LOBCBAL', 'LOBSTATUS', 'LOBPC', 'LOBIP', 'LOBREMARKS'],
        'filter'  => ['type' => 'range', 'datecol' => 'LOBDATE', 'bcodeCol' => 'LIBCODE', 'mode' => 'like'],
    ],
    'PATIENT_BIO' => [
        'columns' => ['EMR', 'PNAME', 'MEDIAN', 'MIDDLE_NAME', 'SURNAME', 'SEX', 'MSTATUS', 'CNIC', 'DOB', 'EDLEVEL', 'PREFESSION', 'PHABITS', 'CELL', 'ADCONTACTS', 'BLOOD_GROUP', 'CITY', 'ADDRESS', 'PBUSER', 'PBED', 'PBT', 'PBPC', 'PBIP', 'PREFIX_CODE'],
        'filter'  => ['type' => 'via', 'keyCol' => 'EMR', 'lookup' => 'PATIENT_VISITS', 'datecol' => 'PVED'],
    ],
    'PATIENT_VISITS' => [
        'columns' => ['BCODE', 'EMR', 'LAB_NO', 'CCODE', 'TOKEN_NO', 'VISIT_DATE', 'VISIT_TIME', 'AGE', 'AGE_UNIT', 'REG_NO', 'HUCODE', 'PAYMENT_STATUS', 'REF_CODE', 'REF_BY', 'PVREMARKS', 'PUNAME', 'PPWD', 'PVUSER', 'PVED', 'PVET', 'PVEPC', 'PVEIP', 'LIBCODE'],
        'filter'  => ['type' => 'range', 'datecol' => 'PVED', 'bcodeCol' => 'BCODE', 'mode' => 'eq'],
    ],
    'P_INVESTIGATIONS' => [
        'columns' => ['BCODE', 'EMR', 'LAB_NO', 'CCODE', 'ICODE', 'DCODE', 'SCODE', 'RATE', 'QTY', 'DISCOUNT', 'SDDATE', 'SDDT', 'PISTATUS', 'PIREMARKS', 'PIEUSER', 'PIED', 'PIET', 'PIEPC', 'PIEIP'],
        'filter'  => ['type' => 'range', 'datecol' => 'PIED', 'bcodeCol' => 'BCODE', 'mode' => 'eq'],
    ],
    'PBILLING' => [
        'columns' => ['BCODE', 'EMR', 'LAB_NO', 'CCODE', 'PBPSTATUS', 'TXTYPE', 'RCVD_AMOUNT', 'PBREMARKS', 'PBEUSER', 'PBED', 'PBET', 'PBEPC', 'PBEIP', 'CASH', 'CASHR', 'PBVNO', 'LIBCODE'],
        'filter'  => ['type' => 'range', 'datecol' => 'PBED', 'bcodeCol' => 'BCODE', 'mode' => 'eq'],
        'defaults' => ['RCVD_AMOUNT' => 0],
    ],
    'PIVERIFIED' => [
        'columns' => ['BCODE', 'EMR', 'LAB_NO', 'CCODE', 'ICODE', 'PIVSTATUS', 'PIVREMARKS', 'PIVEU', 'PIVED', 'PIVET', 'PIVEPC', 'PIVEIP'],
        'filter'  => ['type' => 'range', 'datecol' => 'PIVED', 'bcodeCol' => 'BCODE', 'mode' => 'eq'],
    ],
    'PIPARAMV1' => [
        'columns' => ['BCODE', 'CCODE', 'EMR', 'LAB_NO', 'ICODE', 'IPCODE', 'IPRRCODE', 'PIPVALUE', 'PIPESTATUS', 'PIPSTATUS', 'PIPED', 'PIPET', 'PIPVARIFIED', 'PIPEU', 'PIPREMARKS', 'PIPRRANGE', 'PIPEPC', 'PIPEIP'],
        'filter'  => ['type' => 'range', 'datecol' => 'PIPED', 'bcodeCol' => 'BCODE', 'mode' => 'eq'],
    ],
    'PIPARAMVDTL' => [
        'columns' => ['BCODE', 'EMR', 'LAB_NO', 'ICODE', 'IPCODE', 'ABCODE', 'SENSITIVITY', 'PIPDSTATUS', 'PIPDUE', 'PIPDED', 'PIPDET', 'PIPDEPC', 'PIPDEIP', 'ORGANISM2', 'ORGANISM3'],
        'filter'  => ['type' => 'range', 'datecol' => 'PIPDED', 'bcodeCol' => 'BCODE', 'mode' => 'eq'],
    ],
    'APPREQUEST' => [
        'columns' => ['APPREQID', 'BCODE', 'EMR', 'LAB_NO', 'CCODE', 'APPCODE', 'APPRNOTE', 'APPRSTATUS', 'APPREU', 'APPRED', 'APPRET', 'APPREPC', 'APPREIP', 'LIBCODE'],
        'filter'  => ['type' => 'range', 'datecol' => 'APPRED', 'bcodeCol' => 'BCODE', 'mode' => 'eq'],
    ],
    'APPRCRESPONSE' => [
        'columns' => ['APPREQID', 'ICODE', 'APPRCSTATUS', 'APPRCREU', 'APPRCRED', 'APPRCRET', 'APPRCREPC', 'APPRCREIP', 'APPRCREMARKS', 'APPRCQTY', 'APPRCRATE', 'LIBCODE'],
        'filter'  => ['type' => 'via', 'keyCol' => 'APPREQID', 'lookup' => 'APPREQUEST', 'datecol' => 'APPRED'],
    ],
    'PBCANCEL' => [
        'columns' => ['BCODE', 'EMR', 'LAB_NO', 'CCODE', 'PBCPSTATUS', 'TXTYPE', 'PAID_AMOUNT', 'PBCREMARKS', 'PBCEUSER', 'PBCED', 'PBCET', 'PBCEPC', 'PBCEIP', 'PBCVNO', 'LIBCODE'],
        'filter'  => ['type' => 'range', 'datecol' => 'PBCED', 'bcodeCol' => 'BCODE', 'mode' => 'eq'],
    ],
];

const GROUP_A_TABLES = ['LOGINBATCH', 'LOGOUTBATCH', 'PATIENT_BIO', 'PATIENT_VISITS', 'P_INVESTIGATIONS', 'PBILLING', 'PIVERIFIED', 'PIPARAMV1', 'PIPARAMVDTL'];
const GROUP_B_TABLES = ['APPREQUEST', 'APPRCRESPONSE', 'PBCANCEL'];

function branchDbLink(string $bcode): string
{
    return 'd' . $bcode;
}


function loadBranchNames($conn): array
{
    $names = [];

    try {
        $stmt = runQuery($conn, 'SELECT BCODE, BNAME FROM BRANCHES', OCI_DEFAULT);
    } catch (RuntimeException $ex) {
        return $names;
    }

    while ($row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS)) {
        $bcode = trim((string) $row['BCODE']);
        if ($bcode !== '') {
            $names[$bcode] = trim((string) $row['BNAME']);
        }
    }

    return $names;
}

function installJsonErrorHandler(): void
{
    set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0) {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });
}

function sqlStr(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function dateWhere(string $col, string $from, string $to): string
{
    return "{$col} BETWEEN " . sqlStr($from) . ' AND ' . sqlStr($to);
}

function logQuery(string $sql): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    @file_put_contents($dir . '/queries.log', '[' . date('Y-m-d H:i:s') . "]\n" . $sql . ";\n\n", FILE_APPEND | LOCK_EX);
}

function jsonResponse(array $data): string
{
    $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) {
        logQuery('-- JSON ENCODE FAILED: ' . json_last_error_msg());
        return json_encode(['ok' => false, 'error' => 'Could not encode response: ' . json_last_error_msg()]);
    }

    return $json;
}

function runQuery($conn, string $sql, int $mode = OCI_NO_AUTO_COMMIT)
{
    logQuery($sql);

    $stmt = oci_parse($conn, $sql);
    if (!oci_execute($stmt, $mode)) {
        $e = oci_error($stmt);
        throw new RuntimeException($e['message'] ?? 'unknown error');
    }

    return $stmt;
}

function periodBounds(string $pfrom, string $pto): array
{
    $fmt = static function (string $ymd): string {
        return strtoupper(DateTime::createFromFormat('!Y-m-d', $ymd)->format('d-M-y'));
    };

    return [$fmt($pfrom), $fmt($pto)];
}

function connectToBranch(string $ip, int $timeoutSeconds = 6)
{
    $serviceName = 'drl';

    if ($ip === '172.16.13.254' || $ip === '172.16.24.254' || $ip === '10.141.70.254') {
        $serviceName = 'dbnc';
    }

    $descriptor = "(DESCRIPTION=(CONNECT_TIMEOUT={$timeoutSeconds})(TRANSPORT_CONNECT_TIMEOUT={$timeoutSeconds})"
        . "(ADDRESS=(PROTOCOL=TCP)(HOST={$ip})(PORT=1521))(CONNECT_DATA=(SERVICE_NAME=$serviceName)))";

    return @oci_connect('drl', 'drl', $descriptor);
}

function fetchCount($conn, string $table, string $datecol, string $bcode, string $pfrom, string $pto): int
{
    [$from, $to] = periodBounds($pfrom, $pto);

    $sql = "SELECT COUNT(*) AS CNT FROM {$table} WHERE " . dateWhere($datecol, $from, $to) . ' AND BCODE = ' . sqlStr($bcode);

    try {
        $stmt = runQuery($conn, $sql, OCI_DEFAULT);
    } catch (RuntimeException $ex) {
        throw new RuntimeException("Count query failed on {$table}: " . $ex->getMessage());
    }

    $row = oci_fetch_assoc($stmt);
    return (int) $row['CNT'];
}

function rangeWhere(array $filter, string $bcode, string $from, string $to): string
{
    $bcodePart = $filter['mode'] === 'like'
        ? "{$filter['bcodeCol']} LIKE " . sqlStr($bcode . '%')
        : "{$filter['bcodeCol']} = " . sqlStr($bcode);

    return dateWhere($filter['datecol'], $from, $to) . ' AND ' . $bcodePart;
}

function fetchPatientBioEmrs($conn, string $dblink, string $bcode, string $pfrom, string $pto): array
{
    [$from, $to] = periodBounds($pfrom, $pto);

    $def = FILL_TABLES['PATIENT_BIO'];
    $def['columns'] = ['EMR'];

    $stmt = runQuery($conn, buildDeficitSql('PATIENT_BIO', $def, $dblink, $bcode, $from, $to));

    $emrs = [];
    while ($row = oci_fetch_array($stmt, OCI_ASSOC)) {
        $emrs[] = (string) $row['EMR'];
    }

    return $emrs;
}

function emrInList(array $emrs): string
{
    $parts = [];
    foreach (array_chunk($emrs, 1000) as $chunk) {
        $parts[] = 'EMR IN (' . implode(', ', array_map('sqlStr', $chunk)) . ')';
    }

    return count($parts) === 1 ? $parts[0] : '(' . implode(' OR ', $parts) . ')';
}

function buildDeficitSql(string $table, array $def, string $dblink, string $bcode, string $from, string $to, array $emrs = []): string
{
    $cols = implode(', ', $def['columns']);
    $f    = $def['filter'];

    if ($f['type'] === 'emr') {
        $where = emrInList($emrs);
        if (isset($f['datecol'])) {
            $where .= ' AND ' . rangeWhere($f, $bcode, $from, $to);
        }

        return "SELECT {$cols} FROM {$table}@{$dblink} WHERE {$where}\n"
            . "MINUS\n"
            . "SELECT {$cols} FROM {$table} WHERE {$where}";
    }

    if ($f['type'] === 'via') {
        $lookupWhere = dateWhere($f['datecol'], $from, $to) . ' AND BCODE = ' . sqlStr($bcode);

        return "SELECT {$cols}\nFROM {$table}@{$dblink}\nWHERE {$f['keyCol']} IN (\n"
            . "    SELECT {$f['keyCol']} FROM {$f['lookup']}@{$dblink} WHERE {$lookupWhere}\n"
            . "    MINUS\n"
            . "    SELECT {$f['keyCol']} FROM {$f['lookup']} WHERE {$lookupWhere}\n"
            . ')';
    }

    $where = rangeWhere($f, $bcode, $from, $to);

    return "SELECT {$cols} FROM {$table}@{$dblink} WHERE {$where}\n"
        . "MINUS\n"
        . "SELECT {$cols} FROM {$table} WHERE {$where}";
}

function buildInsertSql(string $table, array $def, array $row): string
{
    $values = [];
    foreach ($def['columns'] as $col) {
        $val = $row[$col] === null ? 'NULL' : sqlStr((string) $row[$col]);

        if ($val === 'NULL' && isset($def['defaults'][$col])) {
            $val = $def['defaults'][$col];
        }

        $values[] = $val;
    }

    return 'INSERT INTO ' . $table . ' (' . implode(', ', $def['columns']) . ')'
        . ' VALUES (' . implode(', ', $values) . ')';
}

function fillTable($conn, string $table, string $dblink, string $bcode, string $pfrom, string $pto, bool $dryRun = false, array $emrs = []): array
{
    $def = FILL_TABLES[$table];
    [$from, $to] = periodBounds($pfrom, $pto);

    if ($def['filter']['type'] === 'emr' && !$emrs) {
        return ['found' => 0, 'inserted' => 0, 'skipped' => 0, 'sql' => '', 'inserts' => [], 'insertsOmitted' => 0];
    }

    $selectSql = buildDeficitSql($table, $def, $dblink, $bcode, $from, $to, $emrs);

    try {
        $sel = runQuery($conn, $selectSql);
    } catch (RuntimeException $ex) {
        throw new RuntimeException('deficit query failed: ' . $ex->getMessage());
    }

    $inserts = [];
    $omitted = 0;
    $keep = static function (string $sql) use (&$inserts, &$omitted): void {
        if (count($inserts) < MODAL_INSERT_LIMIT) {
            $inserts[] = $sql;
        } else {
            $omitted++;
        }
    };

    $foundEmrs = [];

    if ($dryRun) {
        $found = 0;
        while ($row = oci_fetch_array($sel, OCI_ASSOC + OCI_RETURN_NULLS)) {
            $sql = buildInsertSql($table, $def, $row);
            logQuery("-- DRY RUN (not executed)\n" . $sql);
            $keep($sql);
            if ($table === 'PATIENT_BIO') {
                $foundEmrs[] = (string) $row['EMR'];
            }
            $found++;
        }

        return ['found' => $found, 'inserted' => 0, 'skipped' => 0, 'sql' => $selectSql, 'inserts' => $inserts, 'insertsOmitted' => $omitted, 'foundEmrs' => $foundEmrs];
    }

    $rows = [];
    while ($row = oci_fetch_array($sel, OCI_ASSOC + OCI_RETURN_NULLS)) {
        $rows[] = $row;
        if ($table === 'PATIENT_BIO') {
            $foundEmrs[] = (string) $row['EMR'];
        }
    }

    $inserted = 0;
    $skipped  = 0;

    foreach ($rows as $row) {
        $sql = buildInsertSql($table, $def, $row);
        logQuery($sql);
        $keep($sql);

        $stmt = oci_parse($conn, $sql);
        if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
            $e = oci_error($stmt);
            if ((int) ($e['code'] ?? 0) === 1) {
                $skipped++;
                continue;
            }
            throw new RuntimeException($e['message'] ?? 'unknown error');
        }

        $inserted++;
    }

    return ['found' => count($rows), 'inserted' => $inserted, 'skipped' => $skipped, 'sql' => $selectSql, 'inserts' => $inserts, 'insertsOmitted' => $omitted, 'foundEmrs' => $foundEmrs];
}
