<?php
// Shared by api.php: lab no parsing, branch connections and the deleted antibiotics workbook.

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;

// The running log of every deleted row; the same 13 columns as PIPARAMVDTL up to PIPDEIP, no header.
const DA_WORKBOOK = __DIR__ . '/../deleted antibiotics.xlsx';
const DA_LOCK     = __DIR__ . '/../deleted antibiotics.lock';

// Column order of the workbook (A..M). PIPDED is read as text so the date does not depend on NLS settings.
const DA_COLUMNS = ['BCODE', 'EMR', 'LAB_NO', 'ICODE', 'IPCODE', 'ABCODE', 'SENSITIVITY', 'PIPDSTATUS', 'PIPDUE', 'PIPDED', 'PIPDET', 'PIPDEPC', 'PIPDEIP'];

const DA_SELECT = "SELECT BCODE, EMR, LAB_NO, ICODE, IPCODE, ABCODE, SENSITIVITY, PIPDSTATUS, PIPDUE,
                          TO_CHAR(PIPDED, 'YYYY-MM-DD HH24:MI:SS') AS PIPDED, PIPDET, PIPDEPC, PIPDEIP
                     FROM PIPARAMVDTL
                    WHERE LAB_NO = :lab
                    ORDER BY PIPDED, PIPDET, ABCODE";

// Splits the textarea on commas, spaces and new lines; keeps the first of any repeats.
function da_split_lab_nos(string $text): array
{
    $parts = preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_unique($parts));
}

// The branch code is the third pair of digits: 25 03 01 03515 -> 01. Null if the lab no is not valid.
function da_branch_of(string $labNo, array $connections): ?string
{
    if (!preg_match('/^\d{7,20}$/', $labNo)) {
        return null;
    }
    $bcode = substr($labNo, 4, 2);

    return isset($connections[$bcode]) ? $bcode : null;
}

function da_connect_branch(string $bcode, array $connections, int $timeoutSeconds = 6)
{
    $sid = in_array($bcode, ['13', '24', '27'], true) ? 'dbnc' : 'drl';
    $descriptor = "(DESCRIPTION=(CONNECT_TIMEOUT={$timeoutSeconds})(TRANSPORT_CONNECT_TIMEOUT={$timeoutSeconds})"
        . "(ADDRESS=(PROTOCOL=TCP)(HOST={$connections[$bcode]})(PORT=1521))(CONNECT_DATA=(SERVICE_NAME={$sid})))";

    return @oci_connect('drl', 'drl', $descriptor) ?: false;
}

function da_oci_message($resource): string
{
    $e = $resource ? @oci_error($resource) : @oci_error();

    return is_array($e) ? trim($e['message']) : 'Unknown database error';
}

// Rows of one lab no as assoc arrays in DA_COLUMNS order. Throws on a database error.
function da_fetch_rows($bconn, string $labNo): array
{
    $stmt = @oci_parse($bconn, DA_SELECT);
    if (!$stmt || !@oci_bind_by_name($stmt, ':lab', $labNo) || !@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        throw new RuntimeException(da_oci_message($stmt ?: $bconn));
    }
    $rows = [];
    while ($row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS)) {
        $rows[] = array_map(fn($c) => $row[$c], array_combine(DA_COLUMNS, DA_COLUMNS));
    }
    oci_free_statement($stmt);

    return $rows;
}

// Deletes the lab no's rows without committing; returns how many went.
function da_delete_rows($bconn, string $labNo): int
{
    $stmt = @oci_parse($bconn, 'DELETE FROM PIPARAMVDTL WHERE LAB_NO = :lab');
    if (!$stmt || !@oci_bind_by_name($stmt, ':lab', $labNo) || !@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        throw new RuntimeException(da_oci_message($stmt ?: $bconn));
    }
    $n = oci_num_rows($stmt);
    oci_free_statement($stmt);

    return (int) $n;
}

// Appends the rows to the end of the workbook, in the format of the rows already there:
// text in every column except PIPDED (date, d-mmm-yy) and PIPDET (time, h:mm:ss).
// Saves to a temporary file first and swaps it in, so a failed save leaves the old file as it was.
function da_append_to_workbook(array $rows, string $path = DA_WORKBOOK): void
{
    require_once __DIR__ . '/../dengue/vendor/autoload.php';

    if (!is_file($path)) {
        throw new RuntimeException('deleted antibiotics.xlsx is missing from the drl-tools folder');
    }
    $book = IOFactory::load($path);
    $ws   = $book->getSheet(0);

    $last  = $ws->getHighestDataRow();
    $empty = $last === 1 && trim((string) $ws->getCell('A1')->getValue()) === '' && trim((string) $ws->getCell('C1')->getValue()) === '';
    $first = $empty ? 1 : $last + 1;

    $r = $first;
    foreach ($rows as $row) {
        $col = 'A';
        foreach (DA_COLUMNS as $name) {
            $value = $row[$name];
            $cell  = $col . $r;
            if ($value === null || $value === '') {
                // leave the cell empty
            } elseif ($name === 'PIPDED' && ($d = DateTime::createFromFormat('!Y-m-d H:i:s', $value))) {
                $ws->getCell($cell)->setValue(XlsDate::PHPToExcel($d));
            } elseif ($name === 'PIPDET' && preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($value), $m)) {
                $ws->getCell($cell)->setValue(((int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0)) / 86400);
            } else {
                $ws->getCell($cell)->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
            $col++;
        }
        $r++;
    }
    $end = $r - 1;
    $ws->getStyle("A{$first}:A{$end}")->getNumberFormat()->setFormatCode('@');
    $ws->getStyle("J{$first}:J{$end}")->getNumberFormat()->setFormatCode('d-mmm-yy');
    $ws->getStyle("K{$first}:K{$end}")->getNumberFormat()->setFormatCode('h:mm:ss');

    $tmp = $path . '.tmp';
    IOFactory::createWriter($book, 'Xlsx')->save($tmp);
    $book->disconnectWorksheets();
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save deleted antibiotics.xlsx (is it open in Excel on the server?)');
    }
}
