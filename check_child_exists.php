<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access(['expectsJson' => true]);
require_once __DIR__ . '/database.php';

header('Content-Type: application/json');

function normalize_name_input($value) {
    $normalized = strtoupper(trim($value));
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return $normalized;
}

function normalize_name_key($value) {
    $normalized = normalize_name_input($value);
    return preg_replace('/\s+/', '', $normalized);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$first_name = normalize_name_input($_POST['first_name'] ?? '');
$last_name  = normalize_name_input($_POST['last_name']  ?? '');
$first_key  = normalize_name_key($first_name);
$last_key   = normalize_name_key($last_name);

// Require both fields — no check if either is empty
if ($first_name === '' || $last_name === '') {
    echo json_encode(['success' => true, 'exists' => false]);
    exit;
}

/**
 * Global check: first_name + last_name across ALL barangays and ALL roles.
 * We also fetch the name of the person who first recorded this child.
 */
$sql = "SELECT u.first_name, u.middle_name, u.last_name, u.suffix, b.barangay_name
        FROM children c
        LEFT JOIN growth_records gr ON gr.child_id = c.child_id
        LEFT JOIN users u ON u.user_id = gr.recorded_by
        LEFT JOIN barangays b ON c.barangay_id = b.barangay_id
    WHERE REPLACE(REPLACE(REPLACE(UPPER(c.first_name), ' ', ''), '\t', ''), '\n', '') = ?
      AND REPLACE(REPLACE(REPLACE(UPPER(c.last_name), ' ', ''), '\t', ''), '\n', '') = ?
        ORDER BY gr.record_id ASC
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

$stmt->bind_param('ss', $first_key, $last_key);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

$exists = ($row !== null);
$message = '';

if ($exists) {
    // Build full name of the recorder
    $parts = array_filter([$row['first_name'], $row['middle_name'], $row['last_name'], $row['suffix']]);
    $recorderName = !empty($parts) ? implode(' ', $parts) : 'System';
    $barangayName = $row['barangay_name'] ?? 'Unknown Barangay';

    $message = "This child already exists in the system (Barangay: {$barangayName}). Recorded by: {$recorderName}.";
}

echo json_encode([
    'success' => true,
    'exists'  => $exists,
    'message' => $message
]);
