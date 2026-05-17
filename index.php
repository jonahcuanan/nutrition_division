<?php
session_start();

$errorMessage   = isset($_GET['error'])   ? $_GET['error'] : '';
$timeoutMessage = isset($_GET['timeout']) && $_GET['timeout'] === '1';

require_once __DIR__ . '/database.php';

$displayName = trim($_SESSION['full_name'] ?? '');
if ($displayName === '') {
    $nameParts = array_filter([
        $_SESSION['first_name'] ?? '',
        $_SESSION['middle_name'] ?? '',
        $_SESSION['last_name'] ?? '',
    ]);
    $displayName = trim(implode(' ', $nameParts));
}
if ($displayName === '') {
    $displayName = 'User';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NutriKids Portal — Staff Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/index.css">
</head>
<body>

<div class="login-wrapper">

    <!-- LEFT BRAND PANEL -->
    <div class="panel-brand">
        <div class="brand-logo">
            <div class="brand-logo-icon">🥗</div>
            <span class="brand-logo-text">Nutri<span>Kids</span></span>
        </div>

        <h2 class="brand-tagline">
            Nourishing<br>every child's<br><em>future.</em>
        </h2>

        <p class="brand-desc">
            A secure portal for staff to monitor, record, and improve child nutrition outcomes across your community.
        </p>

        <div class="brand-badge">
            <div class="brand-badge-dot"></div>
            <span class="brand-badge-text">Encrypted &amp; secure access</span>
        </div>
    </div>

    <!-- RIGHT FORM PANEL -->
    <div class="panel-form">
        <?php if (isset($_SESSION['user_id'])): ?>

            <div class="welcome">
                <div class="welcome-icon">👋</div>
                <h1>Welcome back, <?= htmlspecialchars($displayName) ?>!</h1>
                <p class="welcome-role">Signed in as <strong><?= htmlspecialchars($_SESSION['role']) ?></strong></p>
                <div class="welcome-actions">
                    <a class="btn-primary" href="dashboard.php">Go to Dashboard</a>
                    <a class="btn-ghost" href="logout.php">Sign Out</a>
                </div>
            </div>

        <?php else: ?>

            <p class="form-eyebrow">Secure Staff Portal</p>
            <h1 class="form-title">Sign in</h1>
            <p class="form-subtitle">Enter your credentials to access the nutrition records.</p>

            <?php if ($timeoutMessage): ?>
                <div class="toast-container">
                    <div class="toast" id="timeoutToast">
                        <div class="toast-icon">⏰</div>
                        <div class="toast-content">
                            <div class="toast-title">Session expired!</div>
                            <div class="toast-msg">Your session expired due to inactivity. Please sign in again.</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="error-alert">
                    <span>⚠</span>
                    <?= htmlspecialchars($errorMessage) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" autocomplete="on" class="login-form">

                <div class="field">
                    <span class="field-label">User ID</span>
                    <input
                        type="text"
                        name="user_id"
                        placeholder="Enter your 6-digit ID"
                        required
                        maxlength="6"
                        inputmode="numeric"
                        pattern="\d{6}"
                        autocomplete="off"
                    >
                </div>

                <div class="field">
                    <span class="field-label">Password</span>
                    <div class="password-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                            autocapitalize="characters"
                        >
                        <button type="button" class="toggle-pw" onclick="togglePw()" id="eye-btn" title="Show/hide password">👁</button>
                    </div>
                </div>

                <div class="forgot-link">
                    <a href="forgot_password.php">Forgot password?</a>
                </div>

                <button type="submit" class="btn-primary">Sign In</button>

            </form>

            <div class="trust-row">
                🔒 Your data is encrypted and protected
            </div>

        <?php endif; ?>
    </div>

</div>

<script src="javascript/index.js"></script>
<script src="javascript/uppercase-all.js"></script>
<script src="javascript/logout-confirm.js"></script>
</body>
</html>