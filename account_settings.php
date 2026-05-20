<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();
$currentRole = $_SESSION['role'] ?? '';
$isBns = ($currentRole === 'Barangay Nutrition Scholars');
$isStaff = ($currentRole === 'Staff');
$isAdmin = ($currentRole === 'Admin');
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/activity_logger.php';

// ── AJAX ENDPOINT: CHECK EMAIL EXISTS ──
if (isset($_GET['action']) && $_GET['action'] === 'check_email_exists') {
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');

    // Always exclude the currently logged-in user (prevents own-email false positive).
    // For admin editing another user, the JS sends target_user_id via exclude_user_id.
    $session_uid = (int)($_SESSION['user_id'] ?? 0);
    $post_exclude = isset($_POST['exclude_user_id']) ? (int)$_POST['exclude_user_id'] : 0;

    // Use the larger of the two so that the session user is always excluded,
    // and if an admin is editing a different account, that account is also excluded.
    // Build the exclusion list
    $exclude_ids = array_filter(array_unique([$session_uid, $post_exclude]));

    if ($email === '') {
        echo json_encode(['success' => true, 'exists' => false]);
        exit;
    }

    if (!empty($exclude_ids)) {
        $placeholders = implode(',', array_fill(0, count($exclude_ids), '?'));
        $types = str_repeat('i', count($exclude_ids));
        $sql  = "SELECT 1 FROM users WHERE email = ? AND user_id NOT IN ($placeholders) LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $params = array_merge([$email], array_values($exclude_ids));
            $bind_types = 's' . $types;
            $stmt->bind_param($bind_types, ...$params);
        }
    } else {
        $sql  = "SELECT 1 FROM users WHERE email = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) $stmt->bind_param('s', $email);
    }

    if ($stmt) {
        $stmt->execute();
        $stmt->store_result();
        $exists = ($stmt->num_rows > 0);
        $stmt->close();
        echo json_encode(['success' => true, 'exists' => $exists, 'message' => $exists ? 'This email address is already in use by another account.' : '']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
    exit;
}

function generate_unique_user_id(mysqli $conn): ?int {
    for ($i = 0; $i < 20; $i++) {
        $candidate = random_int(1, 999999);
        $check = $conn->prepare('SELECT 1 FROM users WHERE user_id = ? LIMIT 1');
        if (!$check) {
            continue;
        }
        $check->bind_param('i', $candidate);
        $check->execute();
        $check->store_result();
        $exists = $check->num_rows > 0;
        $check->close();
        if (!$exists) {
            return $candidate;
        }
    }

    return null;
}

$successMessage = '';
$errorMessage = '';
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);

// Redirect non-admins away from activity logs or users account tabs
if (!$isAdmin && isset($_GET['tab']) && in_array($_GET['tab'], ['activity_logs', 'users_account'])) {
    header('Location: account_settings.php');
    exit;
}

// Load barangay options
$barangayOptions = [];
$barangayQuery = $conn->query('SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC');
if ($barangayQuery) {
    while ($row = $barangayQuery->fetch_assoc()) {
        $barangayOptions[] = $row;
    }
}

// Handle My Account updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    $newEmail    = trim($_POST['email'] ?? '');
    $newPassword = $_POST['password'] ?? '';
    $confirmPw   = $_POST['confirm_password'] ?? '';

    $newPassword = strtoupper($newPassword);
    $confirmPw = strtoupper($confirmPw);

    $errors = [];
    if ($newEmail === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    } elseif (substr(strtolower($newEmail), -10) !== '@gmail.com') {
        $errors[] = 'Email address must end with @gmail.com.';
    }

    if ($newPassword !== '' && $newPassword !== $confirmPw) $errors[] = 'New password and confirmation do not match.';
    if ($newPassword !== '' && strlen($newPassword) < 6) $errors[] = 'Password must be at least 6 characters long.';

    if (empty($errors)) {
        // Check if email is already taken by another user
        $emailCheck = $conn->prepare('SELECT 1 FROM users WHERE email = ? AND user_id != ? LIMIT 1');
        if ($emailCheck) {
            $emailCheck->bind_param('si', $newEmail, $sessionUserId);
            $emailCheck->execute();
            $emailCheck->store_result();
            if ($emailCheck->num_rows > 0) $errors[] = 'This email address is already in use by another account.';
            $emailCheck->close();
        }
    }

    if (empty($errors)) {
        if ($sessionUserId <= 0) {
            $errorMessage = 'Session expired. Please log in again.';
        }
    }

    if (empty($errors) && $errorMessage === '') {
        if ($newPassword !== '') {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET email = ?, password = ? WHERE user_id = ?');
            if ($stmt) {
                $stmt->bind_param('ssi', $newEmail, $hashed, $sessionUserId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    $_SESSION['email'] = $newEmail;
                    log_user_activity($conn, $sessionUserId, 'change_password');
                    $successMessage = 'Account updated successfully.';
                }
                else $errorMessage = 'Failed to update account.';
            } else { $errorMessage = 'Database error while updating account.'; }
        } else {
            $stmt = $conn->prepare('UPDATE users SET email = ? WHERE user_id = ?');
            if ($stmt) {
                $stmt->bind_param('si', $newEmail, $sessionUserId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) { $_SESSION['email'] = $newEmail; $successMessage = 'Account updated successfully.'; }
                else $errorMessage = 'Failed to update account.';
            } else { $errorMessage = 'Database error while updating account.'; }
        }
    } else {
        $errorMessage = implode(' ', $errors);
    }
}

// Handle user creation (Admins only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    if (!$isAdmin) {
        $errorMessage = 'You do not have permission to create users.';
    } else {
        $firstName       = trim($_POST['first_name'] ?? '');
        $middleName      = trim($_POST['middle_name'] ?? '');
        $lastName        = trim($_POST['last_name'] ?? '');
        $suffix          = trim($_POST['suffix'] ?? '');
        $password        = strtoupper($_POST['password'] ?? '');
        $confirmPassword = strtoupper($_POST['confirm_password'] ?? '');
        $role            = trim($_POST['role'] ?? '');
        $contactNumber   = trim($_POST['contact_number'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $barangayId      = trim($_POST['barangay_id'] ?? '');
        $manualUserIdRaw = trim($_POST['user_id'] ?? '');

        $errors = [];
        if ($firstName === '' || $lastName === '' || $password === '' || $confirmPassword === '' || $role === '' || $contactNumber === '' || $email === '' || $manualUserIdRaw === '')
            $errors[] = 'All required fields must be filled (User ID, Role, Password, Names, Contact, and Email).';
        if ($password !== $confirmPassword) $errors[] = 'Password and Confirm Password do not match.';
        if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters long.';
        
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } elseif ($email !== '' && substr(strtolower($email), -10) !== '@gmail.com') {
            $errors[] = 'Email address must end with @gmail.com.';
        } elseif ($email !== '') {
            // Check if email is already taken
            $emailCheck = $conn->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
            if ($emailCheck) {
                $emailCheck->bind_param('s', $email);
                $emailCheck->execute();
                $emailCheck->store_result();
                if ($emailCheck->num_rows > 0) $errors[] = 'This email address is already in use by another account.';
                $emailCheck->close();
            }
        }

        if ($manualUserIdRaw !== '' && !preg_match('/^\d{6}$/', $manualUserIdRaw)) {
            $errors[] = 'User ID must be exactly 6 digits.';
        }

        if (empty($errors)) {
            $nameCheck = $conn->prepare('SELECT 1 FROM users WHERE LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?) LIMIT 1');
            if ($nameCheck) {
                $nameCheck->bind_param('ss', $firstName, $lastName);
                $nameCheck->execute();
                $nameCheck->store_result();
                if ($nameCheck->num_rows > 0) $errors[] = 'A user with the same first and last name already exists.';
                $nameCheck->close();
            } else { $errors[] = 'Database error while checking user name.'; }
        }

        $roleNeedsBarangay = in_array($role, ['Barangay Nutrition Scholars', 'Health Worker'], true);
        if ($roleNeedsBarangay && ($barangayId === '' || !ctype_digit($barangayId))) {
            $errors[] = 'Barangay selection is required for Barangay Nutrition Scholars and Health Worker users.';
        }

        $assignedUserId = null;
        if (empty($errors)) {
            if ($manualUserIdRaw !== '') {
                $assignedUserId = (int)$manualUserIdRaw;
                $checkStmt = $conn->prepare('SELECT 1 FROM users WHERE user_id = ? LIMIT 1');
                if ($checkStmt) {
                    $checkStmt->bind_param('i', $assignedUserId);
                    $checkStmt->execute();
                    $checkStmt->store_result();
                    if ($checkStmt->num_rows > 0) $errors[] = 'User ID is already in use. Please choose another.';
                    $checkStmt->close();
                } else {
                    $errors[] = 'Database error while checking user ID.';
                }
            } else {
                $assignedUserId = generate_unique_user_id($conn);
                if ($assignedUserId === null) {
                    $errors[] = 'Unable to generate a unique user ID. Please try again.';
                }
            }
        }

        if (empty($errors)) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $status = 'Active';
            $barangayParam = ($role === 'Staff')
                ? null
                : (($barangayId !== '' && ctype_digit($barangayId)) ? (int)$barangayId : null);
            $systemUsername = (string)$assignedUserId;
            $stmt = $conn->prepare('INSERT INTO users (user_id, first_name, middle_name, last_name, suffix, username, password, role, contact_number, email, status, barangay_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if ($stmt) {
                $stmt->bind_param('issssssssssi', $assignedUserId, $firstName, $middleName, $lastName, $suffix, $systemUsername, $hashedPassword, $role, $contactNumber, $email, $status, $barangayParam);
                if ($stmt->execute()) {
                    $successMessage = 'User account created successfully. User ID: ' . str_pad((string)$assignedUserId, 6, '0', STR_PAD_LEFT) . '.';
                }
                else $errorMessage = 'Failed to create user account. Please try again.';
                $stmt->close();
            } else { $errorMessage = 'Database error while creating user.'; }
        } else {
            $errorMessage = implode(' ', $errors);
        }
    }
}

// NOTE: Admin user-edit functionality removed to disable edit actions from Users Account.

// Fetch activity logs
$activityLogResult = null;
if (isset($_GET['tab']) && $_GET['tab'] === 'activity_logs') {
    $sql = "SELECT l.id, l.user_id, u.first_name, u.middle_name, u.last_name, u.role, l.activity_type, l.details, l.activity_time
            FROM user_activity_log l
            LEFT JOIN users u ON l.user_id = u.user_id
            ORDER BY l.activity_time DESC";
    $activityLogResult = $conn->query($sql);
}

// Fetch all user accounts
$userAccountRows = [];
$totalUsers = 0;
if (isset($_GET['tab']) && $_GET['tab'] === 'users_account') {
    $result = $conn->query("SELECT u.user_id, u.first_name, u.middle_name, u.last_name, u.suffix, u.role, u.contact_number, u.email, b.barangay_name FROM users u LEFT JOIN barangays b ON u.barangay_id = b.barangay_id WHERE u.role != 'Admin' ORDER BY u.last_name ASC, u.first_name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $userAccountRows[] = $row;
        }
        $totalUsers = count($userAccountRows);
    }
}

$isLogsTab = isset($_GET['tab']) && $_GET['tab'] === 'activity_logs';
$isUsersAccountTab = isset($_GET['tab']) && $_GET['tab'] === 'users_account';
$isSettingsTab = !$isLogsTab && !$isUsersAccountTab;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings</title>
    <link rel="stylesheet" href="css/account_settings.css?v=<?= filemtime(__DIR__ . '/css/account_settings.css') ?>">

</head>
<body>
<?php include 'sidebar.php'; ?>

<main class="main-content">
    <div id="toastContainer"
         data-success="<?= htmlspecialchars($successMessage ?? '', ENT_QUOTES) ?>"
         data-error="<?= htmlspecialchars($errorMessage ?? '', ENT_QUOTES) ?>"></div>

    <!-- Page Header (Unified) -->
    <div class="page-header" style="margin-bottom: 22px;">
        <div class="page-header-left">
            <div class="page-header-icon">⚙️</div>
            <div>
                <h1>Account Settings</h1>
                <p><?= $isAdmin ? 'Manage your account and create new staff users' : 'Update your login credentials below' ?></p>
            </div>
        </div>
        <?php if ($isAdmin && !$isLogsTab): ?>
            <button type="button" class="btn btn-primary" onclick="openModal()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Create New Users 
            </button>
        <?php endif; ?>
    </div>


    <!-- ── Nav Tabs ── -->
    <nav class="settings-nav">
        <a href="account_settings.php" class="<?= $isSettingsTab ? 'active' : '' ?>">
            ⚙️ Account Settings
        </a>
        <?php if ($isAdmin): ?>
        <a href="account_settings.php?tab=users_account" class="<?= $isUsersAccountTab ? 'active' : '' ?>">
            👥 Users Account
        </a>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
            <a href="account_settings.php?tab=activity_logs" class="<?= $isLogsTab ? 'active' : '' ?>">
                📋 Activity Logs
            </a>
        <?php endif; ?>
    </nav>

    <?php if ($isLogsTab): ?>
    <!-- ══════════════ ACTIVITY LOGS ══════════════ -->

        <div class="table-card">
            <div class="table-card-header">
                <div class="table-card-header-left">
                    <span class="table-card-title">Activity Logs</span>
                    <div class="table-card-sub">System-wide user activity history</div>
                </div>
                <div class="table-header-controls">
                    <div class="search-wrap">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="logsSearchInput" placeholder="Search user ID, name, role, activity..." oninput="filterLogsTable()">
                    </div>
                </div>
            </div>

            <div class="table-scroll">
                <table class="log-table" id="logsTable">
                    <colgroup>
                        <col style="width:11%">
                        <col style="width:17%">
                        <col style="width:10%">
                        <col>
                        <col style="width:11%">
                        <col style="width:9%">
                    </colgroup>
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Full Name</th>
                                <th>Role</th>
                                <th>Activity</th>
                                <th>Date</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($activityLogResult && $activityLogResult->num_rows > 0): ?>
                            <?php while($row = $activityLogResult->fetch_assoc()):
                                // Build full name
                                $logNameParts = array_filter([
                                    $row['first_name'] ?? '',
                                    $row['middle_name'] ?? '',
                                    $row['last_name'] ?? ''
                                ]);
                                $logFullName = trim(implode(' ', $logNameParts));

                                // Parse datetime
                                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $row['activity_time']);
                                $logDate = $dt ? $dt->format('M d, Y') : $row['activity_time'];
                                $logTime = $dt ? $dt->format('h:i A')  : '';
                                $logRole = $row['role'] ?? '';
                                $logDetails = trim($row['details'] ?? '');

                                // Activity badge config
                                $actType = $row['activity_type'];
                                $badgeMap = [
                                    'login'           => ['label' => 'Logged In',         'icon' => '🔓', 'bg' => '#d1fae5', 'color' => '#065f46'],
                                    'logout'          => ['label' => 'Logged Out',        'icon' => '🔒', 'bg' => '#fee2e2', 'color' => '#991b1b'],
                                    'add_profile'     => ['label' => 'Added Profile',     'icon' => '👶', 'bg' => '#dbeafe', 'color' => '#1e40af'],
                                    'edit_profile'    => ['label' => 'Updated Measurement','icon' => '📏', 'bg' => '#ede9fe', 'color' => '#5b21b6'],
                                    'generate_report' => ['label' => 'Generated Report',  'icon' => '📄', 'bg' => '#fef9c3', 'color' => '#713f12'],
                                    'change_password' => ['label' => 'Changed Password',  'icon' => '🔑', 'bg' => '#ffedd5', 'color' => '#92400e'],
                                    'clear_measurements'=>['label'=> 'Cleared Measurements','icon'=> '🗑️','bg' => '#f3f4f6', 'color' => '#374151'],
                                    'intervention_type_add' => ['label' => 'Added Intervention Type', 'icon' => '🧾', 'bg' => '#e0f2fe', 'color' => '#0369a1'],
                                    'intervention_add'      => ['label' => 'Added Intervention',      'icon' => '✅', 'bg' => '#dcfce7', 'color' => '#166534'],
                                    'intervention_edit'     => ['label' => 'Updated Intervention',    'icon' => '✏️', 'bg' => '#fef9c3', 'color' => '#854d0e'],
                                    'inventory_add_item'    => ['label' => 'Added Inventory Item',    'icon' => '📦', 'bg' => '#dbeafe', 'color' => '#1e40af'],
                                    'inventory_add_category'=> ['label' => 'Added Inventory Category','icon' => '🏷️','bg' => '#e2e8f0', 'color' => '#334155'],
                                    'inventory_distribute'  => ['label' => 'Distributed Inventory',   'icon' => '📤', 'bg' => '#fee2e2', 'color' => '#991b1b'],
                                    'auto_logout'           => ['label' => 'Auto Logout (Inactive)',   'icon' => '⏰', 'bg' => '#fef3c7', 'color' => '#92400e'],
                                    'browser_closed'        => ['label' => 'Session Ended (Closed)',   'icon' => '🚪', 'bg' => '#f1f5f9', 'color' => '#475569'],
                                    'barangay_add'          => ['label' => 'Added Barangay',           'icon' => '🏘️', 'bg' => '#d1fae5', 'color' => '#065f46'],
                                    'barangay_edit'         => ['label' => 'Updated Barangay',         'icon' => '🏡', 'bg' => '#ede9fe', 'color' => '#5b21b6'],
                                    'barangay_delete'       => ['label' => 'Deleted Barangay',         'icon' => '🗑️',  'bg' => '#fee2e2', 'color' => '#991b1b'],
                                    'archive_child'         => ['label' => 'Archived Child Profile',  'icon' => '🗂️', 'bg' => '#ffe4e6', 'color' => '#9f1239'],
                                    'restore_child'         => ['label' => 'Restored Child Profile',  'icon' => '♻️', 'bg' => '#dcfce7', 'color' => '#166534'],
                                ];
                                $badge = $badgeMap[$actType] ?? [
                                    'label' => ucwords(str_replace('_', ' ', $actType)),
                                    'icon'  => '📋',
                                    'bg'    => '#f3f4f6',
                                    'color' => '#374151',
                                ];
                            ?>
                                <tr>
                                    <td><span class="ua-username"><?= htmlspecialchars(str_pad((string)$row['user_id'], 6, '0', STR_PAD_LEFT)) ?></span></td>
                                    <td><?= htmlspecialchars($logFullName) ?></td>
                                    <td><?= htmlspecialchars($logRole) ?></td>
                                    <td class="log-td-activity">
                                        <div style="display:flex;flex-direction:column;gap:5px;">
                                            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px 3px 7px;border-radius:20px;font-size:0.74rem;font-weight:600;background:<?= $badge['bg'] ?>;color:<?= $badge['color'] ?>;width:fit-content;">
                                                <span style="font-size:0.85rem;line-height:1;"><?= $badge['icon'] ?></span>
                                                <?= htmlspecialchars($badge['label']) ?>
                                            </span>
                                            <?php if ($logDetails !== ''): ?>
                                                <div style="font-size:0.75rem;color:#555;line-height:1.45;padding:4px 8px;background:#f8fafc;border-left:3px solid #cbd5e1;border-radius:0 4px 4px 0;">
                                                    <?= htmlspecialchars($logDetails) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="white-space:nowrap;font-size:0.82rem;"><?= htmlspecialchars($logDate) ?></td>
                                    <td style="white-space:nowrap;font-size:0.82rem;font-weight:600;"><?= htmlspecialchars($logTime) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding: 44px 24px; color: var(--text-faint); font-family: 'Sora', sans-serif; font-size: 0.86rem;">
                                    <div style="font-size:2rem; margin-bottom:10px;">📋</div>
                                    No activity records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
            </div><!-- /.table-scroll -->

            <div class="no-results" id="logsNoResults">
                <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 10px;display:block;opacity:.4"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                No results match your search.
            </div>
        </div><!-- /.table-card -->

    <?php elseif ($isUsersAccountTab): ?>
    <!-- ══════════════ USERS ACCOUNT ══════════════ -->

        <div class="table-card">
            <div class="table-card-header">
                <div class="table-card-header-left">
                    <span class="table-card-title">Users Directory</span>
                    <div class="table-card-sub">Total users: <?= (int)$totalUsers ?></div>
                </div>
                <div class="table-header-controls">
                    <div class="search-wrap">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="usersSearchInput" placeholder="Search name, user ID, role, barangay..." oninput="filterUsersTable()">
                    </div>
                </div>
            </div>

            <div class="table-scroll">
                <table id="usersAccountTable" class="user-account-table">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Full Name</th>
                            <th>Role</th>
                            <th>Assigned Barangay</th>
                            <th>Contact Number</th>
                            <th>Email Address</th>
                            <!-- Actions column removed -->
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($userAccountRows)): ?>
                        <?php foreach ($userAccountRows as $row): ?>
                            <?php
                                $nameParts = array_filter([
                                    $row['first_name'] ?? '',
                                    $row['middle_name'] ?? '',
                                    $row['last_name'] ?? ''
                                ]);
                                $fullName = trim(implode(' ', $nameParts));
                                $suffix = trim($row['suffix'] ?? '');
                                if ($suffix !== '') $fullName .= ' ' . $suffix;
                                $barangayName = $row['barangay_name'] ?? '';
                                $barangayUnassigned = ($barangayName === '' || $barangayName === null);
                                if ($barangayUnassigned) $barangayName = 'Unassigned';
                                $contactNumber = trim($row['contact_number'] ?? '');
                                $email = trim($row['email'] ?? '');

                                $roleRaw = $row['role'] ?? '';
                                // Display acronyms for long role names
                                $roleDisplay = $roleRaw;
                                if ($roleRaw === 'Barangay Nutrition Scholars') {
                                    $roleDisplay = 'BNS';
                                } elseif ($roleRaw === 'Health Worker') {
                                    $roleDisplay = 'HW';
                                }
                            ?>
                            <tr>
                                <td><span class="ua-username"><?= htmlspecialchars(str_pad((string)$row['user_id'], 6, '0', STR_PAD_LEFT)) ?></span></td>
                                <td><?= htmlspecialchars($fullName) ?></td>
                                <td title="<?= htmlspecialchars($roleRaw) ?>"><?= htmlspecialchars($roleDisplay) ?></td>
                                <td>
                                    <?php if ($barangayUnassigned): ?>
                                        <span class="ua-barangay-unassigned">Unassigned</span>
                                    <?php else: ?>
                                        <span class="ua-barangay">📍 <?= htmlspecialchars($barangayName) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="ua-contact"><?= $contactNumber !== '' ? htmlspecialchars($contactNumber) : '<span class="ua-dash">&mdash;</span>' ?></td>
                                <td class="ua-email"><?= $email !== '' ? htmlspecialchars($email) : '<span class="ua-dash">&mdash;</span>' ?></td>
                                <!-- Actions cell removed -->
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding: 44px 24px; color: var(--text-faint); font-family: 'Sora', sans-serif; font-size: 0.86rem;">
                                <div style="font-size:2rem; margin-bottom:10px;">👤</div>
                                No user accounts found.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="no-results" id="usersNoResults">
                <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 10px;display:block;opacity:.4"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                No results match your search.
            </div>
        </div>

    <?php else: ?>
    <!-- ══════════════ ACCOUNT SETTINGS ══════════════ -->



        <div class="settings-layout">

            <!-- ── My Account Card ── -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">👤</div>
                    <div>
                        <div class="card-header-title">My Account</div>
                        <div class="card-header-sub">Update your email or password</div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="post" action="" id="myAccountForm">
                        <input type="hidden" name="update_account" value="1">
                        <input type="hidden" id="myCurrentUserId" value="<?= $sessionUserId ?>">

                        <div class="section-label">Login Credentials</div>

                        <div class="field">
                            <label>User ID</label>
                            <div class="input-readonly-locked no-click" title="User ID cannot be edited">
                                <?= htmlspecialchars(str_pad((string)($sessionUserId > 0 ? $sessionUserId : ''), 6, '0', STR_PAD_LEFT)) ?>
                            </div>
                            <span class="hint">This ID is system-assigned and cannot be edited.</span>
                        </div>
                        <div class="field">
                            <label>Email Address <span class="req">*</span></label>
                            <input type="email" name="email"
                                   value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>"
                                   placeholder="your@gmail.com" required
                                   pattern="[a-zA-Z0-9._%+-]+@gmail\.com"
                                   title="Email address must end with @gmail.com">
                        </div>

                        <hr class="divider">
                        <div class="section-label">Change Password</div>

                        <div class="field">
                            <label>New Password</label>
                            <div class="password-field">
                                <input type="password" name="password" id="myPassword"
                                       placeholder="Leave blank to keep current"
                                       autocomplete="new-password">
                                <button type="button" class="pw-toggle" data-target="myPassword" aria-label="Show password">Show</button>
                            </div>
                        </div>
                        <div class="field">
                            <label>Confirm New Password</label>
                            <div class="password-field">
                                <input type="password" name="confirm_password" id="myPasswordConfirm"
                                       placeholder="Re-enter new password"
                                       autocomplete="new-password">
                                <button type="button" class="pw-toggle" data-target="myPasswordConfirm" aria-label="Show password">Show</button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-full" style="margin-top: 10px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- ── Security Tips Card ── -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">🔒</div>
                    <div>
                        <div class="card-header-title">Security Tips</div>
                        <div class="card-header-sub">Keep your account safe</div>
                    </div>
                </div>
                <div class="card-body">
                    <div style="display:flex; flex-direction:column; gap:18px;">

                        <div style="display:flex; gap:13px; align-items:flex-start;">
                            <div style="width:36px;height:36px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;">🔑</div>
                            <div>
                                <div style="font-size:0.82rem;font-weight:700;color:#143b27;margin-bottom:3px;">Use a strong password</div>
                                <div style="font-size:0.75rem;color:#4a745c;line-height:1.55;">Mix uppercase, lowercase, numbers and symbols. Aim for at least 10 characters.</div>
                            </div>
                        </div>

                        <div style="display:flex; gap:13px; align-items:flex-start;">
                            <div style="width:36px;height:36px;background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;">🔄</div>
                            <div>
                                <div style="font-size:0.82rem;font-weight:700;color:#143b27;margin-bottom:3px;">Change passwords regularly</div>
                                <div style="font-size:0.75rem;color:#4a745c;line-height:1.55;">Update your password every few months to reduce risk of unauthorized access.</div>
                            </div>
                        </div>

                        <div style="display:flex; gap:13px; align-items:flex-start;">
                            <div style="width:36px;height:36px;background:#fff7ed;border:1.5px solid #fed7aa;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;">🚫</div>
                            <div>
                                <div style="font-size:0.82rem;font-weight:700;color:#143b27;margin-bottom:3px;">Don't reuse passwords</div>
                                <div style="font-size:0.75rem;color:#4a745c;line-height:1.55;">Avoid using the same password across different accounts or systems.</div>
                            </div>
                        </div>

                        <div style="display:flex; gap:13px; align-items:flex-start;">
                            <div style="width:36px;height:36px;background:#fdf4ff;border:1.5px solid #e9d5ff;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;">🖥️</div>
                            <div>
                                <div style="font-size:0.82rem;font-weight:700;color:#143b27;margin-bottom:3px;">Log out on shared devices</div>
                                <div style="font-size:0.75rem;color:#4a745c;line-height:1.55;">Always sign out when using a shared or public computer to protect your data.</div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

        </div><!-- /.settings-layout -->

    <?php endif; ?>
</main>

<!-- ══════════════════════════════
     CREATE USER MODAL
══════════════════════════════ -->
<?php if ($isAdmin): ?>
<div class="modal-overlay<?php
    $modalState = '';
    if (isset($_POST['create_user'])) {
        if ($errorMessage)  $modalState = ' active modal-error';
        elseif ($successMessage) $modalState = ' active modal-success';
    }
    echo $modalState;
?>" id="userModal">
    <div class="modal-backdrop" id="modalBackdrop"></div>
    <div class="modal-box">

        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="modal-header-icon" style="background:var(--blue-light);">👤</div>
                <div>
                    <div class="modal-title">Create New User</div>
                    <div class="modal-sub">Fill in the details below to add a new account</div>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeModal()" aria-label="Close">✕</button>
        </div>

        <div class="modal-body">

            <?php if (isset($_POST['create_user']) && $successMessage): ?>
                <div class="modal-alert modal-alert-success" id="modalAlertBanner">
                    <div class="modal-alert-icon">✅</div>
                    <div class="modal-alert-body">
                        <div class="modal-alert-title">User Created Successfully</div>
                        <div class="modal-alert-text"><?= htmlspecialchars($successMessage) ?></div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (isset($_POST['create_user']) && $errorMessage): ?>
                <?php
                    // Parse multiple errors into a list if they contain '. '
                    $errParts = array_filter(array_map('trim', explode('.', $errorMessage)));
                ?>
                <div class="modal-alert modal-alert-error" id="modalAlertBanner">
                    <div class="modal-alert-icon">⚠️</div>
                    <div class="modal-alert-body">
                        <div class="modal-alert-title">Unable to Create User</div>
                        <?php if (count($errParts) > 1): ?>
                            <ul class="modal-alert-list">
                                <?php foreach ($errParts as $ep): ?>
                                    <li><?= htmlspecialchars($ep) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="modal-alert-text"><?= htmlspecialchars($errorMessage) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="" id="createUserForm">
                <input type="hidden" name="create_user" value="1">

                <!-- Account Details -->
                <div class="section-label">Account Details</div>
                <div class="form-grid-2">
                    <div class="field">
                        <label>User ID <span class="req">*</span></label>
                        <div class="user-id-row">
                            <input type="text" name="user_id" id="userIdInput" placeholder="Enter 6-digit ID"
                                   inputmode="numeric" pattern="\d{6}" autocomplete="off" required>
                            <button type="button" class="btn btn-outline btn-compact" id="generateUserIdBtn">Generate</button>
                        </div>
                        <span class="hint">Enter a unique 6-digit ID or click generate.</span>
                    </div>
                    <div class="field">
                        <label>Role <span class="req">*</span></label>
                        <select name="role" id="roleSelect" required>
                            <option value="">Select role</option>
                            <option value="Health Worker">Health Worker</option>
                            <option value="Staff">Staff</option>
                            <option value="Barangay Nutrition Scholars">Barangay Nutrition Scholars</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid-2">
                    <div class="field" id="barangayField" style="grid-column: span 2;">
                        <label>Assign Barangay <span class="req" id="bnsReq" style="display:none;">*</span></label>
                        <select name="barangay_id" id="barangaySelect">
                            <option value="">Select barangay</option>
                            <?php foreach ($barangayOptions as $b): ?>
                                <option value="<?= $b['barangay_id'] ?>"><?= htmlspecialchars($b['barangay_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="hint">Required for Barangay Nutrition Scholars and Health Worker; disabled for Staff.</span>
                    </div>
                </div>
                <div class="form-grid-2">
                    <div class="field">
                        <label>Password <span class="req">*</span></label>
                        <div class="password-field">
                            <input type="password" name="password" id="newPwField"
                                   placeholder="Min. 6 characters" required autocomplete="new-password">
                            <button type="button" class="pw-toggle" data-target="newPwField" aria-label="Show password">Show</button>
                        </div>
                        <!-- Real-time password requirements checklist -->
                        <div class="pw-req-list" id="newPwReqList">
                            <div class="pw-req-item" id="pwReqLen">
                                <span class="pw-req-dot">✓</span>
                                At least 6 characters
                            </div>
                            <div class="pw-req-item" id="pwReqNum">
                                <span class="pw-req-dot">✓</span>
                                One number
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label>Confirm Password <span class="req">*</span></label>
                        <div class="password-field">
                            <input type="password" name="confirm_password" id="newPwConfirm"
                                   placeholder="Re-enter password" required autocomplete="new-password">
                            <button type="button" class="pw-toggle" data-target="newPwConfirm" aria-label="Show password">Show</button>
                        </div>
                        <div class="pw-match-indicator hidden" id="pwMatchIndicator">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" id="pwMatchIcon"><polyline points="20 6 9 17 4 12"/></svg>
                            <span id="pwMatchText">Passwords match</span>
                        </div>
                    </div>
                </div>

                <hr class="divider">

                <!-- Personal Info -->
                <div class="section-label">Personal Information</div>
                <div class="form-grid-2">
                    <div class="field">
                        <label>First Name <span class="req">*</span></label>
                        <input type="text" name="first_name" id="userFirstName" placeholder="e.g. Juan" required>
                        <span id="userFirstNameError" class="inline-error hidden">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            Duplicate name detected.
                        </span>
                    </div>
                    <div class="field">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" id="userMiddleName" placeholder="e.g. Santos">
                    </div>
                </div>
                <div class="form-grid-2">
                    <div class="field">
                        <label>Last Name <span class="req">*</span></label>
                        <input type="text" name="last_name" id="userLastName" placeholder="e.g. Dela Cruz" required>
                        <span id="userLastNameError" class="inline-error hidden">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            Duplicate name detected.
                        </span>
                    </div>
                    <div class="field">
                        <label>Suffix</label>
                        <input type="text" name="suffix" id="userSuffix" placeholder="Jr., Sr., etc.">
                    </div>
                </div>

                <div id="userExistsBanner" class="dup-banner hidden">
                    <svg class="dup-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span id="userExistsMsg">This user already exists in the system. Please verify the name before proceeding.</span>
                </div>
                <hr class="divider">

                <!-- Contact Info -->
                <div class="section-label">Contact Information</div>
                <div class="form-grid-2">
                    <div class="field">
                        <label>Contact Number <span class="req">*</span></label>
                        <input type="text" name="contact_number" placeholder="e.g. 0912-345-6789" required>
                    </div>
                    <div class="field">
                        <label>Email Address <span class="req">*</span></label>
                        <input type="email" name="email" placeholder="e.g. juan@gmail.com" required
                               pattern="[a-zA-Z0-9._%+-]+@gmail\.com"
                               title="Email address must end with @gmail.com">
                    </div>
                </div>

            </form>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
            <button type="submit" form="createUserForm" class="btn btn-primary" id="submitBtn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                Create User
            </button>
        </div>

    </div>
</div>
<?php endif; ?>

<!-- Edit user modal removed: admin edit actions disabled -->

<script src="javascript/account_settings.js?v=<?= filemtime(__DIR__ . '/javascript/account_settings.js') ?>"></script>
</body>
</html>