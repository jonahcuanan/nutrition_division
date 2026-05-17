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
$last_name  = normalize_name_input($_POST['last_name'] ?? '');
$first_key  = normalize_name_key($first_name);
$last_key   = normalize_name_key($last_name);

if ($first_name === '' || $last_name === '') {
    echo json_encode(['success' => true, 'exists' => false]);
    exit;
}

$sql = "SELECT 1 FROM users
        WHERE REPLACE(REPLACE(REPLACE(UPPER(first_name), ' ', ''), '\t', ''), '\n', '') = ?
          AND REPLACE(REPLACE(REPLACE(UPPER(last_name), ' ', ''), '\t', ''), '\n', '') = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

$stmt->bind_param('ss', $first_key, $last_key);
$stmt->execute();
$stmt->store_result();
$exists = ($stmt->num_rows > 0);
$stmt->close();

$message = '';
if ($exists) {
    $message = 'This user already exists in the system. Please verify the name before proceeding.';
}

echo json_encode([
    'success' => true,
    'exists'  => $exists,
    'message' => $message
]);
