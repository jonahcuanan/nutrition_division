<?php
// update_profile.php
// GET  - fetch latest child profile (previously get_child_profile.php)
// POST - update an existing child profile record

session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access(['expectsJson' => true]);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/growth_utils.php';
require_once __DIR__ . '/activity_logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $update_mode      = isset($_POST['update_mode']) ? $_POST['update_mode'] : 'both';
    $record_id        = isset($_POST['record_id']) ? intval($_POST['record_id']) : null;
    $child_id         = isset($_POST['child_id']) ? intval($_POST['child_id']) : null;
    $measurement_date = isset($_POST['measurement_date']) ? $_POST['measurement_date'] : null;
    $weight           = (isset($_POST['weight']) && $_POST['weight'] !== '') ? floatval($_POST['weight']) : null;
    $height           = (isset($_POST['height']) && $_POST['height'] !== '') ? floatval($_POST['height']) : null;
    $muac             = (isset($_POST['muac_measurement']) && $_POST['muac_measurement'] !== '') ? floatval($_POST['muac_measurement']) : 0;

    if (!$child_id) {
        echo json_encode(['success' => false, 'message' => 'Missing child ID.']);
        exit;
    }

    // 1) Get child + guardian details from children table
    $childSql = "SELECT c.birthdate, c.sex, c.guardian_id, c.first_name, c.middle_name, c.last_name, c.address, c.is_ip,
                g.first_name AS guardian_first, g.last_name AS guardian_last
             FROM children c
             LEFT JOIN guardians g ON c.guardian_id = g.guardian_id
             WHERE c.child_id = ?
             LIMIT 1";
    $childStmt = $conn->prepare($childSql);
    if (!$childStmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    $childStmt->bind_param('i', $child_id);
    $childStmt->execute();
    $childResult = $childStmt->get_result();
    if (!$childResult || !($childRow = $childResult->fetch_assoc())) {
        echo json_encode(['success' => false, 'message' => 'Child not found.']);
        $childStmt->close();
        exit;
    }
    $childStmt->close();

    $originalChild = $childRow;

    // Handle profile updates if mode is 'profile' or 'both'
    $profileUpdated = false;
    if ($update_mode === 'profile' || $update_mode === 'both') {
        $upd_first_name = isset($_POST['first_name']) ? strtoupper(trim($_POST['first_name'])) : null;
        $upd_last_name  = isset($_POST['last_name']) ? strtoupper(trim($_POST['last_name'])) : null;
        $upd_address    = isset($_POST['address']) ? strtoupper(trim($_POST['address'])) : null;
        $upd_sex        = isset($_POST['sex']) ? strtoupper(trim($_POST['sex'])) : null;
        $upd_birthdate  = isset($_POST['birthdate']) ? trim($_POST['birthdate']) : null;
        $upd_is_ip      = isset($_POST['is_ip']) ? strtoupper(trim($_POST['is_ip'])) : null;
        
        $upd_g_first    = isset($_POST['guardian_first_name']) ? strtoupper(trim($_POST['guardian_first_name'])) : null;
        $upd_g_last     = isset($_POST['guardian_last_name']) ? strtoupper(trim($_POST['guardian_last_name'])) : null;

        if ($upd_first_name && $upd_last_name) {
            $updChildSql = "UPDATE children SET first_name=?, last_name=?, address=?, sex=?, birthdate=?, is_ip=? WHERE child_id=?";
            $updChildStmt = $conn->prepare($updChildSql);
            if ($updChildStmt) {
                $updChildStmt->bind_param('ssssssi', $upd_first_name, $upd_last_name, $upd_address, $upd_sex, $upd_birthdate, $upd_is_ip, $child_id);
                $updChildStmt->execute();
                $updChildStmt->close();
                $profileUpdated = true;
            }
            if ($upd_birthdate) $childRow['birthdate'] = $upd_birthdate;
            if ($upd_sex) $childRow['sex'] = $upd_sex;
        }

        if ($upd_g_first && $upd_g_last && $childRow['guardian_id']) {
            $updGuardSql = "UPDATE guardians SET first_name=?, last_name=? WHERE guardian_id=?";
            $updGuardStmt = $conn->prepare($updGuardSql);
            if ($updGuardStmt) {
                $updGuardStmt->bind_param('ssi', $upd_g_first, $upd_g_last, $childRow['guardian_id']);
                $updGuardStmt->execute();
                $updGuardStmt->close();
                $profileUpdated = true;
            }
        }
    }

    // Handle measurement update if mode is 'measurement' or 'both'
    $measurementRecorded = false;
    $detailParts = [];
    $logFirst  = isset($upd_first_name) ? $upd_first_name : ($childRow['first_name'] ?? '');
    $logMid    = $childRow['middle_name'] ?? '';
    $logLast   = isset($upd_last_name)  ? $upd_last_name  : ($childRow['last_name']  ?? '');
    $childFullName = implode(' ', array_filter([$logFirst, $logMid, $logLast]));
    $detailParts[] = 'Child: ' . ($childFullName !== '' ? $childFullName : ('Child #' . $child_id));

    $isMeasurementMode = ($update_mode === 'measurement' || $update_mode === 'muac' || $update_mode === 'both');
    if ($isMeasurementMode) {
        // Default to today if date is missing or empty
        if (!$measurement_date) {
            $measurement_date = (new DateTime('today'))->format('Y-m-d');
        }

        // Enforce that measurement date is today's server date
        $todayServer = (new DateTime('today'))->format('Y-m-d');
        if ($measurement_date !== $todayServer) {
            echo json_encode(['success' => false, 'message' => 'Measurement date must be today (server date).']);
            exit;
        }

        // Fetch cutoff for current session
        $cutoffRecordId = file_exists(__DIR__ . '/measurement_session.txt') ? (int)trim(file_get_contents(__DIR__ . '/measurement_session.txt')) : 0;

        // Fetch the LATEST measurement record to fill in missing gaps and check for recent updates
        $latestMeasSql = "SELECT record_id, height, weight, muac_measurement FROM growth_records WHERE child_id = ? ORDER BY measurement_date DESC, record_id DESC LIMIT 1";
        $latestMeasStmt = $conn->prepare($latestMeasSql);
        $lastRecordId = 0; $lastHeight = 0; $lastWeight = 0; $lastMuac = 0;
        if ($latestMeasStmt) {
            $latestMeasStmt->bind_param('i', $child_id);
            $latestMeasStmt->execute();
            $lResult = $latestMeasStmt->get_result();
            if ($lRow = $lResult->fetch_assoc()) {
                $lastRecordId = (int)$lRow['record_id'];
                $lastHeight = (float)$lRow['height'];
                $lastWeight = (float)$lRow['weight'];
                $lastMuac   = (float)$lRow['muac_measurement'];
            }
            $latestMeasStmt->close();
        }

        $currentMonth = (new DateTime($measurement_date))->format('Y-m');
        $isCurrentSession = ($lastRecordId > $cutoffRecordId);

        $birthdate = $childRow['birthdate'];
        $sex       = $childRow['sex'];

        try {
            $b = new DateTime($birthdate);
            $m = new DateTime($measurement_date);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Invalid date provided.']);
            exit;
        }

        if ($m < $b) {
            echo json_encode(['success' => false, 'message' => 'Measurement date cannot be before birthdate.']);
            exit;
        }



        $diff = $b->diff($m);
        $age_in_months = ($diff->y * 12) + $diff->m;
        if ($age_in_months < 0) $age_in_months = 0;

        // MUAC is only valid for children 6 months and older
        if ($age_in_months < 6) {
            $muac = 0;
        }

        // Enforce limits separately
        $measCountSql = "SELECT COUNT(*) as c FROM growth_records WHERE child_id = ? AND DATE_FORMAT(measurement_date, '%Y-%m') = ? AND is_muac_only = FALSE";
        $stMeas = $conn->prepare($measCountSql);
        $stMeas->bind_param('is', $child_id, $currentMonth);
        $stMeas->execute();
        $measCount = $stMeas->get_result()->fetch_assoc()['c'] ?? 0;
        $stMeas->close();

        $muacCountSql = "SELECT COUNT(*) as c FROM growth_records WHERE child_id = ? AND DATE_FORMAT(measurement_date, '%Y-%m') = ? AND (is_muac_only = TRUE OR muac_measurement > 0)";
        $stMuac = $conn->prepare($muacCountSql);
        $stMuac->bind_param('is', $child_id, $currentMonth);
        $stMuac->execute();
        $muacCount = $stMuac->get_result()->fetch_assoc()['c'] ?? 0;
        $stMuac->close();

        if ($update_mode === 'measurement' || $update_mode === 'both') {
            if ($measCount >= 2) {
                echo json_encode(['success' => false, 'message' => 'Limit reached: This child already has 2 measurement records this month.']);
                exit;
            }
        }

        if ($update_mode === 'muac') {
            if ($muacCount >= 2) {
                echo json_encode(['success' => false, 'message' => 'Limit reached: This child already has 2 MUAC records this month.']);
                exit;
            }
        }

        // Inheritance logic for MUAC mode
        if ($update_mode === 'muac') {
            if (($height === null || $height <= 0) && $lastHeight > 0) $height = $lastHeight;
            if (($weight === null || $weight <= 0) && $lastWeight > 0) $weight = $lastWeight;
            
            // If still no height/weight (new child), allow 0 for MUAC-only updates
            if ($height === null || $height < 0) $height = 0;
            if ($weight === null || $weight < 0) $weight = 0;
        }

        // Decoupled MUAC logic: When updating measurement, MUAC is NOT inherited.
        if ($update_mode === 'measurement') {
            $muac = 0; 
        }

        // Check if we have valid height and weight
        // (Only strictly required for standard measurements, MUAC can exist alone with 0 height/weight)
        if ($update_mode !== 'muac' && ($height <= 0 || $weight <= 0)) {
             echo json_encode(['success' => false, 'message' => 'Height and Weight are required for standard measurement updates.']);
             exit;
        }

        // Update age_in_months in children table
        $childUpdateSql = "UPDATE children SET age_in_months = ? WHERE child_id = ?";
        $childUpdateStmt = $conn->prepare($childUpdateSql);
        if ($childUpdateStmt) {
            $childUpdateStmt->bind_param('ii', $age_in_months, $child_id);
            $childUpdateStmt->execute();
            $childUpdateStmt->close();
        }

        $normalizedSex = ucfirst(strtolower($sex));
        $oorW = false; $oorH = false; $oorL = false;
        
        $weight_id = null;
        $height_id = null;
        $wfl_id    = null;

        // Only compute height/weight statuses if we have valid measurements (> 0)
        if ($height > 0 && $weight > 0) {
            $weightRef = fetchClosestGrowthReference($conn, GROWTH_WEIGHT_TABLE, $age_in_months, $normalizedSex, $oorW);
            $heightRef = fetchClosestGrowthReference($conn, GROWTH_HEIGHT_TABLE, $age_in_months, $normalizedSex, $oorH);
            $wflAgeGroup = resolveWeightForLengthAgeGroup($age_in_months);
            $wflRef = ($wflAgeGroup !== null) ? fetchWeightForLengthReferenceByAgeGroup($conn, $normalizedSex, $wflAgeGroup, $height, $oorL) : null;

            if ($weightRef) $weight_id = (int)$weightRef['weight_id'];
            if ($heightRef) $height_id = (int)$heightRef['height_id'];
            if ($wflRef)    $wfl_id    = (int)$wflRef['wfl_id'];
        }

        $muac_id   = ($muac > 0) ? 1 : null;
        $sessionUserId = (int)$_SESSION['user_id'];

        if ($update_mode === 'muac' && $lastRecordId <= 0) {
            echo json_encode(['success' => false, 'message' => 'No measurement record found to update MUAC. Please update Measurement (Height/Weight) first.']);
            exit;
        }

        $is_muac_only = ($update_mode === 'muac') ? 1 : 0;

        // Insert or Update logic
        if ($update_mode === 'muac') {
            if ($lastMuac <= 0) {
                // First MUAC update: merge into the most recent measurement record
                $growthSql = "UPDATE growth_records 
                              SET muac_measurement = ?, muac_id = ?, recorded_by = ? 
                              WHERE record_id = ?";
                $growthStmt = $conn->prepare($growthSql);
                if ($growthStmt) {
                    $growthStmt->bind_param('diii', $muac, $muac_id, $sessionUserId, $lastRecordId);
                    if ($growthStmt->execute()) {
                        $measurementRecorded = true;
                    }
                    $growthStmt->close();
                }
            } else {
                // Second MUAC update: insert as a new record inheriting height and weight
                $growthSql = "INSERT INTO growth_records (child_id, measurement_date, weight, height, muac_measurement, weight_id, height_id, wfl_id, muac_id, recorded_by, is_muac_only)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $growthStmt = $conn->prepare($growthSql);
                if ($growthStmt) {
                    $growthStmt->bind_param('isdddiiiiii', $child_id, $measurement_date, $weight, $height, $muac, $weight_id, $height_id, $wfl_id, $muac_id, $sessionUserId, $is_muac_only);
                    if ($growthStmt->execute()) {
                        $measurementRecorded = true;
                    }
                    $growthStmt->close();
                }
            }
        } else {
            // Insert new record (Standard Measurement or 'both')
            $growthSql = "INSERT INTO growth_records (child_id, measurement_date, weight, height, muac_measurement, weight_id, height_id, wfl_id, muac_id, recorded_by, is_muac_only)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $growthStmt = $conn->prepare($growthSql);
            if ($growthStmt) {
                $growthStmt->bind_param('isdddiiiiii', $child_id, $measurement_date, $weight, $height, $muac, $weight_id, $height_id, $wfl_id, $muac_id, $sessionUserId, $is_muac_only);
                if ($growthStmt->execute()) {
                    $measurementRecorded = true;
                }
                $growthStmt->close();
            }
        }

        if ($measurementRecorded) {
            $muacPart = ($muac > 0) ? (', MUAC ' . number_format((float)$muac, 1) . ' cm') : '';
            $detailParts[] = 'Measurement: ' . $measurement_date . ', H ' . number_format((float)$height, 1) . ' cm, W ' . number_format((float)$weight, 1) . ' kg' . $muacPart . ', Age ' . (int)$age_in_months . ' mo';
        }
    }

    if ($profileUpdated) {
        $changes = [];
        $compare = function($label, $old, $new) use (&$changes) {
            $oldN = strtoupper(trim((string)$old));
            $newN = strtoupper(trim((string)$new));
            if ($new !== null && $oldN !== $newN) {
                $changes[] = "$label: " . ($old ?: '—') . " -> " . ($new ?: '—');
            }
        };
        $compare('First Name', $originalChild['first_name'], $_POST['first_name'] ?? null);
        $compare('Last Name', $originalChild['last_name'], $_POST['last_name'] ?? null);
        $compare('DOB', $originalChild['birthdate'], $_POST['birthdate'] ?? null);
        $compare('Sex', $originalChild['sex'], $_POST['sex'] ?? null);
        $compare('Address', $originalChild['address'], $_POST['address'] ?? null);
        $compare('Guardian First', $originalChild['guardian_first'], $_POST['guardian_first_name'] ?? null);
        $compare('Guardian Last', $originalChild['guardian_last'], $_POST['guardian_last_name'] ?? null);
        $compare('IP', $originalChild['is_ip'], $_POST['is_ip'] ?? null);

        if (!empty($changes)) {
            $detailParts = array_merge($detailParts, $changes);
        }
    }

    if ($profileUpdated || $measurementRecorded) {
        log_user_activity($conn, (int)$_SESSION['user_id'], 'edit_profile', implode(' | ', $detailParts));

        // Fetch the COMPLETE latest state to return to the frontend
        $finalSql = "SELECT gr.*, c.first_name, c.last_name, c.address, c.sex, c.birthdate, c.is_ip, 
                            g.first_name AS guardian_first, g.last_name AS guardian_last, c.age_in_months
                     FROM children c
                     LEFT JOIN growth_records gr ON gr.record_id = (
                         SELECT record_id FROM growth_records WHERE child_id = c.child_id ORDER BY measurement_date DESC, record_id DESC LIMIT 1
                     )
                     LEFT JOIN guardians g ON c.guardian_id = g.guardian_id
                     WHERE c.child_id = ?
                     LIMIT 1";
        $finalStmt = $conn->prepare($finalSql);
        $finalData = null;
        if ($finalStmt) {
            $finalStmt->bind_param('i', $child_id);
            $finalStmt->execute();
            $finalData = $finalStmt->get_result()->fetch_assoc();
            $finalStmt->close();
        }

        $successMessage = 'Profile updated successfully.';
        if ($update_mode === 'measurement') {
            $successMessage = 'Measurement updated successfully.';
        } elseif ($update_mode === 'muac') {
            $successMessage = 'MUAC updated successfully.';
        }

        echo json_encode(['success' => true, 'message' => $successMessage, 'data' => $finalData]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'No changes were made or invalid input.']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_child_profile') {
    $child_id = isset($_GET['child_id']) ? intval($_GET['child_id']) : 0;
    if ($child_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid child ID.']);
        exit;
    }

    $sql = "SELECT gr.*, c.birthdate, c.sex 
            FROM growth_records gr 
            JOIN children c ON gr.child_id = c.child_id 
            WHERE gr.child_id = ? 
            ORDER BY gr.measurement_date DESC, gr.record_id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $child_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            echo json_encode(['success' => true, 'data' => $row]);
        } else {
            // No growth records yet, just return child info?
            $q = $conn->prepare("SELECT birthdate, sex FROM children WHERE child_id = ?");
            $q->bind_param('i', $child_id);
            $q->execute();
            $c = $q->get_result()->fetch_assoc();
            $q->close();
            echo json_encode(['success' => true, 'data' => array_merge(['weight'=>0,'height'=>0,'muac_measurement'=>0], $c ?: [])]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method or action.']);
}
