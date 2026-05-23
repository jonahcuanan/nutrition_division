<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentRole = $_SESSION['role'] ?? '';
$isBns = ($currentRole === 'Barangay Nutrition Scholars');
$assignedBarangayId = isset($_SESSION['barangay_id']) ? (int)$_SESSION['barangay_id'] : 0;

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/growth_utils.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Protection;

// 1. Gather Selected Child IDs securely
$childIdsInput = $_POST['child_ids'] ?? $_GET['child_ids'] ?? '';
$childIds = array_filter(array_map('intval', explode(',', $childIdsInput)));

// Security filter: BNS, HW, and Staff can only export child profiles they have access to
$childIds = array_filter($childIds, function($id) use ($conn) {
    return verify_child_barangay_access($conn, $id);
});

if (empty($childIds)) {
    die("No valid children selected or access denied.");
}

// Log activity transaction
log_user_activity($conn, $currentUserId, 'export_excel', 'Exported ' . count($childIds) . ' child profiles to Excel.');


// 2. Determine Cutoff Period Logic (clear measurement logic)
$sessionFile = __DIR__ . '/measurement_session.txt';
$cutoffRecordId = file_exists($sessionFile) ? (int)trim(file_get_contents($sessionFile)) : 0;
$periodIsNew = false;
if (file_exists($sessionFile)) {
    clearstatcache(true, $sessionFile);
    $mtime = filemtime($sessionFile);
    if (date('Y-m', $mtime) === date('Y-m')) {
        $hasClearThisMonth = false;
        $clearSql = "SELECT 1 FROM user_activity_log WHERE activity_type = 'clear_measurements' AND DATE_FORMAT(activity_time, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m') LIMIT 1";
        if ($clearRes = $conn->query($clearSql)) {
            $hasClearThisMonth = ($clearRes->num_rows > 0);
        }
        if ($hasClearThisMonth) {
            $periodIsNew = true;
        } else {
            $cutoffRecordId = 0;
        }
    } else {
        $cutoffRecordId = 0;
    }
}
if ($cutoffRecordId > 0) {
    $maxQuery = $conn->query("SELECT MAX(record_id) FROM growth_records");
    if ($maxQuery) {
        $maxRow = $maxQuery->fetch_row();
        $maxId = $maxRow ? (int)$maxRow[0] : 0;
        if ($cutoffRecordId > $maxId) {
            $cutoffRecordId = 0;
        }
    }
}

// Helper formatting functions
function status_abbrev($status) {
    $value = strtolower(trim((string)$status));
    if ($value === '' || $value === '—' || $value === 'n/a') {
        return 'N/A';
    }
    if ($value === 'out of range') {
        return 'OOR';
    }

    $map = [
        'severely underweight' => 'SUW',
        'underweight' => 'UW',
        'normal' => 'N',
        'severely stunted' => 'SSt',
        'stunted' => 'St',
        'tall' => 'T',
        'severely wasted' => 'SW',
        'moderately wasted' => 'MW',
        'wasted' => 'W',
        'overweight' => 'OW',
        'obese' => 'Ob',
    ];

    if (isset($map[$value])) {
        return $map[$value];
    }

    foreach ($map as $key => $abbr) {
        if (strpos($value, $key) !== false) {
            return $abbr;
        }
    }
    return strtoupper((string)$status);
}

function get_status_color($status_abbr) {
    $abbr = strtolower(trim((string)$status_abbr));
    if (in_array($abbr, ['suw', 'sst', 'sw'], true)) {
        return 'FF0000'; // Red
    }
    if (in_array($abbr, ['uw', 'st', 'w', 'mw'], true)) {
        return 'FFFF00'; // Yellow
    }
    if (in_array($abbr, ['ow', 'ob'], true)) {
        return 'FFC000'; // Orange
    }
    if (in_array($abbr, ['n', 't'], true)) {
        return '00FF00'; // Green
    }
    if ($abbr === 'oor') {
        return 'EEF2F7'; // Light gray
    }
    return 'FAFCFD'; // White-ish N/A
}

function build_display_name(string $first = '', string $middle = '', string $last = '', string $suffix = ''): string
{
    $first = trim($first);
    $middle = trim($middle);
    $last = trim($last);
    $suffix = trim($suffix);

    if ($last !== '') {
        $parts = [$last . ',', $first];
    } else {
        $parts = [$first];
    }
    if ($middle !== '') {
        $parts[] = $middle;
    }
    if ($suffix !== '') {
        $parts[] = $suffix;
    }

    return trim(implode(' ', array_filter($parts)));
}

// 3. Fetch Selected Child Records
$idsPlaceholder = implode(',', $childIds);
$sql = "SELECT c.*, b.barangay_name, b.city, b.province, g.first_name AS guardian_first, g.middle_name AS guardian_middle, g.last_name AS guardian_last, g.suffix AS guardian_suffix,
               gr.record_id AS latest_record_id, gr.height AS latest_height, gr.weight AS latest_weight,
               gr.muac_measurement AS latest_muac, gr.muac_id AS latest_muac_id,
               gr.measurement_date AS latest_measurement_date
        FROM children c
        LEFT JOIN barangays b ON c.barangay_id = b.barangay_id
        LEFT JOIN guardians g ON c.guardian_id = g.guardian_id
        LEFT JOIN growth_records gr ON gr.record_id = (
            SELECT gr2.record_id
            FROM growth_records gr2
            WHERE gr2.child_id = c.child_id
            ORDER BY gr2.measurement_date DESC, gr2.record_id DESC
            LIMIT 1
        )
        WHERE c.child_id IN ($idsPlaceholder)
        ORDER BY b.barangay_name ASC, c.last_name ASC, c.first_name ASC";

$result = $conn->query($sql);
$rows = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Apply Cutoff Measurement Period logic
        if ($cutoffRecordId > 0 && $row['latest_record_id'] <= $cutoffRecordId) {
            $row['latest_measurement_date'] = null;
            $row['latest_height'] = null;
            $row['latest_weight'] = null;
            $row['latest_muac'] = null;
            $row['latest_record_id'] = null;
        }

        // Setup defaults
        $row['wfa'] = 'N/A';
        $row['hfa'] = 'N/A';
        $row['wflh'] = 'N/A';
        $row['muac_status'] = 'N/A';
        $row['age_months_calc'] = '';

        $ageMonths = null;
        if (!empty($row['birthdate']) && !empty($row['latest_measurement_date'])) {
            try {
                $b = new DateTime($row['birthdate']);
                $m = new DateTime($row['latest_measurement_date']);
                if ($m >= $b) {
                    $diff = $b->diff($m);
                    $ageMonths = ($diff->y * 12) + $diff->m;
                    if ($ageMonths < 0) $ageMonths = 0;
                }
            } catch (Exception $e) { $ageMonths = null; }
        }

        $currentAgeMonths = null;
        if (!empty($row['birthdate'])) {
            try {
                $b = new DateTime($row['birthdate']);
                $now = new DateTime('today');
                $diff = $b->diff($now);
                $currentAgeMonths = ($diff->y * 12) + $diff->m;
                if ($currentAgeMonths < 0) $currentAgeMonths = 0;
            } catch (Exception $e) { $currentAgeMonths = null; }
        }

        $row['age_months_calc'] = $ageMonths !== null ? $ageMonths : ($currentAgeMonths !== null ? $currentAgeMonths : '');

        // Calculate statuses
        if ($ageMonths !== null && $row['latest_height'] > 0 && $row['latest_weight'] > 0) {
            $normalizedSex = ucfirst(strtolower($row['sex']));
            $oorW = false; $oorH = false; $oorL = false;
            
            $weightRef = fetchClosestGrowthReference($conn, GROWTH_WEIGHT_TABLE, $ageMonths, $normalizedSex, $oorW);
            $heightRef = fetchClosestGrowthReference($conn, GROWTH_HEIGHT_TABLE, $ageMonths, $normalizedSex, $oorH);
            $wflAgeGroup = resolveWeightForLengthAgeGroup((int)$ageMonths);
            $wflRef = null;
            if ($wflAgeGroup === null) {
                $oorL = true;
            } else {
                $wflRef = fetchWeightForLengthReferenceByAgeGroup($conn, $normalizedSex, $wflAgeGroup, (float)$row['latest_height'], $oorL);
            }

            $row['wfa'] = $weightRef ? (determineWeightForAgeStatus((float)$row['latest_weight'], $weightRef) ?? 'N/A') : 'N/A';
            $row['hfa'] = $heightRef ? (determineHeightForAgeStatus((float)$row['latest_height'], $heightRef) ?? 'N/A') : 'N/A';
            $row['wflh'] = $wflRef ? (determineWeightForLengthStatus((float)$row['latest_weight'], $wflRef) ?? 'N/A') : 'N/A';
        }

        if ($row['latest_muac'] > 0) {
            $row['muac_status'] = determineMuacStatus((float)$row['latest_muac']) ?? 'N/A';
        }

        $rows[] = $row;
    }
}

// 4. Initialize PhpSpreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('e-OPT Child Profiles');

// 6. Build 2-Row Table Header matching the print page layout
// --- ROW 1: group labels + individual columns that span both rows ---
$sheet->setCellValue('A1', "Child\nSeq.");
$sheet->mergeCells('A1:A2');

$sheet->setCellValue('B1', "Address or Location\nof Child's Residence\n(if Purok, Area or Location in\nthe Barangay)");
$sheet->mergeCells('B1:B2');

$sheet->setCellValue('C1', 'Barangay');
$sheet->mergeCells('C1:C2');

$sheet->setCellValue('D1', "Name of Mother / Caregiver\n(Last Name, First Name, Middle Name, Suffix)");
$sheet->mergeCells('D1:D2');

$sheet->setCellValue('E1', "Full Name of Child\n(Last Name, First Name, Middle Name, Suffix)");
$sheet->mergeCells('E1:E2');

$sheet->setCellValue('F1', "Belongs to a\nGroup?\nYES/NO");
$sheet->mergeCells('F1:F2');

$sheet->setCellValue('G1', "Sex\nM/F");
$sheet->mergeCells('G1:G2');

// Red group label: MEASUREMENT INFORMATION (spans H–I)
$sheet->setCellValue('H1', "MEASUREMENT INFORMATION AT FORMAL ENTRY: PLS READ");
$sheet->mergeCells('H1:I1');

$sheet->setCellValue('J1', "Weight\n(kg)");
$sheet->mergeCells('J1:J2');

$sheet->setCellValue('K1', "Height\n(cm)");
$sheet->mergeCells('K1:K2');

// Red group label: NO DATA ENTRY REQUIRED (spans L–Q)
$sheet->setCellValue('L1', "NO DATA ENTRY REQUIRED - AUTOMATIC RESULTS CALCULATION");
$sheet->mergeCells('L1:Q1');

// --- ROW 2: sub-headers for the two red groups ---
$sheet->setCellValue('H2', 'Date of Birth');
$sheet->setCellValue('I2', 'Date Measured');
$sheet->setCellValue('L2', 'Age in Months');
$sheet->setCellValue('M2', "Weight for\nAge Status");
$sheet->setCellValue('N2', "Height for\nAge Status");
$sheet->setCellValue('O2', "Weight for\nL/HT Status");
$sheet->setCellValue('P2', "MUAC\n(cm)");
$sheet->setCellValue('Q2', "MUAC\nStatus");

// Style: individual columns (white bg, black text, black border)
$headerStyleSingle = [
    'font' => [
        'bold'  => true,
        'size'  => 9,
        'color' => ['argb' => 'FF000000'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER,
        'wrapText'   => true,
    ],
    'fill' => [
        'fillType'   => Fill::FILL_SOLID,
        'startColor' => ['argb' => 'FFFFFFFF'],
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color'       => ['argb' => 'FF000000'],
        ],
    ],
];

// Style: red group labels (red bg, white bold text, black border)
$headerStyleGroup = [
    'font' => [
        'bold'  => true,
        'size'  => 9,
        'color' => ['argb' => 'FFFFFFFF'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER,
        'wrapText'   => true,
    ],
    'fill' => [
        'fillType'   => Fill::FILL_SOLID,
        'startColor' => ['argb' => 'FFC0392B'], // Crimson red matching print page
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color'       => ['argb' => 'FF000000'],
        ],
    ],
];

// Apply styles
$sheet->getStyle('A1:G2')->applyFromArray($headerStyleSingle);
$sheet->getStyle('H1:I1')->applyFromArray($headerStyleGroup);
$sheet->getStyle('H2:I2')->applyFromArray($headerStyleSingle);
$sheet->getStyle('J1:K2')->applyFromArray($headerStyleSingle);
$sheet->getStyle('L1:Q1')->applyFromArray($headerStyleGroup);
$sheet->getStyle('L2:Q2')->applyFromArray($headerStyleSingle);

// Row heights
$sheet->getRowDimension(1)->setRowHeight(60);
$sheet->getRowDimension(2)->setRowHeight(30);

// 7. Write Data Rows (starts at row 3 — right after 2-row header)
$startRow = 3;
$currentRow = $startRow;
$seq = 1;

$borderStyleData = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['argb' => 'FF000000'],
        ],
    ],
];

foreach ($rows as $row) {
    // Basic Information
    $sheet->setCellValue('A' . $currentRow, $seq++);
    
    $address = trim((string)($row['address'] ?? ''));
    if ($address === '') {
        $address = $row['barangay_name'] ?? '';
    }
    $sheet->setCellValue('B' . $currentRow, $address);
    $sheet->setCellValue('C' . $currentRow, $row['barangay_name'] ?? '');
    
    $motherName = build_display_name($row['guardian_first'] ?? '', $row['guardian_middle'] ?? '', $row['guardian_last'] ?? '', $row['guardian_suffix'] ?? '');
    $sheet->setCellValue('D' . $currentRow, $motherName !== '' ? $motherName : '—');

    $childName = build_display_name($row['first_name'] ?? '', $row['middle_name'] ?? '', $row['last_name'] ?? '', $row['suffix'] ?? '');
    $sheet->setCellValue('E' . $currentRow, $childName !== '' ? $childName : '—');
    
    $sheet->setCellValue('F' . $currentRow, ($row['is_ip'] === 'Yes') ? 'YES' : 'NO');
    $sheet->setCellValue('G' . $currentRow, ($row['sex'] === 'Male') ? 'M' : (($row['sex'] === 'Female') ? 'F' : '—'));
    
    // Dates in system format (mmm-dd-yyyy) and typed as Excel dates with time stripped completely
    if (!empty($row['birthdate']) && $row['birthdate'] !== '0000-00-00') {
        $dobDate = new DateTime($row['birthdate']);
        $dobDate->setTime(0, 0, 0);
        $dobExcel = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($dobDate);
        $sheet->setCellValue('H' . $currentRow, $dobExcel);
        $sheet->getStyle('H' . $currentRow)->getNumberFormat()->setFormatCode('mmm-dd-yyyy');
    } else {
        $sheet->setCellValue('H' . $currentRow, '');
    }
    
    if (!empty($row['latest_measurement_date']) && $row['latest_measurement_date'] !== '0000-00-00') {
        $domDate = new DateTime($row['latest_measurement_date']);
        $domDate->setTime(0, 0, 0);
        $domExcel = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($domDate);
        $sheet->setCellValue('I' . $currentRow, $domExcel);
        $sheet->getStyle('I' . $currentRow)->getNumberFormat()->setFormatCode('mmm-dd-yyyy');
    } else {
        $sheet->setCellValue('I' . $currentRow, '');
    }
    
    // Measurements
    $sheet->setCellValue('J' . $currentRow, $row['latest_weight'] > 0 ? (float)$row['latest_weight'] : '—');
    $sheet->setCellValue('K' . $currentRow, $row['latest_height'] > 0 ? (float)$row['latest_height'] : '—');
    
    // Age in Months (Auto-calculated via Excel formula)
    $sheet->setCellValue('L' . $currentRow, '=IF(OR(H' . $currentRow . '="", I' . $currentRow . '=""), "", DATEDIF(H' . $currentRow . ', I' . $currentRow . ', "M"))');
    
    // Statuses
    $wfaShort = status_abbrev($row['wfa']);
    $hfaShort = status_abbrev($row['hfa']);
    $wflShort = status_abbrev($row['wflh']);
    $muacShort = status_abbrev($row['muac_status']);
    
    $sheet->setCellValue('M' . $currentRow, $wfaShort);
    $sheet->setCellValue('N' . $currentRow, $hfaShort);
    $sheet->setCellValue('O' . $currentRow, $wflShort);
    
    $sheet->setCellValue('P' . $currentRow, $row['latest_muac'] > 0 ? (float)$row['latest_muac'] : '—');
    $sheet->setCellValue('Q' . $currentRow, $muacShort);
    
    // Style alignments (perfect vertical center, horizontal center for all status results & measurements)
    $sheet->getStyle("A$currentRow:Q$currentRow")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("A$currentRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("F$currentRow:Q$currentRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Apply borders to row
    $sheet->getStyle("A$currentRow:Q$currentRow")->applyFromArray($borderStyleData);
    $sheet->getRowDimension($currentRow)->setRowHeight(20);
    
    // Color code Status columns: M, N, O, Q
    $statusesToColor = [
        'M' => $wfaShort,
        'N' => $hfaShort,
        'O' => $wflShort,
        'Q' => $muacShort
    ];
    
    foreach ($statusesToColor as $col => $statusAbbr) {
        $colorHex = get_status_color($statusAbbr);
        if ($colorHex !== 'FFFFFF') {
            $sheet->getStyle($col . $currentRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF' . $colorHex);
            $sheet->getStyle($col . $currentRow)->getFont()->setBold(true);
        }
    }
    
    $currentRow++;
}

$lastDataRow = $currentRow - 1;

// 8. Auto-fit columns with premium, robust spacing to prevent clipping
$sheet->calculateColumnWidths();
foreach (range('A', 'Q') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
$sheet->calculateColumnWidths();

// Define specific minimum widths for columns to ensure gorgeous headers and data display
$minWidths = [
    'A' => 10,  // Child Seq.
    'B' => 28,  // Address
    'C' => 16,  // Barangay
    'D' => 24,  // Mother
    'E' => 24,  // Child
    'F' => 12,  // Group
    'G' => 10,  // Sex
    'H' => 15,  // DOB
    'I' => 15,  // Date Measured
    'J' => 12,  // Weight
    'K' => 12,  // Height
    'L' => 14,  // Age in Months
    'M' => 14,  // WFA
    'N' => 14,  // HFA
    'O' => 14,  // WFLH
    'P' => 12,  // MUAC (cm)
    'Q' => 14,  // MUAC Status
];

foreach ($minWidths as $col => $minW) {
    $calculatedW = $sheet->getColumnDimension($col)->getWidth();
    if ($calculatedW < $minW) {
        $sheet->getColumnDimension($col)->setAutoSize(false);
        $sheet->getColumnDimension($col)->setWidth($minW);
    }
}

// 9. Add AutoFilter on Barangay column only (Column C)
if ($lastDataRow >= $startRow) {
    $sheet->setAutoFilter("C1:C$lastDataRow");
    
    // Enable Sheet Protection
    $sheet->getProtection()->setSheet(true);
    
    // Unlock editable cell ranges (Columns A to K, and Column P)
    $sheet->getStyle("A$startRow:K$lastDataRow")->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
    $sheet->getStyle("P$startRow:P$lastDataRow")->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
    
    // Hide formulas/values for automatic status results from the formula bar (Columns L to O, and Column Q)
    $sheet->getStyle("L$startRow:O$lastDataRow")->getProtection()->setHidden(Protection::PROTECTION_PROTECTED);
    $sheet->getStyle("Q$startRow:Q$lastDataRow")->getProtection()->setHidden(Protection::PROTECTION_PROTECTED);
}

// 10. Clear buffers and initiate download response
if (ob_get_level()) {
    ob_end_clean();
}

$filename = 'Nut_StatusTool_' . date('Y') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Cache-Control: max-age=1'); // Excel compatibility
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
header('Pragma: public'); // HTTP/1.0

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
