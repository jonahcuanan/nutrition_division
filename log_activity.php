<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access(['expectsJson' => true]);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$activityType = isset($data['activity_type']) ? trim((string)$data['activity_type']) : '';
$details      = isset($data['details'])       ? trim((string)$data['details'])       : '';

$allowedActivities = [
    'generate_report',
    'add_profile',
    'edit_profile',
    'change_password',
    'login',
    'logout',
    'intervention_type_add',
    'intervention_add',
    'intervention_edit',
    'inventory_add_item',
    'inventory_add_category',
    'inventory_distribute',
    'barangay_add',
    'barangay_edit',
    'barangay_delete',
];

if ($activityType === '' || !in_array($activityType, $allowedActivities, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid activity type']);
    exit;
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/activity_logger.php';

$ok = log_user_activity($conn, (int)$_SESSION['user_id'], $activityType, $details);

echo json_encode(['success' => $ok]);
