<?php

// Match Philippine operations calendar (PHP default on XAMPP is often UTC, which skews “today”).
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const ROLE_ADMIN = 'Admin';
const ROLE_HEALTH_WORKER = 'Health Worker';
const ROLE_BNS = 'Barangay Nutrition Scholars';
const ROLE_STAFF = 'Staff';

function access_public_pages(): array
{
    return [
        'index.php',
        'login.php',
        'logout.php',
        'auto_logout.php',
    ];
}

function access_non_admin_pages(): array
{
    return [
        'dashboard.php',
        'add_profile.php',
        'child_profiles.php',
        'view_child_profile.php',
        'reports.php',
        'account_settings.php',
        'compute_growth_status.php',
        'update_profile.php',
        'log_activity.php',
        'check_child_exists.php',
        'server_date.php',
        'archive_children.php',
        'restore_child.php',
        'archive_child.php',
    ];
}

function access_archive_restricted_roles(): array
{
    return [
        ROLE_HEALTH_WORKER,
        ROLE_BNS,
    ];
}

function require_login(bool $expectsJson = false): void
{
    if (isset($_SESSION['user_id'])) {
        return;
    }

    if ($expectsJson) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    header('Location: index.php');
    exit;
}

function deny_access(bool $expectsJson = false): void
{
    if ($expectsJson) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    header('Location: dashboard.php?error=access');
    exit;
}

function enforce_page_access(array $options = []): void
{
    $page = $options['page'] ?? basename($_SERVER['PHP_SELF'] ?? '');
    $expectsJson = $options['expectsJson'] ?? false;

    if ($page === '') {
        return;
    }

    if (in_array($page, access_public_pages(), true)) {
        return;
    }

    require_login($expectsJson);

    $role = $_SESSION['role'] ?? '';
    if ($role === ROLE_ADMIN) {
        return;
    }

    // `archive_children.php` is intentionally accessible to barangay-level users
    // (e.g., Barangay Nutrition Scholars and Health Workers). Specific
    // filtering by barangay or recorded_by is handled inside
    // `archive_children.php` so do not deny access here.

    if (in_array($page, access_non_admin_pages(), true)) {
        return;
    }

    deny_access($expectsJson);
}
