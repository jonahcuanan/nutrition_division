<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentRole = $_SESSION['role'] ?? '';
$isBns = ($currentRole === 'Barangay Nutrition Scholars');
$isAdmin = ($currentRole === 'Admin');
$isHw = ($currentRole === 'Health Worker');
$isStaff = ($currentRole === 'Staff');
$assignedBarangayId = isset($_SESSION['barangay_id']) ? (int)$_SESSION['barangay_id'] : 0;
$highlightChildId = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$highlightRequested = isset($_GET['highlight']) && $_GET['highlight'] === '1';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/growth_utils.php';

// Allow filtering by barangay_id, month range, and year via GET for reports integration
$getBarangayId = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : 0;
$getBarangayName = isset($_GET['barangay_name']) ? trim((string)$_GET['barangay_name']) : '';
$getMonthSingle = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : 0;
$getMonthFrom = isset($_GET['month_from']) && $_GET['month_from'] !== '' ? (int)$_GET['month_from'] : 0;
$getMonthTo = isset($_GET['month_to']) && $_GET['month_to'] !== '' ? (int)$_GET['month_to'] : 0;
$getYear = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : 0;

if ($getMonthFrom > 0 && $getMonthTo === 0) {
    $getMonthTo = $getMonthFrom;
} elseif ($getMonthTo > 0 && $getMonthFrom === 0) {
    $getMonthFrom = $getMonthTo;
} elseif ($getMonthFrom > 0 && $getMonthTo > 0 && $getMonthFrom > $getMonthTo) {
    $tmp = $getMonthFrom;
    $getMonthFrom = $getMonthTo;
    $getMonthTo = $tmp;
} elseif ($getMonthSingle > 0 && $getMonthFrom === 0 && $getMonthTo === 0) {
    $getMonthFrom = $getMonthSingle;
    $getMonthTo = $getMonthSingle;
}
$isPrintReport = isset($_GET['print_report']) && $_GET['print_report'] === '1';

function status_cell_class($status) {
    $abbr = strtolower(status_abbrev($status));

    if ($abbr === 'n/a') {
        return 'status-na';
    }
    if ($abbr === 'oor') {
        return 'status-oor';
    }
    if (in_array($abbr, ['suw', 'sst', 'sw'], true)) {
        return 'status-severe';
    }
    if (in_array($abbr, ['uw', 'st', 'w', 'mw'], true)) {
        return 'status-moderate';
    }
    if (in_array($abbr, ['ow', 'ob'], true)) {
        return 'status-over';
    }
    if (in_array($abbr, ['n', 't'], true)) {
        return 'status-normal';
    }
    return 'status-na';
}

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

function fetch_barangay_info($conn, $barangayId) {
    if (!$barangayId) {
        return null;
    }

    $stmt = $conn->prepare('SELECT barangay_name, city, province FROM barangays WHERE barangay_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $barangayId);
    $stmt->execute();
    $result = $stmt->get_result();
    $info = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $info ?: null;
}

function fetch_barangay_info_by_name($conn, $barangayName) {
    $name = trim((string)$barangayName);
    if ($name === '') {
        return null;
    }

    $stmt = $conn->prepare('SELECT barangay_name, city, province FROM barangays WHERE LOWER(barangay_name) = LOWER(?) LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $name);
    $stmt->execute();
    $result = $stmt->get_result();
    $info = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $info ?: null;
}

// Handle AJAX request for latest child profile (previously get_child_profile.php)
if (isset($_GET['action']) && $_GET['action'] === 'get_child_profile') {
    header('Content-Type: application/json');

    $childId = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
    if ($childId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid child ID.']);
        exit;
    }

    // Enforce barangay-level access: HW and BNS may only load children they own
    if (!verify_child_barangay_access($conn, $childId)) {
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    $sqlProfile = "SELECT c.child_id, c.first_name, c.middle_name, c.last_name, c.suffix, c.birthdate, c.sex, c.address, c.is_ip, c.barangay_id,
                      g.first_name AS guardian_first, g.middle_name AS guardian_middle, g.last_name AS guardian_last, g.suffix AS guardian_suffix,
                      gr.record_id, gr.measurement_date, gr.weight, gr.height,
                      gr.muac_measurement, gr.muac_id,
                      gr.weight_id, gr.height_id, gr.wfl_id, gr.recorded_by,
                      u.first_name AS bns_first_name, u.last_name AS bns_last_name
                  FROM children c
                  LEFT JOIN guardians g ON c.guardian_id = g.guardian_id
                  LEFT JOIN growth_records gr ON gr.record_id = (
                      SELECT gr2.record_id 
                      FROM growth_records gr2 
                      WHERE gr2.child_id = c.child_id 
                      ORDER BY gr2.measurement_date DESC, gr2.record_id DESC 
                      LIMIT 1
                  )
                  LEFT JOIN users u ON gr.recorded_by = u.user_id
                  WHERE c.child_id = ?
                  LIMIT 1";

    $stmtProfile = $conn->prepare($sqlProfile);
    if (!$stmtProfile) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }

    $stmtProfile->bind_param('i', $childId);
    $stmtProfile->execute();
    $resultProfile = $stmtProfile->get_result();

    if ($resultProfile && $rowProfile = $resultProfile->fetch_assoc()) {
        $ageInMonths = null;
        if (!empty($rowProfile['birthdate']) && !empty($rowProfile['measurement_date'])) {
            try {
                $b = new DateTime($rowProfile['birthdate']);
                $m = new DateTime($rowProfile['measurement_date']);
                if ($m >= $b) {
                    $diff = $b->diff($m);
                    $ageInMonths = ($diff->y * 12) + $diff->m;
                    if ($ageInMonths < 0) $ageInMonths = 0;
                }
            } catch (Exception $e) {
                $ageInMonths = null;
            }
        }

        $heightStatus = 'N/A';
        $weightStatus = 'N/A';
        $wflStatus = 'N/A';
        $muacStatus = 'N/A';

        if ($ageInMonths !== null && $rowProfile['weight'] > 0 && $rowProfile['height'] > 0) {
            $normalizedSex = ucfirst(strtolower($rowProfile['sex'] ?? ''));
            $weightOutOfRange = false;
            $heightOutOfRange = false;
            $wflOutOfRange = false;

            $weightRef = fetchClosestGrowthReference($conn, GROWTH_WEIGHT_TABLE, $ageInMonths, $normalizedSex, $weightOutOfRange);
            $heightRef = fetchClosestGrowthReference($conn, GROWTH_HEIGHT_TABLE, $ageInMonths, $normalizedSex, $heightOutOfRange);
            $wflAgeGroup = resolveWeightForLengthAgeGroup((int)$ageInMonths);
            $wflRef = null;
            if ($wflAgeGroup === null) {
                $wflOutOfRange = true;
            } else {
                $wflRef = fetchWeightForLengthReferenceByAgeGroup($conn, $normalizedSex, $wflAgeGroup, (float)$rowProfile['height'], $wflOutOfRange);
            }

            $weightStatus = $weightRef ? (determineWeightForAgeStatus((float)$rowProfile['weight'], $weightRef) ?? 'N/A') : 'N/A';
            $heightStatus = $heightRef ? (determineHeightForAgeStatus((float)$rowProfile['height'], $heightRef) ?? 'N/A') : 'N/A';
            $wflStatus    = $wflRef    ? (determineWeightForLengthStatus((float)$rowProfile['weight'], $wflRef) ?? 'N/A')    : 'N/A';
        }

        if ($rowProfile['muac_measurement'] > 0) {
            $muacStatus = determineMuacStatus((float)$rowProfile['muac_measurement']) ?? 'N/A';
        }

        $rowProfile['age_in_months'] = $ageInMonths;
        $rowProfile['height_for_age_status'] = $heightStatus;
        $rowProfile['weight_for_age_status'] = $weightStatus;
        $rowProfile['weight_for_ltht_status'] = $wflStatus;
        $rowProfile['muac_status'] = $muacStatus;

        echo json_encode([
            'success' => true,
            'data' => $rowProfile,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No profile found for this child.']);
    }

    $stmtProfile->close();
    exit;
}

// Handle AJAX request to compute status preview from height/weight inputs
if (isset($_GET['action']) && $_GET['action'] === 'compute_status') {
    header('Content-Type: application/json');

    $ageMonths = isset($_GET['age_in_months']) ? (int)$_GET['age_in_months'] : null;
    $sex       = isset($_GET['sex'])           ? trim($_GET['sex'])           : null;
    $height    = isset($_GET['height'])        ? (float)$_GET['height']       : null;
    $weight    = isset($_GET['weight'])        ? (float)$_GET['weight']       : null;

    $muacVal = isset($_GET['muac']) ? (float)$_GET['muac'] : 0;
    
    if ($ageMonths === null || !$sex) {
        echo json_encode(['success' => false, 'message' => 'Age and Sex are required for status calculation.']);
        exit;
    }

    $weightStatus = 'N/A';
    $heightStatus = 'N/A';
    $wflStatus    = 'N/A';
    $muacStatus   = 'N/A';

    if ($height > 0 && $weight > 0) {
        $normalizedSex    = ucfirst(strtolower($sex));
        $weightOutOfRange = false;
        $heightOutOfRange = false;
        $wflOutOfRange    = false;

        $weightRef = fetchClosestGrowthReference($conn, GROWTH_WEIGHT_TABLE, $ageMonths, $normalizedSex, $weightOutOfRange);
        $heightRef = fetchClosestGrowthReference($conn, GROWTH_HEIGHT_TABLE, $ageMonths, $normalizedSex, $heightOutOfRange);
        $wflAgeGroup = resolveWeightForLengthAgeGroup((int)$ageMonths);
        $wflRef = ($wflAgeGroup !== null) ? fetchWeightForLengthReferenceByAgeGroup($conn, $normalizedSex, $wflAgeGroup, $height, $wflOutOfRange) : null;

        $weightStatus = $weightRef ? (determineWeightForAgeStatus($weight, $weightRef) ?? 'N/A') : 'N/A';
        $heightStatus = $heightRef ? (determineHeightForAgeStatus($height, $heightRef) ?? 'N/A') : 'N/A';
        $wflStatus    = $wflRef    ? (determineWeightForLengthStatus($weight, $wflRef) ?? 'N/A') : 'N/A';
    }

    if ($muacVal > 0) {
        $muacStatus = determineMuacStatus($muacVal) ?? 'N/A';
    }

    echo json_encode([
        'success'               => true,
        'weight_for_age_status' => $weightStatus,
        'height_for_age_status' => $heightStatus,
        'weight_for_ltht_status'=> $wflStatus,
        'muac_status'           => $muacStatus
    ]);
    exit;
}

// Read the cutoff record_id set by "New Measurement Period"
$sessionFile = __DIR__ . '/measurement_session.txt';
$cutoffRecordId = file_exists($sessionFile)
    ? (int)trim(file_get_contents($sessionFile))
    : 0;

// Only treat the cutoff as active if the session file is from the current month
// and a clear_measurements activity exists in the current month.
$periodIsNew = false;
if (file_exists($sessionFile)) {
    clearstatcache(true, $sessionFile);
    $mtime = filemtime($sessionFile);
    if (date('Y-m', $mtime) === date('Y-m')) {
        $hasClearThisMonth = false;
        if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno === 0) {
            $clearSql = "SELECT 1 FROM user_activity_log WHERE activity_type = 'clear_measurements' AND DATE_FORMAT(activity_time, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m') LIMIT 1";
            if ($clearRes = $conn->query($clearSql)) {
                $hasClearThisMonth = ($clearRes->num_rows > 0);
            }
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

// Safeguard: If the cutoff is greater than the max record_id, the database was likely reset.
if ($cutoffRecordId > 0 && isset($conn) && $conn instanceof mysqli && $conn->connect_errno === 0) {
    $maxQuery = $conn->query("SELECT MAX(record_id) FROM growth_records");
    if ($maxQuery) {
        $maxRow = $maxQuery->fetch_row();
        $maxId = $maxRow ? (int)$maxRow[0] : 0;
        if ($cutoffRecordId > $maxId) {
            $cutoffRecordId = 0;
        }
    }
}


    $baseSql = 'SELECT c.*, b.barangay_name, g.first_name AS guardian_first, g.last_name AS guardian_last, g.middle_name AS guardian_middle, g.suffix AS guardian_suffix,
            gr.record_id AS latest_record_id, gr.height AS latest_height, gr.weight AS latest_weight,
            gr.muac_measurement AS latest_muac, gr.muac_id AS latest_muac_id,
            gr.measurement_date AS latest_measurement_date,
            (SELECT gr3.record_id FROM growth_records gr3 WHERE gr3.child_id = c.child_id ORDER BY gr3.measurement_date DESC, gr3.record_id DESC LIMIT 1) as absolute_latest_record_id,
            (SELECT COUNT(*) FROM growth_records grc 
             WHERE grc.child_id = c.child_id 
             AND MONTH(grc.measurement_date) = MONTH(CURRENT_DATE) 
             AND YEAR(grc.measurement_date) = YEAR(CURRENT_DATE)
             AND COALESCE(grc.weight, 0) > 0 AND COALESCE(grc.height, 0) > 0) as month_measurement_count,
            (SELECT COUNT(*) FROM growth_records grc 
             WHERE grc.child_id = c.child_id 
             AND MONTH(grc.measurement_date) = MONTH(CURRENT_DATE) 
             AND YEAR(grc.measurement_date) = YEAR(CURRENT_DATE)
             AND COALESCE(grc.muac_measurement, 0) > 0) as month_muac_count
    FROM children c
    LEFT JOIN barangays b ON c.barangay_id = b.barangay_id
    LEFT JOIN guardians g ON c.guardian_id = g.guardian_id
    LEFT JOIN growth_records gr ON gr.record_id = (
        SELECT gr2.record_id
        FROM growth_records gr2
        WHERE gr2.child_id = c.child_id';

if ($getMonthFrom > 0 && $getMonthTo > 0) $baseSql .= " AND MONTH(gr2.measurement_date) BETWEEN $getMonthFrom AND $getMonthTo";
if ($getYear > 0) $baseSql .= " AND YEAR(gr2.measurement_date) = $getYear";

$hasDateFilter = ($getMonthFrom > 0 || $getYear > 0);

if (!$hasDateFilter && $cutoffRecordId > 0) {
    $baseSql .= "\n        ORDER BY (gr2.record_id > $cutoffRecordId) DESC, gr2.measurement_date DESC, gr2.record_id DESC\n        LIMIT 1\n    )";
} else {
    $baseSql .= "\n        ORDER BY gr2.measurement_date DESC, gr2.record_id DESC\n        LIMIT 1\n    )";
}

$whereClauses = ["c.status = 'Active'"];
$bindParams = [];
$bindTypes = "";

// Role-based restrictions
if ($isBns) {
    $whereClauses[] = "(
        SELECT grb.recorded_by FROM growth_records grb
        JOIN users u ON grb.recorded_by = u.user_id
        WHERE grb.child_id = c.child_id AND u.role = 'Barangay Nutrition Scholars'
        ORDER BY grb.measurement_date DESC, grb.record_id DESC
        LIMIT 1
    ) IS NULL OR (
        SELECT grb.recorded_by FROM growth_records grb
        JOIN users u ON grb.recorded_by = u.user_id
        WHERE grb.child_id = c.child_id AND u.role = 'Barangay Nutrition Scholars'
        ORDER BY grb.measurement_date DESC, grb.record_id DESC
        LIMIT 1
    ) = ?";
    $bindParams[] = $currentUserId;
    $bindTypes .= "i";
}

// Barangay filters (clamped by role permissions if not admin)
if ($getBarangayId > 0) {
    $targetBrgyId = (($isBns || $isHw || $isStaff) && $assignedBarangayId > 0) ? $assignedBarangayId : $getBarangayId;
    $whereClauses[] = "c.barangay_id = ?";
    $bindParams[] = $targetBrgyId;
    $bindTypes .= "i";
} elseif ($getBarangayName !== '') {
    if (!$isBns && !$isHw && !$isStaff) {
        $whereClauses[] = "LOWER(b.barangay_name) = LOWER(?)";
        $bindParams[] = $getBarangayName;
        $bindTypes .= "s";
    } else if ($assignedBarangayId > 0) {
        $whereClauses[] = "c.barangay_id = ?";
        $bindParams[] = $assignedBarangayId;
        $bindTypes .= "i";
    }
} elseif (($isBns || $isHw || $isStaff) && $assignedBarangayId > 0) {
    $whereClauses[] = "c.barangay_id = ?";
    $bindParams[] = $assignedBarangayId;
    $bindTypes .= "i";
}

// Period record presence filter
if ($getMonthFrom > 0 || $getYear > 0) {
    $whereClauses[] = "gr.record_id IS NOT NULL";
}

$baseSql .= " WHERE " . implode(" AND ", $whereClauses);
$baseSql .= " ORDER BY b.barangay_name ASC, g.last_name ASC, g.first_name ASC, g.middle_name ASC, g.suffix ASC, c.last_name ASC, c.first_name ASC";

$stmtList = $conn->prepare($baseSql);
if ($stmtList) {
    if (!empty($bindTypes)) {
        $stmtList->bind_param($bindTypes, ...$bindParams);
    }
    $stmtList->execute();
    $result = $stmtList->get_result();
} else {
    $result = false;
}

$totalChildren = 0;
$totalMale = 0;
$totalFemale = 0;
$totalIP = 0;
$rows = [];

$unmeasuredCount = 0;
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $row['latest_hfa'] = '—';
        $row['latest_wfa'] = '—';
        $row['latest_wflh'] = '—';
        $row['latest_muac_status'] = '—';
        $row['latest_age_months'] = $row['age_in_months'] ?? null;

        if (!$hasDateFilter && $cutoffRecordId > 0 && $row['latest_record_id'] <= $cutoffRecordId) {
            $row['latest_measurement_date'] = null;
            $row['latest_height'] = null;
            $row['latest_weight'] = null;
            $row['latest_muac'] = null;
            $row['latest_record_id'] = null;
        }

        if (!empty($row['latest_measurement_date']) && !empty($row['birthdate'])) {
            $latestAgeMonths = null;
            try {
                $b = new DateTime($row['birthdate']);
                $m = new DateTime($row['latest_measurement_date']);
                if ($m >= $b) {
                    $diff = $b->diff($m);
                    $latestAgeMonths = ($diff->y * 12) + $diff->m;
                    if ($latestAgeMonths < 0) $latestAgeMonths = 0;
                }
            } catch (Exception $e) { $latestAgeMonths = null; }

            if ($latestAgeMonths !== null) {
                $row['latest_age_months'] = $latestAgeMonths;
                
                // Report Filtering: Only include children aged 0-59 months
                if ($isPrintReport && ($latestAgeMonths > 59)) {
                    continue;
                }

                // Calculate Height/Weight Statuses if data exists
                if ($row['latest_height'] > 0 && $row['latest_weight'] > 0) {
                    $normalizedSex = ucfirst(strtolower($row['sex'] ?? ''));
                    $weightOutOfRange = false;
                    $heightOutOfRange = false;
                    $wflOutOfRange = false;

                    $weightRef = fetchClosestGrowthReference($conn, GROWTH_WEIGHT_TABLE, $latestAgeMonths, $normalizedSex, $weightOutOfRange);
                    $heightRef = fetchClosestGrowthReference($conn, GROWTH_HEIGHT_TABLE, $latestAgeMonths, $normalizedSex, $heightOutOfRange);
                    $wflAgeGroup = resolveWeightForLengthAgeGroup((int)$latestAgeMonths);
                    $wflRef = null;
                    if ($wflAgeGroup === null) {
                        $wflOutOfRange = true;
                    } else {
                        $wflRef = fetchWeightForLengthReferenceByAgeGroup($conn, $normalizedSex, $wflAgeGroup, (float)$row['latest_height'], $wflOutOfRange);
                    }

                    $row['latest_wfa']  = $weightRef ? (determineWeightForAgeStatus((float)$row['latest_weight'], $weightRef) ?? 'N/A') : 'N/A';
                    $row['latest_hfa']  = $heightRef ? (determineHeightForAgeStatus((float)$row['latest_height'], $heightRef) ?? 'N/A') : 'N/A';
                    $row['latest_wflh'] = $wflRef    ? (determineWeightForLengthStatus((float)$row['latest_weight'], $wflRef) ?? 'N/A') : 'N/A';
                }

                // Always calculate MUAC Status if MUAC exists
                if ($row['latest_muac'] > 0) {
                    $row['latest_muac_status'] = determineMuacStatus((float)$row['latest_muac']) ?? 'N/A';
                }
            }
        }

        if (empty($row['latest_measurement_date']) || $row['latest_height'] <= 0 || $row['latest_weight'] <= 0) {
            // In report mode, we only want to list children with valid measurements who are in the age range.
            if ($isPrintReport) {
                // If we don't have enough data for a full report, skip this row in print mode
                if (empty($row['latest_measurement_date']) || ($row['latest_height'] <= 0 && $row['latest_weight'] <= 0)) {
                    continue;
                }
            }
            if ($row['latest_height'] <= 0 && $row['latest_weight'] <= 0) {
                $unmeasuredCount++;
            }
        }

        $rows[] = $row;
        $totalChildren++;
        if ($row['sex'] === 'Male') $totalMale++;
        if ($row['sex'] === 'Female') $totalFemale++;
        if (($row['is_ip'] ?? '') === 'Yes') $totalIP++;
    }
}

if (isset($stmtList) && $stmtList instanceof mysqli_stmt) {
    $stmtList->close();
}

$barangayInfo = null;
$sessionBarangayId = $assignedBarangayId;

if ($sessionBarangayId > 0) {
    $barangayInfo = fetch_barangay_info($conn, $sessionBarangayId);
}

if (!$barangayInfo && $getBarangayId > 0) {
    $barangayInfo = fetch_barangay_info($conn, $getBarangayId);
}

if (!$barangayInfo && $getBarangayName !== '') {
    $barangayInfo = fetch_barangay_info_by_name($conn, $getBarangayName);
}

if (!$barangayInfo) {
    $uniqueBarangayId = null;
    foreach ($rows as $row) {
        if (empty($row['barangay_id'])) {
            continue;
        }
        $candidateId = (int)$row['barangay_id'];
        if ($uniqueBarangayId === null) {
            $uniqueBarangayId = $candidateId;
        } elseif ($uniqueBarangayId !== $candidateId) {
            $uniqueBarangayId = 0;
            break;
        }
    }

    if ($uniqueBarangayId) {
        $barangayInfo = fetch_barangay_info($conn, $uniqueBarangayId);
    }
}

$uniqueBarangays = [];
foreach ($rows as $row) {
    if (!empty($row['barangay_name']) && !in_array($row['barangay_name'], $uniqueBarangays)) {
        $uniqueBarangays[] = $row['barangay_name'];
    }
}
sort($uniqueBarangays);

if (!$barangayInfo) {
    $fallback = $conn->query('SELECT barangay_name, city, province FROM barangays ORDER BY barangay_name ASC LIMIT 1');
    if ($fallback && $fallback->num_rows > 0) {
        $barangayInfo = $fallback->fetch_assoc();
    }
}
$printBarangayName = 'All Barangays';
if ($getBarangayName !== '') {
    $printBarangayName = $getBarangayName;
} elseif ($barangayInfo && ($getBarangayId > 0 || $isHw && $assignedBarangayId > 0)) {
    $printBarangayName = $barangayInfo['barangay_name'] ?? $printBarangayName;
}
$printCity = $barangayInfo['city'] ?? '__________';
$printProvince = $barangayInfo['province'] ?? '__________';
$hidePrintBarangayColumnDefault = ($getBarangayId > 0) || ($getBarangayName !== '') || ($isHw && $assignedBarangayId > 0);
$printBodyClass = $hidePrintBarangayColumnDefault ? ' print-hide-barangay' : '';
if ($isPrintReport) {
    $printBodyClass .= ' print-report-mode';
}

$barangaysQuery = $conn->query('SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC');
$barangaysList = [];
if ($barangaysQuery) {
    while ($b = $barangaysQuery->fetch_assoc()) {
        $barangaysList[] = $b;
    }
}
$limit_barangay = in_array($currentRole, ['Barangay Nutrition Scholars', 'Health Worker', 'Staff'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Profiles</title>
    <link rel="stylesheet" href="css/tailwind.css">
    <link rel="stylesheet" href="css/child_profiles.css">
    <link rel="stylesheet" href="css/growth-status.css">
    <?php if ($isPrintReport): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <?php endif; ?>

</head>
<body class="bg-slate-100 text-slate-900 font-sans text-[14px]<?= $printBodyClass ?>" data-server-today="<?= date('Y-m-d'); ?>" data-hide-print-barangay="<?= $hidePrintBarangayColumnDefault ? '1' : '0' ?>" data-print-barangay-default="<?= htmlspecialchars($printBarangayName) ?>">
<?php include 'sidebar.php'; ?>

<main class="main-content min-h-screen px-4 md:px-9 py-6 md:py-8 pb-16 space-y-5 print-wrap">

    <div id="toastContainer"></div>

    <!-- Page Header -->
    <div class="no-print flex flex-col gap-3 mb-5 justify-between md:flex-row md:items-center">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-lg">👶</div>
            <div>
                <h1 class="text-lg font-bold text-slate-900">Child Profiles</h1>
                <p class="mt-0.5 text-xs text-slate-500">View and manage all registered children</p>
            </div>
        </div>
        <div class="flex items-center gap-2 w-full md:w-auto md:min-w-[515px] lg:min-w-[615px]">
            <div class="relative flex-1 min-w-[315px]">
                <svg class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="searchInput" placeholder="Search name, address, barangay, caregiver…" class="w-full rounded-md border border-slate-300 bg-white py-2 pl-7 pr-3 text-[0.8rem] text-slate-900 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100" />
            </div>
            <span id="rowCount" class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-gradient-to-r from-emerald-50 to-teal-50 px-3 py-1.5 text-[0.72rem] font-bold text-emerald-800 shadow-sm whitespace-nowrap">
                <span id="rowCountNumber" class="inline-flex min-w-7 items-center justify-center rounded-full bg-emerald-600 px-2 py-0.5 text-[0.72rem] font-extrabold leading-none text-white"><?= (int)$totalChildren ?></span>
                <span class="uppercase tracking-[0.12em]">records</span>
            </span>
        </div>
    </div>



    <!-- Toolbar -->
    <div class="no-print mb-4 flex flex-col gap-2.5 child-toolbar">
        <div class="flex flex-wrap items-center gap-2.5">
            <span class="text-[0.75rem] font-semibold uppercase tracking-[0.14em] text-slate-500">Filter by:</span>
            <?php if ($isAdmin): ?>
            <select id="barangayFilter" class="h-9 rounded-md border border-slate-300 bg-white px-3 pr-8 text-[0.8rem] text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
                <option value="">All Barangays</option>
                <?php foreach ($uniqueBarangays as $b): ?>
                    <option value="<?= strtolower(htmlspecialchars($b)) ?>"><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select id="sexFilter" class="h-9 rounded-md border border-slate-300 bg-white px-3 pr-8 text-[0.8rem] text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
                <option value="">All Sex (Boys & Girls)</option>
                <option value="male">Male (Boys)</option>
                <option value="female">Female (Girls)</option>
            </select>
            <button type="button" id="btnToggleFilters" class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                More Filters
                <svg id="iconToggleFilters" class="transition-transform duration-200" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <button type="button" id="btnGenerateReport" class="inline-flex items-center gap-2 rounded-md border border-transparent bg-blue-600 px-3 py-2 text-[0.8rem] font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white"><path d="M6 9V3h12v6"/><path d="M6 21H4a2 2 0 0 1-2-2v-5h20v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="7" rx="1"/></svg>
                Print
            </button>
            <button type="button" id="btnExportExcel" class="inline-flex items-center gap-2 rounded-md border border-transparent bg-blue-600 px-3 py-2 text-[0.8rem] font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Export Excel
            </button>
            <?php if ($isAdmin): ?>
            <?php 
                $disableNewPeriod = ($cutoffRecordId > 0 && $unmeasuredCount > 0);
            ?>
            <button type="button" id="btnGeneralClearMeasurements" data-unmeasured="<?= $unmeasuredCount ?>" data-blocked="<?= $disableNewPeriod ? 'true' : 'false' ?>" class="inline-flex items-center gap-2 rounded-md border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100 px-3 py-2 text-[0.8rem] font-semibold shadow-sm transition-colors">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21v-5h5"/></svg>
                New Measurement Period
            </button>
            <?php endif; ?>
            
        </div>

        <!-- Advanced Filters -->
        <div id="advancedFiltersPanel" class="hidden flex-col md:flex-row flex-wrap items-stretch md:items-center gap-3 p-3.5 bg-slate-50 border border-slate-200 rounded-lg shadow-inner w-full mt-1">
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:flex flex-wrap items-center gap-2.5 w-full md:w-auto flex-1">
                <select id="ipFilter" class="h-9 rounded-md border border-slate-300 bg-white px-3 pr-8 text-[0.8rem] text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 w-full col-span-2 sm:col-span-1 lg:w-auto">
                    <option value="">All Groups</option>
                    <option value="yes">Indigenous</option>
                    <option value="no">Non-Indigenous</option>
                </select>

                <div class="flex items-center gap-2 col-span-2 sm:col-span-1 lg:w-auto">
                    <input type="number" id="ageFilter" placeholder="Age (months)" class="h-9 w-full lg:w-[130px] rounded-md border border-slate-300 bg-white px-3 text-[0.8rem] text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100" min="0" />
                </div>

                <select id="hfaFilter" class="h-9 rounded-md border border-slate-300 bg-white px-3 pr-8 text-[0.8rem] text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 w-full col-span-2 sm:col-span-1 lg:w-auto">
                    <option value="">Height-for-Age</option>
                    <option value="normal">Normal Height</option>
                    <option value="tall">Tall</option>
                    <option value="stunted">Stunted</option>
                    <option value="severely stunted">Severely Stunted</option>
                </select>

                <select id="wfaFilter" class="h-9 rounded-md border border-slate-300 bg-white px-3 pr-8 text-[0.8rem] text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 w-full col-span-2 sm:col-span-1 lg:w-auto">
                    <option value="">Weight-for-Age</option>
                    <option value="normal">Normal Weight</option>
                    <option value="underweight">Underweight</option>
                    <option value="severely underweight">Severely Underweight</option>
                    <option value="overweight">Overweight</option>
                </select>

                <select id="wflhFilter" class="h-9 rounded-md border border-slate-300 bg-white px-3 pr-8 text-[0.8rem] text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 w-full col-span-2 sm:col-span-1 lg:w-auto">
                    <option value="">Weight-for-Length/Height </option>
                    <option value="normal">Normal Weight for Height</option>
                    <option value="overweight">Overweight</option>
                    <option value="obese">Obese</option>
                    <option value="wasted">Wasted </option>
                    <option value="severely wasted">Severely Wasted </option>
                </select>

                <select id="muacFilter" class="h-9 rounded-md border border-slate-300 bg-white px-3 pr-8 text-[0.8rem] text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 w-full col-span-2 sm:col-span-1 lg:w-auto">
                    <option value="">MUAC</option>
                    <option value="normal">Normal</option>
                    <option value="moderately wasted">Moderately Wasted</option>
                    <option value="severely wasted">Severely Wasted</option>
                </select>
                
                <button type="button" id="btnResetFilters" class="h-9 inline-flex items-center justify-center gap-1.5 rounded-md border border-rose-200 bg-rose-50 px-4 text-[0.8rem] font-semibold text-rose-600 shadow-sm hover:bg-rose-100 transition-colors col-span-2 sm:col-span-3 lg:col-span-1 lg:ml-auto">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                    Reset
                </button>
            </div>
        </div>
    </div>

    <!-- Print Layout -->
    <div class="print-only print-sheet">
        <div class="print-header print-header-theme px-[6px] pt-[2px] pb-[3px]">
            <div class="print-top">
                <div class="print-version">
                    <span>Version: Mar</span>
                    <span>2021</span>
                </div>
                <div class="print-title-group">
                    <div class="print-title">Community Level e-OPT PLUS Tool</div>
                    <div class="print-title-right">
                        <span class="print-read-first">PLS READ THIS FIRST.</span>
                        <span class="print-date-inline">
                            <span>Date: <?= date('d/m/Y'); ?></span>
                            <span>Year: <?= date('Y'); ?></span>
                        </span>
                    </div>
                </div>
                <img class="print-logo bg-white p-[1px]" src="images/lgu-bislig.png" alt="National Nutrition Council logo">
            </div>
            <div class="print-note-row">
                <div class="print-bar print-bar-theme">
                    <span class="print-label bg-[#963634] text-white font-bold px-[3px] py-[1px] uppercase">THIS TOOL IS FOR:</span>
                    <span class="print-barangay px-[3px] py-[1px] ">Barangay</span>
                </div>
                <div class="print-note">For a maximum of 1000 children in a small or medium sized barangay. For large barangays, use this file for a purok, section, or part of the barangay.</div>
            </div>
        </div>
        <div class="print-band print-band-theme px-[6px] py-[2px] font-bold uppercase" style="margin-top: -45px;">
            <span class="print-band-spacer"></span>
            <span class="print-band-center">WEIGHT FOR AGE, HEIGHT FOR AGE, &amp; WEIGHT FOR LENGTH/HEIGHT STATUS</span>
            <span class="print-band-right">Region: CARAGA</span>
        </div>
        <div class="print-tip text-center px-[4px] py-[2px] choose-barangay" style="margin-top: 10px !important;">CHOOSE FROM DROPDOWN LIST TO FILL IN PROVINCE &gt;&gt; MUNICIPALITY/CITY &gt;&gt; BARANGAY IN SEQUENCE. IF USING FOR A PUROK, PLS TYPE IN PUROK NAME.</div>
        <div class="print-tip mt-[2px] text-center px-[4px] py-[2px] zoom-tip">Tip: To adjust the size of the worksheet to fit your screen, use the zoom level slider at the right bottom corner below. Depending on the size of your screen, set the zoom level between 60-90%.</div>
        <div class="print-meta-row print-meta-theme mt-[4px] py-[2px]">
            <span class="meta-barangay" id="printMetaBarangay">Barangay: <?= htmlspecialchars($printBarangayName) ?></span>
            <span class="meta-city">City: <?= htmlspecialchars($printCity) ?></span>
            <span class="meta-province">Province: <?= htmlspecialchars($printProvince) ?></span>
        </div>
    </div>

    <div class="print-only">
        <table class="print-table">
            <colgroup>
                <col style="width:0.35in"> <!-- Seq -->
                <col style="width:1.0in">  <!-- Address -->
                <col class="print-barangay-col" style="width:0.8in"> <!-- Barangay -->
                <col style="width:1.3in">  <!-- Mother Name -->
                <col style="width:1.3in">  <!-- Child Name -->
                <col style="width:0.5in">  <!-- IP Group -->
                <col style="width:0.3in">  <!-- Sex -->
                <col style="width:0.75in"> <!-- DOB -->
                <col style="width:0.75in"> <!-- Date Measured -->
                <col style="width:0.4in">  <!-- Weight -->
                <col style="width:0.4in">  <!-- Height -->
                <col style="width:0.4in">  <!-- Age -->
                <col style="width:0.55in"> <!-- WFA Status -->
                <col style="width:0.55in"> <!-- HFA Status -->
                <col style="width:0.55in"> <!-- WFLH Status -->
                <col style="width:0.45in"> <!-- MUAC cm -->
                <col style="width:0.55in"> <!-- MUAC Status -->
            </colgroup>
            <thead>
                <tr>
                    <th rowspan="2" class="print-center bg-white text-black border border-black">Child Seq.</th>
                    <th rowspan="2" class="print-center print-h11 bg-white text-black border border-black">Address or Location
                        <span class="print-subhead print-subhead-lg">of Child's Residence</span>
                        <span class="print-th-subnote">(if Purok, Area or Location in the Barangay)</span>
                    </th>
                    <th rowspan="2" class="print-center print-h11 bg-white text-black border border-black print-barangay-col">Barangay</th>
                    <th rowspan="2" class="print-center print-h11 bg-white text-black border border-black">Name of Mother 
                        <span class="print-subhead print-subhead-lg">(Last Name, First Name)</span>
                    </th>
                    <th rowspan="2" class="print-center print-h11 bg-white text-black border border-black">Full Name of Child
                        <span class="print-th-subnote">(Last Name, First Name)</span>
                    </th>
                    <th rowspan="2" class="print-center print-h10 bg-white text-black border border-black">Belongs to a<br>Group?
                        <span class="print-subhead print-subhead-md print-upper">YES/NO</span>
                    </th>
                    <th rowspan="2" class="print-center bg-white text-black border border-black">Sex<br><span class="print-subhead">M/F</span></th>
                    <th colspan="2" class="print-th-note print-center border border-black" style="background-color: #c0392b !important; color: #ffffff;">MEASUREMENT INFORMATION AT FORMAL<br>ENTRY: PLS READ</th>
                    <th rowspan="2" class="print-center print-h11 bg-white text-black border border-black">Weight<br>(kg)</th>
                    <th rowspan="2" class="print-center print-h11 bg-white text-black border border-black">Height<br>(cm)</th>
                    <th colspan="6" class="print-th-status-note print-center border border-black" style="background-color: #c0392b !important; color: #ffffff;">NO DATA ENTRY REQUIRED -<br>AUTOMATIC RESULTS CALCULATION</th>
                </tr>
                <tr>
                    <th class="print-center print-h11 bg-white text-black border border-black">Date of Birth</th>
                    <th class="print-center print-h11 bg-white text-black border border-black print-nowrap">Date Measured</th>
                    <th class="print-center print-h11 bg-white text-black border border-black age-months">Age in Months</th>
                    <th class="print-center print-h11 bg-white text-black border border-black">Weight for<br>Age Status</th>
                    <th class="print-center print-h11 bg-white text-black border border-black">Height for Age<br>Status</th>
                    <th class="print-center print-h11 bg-white text-black border border-black">Weight for<br>L/HT Status</th>
                    <th class="print-center print-h11 bg-white text-black border border-black">MUAC<br>(cm)</th>
                    <th class="print-center print-h11 bg-white text-black border border-black">MUAC<br>Status</th>
                </tr>
            </thead>
            <tbody id="printTableBody">
            <?php if (!empty($rows)): ?>
                <?php $printSeq = 1; ?>
                <?php foreach ($rows as $row): ?>
                <?php
                    $childFullName = build_display_name($row['first_name'] ?? '', $row['middle_name'] ?? '', $row['last_name'] ?? '', $row['suffix'] ?? '');
                    $heightDisplay = $row['latest_height'] > 0 ? number_format((float)$row['latest_height'], 1) : '—';
                    $weightDisplay = $row['latest_weight'] > 0 ? number_format((float)$row['latest_weight'], 1) : '—';
                    $birthdatePrint = '—';
                    if (!empty($row['birthdate'])) {
                        $birthTs = strtotime($row['birthdate']);
                        if ($birthTs) {
                            $birthdatePrint = date('M-d-Y', $birthTs);
                        }
                    }
                    $measurementPrint = '—';
                    if (!empty($row['latest_measurement_date'])) {
                        $measureTs = strtotime($row['latest_measurement_date']);
                        if ($measureTs) {
                            $measurementPrint = date('M-d-Y', $measureTs);
                        }
                    }
                    $ageMonths = null;
                    if (!empty($row['birthdate']) && !empty($row['latest_measurement_date'])) {
                        try {
                            $b = new DateTime($row['birthdate']);
                            $m = new DateTime($row['latest_measurement_date']);
                            $diff = $b->diff($m);
                            $ageMonths = ($diff->y * 12) + $diff->m;
                            if ($ageMonths < 0) $ageMonths = 0;
                        } catch (Exception $e) { $ageMonths = null; }
                    }

                    // Compute current age for display if measurement age is null
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

                    $ageMonthsDisplay = $ageMonths !== null ? $ageMonths : ($currentAgeMonths !== null ? $currentAgeMonths : '—');

                    $addressDisplay = trim((string)($row['address'] ?? ''));
                    if ($addressDisplay === '') {
                        $addressDisplay = $row['barangay_name'] ?? '—';
                    }
                    $barangayDisplay = $row['barangay_name'] ?? '—';
                    $g_ln = trim((string)($row['guardian_last'] ?? ''));
                    $g_fn = trim((string)($row['guardian_first'] ?? ''));
                    $g_mn = trim((string)($row['guardian_middle'] ?? ''));
                    $g_sx = trim((string)($row['guardian_suffix'] ?? ''));

                    $g_parts = [];
                    if ($g_ln !== '') {
                        $g_parts[] = $g_ln . ',';
                    }
                    $g_firstMiddle = trim($g_fn . ' ' . $g_mn);
                    if ($g_firstMiddle !== '') {
                        $g_parts[] = $g_firstMiddle;
                    }
                    if ($g_sx !== '') {
                        $g_parts[] = $g_sx;
                    }

                    $guardianDisplay = trim(implode(' ', $g_parts));
                    $guardianDisplay = $guardianDisplay !== '' ? $guardianDisplay : '—';
                    $sexDisplay = $row['sex'] === 'Male' ? 'M' : ($row['sex'] === 'Female' ? 'F' : '—');
                    
                    // Compute statuses
                    $wfaStatus = 'N/A';
                    $hfaStatus = 'N/A';
                    $wflStatus = 'N/A';
                    $muacStatus = 'N/A';
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

                        $wfaStatus = $weightRef ? (determineWeightForAgeStatus((float)$row['latest_weight'], $weightRef) ?? 'N/A') : 'N/A';
                        $hfaStatus = $heightRef ? (determineHeightForAgeStatus((float)$row['latest_height'], $heightRef) ?? 'N/A') : 'N/A';
                        $wflStatus = $wflRef ? (determineWeightForLengthStatus((float)$row['latest_weight'], $wflRef) ?? 'N/A') : 'N/A';
                        $muacStatus = $row['latest_muac'] > 0 ? (determineMuacStatus((float)$row['latest_muac']) ?? 'N/A') : 'N/A';
                    }

                    $wfaShort = status_abbrev($wfaStatus);
                    $hfaShort = status_abbrev($hfaStatus);
                    $wflhShort = status_abbrev($wflStatus);
                    $muacShort = status_abbrev($muacStatus);
                ?>
                <tr data-print-barangay="<?= strtolower(htmlspecialchars($barangayDisplay)) ?>"
                    data-name="<?= strtolower(htmlspecialchars($childFullName)) ?>"
                    data-guardian="<?= strtolower(htmlspecialchars($guardianDisplay)) ?>"
                    data-address="<?= strtolower(htmlspecialchars($addressDisplay)) ?>"
                    data-barangay="<?= strtolower(htmlspecialchars($barangayDisplay)) ?>"
                    data-sex="<?= strtolower(htmlspecialchars($row['sex'] ?? '')) ?>"
                    data-ip="<?= strtolower($row['is_ip'] ?? 'no') ?>"
                    data-age="<?= $ageMonths !== null ? (int)$ageMonths : ($currentAgeMonths !== null ? (int)$currentAgeMonths : '') ?>"
                    data-hfa="<?= strtolower($hfaStatus) ?>"
                    data-wfa="<?= strtolower($wfaStatus) ?>"
                    data-wflh="<?= strtolower($wflStatus) ?>"
                    data-muac="<?= strtolower($muacStatus) ?>">
                    <td class="print-center"><?= $printSeq ?></td>
                    <td class="print-left"><?= htmlspecialchars($addressDisplay) ?></td>
                    <td class="print-left print-barangay-col"><?= htmlspecialchars($barangayDisplay) ?></td>
                    <td class="print-left"><?= htmlspecialchars($guardianDisplay) ?></td>
                    <td class="print-left"><?= htmlspecialchars($childFullName) ?></td>
                    <td class="print-center"><?= $row['is_ip'] === 'Yes' ? 'YES' : 'NO' ?></td>
                    <td class="print-center"><?= htmlspecialchars($sexDisplay) ?></td>
                    <td class="print-center print-date-cell"><?= htmlspecialchars($birthdatePrint) ?></td>
                    <td class="print-center print-date-cell"><?= htmlspecialchars($measurementPrint) ?></td>
                    <td class="print-center <?= status_cell_class($wfaStatus) === 'status-severe' ? 'status-severe' : '' ?>"><?= htmlspecialchars($weightDisplay) ?></td>
                    <td class="print-center <?= status_cell_class($hfaStatus) === 'status-severe' ? 'status-severe' : '' ?>"><?= htmlspecialchars($heightDisplay) ?></td>
                    <td class="print-center age-months"><?= htmlspecialchars($ageMonthsDisplay) ?></td>
                    <td class="print-center print-status-cell <?= status_cell_class($wfaStatus) ?>" title="<?= htmlspecialchars($wfaStatus) ?>">
                        <?= htmlspecialchars($wfaShort) ?>
                    </td>
                    <td class="print-center print-status-cell <?= status_cell_class($hfaStatus) ?>" title="<?= htmlspecialchars($hfaStatus) ?>">
                        <?= htmlspecialchars($hfaShort) ?>
                    </td>
                    <td class="print-center print-status-cell <?= status_cell_class($wflStatus) ?>" title="<?= htmlspecialchars($wflStatus) ?>">
                        <?= htmlspecialchars($wflhShort) ?>
                    </td>
                    <td class="print-center font-bold">
                        <?= $row['latest_muac'] > 0 ? number_format((float)$row['latest_muac'], 1) : '—' ?>
                    </td>
                    <td class="print-center print-status-cell <?= status_cell_class($muacStatus) ?>" title="<?= htmlspecialchars($muacStatus) ?>">
                        <?= htmlspecialchars($muacShort) ?>
                    </td>
                </tr>
                <?php $printSeq++; ?>
                <?php endforeach; ?>
                <tr id="printNoDataRow" style="display: none;">
                    <td colspan="17" class="print-center py-8 text-slate-400">No records match the current filters.</td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="17" class="print-center py-8 text-slate-400">No child profiles found in the system.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php $screenColumnCount = $isAdmin ? 17 : 16; ?>
    <!-- Screen Table -->
    <div class="screen-only child-table-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="table-stack min-w-full border border-slate-300 text-left text-[0.62rem] leading-tight">
            <colgroup>
                <col style="width:110px"><!-- Address -->
                <?php if ($isAdmin): ?><col style="width:90px"><?php endif; ?><!-- Barangay -->
                <col style="width:100px"><!-- Mother/Caregiver -->
                <col style="width:120px"><!-- Full Name -->
                <col style="width:55px"> <!-- IP Group -->
                <col style="width:45px"> <!-- Sex -->
                <col style="width:72px"> <!-- DOB -->
                <col style="width:72px"> <!-- Date Measured -->
                <col style="width:55px"> <!-- Weight -->
                <col style="width:55px"> <!-- Height -->
                <col style="width:58px"> <!-- Age -->
                <col style="width:70px"> <!-- HFA -->
                <col style="width:70px"> <!-- WFA -->
                <col style="width:70px"> <!-- WFLH -->
                <col style="width:55px"> <!-- MUAC cm -->
                <col style="width:63px"> <!-- MUAC Status -->
                <col style="width:60px"> <!-- Actions -->
            </colgroup>
            <thead class="text-[0.58rem] font-semibold uppercase tracking-wide text-white">
                <tr>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 align-middle">Address / Location</th>
                    <?php if ($isAdmin): ?>
                        <th class="border border-slate-300 bg-black px-2 py-1.5 align-middle">Barangay</th>
                    <?php endif; ?>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 align-middle">Mother / Caregiver</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 align-middle">Full Name of Child</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle">
                        <span class="block">Belongs</span>
                        <span class="block">to IP</span>
                        <span class="block">Group?</span>
                    </th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle">Sex</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle">Date of Birth</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle">Date Measured</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle">Weight (kg)</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle">Height (cm)</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle age-months">Age (months)</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle">Weight for Age Status</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle">Height for Age Status</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle">Weight for L/HT Status</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle">MUAC (cm)</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle">MUAC Status</th>
                    <th class="border border-slate-300 bg-black px-2 py-1.5 text-center align-middle actions-col">Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody" class="bg-white">
            <?php if (!empty($rows)): ?>
                <tr id="screenNoDataRow" style="display: none;">
                    <td colspan="<?= $screenColumnCount ?>" class="px-4 py-10 text-center text-slate-400">
                        <div class="mb-3 text-3xl">🔍</div>
                        <p class="mb-2 text-sm font-semibold">No matching records found.</p>
                    </td>
                </tr>
                <?php foreach ($rows as $i => $row): ?>
                <?php
                    $childFullName = build_display_name($row['first_name'] ?? '', $row['middle_name'] ?? '', $row['last_name'] ?? '', $row['suffix'] ?? '');
                    $heightDisplay = $row['latest_height'] > 0 ? number_format((float)$row['latest_height'], 1) : '—';
                    $weightDisplay = $row['latest_weight'] > 0 ? number_format((float)$row['latest_weight'], 1) : '—';
                    $birthdateDisplay = '—';
                    if (!empty($row['birthdate'])) {
                        $birthTs = strtotime($row['birthdate']);
                        if ($birthTs) {
                            $birthdateDisplay = date('M-d-y', $birthTs);
                        }
                    }
                    $measurementDisplay = '—';
                    if (!empty($row['latest_measurement_date'])) {
                        $measureTs = strtotime($row['latest_measurement_date']);
                        if ($measureTs) {
                            $measurementDisplay = date('M-d-y', $measureTs);
                        }
                    }
                    // Determine current age for display and button eligibility
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

                    $ageMonths = null;
                    if (!empty($row['birthdate']) && !empty($row['latest_measurement_date'])) {
                        try {
                            $b = new DateTime($row['birthdate']);
                            $m = new DateTime($row['latest_measurement_date']);
                            $diff = $b->diff($m);
                            $ageMonths = ($diff->y * 12) + $diff->m;
                            if ($ageMonths < 0) $ageMonths = 0;
                        } catch (Exception $e) { $ageMonths = null; }
                    }
                    $ageMonthsDisplay = $ageMonths !== null ? $ageMonths : ($currentAgeMonths !== null ? $currentAgeMonths : '—');

                    $addressDisplay = trim((string)($row['address'] ?? ''));
                    if ($addressDisplay === '') {
                        $addressDisplay = $row['barangay_name'] ?? '—';
                    }
                    $barangayDisplay = $row['barangay_name'] ?? '—';
                    $g_ln = trim((string)($row['guardian_last'] ?? ''));
                    $g_fn = trim((string)($row['guardian_first'] ?? ''));
                    $g_mn = trim((string)($row['guardian_middle'] ?? ''));
                    $g_sx = trim((string)($row['guardian_suffix'] ?? ''));

                    $g_parts = [];
                    if ($g_ln !== '') {
                        $g_parts[] = $g_ln . ',';
                    }
                    $g_firstMiddle = trim($g_fn . ' ' . $g_mn);
                    if ($g_firstMiddle !== '') {
                        $g_parts[] = $g_firstMiddle;
                    }
                    if ($g_sx !== '') {
                        $g_parts[] = $g_sx;
                    }

                    $guardianDisplay = trim(implode(' ', $g_parts));
                    $guardianDisplay = $guardianDisplay !== '' ? $guardianDisplay : '—';
                    $sexDisplay = $row['sex'] === 'Male' ? 'M' : ($row['sex'] === 'Female' ? 'F' : '—');

                    // Compute statuses
                    $wfaStatus = 'N/A';
                    $hfaStatus = 'N/A';
                    $wflStatus = 'N/A';
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

                        $wfaStatus = $weightRef ? (determineWeightForAgeStatus((float)$row['latest_weight'], $weightRef) ?? 'N/A') : 'N/A';
                        $hfaStatus = $heightRef ? (determineHeightForAgeStatus((float)$row['latest_height'], $heightRef) ?? 'N/A') : 'N/A';
                        $wflStatus = $wflRef ? (determineWeightForLengthStatus((float)$row['latest_weight'], $wflRef) ?? 'N/A') : 'N/A';
                    }

                    $wfaShort = status_abbrev($wfaStatus);
                    $hfaShort = status_abbrev($hfaStatus);
                    $wflhShort = status_abbrev($wflStatus);
                    $muacStatus = $row['latest_muac_status'] ?? '—';
                    $muacShort = status_abbrev($muacStatus);
                ?>
                <?php $is59 = ((int)$ageMonthsDisplay === 59); ?>
                <tr
                    data-child-id="<?= (int)$row['child_id'] ?>"
                    data-name="<?= strtolower(htmlspecialchars($childFullName)) ?>"
                    data-first-name="<?= htmlspecialchars($row['first_name']) ?>"
                    data-middle-name="<?= htmlspecialchars($row['middle_name'] ?? '') ?>"
                    data-last-name="<?= htmlspecialchars($row['last_name']) ?>"
                    data-suffix="<?= htmlspecialchars($row['suffix'] ?? '') ?>"
                    data-barangay="<?= strtolower(htmlspecialchars($barangayDisplay)) ?>"
                    data-guardian="<?= strtolower(htmlspecialchars($guardianDisplay)) ?>"
                    data-guardian-first="<?= htmlspecialchars($row['guardian_first']) ?>"
                    data-guardian-last="<?= htmlspecialchars($row['guardian_last']) ?>"
                    data-address="<?= htmlspecialchars($row['address'] ?? '') ?>"
                    data-sex="<?= htmlspecialchars($row['sex']) ?>"
                    data-ip="<?= htmlspecialchars($row['is_ip']) ?>"
                    data-birthdate="<?= htmlspecialchars($row['birthdate']) ?>"
                    data-age="<?= htmlspecialchars((string)$ageMonthsDisplay) ?>"
                    data-wfa="<?= strtolower(htmlspecialchars($wfaStatus)) ?>"
                    data-hfa="<?= strtolower(htmlspecialchars($hfaStatus)) ?>"
                    data-wflh="<?= strtolower(htmlspecialchars($wflStatus)) ?>"
                    data-muac="<?= strtolower(htmlspecialchars($muacStatus)) ?>"
                    class="hover:bg-slate-50 <?= $is59 ? 'row-59mo' : '' ?>"
                >
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-slate-700" data-label="Address / Location">
                        <?= htmlspecialchars($addressDisplay) ?>
                    </td>
                    <?php if ($isAdmin): ?>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-slate-700" data-label="Barangay">
                        <?= htmlspecialchars($barangayDisplay) ?>
                    </td>
                    <?php endif; ?>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-slate-700" data-label="Mother / Caregiver">
                        <?= htmlspecialchars($guardianDisplay) ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle" data-label="Full Name of Child">
                        <div class="font-semibold text-slate-900 text-[0.72rem]">
                            <?= htmlspecialchars($childFullName) ?>
                        </div>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center text-slate-700" data-label="Belongs to IP Group?">
                        <?= $row['is_ip'] === 'Yes' ? 'Yes' : 'No' ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center text-slate-700" data-label="Sex">
                        <?= htmlspecialchars($sexDisplay) ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center text-[0.62rem] text-slate-700 whitespace-nowrap" data-label="Date of Birth">
                        <?= htmlspecialchars($birthdateDisplay) ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center text-[0.62rem] text-slate-700 whitespace-nowrap" data-label="Date Measured">
                        <?= htmlspecialchars($measurementDisplay) ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center font-bold <?= status_cell_class($wfaStatus) === 'status-severe' ? 'status-severe' : '' ?>" data-label="Weight (kg)">
                        <?= htmlspecialchars($weightDisplay) ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center font-bold <?= status_cell_class($hfaStatus) === 'status-severe' ? 'status-severe' : '' ?>" data-label="Height (cm)">
                        <?= htmlspecialchars($heightDisplay) ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center age-months" data-label="Age (months)">
                        <?= htmlspecialchars((string)$ageMonthsDisplay) ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center font-semibold <?= status_cell_class($wfaStatus) ?>" title="<?= htmlspecialchars($wfaStatus) ?>" data-label="Weight for Age Status">
                        <?= htmlspecialchars($wfaShort) ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center font-semibold <?= status_cell_class($hfaStatus) ?>" title="<?= htmlspecialchars($hfaStatus) ?>" data-label="Height for Age Status">
                        <?= htmlspecialchars($hfaShort) ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center font-semibold <?= status_cell_class($wflStatus) ?>" title="<?= htmlspecialchars($wflStatus) ?>" data-label="Weight for L/HT Status">
                        <?= htmlspecialchars($wflhShort) ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center text-slate-700 font-bold" data-label="MUAC (cm)">
                        <?= $row['latest_muac'] > 0 ? number_format((float)$row['latest_muac'], 1) : '—' ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center font-semibold <?= status_cell_class($muacStatus) ?>" title="<?= htmlspecialchars($muacStatus) ?>" data-label="MUAC Status">
                        <?= htmlspecialchars($muacShort) ?>
                    </td>
                    <td class="border border-slate-300 px-2 py-1.5 align-middle text-center actions-cell" data-label="Actions">
                        <div class="relative inline-flex">
                            <button type="button" class="action-menu-btn inline-flex h-8 w-8 items-center justify-center rounded-full border border-blue-100 bg-blue-50 text-blue-600 hover:bg-blue-100 hover:border-blue-200 transition-colors shadow-sm" aria-label="Open actions" aria-expanded="false">
                                <span class="text-lg font-bold">⋮</span>
                            </button>
                            <div class="action-menu hidden w-40 rounded-lg border border-slate-200 bg-white p-1 shadow-lg">
                                <a class="flex items-center gap-2 rounded-md px-3 py-2 text-left text-[0.78rem] font-semibold text-emerald-700 hover:bg-emerald-50" href="view_child_profile.php?child_id=<?= (int)$row['child_id'] ?>">
                                    <svg class="shrink-0 opacity-90" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                    View
                                </a>
                                <?php if (!$isStaff): ?>
                                <button type="button" class="btn-open-update flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-[0.78rem] font-semibold text-blue-700 hover:bg-blue-50" data-child-id="<?= (int)$row['child_id'] ?>" data-mode="measurement">
                                    <svg class="shrink-0 opacity-90" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25z"/><path d="M14.06 6.19l1.77-1.77a1.5 1.5 0 1 1 2.12 2.12l-1.77 1.77"/></svg>
                                    Update Measurement
                                </button>
                                <?php if ($currentAgeMonths !== null && $currentAgeMonths >= 6 && $currentAgeMonths <= 59): ?>
                                <button type="button" class="btn-open-update flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-[0.78rem] font-semibold text-blue-700 hover:bg-blue-50" data-child-id="<?= (int)$row['child_id'] ?>" data-mode="muac">
                                    <svg class="shrink-0 opacity-90" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Update MUAC
                                </button>
                                <?php endif; ?>
                                <button type="button" class="btn-open-update flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-[0.78rem] font-semibold text-indigo-700 hover:bg-indigo-50" data-child-id="<?= (int)$row['child_id'] ?>" data-mode="profile">
                                    <svg class="shrink-0 opacity-90" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    Edit Profile
                                </button>
                                <button type="button" class="btn-open-archive flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-[0.78rem] font-semibold text-rose-700 hover:bg-rose-50" data-child-id="<?= (int)$row['child_id'] ?>">
                                    <svg class="shrink-0 opacity-90" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                                    Archive
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="<?= $screenColumnCount ?>" class="px-4 py-10 text-center text-slate-400">
                        <div class="mb-3 text-3xl">👶</div>
                        <p class="mb-2 text-sm">No child profiles found.</p>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>

    <!-- Update Profile Modal (outside <main> so fixed positioning covers full viewport) -->
    <div id="updateModal" class="fixed inset-0 z-[9999] flex items-center justify-center p-5 opacity-0 invisible pointer-events-none transition-opacity duration-200" aria-hidden="true">
        <div id="updateModalBackdrop" class="absolute inset-0 bg-slate-900/55 backdrop-blur-md"></div>
        <div class="relative z-10 w-full max-w-5xl transform overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/70 shadow-[0_30px_80px_-40px_rgba(15,23,42,0.6)] transition duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] translate-y-4 scale-95" id="updateModalBox" role="dialog" aria-modal="true" aria-labelledby="updateModalTitle">
            <div class="h-1.5 bg-gradient-to-r from-blue-600 via-blue-500 to-sky-400"></div>
            <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-xl">✏️</div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900" id="updateModalTitle">Update Child Profile</h3>
                    <p class="mt-0.5 text-[0.76rem] text-slate-500" id="updateModalInstruction">Enter the latest nutrition measurement for this child</p>
                </div>
            </div>
            <div class="max-h-[78vh] overflow-y-auto">
            <form id="updateProfileForm" class="px-5 pt-5 pb-4">
                <input type="hidden" name="record_id" id="record_id">
                <input type="hidden" name="child_id" id="child_id">
                <input type="hidden" name="weight_id" id="weight_id">
                <input type="hidden" name="height_id" id="height_id">
                <input type="hidden" name="wfl_id" id="wfl_id">
                <input type="hidden" name="muac_id" id="muac_id">

                <!-- ── Editable Child Info Accordion ── -->
                <details id="profileEditSection" class="group mb-5 rounded-xl border border-slate-200 bg-slate-50 shadow-sm overflow-hidden">
                    <summary class="flex cursor-pointer items-center justify-between p-3.5 focus:outline-none focus-visible:bg-slate-100 transition-colors">
                        <div class="flex items-center gap-2 text-[0.7rem] font-bold uppercase tracking-wide text-slate-600">
                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-[0.8rem] shadow-sm">👶</span>
                            Edit Profile Details
                        </div>
                        <span class="text-slate-400 transition-transform duration-300 group-open:rotate-180">
                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
                        </span>
                    </summary>
                    <div class="border-t border-slate-200 px-4 pb-4 pt-3 bg-white">
                        <div class="grid grid-cols-1 gap-x-5 gap-y-3 sm:grid-cols-2">
                            <div class="flex flex-col gap-1 text-[0.78rem]">
                                <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">First Name</label>
                                <input type="text" name="first_name" id="edit_first_name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100" />
                            </div>
                            <div class="flex flex-col gap-1 text-[0.78rem]">
                                <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Middle Name</label>
                                <input type="text" name="middle_name" id="edit_middle_name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100" />
                            </div>
                            <div class="flex flex-col gap-1 text-[0.78rem]">
                                <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Last Name</label>
                                <input type="text" name="last_name" id="edit_last_name" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100" />
                            </div>
                            <div class="flex flex-col gap-1 text-[0.78rem]">
                                <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Suffix (e.g. JR, III)</label>
                                <input type="text" name="suffix" id="edit_suffix" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100" />
                            </div>
                            <div class="flex flex-col gap-1 text-[0.78rem]">
                                <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Date of Birth</label>
                                <input type="date" name="birthdate" id="edit_birthdate" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100" />
                            </div>
                            <div class="flex flex-col gap-1 text-[0.78rem]">
                                <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Sex</label>
                                <select name="sex" id="edit_sex" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100 bg-white">
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-1 text-[0.78rem] sm:col-span-2">
                                <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Address / Location</label>
                                <input type="text" name="address" id="edit_address" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100" />
                            </div>
                             <div class="flex flex-col gap-1 text-[0.78rem] sm:col-span-2">
                                 <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Barangay</label>
                                 <select name="barangay_id" id="edit_barangay_id" required class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100 bg-white" <?= $limit_barangay ? 'disabled' : '' ?>>
                                     <option value="">Select barangay</option>
                                     <?php foreach ($barangaysList as $b): ?>
                                         <option value="<?= htmlspecialchars($b['barangay_id']) ?>"><?= htmlspecialchars($b['barangay_name']) ?></option>
                                     <?php endforeach; ?>
                                 </select>
                                 <?php if ($limit_barangay): ?>
                                     <input type="hidden" name="barangay_id" id="hidden_edit_barangay_id" value="" />
                                 <?php endif; ?>
                             </div>
                             <div class="flex flex-col gap-1 text-[0.78rem] sm:col-span-2">
                                 <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Assigned Barangay Nutrition Scholar (BNS)</label>
                                 <div class="flex items-center gap-2">
                                     <input type="text" id="edit_designated_user_name" readonly
                                            class="<?= $isBns ? 'w-full rounded-md border border-slate-800 bg-black px-3 py-1.5 text-[0.82rem] text-white shadow-sm outline-none cursor-default font-medium' : 'w-full rounded-md border border-slate-300 bg-slate-50 px-3 py-1.5 text-[0.82rem] text-slate-700 shadow-sm outline-none cursor-default font-medium animate-pulse' ?>"
                                            placeholder="No BNS Assigned" />
                                     <button type="button" id="btn_change_bns" <?= $isBns ? 'disabled aria-disabled="true"' : '' ?>
                                             class="<?= $isBns ? 'shrink-0 rounded-md bg-black px-3 py-1.5 text-[0.82rem] font-bold text-white border border-slate-800 cursor-not-allowed' : 'shrink-0 rounded-md bg-blue-50 px-3 py-1.5 text-[0.82rem] font-bold text-blue-600 border border-blue-200/50 hover:bg-blue-100/70 transition-all focus:outline-none' ?>">
                                         Change
                                     </button>
                                 </div>
                                 <input type="hidden" name="designated_user_id" id="edit_designated_user_id" value="" />
                             </div>
                            <div class="flex flex-col gap-1 text-[0.78rem]">
                                <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Mother/Caregiver First</label>
                                <input type="text" name="guardian_first_name" id="edit_g_first" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100" />
                            </div>
                            <div class="flex flex-col gap-1 text-[0.78rem]">
                                <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Mother/Caregiver Middle</label>
                                <input type="text" name="guardian_middle_name" id="edit_g_middle" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100" />
                            </div>
                            <div class="flex flex-col gap-1 text-[0.78rem]">
                                <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Mother/Caregiver Last</label>
                                <input type="text" name="guardian_last_name" id="edit_g_last" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100" />
                            </div>
                            <div class="flex flex-col gap-1 text-[0.78rem]">
                                <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Mother/Caregiver Suffix</label>
                                <input type="text" name="guardian_suffix" id="edit_g_suffix" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100" />
                            </div>
                            <div class="flex flex-col gap-1 text-[0.78rem]">
                                <label class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">
                                    <span class="block">Belongs to IP Group?</span>
                                </label>
                                <select name="is_ip" id="edit_ip" class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-[0.82rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-100 bg-white">
                                    <option value="No">No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </details>

                <!-- ── New Measurement Inputs ── -->
                <div id="measurementSection" class="mb-4">
                    <div class="mb-2 flex items-center gap-2 text-[0.7rem] font-bold uppercase tracking-wide text-slate-600">
                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-[0.8rem] shadow-sm">📏</span>
                    New Measurement
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 mb-4">
                    <div class="flex flex-col gap-1 text-[0.78rem]">
                        <label for="measurement_date" class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Measurement Date</label>
                        <input type="date" name="measurement_date" id="measurement_date" class="w-full rounded-md border border-slate-300 px-3 py-2 text-[0.85rem] text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
                    </div>
                    <div class="flex flex-col gap-1 text-[0.78rem]">
                        <label for="age_in_months" class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Age (months)</label>
                        <input type="number" name="age_in_months" id="age_in_months" min="0" class="w-full rounded-md border border-slate-300 bg-emerald-50 px-3 py-2 text-[0.85rem] font-bold text-emerald-800 shadow-sm outline-none cursor-default" readonly />
                    </div>
                    <div class="flex flex-col gap-1 text-[0.78rem]">
                        <label for="height" class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Height (cm)</label>
                        <input type="number" step="0.1" min="0" name="height" id="height" placeholder="e.g. 75.5" class="w-full rounded-md border border-slate-300 px-3 py-2 text-[0.85rem] font-bold text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-shadow" />
                    </div>
                    <div class="flex flex-col gap-1 text-[0.78rem]">
                        <label for="weight" class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">Weight (kg)</label>
                        <input type="number" step="0.1" min="0" name="weight" id="weight" placeholder="e.g. 10.2" class="w-full rounded-md border border-slate-300 px-3 py-2 text-[0.85rem] font-bold text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-shadow" />
                    </div>
                    <div id="muacUpdateContainer" class="flex flex-col gap-1 text-[0.78rem]">
                        <label for="muac" class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">MUAC (cm)</label>
                        <input type="number" step="0.1" min="0.1" name="muac_measurement" id="muac" placeholder="e.g. 12.5" class="w-full rounded-md border border-slate-300 px-3 py-2 text-[0.85rem] font-bold text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition-shadow" />
                    </div>
                    </div>
                </div>

                <input type="hidden" name="update_mode" id="update_mode" value="both">

                <!-- Predicted Status Row (live preview) -->
                <div id="previewSection" class="rounded-xl border border-blue-100 bg-blue-50/50 p-3 shadow-sm">
                    <div class="mb-2 flex items-center gap-1.5 text-[0.65rem] font-bold uppercase tracking-wide text-slate-500">
                        Status Preview
                        <span class="inline-flex h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse" id="statusPreviewDot"></span>
                        <span class="text-[0.62rem] normal-case font-medium text-slate-500" id="statusPreviewHint">(enter height &amp; weight)</span>
                    </div>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-4">
                        <div class="flex flex-col gap-0.5 rounded-lg border border-white bg-white/80 px-2 py-1.5 shadow-sm">
                            <span class="text-[0.58rem] font-bold uppercase tracking-wider text-slate-400">Height-for-Age</span>
                            <span class="text-[0.76rem] font-bold text-slate-300" id="info_hfa_status">&mdash;</span>
                        </div>
                        <div class="flex flex-col gap-0.5 rounded-lg border border-white bg-white/80 px-2 py-1.5 shadow-sm">
                            <span class="text-[0.58rem] font-bold uppercase tracking-wider text-slate-400">Weight-for-Age</span>
                            <span class="text-[0.76rem] font-bold text-slate-300" id="info_wfa_status">&mdash;</span>
                        </div>
                        <div class="flex flex-col gap-0.5 rounded-lg border border-white bg-white/80 px-2 py-1.5 shadow-sm">
                            <span class="text-[0.58rem] font-bold uppercase tracking-wider text-slate-400">Weight-for-L/HT</span>
                            <span class="text-[0.76rem] font-bold text-slate-300" id="info_wfl_status">&mdash;</span>
                        </div>
                        <div class="flex flex-col gap-0.5 rounded-lg border border-white bg-white/80 px-2 py-1.5 shadow-sm">
                            <span class="text-[0.58rem] font-bold uppercase tracking-wider text-slate-400">MUAC Status</span>
                            <span class="text-[0.76rem] font-bold text-slate-300" id="info_muac_status">&mdash;</span>
                        </div>
                    </div>
                </div>
                <div id="updateModalMessage" class="mt-3 text-xs"></div>
            </form>
            </div>
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 bg-slate-50 px-5 py-4">
                <div class="flex items-center gap-2">
                    <button type="button" id="btnUpdateCancel" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-[0.82rem] font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Cancel</button>
                    <button type="submit" id="btnUpdateSave" form="updateProfileForm" class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-[0.82rem] font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-blue-300">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Clear Measurement Confirmation Modal -->
    <div id="clearConfirmModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-5 opacity-0 invisible pointer-events-none transition-opacity duration-200" aria-hidden="true">
        <div id="clearConfirmBackdrop" class="absolute inset-0 bg-slate-900/55 backdrop-blur-md"></div>
        <div class="relative z-10 w-full max-w-sm transform overflow-hidden rounded-2xl bg-white shadow-2xl transition duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] translate-y-4 scale-95" id="clearConfirmBox" role="dialog" aria-modal="true" aria-labelledby="clearConfirmTitle">
            <div class="p-6 text-center">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-amber-100">
                    <svg class="h-8 w-8 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <h3 class="mb-2 text-lg font-bold text-slate-900" id="clearConfirmTitle">Clear Measurement Details?</h3>
                <p class="text-sm text-slate-600 leading-relaxed">Are you sure you want to clear these measurement details and move them to history to record a new measurement?</p>
            </div>
            <div class="flex items-center justify-center gap-3 bg-slate-50 px-6 py-4">
                <button type="button" id="btnCancelClear" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">Cancel</button>
                <button type="button" id="btnConfirmClear" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-700 transition-colors">Yes, Clear Details</button>
            </div>
        </div>
    </div>

    <!-- Warning Modal -->
    <div id="warningModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-5 opacity-0 invisible pointer-events-none transition-opacity duration-200" aria-hidden="true">
        <div id="warningBackdrop" class="absolute inset-0 bg-slate-900/55 backdrop-blur-md"></div>
        <div class="relative z-10 w-full max-w-sm transform overflow-hidden rounded-2xl bg-white shadow-2xl transition duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] translate-y-4 scale-95" id="warningBox" role="dialog" aria-modal="true" aria-labelledby="warningTitle">
            <div class="p-6 text-center">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-rose-100">
                    <svg class="h-8 w-8 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <h3 class="mb-2 text-lg font-bold text-slate-900" id="warningTitle">Action Blocked</h3>
                <p class="text-sm text-slate-600 leading-relaxed" id="warningMessage"></p>
            </div>
            <div class="flex items-center justify-center gap-3 bg-slate-50 px-6 py-4">
                <button type="button" id="btnWarningOk" class="rounded-lg bg-rose-600 px-6 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-700 transition-colors w-full">Understood</button>
            </div>
        </div>
    </div>

    <!-- Archive Confirmation Modal -->
    <div id="archiveModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-5 opacity-0 invisible pointer-events-none transition-opacity duration-200" aria-hidden="true">
        <div id="archiveBackdrop" class="absolute inset-0 bg-slate-900/55 backdrop-blur-md"></div>
        <div class="relative z-10 w-full max-w-md transform overflow-hidden rounded-2xl bg-white shadow-2xl transition duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] translate-y-4 scale-95" id="archiveBox" role="dialog" aria-modal="true" aria-labelledby="archiveTitle">
            <div class="p-6">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-rose-100">
                    <svg class="h-8 w-8 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" />
                    </svg>
                </div>
                <h3 class="mb-2 text-lg font-bold text-slate-900 text-center" id="archiveTitle">Archive Child Profile?</h3>
                <p class="text-sm text-slate-600 leading-relaxed text-center mb-4" id="archivePrompt">Are you sure you want to archive this child?</p>

                <div class="space-y-3.5">
                    <div>
                        <label for="archiveReason" class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Status <span class="text-rose-500">*</span></label>
                        <select id="archiveReason" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
                            <option value="">Select status</option>
                            <option value="Archive">Archive</option>
                            <option value="Decease">Decease</option>
                            <option value="OverAge">OverAge</option>
                        </select>
                    </div>
                    <div>
                        <label for="archiveDate" class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Date of Archival <span class="text-rose-500">*</span></label>
                        <input type="date" id="archiveDate" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <p id="archiveError" class="mt-2 text-xs text-rose-600 hidden">Please select a status before archiving.</p>
            </div>
            <div class="flex items-center justify-center gap-3 bg-slate-50 px-6 py-4">
                <button type="button" id="btnArchiveCancel" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">Cancel</button>
                <button type="button" id="btnArchiveConfirm" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-700 transition-colors">Archive</button>
            </div>
        </div>
    </div>

    <!-- Clear Measurement Success Modal -->
    <div id="clearSuccessModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-5 opacity-0 invisible pointer-events-none transition-opacity duration-200" aria-hidden="true">
        <div id="clearSuccessBackdrop" class="absolute inset-0 bg-slate-900/55 backdrop-blur-md"></div>
        <div class="relative z-10 w-full max-w-sm transform overflow-hidden rounded-2xl bg-white shadow-2xl transition duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] translate-y-4 scale-95" id="clearSuccessBox" role="dialog" aria-modal="true" aria-labelledby="clearSuccessTitle">
            <div class="p-6 text-center">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100">
                    <svg class="h-8 w-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h3 class="mb-2 text-lg font-bold text-slate-900" id="clearSuccessTitle">New Measurement Period Started</h3>
                <p class="text-sm text-slate-600 leading-relaxed" id="clearSuccessMessage"></p>
            </div>
            <div class="flex items-center justify-center gap-3 bg-slate-50 px-6 py-4">
                <button type="button" id="btnClearSuccessOk" class="rounded-lg bg-emerald-600 px-6 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 transition-colors w-full">OK</button>
            </div>
        </div>
    </div>
    <!-- ══════════════════════════════
         ACTION BLOCKED MODAL
    ══════════════════════════════ -->
    <div class="fixed inset-0 z-[10000] flex items-center justify-center p-5 opacity-0 invisible pointer-events-none transition-opacity duration-200" id="actionBlockedModal" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-md" id="actionBlockedBackdrop"></div>
        <div class="relative z-10 w-full max-w-md transform overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/70 shadow-[0_30px_80px_-40px_rgba(15,23,42,0.6)] transition duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] translate-y-4 scale-95" id="actionBlockedBox" role="dialog" aria-modal="true" aria-labelledby="actionBlockedTitle">
            <div class="h-1.5 bg-rose-500"></div>
            <div class="px-6 py-8 text-center">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-rose-100 text-rose-600">
                    <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <h3 class="mb-2 text-lg font-bold text-slate-900" id="actionBlockedTitle">Action Blocked</h3>
                <p class="text-sm text-slate-600 leading-relaxed">
                    Action blocked since some children's weight and height were not being updated in the current period.
                </p>
            </div>
            <div class="flex items-center justify-center gap-3 bg-slate-50 px-6 py-4 border-t border-slate-100">
                <button type="button" id="btnActionBlockedOk" class="rounded-lg bg-rose-600 px-6 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-700 transition-colors w-full">Got it</button>
            </div>
        </div>
    </div>
    <!-- ══════════════════════════════
         USER SELECTION MODAL (BNS Pop-up)
    ══════════════════════════════ -->
    <div class="fixed inset-0 z-[10000] flex items-center justify-center p-5 opacity-0 invisible pointer-events-none transition-opacity duration-200" id="userSelectModal" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-md" id="userSelectBackdrop"></div>
        <div class="relative z-10 w-full max-w-md transform overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/70 shadow-[0_30px_80px_-40px_rgba(15,23,42,0.6)] transition duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] translate-y-4 scale-95" id="userSelectBox" role="dialog" aria-modal="true" aria-labelledby="userSelectTitle">

            <div class="h-1.5 bg-gradient-to-r from-blue-600 via-blue-500 to-sky-400"></div>

            <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-xl">👤</div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900" id="userSelectTitle">Assign Designated User</h3>
                    <p class="mt-0.5 text-[0.76rem] text-slate-500">Select the user responsible for this barangay</p>
                </div>
            </div>

            <!-- Barangay indicator -->
            <div class="flex items-center gap-2 bg-blue-50/50 border-b border-blue-100/50 px-5 py-2.5">
                <span class="text-[0.68rem] font-bold uppercase tracking-wider text-blue-500">Barangay:</span>
                <span class="text-[0.78rem] font-bold text-blue-800" id="userSelectBarangayName">—</span>
            </div>

            <div class="px-5 py-5 min-h-[160px]">
                <!-- Loading state -->
                <div id="userSelectLoading" class="flex flex-col items-center justify-center py-10 gap-3">
                    <div class="h-8 w-8 animate-spin rounded-full border-[3px] border-slate-100 border-t-blue-500"></div>
                    <span class="text-[0.78rem] font-medium text-slate-400">Searching active users…</span>
                </div>

                <!-- Empty state -->
                <div id="userSelectEmpty" class="hidden flex-col items-center justify-center py-10 gap-2 text-center">
                    <div class="text-3xl mb-1 filter grayscale">🔍</div>
                    <p class="text-[0.82rem] font-bold text-slate-700">No active users found</p>
                    <p class="text-[0.72rem] text-slate-400 max-w-[220px] leading-relaxed">No Barangay Nutrition Scholar or Health Worker is currently assigned to this barangay.</p>
                </div>

                <!-- User cards list -->
                <div id="userSelectList" class="hidden space-y-2 max-h-60 overflow-y-auto pr-1 custom-scrollbar"></div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 bg-slate-50 px-5 py-4">
                <button type="button" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-[0.82rem] font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50" id="userSelectBackBtn">
                    Cancel
                </button>
                <button type="button" class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-5 py-2 text-[0.82rem] font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:bg-blue-300 disabled:cursor-not-allowed" id="userSelectConfirmBtn" disabled>
                    <span class="us-btn-label">✓ Select User</span>
                    <span class="us-spinner hidden h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                </button>
            </div>

        </div>
    </div>

<script>
window.highlightConfig = {
    requested: <?= $highlightRequested ? 'true' : 'false' ?>,
    childId: <?= (int)$highlightChildId ?>
};
</script>
<script src="javascript/child_profiles.js?v=<?= time() ?>"></script>
<?php if ($isPrintReport): ?>
<script>
    window.saveAsPDF = function() {
        return new Promise((resolve, reject) => {
            if (typeof html2pdf === 'undefined') {
                reject(new Error('html2pdf library is not available.'));
                return;
            }

            const source = document.querySelector('.print-wrap');
            if (!source) {
                reject(new Error('Printable report content not found.'));
                return;
            }

            const sandbox = document.createElement('div');
            sandbox.style.position = 'fixed';
            sandbox.style.left = '-100000px';
            sandbox.style.top = '0';
            sandbox.style.width = '1200px';
            sandbox.style.background = '#ffffff';
            sandbox.style.zIndex = '-1';

            const exportNode = source.cloneNode(true);
            exportNode.querySelectorAll('.no-print').forEach((el) => el.remove());
            exportNode.querySelectorAll('.print-only').forEach((el) => {
                el.classList.remove('print-only');
            });

            sandbox.appendChild(exportNode);
            document.body.appendChild(sandbox);

            const barangayName = (document.getElementById('printMetaBarangay')?.textContent || 'Nut_Status')
                .replace(/Barangay:\s*/i, '')
                .trim()
                .replace(/[^a-z0-9_-]+/gi, '_');

            const options = {
                margin: [0.2, 0.2, 0.2, 0.2],
                filename: `Nut_Status_Report_${barangayName}_${new Date().getFullYear()}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    letterRendering: true
                },
                jsPDF: { unit: 'in', format: 'legal', orientation: 'landscape' },
                pagebreak: { mode: ['css', 'legacy'] }
            };

            html2pdf().set(options).from(exportNode).save().then(() => {
                sandbox.remove();
                resolve();
            }).catch((err) => {
                sandbox.remove();
                reject(err);
            });
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            window.print();
        }, 1500);
    });
</script>
<?php endif; ?>
</body>
</html>