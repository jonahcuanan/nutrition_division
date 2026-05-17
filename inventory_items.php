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

$successMessage = '';
$errorMessage = '';

if (isset($_GET['restocked']) && $_GET['restocked'] == '1') {
    $successMessage = 'Stock updated! The item quantity has been successfully restocked.';
}

$categoryId = null;
$restockErrors = [];
$restockItemId = '';
$restockQty = '1';
$restockRemarks = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restock_item') {
    $restockItemId = trim($_POST['item_id'] ?? '');
    $restockQty = trim($_POST['restock_qty'] ?? '1');
    $restockRemarks = trim($_POST['restock_remarks'] ?? '');

    if ($restockItemId === '' || !ctype_digit($restockItemId)) {
        $restockErrors[] = 'Invalid item selected.';
    }
    if ($restockQty === '' || !is_numeric($restockQty) || (int)$restockQty < 1) {
        $restockErrors[] = 'Restock quantity must be at least 1.';
    }

    if (empty($restockErrors)) {
        $itemId = (int)$restockItemId;
        $qtyVal = (int)$restockQty;

        $checkStmt = $conn->prepare('SELECT item_name FROM inventory WHERE inventory_id = ?');
        if ($checkStmt) {
            $checkStmt->bind_param('i', $itemId);
            $checkStmt->execute();
            $checkStmt->bind_result($itemName);
            if (!$checkStmt->fetch()) {
                $restockErrors[] = 'Item not found.';
            }
            $checkStmt->close();
        } else {
            $restockErrors[] = 'Database error checking item.';
        }

        if (empty($restockErrors)) {
            $stmt = $conn->prepare('UPDATE inventory SET quantity = quantity + ?, last_updated = NOW() WHERE inventory_id = ?');
            if ($stmt) {
                $stmt->bind_param('ii', $qtyVal, $itemId);
                if ($stmt->execute()) {
                    $stmt->close();
                    $redirectParams = $_GET;
                    $redirectParams['restocked'] = 1;
                    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
                    $redirectUrl = $baseUrl . (!empty($redirectParams) ? ('?' . http_build_query($redirectParams)) : '');
                    header('Location: ' . $redirectUrl);
                    exit;
                }
                $restockErrors[] = 'Failed to restock item. Please try again.';
                $stmt->close();
            } else {
                $restockErrors[] = 'Database error updating stock.';
            }
        }
    }
}
$isUncategorized = false;
$categoryLabel = 'All Categories';

if (isset($_GET['category_id']) && ctype_digit((string)$_GET['category_id'])) {
    $categoryId = (int)$_GET['category_id'];
    $stmt = $conn->prepare('SELECT category_name FROM category_inventory WHERE category_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        $stmt->bind_result($catName);
        if ($stmt->fetch()) {
            $categoryLabel = $catName ?: $categoryLabel;
        } else {
            $categoryLabel = 'Unknown Category';
        }
        $stmt->close();
    }
} elseif (isset($_GET['category']) && $_GET['category'] === 'uncategorized') {
    $isUncategorized = true;
    $categoryLabel = 'Uncategorized';
}

$categories = [];
$catResult = $conn->query("SELECT c.category_id, c.category_name, COUNT(i.inventory_id) AS item_count FROM category_inventory c LEFT JOIN inventory i ON i.category_id = c.category_id GROUP BY c.category_id, c.category_name ORDER BY c.category_name");
if ($catResult && $catResult->num_rows > 0) {
    while ($row = $catResult->fetch_assoc()) {
        $categories[] = $row;
    }
}

$hasUncategorized = false;
$inventoryItems = [];
$today = new DateTime();

if ($categoryId !== null) {
    $stmt = $conn->prepare('SELECT i.inventory_id, i.item_name, i.category_id, c.category_name, i.quantity, i.unit, i.expiration_date, i.last_updated FROM inventory i LEFT JOIN category_inventory c ON i.category_id = c.category_id WHERE i.category_id = ? ORDER BY i.item_name');
    if ($stmt) {
        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $categoryLabelRow = $row['category_name'] ?: 'Uncategorized';
            if ($row['category_name'] === null || $row['category_name'] === '') {
                $hasUncategorized = true;
            }
            $row['category_label'] = $categoryLabelRow;
            $row['category_key'] = strtolower($categoryLabelRow);
            $inventoryItems[] = $row;
        }
        $stmt->close();
    }
} elseif ($isUncategorized) {
    $invResult = $conn->query('SELECT i.inventory_id, i.item_name, i.category_id, c.category_name, i.quantity, i.unit, i.expiration_date, i.last_updated FROM inventory i LEFT JOIN category_inventory c ON i.category_id = c.category_id WHERE i.category_id IS NULL OR i.category_id = 0 ORDER BY i.item_name');
    if ($invResult && $invResult->num_rows > 0) {
        while ($row = $invResult->fetch_assoc()) {
            $row['category_label'] = 'Uncategorized';
            $row['category_key'] = 'uncategorized';
            $inventoryItems[] = $row;
        }
    }
    $hasUncategorized = true;
} else {
    $invResult = $conn->query('SELECT i.inventory_id, i.item_name, i.category_id, c.category_name, i.quantity, i.unit, i.expiration_date, i.last_updated FROM inventory i LEFT JOIN category_inventory c ON i.category_id = c.category_id ORDER BY i.item_name');
    if ($invResult && $invResult->num_rows > 0) {
        while ($row = $invResult->fetch_assoc()) {
            $categoryLabelRow = $row['category_name'] ?: 'Uncategorized';
            if ($row['category_name'] === null || $row['category_name'] === '') {
                $hasUncategorized = true;
            }
            $row['category_label'] = $categoryLabelRow;
            $row['category_key'] = strtolower($categoryLabelRow);
            $inventoryItems[] = $row;
        }
    }
}

$totalItems = count($inventoryItems);
$maxQty = 1;
foreach ($inventoryItems as $item) {
    $qty = (int)$item['quantity'];
    if ($qty > $maxQty) $maxQty = $qty;
}

$lockCategoryFilter = $categoryId !== null || $isUncategorized;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Stock</title>
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
<body data-open-restock-modal="<?= !empty($restockErrors) ? '1' : '0' ?>">
<?php include 'sidebar.php'; ?>

<main class="main-content">
    <div class="page-header">
        <div class="page-header-icon">📦</div>
        <div class="page-header-text">
            <h1>Current Stock</h1>
            <div style="margin-top: 6px;">
                <span style="font-size: 0.75rem; color: var(--slate-500); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Filtering by:</span>
                <span class="badge badge-blue" style="font-size: 0.85rem; padding: 4px 12px; margin-left: 4px; border-width: 1.5px;"><?= htmlspecialchars($categoryLabel) ?></span>
            </div>
        </div>
        <div class="page-header-actions">
            <a class="btn btn-outline" href="inventory.php">← Back to Inventory</a>
        </div>
    </div>

    <div id="toastContainer"
         data-success="<?= htmlspecialchars($successMessage ?? '', ENT_QUOTES) ?>"
         data-error="<?= htmlspecialchars($errorMessage ?? '', ENT_QUOTES) ?>"></div>

    <?php if (isset($_GET['restocked']) && $_GET['restocked'] == '1'): ?>
    <div class="alert alert-success">
        <span class="alert-icon">✅</span>
        <div class="alert-body">
            <strong>Stock updated!</strong>
            <span>The item quantity has been restocked.</span>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-header-icon icon-green">📋</div>
                <div>
                    <h2>Stock Items</h2>
                    <p>View the current inventory for this category</p>
                </div>
            </div>
        </div>
        <div class="toolbar">
            <div class="search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="invSearch" placeholder="Search by item name…">
            </div>
            <!-- Category filter removed as per user request -->
            <select class="filter-select" id="stockFilter">
                <option value="">All Stock Levels</option>
                <option value="low">⚠️ Low Stock (≤5)</option>
                <option value="ok">✅ Sufficient Stock</option>
            </select>
            <select class="filter-select" id="expirationFilter">
                <option value="">All Expiration</option>
                <option value="expired">🔴 Expired</option>
                <option value="soon">🟠 Expiring Soon</option>
                <option value="good">🟢 Good / No Expiry</option>
            </select>
            <select class="filter-select" id="availabilityFilter">
                <option value="">All Availability</option>
                <option value="in">📦 In Stock</option>
                <option value="out">❌ Out of Stock</option>
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
                    <th class="hide-mobile">Last Updated</th>
                    <th class="center">Actions</th>
                </tr>
            </thead>
            <tbody id="invBody">
            <?php if (!empty($inventoryItems)):
                foreach ($inventoryItems as $row):
                    $qty = (int)$row['quantity'];
                    $barPct = $maxQty > 0 ? min(100, round(($qty / $maxQty) * 100)) : 0;
                    if ($qty === 0)     { $qtyClass = 'qty-empty'; $barClass = 'bar-red'; $availability = 'out'; }
                    elseif ($qty <= 5) { $qtyClass = 'qty-warn';  $barClass = 'bar-amber'; $availability = 'in'; }
                    else               { $qtyClass = 'qty-ok';    $barClass = 'bar-green'; $availability = 'in'; }

                    $expDate = '—'; $expClass = 'exp-none'; $expExtra = ''; $expirationStatus = 'good';
                    if ($row['expiration_date']) {
                        $expDt   = new DateTime($row['expiration_date']);
                        $isPast  = $expDt < $today;
                        $diffD   = (int)$today->diff($expDt)->days;
                        $expDate = date('M d, Y', strtotime($row['expiration_date']));
                        if ($isPast)        { 
                            $expClass = 'exp-expired'; 
                            $expExtra = '<span class="badge badge-red" style="margin-left:4px;">Expired</span>';
                            $expirationStatus = 'expired';
                        }
                        elseif ($diffD<=30) { 
                            $expClass = 'exp-soon';    
                            $expExtra = '<span class="badge badge-amber" style="margin-left:4px;">⚠ '.$diffD.'d left</span>';
                            $expirationStatus = 'soon';
                        }
                        else                { 
                            $expClass = 'exp-ok';
                            $expirationStatus = 'good';
                        }
                    }
                    $lastUpd = $row['last_updated'] ? date('M d, Y', strtotime($row['last_updated'])) : '—';
            ?>
            <tr data-search="<?= strtolower(htmlspecialchars($row['item_name'].' '.$row['category_label'])) ?>" 
                data-category="<?= htmlspecialchars($row['category_key']) ?>" 
                data-stock="<?= $qty<=5?'low':'ok' ?>"
                data-expiration="<?= $expirationStatus ?>"
                data-availability="<?= $availability ?>">
                <td class="item-name-cell" data-label="Item">
                    <strong><?= htmlspecialchars($row['item_name']) ?></strong>
                    <?= $qty===0 ? '<span>Out of stock</span>' : '' ?>
                </td>
                <td data-label="Category"><span class="badge badge-blue"><?= htmlspecialchars($row['category_label']) ?></span></td>
                <td class="qty-cell" data-label="Stock">
                    <span class="qty-number <?= $qtyClass ?>"><?= $qty ?></span>
                    <span class="qty-unit"><?= htmlspecialchars($row['unit']) ?></span>
                    <div class="stock-bar-wrap"><div class="stock-bar <?= $barClass ?>" style="width:<?= $barPct ?>%"></div></div>
                </td>
                <td class="hide-mobile" data-label="Expiration">
                    <span class="<?= $expClass ?>"><?= $expDate ?></span><?= $expExtra ?>
                </td>
                <td class="hide-mobile" data-label="Last Updated" style="font-size:0.78rem;color:#9ca3af;">
                    <?= $lastUpd ?>
                </td>
                <td data-label="Action" class="qty-cell">
                    <button
                        type="button"
                        class="btn btn-green btn-restock"
                        data-item-id="<?= (int)$row['inventory_id'] ?>"
                        data-item-name="<?= htmlspecialchars($row['item_name'], ENT_QUOTES) ?>"
                        data-item-unit="<?= htmlspecialchars($row['unit'], ENT_QUOTES) ?>"
                        data-item-qty="<?= $qty ?>"
                    >
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                        Restock
                    </button>
                </td>
            </tr>
            <?php endforeach;
            else: ?>
            <tr><td colspan="6" class="empty-cell">
                <div class="empty-state">
                    <div class="empty-icon">📦</div>
                    <h3>No items found</h3>
                    <p>No inventory items match this category.</p>
                </div>
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- ══ RESTOCK MODAL ══ -->
<div class="modal-overlay" id="restockModal">
    <div class="modal-backdrop" id="restockBackdrop"></div>
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-accent accent-green"></div>
        <div class="modal-head">
            <div class="modal-head-left">
                <div class="modal-head-icon icon-green">📦</div>
                <div>
                    <h3>Restock Inventory</h3>
                    <p id="restockItemName">Select an item to restock</p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" id="restockModalClose">✕</button>
        </div>
        <div class="modal-body">
            <?php if (!empty($restockErrors)): ?>
            <div class="modal-alert error">
                <strong>Please fix the following:</strong>
                <ul><?php foreach ($restockErrors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
            </div>
            <?php endif; ?>

            <form method="post" action="" id="restockForm">
                <input type="hidden" name="action" value="restock_item">
                <input type="hidden" name="item_id" id="restockItemId" value="<?= htmlspecialchars($restockItemId) ?>">

                <div class="item-info-box" style="margin-bottom: 20px;">
                    <div class="item-info-title">Inventory Details</div>
                    <div class="item-info-row">
                        <div class="info-chunk">
                            <span class="info-label">Current Stock</span>
                            <span class="info-value" id="restockCurrentQty">—</span>
                        </div>
                    </div>
                </div>

                <div class="section-label">Restock Details</div>
                
                <div class="field">
                    <label>Quantity to Add <span class="req">*</span></label>
                    <div style="position: relative;">
                        <input type="number" name="restock_qty" id="restockQty" min="1" step="1" required value="<?= htmlspecialchars($restockQty) ?>" placeholder="Enter amount">
                    </div>
                    <span class="field-hint">Specify the number of units to add to the existing stock.</span>
                </div>
            </form>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" id="restockModalCancel">Cancel</button>
            <button type="submit" form="restockForm" class="btn btn-green">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right: 4px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Confirm Restock
            </button>
        </div>
    </div>
</div>

<script src="javascript/inventory.js"></script>
</body>
</html>
