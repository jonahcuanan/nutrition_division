<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access(['expectsJson' => true]);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/activity_logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

// Get the maximum record_id from growth_records
$res = $conn->query("SELECT MAX(record_id) as max_id FROM growth_records");
$row = $res->fetch_assoc();
$maxId = (int)($row['max_id'] ?? 0);

$sessionFile = __DIR__ . '/measurement_session.txt';
$cutoffRecordId = file_exists($sessionFile) ? (int)trim(file_get_contents($sessionFile)) : 0;

// Apply the same validation as child_profiles.php:
// Only treat the cutoff as active if the session file is from the current month
// AND a clear_measurements activity was logged this month.
if ($cutoffRecordId > 0 && file_exists($sessionFile)) {
    clearstatcache(true, $sessionFile);
    $mtime = filemtime($sessionFile);
    if (date('Y-m', $mtime) !== date('Y-m')) {
        // Session file is from a previous month — treat as no active period
        $cutoffRecordId = 0;
    } else {
        $hasClearThisMonth = false;
        $clearSql = "SELECT 1 FROM user_activity_log WHERE activity_type = 'clear_measurements' AND DATE_FORMAT(activity_time, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m') LIMIT 1";
        if ($clearRes = $conn->query($clearSql)) {
            $hasClearThisMonth = ($clearRes->num_rows > 0);
        }
        if (!$hasClearThisMonth) {
            // No clear_measurements logged this month — cutoff is stale
            $cutoffRecordId = 0;
        }
    }
}

// Safeguard: if cutoff exceeds max record_id, database was likely reset
if ($cutoffRecordId > 0) {
    $maxQuery = $conn->query("SELECT MAX(record_id) FROM growth_records");
    if ($maxQuery) {
        $maxRow = $maxQuery->fetch_row();
        $currentMax = $maxRow ? (int)$maxRow[0] : 0;
        if ($cutoffRecordId > $currentMax) {
            $cutoffRecordId = 0;
        }
    }
}

if ($cutoffRecordId > 0) {
    // Check if there are any active children who have NOT been measured in the current period.
    // A child is "measured" only if they have a post-cutoff record with valid height AND weight
    // (matching the frontend definition in child_profiles.php).
    $unmeasuredSql = "
        SELECT COUNT(*) as unmeasured
        FROM children c
        WHERE c.status = 'Active'
        AND NOT EXISTS (
            SELECT 1 FROM growth_records gr 
            WHERE gr.child_id = c.child_id 
            AND gr.record_id > $cutoffRecordId 
            AND gr.is_muac_only = FALSE
            AND COALESCE(gr.weight, 0) > 0
            AND COALESCE(gr.height, 0) > 0
        )
    ";
    if ($unmeasuredRes = $conn->query($unmeasuredSql)) {
        $unmeasuredRow = $unmeasuredRes->fetch_assoc();
        if ((int)$unmeasuredRow['unmeasured'] > 0) {
            echo json_encode(['success' => false, 'message' => 'You cannot start a new measurement period until all active children have been measured in the current period.']);
            exit;
        }
    }
}

// Save the max_id to a file. Any record with an ID <= this max_id is considered "history" and will be hidden from the main view.
file_put_contents(__DIR__ . '/measurement_session.txt', $maxId);

log_user_activity($conn, $currentUserId, 'clear_measurements');

echo json_encode(['success' => true, 'message' => "Measurements cleared for the new session."]);
