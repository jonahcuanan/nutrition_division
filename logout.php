<?php
// logout.php - destroys session and redirects to login
session_start();
// Log user logout activity before destroying session
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/database.php';
    require_once __DIR__ . '/activity_logger.php';
    log_user_activity($conn, (int)$_SESSION['user_id'], 'logout');
}
session_unset();
session_destroy();
header('Location: index.php');
exit;
