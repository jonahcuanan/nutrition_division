<?php
/**
 * auto_logout.php
 * Called via navigator.sendBeacon or fetch from session_monitor.js.
 * Logs the logout reason and destroys the session.
 *
 * POST body (JSON): { "reason": "timeout" | "browser_closed" }
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only act if a user is actually logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(204);
    exit;
}

$userId = (int)$_SESSION['user_id'];

$raw    = file_get_contents('php://input');
$data   = json_decode($raw, true);
$reason = isset($data['reason']) ? trim((string)$data['reason']) : 'timeout';

// Map reason to an allowed activity type
$allowedReasons = ['timeout', 'browser_closed'];
if (!in_array($reason, $allowedReasons, true)) {
    $reason = 'timeout';
}

$activityType = ($reason === 'browser_closed') ? 'browser_closed' : 'auto_logout';

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/activity_logger.php';

log_user_activity($conn, $userId, $activityType);

// Destroy session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

http_response_code(204);
exit;
