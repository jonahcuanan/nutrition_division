<?php
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/activity_logger.php';

$successMessage = '';
$errorMessage = '';

$resetEmail = trim($_SESSION['reset_email'] ?? '');
$resetVerified = !empty($_SESSION['reset_verified']);

if ($resetEmail === '' || !filter_var($resetEmail, FILTER_VALIDATE_EMAIL) || !$resetVerified) {
    $errorMessage = 'Please verify your reset PIN before updating your password.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage === '') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $password = strtoupper($password);
    $confirmPassword = strtoupper($confirmPassword);

    if ($password === '' || $confirmPassword === '') {
        $errorMessage = 'Please enter and confirm your new password.';
    } elseif ($password !== $confirmPassword) {
        $errorMessage = 'New password and confirmation do not match.';
    } elseif (strlen($password) < 6) {
        $errorMessage = 'Password must be at least 6 characters long.';
    } else {
        $stmt = $conn->prepare('SELECT pr.id, pr.user_id, pr.expires_at FROM password_resets pr JOIN users u ON u.user_id = pr.user_id WHERE u.email = ? AND pr.used_at IS NULL ORDER BY pr.created_at DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $resetEmail);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
        } else {
            $row = null;
        }

        if (!$row) {
            $errorMessage = 'Reset PIN is invalid or expired.';
        } elseif (strtotime($row['expires_at']) <= time()) {
            $errorMessage = 'Reset PIN has expired. Please request a new one.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $updateUser = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ?');
            $updateReset = $conn->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');

            if ($updateUser && $updateReset) {
                $updateUser->bind_param('si', $hashed, $row['user_id']);
                $updateReset->bind_param('i', $row['id']);

                $okUser = $updateUser->execute();
                $okReset = $updateReset->execute();

                $updateUser->close();
                $updateReset->close();

                if ($okUser && $okReset) {
                    log_user_activity($conn, (int)$row['user_id'], 'password_reset');
                    $successMessage = 'Your password has been updated. You can now sign in.';
                    unset($_SESSION['reset_email'], $_SESSION['reset_verified'], $_SESSION['reset_verified_at']);
                } else {
                    $errorMessage = 'Unable to reset password. Please try again.';
                }
            } else {
                $errorMessage = 'Database error while resetting password.';
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — NutriKids Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/index.css">
</head>
<body>
<div class="login-wrapper">
    <div class="panel-brand">
        <div class="brand-logo">
            <div class="brand-logo-icon">🥗</div>
            <span class="brand-logo-text">Nutri<span>Kids</span></span>
        </div>
        <h2 class="brand-tagline">Create<br>a new<br><em>password.</em></h2>
        <p class="brand-desc">
            Choose a strong password to secure your account.
        </p>
        <div class="brand-badge">
            <div class="brand-badge-dot"></div>
            <span class="brand-badge-text">One-time PIN</span>
        </div>
    </div>

    <div class="panel-form">
        <p class="form-eyebrow">Account Recovery</p>
        <h1 class="form-title">Reset password</h1>
        <p class="form-subtitle">Enter and confirm your new password.</p>

        <?php if ($errorMessage): ?>
            <div class="error-alert">
                <span>⚠</span>
                <?= htmlspecialchars($errorMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <div class="error-alert" style="background:#ecfdf5;border-color:#bbf7d0;color:#166534;">
                <span>✅</span>
                <?= htmlspecialchars($successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (!$successMessage && $errorMessage === ''): ?>
            <form method="post" class="login-form" autocomplete="off">
                <div class="field">
                    <span class="field-label">New password</span>
                    <input type="password" name="password" placeholder="Enter new password" required autocomplete="new-password">
                </div>

                <div class="field">
                    <span class="field-label">Confirm password</span>
                    <input type="password" name="confirm_password" placeholder="Confirm new password" required autocomplete="new-password">
                </div>

                <button type="submit" class="btn-primary">Update password</button>
            </form>
        <?php endif; ?>

        <div class="trust-row" style="margin-top: 16px;">
            <a href="index.php" style="color: var(--text-mid); text-decoration: none;">Back to sign in</a>
        </div>
    </div>
</div>
</body>
</html>
