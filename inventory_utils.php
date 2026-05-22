<?php

/**
 * Inventory status helpers (status enum: Available, Expired).
 */

function inventory_status_column_exists(mysqli $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = false;
    $res = $conn->query("SHOW COLUMNS FROM inventory LIKE 'status'");
    if ($res && $res->num_rows > 0) {
        $cached = true;
    }
    if ($res instanceof mysqli_result) {
        $res->free();
    }
    return $cached;
}

function inventory_status_from_expiration(?string $expirationDate, ?DateTime $today = null): string
{
    if ($expirationDate === null || $expirationDate === '') {
        return 'Available';
    }
    $today = $today ?? new DateTime('today');
    try {
        $exp = new DateTime($expirationDate);
    } catch (Exception $e) {
        return 'Available';
    }
    return ($exp < $today) ? 'Expired' : 'Available';
}

/** Keep DB status column aligned with expiration_date when the column exists. */
function sync_inventory_statuses(mysqli $conn): void
{
    // Clear expiration date and status for zero stock items
    if (inventory_status_column_exists($conn)) {
        $conn->query("UPDATE inventory SET expiration_date = NULL, status = 'Available' WHERE quantity = 0");
    } else {
        $conn->query("UPDATE inventory SET expiration_date = NULL WHERE quantity = 0");
    }

    if (!inventory_status_column_exists($conn)) {
        return;
    }
    $conn->query(
        "UPDATE inventory SET status = 'Expired'
         WHERE expiration_date IS NOT NULL AND expiration_date < CURDATE()"
    );
    $conn->query(
        "UPDATE inventory SET status = 'Available'
         WHERE expiration_date IS NULL OR expiration_date >= CURDATE()"
    );
}

function enrich_inventory_status(array $row, ?DateTime $today = null): array
{
    $today = $today ?? new DateTime('today');
    $status = trim((string)($row['status'] ?? ''));
    if ($status === 'Available' || $status === 'Expired') {
        $row['status'] = $status;
        return $row;
    }
    $row['status'] = inventory_status_from_expiration($row['expiration_date'] ?? null, $today);
    return $row;
}

function inventory_select_columns(): string
{
    global $conn;
    if ($conn instanceof mysqli && inventory_status_column_exists($conn)) {
        return 'i.inventory_id, i.item_name, i.category_id, c.category_name, i.quantity, i.unit, i.expiration_date, i.status, i.last_updated';
    }
    return 'i.inventory_id, i.item_name, i.category_id, c.category_name, i.quantity, i.unit, i.expiration_date, i.last_updated';
}

/** How many intervention records reference each inventory item. */
function fetch_intervention_usage_counts(mysqli $conn, array $inventoryIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $inventoryIds))));
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT inventory_id, COUNT(*) AS usage_count FROM intervention_items WHERE inventory_id IN ($placeholders) GROUP BY inventory_id";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $map = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $iid = (int)($row['inventory_id'] ?? 0);
        if ($iid > 0) {
            $map[$iid] = (int)($row['usage_count'] ?? 0);
        }
    }
    $stmt->close();
    return $map;
}

function fetch_inventory_item_for_action(mysqli $conn, int $itemId): ?array
{
    if ($itemId <= 0) {
        return null;
    }
    sync_inventory_statuses($conn);
    $sql = inventory_status_column_exists($conn)
        ? 'SELECT inventory_id, item_name, quantity, status FROM inventory WHERE inventory_id = ? LIMIT 1'
        : 'SELECT inventory_id, item_name, quantity, expiration_date FROM inventory WHERE inventory_id = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }
    return enrich_inventory_status($row);
}
