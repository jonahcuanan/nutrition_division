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

// Enforce once-per-month limit
$sessionFile = __DIR__ . '/measurement_session.txt';
if (file_exists($sessionFile)) {
    $mtime = filemtime($sessionFile);
    if (date('Y-m', $mtime) === date('Y-m')) {
        echo json_encode(['success' => false, 'message' => 'A new measurement period has already been started this month. You can only start one once per calendar month.']);
        exit;
    }
}

// Save the max_id to a file. Any record with an ID <= this max_id is considered "history" and will be hidden from the main view.
file_put_contents(__DIR__ . '/measurement_session.txt', $maxId);

log_user_activity($conn, $currentUserId, 'clear_measurements');

echo json_encode(['success' => true, 'message' => "Measurements cleared for the new session."]);
