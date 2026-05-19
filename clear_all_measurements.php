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

if ($cutoffRecordId > 0) {
    // Check if there are any active children who have NOT been measured in the current period
    $unmeasuredSql = "
        SELECT COUNT(*) as unmeasured
        FROM children c
        WHERE c.status = 'Active'
        AND NOT EXISTS (
            SELECT 1 FROM growth_records gr 
            WHERE gr.child_id = c.child_id 
            AND gr.record_id > $cutoffRecordId 
            AND gr.is_muac_only = FALSE
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
