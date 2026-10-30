<?php
// Dengue report: one workbook with the positive cases (Record) and the per-branch counts with a pie
// chart (Count). Same layout as the old dengue app's report.php and count.php, now as two sheets.
// With pdsru=1 it's the PDSRU copy: its own first heading and Technique / Sample Type columns.
require_once __DIR__ . '/../bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;

function fail(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit();
}

if (!is_logged_in()) {
    fail(401, 'Logged out');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Use the form');
}

session_write_close();
set_time_limit(300);
ini_set('memory_limit', '-1');

$from   = (string) ($_POST['from'] ?? '');
$to     = (string) ($_POST['to'] ?? '');
$bcodes = array_values(array_filter((array) ($_POST['bcode'] ?? []), 'is_string'));
$pdsru  = ($_POST['pdsru'] ?? '') === '1';
$isDate = fn($d) => ($t = DateTime::createFromFormat('!Y-m-d', $d)) && $t->format('Y-m-d') === $d;
if (!$isDate($from) || !$isDate($to) || $from > $to) {
    fail(400, 'Pick a From date on or before the To date');
}
foreach ($bcodes as $b) {
    if (!preg_match('/^\d{2}$/', $b)) {
        fail(400, 'Invalid branch');
    }
}

require_once __DIR__ . '/../conn.php';
if (!$conn) {
    fail(500, 'Could not connect to the 253 database');
}

// Dates go in as DD-MON-YY like the old app; both were validated as Y-m-d above.
$startDate = strtoupper(DateTime::createFromFormat('!Y-m-d', $from)->format('d-M-y'));
$endDate   = strtoupper(DateTime::createFromFormat('!Y-m-d', $to)->format('d-M-y'));

// No branch picked means every branch.
$binds   = [];
$inBcode = '';
if ($bcodes) {
    foreach ($bcodes as $i => $b) {
        $binds[":b{$i}"] = $b;
    }
    $inBcode = 'AND pvisit.bcode IN (' . implode(',', array_keys($binds)) . ')';
}

function fetch_all($conn, string $sql, array $binds): array
{
    $stmt = @oci_parse($conn, $sql);
    if ($stmt) {
        foreach ($binds as $name => $value) {
            oci_bind_by_name($stmt, $name, $binds[$name]);
        }
    }
    if (!$stmt || !@oci_execute($stmt)) {
        $e = oci_error($stmt ?: $conn);
        fail(500, $e['message'] ?? 'Query failed');
    }
    $rows = [];
    while ($r = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS)) {
        $rows[] = $r;
    }

    return $rows;
}

// ---- RECORD: positive SER-21 cases ----
$records = [];
$rows = fetch_all($conn, "SELECT DISTINCT pvisit.lab_no,
        piv.pived AS case_reported_date,
        'DR-LUMHS' AS facility,
        pb.pname,
        pb.cell,
        pvisit.age,
        pb.sex,
        branches.bname AS address,
        branches.bname AS district,
        ip.ipdescription || ': ' || pvi.pipvalue AS pipvalue,
        pvisit.visit_date
    FROM patient_bio pb
    JOIN patient_visits pvisit ON pvisit.emr = pb.emr
    JOIN piparamv1 pvi ON pvi.lab_no = pvisit.lab_no
    JOIN piverified piv ON piv.lab_no = pvi.lab_no AND piv.icode = pvi.icode
    JOIN iparameters ip ON pvi.ipcode = ip.ipcode
    JOIN branches ON branches.bcode = pvisit.bcode
    WHERE pvi.icode = 'SER-21'
    $inBcode
    AND pvisit.visit_date BETWEEN '$startDate' AND '$endDate'
    AND UPPER(pvi.pipvalue) LIKE '%POSITIVE%'
    ORDER BY piv.pived ASC", $binds);
foreach ($rows as $i => $row) {
    $records[] = [
        $i + 1,
        date("M j, Y", strtotime($row['CASE_REPORTED_DATE'])),
        $row['FACILITY'],
        $row['PNAME'],
        $row['CELL'],
        $row['AGE'],
        $row['SEX'],
        $row['ADDRESS'],
        $row['DISTRICT'],
        'NA',
        'NA',
        $row['PIPVALUE'],
        ...($pdsru ? ['ELISA', 'Serum'] : []),
        date("M j, Y", strtotime($row['VISIT_DATE'])),
    ];
}

// ---- COUNT: SER-21 tests per branch ----
$counts = [];
$rows = fetch_all($conn, "SELECT branches.bname,
        COUNT(1) AS totalcount,
        COUNT(CASE WHEN UPPER(pvi.pipvalue) LIKE '%POSITIVE%' THEN 1 END) AS positive,
        COUNT(CASE WHEN UPPER(pvi.pipvalue) NOT LIKE '%POSITIVE%' THEN 1 END) AS negative
    FROM patient_bio pb
    JOIN patient_visits pvisit ON pvisit.emr = pb.emr
    JOIN piparamv1 pvi ON pvi.lab_no = pvisit.lab_no
    JOIN piverified piv ON piv.lab_no = pvi.lab_no AND piv.icode = pvi.icode
    JOIN iparameters ip ON pvi.ipcode = ip.ipcode
    JOIN branches ON branches.bcode = pvisit.bcode
    WHERE pvi.icode = 'SER-21'
    $inBcode
    AND pvisit.visit_date BETWEEN '$startDate' AND '$endDate'
    GROUP BY branches.bname", $binds);
foreach ($rows as $i => $row) {
    $counts[] = [$i + 1, $row['BNAME'], (int) $row['TOTALCOUNT'], (int) $row['POSITIVE'], (int) $row['NEGATIVE']];
}

// ---- WORKBOOK ----
require __DIR__ . '/vendor/autoload.php';

$startYear  = date("Y", strtotime($from));
$endYear    = date("Y", strtotime($to));
$reportYear = $startYear === $endYear ? $startYear : $startYear . " - " . $endYear;

$titles = [
    $pdsru ? "Provisional Disease Surveillance Response Unit (PDSRU)" : "Vector Borne Diseases (VBDs)",
    "Directorate General Health Services Sindh, Hyderabad",
    "Health Department, Government of Sindh",
    "Dengue Cases Line Listing Report " . $reportYear,
];

// "a" or "a - b" when the two differ.
$span = fn(string $format) => date($format, strtotime($from)) === date($format, strtotime($to))
    ? date($format, strtotime($from))
    : date($format, strtotime($from)) . " - " . date($format, strtotime($to));

function add_logo(Worksheet $sheet): void
{
    $sheet->mergeCells("A1:D1");
    $drawing = new Drawing();
    $drawing->setName('Logo');
    $drawing->setDescription('Government of Sindh Logo');
    $drawing->setPath(__DIR__ . '/image.png');
    $drawing->setHeight(60);
    $drawing->setCoordinates('A1');
    $drawing->setOffsetX(5);
    $drawing->setOffsetY(5);
    $drawing->setWorksheet($sheet);
}

// Writes records into column groups [start, end, label] from $row on, merging multi-column groups.
function write_rows(Worksheet $sheet, array $headers, array $data, int $row): void
{
    foreach ($data as $record) {
        foreach ($headers as $colIndex => [$startCol, $endCol]) {
            if ($startCol !== $endCol) {
                $sheet->mergeCells("{$startCol}{$row}:{$endCol}{$row}");
            }
            $sheet->setCellValue("{$startCol}{$row}", $record[$colIndex] ?? "");
        }
        $row++;
    }
}

function write_header(Worksheet $sheet, array $headers, int $headerRow): void
{
    foreach ($headers as [$start, $end, $label]) {
        if ($start !== $end) {
            $sheet->mergeCells("{$start}{$headerRow}:{$end}{$headerRow}");
        }
        $sheet->setCellValue("{$start}{$headerRow}", $label);
    }
}

$spreadsheet = new Spreadsheet();

// ---- Record sheet ----
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Record");
$sheet->setShowGridlines(false);
add_logo($sheet);

// The PDSRU copy has two more columns, so Entered Date and the headings reach two columns further.
$lastCol = $pdsru ? 'T' : 'R';
foreach ($titles as $index => $text) {
    $row = 1 + $index;
    $sheet->mergeCells($pdsru ? "H{$row}:S{$row}" : "H{$row}:Q{$row}");
    $sheet->setCellValue("H{$row}", $text);
    $sheet->getStyle("H{$row}")->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_TOP);
    $sheet->getStyle("H{$row}")->getFont()
        ->setName('Calibri')
        ->setSize(14)
        ->setBold(true);
}

$sheet->mergeCells("A6:B6");
$sheet->setCellValue("A6", "Date:");
$sheet->getStyle("A6:B6")->applyFromArray(['font' => ['name' => 'Calibri', 'size' => 9]]);
$sheet->mergeCells("C6:D6");
$sheet->setCellValue("C6", $span("n/j/y"));
$sheet->getStyle("C6:D6")->applyFromArray(['font' => ['name' => 'Tahoma', 'size' => 10]]);

$headers = [
    ["A", "A", "Sno"],
    ["B", "C", "Case Reported Date"],
    ["D", "E", "Facility"],
    ["F", "H", "Patient Name"],
    ["I", "I", "Mobile Number"],
    ["J", "J", "Age"],
    ["K", "K", "Gender"],
    ["L", "L", "Address"],
    ["M", "M", "District"],
    ["N", "N", "Taluka"],
    ["O", "O", "UC"],
    ["P", "P", "Outcome of Disease"],
    ...($pdsru ? [["Q", "Q", "Technique"], ["R", "R", "Sample Type"], ["S", "T", "Entered Date"]] : [["Q", "R", "Entered Date"]]),
];
$headerRow = 8;
write_header($sheet, $headers, $headerRow);
$sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
    'font' => ['color' => ['rgb' => 'FFFFFF'], 'name' => 'Tahoma', 'size' => 8],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '138496']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '777777']]],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(20.88);

$row = 9;
$lastRow = $row + count($records) - 1;
if ($records) {
    $sheet->getStyle("A{$row}:{$lastCol}{$lastRow}")->applyFromArray([
        'font' => ['name' => 'Tahoma', 'size' => 8],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '777777']]],
    ]);
    $sheet->getStyle("A{$row}:A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle("J{$row}:K{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    for ($r = $row; $r <= $lastRow; $r++) {
        $sheet->getRowDimension($r)->setRowHeight(20.88);
    }
    write_rows($sheet, $headers, $records, $row);
}

$widths = [
    'A' => 4.57,
    'B' => 6.14,
    'C' => 9.88,
    'D' => 5.33,
    'E' => 4.57,
    'F' => 2.22,
    'G' => 1.57,
    'H' => 18.22,
    'I' => 13.74,
    'J' => 4.57,
    'K' => 6.88,
    'L' => 15.94,
    'M' => 11.44,
    'N' => 6.14,
    'O' => 3.88,
    'P' => 14.44,
    'Q' => 14.44,
    'R' => 0.74
];
if ($pdsru) {
    $widths = array_merge($widths, ['R' => 14.44, 'S' => 14.44, 'T' => 0.78]);
}
foreach ($widths as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

// ---- Count sheet ----
$sheet = $spreadsheet->createSheet();
$sheet->setTitle("Count");
add_logo($sheet);

for ($r = 1; $r <= 4; $r++) {
    $sheet->getRowDimension($r)->setRowHeight(21);
}
foreach ($titles as $index => $text) {
    $row = 1 + $index;
    $sheet->mergeCells("E{$row}:G{$row}");
    $sheet->setCellValue("E{$row}", $text);
    $sheet->getStyle("E{$row}")->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_BOTTOM);
    $sheet->getStyle("E{$row}")->getFont()
        ->setName('Calibri')
        ->setSize(14)
        ->setBold(true);
}

$sheet->mergeCells("A5:C5");
$sheet->setCellValue("A5", "Date " . $span("d-M-y"));
$sheet->getStyle("A5:C5")->applyFromArray([
    'font' => ['name' => 'Calibri', 'size' => 11],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_BOTTOM],
]);

$headers = [
    ["A", "A", ""],
    ["B", "D", "Branches"],
    ["E", "E", "Total Number of Cases"],
    ["F", "F", "Positive Cases"],
    ["G", "G", "Negative Cases"],
];
$headerRow = 6;
write_header($sheet, $headers, $headerRow);
$sheet->getStyle("A{$headerRow}:G{$headerRow}")->applyFromArray([
    'font' => ['color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri', 'size' => 12, 'bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '138496']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getStyle("A{$headerRow}:D{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension($headerRow)->setRowHeight(30);

$row = 7;
$lastRow = $row + count($counts) - 1;
if ($counts) {
    $sheet->getStyle("A{$row}:G{$lastRow}")->applyFromArray([
        'font' => ['name' => 'Calibri', 'size' => 11],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_BOTTOM, 'wrapText' => true],
    ]);
    $sheet->getStyle("F{$row}:F{$lastRow}")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'ff0000']],
        'font' => ['color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri', 'size' => 11, 'bold' => true],
    ]);
    $sheet->getStyle("G{$row}:G{$lastRow}")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '00b050']],
        'font' => ['color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri', 'size' => 11, 'bold' => true],
    ]);
    for ($r = $row; $r <= $lastRow; $r++) {
        $sheet->getRowDimension($r)->setRowHeight(15);
    }
    write_rows($sheet, $headers, $counts, $row);
}

// Pie of the totals, with the values written into the chart rather than pointing at cells.
$total = array_sum(array_column($counts, 2));
$positive = array_sum(array_column($counts, 3));
$negative = array_sum(array_column($counts, 4));

$labels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 3, ['Total', 'Positive Cases', 'Negative Cases'])];
$values = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, null, null, 3, [$total, $positive, $negative])];
$series = new DataSeries(DataSeries::TYPE_PIECHART, null, range(0, count($values) - 1), [], $labels, $values);

$layout = new Layout();
$layout->setShowVal(true);
$layout->setShowPercent(true);
$layout->setShowCatName(true);
$layout->setShowLegendKey(false);

$chart = new Chart('pie_chart', null, new Legend(Legend::POSITION_RIGHT, null, false), new PlotArea($layout, [$series]), true, 0, null, null);
$chart->setTopLeftPosition('D' . ($lastRow + 4), 2);
$chart->setBottomRightPosition('G' . ($lastRow + 24), 90);
$sheet->addChart($chart);

foreach (['A' => 9.00, 'B' => 9.00, 'C' => 9.00, 'D' => 9.00, 'E' => 26.44, 'F' => 20.74, 'G' => 20.94] as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

$spreadsheet->setActiveSheetIndex(0);

// ---- OUTPUT ----
$writer = new Xlsx($spreadsheet);
$writer->setIncludeCharts(true);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . ($pdsru ? 'PDSRU%20' : '') . 'Dengue%20Report%20(' . rawurlencode($span("j-M-y")) . ').xlsx');
$writer->save("php://output");
exit();
