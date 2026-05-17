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

$childId = isset($_POST['child_id']) ? (int)$_POST['child_id'] : 0;
$archiveStatus = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$statusDate = isset($_POST['status_date']) ? trim((string)$_POST['status_date']) : '';
$allowedArchiveStatuses = ['Archive', 'Disease', 'OverAge'];

if ($childId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid child ID.']);
    exit;
}

if (!in_array($archiveStatus, $allowedArchiveStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid archive status selected.']);
    exit;
}

if ($statusDate === '') {
    echo json_encode(['success' => false, 'message' => 'Archival date is required.']);
    exit;
}

$childFullName = '';
$nameStmt = $conn->prepare('SELECT first_name, middle_name, last_name, suffix FROM children WHERE child_id = ?');
if ($nameStmt) {
    $nameStmt->bind_param('i', $childId);
    if ($nameStmt->execute()) {
        $nameStmt->bind_result($firstName, $middleName, $lastName, $suffix);
        if ($nameStmt->fetch()) {
            $nameParts = array_filter([
                trim((string)$firstName),
                trim((string)$middleName),
                trim((string)$lastName),
                trim((string)$suffix)
            ]);
            $childFullName = trim(implode(' ', $nameParts));
        }
    }
    $nameStmt->close();
}

$stmt = $conn->prepare('UPDATE children SET status = ?, status_date = ? WHERE child_id = ?');
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare archive query.']);
    exit;
}
$stmt->bind_param('ssi', $archiveStatus, $statusDate, $childId);

if (!$stmt->execute()) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Failed to archive child profile.']);
    exit;
}

if ($stmt->affected_rows < 1) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Child profile not found or already archived.']);
    exit;
}

$stmt->close();

$detailParts = ['Status: ' . $archiveStatus];
if ($childFullName !== '') {
    $detailParts[] = 'Child: ' . $childFullName;
}
$details = 'Archived child profile (' . implode(', ', $detailParts) . ')';
log_user_activity($conn, (int)($_SESSION['user_id'] ?? 0), 'archive_child', $details);

echo json_encode(['success' => true, 'message' => 'Child profile archived successfully.']);
exit;
