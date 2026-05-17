<?php
session_start();
require_once __DIR__ . '/database.php';

header('Content-Type: application/json');

$email = trim($_SESSION['reset_email'] ?? '');
$pinCode = trim($_POST['pin'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'message' => 'Reset session expired. Request a new PIN.']);
    exit;
}

if ($pinCode === '') {
    echo json_encode(['ok' => false, 'message' => '']);
    exit;
}

$tokenHash = hash('sha256', $pinCode);
$stmt = $conn->prepare('SELECT pr.expires_at, pr.used_at FROM password_resets pr JOIN users u ON u.user_id = pr.user_id WHERE u.email = ? AND pr.token_hash = ? LIMIT 1');

if (!$stmt) {
    echo json_encode(['ok' => false, 'message' => 'Unable to verify PIN right now.']);
    exit;
}

$stmt->bind_param('ss', $email, $tokenHash);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'PIN does not match.']);
    exit;
}

if (!empty($row['used_at'])) {
    echo json_encode(['ok' => false, 'message' => 'This PIN has already been used.']);
    exit;
}

if (strtotime($row['expires_at']) <= time()) {
    echo json_encode(['ok' => false, 'message' => 'PIN has expired. Request a new one.']);
    exit;
}

echo json_encode(['ok' => true, 'message' => 'PIN matched.']);
