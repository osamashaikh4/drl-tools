<?php

// Tables and their columns ({TABLE: [COL, ...]}) for the editor's autocomplete. Branches share 253's schema.
function commands_schema($conn)
{
    $stmt = oci_parse($conn, "SELECT c.TABLE_NAME, c.COLUMN_NAME FROM USER_TAB_COLUMNS c
        JOIN USER_TABLES t ON t.TABLE_NAME = c.TABLE_NAME ORDER BY c.TABLE_NAME, c.COLUMN_ID");
    $schema = [];
    if ($stmt && @oci_execute($stmt)) {
        while ($row = oci_fetch_assoc($stmt)) {
            $schema[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
        }
    }
    return $schema;
}

const COMMANDS_MAX_ROWS = 500;

function commands_connect_branch($bcode, $connections, $timeoutSeconds = 6)
{
    if (!isset($connections[$bcode])) {
        return false;
    }
    $sid = in_array($bcode, ['13', '24', '27'], true) ? 'dbnc' : 'drl';
    $descriptor = "(DESCRIPTION=(CONNECT_TIMEOUT={$timeoutSeconds})(TRANSPORT_CONNECT_TIMEOUT={$timeoutSeconds})"
        . "(ADDRESS=(PROTOCOL=TCP)(HOST={$connections[$bcode]})(PORT=1521))(CONNECT_DATA=(SERVICE_NAME={$sid})))";
    return @oci_connect('drl', 'drl', $descriptor) ?: false;
}

// Splits a script into statements on ";" outside quotes and -- / /* */ comments.
function split_sql_statements($script)
{
    $statements = [];
    $buf = '';
    $len = strlen($script);

    for ($i = 0; $i < $len; $i++) {
        $ch   = $script[$i];
        $next = $i + 1 < $len ? $script[$i + 1] : '';

        if ($ch === '-' && $next === '-') {
            $end = strpos($script, "\n", $i);
            $end = $end === false ? $len : $end;
            $buf .= substr($script, $i, $end - $i);
            $i = $end - 1;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $end = strpos($script, '*/', $i + 2);
            $end = $end === false ? $len : $end + 2;
            $buf .= substr($script, $i, $end - $i);
            $i = $end - 1;
            continue;
        }
        if ($ch === "'" || $ch === '"') {
            // Copy the whole quoted literal; '' inside a string is an escaped quote.
            $end = $i + 1;
            while ($end < $len) {
                if ($script[$end] === $ch) {
                    if ($ch === "'" && $end + 1 < $len && $script[$end + 1] === "'") {
                        $end += 2;
                        continue;
                    }
                    break;
                }
                $end++;
            }
            $buf .= substr($script, $i, $end - $i + 1);
            $i = $end;
            continue;
        }
        if ($ch === ';') {
            $statements[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $statements[] = $buf;

    // Drop comment-only / empty pieces, and comments in front of a statement so it starts with its keyword.
    $out = [];
    foreach ($statements as $s) {
        $code = trim(preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~'], '', $s));
        if ($code !== '') {
            $out[] = trim(preg_replace('~^(\s*(--[^\n]*(\n|$)|/\*.*?\*/))+~s', '', $s));
        }
    }
    return $out;
}

// 'select' for SELECT / WITH queries, 'dml' for INSERT / UPDATE, null for anything else.
function statement_kind($sql)
{
    $code = trim(preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~'], '', $sql));
    if (preg_match('/^(SELECT|WITH)\b/i', $code)) {
        // A locking query is not read-only.
        return preg_match('/\bFOR\s+UPDATE\b/i', $code) ? null : 'select';
    }
    if (preg_match('/^(INSERT|UPDATE)\b/i', $code)) {
        return 'dml';
    }
    return null;
}

// Runs a SELECT read-only (rolled back) and returns its columns and up to COMMANDS_MAX_ROWS rows.
function run_select($bconn, $sql)
{
    $stmt = @oci_parse($bconn, $sql);
    if (!$stmt || !@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $e = $stmt ? oci_error($stmt) : oci_error($bconn);
        @oci_rollback($bconn);
        return ['ok' => false, 'error' => $e['message'] ?? 'Query failed'];
    }

    $columns = [];
    for ($i = 1, $n = oci_num_fields($stmt); $i <= $n; $i++) {
        $columns[] = oci_field_name($stmt, $i);
    }

    $rows = [];
    $truncated = false;
    while ($row = oci_fetch_array($stmt, OCI_NUM + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) {
        if (count($rows) >= COMMANDS_MAX_ROWS) {
            $truncated = true;
            break;
        }
        $rows[] = $row;
    }
    oci_free_statement($stmt);
    oci_rollback($bconn);

    return ['ok' => true, 'columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
}

// Runs an INSERT / UPDATE (committed), skipping an INSERT whose exact row already exists.
function run_dml($bconn, $sql)
{
    $check = insert_row_exists($bconn, $sql);
    if ($check['exists'] === true) {
        return ['ok' => true, 'skipped' => true, 'status' => 'Skipped: row already exists', 'affected' => 0];
    }
    $note = $check['exists'] === null ? ' (duplicate check not done: ' . $check['reason'] . ')' : '';

    $stmt = @oci_parse($bconn, $sql);
    if (!$stmt || !@oci_execute($stmt)) {
        $e = $stmt ? oci_error($stmt) : oci_error($bconn);
        return ['ok' => false, 'status' => 'Execution failed: ' . ($e['message'] ?? 'unknown error') . $note];
    }
    $affected = oci_num_rows($stmt);
    return ['ok' => true, 'status' => 'Executed successfully' . $note, 'affected' => $affected];
}

// Splits a SQL list on commas that are not inside quotes or parentheses.
function split_sql_list($list)
{
    $parts = [];
    $buf   = '';
    $depth = 0;
    $len   = strlen($list);

    for ($i = 0; $i < $len; $i++) {
        $ch = $list[$i];

        if ($ch === "'" || $ch === '"') {
            // Copy the whole quoted literal; '' inside a string is an escaped quote.
            $end = $i + 1;
            while ($end < $len) {
                if ($list[$end] === $ch) {
                    if ($ch === "'" && $end + 1 < $len && $list[$end + 1] === "'") {
                        $end += 2;
                        continue;
                    }
                    break;
                }
                $end++;
            }
            $buf .= substr($list, $i, $end - $i + 1);
            $i = $end;
            continue;
        }

        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
        } elseif ($ch === ',' && $depth === 0) {
            $parts[] = trim($buf);
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $parts[] = trim($buf);

    return $parts;
}

// Parses "INSERT INTO table [(cols)] VALUES (values)". Returns [table, cols|null, values] or null.
function parse_insert_values($sql)
{
    if (!preg_match('/^\s*INSERT\s+INTO\s+([A-Za-z0-9_$#."]+)\s*(\(([^()]*)\))?\s*VALUES\s*\((.*)\)\s*$/is', $sql, $m)) {
        return null;
    }

    $cols = ($m[3] ?? '') !== '' ? array_map('trim', explode(',', $m[3])) : null;
    return [$m[1], $cols, split_sql_list($m[4])];
}

// Column names of a table in insert order, read from the branch the command runs on.
function table_columns($bconn, $table)
{
    $name  = strtoupper(str_replace('"', '', $table));
    $owner = null;
    if (strpos($name, '.') !== false) {
        [$owner, $name] = explode('.', $name, 2);
    }

    $sql = $owner === null
        ? "SELECT COLUMN_NAME FROM USER_TAB_COLUMNS WHERE TABLE_NAME = :t ORDER BY COLUMN_ID"
        : "SELECT COLUMN_NAME FROM ALL_TAB_COLUMNS WHERE OWNER = :o AND TABLE_NAME = :t ORDER BY COLUMN_ID";

    $stmt = oci_parse($bconn, $sql);
    oci_bind_by_name($stmt, ':t', $name);
    if ($owner !== null) {
        oci_bind_by_name($stmt, ':o', $owner);
    }
    if (!@oci_execute($stmt)) {
        return [];
    }

    $cols = [];
    while ($row = oci_fetch_assoc($stmt)) {
        $cols[] = $row['COLUMN_NAME'];
    }
    return $cols;
}

/**
 * For an INSERT ... VALUES, checks whether a row with exactly those values already exists on the branch.
 * Returns ['exists' => bool] or ['exists' => null, 'reason' => why the check could not be done].
 */
function insert_row_exists($bconn, $sql)
{
    if (!preg_match('/^\s*INSERT\b/i', $sql)) {
        return ['exists' => false];
    }

    $parsed = parse_insert_values($sql);
    if ($parsed === null) {
        return ['exists' => null, 'reason' => 'only INSERT ... VALUES (...) can be checked'];
    }
    [$table, $cols, $values] = $parsed;

    if ($cols === null) {
        $cols = table_columns($bconn, $table);
        if (empty($cols)) {
            return ['exists' => null, 'reason' => "could not read the columns of {$table}"];
        }
    }
    if (count($cols) !== count($values)) {
        return ['exists' => null, 'reason' => count($cols) . ' columns but ' . count($values) . ' values'];
    }

    $where = [];
    foreach ($cols as $i => $col) {
        $val = $values[$i];
        // In Oracle an empty string is NULL, and NULL never equals anything.
        $where[] = (strtoupper($val) === 'NULL' || $val === "''") ? "{$col} IS NULL" : "{$col} = {$val}";
    }

    $check = oci_parse($bconn, "SELECT COUNT(*) N FROM {$table} WHERE " . implode(' AND ', $where));
    if (!$check || !@oci_execute($check)) {
        $e = $check ? oci_error($check) : oci_error($bconn);
        return ['exists' => null, 'reason' => $e['message'] ?? 'check query failed'];
    }

    $row = oci_fetch_assoc($check);
    return ['exists' => (int)$row['N'] > 0];
}
