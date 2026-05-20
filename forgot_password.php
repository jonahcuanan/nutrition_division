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
$showPasswordForm = false;

$resetEmail = trim($_SESSION['reset_email'] ?? '');
$resetVerified = !empty($_SESSION['reset_verified']);
$showPasswordForm = $resetEmail !== '' && filter_var($resetEmail, FILTER_VALIDATE_EMAIL) && $resetVerified;

// Page title and subtitle per current step
if ($showPasswordForm) {
    $pageTitle = 'Reset password';
    $pageSubtitle = 'Enter and confirm your new password.';
} elseif ($showResetForm) {
    $pageTitle = 'Forgot password';
    $pageSubtitle = 'Enter the reset PIN we emailed you.';
} else {
    $pageTitle = 'Forgot password';
    $pageSubtitle = 'We will email you a one-time PIN.';
}

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

function get_session_reset_data(): array
{
    return [
        'email' => trim($_SESSION['reset_email'] ?? ''),
        'token_hash' => $_SESSION['reset_pin_hash'] ?? '',
        'expires_at' => $_SESSION['reset_pin_expires_at'] ?? '',
        'verified' => (bool)($_SESSION['reset_verified'] ?? false),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'send_pin';

    // AJAX PIN verification for client-side quick checks
    if ($action === 'ajax_check_pin') {
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
        $sessionTokenHash = $_SESSION['reset_pin_hash'] ?? '';
        $expiresAt = $_SESSION['reset_pin_expires_at'] ?? '';
        $alreadyVerified = !empty($_SESSION['reset_verified']);

        if ($sessionTokenHash === '' || $expiresAt === '') {
            echo json_encode(['ok' => false, 'message' => 'Reset session expired. Request a new PIN.']);
            exit;
        }

        if ($alreadyVerified) {
            echo json_encode(['ok' => false, 'message' => 'This PIN has already been used.']);
            exit;
        }

        if (!hash_equals($sessionTokenHash, $tokenHash)) {
            echo json_encode(['ok' => false, 'message' => 'PIN does not match.']);
            exit;
        }

        if (strtotime($expiresAt) <= time()) {
            echo json_encode(['ok' => false, 'message' => 'PIN has expired. Request a new one.']);
            exit;
        }

        echo json_encode(['ok' => true, 'message' => 'PIN matched.']);
        exit;
    }

    if ($action === 'update_password') {
        if ($resetEmail === '' || !filter_var($resetEmail, FILTER_VALIDATE_EMAIL) || !$resetVerified) {
            $errorMessage = 'Please verify your reset PIN before updating your password.';
        } else {
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
                $stmt = $conn->prepare('SELECT user_id, status FROM users WHERE email = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('s', $resetEmail);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $user = $result->fetch_assoc();
                    $stmt->close();
                } else {
                    $user = null;
                }

                if (!$user || $user['status'] !== 'Active') {
                    $errorMessage = 'Unable to reset password. Please try again.';
                } else {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $updateUser = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ?');

                    if ($updateUser) {
                        $updateUser->bind_param('si', $hashed, $user['user_id']);
                        $okUser = $updateUser->execute();
                        $updateUser->close();

                        if ($okUser) {
                            log_user_activity($conn, (int)$user['user_id'], 'password_reset');
                            $successMessage = 'Your password has been updated. You can now sign in.';
                            unset(
                                $_SESSION['reset_email'],
                                $_SESSION['reset_verified'],
                                $_SESSION['reset_verified_at'],
                                $_SESSION['reset_pin_hash'],
                                $_SESSION['reset_pin_expires_at']
                            );
                            $showPasswordForm = false;
                        } else {
                            $errorMessage = 'Unable to reset password. Please try again.';
                        }
                    } else {
                        $errorMessage = 'Database error while resetting password.';
                    }
                }
            }
        }
    } elseif ($action === 'verify_pin') {
        $sessionReset = get_session_reset_data();
        $email = $sessionReset['email'];
        $pinCode = trim($_POST['pin'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Reset session expired. Please request a new PIN.';
        } elseif ($pinCode === '') {
            $errorMessage = 'Please enter the reset PIN.';
        } else {
            $tokenHash = hash('sha256', $pinCode);
            if ($tokenHash !== $sessionReset['token_hash']) {
                $errorMessage = 'Reset PIN is invalid or expired.';
            } elseif ($sessionReset['expires_at'] === '' || strtotime($sessionReset['expires_at']) <= time()) {
                $errorMessage = 'Reset PIN has expired. Please request a new one.';
            } elseif ($sessionReset['verified']) {
                $errorMessage = 'This reset PIN has already been used.';
            } else {
                $_SESSION['reset_verified'] = true;
                $_SESSION['reset_verified_at'] = time();
                $_SESSION['reset_pin_hash'] = '';
                $_SESSION['reset_pin_expires_at'] = '';
                $showPasswordForm = true;
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

                $fullName = trim($user['first_name'] . ' ' . $user['last_name']);

                if (send_reset_email($email, $fullName, $pinCode, $errorMessage)) {
                    log_user_activity($conn, (int)$user['user_id'], 'password_reset_requested');
                    $successMessage = 'If an account with that email exists, a reset PIN has been sent.';
                    $_SESSION['reset_email'] = $email;
                    $_SESSION['reset_verified'] = false;
                    $_SESSION['reset_pin_hash'] = $tokenHash;
                    $_SESSION['reset_pin_expires_at'] = $expiresAt;
                    $showResetForm = true;
                    $showPasswordForm = false;
                }
            } else {
                $successMessage = 'If an account with that email exists, a reset PIN has been sent.';
                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_verified'] = false;
                $_SESSION['reset_pin_hash'] = '';
                $_SESSION['reset_pin_expires_at'] = '';
                $showResetForm = false;
                $showPasswordForm = false;
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
        <h1 class="form-title"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="form-subtitle"><?= htmlspecialchars($pageSubtitle) ?></p>

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

        <?php if ($showPasswordForm): ?>
            <form method="post" class="login-form" autocomplete="off">
                <input type="hidden" name="action" value="update_password">

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
        <?php elseif (!$showResetForm): ?>
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
