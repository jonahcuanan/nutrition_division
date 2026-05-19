<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/growth_utils.php';
require_once __DIR__ . '/activity_logger.php';

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentRole = $_SESSION['role'] ?? '';
$isAdmin = ($currentRole === 'Admin');
$isHw = ($currentRole === 'Health Worker');
$isBns = ($currentRole === 'Barangay Nutrition Scholars');
$isStaff = ($currentRole === 'Staff');
$assignedBarangayId = isset($_SESSION['barangay_id']) ? (int)$_SESSION['barangay_id'] : 0;

// Get list of barangays for dropdown
$barangays = [];
if ($isAdmin || $isStaff) {
    $sql = "SELECT barangay_id, barangay_name, city, province FROM barangays ORDER BY barangay_name ASC";
} elseif (($isHw || $isBns) && $assignedBarangayId > 0) {
    // Health Workers and Barangay Nutrition Scholars can only see their assigned barangay
    $sql = "SELECT barangay_id, barangay_name, city, province FROM barangays WHERE barangay_id = ? ORDER BY barangay_name ASC";
} else {
    $sql = "SELECT DISTINCT b.barangay_id, b.barangay_name, b.city, b.province FROM barangays b ORDER BY b.barangay_name ASC";
}

$stmt = $conn->prepare($sql);
if (($isHw || $isBns) && $assignedBarangayId > 0) {
    $stmt->bind_param('i', $assignedBarangayId);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $barangays[] = $row;
}
$stmt->close();

$reportData = null;
$form1bData = null;
$selectedBarangay = null;
$selectedReportType = $_POST['report_type'] ?? '';
$selectedYear = isset($_POST['report_year']) ? (int)$_POST['report_year'] : (int)date('Y');
$selectedMonthFrom = isset($_POST['month_from']) && $_POST['month_from'] !== '' ? (int)$_POST['month_from'] : null;
$selectedMonthTo = isset($_POST['month_to']) && $_POST['month_to'] !== '' ? (int)$_POST['month_to'] : null;
$bodyPrintClass = '';

// Process form submission
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['report_type'])
    && in_array($_POST['report_type'], ['summary', 'opt_form1a', 'nut_status', 'opt_form1b'], true)
    && isset($_POST['barangay_id'])
) {
    $selectedBarangayId = (int)$_POST['barangay_id'];
    
    // Security check: Health Workers and Barangay Nutrition Scholars can only generate reports for their assigned barangay
    if (($isHw || $isBns) && $assignedBarangayId > 0 && $selectedBarangayId !== $assignedBarangayId) {
        $selectedBarangayId = $assignedBarangayId;
    }
    $monthFrom = $selectedMonthFrom;
    $monthTo = $selectedMonthTo;

    if ($monthFrom !== null && $monthTo === null) {
        $monthTo = $monthFrom;
    } elseif ($monthTo !== null && $monthFrom === null) {
        $monthFrom = $monthTo;
    } elseif ($monthFrom !== null && $monthTo !== null && $monthFrom > $monthTo) {
        $tmp = $monthFrom;
        $monthFrom = $monthTo;
        $monthTo = $tmp;
    }
    $selectedMonthFrom = $monthFrom;
    $selectedMonthTo = $monthTo;
    
    // Fetch barangay info
    $stmtBarangay = $conn->prepare("SELECT barangay_id, barangay_name, city, province, total_population, estimated_children_measured, psgc FROM barangays WHERE barangay_id = ?");
    $stmtBarangay->bind_param('i', $selectedBarangayId);
    $stmtBarangay->execute();
    $barangayResult = $stmtBarangay->get_result();
    if ($selectedBarangay = $barangayResult->fetch_assoc()) {
    // Generate report data
        $reportData = generateSummaryReport($conn, $selectedBarangayId, $monthFrom, $monthTo, $selectedYear, $isBns, $currentUserId);

        // Log this report generation with specific details
        $reportTypeLabels = [
            'summary'    => 'Summary (OPT Form 1)',
            'opt_form1a' => 'OPT Form 1A',
            'nut_status' => 'Nut Status Report',
            'opt_form1b' => 'OPT Form 1B'
        ];
        $reportTypeLabel = $reportTypeLabels[$_POST['report_type']] ?? ucfirst($_POST['report_type']);
        if ($monthFrom !== null && $monthTo !== null) {
            if ($monthFrom === $monthTo) {
                $monthLabel = date('F', mktime(0, 0, 0, $monthFrom, 1));
            } else {
                $fromLabel = date('F', mktime(0, 0, 0, $monthFrom, 1));
                $toLabel = date('F', mktime(0, 0, 0, $monthTo, 1));
                $monthLabel = $fromLabel . ' to ' . $toLabel;
            }
        } else {
            $monthLabel = 'All months';
        }
        $logDetails      = 'Generated ' . $reportTypeLabel . ' report for Brgy. '
                           . ($selectedBarangay['barangay_name'] ?? '') . ', ' . $monthLabel . ' ' . $selectedYear;
        log_user_activity($conn, $currentUserId, 'generate_report', $logDetails);
    }
    $stmtBarangay->close();
}

function status_cell_class($status) {
    $abbr = strtolower(status_abbrev($status));
    if ($abbr === 'n/a') return 'status-na';
    if ($abbr === 'oor') return 'status-oor';
    if (in_array($abbr, ['suw', 'sst', 'sw'], true)) return 'status-severe';
    if (in_array($abbr, ['uw', 'st', 'w', 'mw'], true)) return 'status-moderate';
    if (in_array($abbr, ['ow', 'ob'], true)) return 'status-over';
    if (in_array($abbr, ['n', 't'], true)) return 'status-normal';
    return 'status-na';
}

function status_abbrev($status) {
    $value = strtolower(trim((string)$status));
    if ($value === '' || $value === '—' || $value === 'n/a') return 'N/A';
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
    if (isset($map[$value])) return $map[$value];
    foreach ($map as $key => $abbr) {
        if (strpos($value, $key) !== false) return $abbr;
    }
    return strtoupper((string)$status);
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

function resolve_wflh_abbrev(?string $status): string
{
    $value = strtolower(trim((string)$status));
    if ($value === '' || $value === '—' || $value === 'n/a') return 'N/A';
    if ($value === 'severely wasted') return 'SW';
    if ($value === 'wasted') return 'MW';
    if ($value === 'normal') return 'N';
    if ($value === 'overweight') return 'OW';
    if ($value === 'obese') return 'Ob';
    return strtoupper((string)$status);
}


function generateSummaryReport($conn, $barangayId, $monthFrom = null, $monthTo = null, $year = null, $isBns = false, $userId = null) {
    // Fetch all children with their latest measurements for this barangay
    $innerConditions = [];
    if ($monthFrom !== null && $monthTo !== null) $innerConditions[] = "MONTH(measurement_date) BETWEEN ? AND ?";
    if ($year !== null) $innerConditions[] = "YEAR(measurement_date) = ?";
    
    $innerQueryExt = !empty($innerConditions) ? " WHERE " . implode(" AND ", $innerConditions) : "";
    $sql = "
        SELECT 
            c.child_id,
            c.first_name,
            c.middle_name,
            c.last_name,
            c.sex,
            c.birthdate,
            c.is_ip,
            c.address,
            g.first_name as g_first,
            g.middle_name as g_middle,
            g.last_name as g_last,
            g.suffix as g_suffix,
            gr.measurement_date,
            gr.weight,
            gr.height
        FROM children c
        LEFT JOIN guardians g ON c.guardian_id = g.guardian_id
        LEFT JOIN (
            SELECT child_id, measurement_date, weight, height,
                   ROW_NUMBER() OVER (PARTITION BY child_id ORDER BY measurement_date DESC) as rn
            FROM growth_records
            $innerQueryExt
        ) gr ON c.child_id = gr.child_id AND gr.rn = 1
        WHERE c.barangay_id = ? AND c.status = 'Active'
    ";
    
    // For BNS users, only show children they've recorded measurements for
    if ($isBns && $userId !== null) {
        $sql .= " AND EXISTS (SELECT 1 FROM growth_records grb WHERE grb.child_id = c.child_id AND grb.recorded_by = ?)";
    }
    
    if ($monthFrom !== null && $monthTo !== null) {
        $sql .= " AND MONTH(gr.measurement_date) BETWEEN ? AND ?";
    }
    if ($year !== null) {
        $sql .= " AND YEAR(gr.measurement_date) = ?";
    }
    
    $sql .= " ORDER BY c.first_name, c.last_name";
    
    $stmt = $conn->prepare($sql);
    
    // Determine bind parameters
    $params = [];
    $types = "";
    
    if ($monthFrom !== null && $monthTo !== null) { $params[] = $monthFrom; $types .= "i"; $params[] = $monthTo; $types .= "i"; }
    if ($year !== null) { $params[] = $year; $types .= "i"; }
    $params[] = $barangayId; $types .= "i";
    if ($isBns && $userId !== null) { $params[] = $userId; $types .= "i"; }
    if ($monthFrom !== null && $monthTo !== null) { $params[] = $monthFrom; $types .= "i"; $params[] = $monthTo; $types .= "i"; }
    if ($year !== null) { $params[] = $year; $types .= "i"; }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Define age groups
    $ageGroups = [
        '0-5' => ['min' => 0, 'max' => 5],
        '6-11' => ['min' => 6, 'max' => 11],
        '12-23' => ['min' => 12, 'max' => 23],
        '24-35' => ['min' => 24, 'max' => 35],
        '36-47' => ['min' => 36, 'max' => 47],
        '48-59' => ['min' => 48, 'max' => 59],
    ];
    
    // Define nutritional status categories
    $categories = [
        'WFA-Normal', 'WFA-OW', 'WFA-UW', 'WFA-SUW',
        'HFA-Normal', 'HFA-Tall', 'HFA-St', 'HFA-SSt',
        'WL/H-Normal', 'WL/H-OW', 'WL/H-Ob', 'WL/H-MW', 'WL/H-SW'
    ];
    
    // Initialize data structure
    $data = [];
    foreach ($categories as $cat) {
        $data[$cat] = [];
        foreach ($ageGroups as $group => $range) {
            $data[$cat][$group] = ['boys' => 0, 'girls' => 0, 'total' => 0];
        }
        $data[$cat]['0-59'] = ['boys' => 0, 'girls' => 0, 'total' => 0];
        $data[$cat]['0-23'] = ['boys' => 0, 'girls' => 0, 'total' => 0];
        $data[$cat]['ip'] = ['boys' => 0, 'girls' => 0, 'total' => 0];
    }
    
    // Process each child
    $totalChildren = 0;
    $childrenWithRecords = 0;
    $totalMeasured59 = 0;
    $totalMeasured23 = 0;
    $ipTotals = ['boys' => 0, 'girls' => 0, 'total' => 0];
    
    while ($row = $result->fetch_assoc()) {
        $totalChildren++;
        
        if (empty($row['measurement_date']) || empty($row['weight']) || empty($row['height'])) {
            continue;
        }
        
        $childrenWithRecords++;
        if (!isset($affectedChildren)) $affectedChildren = [];
        
        // Calculate age in months
        try {
            $birthDate = new DateTime($row['birthdate']);
            $measureDate = new DateTime($row['measurement_date']);
            $diff = $birthDate->diff($measureDate);
            $ageMonths = ($diff->y * 12) + $diff->m;
        } catch (Exception $e) {
            continue;
        }
        
        if ($ageMonths < 0 || $ageMonths > 59) {
            continue;
        }
        
        $sex = strtolower($row['sex'] === 'Male' ? 'boys' : 'girls');
        $isIp = ($row['is_ip'] ?? '') === 'Yes';
        $weight = (float)$row['weight'];
        $height = (float)$row['height'];

        $totalMeasured59++;
        if ($ageMonths <= 23) {
            $totalMeasured23++;
        }
        if ($isIp) {
            $ipTotals[$sex]++;
            $ipTotals['total']++;
        }
        
        // Determine WFA status
        $wfaRef = fetchClosestGrowthReference($conn, GROWTH_WEIGHT_TABLE, $ageMonths, $row['sex']);
        if ($wfaRef) {
            $wfaStatusFull = determineWeightForAgeStatus($weight, $wfaRef);
            if ($wfaStatusFull) {
                // Map full status to abbreviated category
                if (strpos($wfaStatusFull, 'Severely Underweight') !== false) {
                    $wfaStatus = 'WFA-SUW';
                } elseif (strpos($wfaStatusFull, 'Underweight') !== false) {
                    $wfaStatus = 'WFA-UW';
                } elseif (strpos($wfaStatusFull, 'Normal') !== false) {
                    $wfaStatus = 'WFA-Normal';
                } else {
                    $wfaStatus = 'WFA-OW';
                }
                
                // Record in data
                $ageGroup = getAgeGroup($ageMonths, $ageGroups);
                if ($ageGroup) {
                    $data[$wfaStatus][$ageGroup][$sex]++;
                    $data[$wfaStatus][$ageGroup]['total']++;
                    $data[$wfaStatus]['0-59'][$sex]++;
                    $data[$wfaStatus]['0-59']['total']++;
                    if ($ageMonths <= 23) {
                        $data[$wfaStatus]['0-23'][$sex]++;
                        $data[$wfaStatus]['0-23']['total']++;
                    }
                    if ($isIp) {
                        $data[$wfaStatus]['ip'][$sex]++;
                        $data[$wfaStatus]['ip']['total']++;
                    }
                }
            }
        }
        
        // Determine HFA status
        $hfaRef = fetchClosestGrowthReference($conn, GROWTH_HEIGHT_TABLE, $ageMonths, $row['sex']);
        if ($hfaRef) {
            $hfaStatusFull = determineHeightForAgeStatus($height, $hfaRef);
            if ($hfaStatusFull) {
                // Map full status to abbreviated category
                if (strpos($hfaStatusFull, 'Severely Stunted') !== false) {
                    $hfaStatus = 'HFA-SSt';
                } elseif (strpos($hfaStatusFull, 'Stunted') !== false) {
                    $hfaStatus = 'HFA-St';
                } elseif (strpos($hfaStatusFull, 'Tall') !== false) {
                    $hfaStatus = 'HFA-Tall';
                } else {
                    $hfaStatus = 'HFA-Normal';
                }
                
                $ageGroup = getAgeGroup($ageMonths, $ageGroups);
                if ($ageGroup) {
                    $data[$hfaStatus][$ageGroup][$sex]++;
                    $data[$hfaStatus][$ageGroup]['total']++;
                    $data[$hfaStatus]['0-59'][$sex]++;
                    $data[$hfaStatus]['0-59']['total']++;
                    if ($ageMonths <= 23) {
                        $data[$hfaStatus]['0-23'][$sex]++;
                        $data[$hfaStatus]['0-23']['total']++;
                    }
                    if ($isIp) {
                        $data[$hfaStatus]['ip'][$sex]++;
                        $data[$hfaStatus]['ip']['total']++;
                    }
                }
            }
        }
        
        // Determine WL/H status
        $wflStatusFull = null;
        $wflAgeGroup = resolveWeightForLengthAgeGroup((int)$ageMonths);
        if ($wflAgeGroup !== null) {
            $wflOor = false;
            $wflRef = fetchWeightForLengthReferenceByAgeGroup($conn, $row['sex'], $wflAgeGroup, $height, $wflOor);
            if ($wflRef && !$wflOor) {
                $wflStatusFull = determineWeightForLengthStatus($weight, $wflRef);
            }
        }

        if ($wflStatusFull) {
                // Map full status to abbreviated category
                if (strpos($wflStatusFull, 'Severely Wasted') !== false) {
                    $wflStatus = 'WL/H-SW';
                } elseif (strpos($wflStatusFull, 'Wasted') !== false) {
                    $wflStatus = 'WL/H-MW';
                } elseif (strpos($wflStatusFull, 'Obese') !== false) {
                    $wflStatus = 'WL/H-Ob';
                } elseif (strpos($wflStatusFull, 'Overweight') !== false) {
                    $wflStatus = 'WL/H-OW';
                } else {
                    $wflStatus = 'WL/H-Normal';
                }
                
                $ageGroup = getAgeGroup($ageMonths, $ageGroups);
                if ($ageGroup) {
                    $data[$wflStatus][$ageGroup][$sex]++;
                    $data[$wflStatus][$ageGroup]['total']++;
                    $data[$wflStatus]['0-59'][$sex]++;
                    $data[$wflStatus]['0-59']['total']++;
                    if ($ageMonths <= 23) {
                        $data[$wflStatus]['0-23'][$sex]++;
                        $data[$wflStatus]['0-23']['total']++;
                    }
                    if ($isIp) {
                        $data[$wflStatus]['ip'][$sex]++;
                        $data[$wflStatus]['ip']['total']++;
                    }
                }
        }

        $isAffected = false;
        if (in_array($wfaStatus ?? '', ['WFA-UW', 'WFA-SUW', 'WFA-OW'])) $isAffected = true;
        if (in_array($hfaStatus ?? '', ['HFA-St', 'HFA-SSt'])) $isAffected = true;
        if (in_array($wflStatus ?? '', ['WL/H-SW', 'WL/H-MW', 'WL/H-OW', 'WL/H-Ob'])) $isAffected = true;

        if ($isAffected) {
            $affectedChildren[] = [
                'seq' => count($affectedChildren) + 1,
                'address' => $row['address'],
                'guardian' => build_display_name($row['g_first'] ?? '', $row['g_middle'] ?? '', $row['g_last'] ?? '', $row['g_suffix'] ?? ''),
                'child_name' => build_display_name($row['first_name'], $row['middle_name'], $row['last_name']),
                'sex' => $row['sex'],
                'birthdate' => $row['birthdate'],
                'age_months' => $ageMonths,
                'weight' => $weight,
                'height' => $height,
                'wfa_status' => $wfaStatusFull ?? '',
                'wfl_status' => $wflStatusFull ?? '',
                'hfa_status' => $hfaStatusFull ?? ''
            ];
        }
    }
    
    $stmt->close();
    
    return [
        'ageGroups' => $ageGroups,
        'data' => $data,
        'totalChildren' => $totalChildren,
        'childrenWithRecords' => $childrenWithRecords,
        'affectedChildren' => $affectedChildren ?? [],
        'totals' => [
            'measured59' => $totalMeasured59,
            'measured23' => $totalMeasured23,
            'ip' => $ipTotals,
        ],
    ];
}

function getAgeGroup($ageMonths, $ageGroups) {
    foreach ($ageGroups as $group => $range) {
        if ($ageMonths >= $range['min'] && $ageMonths <= $range['max']) {
            return $group;
        }
    }
    return null;
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Nutrition Tracking</title>
    <link rel="stylesheet" href="css/tailwind.css">

    <link rel="stylesheet" href="css/reports.css">
    <link rel="stylesheet" href="css/growth-status.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

</head>
<body class="bg-slate-50 print:bg-white print:m-0 print:p-0 <?= $bodyPrintClass ?>">
    <?php include 'sidebar.php'; ?>
    
    <main class="main-content px-2 md:px-6 py-4 print:py-0 print-wrap">
        <div class="w-full max-w-[1600px] mx-auto print:max-w-none print:w-full print:px-0 print:mx-0">
            <!-- Report Selector -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6 no-print">
                <h1 class="text-2xl font-bold text-slate-900 mb-6">Generate Report</h1>
                


                <form method="POST" class="space-y-4" id="reportForm">
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Select Report Type</label>
                            <select name="report_type" id="reportType" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                                <option value="">-- Choose a Report --</option>
                                <option value="summary" <?= $selectedReportType === 'summary' ? 'selected' : '' ?>>Summary Report (OPT PLUS <?= $selectedYear ?>)</option>
                                <option value="opt_form1a" <?= $selectedReportType === 'opt_form1a' ? 'selected' : '' ?>>OPT Form 1A</option>
                                <option value="opt_form1b" <?= $selectedReportType === 'opt_form1b' ? 'selected' : '' ?>>OPT Form 1B</option>
                                <option value="nut_status" <?= $selectedReportType === 'nut_status' ? 'selected' : '' ?>>Nut Status Report</option>

                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Select Barangay</label>
                            <select name="barangay_id" id="barangayId" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                                <option value="">-- Choose a Barangay --</option>
                                <?php foreach ($barangays as $b): ?>
                                    <option value="<?= $b['barangay_id'] ?>" <?= (isset($selectedBarangayId) && $selectedBarangayId == $b['barangay_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['barangay_name']) ?> - <?= htmlspecialchars($b['city']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Select Year</label>
                            <select name="report_year" id="reportYear" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                                <?php
                                    $currentYear = (int)date('Y');
                                    $selectedYearValue = $selectedYear ?? $currentYear;
                                    // Limit to last 5 years to align with the 4 years and 11 months (0-59 months) program eligibility
                                    for ($y = $currentYear; $y >= $currentYear - 5; $y--) {
                                        $sel = ($selectedYearValue === $y) ? 'selected' : '';
                                        echo "<option value=\"$y\" $sel>$y</option>";
                                    }
                                ?>
                            </select>
                        </div>

                        <?php
                            $months = [
                                1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
                                5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
                                9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
                            ];
                            $currMonthFrom = isset($_POST['month_from']) && $_POST['month_from'] !== '' ? (int)$_POST['month_from'] : '';
                            $currMonthTo = isset($_POST['month_to']) && $_POST['month_to'] !== '' ? (int)$_POST['month_to'] : '';
                        ?>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">From Month (Optional)</label>
                            <select name="month_from" id="monthFrom" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">-- All Months --</option>
                                <?php
                                    foreach ($months as $num => $name) {
                                        $sel = ($currMonthFrom === $num) ? 'selected' : '';
                                        echo "<option value=\"$num\" $sel>$name</option>";
                                    }
                                ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">To Month (Optional)</label>
                            <select name="month_to" id="monthTo" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">-- All Months --</option>
                                <?php
                                    foreach ($months as $num => $name) {
                                        $sel = ($currMonthTo === $num) ? 'selected' : '';
                                        echo "<option value=\"$num\" $sel>$name</option>";
                                    }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="px-6 py-2 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition">
                            Generate Report
                        </button>
                    </div>
                </form>
            </div>

            <!-- Report Display -->
            <div id="reportDisplayArea">
            <?php if ($reportData !== null && $selectedBarangay): ?>
                <div class="mt-12 mb-12 print:mt-0 print:mb-0">
                    <?php if ($selectedReportType !== 'nut_status'): ?>
                    <!-- Print Button -->
                        <div class="no-print mb-6 flex justify-between items-center">
                            <h2 class="text-xl font-bold text-slate-900">
                                <?php 
                                    if ($selectedReportType === 'opt_form1a') echo 'OPT Form 1A';
                                    else echo 'Summary Report';
                                ?>: <?= htmlspecialchars($selectedBarangay['barangay_name']) ?>
                            </h2>
                            <div class="flex gap-2">
                                <button onclick="window.print()" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-200 transition-all active:scale-95">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                    Print Report
                                </button>
                                <button onclick="saveAsPDF()" id="pdfBtn" class="inline-flex items-center gap-2 px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold rounded-xl shadow-lg shadow-rose-200 transition-all active:scale-95">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                    Save as PDF
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Report Content -->
                    <div class="report-container print:!mt-0" id="reportContent">
                        <?php if ($selectedReportType === 'opt_form1a'): ?>
                            <div class="opt1a-print-shift">
                            <?php
                                $wfaCategories = [
                                    'WFA-Normal' => 'Normal (N)',
                                    'WFA-UW' => 'Underweight (UW)',
                                    'WFA-SUW' => 'Severely Underweight (SUW)',
                                    'WFA-OW' => 'Overweight (OW)',
                                ];
                                $ageHeaders = ['0-5', '6-11', '12-23', '24-35', '36-47', '48-59'];
                                $measured59 = (int)($reportData['totals']['measured59'] ?? 0);
                                $estimatedPop = (int)($selectedBarangay['estimated_children_measured'] ?? 0);
                                $optCoverage = $estimatedPop > 0 ? number_format(($measured59 / $estimatedPop) * 100, 1) : '0.0';
                                $underweightTotal = (int)($reportData['data']['WFA-UW']['0-59']['total'] ?? 0);
                                $suwTotal = (int)($reportData['data']['WFA-SUW']['0-59']['total'] ?? 0);
                                $owTotal = (int)($reportData['data']['WFA-OW']['0-59']['total'] ?? 0);
                                $uwSuwPrev = $measured59 > 0 ? number_format((($underweightTotal + $suwTotal) / $measured59) * 100, 1) : '0.0';
                            ?>
                            <div class="opt1a-header">
                                <div style="position: relative; padding-top: 0;">
                                    <div style="position: absolute; top: 0; left: 40px; font-size: 8.5pt;">
                                        <div># pages for printing: <span style="margin-left: 12px;">3</span></div>
                                    </div>
                                    
                                    <div style="text-align: center; font-size: 9.5pt; line-height: 1.2;">
                                        <div>Republic of the Philippines</div>
                                        <div>Department of Health</div>
                                        <div style="font-weight: bold;">NATIONAL NUTRITION COUNCIL</div>
                                        <div style="font-weight: bold; font-size: 11pt;">Region XIII - CARAGA</div>
                                    </div>
                                    
                                    <div style="position: absolute; top: 8px; right: 40px;">
                                        <img src="images/lgu-bislig.png" alt="National Nutrition Council" onerror="this.style.display='none';" style="width: 65px; height: 65px; object-fit: contain;">
                                    </div>
                                </div>
                                
                                <div style="margin-top: 8px; font-size: 8pt; font-style: italic; padding-left: 10px;">
                                    Revised March 2021 Page 1 of 3
                                </div>
                                <div style="font-weight: bold; font-size: 10pt; text-align: left; padding-left: 10px; margin-top: 2px;">
                                    OPT Plus Form 1A. <span style="font-weight: normal;">Barangay Tally and Summary Sheet of Preschoolers with Weight &amp; Height Measurement by Age Group, Sex and Nutritional Status</span>
                                </div>
                            </div>

                            <table style="width: 100%; font-size: 8.5pt; margin-top: 5px; margin-bottom: 5px; border-collapse: collapse;">
                                <tr>
                                    <td style="text-align: right; padding-right: 8px; width: 80px;">Barangay:</td>
                                    <td style="border-bottom: 1px solid #000; text-align: center; width: 150px;"><?= htmlspecialchars($selectedBarangay['barangay_name'] ?? '') ?></td>
                                    <td style="width: 20px;"></td>

                                    <td style="text-align: right; padding-right: 8px; line-height: 1.1; width: 230px;">
                                        Estimated Population of Children  0-59<br><span style="margin-right: 15px;">months old:</span>
                                    </td>
                                    <td style="border-bottom: 1px solid #000; text-align: center; width: 80px; vertical-align: bottom;"><?= htmlspecialchars($selectedBarangay['estimated_children_measured'] ?? '—') ?></td>
                                    <td style="width: 20px;"></td>

                                    <td style="text-align: right; padding-right: 8px; width: 170px;">Total Population of Barangay:</td>
                                    <td style="border-bottom: 1px solid #000; text-align: center; width: 80px;"><?= htmlspecialchars($selectedBarangay['total_population'] ?? '—') ?></td>
                                    <td style="width: 15px;"></td>

                                    <td style="text-align: left; vertical-align: bottom; width: 150px;">
                                        Source: DOH - EB 2025
                                        <div style="border-bottom: 1px solid #000; margin-top: 2px;"></div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="text-align: right; padding-right: 8px; padding-top: 6px;">City:</td>
                                    <td style="border-bottom: 1px solid #000; text-align: center; padding-top: 6px;"><?= htmlspecialchars($selectedBarangay['city'] ?? '') ?></td>
                                    <td></td>
                                    
                                    <td style="text-align: right; padding-right: 8px; padding-top: 6px;">WFA - Actual # of Children Measured:</td>
                                    <td style="border-bottom: 1px solid #000; text-align: center; padding-top: 6px;"><?= $measured59 ?></td>
                                    <td></td>

                                    <td style="text-align: right; padding-right: 8px; padding-top: 6px;">Year Covered by this OPT Plus:</td>
                                    <td style="border-bottom: 1px solid #000; text-align: center; padding-top: 6px; font-weight: bold;">CY <?= $selectedYear ?></td>
                                    <td></td>
                                    
                                    <td></td>
                                </tr>
                                <tr>
                                    <td style="text-align: right; padding-right: 8px; padding-top: 6px;">Province:</td>
                                    <td style="border-bottom: 1px solid #000; text-align: center; padding-top: 6px;"><?= htmlspecialchars($selectedBarangay['province'] ?? '') ?></td>
                                    <td></td>

                                    <td style="text-align: right; padding-right: 8px; padding-top: 6px;">OPT Plus Coverage - WFA:</td>
                                    <td style="border-bottom: 1px solid #000; text-align: center; padding-top: 6px;"><?= $optCoverage ?>%</td>
                                    <td></td>

                                    <td style="text-align: right; padding-right: 8px; padding-top: 6px;">Prevalence Rate UW &amp; SUW (Last OPT Plus):</td>
                                    <td style="text-align: center; padding-top: 6px;"><div style="background-color: #00b050; width: 100%; height: 18px;"></div></td>
                                    <td></td>

                                    <td></td>
                                </tr>
                                <tr>
                                    <td style="text-align: right; padding-right: 8px; padding-top: 6px;">Region:</td>
                                    <td style="border-bottom: 1px solid #000; text-align: center; padding-top: 6px;">CARAGA</td>
                                    <td></td>

                                    <td colspan="2" style="text-align: left; padding-top: 6px; padding-left: 20px;">
                                        # Indigenous Preschoolers: Total = <span style="display:inline-block; width: 25px; border-bottom: 1px solid #000; text-align:center;"><?= $reportData['totals']['ip']['total'] ?? 0 ?></span> 
                                        &nbsp;M = <span style="display:inline-block; width: 25px; border-bottom: 1px solid #000; text-align:center;"><?= $reportData['totals']['ip']['boys'] ?? 0 ?></span> 
                                        &nbsp;F = <span style="display:inline-block; width: 25px; border-bottom: 1px solid #000; text-align:center;"><?= $reportData['totals']['ip']['girls'] ?? 0 ?></span>
                                    </td>
                                    <td></td>

                                    <td colspan="3" style="text-align: center; padding-top: 8px; font-weight: bold; font-size: 8pt;">
                                        Prevalence Rate Underweight &amp; Severe Underweight:<br>
                                        <span style="font-weight: normal; margin-top: 2px; display: inline-block;">0-59 months: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <?= $uwSuwPrev ?>%</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td colspan="2" style="text-align: left; padding-top: 8px;">
                                        <div style="display: flex; align-items: center; justify-content: flex-start; padding-left: 40px;">
                                            <span style="margin-right: 8px; font-size: 8.5pt;">Indigenous groups (specify if applicable):</span>
                                            <div style="background-color: #00b050; width: 180px; height: 18px;"></div>
                                        </div>
                                    </td>
                                    <td colspan="4"></td>
                                </tr>
                            </table>

                            <table class="opt1a-table">
                                <thead>
                                    <tr>
                                        <th rowspan="4" class="opt1a-age-header">Age<br>Group<br><br><span style="font-weight: normal;">(1)</span></th>
                                        <th colspan="19" class="opt1a-group-title">Weight for Age Status</th>
                                        <th colspan="8" class="opt1a-group-title">Total, by age group</th>
                                    </tr>
                                    <tr class="opt1a-subhead">
                                        <th colspan="4">Normal (N)</th>
                                        <th colspan="4">Underweight (UW)</th>
                                        <th colspan="4">Severely Underweight (SUW)</th>
                                        <th colspan="4">Overweight (OW)</th>
                                        <th colspan="3">TOTAL</th>
                                        <th colspan="2">N</th>
                                        <th colspan="2">UW</th>
                                        <th colspan="2">SUW</th>
                                        <th colspan="2">OW</th>
                                    </tr>
                                    <tr class="opt1a-subhead">
                                        <th colspan="2">Boys</th>
                                        <th colspan="2">Girls</th>
                                        <th colspan="2">Boys</th>
                                        <th colspan="2">Girls</th>
                                        <th colspan="2">Boys</th>
                                        <th colspan="2">Girls</th>
                                        <th colspan="2">Boys</th>
                                        <th colspan="2">Girls</th>
                                        <th>Boys</th>
                                        <th>Girls</th>
                                        <th>Total</th>
                                        <th>No.</th>
                                        <th>Prev (%)</th>
                                        <th>No.</th>
                                        <th>Prev (%)</th>
                                        <th>No.</th>
                                        <th>Prev (%)</th>
                                        <th>No.</th>
                                        <th>Prev (%)</th>
                                    </tr>
                                    <tr class="opt1a-subhead" style="font-weight: normal;">
                                        <th>(2)</th><th>(3)</th><th>(4)</th><th>(5)</th>
                                        <th>(6)</th><th>(7)</th><th>(8)</th><th>(9)</th>
                                        <th>(10)</th><th>(11)</th><th>(12)</th><th>(13)</th>
                                        <th>(14)</th><th>(15)</th><th>(16)</th><th>(17)</th>
                                        <th>(18)</th><th>(19)</th><th>(20)</th>
                                        <th>(21)</th><th>(22)</th>
                                        <th>(23)</th><th>(24)</th>
                                        <th>(25)</th><th>(26)</th>
                                        <th>(27)</th><th>(28)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $rn = 1;
                                    foreach ($ageHeaders as $ag): 
                                    ?>
                                        <?php
                                            $normalBoys = $reportData['data']['WFA-Normal'][$ag]['boys'] ?? 0;
                                            $normalGirls = $reportData['data']['WFA-Normal'][$ag]['girls'] ?? 0;
                                            $uwBoys = $reportData['data']['WFA-UW'][$ag]['boys'] ?? 0;
                                            $uwGirls = $reportData['data']['WFA-UW'][$ag]['girls'] ?? 0;
                                            $suwBoys = $reportData['data']['WFA-SUW'][$ag]['boys'] ?? 0;
                                            $suwGirls = $reportData['data']['WFA-SUW'][$ag]['girls'] ?? 0;
                                            $owBoys = $reportData['data']['WFA-OW'][$ag]['boys'] ?? 0;
                                            $owGirls = $reportData['data']['WFA-OW'][$ag]['girls'] ?? 0;

                                            $totalBoys = $normalBoys + $uwBoys + $suwBoys + $owBoys;
                                            $totalGirls = $normalGirls + $uwGirls + $suwGirls + $owGirls;
                                            $totalByAge = $totalBoys + $totalGirls;

                                            $normalTotal = $normalBoys + $normalGirls;
                                            $uwTotal = $uwBoys + $uwGirls;
                                            $suwTotal = $suwBoys + $suwGirls;
                                            $owTotal = $owBoys + $owGirls;

                                            $normalPrev = $totalByAge > 0 ? ($normalTotal / $totalByAge) * 100 : 0.0;
                                            $uwPrev = $totalByAge > 0 ? ($uwTotal / $totalByAge) * 100 : 0.0;
                                            $suwPrev = $totalByAge > 0 ? ($suwTotal / $totalByAge) * 100 : 0.0;
                                            $owPrev = $totalByAge > 0 ? ($owTotal / $totalByAge) * 100 : 0.0;
                                        ?>
                                        <tr>
                                            <td class="opt1a-age-cell">
                                                <?= $ag ?><br>months<br><span style="font-size: 7pt; font-style: italic;">(R<?= $rn ?>)</span>
                                            </td>
                                            <!-- Normal: Boys cols(2)(3), Girls cols(4)(5) -->
                                            <td colspan="2"><?= $normalBoys ?></td>
                                            <td colspan="2"><?= $normalGirls ?></td>
                                            <!-- UW: Boys cols(6)(7), Girls cols(8)(9) -->
                                            <td colspan="2"><?= $uwBoys ?></td>
                                            <td colspan="2"><?= $uwGirls ?></td>
                                            <!-- SUW: Boys cols(10)(11), Girls cols(12)(13) -->
                                            <td colspan="2"><?= $suwBoys ?></td>
                                            <td colspan="2"><?= $suwGirls ?></td>
                                            <!-- OW: Boys cols(14)(15), Girls cols(16)(17) -->
                                            <td colspan="2"><?= $owBoys ?></td>
                                            <td colspan="2"><?= $owGirls ?></td>
                                            <!-- TOTAL: Boys(18), Girls(19), Total(20) -->
                                            <td><?= $totalBoys ?></td>
                                            <td><?= $totalGirls ?></td>
                                            <td><?= $totalByAge ?></td>
                                            <!-- N: No.(21), Prev%(22) -->
                                            <td><?= $normalTotal ?></td>
                                            <td><?= number_format($normalPrev, 1) ?></td>
                                            <!-- UW: No.(23), Prev%(24) -->
                                            <td><?= $uwTotal ?></td>
                                            <td><?= number_format($uwPrev, 1) ?></td>
                                            <!-- SUW: No.(25), Prev%(26) -->
                                            <td><?= $suwTotal ?></td>
                                            <td><?= number_format($suwPrev, 1) ?></td>
                                            <!-- OW: No.(27), Prev%(28) -->
                                            <td><?= $owTotal ?></td>
                                            <td><?= number_format($owPrev, 1) ?></td>
                                        </tr>
                                    <?php 
                                    $rn++;
                                    endforeach; 
                                    ?>
                                    
                                    <!-- Double Border Top for Totals -->
                                    <tr style="border-top: 3px double #000;">
                                        <?php
                                            $normalBoys23 = $reportData['data']['WFA-Normal']['0-23']['boys'] ?? 0;
                                            $normalGirls23 = $reportData['data']['WFA-Normal']['0-23']['girls'] ?? 0;
                                            $uwBoys23 = $reportData['data']['WFA-UW']['0-23']['boys'] ?? 0;
                                            $uwGirls23 = $reportData['data']['WFA-UW']['0-23']['girls'] ?? 0;
                                            $suwBoys23 = $reportData['data']['WFA-SUW']['0-23']['boys'] ?? 0;
                                            $suwGirls23 = $reportData['data']['WFA-SUW']['0-23']['girls'] ?? 0;
                                            $owBoys23 = $reportData['data']['WFA-OW']['0-23']['boys'] ?? 0;
                                            $owGirls23 = $reportData['data']['WFA-OW']['0-23']['girls'] ?? 0;

                                            $totalBoys23 = $normalBoys23 + $uwBoys23 + $suwBoys23 + $owBoys23;
                                            $totalGirls23 = $normalGirls23 + $uwGirls23 + $suwGirls23 + $owGirls23;
                                            $totalByAge23 = $totalBoys23 + $totalGirls23;

                                            $normalTotal23 = $normalBoys23 + $normalGirls23;
                                            $uwTotal23 = $uwBoys23 + $uwGirls23;
                                            $suwTotal23 = $suwBoys23 + $suwGirls23;
                                            $owTotal23 = $owBoys23 + $owGirls23;
                                        ?>
                                        <td class="opt1a-age-cell"><strong>Total (R7)</strong><br>0-23 mos</td>
                                        <!-- Normal Boys cols(2)(3), Girls cols(4)(5) -->
                                        <td colspan="2"><?= $normalBoys23 ?></td>
                                        <td colspan="2"><?= $normalGirls23 ?></td>
                                        <!-- UW Boys cols(6)(7), Girls cols(8)(9) -->
                                        <td colspan="2"><?= $uwBoys23 ?></td>
                                        <td colspan="2"><?= $uwGirls23 ?></td>
                                        <!-- SUW Boys cols(10)(11), Girls cols(12)(13) -->
                                        <td colspan="2"><?= $suwBoys23 ?></td>
                                        <td colspan="2"><?= $suwGirls23 ?></td>
                                        <!-- OW Boys cols(14)(15), Girls cols(16)(17) -->
                                        <td colspan="2"><?= $owBoys23 ?></td>
                                        <td colspan="2"><?= $owGirls23 ?></td>
                                        <!-- TOTAL: Boys(18) Girls(19) Total(20) -->
                                        <td><?= $totalBoys23 ?></td>
                                        <td><?= $totalGirls23 ?></td>
                                        <td><?= $totalByAge23 ?></td>
                                        <!-- N: No.(21) Prev(22) -->
                                        <td><?= $normalTotal23 ?></td>
                                        <td rowspan="2" style="background-color: #f2f2f2; text-align: center; vertical-align: middle;"></td>
                                        <!-- UW: No.(23) Prev(24) -->
                                        <td><?= $uwTotal23 ?></td>
                                        <td rowspan="2" style="background-color: #f2f2f2; text-align: center; vertical-align: middle;"></td>
                                        <!-- SUW: No.(25) Prev(26) -->
                                        <td><?= $suwTotal23 ?></td>
                                        <td rowspan="2" style="background-color: #f2f2f2; text-align: center; vertical-align: middle;"></td>
                                        <!-- OW: No.(27) Prev(28) -->
                                        <td><?= $owTotal23 ?></td>
                                        <td rowspan="2" style="background-color: #f2f2f2; text-align: center; vertical-align: middle;"></td>
                                    </tr>
                                    <tr>
                                        <?php
                                            $normalPrevBoys23 = $totalBoys23 > 0 ? ($normalBoys23 / $totalBoys23) * 100 : 0.0;
                                            $normalPrevGirls23 = $totalGirls23 > 0 ? ($normalGirls23 / $totalGirls23) * 100 : 0.0;
                                            $uwPrevBoys23 = $totalBoys23 > 0 ? ($uwBoys23 / $totalBoys23) * 100 : 0.0;
                                            $uwPrevGirls23 = $totalGirls23 > 0 ? ($uwGirls23 / $totalGirls23) * 100 : 0.0;
                                            $suwPrevBoys23 = $totalBoys23 > 0 ? ($suwBoys23 / $totalBoys23) * 100 : 0.0;
                                            $suwPrevGirls23 = $totalGirls23 > 0 ? ($suwGirls23 / $totalGirls23) * 100 : 0.0;
                                            $owPrevBoys23 = $totalBoys23 > 0 ? ($owBoys23 / $totalBoys23) * 100 : 0.0;
                                            $owPrevGirls23 = $totalGirls23 > 0 ? ($owGirls23 / $totalGirls23) * 100 : 0.0;
                                            
                                            $normalPrevOverall23 = $totalByAge23 > 0 ? ($normalTotal23 / $totalByAge23) * 100 : 0.0;
                                            $uwPrevOverall23 = $totalByAge23 > 0 ? ($uwTotal23 / $totalByAge23) * 100 : 0.0;
                                            $suwPrevOverall23 = $totalByAge23 > 0 ? ($suwTotal23 / $totalByAge23) * 100 : 0.0;
                                            $owPrevOverall23 = $totalByAge23 > 0 ? ($owTotal23 / $totalByAge23) * 100 : 0.0;
                                        ?>
                                        <td class="opt1a-age-cell">Prevalence(%)<br>0-23 mos</td>
                                        <!-- Normal Boys cols(2)(3), Girls cols(4)(5) -->
                                        <td colspan="2"><?= number_format($normalPrevBoys23, 1) ?></td>
                                        <td colspan="2"><?= number_format($normalPrevGirls23, 1) ?></td>
                                        <!-- UW Boys cols(6)(7), Girls cols(8)(9) -->
                                        <td colspan="2"><?= number_format($uwPrevBoys23, 1) ?></td>
                                        <td colspan="2"><?= number_format($uwPrevGirls23, 1) ?></td>
                                        <!-- SUW Boys cols(10)(11), Girls cols(12)(13) -->
                                        <td colspan="2"><?= number_format($suwPrevBoys23, 1) ?></td>
                                        <td colspan="2"><?= number_format($suwPrevGirls23, 1) ?></td>
                                        <!-- OW Boys cols(14)(15), Girls cols(16)(17) -->
                                        <td colspan="2"><?= number_format($owPrevBoys23, 1) ?></td>
                                        <td colspan="2"><?= number_format($owPrevGirls23, 1) ?></td>
                                        <!-- TOTAL: Boys(18) Girls(19) Total(20) -->
                                        <td style="background-color: #f2f2f2;"></td>
                                        <td style="background-color: #f2f2f2;"></td>
                                        <td style="background-color: #f2f2f2;"></td>
                                        <!-- N Prev(22) -->
                                        <td style="background-color: #f2f2f2; font-weight: bold;"><?= number_format($normalPrevOverall23, 2) ?></td>
                                        <!-- UW Prev(24) -->
                                        <td style="background-color: #f2f2f2; font-weight: bold;"><?= number_format($uwPrevOverall23, 2) ?></td>
                                        <!-- SUW Prev(26) -->
                                        <td style="background-color: #f2f2f2; font-weight: bold;"><?= number_format($suwPrevOverall23, 2) ?></td>
                                        <!-- OW Prev(28) -->
                                        <td style="background-color: #f2f2f2; font-weight: bold;"><?= number_format($owPrevOverall23, 2) ?></td>
                                    </tr>

                                    <tr style="border-top: 2px solid #000;">
                                        <?php
                                            $normalBoys59 = $reportData['data']['WFA-Normal']['0-59']['boys'] ?? 0;
                                            $normalGirls59 = $reportData['data']['WFA-Normal']['0-59']['girls'] ?? 0;
                                            $uwBoys59 = $reportData['data']['WFA-UW']['0-59']['boys'] ?? 0;
                                            $uwGirls59 = $reportData['data']['WFA-UW']['0-59']['girls'] ?? 0;
                                            $suwBoys59 = $reportData['data']['WFA-SUW']['0-59']['boys'] ?? 0;
                                            $suwGirls59 = $reportData['data']['WFA-SUW']['0-59']['girls'] ?? 0;
                                            $owBoys59 = $reportData['data']['WFA-OW']['0-59']['boys'] ?? 0;
                                            $owGirls59 = $reportData['data']['WFA-OW']['0-59']['girls'] ?? 0;

                                            $totalBoys59 = $normalBoys59 + $uwBoys59 + $suwBoys59 + $owBoys59;
                                            $totalGirls59 = $normalGirls59 + $uwGirls59 + $suwGirls59 + $owGirls59;
                                            $totalByAge59 = $totalBoys59 + $totalGirls59;

                                            $normalTotal59 = $normalBoys59 + $normalGirls59;
                                            $uwTotal59 = $uwBoys59 + $uwGirls59;
                                            $suwTotal59 = $suwBoys59 + $suwGirls59;
                                            $owTotal59 = $owBoys59 + $owGirls59;
                                        ?>
                                        <td style="font-size: 8pt; line-height: 1.1; vertical-align: top; padding-top: 4px;"><strong>Total (R9)</strong><br>0-59 mos</td>
                                        <!-- Normal Boys cols(2)(3), Girls cols(4)(5) -->
                                        <td colspan="2"><?= $normalBoys59 ?></td>
                                        <td colspan="2"><?= $normalGirls59 ?></td>
                                        <!-- UW Boys cols(6)(7), Girls cols(8)(9) -->
                                        <td colspan="2"><?= $uwBoys59 ?></td>
                                        <td colspan="2"><?= $uwGirls59 ?></td>
                                        <!-- SUW Boys cols(10)(11), Girls cols(12)(13) -->
                                        <td colspan="2"><?= $suwBoys59 ?></td>
                                        <td colspan="2"><?= $suwGirls59 ?></td>
                                        <!-- OW Boys cols(14)(15), Girls cols(16)(17) -->
                                        <td colspan="2"><?= $owBoys59 ?></td>
                                        <td colspan="2"><?= $owGirls59 ?></td>
                                        <!-- TOTAL: Boys(18) Girls(19) Total(20) -->
                                        <td><?= $totalBoys59 ?></td>
                                        <td><?= $totalGirls59 ?></td>
                                        <td><?= $totalByAge59 ?></td>
                                        <!-- N: No.(21) Prev(22) -->
                                        <td><?= $normalTotal59 ?></td>
                                        <td rowspan="2" style="background-color: #f2f2f2; text-align: center; vertical-align: middle;"></td>
                                        <!-- UW: No.(23) Prev(24) -->
                                        <td><?= $uwTotal59 ?></td>
                                        <td rowspan="2" style="background-color: #f2f2f2; text-align: center; vertical-align: middle;"></td>
                                        <!-- SUW: No.(25) Prev(26) -->
                                        <td><?= $suwTotal59 ?></td>
                                        <td rowspan="2" style="background-color: #f2f2f2; text-align: center; vertical-align: middle;"></td>
                                        <!-- OW: No.(27) Prev(28) -->
                                        <td><?= $owTotal59 ?></td>
                                        <td rowspan="2" style="background-color: #f2f2f2; text-align: center; vertical-align: middle;"></td>
                                    </tr>
                                    <tr>
                                        <?php
                                            $normalPrevBoys59 = $totalBoys59 > 0 ? ($normalBoys59 / $totalBoys59) * 100 : 0.0;
                                            $normalPrevGirls59 = $totalGirls59 > 0 ? ($normalGirls59 / $totalGirls59) * 100 : 0.0;
                                            $uwPrevBoys59 = $totalBoys59 > 0 ? ($uwBoys59 / $totalBoys59) * 100 : 0.0;
                                            $uwPrevGirls59 = $totalGirls59 > 0 ? ($uwGirls59 / $totalGirls59) * 100 : 0.0;
                                            $suwPrevBoys59 = $totalBoys59 > 0 ? ($suwBoys59 / $totalBoys59) * 100 : 0.0;
                                            $suwPrevGirls59 = $totalGirls59 > 0 ? ($suwGirls59 / $totalGirls59) * 100 : 0.0;
                                            $owPrevBoys59 = $totalBoys59 > 0 ? ($owBoys59 / $totalBoys59) * 100 : 0.0;
                                            $owPrevGirls59 = $totalGirls59 > 0 ? ($owGirls59 / $totalGirls59) * 100 : 0.0;
                                            
                                            $normalPrevOverall59 = $totalByAge59 > 0 ? ($normalTotal59 / $totalByAge59) * 100 : 0.0;
                                            $uwPrevOverall59 = $totalByAge59 > 0 ? ($uwTotal59 / $totalByAge59) * 100 : 0.0;
                                            $suwPrevOverall59 = $totalByAge59 > 0 ? ($suwTotal59 / $totalByAge59) * 100 : 0.0;
                                            $owPrevOverall59 = $totalByAge59 > 0 ? ($owTotal59 / $totalByAge59) * 100 : 0.0;
                                        ?>
                                        <td style="font-size: 8pt; line-height: 1.1; vertical-align: top; padding-top: 4px;">Prevalence(%)<br>0-59 mos</td>
                                        <!-- Normal Boys cols(2)(3), Girls cols(4)(5) -->
                                        <td colspan="2"><?= number_format($normalPrevBoys59, 1) ?></td>
                                        <td colspan="2"><?= number_format($normalPrevGirls59, 1) ?></td>
                                        <!-- UW Boys cols(6)(7), Girls cols(8)(9) -->
                                        <td colspan="2"><?= number_format($uwPrevBoys59, 1) ?></td>
                                        <td colspan="2"><?= number_format($uwPrevGirls59, 1) ?></td>
                                        <!-- SUW Boys cols(10)(11), Girls cols(12)(13) -->
                                        <td colspan="2"><?= number_format($suwPrevBoys59, 1) ?></td>
                                        <td colspan="2"><?= number_format($suwPrevGirls59, 1) ?></td>
                                        <!-- OW Boys cols(14)(15), Girls cols(16)(17) -->
                                        <td colspan="2"><?= number_format($owPrevBoys59, 1) ?></td>
                                        <td colspan="2"><?= number_format($owPrevGirls59, 1) ?></td>
                                        <!-- TOTAL: Boys(18) Girls(19) Total(20) -->
                                        <td style="background-color: #f2f2f2;"></td>
                                        <td style="background-color: #f2f2f2;"></td>
                                        <td style="background-color: #f2f2f2;"></td>
                                        <!-- N Prev(22) -->
                                        <td style="background-color: #f2f2f2; font-weight: bold;"><?= number_format($normalPrevOverall59, 2) ?></td>
                                        <!-- UW Prev(24) -->
                                        <td style="background-color: #f2f2f2; font-weight: bold;"><?= number_format($uwPrevOverall59, 2) ?></td>
                                        <!-- SUW Prev(26) -->
                                        <td style="background-color: #f2f2f2; font-weight: bold;"><?= number_format($suwPrevOverall59, 2) ?></td>
                                        <!-- OW Prev(28) -->
                                        <td style="background-color: #f2f2f2; font-weight: bold;"><?= number_format($owPrevOverall59, 2) ?></td>
                                    </tr>
                                </tbody>
                            </table>


                            <div class="opt1a-note" style="margin-top: 2px;">
                                Note: a) R1 means Row No 1, R2 means Row 2, etc.; b)Total (R7) - refers to the sum of preschoolers by nutritional status for children 0-59 months; c) Prevalence (R8 & R10)- refers to the prevalence rate by sex, by nutritional status for age group 0- 59 mos.<br>
                                <i>1/ Refers to previous year prevalence rate of the area</i><br>
                                <strong style="font-size: 8pt;">Use WEIGHT-FOR-LENGTH or WEIGHT-FOR-HEIGHT to correctly determine overweight and obesity</strong>
                            </div>

                            <div class="opt1a-signatories" style="display: flex; justify-content: space-between; margin-top: 2px; font-size: 8pt;">
                                <!-- Prepared By -->
                                <div class="opt1a-signatory-block" style="display: flex; gap: 8px; align-items: flex-start;">
                                    <div class="opt1a-signatory-label-single">Prepared by:</div>
                                    <div style="display: flex; flex-direction: column; align-items: center;">
                                        <div class="opt1a-signatory-line"></div>
                                        <div class="opt1a-signatory-text opt1a-signatory-text-single">Name and Signature of Barangay Nutrition Scholar</div>
                                        <div class="opt1a-signatory-date">
                                            <span class="opt1a-signatory-date-label">Date:</span>
                                            <div class="opt1a-signatory-date-line"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Checked By -->
                                <div class="opt1a-signatory-block" style="display: flex; gap: 8px; align-items: flex-start;">
                                    <div class="opt1a-signatory-label-double">Checked:</div>
                                    <div class="opt1a-signatory-column-shift" style="display: flex; flex-direction: column; align-items: center;">
                                        <div class="opt1a-signatory-line"></div>
                                        <div class="opt1a-signatory-text opt1a-signatory-text-double opt1a-signatory-text-shift">Name and Signature of Midwife/Nurse/MNAO/MHO or<br>District/City Nutrition Program Coordinator</div>
                                        <div class="opt1a-signatory-date">
                                            <span class="opt1a-signatory-date-label">Date:</span>
                                            <div class="opt1a-signatory-date-line"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Approved By -->
                                <div class="opt1a-signatory-block" style="display: flex; gap: 8px; align-items: flex-start;">
                                    <div class="opt1a-signatory-label-double">Approved:</div>
                                    <div class="opt1a-signatory-column-shift" style="display: flex; flex-direction: column; align-items: center;">
                                        <div class="opt1a-signatory-line"></div>
                                        <div class="opt1a-signatory-text opt1a-signatory-text-triple opt1a-signatory-text-shift">Name and Signature of Barangay Captain,<br>BNC Chairperson</div>
                                        <div class="opt1a-signatory-date">
                                            <span class="opt1a-signatory-date-label">Date:</span>
                                            <div class="opt1a-signatory-date-line"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            </div>
                        <?php elseif ($selectedReportType === 'opt_form1b'): ?>
                            <style>
                                .opt1b-table {
                                    border: none !important;
                                }
                                .opt1b-table th, .opt1b-table td {
                                    border: 1px solid #000;
                                    padding: 4px;
                                    text-align: center;
                                }
                                .opt1b-table thead th {
                                    border-top: 1px solid #000 !important;
                                }
                                .opt1b-table thead th:first-child, .opt1b-table tbody td:first-child {
                                    border-left: 1px solid #000 !important;
                                }
                                .opt1b-table thead th:last-child, .opt1b-table tbody td:last-child {
                                    border-right: 1px solid #000 !important;
                                }
                                .opt1b-table tbody tr:last-child td {
                                    border-bottom: 1px solid #000 !important;
                                }
                                .opt1b-table tfoot td {
                                    border: none !important;
                                }
                                @media print {
                                    @page {
                                        size: legal landscape;
                                        margin: 0.3in !important;
                                    }
                                    .no-print { display: none !important; }
                                    .print-wrap { padding: 0 !important; padding-left: 25mm !important; margin: 0 !important; max-width: 100% !important; }
                                    thead { display: table-header-group !important; }
                                    tfoot { display: table-footer-group !important; }
                                    tr { page-break-inside: avoid !important; }
                                    
                                    .print-signatories-spacer {
                                        display: none !important;
                                    }
                                    .print-signatories {
                                        display: flex !important;
                                        position: static !important;
                                        margin-top: 12px !important;
                                        background-color: white !important;
                                        z-index: 9999;
                                        page-break-inside: avoid !important;
                                    }
                                }
                                @media screen {
                                    .print-signatories-spacer {
                                        display: none !important;
                                    }
                                }
                                .status-box {
                                    display: inline-block;
                                    width: 60px;
                                    text-align: center;
                                    font-weight: bold;
                                    border-bottom: 1px solid #000;
                                }
                                .status-box-border {
                                    border: 1px solid #000;
                                }
                                .status-severe { background-color: #ff0000 !important; color: #000 !important; }
                                .status-normal { background-color: #00ff00 !important; color: #000 !important; }
                                .status-moderate { background-color: #ffff00 !important; color: #000 !important; }
                                .status-over { background-color: #ffc000 !important; color: #000 !important; }
                                .status-na { background-color: #fafcfd !important; color: #000 !important; }
                                .status-oor { background-color: #eef2f7 !important; color: #000 !important; }
                                .bg-grey { background-color: #d9d9d9 !important; }
                            </style>
                            <div class="opt1b-container" style="font-family: 'Times New Roman', Times, serif; color: #000; width: 100%;">
                                <!-- TOP HEADER -->
                                 <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px;">
                                    <div style="width: 30%;">
                                        <div style="font-size: 10pt; margin-bottom: 5px;">Page 1</div>
                                        <div style="margin-top: 15px; font-weight: bold; font-size: 11pt;">YEAR: <?= $selectedYear ?></div>
                                    </div>
                                    <div style="width: 40%; text-align: center; font-size: 11.5pt; line-height: 1.3; padding-top: 15px;">
                                        <div>Republic of the Philippines</div>
                                        <div>Department of Health</div>
                                        <div>NATIONAL NUTRITION COUNCIL</div>
                                        <div>Region XIII - CARAGA</div>
                                    </div>
                                    <div style="width: 30%; display: flex; flex-direction: column; align-items: flex-end;">
                                        <img src="images/lgu-bislig.png" style="width: 80px; height: auto; margin-bottom: 15px;" alt="LGU Bislig Logo">
                                        <div style="border: 1px solid #000; padding: 2px 20px; font-size: 9pt;">
                                            # Pages for Printing: &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; 1
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="margin-bottom: 5px; margin-top: -20px;">
                                    <h3 style="font-size: 12pt; font-weight: bold; margin: 0;">Form 1B. List of Affected/At-risk Preschool Children 0-59 Months Old</h3>
                                </div>

                                <?php
                                $affectedUW = 0; $affectedSUW = 0; $affectedOW_wfa = 0;
                                $affectedSt = 0; $affectedSSt = 0;
                                $affectedMW = 0; $affectedSW = 0; $affectedOW_wfl = 0; $affectedOb = 0;

                                foreach ($reportData['affectedChildren'] as $child) {
                                    if ($child['wfa_status'] === 'Underweight') $affectedUW++;
                                    elseif ($child['wfa_status'] === 'Severely Underweight') $affectedSUW++;
                                    elseif ($child['wfa_status'] === 'Overweight') $affectedOW_wfa++;
                                    
                                    if ($child['hfa_status'] === 'Stunted') $affectedSt++;
                                    elseif ($child['hfa_status'] === 'Severely Stunted') $affectedSSt++;
                                    
                                    if ($child['wfl_status'] === 'Wasted') $affectedMW++;
                                    elseif ($child['wfl_status'] === 'Severely Wasted') $affectedSW++;
                                    elseif ($child['wfl_status'] === 'Overweight') $affectedOW_wfl++;
                                    elseif ($child['wfl_status'] === 'Obese') $affectedOb++;
                                }
                                $undernutritionTotal = $affectedUW + $affectedSUW + $affectedSt + $affectedSSt + $affectedMW + $affectedSW;
                                $overweightObeseTotal = $affectedOW_wfa + $affectedOW_wfl + $affectedOb;
                                $totalAffected = $undernutritionTotal + $overweightObeseTotal;
                                ?>

                                <!-- SUMMARY SECTION -->
                                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; margin-top: 20px;">
                                    <div style="width: 40%; display: flex; align-items: flex-start;">
                                        <div style="font-size: 12pt; margin-left: 30px;">Total # Children Affected/At-Risk:</div>
                                        <div style="font-weight: bold; font-size: 13pt; margin-left: 70px;"><?= $totalAffected ?></div>
                                    </div>
                                    
                                    <div style="width: 60%; display: flex; justify-content: flex-end; padding-right: 20px;">
                                        <table style="width: 450px; text-align: center; border-spacing: 20px 2px; border-collapse: separate; font-size: 12pt;">
                                            <tr>
                                                <td>UW= <span style="display:inline-block; width:60px; border-bottom:1px solid #000;"><?= $affectedUW ?></span></td>
                                                <td>St= <span style="display:inline-block; width:60px; border-bottom:1px solid #000;"><?= $affectedSt ?></span></td>
                                                <td>MW= <span style="display:inline-block; width:60px; border-bottom:1px solid #000;"><?= $affectedMW ?></span></td>
                                            </tr>
                                            <tr>
                                                <td>SUW= <span class="status-severe status-box-border" style="display:inline-block; width:60px; font-weight:bold; padding:2px 0;"><?= $affectedSUW ?></span></td>
                                                <td>SSt= <span class="status-severe status-box-border" style="display:inline-block; width:60px; font-weight:bold; padding:2px 0;"><?= $affectedSSt ?></span></td>
                                                <td>SW= <span class="bg-grey status-box-border" style="display:inline-block; width:60px; font-weight:bold; padding:2px 0;"><?= $affectedSW ?></span></td>
                                            </tr>
                                            <tr>
                                                <td>OW= <span style="display:inline-block; width:60px; border-bottom:1px solid #000;"><?= $affectedOW_wfa ?></span></td>
                                                <td></td>
                                                <td>OW= <span style="display:inline-block; width:60px; border-bottom:1px solid #000;"><?= $affectedOW_wfl ?></span></td>
                                            </tr>
                                            <tr>
                                                <td></td>
                                                <td></td>
                                                <td>Ob= <span style="display:inline-block; width:60px; border-bottom:1px solid #000;"><?= $affectedOb ?></span></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>

                                <!-- ADDRESS & TOTAL CHILDREN BOXES -->
                                <table style="width: auto; border-collapse: collapse; font-size: 8.5pt; margin-top:-50px; margin-left: 490px;">
                                    <tr>
                                        <td style="text-align: right; width: 80px; padding-right: 10px; border: none;">Barangay:</td>
                                        <td style="border-bottom: 1px solid #000; text-align: center; width: 180px;"><?= htmlspecialchars($selectedBarangay['barangay_name']) ?></td>
                                        <td style="border: none; width: 25px;"></td>
                                        <td style="border: none;"></td>
                                        <td style="border: none;"></td>
                                    </tr>
                                    <tr>
                                        <td style="text-align: right; padding-right: 10px; padding-top: 5px; border: none;">City:</td>
                                        <td style="border-bottom: 1px solid #000; text-align: center; padding-top: 5px;">CITY OF BISLIG</td>
                                        <td style="border: none;"></td>
                                        <td style="border: none;"></td>
                                        <td style="border: none;"></td>
                                    </tr>
                                    <tr>
                                        <td style="text-align: right; padding-right: 10px; padding-top: 5px; border: none;">Province:</td>
                                        <td style="border-bottom: 1px solid #000; text-align: center; padding-top: 5px;">Surigao del Sur</td>
                                        <td style="border: none;"></td>
                                        <td style="border: 1px solid #000; border-right: none; padding: 2px; text-align: right; padding-right: 5px; border-bottom: none; width: 260px;">Number of Children Affected by Undernutrition:</td>
                                        <td style="border: 1px solid #000; border-left: none; padding: 2px; font-weight: bold; text-align: left; padding-left: 5px; width: 30px; border-bottom: none;"><?= $undernutritionTotal ?></td>
                                    </tr>
                                    <tr>
                                        <td style="text-align: right; padding-right: 10px; padding-top: 5px; border: none;">Region:</td>
                                        <td style="text-align: center; padding-top: 5px;">CARAGA</td>
                                        <td style="border: none;"></td>
                                        <td style="border: 1px solid #000; border-top: none; border-right: none; padding: 2px; text-align: right; padding-right: 5px; border-bottom: none;">Number of Children with Overweight or Obesity:</td>
                                        <td style="border: 1px solid #000; border-top: none; border-left: none; padding: 2px; font-weight: bold; text-align: left; padding-left: 5px; border-bottom: none;"><?= $overweightObeseTotal ?></td>
                                    </tr>
                                </table>

                                <!-- DATA TABLE -->
                                <table class="opt1b-table" style="width: 100%; border-collapse: collapse; font-size: 8pt; border: none;">
                                    <thead>
                                        <tr>
                                            <th rowspan="2" style="width: 50px;">Child Seq.</th>
                                            <th style="width: 140px;">Address</th>
                                            <th rowspan="2" style="width: 180px;">Name of Mother or<br>Caregiver</th>
                                            <th rowspan="2" style="width: 200px;">Full Name of Child</th>
                                            <th rowspan="2" style="width: 40px;">Sex</th>
                                            <th rowspan="2" style="width: 60px;">Age in<br>Months</th>
                                            <th colspan="3">Nutritional Status</th>
                                        </tr>
                                        <tr>
                                            <th style="font-weight: normal; font-size: 7.5pt; padding: 2px;">Purok or Location in the<br>Barangay</th>
                                            <th style="width: 70px;">Weight for<br>Age</th>
                                            <th style="width: 70px;">Height for<br>Age</th>
                                            <th style="width: 80px;">Weight for<br>Length/<br>Height</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $affectedChildren = $reportData['affectedChildren'] ?? [];
                                        $childrenCount = count($affectedChildren);
                                        
                                        foreach ($affectedChildren as $idx => $child): 
                                            $wfa_abbr = status_abbrev($child['wfa_status']);
                                            $wfaCls = status_cell_class($child['wfa_status']);
                                            
                                            $hfa_abbr = status_abbrev($child['hfa_status']);
                                            $hfaCls = status_cell_class($child['hfa_status']);
                                            
                                            $wfl_abbr = status_abbrev($child['wfl_status']);
                                            $wflCls = status_cell_class($child['wfl_status']);
                                        ?>
                                        <tr <?= ($idx === 9 && $childrenCount > 10) ? 'style="page-break-after: always; break-after: page;"' : '' ?>>
                                            <td><?= $idx + 1 ?></td>
                                            <td style="text-align: left; padding-left: 2px;"><?= htmlspecialchars($child['address']) ?></td>
                                            <td style="text-align: left; padding-left: 2px;"><?= mb_strtoupper(htmlspecialchars($child['guardian'])) ?></td>
                                            <td style="text-align: left; padding-left: 2px;"><?= mb_strtoupper(htmlspecialchars($child['child_name'])) ?></td>
                                            <td><?= htmlspecialchars($child['sex'] == 'Male' ? 'M' : 'F') ?></td>
                                            <td><?= htmlspecialchars($child['age_months']) ?></td>
                                            <td class="<?= $wfaCls ?>"><?= $wfa_abbr ?></td>
                                            <td class="<?= $hfaCls ?>"><?= $hfa_abbr ?></td>
                                            <td class="<?= $wflCls ?>"><?= $wfl_abbr ?></td>
                                        </tr>
                                        <?php endforeach; ?>

                                        <?php 
                                        // Always show at least 10 rows
                                        for ($i = $childrenCount; $i < 10; $i++): 
                                        ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td>&nbsp;</td>
                                            <td>&nbsp;</td>
                                            <td>&nbsp;</td>
                                            <td>&nbsp;</td>
                                            <td>&nbsp;</td>
                                            <td>&nbsp;</td>
                                            <td>&nbsp;</td>
                                            <td>&nbsp;</td>
                                        </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="9" style="border: none !important; padding: 0 !important; background-color: transparent !important;">
                                                <!-- Spacer for print page layout -->
                                                <div class="print-signatories-spacer"></div>
                                                <!-- Compact signatories block -->
                                                <div class="print-signatories" style="display: flex; justify-content: space-between; font-size: 8.5pt; width: 100%; line-height: 1.1; margin-top: 15px;">
                                                    <div style="width: 32%;">
                                                        <div style="display: flex; margin-bottom: 2px;">
                                                            <div style="width: 70px; font-weight: bold; margin-top: -2px;">Prepared by:</div>
                                                            <div style="flex-grow: 1; text-align: left;">
                                                                <div style="border-bottom: 1px solid #000; height: 14px;"></div>
                                                                <div style="font-size: 7.5pt; margin-top: 1px; padding-left: 6px;">Name and Signature of Barangay Nutrition Scholar</div>
                                                            </div>
                                                        </div>
                                                        <div style="display: flex;">
                                                            <div style="width: 70px; font-weight: bold;">Date:</div>
                                                            <div style="flex-grow: 1; height: 14px;"></div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div style="width: 32%;">
                                                        <div style="display: flex; margin-bottom: 2px;">
                                                            <div style="width: 60px; font-weight: bold; margin-top: -2px;">Checked:</div>
                                                            <div style="flex-grow: 1; text-align: left;">
                                                                <div style="border-bottom: 1px solid #000; height: 14px;"></div>
                                                                <div style="font-size: 7.5pt; margin-top: 1px;">Name and Signature of DCNPC/Nutritionist/Nurse/Midwife/MNAO</div>
                                                            </div>
                                                        </div>
                                                        <div style="display: flex;">
                                                            <div style="width: 60px; font-weight: bold;">Date:</div>
                                                            <div style="flex-grow: 1; height: 14px;"></div>
                                                        </div>
                                                    </div>

                                                    <div style="width: 32%;">
                                                        <div style="display: flex; margin-bottom: 2px;">
                                                            <div style="width: 65px; font-weight: bold; margin-top: -2px;">Approved:</div>
                                                            <div style="flex-grow: 1; text-align: left;">
                                                                <div style="border-bottom: 1px solid #000; height: 14px;"></div>
                                                                <div style="font-size: 7.5pt; margin-top: 1px;">Name and Signature of Barangay Captain / BNC Chairperson</div>
                                                            </div>
                                                        </div>
                                                        <div style="display: flex;">
                                                            <div style="width: 65px; font-weight: bold;">Date:</div>
                                                            <div style="flex-grow: 1; height: 14px;"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php elseif ($selectedReportType === 'nut_status'): ?>

                            <div class="no-print mb-6 flex justify-between items-center">
                                <h2 class="text-xl font-bold text-slate-900">
                                    Nut Status Report: <?= htmlspecialchars($selectedBarangay['barangay_name']) ?>
                                </h2>
                                <div class="flex gap-2">
                                    <button onclick="document.getElementById('nutStatusIframe').contentWindow.print()" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-200 transition-all active:scale-95">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                        Print Report
                                    </button>
                                    <button onclick="saveNutStatusAsPDF()" id="pdfBtnNut" class="inline-flex items-center gap-2 px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold rounded-xl shadow-lg shadow-rose-200 transition-all active:scale-95">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                        Save as PDF
                                    </button>
                                </div>
                            </div>
                            <!-- Hidden iframe for automatic printing (child_profiles.php handles its own print trigger) -->
                            <iframe src="child_profiles.php?barangay_id=<?= $selectedBarangayId ?>&month_from=<?= $selectedMonthFrom ?>&month_to=<?= $selectedMonthTo ?>&year=<?= $selectedYear ?>&print_report=1" style="width:0; height:0; border:none; visibility:hidden; position:absolute; z-index:-1;" id="nutStatusIframe"></iframe>
                        <?php else: ?>
                        <style>
                            @media print {
                                .print-wrap { padding: 0 !important; padding-left: 25mm !important; margin: 0 !important; max-width: 100% !important; }
                            }
                        </style>
                        <!-- Header -->
                        <div class="opt-header">
                            <div class="opt-header-top">
                                <div class="opt-header-left">
                                    <!-- Row 1: Regn/OPT on left side spacer, Province/Regn/OPT on right -->
                                    <div style="display: flex; align-items: flex-end;">
                                        <!-- Left spacer column matching Barangay label width -->
                                        <div style="width: 220px; flex-shrink: 0;"></div>
                                        <!-- Right column: Province, Regn, OPT Plus Coverage -->
                                        <div style="display: flex; gap: 30px; align-items: flex-end;">
                                            <div class="opt-field">
                                                <span class="opt-label">Province:</span>
                                                <span class="opt-line" style="width: 150px; text-align: center;"><?= htmlspecialchars($selectedBarangay['province'] ?? '') ?></span>
                                            </div>
                                            <div class="opt-field">
                                                <span class="opt-label">Regn:</span>
                                                <span class="opt-line" style="width: 90px; text-align: center;">CARAGA</span>
                                            </div>
                                            <div class="opt-field">
                                                <span class="opt-label">OPT Plus Coverage:</span>
                                                <span class="opt-line" style="width: 55px; text-align: center; font-weight: 400;">4.0%</span>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Row 2: Barangay | Total Popn Barangay -->
                                    <div style="display: flex; align-items: flex-end; margin-top: 8px;">
                                        <!-- Left column: Barangay -->
                                        <div class="opt-field" style="width: 220px; flex-shrink: 0;">
                                            <span class="opt-label">Barangay:</span>
                                            <span class="opt-line" style="width: 130px; text-align: center;"><?= htmlspecialchars($selectedBarangay['barangay_name'] ?? '') ?></span>
                                        </div>
                                        <!-- Right column: Total Popn Barangay -->
                                        <div class="opt-field">
                                            <span class="opt-label">Total Popn Barangay:</span>
                                            <span class="opt-line" style="width: 110px; text-align: center;"><?= htmlspecialchars($selectedBarangay['total_population'] ?? '—') ?></span>
                                        </div>
                                    </div>
                                    <!-- Row 3: City | Estimated Popn -->
                                    <div style="display: flex; align-items: flex-end; margin-top: 8px;">
                                        <!-- Left column: City -->
                                        <div class="opt-field" style="width: 220px; flex-shrink: 0;">
                                            <span class="opt-label">City:</span>
                                            <span class="opt-line" style="width: 145px; text-align: center;"><?= htmlspecialchars($selectedBarangay['city'] ?? '') ?></span>
                                        </div>
                                        <!-- Right column: Estimated Popn -->
                                        <div class="opt-field" style="align-items: flex-end;">
                                            <div class="opt-label" style="line-height: 1.1; text-align: center;">
                                                Estimated Popn of<br>
                                                Children 0-59 mos in<br>
                                                Barangay:
                                            </div>
                                            <span class="opt-line" style="width: 75px; text-align: center; margin-bottom: 2px;"><?= htmlspecialchars($selectedBarangay['estimated_children_measured'] ?? '—') ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="opt-header-right">
                                    <div class="opt-indigenous-box">
                                        <div class="opt-indigenous-content">
                                            <div style="margin-bottom: auto;">Total # Indigenous <br> Preschool Children <br> Measured:</div>
                                            <div class="opt-indigenous-line">
                                                <span>0-59 mos</span>
                                                <span style="font-weight: bold;"><?= $reportData['totals']['ip']['total'] ?? '0' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="opt-seal">
                                        <img src="images/lgu-bislig.png" alt="National Nutrition Council" onerror="this.src=''; this.alt='NNC LOGO'; this.style.border='1px solid #ccc'; this.style.lineHeight='90px';">
                                        OPT PLUS <?= $selectedYear ?>
                                    </div>
                                </div>
                            </div>
                            <?php
                                $totalWfa = (int)($reportData['totals']['measured59'] ?? 0);
                                $totalHfa = (int)($reportData['totals']['measured59'] ?? 0);
                                $totalWflh = (int)($reportData['totals']['measured59'] ?? 0);
                            ?>
                        </div>

                        <!-- Main Summary Table -->
                        <table class="summary-table">
                            <thead>
                                <tr>
                                    <th colspan="10" style="border-top: none; border-left: none; border-bottom: none; border-right: none; text-align: left; vertical-align: bottom; padding-bottom: 6px; background: #fff;">
                                        <div style="font-size: 10pt; font-weight: bold; padding-left: 80px; display: inline-flex;">
                                            <span style="margin-right: -10px;">PSGC:</span>
                                            <span style="display: inline-block; width: 140px; text-align: center; border: none;"><?= htmlspecialchars($selectedBarangay['psgc'] ?? '') ?></span>
                                        </div>
                                    </th>
                                    <th colspan="3" style="border-top: none; border-bottom: 1px solid #000; border-left: none; border-right: none; padding: 0; background: #d9d9d9;">
                                        <div style="display: flex; height: 100%; border: none; font-size: 9pt;">
                                            <div style="display: flex; flex: 1; align-items: center; justify-content: center; padding: 4px 2px; border-right: none; font-weight: normal;">Total WFA:</div>
                                            <div style="display: flex; align-items: center; justify-content: center; padding: 4px 8px; font-weight: bold; background: #c4d79b;"><?= $totalWfa ?></div>
                                        </div>
                                    </th>
                                    <th colspan="3" style="border-top: none; border-bottom: 1px solid #000; border-left: none; border-right: none; padding: 0; background: #d9d9d9;">
                                        <div style="display: flex; height: 100%; border: none; font-size: 9pt;">
                                            <div style="display: flex; flex: 1; align-items: center; justify-content: center; padding: 4px 2px; border-right: none; font-weight: normal; ">Total HFA:</div> 
                                            <div style="display: flex; align-items: center; justify-content: center; padding: 4px 8px; font-weight: bold; background: #c4d79b;"><?= $totalHfa ?></div>
                                        </div>
                                    </th>
                                    <th colspan="3" style="border-top: none; border-bottom: 1px solid #000; border-left: none; border-right: none; padding: 0; background: #d9d9d9;">
                                        <div style="display: flex; height: 100%; border: none; font-size: 9pt;">
                                            <div style="display: flex; flex: 1; align-items: center; justify-content: center; padding: 4px 2px; border-right: none; text-align: center; font-weight: normal; line-height: 1.1;">Total<br>WFL/H:</div>
                                            <div style="display: flex; align-items: center; justify-content: center; padding: 4px 8px; font-weight: bold; background: #c4d79b;"><?= $totalWflh ?></div>
                                        </div>
                                    </th>
                                    <th colspan="2" style="background: #000; color: #fff; border: 1px solid #000; border-top: 4px solid #000; border-right: none; font-size: 9.5pt; font-weight: bold;">Birth to 5 Years</th>
                                    <th colspan="2" style="background: #f2f2f2; border: 1px solid #000; border-top: 4px solid #000; border-left: none; border-right: none; font-size: 9.5pt; font-weight: bold; color: #000;">F1K</th>
                                    <th colspan="3" style="background: #fff; border: 1px solid #000; border-top: 4px solid #000; border-left: none; font-size: 9.5pt; font-weight: bold; color: #000;"># IP Children</th>
                                </tr>
                                <tr>
                                    <th rowspan="2" style="min-width: 100px; width: 100px; max-width: 110px; border-top: 4px solid #000; border-left: 4px solid #000; padding: 2px 4px;" class="head-age">
                                        <a href="#" style="color: blue; text-decoration: underline; font-size: 8.5pt; line-height: 1.3; display: inline-block; word-break: break-word;">ACRONYMS &amp;<br>ABBREVIATIONS</a>
                                    </th>
                                    <th colspan="3" class="head-age" style="border-top: 4px solid #000;">0-5 Months</th>
                                    <th colspan="3" class="head-age" style="border-top: 4px solid #000;">6-11 Months</th>
                                    <th colspan="3" class="head-age" style="border-top: 4px solid #000;">12-23 Months</th>
                                    <th colspan="3" class="head-age" style="border-top: 4px solid #000;">24-35 Months</th>
                                    <th colspan="3" class="head-age" style="border-top: 4px solid #000;">36-47 Months</th>
                                    <th colspan="3" class="head-age" style="border-top: 4px solid #000;">48-59 Months</th>
                                    <th colspan="2" class="head-dark" style="border-top: 2px solid #000; border-bottom: 1px solid #fff;">0-59 Months</th>
                                    <th colspan="2" class="head-dark" style="background: #f2f2f2; color: #000;">0-23 Months</th>
                                    <th rowspan="2" class="head-ip" style="writing-mode: vertical-rl; transform: rotate(180deg); padding: 4px;">Boys</th>
                                    <th rowspan="2" class="head-ip" style="writing-mode: vertical-rl; transform: rotate(180deg); padding: 4px;">Girls</th>
                                    <th rowspan="2" class="head-ip" style="writing-mode: vertical-rl; transform: rotate(180deg); padding: 4px;">Total</th>
                                </tr>
                                <tr>
                                    <?php
                                    $ageHeaders = ['0-5', '6-11', '12-23', '24-35', '36-47', '48-59'];
                                    for ($i = 0; $i < 6; $i++):
                                    ?>
                                        <th class="head-age">Boys</th>
                                        <th class="head-age">Girls</th>
                                        <th class="head-age">Total</th>
                                    <?php endfor; ?>
                                    <th class="head-dark col-black">Total</th>
                                    <th class="head-dark col-black">Prev</th>
                                    <th class="head-dark" style="background: #f2f2f2; color: #000;">Total</th>
                                    <th class="head-dark" style="background: #f2f2f2; color: #000;">Prev</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $rowLabels = [
                                    'WFA-Normal' => 'WFA - Normal',
                                    'WFA-OW' => 'WFA - OW',
                                    'WFA-UW' => 'WFA - UW',
                                    'WFA-SUW' => 'WFA - SUW',
                                    'HFA-Normal' => 'HFA - Normal',
                                    'HFA-Tall' => 'HFA - Tall',
                                    'HFA-St' => 'HFA - St',
                                    'HFA-SSt' => 'HFA - SSt',
                                    'WL/H-Normal' => 'WL/H - Normal',
                                    'WL/H-OW' => 'WL/H - OW',
                                    'WL/H-Ob' => 'WL/H - Ob',
                                    'WL/H-MW' => 'WL/H - MW',
                                    'WL/H-SW' => 'WL/H - SW',
                                ];
                                $measured59 = (int)($reportData['totals']['measured59'] ?? 0);
                                $measured23 = (int)($reportData['totals']['measured23'] ?? 0);
                                foreach ($rowLabels as $key => $label):
                                    $row = $reportData['data'][$key];
                                    $prev59 = $measured59 > 0 ? number_format(($row['0-59']['total'] / $measured59) * 100, 1) . '%' : '0.0%';
                                    $prev23 = $measured23 > 0 ? number_format(($row['0-23']['total'] / $measured23) * 100, 1) . '%' : '0.0%';
                                ?>
                                <?php
                                    $isHfa = strpos($key, 'HFA') === 0;
                                ?>
                                <tr <?= $isHfa ? 'class="hfa-row"' : '' ?>>
                                    <td class="category-cell"><?= $label ?></td>
                                    <?php foreach ($ageHeaders as $ag): ?>
                                        <td><?= $row[$ag]['boys'] ?></td>
                                        <td><?= $row[$ag]['girls'] ?></td>
                                        <td><?= $row[$ag]['total'] ?></td>
                                    <?php endforeach; ?>
                                    <td class="col-black"><?= $row['0-59']['total'] ?></td>
                                    <td class="col-black"><?= $prev59 ?></td>
                                    <td><?= $row['0-23']['total'] ?></td>
                                    <td><?= $prev23 ?></td>
                                    <td><?= $row['ip']['boys'] ?></td>
                                    <td><?= $row['ip']['girls'] ?></td>
                                    <td><?= $row['ip']['total'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <!-- Total Row -->
                                <tr class="total-row">
                                    <td style="text-align: right; padding-right: 14px; font-weight: bold; background-color: #000 !important; color: #fff !important; border: 1px solid #fff; border-bottom:#000;">Total</td>
                                    <?php
                                    foreach ($ageHeaders as $ag):
                                        $boys = 0;
                                        $girls = 0;
                                        foreach ($rowLabels as $key => $label) {
                                            $boys += $reportData['data'][$key][$ag]['boys'];
                                            $girls += $reportData['data'][$key][$ag]['girls'];
                                        }
                                    ?>
                                        <td><?= $boys ?></td>
                                        <td><?= $girls ?></td>
                                        <td><?= $boys + $girls ?></td>
                                    <?php endforeach; ?>
                                    <td style="background-color: #a6a6a6 !important; border: none;"></td>
                                    <td style="background-color: #a6a6a6 !important; border: none;"></td>
                                    <td style="background-color: #a6a6a6 !important; border: none;"></td>
                                    <td style="background-color: #a6a6a6 !important; border: none;"></td>
                                    <td style="background-color: #a6a6a6 !important; border: none;"></td>
                                    <td style="background-color: #a6a6a6 !important; border: none;"></td>
                                    <td style="background-color: #a6a6a6 !important; border: none;"></td>
                                </tr>
                            </tbody>
                        </table>

                        <?php
                            $underNutrition59 = ($reportData['data']['WFA-UW']['0-59']['total'] ?? 0) + ($reportData['data']['WFA-SUW']['0-59']['total'] ?? 0);
                            $owObese59 = ($reportData['data']['WFA-OW']['0-59']['total'] ?? 0);
                            
                            $total023 = 0;
                            foreach ($reportData['data'] as $cat => $catData) {
                                if (strpos($cat, 'WFA') === 0) {
                                    $total023 += $catData['0-23']['total'] ?? 0;
                                }
                            }
                            
                            $underNutrition23 = ($reportData['data']['WFA-UW']['0-23']['total'] ?? 0) + ($reportData['data']['WFA-SUW']['0-23']['total'] ?? 0);
                        ?>

                        <!-- Summary Sections -->
                        <table class="summary-sections-table" style="margin-top: 0;">
                            <thead>
                                <tr>
                                    <th style="width: 33.33%; background:#da9694;">Summary of Children covered by e-OPT Plus</th>
                                    <th style="width: 33.33%; background:#da9694;">Mothers/Caregivers Summary</th>
                                    <th style="width: 33.33%; background: #da9694;">Data Summary</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr style="background: #fff;">
                                    <td style="border: none;">
                                        <div class="summary-flex-row" style="visibility: hidden;">
                                            <span>_</span>
                                            <span>_</span>
                                        </div>
                                    </td>
                                    <td style="border: 1px dotted #000; border-top: none;">
                                        <div class="summary-flex-row">
                                            <span>Total Number of M/Cs of children 0-59 mos. old:</span>
                                            <span style="font-weight: bold;"><?= $measured59 ?></span>
                                        </div>
                                    </td>
                                    <td style="border: 1px dotted #000; border-top: none;">
                                        <div class="summary-flex-row">
                                            <span># Children with weight but no height:</span>
                                            <span style="font-weight: bold;">0</span>
                                        </div>
                                    </td>
                                </tr>
                                <tr style="background: #ffffff;">
                                    <td style="border: 1px dotted #000;">
                                        <div class="summary-flex-row">
                                            <span># Children 0-59 mos. affected by Undernutrition:</span>
                                            <span style="font-weight: bold;"><?= $underNutrition59 ?></span>
                                        </div>
                                    </td>
                                    <td style="border: 1px dotted #000;">
                                        <div class="summary-flex-row">
                                            <span># M/Cs of 0-59 mos children affected by Undernutrition:</span>
                                            <span style="font-weight: bold;"><?= $underNutrition59 ?></span>
                                        </div>
                                    </td>
                                    <td style="border: 1px dotted #000;">
                                        <div class="summary-flex-row">
                                            <span># Children with height but no weight:</span>
                                            <span style="font-weight: bold;">0</span>
                                        </div>
                                    </td>
                                </tr>
                                <tr style="background: #fff;">
                                    <td style="border: 1px dotted #000;">
                                        <div class="summary-flex-row">
                                            <span># Children 0-59 mos. with Overweight/Obesity:</span>
                                            <span style="font-weight: bold;"><?= $owObese59 ?></span>
                                        </div>
                                    </td>
                                    <td style="border: 1px dotted #000;">
                                        <div class="summary-flex-row">
                                            <span># M/Cs of 0-59 mos. children with Overweight/Obesity:</span>
                                            <span style="font-weight: bold;"><?= $owObese59 ?></span>
                                        </div>
                                    </td>
                                    <td style="border: 1px dotted #000;">
                                        <div class="summary-flex-row">
                                            <span># Children with missing information:</span>
                                            <span style="font-weight: bold;">0</span>
                                        </div>
                                    </td>
                                </tr>
                                <tr style="background: #d9d9d9;">
                                    <td style="border: 1px dotted #000;">
                                        <div class="summary-flex-row">
                                            <span>Total Number of Children 0-23 mos. old:</span>
                                            <span style="font-weight: bold;"><?= $total023 ?></span>
                                        </div>
                                    </td>
                                    <td style="border: 1px dotted #000;">
                                        <div class="summary-flex-row">
                                            <span>Total Number of M/Cs of children 0-23 mos. old:</span>
                                            <span style="font-weight: bold;"><?= $total023 ?></span>
                                        </div>
                                    </td>
                                    <td style="border: 1px dotted #000;">
                                        <div class="summary-flex-row">
                                            <span># Children with names repeated:</span>
                                            <span style="font-weight: bold;">0</span>
                                        </div>
                                    </td>
                                </tr>
                                <tr style="background: #d9d9d9;">
                                    <td style="border: 1px dotted #000; border-bottom: 1px solid #000;">
                                        <div class="summary-flex-row">
                                            <span># Children 0-23 mos. affected by Undernutrition:</span>
                                            <span style="font-weight: bold;"><?= $underNutrition23 ?></span>
                                        </div>
                                    </td>
                                    <td style="border: 1px dotted #000; border-bottom: 1px solid #000;">
                                        <div class="summary-flex-row">
                                            <span># M/Cs of 0-23 mos children affected by Undernutrition:</span>
                                            <span style="font-weight: bold;"><?= $underNutrition23 ?></span>
                                        </div>
                                    </td>
                                    <td style="border: 1px dotted #000; border-bottom: 1px solid #000;">
                                        <div class="summary-flex-row">
                                            <span># Children older than 59 months:</span>
                                            <span style="font-weight: bold;">0</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div><!-- end report-container -->
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
    document.getElementById('reportForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const btn = this.querySelector('button[type="submit"]');
        const origText = btn.innerHTML;
        btn.innerHTML = 'Generating...';
        btn.disabled = true;

        try {
            const formData = new FormData(this);
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const text = await response.text();
            
            const parser = new DOMParser();
            const doc = parser.parseFromString(text, 'text/html');
            
            const newContent = doc.getElementById('reportDisplayArea');
            if (newContent) {
                document.getElementById('reportDisplayArea').innerHTML = newContent.innerHTML;
            }
        } catch (err) {
            console.error('Error generating report:', err);
            alert('An error occurred while generating the report. Please try again.');
        } finally {
            btn.innerHTML = origText;
            btn.disabled = false;
        }
    });

    function saveAsPDF() {
        const element = document.getElementById('reportContent');
        const pdfBtn = document.getElementById('pdfBtn');
        const originalContent = pdfBtn.innerHTML;
        
        pdfBtn.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Generating...';
        pdfBtn.disabled = true;

        const reportType = '<?= htmlspecialchars($selectedReportType) ?>';
        const opt = {
            margin: [0.3, 0.3, 0.3, 0.3],
            filename: 'Nutrition_Report_<?= htmlspecialchars($selectedBarangay['barangay_name'] ?? 'Report') ?>_<?= $selectedYear ?>.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { 
                scale: 2, 
                useCORS: true,
                letterRendering: true
            },
            jsPDF: { unit: 'in', format: 'legal', orientation: reportType === 'opt_form1b' ? 'portrait' : 'landscape' }
        };

        html2pdf().set(opt).from(element).save().then(() => {
            pdfBtn.innerHTML = originalContent;
            pdfBtn.disabled = false;
        }).catch(err => {
            console.error('PDF generation error:', err);
            pdfBtn.innerHTML = originalContent;
            pdfBtn.disabled = false;
        });
    }

    function saveNutStatusAsPDF() {
        const iframe = document.getElementById('nutStatusIframe');
        const pdfBtn = document.getElementById('pdfBtnNut');
        const originalContent = pdfBtn.innerHTML;

        pdfBtn.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Generating...';
        pdfBtn.disabled = true;

        if (iframe.contentWindow.saveAsPDF) {
            iframe.contentWindow.saveAsPDF().then(() => {
                pdfBtn.innerHTML = originalContent;
                pdfBtn.disabled = false;
            }).catch(err => {
                pdfBtn.innerHTML = originalContent;
                pdfBtn.disabled = false;
            });
        } else {
            // Fallback if the iframe doesn't have the function yet
            alert('PDF generation is not available for this report type yet.');
            pdfBtn.innerHTML = originalContent;
            pdfBtn.disabled = false;
        }
    }
    </script>
</body>
</html>
