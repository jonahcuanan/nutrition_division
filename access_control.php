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
        'export_profiles.php',
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

/**
 * Verify that the currently logged-in user has permission to access a specific child.
 *
 * - Admin / Staff : always allowed.
 * - Health Worker : child must belong to the user's assigned barangay.
 * - BNS           : child must belong to the user's assigned barangay AND the user
 *                   must have recorded at least one growth record for that child.
 *
 * @param  mysqli $conn     Active database connection.
 * @param  int    $childId  The child_id to check.
 * @return bool   true if access is allowed, false otherwise.
 */
function verify_child_barangay_access(mysqli $conn, int $childId): bool
{
    $role      = $_SESSION['role']      ?? '';
    $userId    = (int)($_SESSION['user_id']    ?? 0);
    $barangayId= (int)($_SESSION['barangay_id'] ?? 0);

    // Admins and Staff have unrestricted access
    if ($role === ROLE_ADMIN || $role === ROLE_STAFF) {
        return true;
    }

    if ($barangayId <= 0) {
        return false;
    }

    if ($role === ROLE_HEALTH_WORKER) {
        $stmt = $conn->prepare(
            'SELECT 1 FROM children WHERE child_id = ? AND barangay_id = ? LIMIT 1'
        );
        if (!$stmt) return false;
        $stmt->bind_param('ii', $childId, $barangayId);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->num_rows;
        $stmt->close();
        return $ok;
    }

    if ($role === ROLE_BNS) {
        // 1. Must be in BNS's assigned barangay
        $stmt = $conn->prepare('SELECT barangay_id, status FROM children WHERE child_id = ? LIMIT 1');
        if (!$stmt) return false;
        $stmt->bind_param('i', $childId);
        $stmt->execute();
        $res = $stmt->get_result();
        $childRow = $res->fetch_assoc();
        $stmt->close();

        if (!$childRow || (int)$childRow['barangay_id'] !== $barangayId) {
            return false;
        }

        // 2. Check growth records to determine assignment
        // Check if any BNS has ever measured this child
        $bnsQuery = 'SELECT gr.recorded_by, c.status FROM growth_records gr 
                     JOIN users u ON gr.recorded_by = u.user_id 
                     JOIN children c ON gr.child_id = c.child_id
                     WHERE gr.child_id = ? AND u.role = "Barangay Nutrition Scholars"
                     ORDER BY gr.measurement_date DESC, gr.record_id DESC';
        $stmt = $conn->prepare($bnsQuery);
        if (!$stmt) return false;
        $stmt->bind_param('i', $childId);
        $stmt->execute();
        $recordsRes = $stmt->get_result();
        $bnsRecords = $recordsRes->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Case A: No BNS has ever measured this child yet -> any BNS in that barangay can view and add first measurement
        if (empty($bnsRecords)) {
            return true;
        }

        // Case B: The most recent measurement by a BNS was by this logged-in BNS
        if ((int)$bnsRecords[0]['recorded_by'] === $userId) {
            return true;
        }

        // Case C: For archived/deceased/overage children, if this BNS has recorded ANY record in the past, let them view it
        $isArchived = in_array($childRow['status'], ['Archive', 'Decease', 'OverAge'], true);
        if ($isArchived) {
            foreach ($bnsRecords as $rec) {
                if ((int)$rec['recorded_by'] === $userId) {
                    return true;
                }
            }
        }

        return false;
    }

    return false;
}
