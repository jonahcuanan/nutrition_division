<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/activity_logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: archive_children.php');
    exit;
}

$childId = isset($_POST['child_id']) ? (int)$_POST['child_id'] : 0;
$tab = isset($_POST['tab']) ? trim((string)$_POST['tab']) : 'all';
$allowedTabs = ['all', 'Archive', 'Disease', 'OverAge'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'all';
}

if ($childId > 0) {
    $childFullName = '';
    $previousStatus = '';
    $nameStmt = $conn->prepare('SELECT first_name, middle_name, last_name, suffix, status FROM children WHERE child_id = ?');
    if ($nameStmt) {
        $nameStmt->bind_param('i', $childId);
        if ($nameStmt->execute()) {
            $nameStmt->bind_result($firstName, $middleName, $lastName, $suffix, $status);
            if ($nameStmt->fetch()) {
                $nameParts = array_filter([
                    trim((string)$firstName),
                    trim((string)$middleName),
                    trim((string)$lastName),
                    trim((string)$suffix)
                ]);
                $childFullName = trim(implode(' ', $nameParts));
                $previousStatus = trim((string)$status);
            }
        }
        $nameStmt->close();
    }

    $stmt = $conn->prepare("UPDATE children SET status = 'Active', status_date = NOW() WHERE child_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $childId);
        $stmt->execute();
        $stmt->close();

        $detailParts = [];
        if ($previousStatus !== '') {
            $detailParts[] = 'From: ' . $previousStatus;
        }
        if ($childFullName !== '') {
            $detailParts[] = 'Child: ' . $childFullName;
        }
        $details = 'Restored child profile' . (empty($detailParts) ? '' : ' (' . implode(', ', $detailParts) . ')');
        log_user_activity($conn, (int)($_SESSION['user_id'] ?? 0), 'restore_child', $details);
    }
}

$toastMessage = 'Child profile restored successfully.';
if ($childFullName !== '') {
    $toastMessage = 'Restored: ' . $childFullName;
}

header('Location: archive_children.php?tab=' . urlencode($tab) . '&toast_success=' . urlencode($toastMessage));
exit;
