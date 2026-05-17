<?php

function log_user_activity(mysqli $conn, ?int $userId, string $activityType, string $details = ''): bool
{
    $uid      = (int)($userId ?? 0);
    $activity = trim($activityType);
    $details  = trim($details);

    if ($uid <= 0 || $activity === '') {
        return false;
    }

    // Guard: verify user exists before inserting (prevents FK constraint crash
    // when the DB is reset and the session still holds a stale user_id).
    $check = $conn->prepare('SELECT 1 FROM users WHERE user_id = ? LIMIT 1');
    if (!$check) {
        error_log("Activity Logger Error (Check Prepare): " . $conn->error);
        return false;
    }
    $check->bind_param('i', $uid);
    $check->execute();
    $check->store_result();
    $userExists = ($check->num_rows > 0);
    $check->close();

    if (!$userExists) {
        // Silently skip — user no longer exists in the database.
        return false;
    }

    $stmt = $conn->prepare('INSERT INTO user_activity_log (user_id, activity_type, details) VALUES (?, ?, ?)');
    if (!$stmt) {
        error_log("Activity Logger Error (Prepare): " . $conn->error);
        return false;
    }

    $stmt->bind_param('iss', $uid, $activity, $details);

    $ok = $stmt->execute();
    if (!$ok) {
        error_log("Activity Logger Error (Execute): " . $stmt->error);
    }

    $stmt->close();

    return $ok;
}
