<?php
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$successMessage = '';
$errorMessage = '';
$showResetForm = false;

function send_reset_email(string $toEmail, string $toName, string $pinCode, ?string &$errorMessage): bool
{
    $smtpHost = getenv('SMTP_HOST');
    $smtpUser = getenv('SMTP_USER');
    $smtpPass = getenv('SMTP_PASS');
    $smtpFrom = getenv('SMTP_FROM');
    $smtpFromName = getenv('SMTP_FROM_NAME') ?: 'NutriKids Portal';
    $smtpPort = getenv('SMTP_PORT') ?: '587';
    $smtpSecure = strtolower(getenv('SMTP_SECURE') ?: 'tls');

    if (!$smtpHost || !$smtpUser || !$smtpPass || !$smtpFrom) {
        $errorMessage = 'Email server is not configured. Please contact the administrator.';
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;

        if ($smtpSecure === 'ssl' || $smtpPort === '465') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->Port = (int)$smtpPort;
        $mail->setFrom($smtpFrom, $smtpFromName);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Password reset PIN';
        $mail->Body = '<p>Hello ' . htmlspecialchars($toName) . ',</p>'
            . '<p>We received a request to reset your NutriKids Portal password. Use this PIN to continue:</p>'
            . '<p style="font-size:20px;font-weight:700;letter-spacing:2px;">' . htmlspecialchars($pinCode) . '</p>'
            . '<p>This PIN expires in 1 hour.</p>'
            . '<p>If you did not request a password reset, you can safely ignore this email.</p>'
            . '<p>Thanks,<br>NutriKids Portal</p>';
        $mail->AltBody = "Your password reset PIN is: " . $pinCode;

        $mail->send();
        return true;
    } catch (Exception $e) {
        $errorMessage = 'Unable to send the reset email. Please try again later.';
        return false;
    }
}

function get_reset_row(mysqli $conn, string $email, string $pinCode): ?array
{
    if ($email === '' || $pinCode === '') {
        return null;
    }

    $tokenHash = hash('sha256', $pinCode);
    $stmt = $conn->prepare('SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at FROM password_resets pr JOIN users u ON u.user_id = pr.user_id WHERE u.email = ? AND pr.token_hash = ? LIMIT 1');

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ss', $email, $tokenHash);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'send_pin';

    if ($action === 'verify_pin') {
        $email = trim($_SESSION['reset_email'] ?? '');
        $pinCode = trim($_POST['pin'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Reset session expired. Please request a new PIN.';
        } elseif ($pinCode === '') {
            $errorMessage = 'Please enter the reset PIN.';
        } else {
            $row = get_reset_row($conn, $email, $pinCode);

            if (!$row) {
                $errorMessage = 'Reset PIN is invalid or expired.';
            } elseif (!empty($row['used_at'])) {
                $errorMessage = 'This reset PIN has already been used.';
            } elseif (strtotime($row['expires_at']) <= time()) {
                $errorMessage = 'Reset PIN has expired. Please request a new one.';
            } else {
                $_SESSION['reset_verified'] = true;
                $_SESSION['reset_verified_at'] = time();
                header('Location: reset_password.php');
                exit;
            }
        }

        $showResetForm = true;
    } else {
        $email = trim($_POST['email'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Please enter a valid email address.';
        } else {
            $stmt = $conn->prepare('SELECT user_id, first_name, last_name, status FROM users WHERE email = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                $stmt->close();
            } else {
                $user = null;
            }

            if ($user && $user['status'] === 'Active') {
                $pinCode = (string)random_int(100000, 999999);
                $tokenHash = hash('sha256', $pinCode);
                $expiresAt = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

                $deleteStmt = $conn->prepare('DELETE FROM password_resets WHERE user_id = ?');
                if ($deleteStmt) {
                    $deleteStmt->bind_param('i', $user['user_id']);
                    $deleteStmt->execute();
                    $deleteStmt->close();
                }

                $insertStmt = $conn->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
                if ($insertStmt) {
                    $insertStmt->bind_param('iss', $user['user_id'], $tokenHash, $expiresAt);
                    $insertStmt->execute();
                    $insertStmt->close();
                }

                $fullName = trim($user['first_name'] . ' ' . $user['last_name']);

                if (send_reset_email($email, $fullName, $pinCode, $errorMessage)) {
                    log_user_activity($conn, (int)$user['user_id'], 'password_reset_requested');
                    $successMessage = 'If an account with that email exists, a reset PIN has been sent.';
                    $_SESSION['reset_email'] = $email;
                    $_SESSION['reset_verified'] = false;
                    $showResetForm = true;
                }
            } else {
                $successMessage = 'If an account with that email exists, a reset PIN has been sent.';
                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_verified'] = false;
                $showResetForm = true;
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
    <title>Forgot Password — NutriKids Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/index.css">
    <link rel="stylesheet" href="css/forgot_password.css">
</head>
<body>
<div class="login-wrapper">
    <div class="panel-brand">
        <div class="brand-logo">
            <div class="brand-logo-icon">🥗</div>
            <span class="brand-logo-text">Nutri<span>Kids</span></span>
        </div>
        <h2 class="brand-tagline">Reset<br>your portal<br><em>access.</em></h2>
        <p class="brand-desc">
            Enter the email attached to your staff account and we will send a password reset PIN.
        </p>
        <div class="brand-badge">
            <div class="brand-badge-dot"></div>
            <span class="brand-badge-text">Secure reset PIN</span>
        </div>
    </div>

    <div class="panel-form">
        <p class="form-eyebrow">Account Recovery</p>
        <h1 class="form-title">Forgot password</h1>
        <p class="form-subtitle">We will email you a one-time PIN.</p>

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

        <?php if (!$showResetForm && !$successMessage): ?>
            <form method="post" class="login-form" autocomplete="on">
                <input type="hidden" name="action" value="send_pin">
                <div class="field">
                    <span class="field-label">Email address</span>
                    <input type="email" name="email" placeholder="name@example.com" required autocomplete="email">
                </div>

                <button type="submit" class="btn-primary">Send reset PIN</button>
            </form>
        <?php else: ?>
            <form method="post" class="login-form" autocomplete="off">
                <input type="hidden" name="action" value="verify_pin">

                <div class="field">
                    <span class="field-label">Reset PIN</span>
                    <input type="hidden" id="reset-pin" name="pin" required>
                    <div class="pin-grid" id="pin-grid">
                        <input class="pin-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="PIN digit 1">
                        <input class="pin-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="PIN digit 2">
                        <input class="pin-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="PIN digit 3">
                        <input class="pin-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="PIN digit 4">
                        <input class="pin-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="PIN digit 5">
                        <input class="pin-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="PIN digit 6">
                    </div>
                    <div id="pin-feedback" class="form-subtitle" style="margin-top: 6px; display: none;"></div>
                </div>

                <button type="submit" class="btn-primary" id="continue-reset" disabled>Continue</button>
            </form>
        <?php endif; ?>

        <div class="trust-row" style="margin-top: 16px;">
            <a href="index.php" style="color: var(--text-mid); text-decoration: none;">Back to sign in</a>
        </div>
    </div>
</div>
<script src="javascript/forgot_password.js"></script>
</body>
</html>
