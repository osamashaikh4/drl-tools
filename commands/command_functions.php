<?php

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
