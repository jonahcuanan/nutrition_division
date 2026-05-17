<?php
// One-time admin account creation script. DELETE after running once!
$servername = "localhost";
$username = "root";
$password = "";
$database = "children";

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
$check = $conn->prepare('SELECT COUNT(*) FROM users WHERE user_id = ?');
$adminUserId = 123456;
$adminUser = '123456';
$check->bind_param('i', $adminUserId);
$check->execute();
$check->bind_result($count);
$check->fetch();
$check->close();
if ($count > 0) {
    exit('Admin user already exists. Delete this file for security.');
}

// Use PHP password_hash for security
$hashedPassword = password_hash('ADMIN123', PASSWORD_DEFAULT);
$stmt = $conn->prepare('INSERT INTO users (
    user_id,
    first_name, middle_name, last_name, suffix,
    username, password, role,
    contact_number, email, status,
    barangay_id, date_created
) VALUES (
    ?,
    ?, ?, ?, ?,
    ?, ?, ?,
    ?, ?, ?,
    ?, NOW()
)');
$first_name = 'Jonah';
$middle_name = 'Garcia';
$last_name = 'Cuanan';
$suffix = null;
$role = 'Admin';
$contact_number = '09123456789';
$email = 'jonahcuanan15@gmail.com';
$status = 'Active';
$barangay_id = null;
// 12 fields: user_id (int), 10 strings, barangay_id (int/null)
$stmt->bind_param(
    'issssssssssi',
    $adminUserId,
    $first_name, $middle_name, $last_name, $suffix,
    $adminUser, $hashedPassword, $role,
    $contact_number, $email, $status,
    $barangay_id
);
if ($stmt->execute()) {
    echo 'Admin user created successfully! Delete this file now for security.';
} else {
    echo 'Error: ' . $stmt->error;
}
$stmt->close();
$conn->close();
?>
