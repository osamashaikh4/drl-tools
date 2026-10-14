<?php

// Columns copied for each table. DATE columns move as 'YYYY-MM-DD HH24:MI:SS' strings.
// IC_HOLDERS.ICHPIC and ICARDS_CATEGORY.IC_CSAMPLE (LONG RAW) are not copied; they are empty on every branch.
const CARD_DATE_FMT = 'YYYY-MM-DD HH24:MI:SS';

const CARD_TABLES = [
    'ICARDS' => [
        'key'     => 'ICCODE',
        'columns' => ['IC_CCODE', 'ICCODE', 'ICFORM_NO', 'ICISSUE_DATE', 'ICEXPIRY', 'ICSTATUS', 'ICEU', 'ICED', 'ICET', 'ICEIP', 'ICEPC', 'ICREMARKS'],
        'dates'   => ['ICISSUE_DATE', 'ICEXPIRY', 'ICED'],
    ],
    'IC_HOLDERS' => [
        'key'     => 'ICHECODE',
        'columns' => ['ICCODE', 'RCODE', 'ICHECODE', 'ICHENAME', 'ICHFHN', 'ICHFHNAME', 'ICHSURNAME', 'EDCODE', 'ODCODE', 'ICHBPS', 'ICHCNIC_BFORM', 'ICHGENDER', 'ICHMSTATUS', 'ICHCONTACT_NO', 'ICHEMERGENCYNO', 'ICHLANDLINE', 'ICHEMAIL', 'ICHDOB', 'ICHDOREGULAR', 'ICHDORETIREMENT', 'ICHBLOODGROUP', 'ICHSTATUS', 'ICHEU', 'ICHED', 'ICHET', 'ICHEIP', 'ICHEP'],
        'dates'   => ['ICHDOB', 'ICHDOREGULAR', 'ICHDORETIREMENT', 'ICHED'],
    ],
    // Lookup tables the card rows point to (foreign keys). Missing rows are copied to the target first.
    'ICARDS_CATEGORY' => [
        'key'     => 'IC_CCODE',
        'columns' => ['CCODE', 'IC_CCODE', 'IC_CNAME', 'IC_CEFF', 'IC_CSTATUS', 'IC_CREMARKS'],
        'dates'   => ['IC_CEFF'],
    ],
    'RELATIONS' => [
        'key'     => 'RCODE',
        'columns' => ['RCODE', 'RNAME', 'RORDER', 'RSTATUS'],
        'dates'   => [],
    ],
    'EDESIG' => [
        'key'     => 'EDCODE',
        'columns' => ['EDCODE', 'EDDESC', 'EDSTATUS'],
        'dates'   => [],
    ],
    'ODEPARTMENTS' => [
        'key'     => 'ODCODE',
        'columns' => ['ODCODE', 'ODDESCRIPTION', 'ODABBR', 'ODSTATUS', 'ODHEADING', 'ODORDER', 'OCODE'],
        'dates'   => [],
    ],
];

function card_connect_branch($bcode, $connections, $timeoutSeconds = 6)
{
    if (!isset($connections[$bcode])) {
        return false;
    }

    $sid = in_array($bcode, ['13', '24', '27'], true) ? 'dbnc' : 'drl';
    $descriptor = "(DESCRIPTION=(CONNECT_TIMEOUT={$timeoutSeconds})(TRANSPORT_CONNECT_TIMEOUT={$timeoutSeconds})"
        . "(ADDRESS=(PROTOCOL=TCP)(HOST={$connections[$bcode]})(PORT=1521))(CONNECT_DATA=(SERVICE_NAME={$sid})))";

    $bconn = @oci_connect('drl', 'drl', $descriptor);
    return $bconn ?: false;
}

function card_branches_list($conn, $connections)
{
    $branches = [];
    $r = @oci_parse($conn, "SELECT BCODE, BNAME FROM BRANCHES WHERE BCODE NOT IN ('32', '33', '35', '37', '38', '70', '71', '44') ORDER BY BCODE ASC");
    if ($r && @oci_execute($r)) {
        while ($row = oci_fetch_array($r, OCI_ASSOC + OCI_RETURN_NULLS)) {
            if (isset($connections[$row['BCODE']])) {
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

function card_query_all($conn, $sql, $binds = [])
{
    $stmt = @oci_parse($conn, $sql);
    if (!$stmt) {
        return [];
    }
    foreach ($binds as $name => $value) {
        oci_bind_by_name($stmt, $name, $binds[$name]);
    }
    if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        return [];
    }
    $rows = [];
    while ($row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS)) {
        $rows[] = $row;
    }
    return $rows;
}

// Lookup lists for the New Card form.
function card_lookup_options($conn, $table, $labelCol, $where = '')
{
    $key  = CARD_TABLES[$table]['key'];
    $rows = card_query_all($conn, "SELECT {$key} K, {$labelCol} L FROM {$table} {$where} ORDER BY {$labelCol}");
    $out  = [];
    foreach ($rows as $row) {
        $out[$row['K']] = $row['L'];
    }
    return $out;
}

function card_select_sql($table, $where)
{
    $def  = CARD_TABLES[$table];
    $cols = [];
    foreach ($def['columns'] as $col) {
        $cols[] = in_array($col, $def['dates'], true) ? "TO_CHAR({$col}, '" . CARD_DATE_FMT . "') {$col}" : $col;
    }
    return 'SELECT ' . implode(', ', $cols) . " FROM {$table} WHERE {$where}";
}

function card_fetch_rows($conn, $table, $where, $binds)
{
    return card_query_all($conn, card_select_sql($table, $where), $binds);
}

function card_row_exists($conn, $table, $keyValue)
{
    $key  = CARD_TABLES[$table]['key'];
    $rows = card_query_all($conn, "SELECT COUNT(*) N FROM {$table} WHERE {$key} = :k", [':k' => $keyValue]);
    return !empty($rows) && (int)$rows[0]['N'] > 0;
}

// Card plus all its holders from a database, or null if the card does not exist there.
function card_fetch($conn, $iccode)
{
    $cards = card_fetch_rows($conn, 'ICARDS', 'ICCODE = :k', [':k' => $iccode]);
    if (empty($cards)) {
        return null;
    }
    $holders = card_fetch_rows($conn, 'IC_HOLDERS', 'ICCODE = :k ORDER BY CASE WHEN RCODE = \'SL\' THEN 0 ELSE 1 END, ICHECODE', [':k' => $iccode]);
    return ['card' => $cards[0], 'holders' => $holders];
}

function card_sql_literal($value, $isDate)
{
    if ($value === null || $value === '') {
        return 'NULL';
    }
    $quoted = "'" . str_replace("'", "''", (string)$value) . "'";
    return $isDate ? "TO_DATE({$quoted}, '" . CARD_DATE_FMT . "')" : $quoted;
}

// Inserts one row (or only previews it on a dry run). Returns [previewSql, errorMessage|null].
function card_insert_row($conn, $table, $row, $dryRun)
{
    $def     = CARD_TABLES[$table];
    $values  = [];
    $preview = [];
    $binds   = [];

    foreach ($def['columns'] as $i => $col) {
        $isDate    = in_array($col, $def['dates'], true);
        $value     = $row[$col] ?? null;
        $value     = ($value === '') ? null : $value;
        $preview[] = card_sql_literal($value, $isDate);

        $bind         = ':b' . $i;
        $binds[$bind] = $value;
        $values[]     = $isDate ? "TO_DATE({$bind}, '" . CARD_DATE_FMT . "')" : $bind;
    }

    $cols       = implode(', ', $def['columns']);
    $previewSql = "INSERT INTO {$table} ({$cols}) VALUES (" . implode(', ', $preview) . ")";

    if ($dryRun) {
        return [$previewSql, null];
    }

    $stmt = oci_parse($conn, "INSERT INTO {$table} ({$cols}) VALUES (" . implode(', ', $values) . ")");
    foreach ($binds as $name => $value) {
        oci_bind_by_name($stmt, $name, $binds[$name]);
    }
    if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $e = oci_error($stmt);
        return [$previewSql, $e['message'] ?? 'unknown error'];
    }
    return [$previewSql, null];
}

// Makes sure a lookup value exists on the target, copying the row from $lookupConn if missing.
// Returns a status row when something was (or would be) copied or failed, otherwise null.
function card_ensure_lookup($lookupConn, $targetConn, $bcode, $table, $value, $dryRun)
{
    if ($value === null || $value === '' || card_row_exists($targetConn, $table, $value)) {
        return null;
    }

    $key  = CARD_TABLES[$table]['key'];
    $rows = card_fetch_rows($lookupConn, $table, "{$key} = :k", [':k' => $value]);
    if (empty($rows)) {
        return ['bcode' => $bcode, 'query' => "{$table} {$key} = {$value}", 'status' => "Failed: {$table} '{$value}' is missing on the branch and was not found in the source to copy", 'error' => true];
    }

    [$sql, $err] = card_insert_row($targetConn, $table, $rows[0], $dryRun);
    if ($err) {
        return ['bcode' => $bcode, 'query' => $sql, 'status' => "Failed copying missing {$table} '{$value}': {$err}", 'error' => true];
    }
    return ['bcode' => $bcode, 'query' => $sql, 'status' => $dryRun ? "Dry run — would copy missing {$table} '{$value}'" : "Copied missing {$table} '{$value}' to branch"];
}

/**
 * Writes a card and its holders into one branch inside a single transaction.
 * An existing card is left as is; only holders missing on the branch are added.
 * $lookupConn is where missing category / relation / designation / department rows are copied from.
 */
function card_write_to_branch($lookupConn, $targetConn, $bcode, $card, $holders, $dryRun)
{
    $status = [];
    $iccode = $card['ICCODE'];
    $failed = false;

    $add = function ($row) use (&$status, &$failed) {
        if ($row) {
            $failed = $failed || !empty($row['error']);
            unset($row['error']);
            $status[] = $row;
        }
    };

    // Lookups first so the foreign keys are satisfied.
    $add(card_ensure_lookup($lookupConn, $targetConn, $bcode, 'ICARDS_CATEGORY', $card['IC_CCODE'] ?? null, $dryRun));
    $seen = [];
    foreach ($holders as $h) {
        foreach (['RELATIONS' => 'RCODE', 'EDESIG' => 'EDCODE', 'ODEPARTMENTS' => 'ODCODE'] as $table => $col) {
            $value = $h[$col] ?? null;
            if (!$failed && !isset($seen[$table][$value])) {
                $seen[$table][$value] = true;
                $add(card_ensure_lookup($lookupConn, $targetConn, $bcode, $table, $value, $dryRun));
            }
        }
    }

    if (!$failed) {
        if (card_row_exists($targetConn, 'ICARDS', $iccode)) {
            $status[] = ['bcode' => $bcode, 'query' => "ICARDS ICCODE = {$iccode}", 'status' => "Notice: card {$iccode} already exists — skipped, checking holders"];
        } else {
            [$sql, $err] = card_insert_row($targetConn, 'ICARDS', $card, $dryRun);
            $add(['bcode' => $bcode, 'query' => $sql, 'status' => $err ? "Failed: {$err}" : ($dryRun ? 'Dry run — not executed' : "Card {$iccode} created successfully"), 'error' => (bool)$err]);
        }
    }

    foreach ($holders as $h) {
        if ($failed) {
            break;
        }
        if (card_row_exists($targetConn, 'IC_HOLDERS', $h['ICHECODE'])) {
            $status[] = ['bcode' => $bcode, 'query' => "IC_HOLDERS ICHECODE = {$h['ICHECODE']}", 'status' => "Notice: holder {$h['ICHECODE']} already exists — skipped"];
            continue;
        }
        [$sql, $err] = card_insert_row($targetConn, 'IC_HOLDERS', $h, $dryRun);
        $add(['bcode' => $bcode, 'query' => $sql, 'status' => $err ? "Failed: {$err}" : ($dryRun ? 'Dry run — not executed' : "Holder {$h['ICHECODE']} created successfully"), 'error' => (bool)$err]);
    }

    if ($dryRun) {
        oci_rollback($targetConn);
    } elseif ($failed) {
        oci_rollback($targetConn);
        $status[] = ['bcode' => $bcode, 'query' => "ROLLBACK", 'status' => "Failed: nothing was saved for card {$iccode} on this branch"];
    } else {
        oci_commit($targetConn);
    }

    return $status;
}

// Audit columns stamped on rows created from the New Card form.
function card_audit_values()
{
    $now = new DateTime('now', new DateTimeZone('Asia/Karachi'));

    return [
        'user' => substr($_SESSION['comname'] ?? 'drl-tools', 0, 20),
        'date' => $now->format('Y-m-d H:i:s'),
        'time' => $now->format('H:i'),
        'ip'   => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 20),
        'pc'   => 'DRL Tools',
    ];
}

// Gender implied by a family relation; a spouse is the opposite of the cardholder.
function card_family_gender($rcode, $gender, $holderGender = '')
{
    if ($gender !== '') {
        return $gender;
    }
    $implied = ['FA' => 'M', 'CS' => 'M', 'MO' => 'F', 'CD' => 'F'];
    if (isset($implied[$rcode])) {
        return $implied[$rcode];
    }
    if ($rcode === 'SP' && in_array($holderGender, ['M', 'F'], true)) {
        return $holderGender === 'M' ? 'F' : 'M';
    }
    return '';
}

// Family member names are stored as "<Relation>= <Name>", e.g. "Wife= Farzana", like the existing data.
function card_family_name($rcode, $gender, $name, $relationName = '')
{
    $prefixes = ['FA' => 'Father', 'MO' => 'Mother', 'CS' => 'Son', 'CD' => 'Daughter'];
    $prefix   = $rcode === 'SP' ? ($gender === 'M' ? 'Husband' : 'Wife') : ($prefixes[$rcode] ?? $relationName);

    // Don't double up if the prefix was typed in already.
    $name = preg_replace('/^\s*(father|mother|son|daughter|wife|husband|spouse)\s*=\s*/i', '', $name);

    return $prefix === '' ? $name : $prefix . '= ' . $name;
}

function card_form_date($value)
{
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    return $value . ' 00:00:00';
}
