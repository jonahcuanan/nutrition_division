<?php
// All login handling is done in index.php now.
// This script handles the login POST from index.php
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/activity_logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	// Direct access without POST: just go back to main page
	header('Location: index.php');
	exit;
}

$userIdRaw = trim($_POST['user_id'] ?? '');
$password = $_POST['password'] ?? '';

if ($userIdRaw === '' || $password === '') {
	header('Location: index.php?error=' . urlencode('Please enter both user ID and password.'));
	exit;
}

if (!preg_match('/^\d{6}$/', $userIdRaw)) {
	header('Location: index.php?error=' . urlencode('User ID must be exactly 6 digits.'));
	exit;
}

$userId = (int)$userIdRaw;
$passwordCandidates = array_values(array_unique([
	$password,
	strtoupper($password),
	strtolower($password),
]));

// Fetch all relevant user fields for session
$stmt = $conn->prepare('SELECT user_id, password, role, status, first_name, middle_name, last_name, suffix, contact_number, email, barangay_id FROM users WHERE user_id = ? LIMIT 1');

if (!$stmt) {
	header('Location: index.php?error=' . urlencode('Database error while logging in.'));
	exit;
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($user && ($user['status'] ?? '') !== 'Active') {
	header('Location: index.php?error=' . urlencode('Your account is inactive. Please contact the admin.'));
	exit;
}

if ($user) {
	$matchedPassword = null;
	foreach ($passwordCandidates as $candidate) {
		if (password_verify($candidate, $user['password'])) {
			$matchedPassword = $candidate;
			break;
		}
	}

	if ($matchedPassword !== null) {
		$canonicalPassword = strtoupper($password);
		if ($canonicalPassword !== $matchedPassword) {
			$rehash = password_hash($canonicalPassword, PASSWORD_DEFAULT);
			$update = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ?');
			if ($update) {
				$update->bind_param('si', $rehash, $user['user_id']);
				$update->execute();
				$update->close();
			}
		}

	$_SESSION['user_id'] = $user['user_id'];
	$_SESSION['role'] = $user['role'];
	// Store full name and other info
	$_SESSION['first_name'] = $user['first_name'];
	$_SESSION['middle_name'] = $user['middle_name'];
	$_SESSION['last_name'] = $user['last_name'];
	$_SESSION['suffix'] = $user['suffix'];
	$_SESSION['contact_number'] = $user['contact_number'];
	$_SESSION['email'] = $user['email'];
	$_SESSION['barangay_id'] = $user['barangay_id'];

	// Optionally, store full name as a single string
	$fullName = $user['first_name'];
	if (!empty($user['middle_name'])) $fullName .= ' ' . $user['middle_name'];
	$fullName .= ' ' . $user['last_name'];
	if (!empty($user['suffix'])) $fullName .= ' ' . $user['suffix'];
	$_SESSION['full_name'] = trim($fullName);

	log_user_activity($conn, (int)$user['user_id'], 'login');

	header('Location: dashboard.php');
	exit;
	}
}

header('Location: index.php?error=' . urlencode('Invalid user ID or password.'));
exit;

