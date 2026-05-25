<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();
$currentRole = $_SESSION['role'] ?? '';
if ($currentRole === 'Health Worker') {
    header('Location: dashboard.php');
    exit;
}
require_once 'database.php';
require_once __DIR__ . '/inventory_utils.php';
require_once __DIR__ . '/activity_logger.php';

$successMessage = '';
$errorMessage = '';
$editErrors = [];
$editItemId = '';
$editExpirationDate = '';
$editItemName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_item') {
    $editItemId = trim($_POST['item_id'] ?? '');
    $editExpirationDate = trim($_POST['expiration_date'] ?? '');
    $editErrors = [];

    if ($editItemId === '' || !ctype_digit($editItemId)) {
        $editErrors[] = 'Invalid item selected.';
    }

    if (empty($editErrors)) {
        $itemId = (int)$editItemId;
        sync_inventory_statuses($conn);

        $checkStmt = $conn->prepare('SELECT item_name FROM inventory WHERE inventory_id = ? LIMIT 1');
        if ($checkStmt) {
            $checkStmt->bind_param('i', $itemId);
            $checkStmt->execute();
            $checkStmt->bind_result($itemName);
            if (!$checkStmt->fetch()) {
                $editErrors[] = 'Item not found.';
            } else {
                $editItemName = (string)$itemName;
            }
            $checkStmt->close();
        } else {
            $editErrors[] = 'Database error checking item.';
        }

        if (empty($editErrors) && $editExpirationDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $editExpirationDate)) {
            $editErrors[] = 'Please enter a valid expiration date.';
        }

        if (empty($editErrors)) {
            $expirationValue = $editExpirationDate !== '' ? $editExpirationDate : null;
            $newStatus = inventory_status_from_expiration($expirationValue);

            if (inventory_status_column_exists($conn)) {
                $stmt = $conn->prepare('UPDATE inventory SET expiration_date = ?, status = ?, last_updated = NOW() WHERE inventory_id = ? LIMIT 1');
            } else {
                $stmt = $conn->prepare('UPDATE inventory SET expiration_date = ?, last_updated = NOW() WHERE inventory_id = ? LIMIT 1');
            }

            if ($stmt) {
                if (inventory_status_column_exists($conn)) {
                    $stmt->bind_param('ssi', $expirationValue, $newStatus, $itemId);
                } else {
                    $stmt->bind_param('si', $expirationValue, $itemId);
                }

                if ($stmt->execute()) {
                    $stmt->close();
                    log_user_activity(
                        $conn,
                        (int)($_SESSION['user_id'] ?? 0),
                        'inventory_edit_item',
                        'Updated expiration for: ' . $editItemName . ' | Expiry: ' . ($editExpirationDate !== '' ? $editExpirationDate : 'No expiry') . ' | Status: ' . $newStatus
                    );
                    header('Location: expired_items.php');
                    exit;
                }
                $editErrors[] = 'Failed to update item. Please try again.';
                $stmt->close();
            } else {
                $editErrors[] = 'Database error updating item.';
            }
        }
    }

    if (!empty($editErrors)) {
        $errorMessage = $editErrors[0];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_item') {
    $deleteItemId = trim($_POST['item_id'] ?? '');
    $deleteErrors = [];

    if ($deleteItemId === '' || !ctype_digit($deleteItemId)) {
        $deleteErrors[] = 'Invalid item selected.';
    }

    if (empty($deleteErrors)) {
        $itemId = (int)$deleteItemId;
        $itemRow = fetch_inventory_item_for_action($conn, $itemId);

        if (!$itemRow) {
            $deleteErrors[] = 'Item not found.';
        } else {
            $usageMap = fetch_intervention_usage_counts($conn, [$itemId]);
            if ((int)($usageMap[$itemId] ?? 0) > 0) {
                $deleteErrors[] = 'Cannot delete this item because it has existing intervention records.';
            }
        }

        if (empty($deleteErrors)) {
            $itemName = (string)($itemRow['item_name'] ?? 'Item');
            $deleteStmt = $conn->prepare('DELETE FROM inventory WHERE inventory_id = ? LIMIT 1');
            if ($deleteStmt) {
                $deleteStmt->bind_param('i', $itemId);
                if ($deleteStmt->execute() && $deleteStmt->affected_rows > 0) {
                    $deleteStmt->close();
                    log_user_activity(
                        $conn,
                        (int)($_SESSION['user_id'] ?? 0),
                        'inventory_delete_item',
                        'Deleted inventory item: ' . $itemName
                    );
                    header('Location: expired_items.php');
                    exit;
                }
                $deleteErrors[] = 'Failed to delete item. Please try again.';
                $deleteStmt->close();
            } else {
                $deleteErrors[] = 'Database error deleting item.';
            }
        }
    }

    if (!empty($deleteErrors)) {
        $errorMessage = $deleteErrors[0];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'zero_expired_stock') {
    $zeroItemId = trim($_POST['item_id'] ?? '');
    $zeroErrors = [];

    if ($zeroItemId === '' || !ctype_digit($zeroItemId)) {
        $zeroErrors[] = 'Invalid item selected.';
    }

    if (empty($zeroErrors)) {
        $itemId = (int)$zeroItemId;
        $itemRow = fetch_inventory_item_for_action($conn, $itemId);

        if (!$itemRow) {
            $zeroErrors[] = 'Item not found.';
        } elseif (($itemRow['status'] ?? '') !== 'Expired') {
            $zeroErrors[] = 'Only expired items can have stock cleared this way.';
        } elseif ((int)($itemRow['quantity'] ?? 0) <= 0) {
            $zeroErrors[] = 'Stock is already zero.';
        }

        if (empty($zeroErrors)) {
            $itemName = (string)($itemRow['item_name'] ?? 'Item');
            $zeroStmt = $conn->prepare('UPDATE inventory SET quantity = 0, last_updated = NOW() WHERE inventory_id = ? AND quantity > 0');
            if ($zeroStmt) {
                $zeroStmt->bind_param('i', $itemId);
                if ($zeroStmt->execute() && $zeroStmt->affected_rows > 0) {
                    $zeroStmt->close();
                    sync_inventory_statuses($conn);
                    log_user_activity(
                        $conn,
                        (int)($_SESSION['user_id'] ?? 0),
                        'inventory_zero_expired_stock',
                        'Set stock to zero (expired): ' . $itemName
                    );
                    header('Location: expired_items.php?stock_zeroed=1');
                    exit;
                }
                $zeroErrors[] = 'Failed to update stock. Please try again.';
                $zeroStmt->close();
            } else {
                $zeroErrors[] = 'Database error updating stock.';
            }
        }
    }

    if (!empty($zeroErrors)) {
        $errorMessage = $zeroErrors[0];
    }
}

if (isset($_GET['stock_zeroed']) && $_GET['stock_zeroed'] == '1') {
    $successMessage = 'Stock set to zero. This expired item can no longer be used in interventions.';
}

$today = new DateTime('today');
sync_inventory_statuses($conn);
$invSelect = inventory_select_columns();

$expiredCondition = inventory_status_column_exists($conn)
    ? "i.status = 'Expired'"
    : "i.expiration_date IS NOT NULL AND i.expiration_date < CURDATE()";

$invResult = $conn->query("SELECT {$invSelect} FROM inventory i LEFT JOIN category_inventory c ON i.category_id = c.category_id WHERE {$expiredCondition} ORDER BY i.item_name");

$inventoryItems = [];
if ($invResult && $invResult->num_rows > 0) {
    while ($row = $invResult->fetch_assoc()) {
        $row = enrich_inventory_status($row, $today);
        $categoryLabelRow = $row['category_name'] ?: 'Uncategorized';
        $row['category_label'] = $categoryLabelRow;
        $row['category_key'] = strtolower($categoryLabelRow);
        $inventoryItems[] = $row;
    }
}

$totalItems = count($inventoryItems);
$maxQty = 1;
$interventionUsageByItem = fetch_intervention_usage_counts(
    $conn,
    array_column($inventoryItems, 'inventory_id')
);
foreach ($inventoryItems as $item) {
    $qty = (int)$item['quantity'];
    if ($qty > $maxQty) $maxQty = $qty;
}

// Fetch categories for filter dropdown
$categories = [];
$catResult = $conn->query("SELECT category_id, category_name FROM category_inventory ORDER BY category_name");
if ($catResult && $catResult->num_rows > 0) {
    while ($row = $catResult->fetch_assoc()) {
        $categories[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expired Items Overview</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/inventory.css">
    <style>
        #toastContainer {
            position: fixed;
            top: 16px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 20000;
            pointer-events: none;
        }

        .toast {
            width: fit-content;
            max-width: min(560px, 92vw);
            padding: 0;
            border-radius: 0;
            font-size: 0.9rem;
            font-weight: 700;
            border: none;
            box-shadow: none;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 8px;
            color: #fff;
            animation: toastIn .25s ease;
            pointer-events: auto;
            align-self: center;
        }

        .toast-label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            max-width: min(560px, 92vw);
            padding: 12px 18px;
            border-radius: 6px;
            color: #fff;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18);
            word-break: break-word;
        }

        .toast-success .toast-label {
            background: #16a34a;
        }

        .toast-error .toast-label {
            background: #ef4444;
        }

        @keyframes toastIn {
            from {
                opacity: 0;
                transform: translateY(-6px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body data-open-restock-modal="0" data-open-edit-modal="<?= !empty($editErrors) ? '1' : '0' ?>">
<?php include 'sidebar.php'; ?>

<main class="main-content">
    <div class="page-header">
        <div class="page-header-icon">🚫</div>
        <div class="page-header-text">
            <h1>Expired Items Overview</h1>
            <p>Monitor and resolve items that have reached their expiration dates</p>
        </div>
        <div class="page-header-actions">
            <a class="btn btn-outline" href="inventory.php">← Back to Inventory</a>
        </div>
    </div>

    <div id="toastContainer"
         data-success="<?= htmlspecialchars($successMessage ?? '', ENT_QUOTES) ?>"
         data-error="<?= htmlspecialchars($errorMessage ?? '', ENT_QUOTES) ?>"></div>

    <?php if ($errorMessage !== ''): ?>
    <div class="alert alert-error" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
        <strong>Could not complete action:</strong> <?= htmlspecialchars($errorMessage) ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-header-icon icon-green">📋</div>
                <div>
                    <h2>All Expired Items</h2>
                    <p>Displaying inventory items currently marked as expired</p>
                </div>
            </div>
        </div>
        <div class="toolbar">
            <div class="search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="invSearch" placeholder="Search by item name…">
            </div>
            <select class="filter-select" id="categoryFilter">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat['category_name']) ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                <?php endforeach; ?>
                <option value="Uncategorized">Uncategorized</option>
            </select>
            <span class="row-count" id="invCount"><?= $totalItems ?> item<?= $totalItems !== 1 ? 's' : '' ?></span>
        </div>

        <table class="table-stack">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Category</th>
                    <th class="center">Stock Level</th>
                    <th class="hide-mobile">Expiration Date</th>
                    <th class="center">Status</th>
                    <th class="hide-mobile">Last Updated</th>
                    <th class="center">Actions</th>
                </tr>
            </thead>
            <tbody id="invBody">
            <?php if (!empty($inventoryItems)):
                foreach ($inventoryItems as $row):
                    $qty = (int)$row['quantity'];
                    if ($qty === 0)     { $qtyClass = 'qty-empty'; $availability = 'out'; }
                    elseif ($qty <= 5) { $qtyClass = 'qty-warn';  $availability = 'in'; }
                    else               { $qtyClass = 'qty-ok';    $availability = 'in'; }

                    $expDate = date('M d, Y', strtotime($row['expiration_date']));
                    $lastUpd = $row['last_updated'] ? date('M d, Y', strtotime($row['last_updated'])) : '—';
                    $itemStatus = (string)($row['status'] ?? 'Expired');
                    $inventoryId = (int)$row['inventory_id'];
                    $usedInInterventions = (int)($interventionUsageByItem[$inventoryId] ?? 0) > 0;
            ?>
            <tr class="inv-stock-row inv-stock-row--expired"
                id="item-row-<?= $inventoryId ?>"
                data-search="<?= strtolower(htmlspecialchars($row['item_name'])) ?>" 
                data-category="<?= htmlspecialchars($row['category_label']) ?>"
                data-stock="<?= $qty<=5?'low':'ok' ?>"
                data-expiration="expired"
                data-availability="<?= $availability ?>"
                data-item-status="expired">
                <td class="item-name-cell inv-non-clickable" data-label="Item">
                    <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                    <?= $qty===0 ? '<span>Out of stock</span>' : '' ?>
                </td>
                <td class="inv-non-clickable" data-label="Category" style="font-weight:500;color:#374151;">
                    <?= htmlspecialchars($row['category_label']) ?>
                </td>
                <td class="qty-cell" data-label="Stock">
                    <span class="qty-number <?= $qtyClass ?>"><?= $qty ?></span>
                    <span class="qty-unit"><?= htmlspecialchars($row['unit']) ?></span>
                </td>
                <td class="hide-mobile inv-non-clickable" data-label="Expiration">
                    <span class="exp-expired"><?= $expDate ?></span><span class="badge badge-red" style="margin-left:4px;">Expired</span>
                </td>
                <td class="center inv-non-clickable" data-label="Status">
                    <span class="badge badge-red"><?= htmlspecialchars($itemStatus) ?></span>
                </td>
                <td class="hide-mobile inv-non-clickable" data-label="Last Updated" style="font-size:0.78rem;color:#9ca3af;">
                    <?= $lastUpd ?>
                </td>
                <td data-label="Action" class="qty-cell inv-actions-cell">
                    <div class="inv-action-group">
                        <button
                            type="button"
                            class="btn btn-edit js-open-edit-item-modal"
                            data-item-id="<?= $inventoryId ?>"
                            data-item-name="<?= htmlspecialchars($row['item_name'], ENT_QUOTES) ?>"
                            data-item-expiration="<?= htmlspecialchars((string)($row['expiration_date'] ?? ''), ENT_QUOTES) ?>"
                            data-item-expiration-display="<?= htmlspecialchars($expDate, ENT_QUOTES) ?>"
                        >
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                            Edit
                        </button>

                        <?php if ($qty > 0): ?>
                        <form method="post" class="js-zero-expired-form" style="display:inline-flex;margin:0;">
                            <input type="hidden" name="action" value="zero_expired_stock">
                            <input type="hidden" name="item_id" value="<?= $inventoryId ?>">
                            <button
                                type="button"
                                class="btn btn-zero-expired-stock js-open-zero-expired-modal"
                                data-item-name="<?= htmlspecialchars($row['item_name'], ENT_QUOTES) ?>"
                                title="Set stock to zero so this expired item cannot be used in new interventions"
                            >
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg>
                                Zero Stock
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="inv-action-note" title="Stock cleared">Stock cleared</span>
                        <?php endif; ?>

                        <?php if (!$usedInInterventions): ?>
                        <form method="post" class="js-delete-item-form" style="display:inline-flex;margin:0;">
                            <input type="hidden" name="action" value="delete_item">
                            <input type="hidden" name="item_id" value="<?= $inventoryId ?>">
                            <button
                                type="button"
                                class="btn btn-delete js-open-delete-item-modal"
                                data-item-name="<?= htmlspecialchars($row['item_name'], ENT_QUOTES) ?>"
                                title="Delete this item"
                            >
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                Delete
                            </button>
                        </form>
                        <?php else: ?>
                        <button
                            type="button"
                            class="btn btn-delete disabled"
                            disabled
                            aria-disabled="true"
                            title="Cannot delete because this item has existing intervention records."
                        >
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                            Delete
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach;
            else: ?>
            <tr><td colspan="7" class="empty-cell">
                <div class="empty-state">
                    <div class="empty-icon">🚫</div>
                    <h3>No expired items found</h3>
                    <p>All items in stock have valid expiration dates.</p>
                </div>
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- ══ EDIT ITEM MODAL ══ -->
<div class="modal-overlay" id="editItemModal" data-item-name="<?= htmlspecialchars($editItemName, ENT_QUOTES) ?>" data-item-expiration="<?= htmlspecialchars($editExpirationDate, ENT_QUOTES) ?>" data-item-expiration-display="<?= htmlspecialchars($editExpirationDate !== '' ? $editExpirationDate : '—', ENT_QUOTES) ?>">
    <div class="modal-backdrop" id="editBackdrop"></div>
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-accent accent-blue"></div>
        <div class="modal-head">
            <div class="modal-head-left">
                <div class="modal-head-icon icon-blue">✏️</div>
                <div>
                    <h3>Edit Item Expiration</h3>
                    <p id="editItemName">Update the expiration date</p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" id="editModalClose">✕</button>
        </div>
        <div class="modal-body">
            <?php if (!empty($editErrors)): ?>
            <div class="modal-alert error">
                <strong>Please fix the following:</strong>
                <ul><?php foreach ($editErrors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
            </div>
            <?php endif; ?>

            <form method="post" action="" id="editItemForm">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" name="item_id" id="editItemId" value="<?= htmlspecialchars($editItemId) ?>">

                <div class="item-info-box" style="margin-bottom: 20px;">
                    <div class="item-info-title">Inventory Details</div>
                    <div class="item-info-row">
                        <div class="info-chunk">
                            <span class="info-label">Current Expiration</span>
                            <span class="info-value" id="editCurrentExpiration">—</span>
                        </div>
                    </div>
                </div>

                <div class="section-label">Expiration Details</div>
                <div class="field">
                    <label>Expiration Date</label>
                    <input type="date" name="expiration_date" id="editExpirationDate" value="<?= htmlspecialchars($editExpirationDate) ?>">
                    <span class="field-hint">Leave blank if the item has no expiry date. Status will update automatically.</span>
                </div>
            </form>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" id="editModalCancel">Cancel</button>
            <button type="submit" form="editItemForm" class="btn btn-blue">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right: 4px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save Changes
            </button>
        </div>
    </div>
</div>

<!-- ══ DELETE ITEM MODAL ══ -->
<div class="modal-overlay" id="deleteItemModal">
    <div class="modal-backdrop" id="deleteItemBackdrop"></div>
    <div class="modal-box" style="max-width:440px;border-radius:14px;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #e5e7eb;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;border-radius:10px;background:#fee2e2;display:flex;align-items:center;justify-content:center;">🗑️</div>
                <div>
                    <div style="font-size:15px;font-weight:700;color:#0f172a;">Delete Item</div>
                    <div style="font-size:12px;color:#64748b;">This action cannot be undone</div>
                </div>
            </div>
            <button type="button" id="deleteItemClose" style="border:none;background:transparent;color:#64748b;cursor:pointer;font-size:16px;line-height:1;">✕</button>
        </div>
        <div style="padding:16px;">
            <div style="font-size:13px;color:#374151;line-height:1.6;">
                Delete this inventory item?
                <div id="deleteItemNameText" style="margin-top:8px;font-weight:700;color:#111827;"></div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;padding:12px 16px;border-top:1px solid #e5e7eb;">
            <button type="button" id="deleteItemCancel" class="btn btn-outline" style="padding:8px 14px;font-size:12px;">Cancel</button>
            <button type="button" id="deleteItemConfirmBtn" class="btn-delete" style="width:auto;margin-top:0;">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                Delete
            </button>
        </div>
    </div>
</div>

<!-- ══ ZERO EXPIRED STOCK MODAL ══ -->
<div class="modal-overlay" id="zeroExpiredStockModal">
    <div class="modal-backdrop" id="zeroExpiredStockBackdrop"></div>
    <div class="modal-box" style="max-width:440px;border-radius:14px;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #e5e7eb;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;border-radius:10px;background:#fffbeb;display:flex;align-items:center;justify-content:center;">⚠️</div>
                <div>
                    <div style="font-size:15px;font-weight:700;color:#92400e;">Zero Stock Level</div>
                </div>
            </div>
            <button type="button" id="zeroExpiredStockClose" style="border:none;background:transparent;color:#64748b;cursor:pointer;font-size:16px;line-height:1;">✕</button>
        </div>
        <div style="padding:16px;">
            <div style="font-size:13px;color:#374151;line-height:1.6;">
                Set stock for <strong id="zeroExpiredStockNameText"></strong> to zero?
                <div style="margin-top:8px;font-size:12px;color:#6b7280;">The item stays in the system for past intervention records, but it will no longer appear for new give-outs.</div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;padding:12px 16px;border-top:1px solid #e5e7eb;">
            <button type="button" id="zeroExpiredStockCancel" class="btn btn-outline" style="padding:8px 14px;font-size:12px;">Cancel</button>
            <button type="button" id="zeroExpiredStockConfirmBtn" class="btn btn-zero-expired-stock" style="width:auto;margin-top:0;padding:8px 14px;font-size:12px;">Zero Stock</button>
        </div>
    </div>
</div>

<script src="javascript/inventory.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const searchInput = document.getElementById('invSearch');
    const categoryFilter = document.getElementById('categoryFilter');
    const rows = document.querySelectorAll('#invBody tr[data-search]');
    const countSpan = document.getElementById('invCount');

    function applyFilters() {
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const selectedCat = categoryFilter ? categoryFilter.value : '';
        let visibleCount = 0;

        rows.forEach(row => {
            const rowSearch = row.getAttribute('data-search') || '';
            const rowCat = row.getAttribute('data-category') || '';

            const matchSearch = !query || rowSearch.includes(query);
            const matchCat = !selectedCat || rowCat === selectedCat;

            const show = matchSearch && matchCat;
            row.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        if (countSpan) {
            countSpan.textContent = visibleCount + ' item' + (visibleCount !== 1 ? 's' : '');
        }
    }

    if (searchInput) searchInput.addEventListener('input', applyFilters);
    if (categoryFilter) categoryFilter.addEventListener('change', applyFilters);
});
</script>
</body>
</html>
