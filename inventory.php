<?php
ob_start(); // Capture any stray output (notices/warnings) so JSON responses are never corrupted.
session_start();
require_once __DIR__ . '/access_control.php';
$inventoryAjaxPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['ajax'] ?? '') === '1');
enforce_page_access(['expectsJson' => $inventoryAjaxPost]);
$currentRole = $_SESSION['role'] ?? '';
if ($currentRole === 'Health Worker') {
    header('Location: dashboard.php');
    exit;
}
require_once 'database.php';
require_once __DIR__ . '/activity_logger.php';

$successMessage = '';
$errorMessage = '';

if (isset($_GET['added']) && $_GET['added'] == '1') {
    $successMessage = 'Item added successfully!';
} elseif (isset($_GET['category_added']) && $_GET['category_added'] == '1') {
    $successMessage = 'Category added successfully!';
} elseif (isset($_GET['category_deleted']) && $_GET['category_deleted'] == '1') {
    $successMessage = 'Category deleted successfully!';
} elseif (isset($_GET['category_delete_error'])) {
    $deleteErr = (string)$_GET['category_delete_error'];
    if ($deleteErr === 'in_use') {
        $errorMessage = 'Cannot delete this category because it still has inventory items.';
    } elseif ($deleteErr === 'not_found') {
        $errorMessage = 'Category not found.';
    } elseif ($deleteErr === 'invalid') {
        $errorMessage = 'Invalid category selected.';
    } else {
        $errorMessage = 'Category deletion failed. Please try again.';
    }
} elseif (isset($_GET['distributed']) && (int)$_GET['distributed'] > 0) {
    $dc = (int)$_GET['distributed'];
    $successMessage = $dc . ' item type' . ($dc > 1 ? 's were' : ' was') . ' successfully given out and stock counts have been updated.';
}

$addErrors = [];
$add_item_name = ''; $add_category_id = ''; $add_quantity = '0';
$add_unit = ''; $add_expiration_date = ''; $add_remarks = 'Initial stock';

$categoryErrors = [];
$category_name = '';

$distributeErrors = [];
$dist_child_id = '';
$dist_remarks  = '';

function normalize_type_name(string $value): string
{
    $normalized = preg_replace('/\s+/', ' ', trim(strtolower($value)));
    return $normalized ?? '';
}

function is_give_out_type_name(?string $value): bool
{
    $normalized = normalize_type_name((string)$value);
    return in_array($normalized, ['give out', 'giveout'], true);
}

function build_person_name(array $row): string
{
    $parts = [];
    if (!empty($row['first_name'])) $parts[] = $row['first_name'];
    if (!empty($row['middle_name'])) $parts[] = $row['middle_name'];
    if (!empty($row['last_name'])) $parts[] = $row['last_name'];
    if (!empty($row['suffix'])) $parts[] = $row['suffix'];
    return trim(implode(' ', $parts));
}

function find_category_name(array $categories, int $categoryId): string
{
    foreach ($categories as $cat) {
        if ((int)($cat['category_id'] ?? 0) === $categoryId) {
            return (string)($cat['category_name'] ?? '');
        }
    }
    return '';
}

function fetch_inventory_labels(mysqli $conn, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT inventory_id, item_name, unit FROM inventory WHERE inventory_id IN ($placeholders)";
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
            $map[$iid] = [
                'name' => (string)($row['item_name'] ?? ''),
                'unit' => (string)($row['unit'] ?? ''),
            ];
        }
    }
    $stmt->close();
    return $map;
}

$inventoryLogRefColumn = null;
try {
    $tableExists = false;
    $tableCheck = $conn->query("SHOW TABLES LIKE 'inventory_logs'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $tableExists = true;
    }
    if ($tableCheck instanceof mysqli_result) {
        $tableCheck->free();
    }

    if ($tableExists) {
        $logColumnCheck = $conn->query("SHOW COLUMNS FROM inventory_logs LIKE 'inventory_id'");
        if ($logColumnCheck && $logColumnCheck->num_rows > 0) {
            $inventoryLogRefColumn = 'inventory_id';
        }
        if ($logColumnCheck instanceof mysqli_result) {
            $logColumnCheck->free();
        }

        if ($inventoryLogRefColumn === null) {
            $legacyColumnCheck = $conn->query("SHOW COLUMNS FROM inventory_logs LIKE 'item_id'");
            if ($legacyColumnCheck && $legacyColumnCheck->num_rows > 0) {
                $inventoryLogRefColumn = 'item_id';
            }
            if ($legacyColumnCheck instanceof mysqli_result) {
                $legacyColumnCheck->free();
            }
        }
    }
} catch (Throwable $e) {
    // Keep page functional even if inventory_logs table is missing or inaccessible.
    $inventoryLogRefColumn = null;
}

$childrenList = [];
$childrenResult = $conn->query("SELECT child_id, first_name, middle_name, last_name, suffix FROM children ORDER BY first_name, last_name");
if ($childrenResult && $childrenResult->num_rows > 0)
    while ($row = $childrenResult->fetch_assoc()) $childrenList[] = $row;

$categories = [];
$catResult = $conn->query("SELECT c.category_id, c.category_name, COUNT(i.inventory_id) AS item_count FROM category_inventory c LEFT JOIN inventory i ON i.category_id = c.category_id GROUP BY c.category_id, c.category_name ORDER BY c.category_name");
if ($catResult && $catResult->num_rows > 0)
    while ($row = $catResult->fetch_assoc()) $categories[] = $row;

/** JSON for inventory AJAX — clears output buffers so stray whitespace/warnings do not break parse. */
function inventory_json_exit(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item') {
        $add_item_name       = trim($_POST['item_name'] ?? '');
        $add_category_id      = trim($_POST['category_id'] ?? '');
        $add_quantity        = trim($_POST['quantity'] ?? '0');
        $add_unit            = trim($_POST['unit'] ?? '');
        $add_expiration_date = trim($_POST['expiration_date'] ?? '');
        $add_remarks         = trim($_POST['remarks'] ?? 'Initial stock');

        $categoryIdVal = null;

        if ($add_item_name === '') $addErrors[] = 'Item name is required.';
        if ($add_category_id === '')  $addErrors[] = 'Category is required.';
        elseif (!ctype_digit($add_category_id)) $addErrors[] = 'Invalid category selected.';
        else $categoryIdVal = (int)$add_category_id;
        if ($add_quantity === '' || !is_numeric($add_quantity) || $add_quantity < 0)
            $addErrors[] = 'Quantity must be 0 or more.';
        if ($add_unit === '') $addErrors[] = 'Unit is required (e.g. pcs, bottles).';

        if (empty($addErrors)) {
            $qtyVal = (int)$add_quantity;
            $expVal = $add_expiration_date !== '' ? $add_expiration_date : null;
            $stmt = $conn->prepare("INSERT INTO inventory (item_name, category_id, quantity, unit, expiration_date, last_updated) VALUES (?, ?, ?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param('siiss', $add_item_name, $categoryIdVal, $qtyVal, $add_unit, $expVal);
                if ($stmt->execute()) {
                    $stmt->close();
                    $catName = find_category_name($categories, $categoryIdVal);
                    $detailsParts = [
                        'Item: ' . $add_item_name,
                        'Qty: ' . $qtyVal . ' ' . $add_unit,
                    ];
                    if ($catName !== '') {
                        $detailsParts[] = 'Category: ' . $catName;
                    }
                    if ($add_expiration_date !== '') {
                        $detailsParts[] = 'Expiry: ' . $add_expiration_date;
                    }
                    log_user_activity(
                        $conn,
                        (int)($_SESSION['user_id'] ?? 0),
                        'inventory_add_item',
                        implode(' | ', $detailsParts)
                    );
                    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                        inventory_json_exit(['success' => true, 'message' => 'Item added successfully!']);
                    }
                    header('Location: inventory.php?added=1');
                    exit;
                }
                else { $addErrors[] = 'Failed to save item. Please try again.'; $stmt->close(); }
            } else { $addErrors[] = 'Database error.'; }
        }
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            inventory_json_exit(['success' => false, 'errors' => $addErrors]);
        }

    } elseif ($action === 'add_category') {
        $category_name = trim($_POST['category_name'] ?? '');

        if ($category_name === '') $categoryErrors[] = 'Category name is required.';

        if (empty($categoryErrors)) {
            $check = $conn->prepare("SELECT category_id FROM category_inventory WHERE category_name = ? LIMIT 1");
            if ($check) {
                $check->bind_param('s', $category_name);
                $check->execute();
                $check->store_result();
                if ($check->num_rows > 0) {
                    $categoryErrors[] = 'That category already exists.';
                }
                $check->close();
            } else {
                $categoryErrors[] = 'Database error checking category.';
            }
        }

        if (empty($categoryErrors)) {
            $stmt = $conn->prepare("INSERT INTO category_inventory (category_name) VALUES (?)");
            if ($stmt) {
                $stmt->bind_param('s', $category_name);
                if ($stmt->execute()) {
                    $stmt->close();
                    log_user_activity(
                        $conn,
                        (int)($_SESSION['user_id'] ?? 0),
                        'inventory_add_category',
                        'Added category: ' . $category_name
                    );
                    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                        inventory_json_exit(['success' => true, 'message' => 'Category added successfully!']);
                    }
                    header('Location: inventory.php?category_added=1');
                    exit;
                }
                else { $categoryErrors[] = 'Failed to save category. Please try again.'; $stmt->close(); }
            } else { $categoryErrors[] = 'Database error.'; }
        }
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            inventory_json_exit(['success' => false, 'errors' => $categoryErrors]);
        }

    } elseif ($action === 'multi_distribute') {
        // Multi-item distribution to one child
        $dist_child_id  = isset($_POST['child_id']) ? (int)$_POST['child_id'] : 0;
        $dist_remarks   = trim($_POST['remarks'] ?? '');
        $itemIds        = isset($_POST['dist_item_ids'])   ? (array)$_POST['dist_item_ids']   : [];
        $itemQtys       = isset($_POST['dist_item_qtys'])  ? (array)$_POST['dist_item_qtys']  : [];

        if ($dist_child_id <= 0)  $distributeErrors[] = 'Please select a child to receive the items.';
        if (empty($itemIds))      $distributeErrors[] = 'Please add at least one item to give out.';

        // Validate each line
        $lines = []; // [inventory_id, qty_to_give]
        if (empty($distributeErrors)) {
            foreach ($itemIds as $idx => $rawId) {
                $itemId  = (int)$rawId;
                $giveQty = isset($itemQtys[$idx]) ? (int)$itemQtys[$idx] : 0;
                if ($itemId <= 0) { $distributeErrors[] = 'Invalid item at row ' . ($idx+1) . '.'; continue; }
                if ($giveQty <= 0) { $distributeErrors[] = 'Quantity must be at least 1 for each item.'; continue; }

                // Check stock
                $chk = $conn->prepare("SELECT quantity, unit, item_name FROM inventory WHERE inventory_id = ?");
                if ($chk) {
                    $chk->bind_param('i', $itemId); $chk->execute();
                    $chk->bind_result($avail, $unit, $iname);
                    if ($chk->fetch()) {
                        if ($giveQty > $avail)
                            $distributeErrors[] = '"' . htmlspecialchars($iname) . '": only ' . $avail . ' ' . $unit . ' available, but ' . $giveQty . ' requested.';
                        else
                            $lines[] = ['inventory_id' => $itemId, 'qty' => $giveQty];
                    } else { $distributeErrors[] = 'Item ID ' . $itemId . ' not found.'; }
                    $chk->close();
                } else { $distributeErrors[] = 'Database error checking stock.'; }
            }
        }

        if (empty($distributeErrors) && !empty($lines)) {
            $txType     = 'DISTRIBUTE';
            $remarksVal = $dist_remarks !== '' ? $dist_remarks : 'Distributed to child';
            $conn->begin_transaction();
            try {
                $u = $conn->prepare("UPDATE inventory SET quantity = quantity - ?, last_updated = NOW() WHERE inventory_id = ?");
                $l = null;
                if ($inventoryLogRefColumn !== null) {
                    $l = $conn->prepare("INSERT INTO inventory_logs ({$inventoryLogRefColumn}, child_id, transaction_type, quantity, transaction_date, remarks) VALUES (?, ?, ?, ?, NOW(), ?)");
                }
                if (!$u || ($inventoryLogRefColumn !== null && !$l)) throw new Exception('Prepare failed.');
                foreach ($lines as $line) {
                    $u->bind_param('ii', $line['qty'], $line['inventory_id']);
                    if (!$u->execute()) throw new Exception('Stock update failed.');
                    if ($l) {
                        $l->bind_param('iisis', $line['inventory_id'], $dist_child_id, $txType, $line['qty'], $remarksVal);
                        if (!$l->execute()) throw new Exception('Log insert failed.');
                    }
                }
                $u->close();
                if ($l) $l->close();
                $conn->commit();
                $count = count($lines);
                $childLabel = '';
                foreach ($childrenList as $child) {
                    if ((int)($child['child_id'] ?? 0) === $dist_child_id) {
                        $childLabel = build_person_name($child);
                        break;
                    }
                }
                if ($childLabel === '') {
                    $childLabel = 'Child #' . $dist_child_id;
                }
                $itemMap = fetch_inventory_labels($conn, array_column($lines, 'inventory_id'));
                $lineDetails = [];
                foreach ($lines as $line) {
                    $iid = (int)$line['inventory_id'];
                    $name = $itemMap[$iid]['name'] ?? ('Item #' . $iid);
                    $unit = $itemMap[$iid]['unit'] ?? '';
                    $lineDetails[] = $name . ' x ' . (int)$line['qty'] . ($unit ? ' ' . $unit : '');
                }
                log_user_activity(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'inventory_distribute',
                    'Distributed to ' . $childLabel . ' | ' . implode(', ', $lineDetails)
                );
                if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
                    inventory_json_exit(['success' => true, 'message' => 'Distribution recorded successfully!']);
                }
                header('Location: inventory.php?distributed=' . $count); exit;
            } catch (Exception $e) {
                $conn->rollback();
                $distributeErrors[] = 'Something went wrong: ' . $e->getMessage() . ' Please try again.';
            }
        }
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            inventory_json_exit(['success' => false, 'errors' => $distributeErrors]);
        }
    } elseif ($action === 'delete_category') {
        $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
        $categoryId = (int)($_POST['category_id'] ?? 0);

        if ($categoryId <= 0) {
            if ($isAjax) inventory_json_exit(['success' => false, 'error' => 'Invalid category selected.']);
            header('Location: inventory.php?category_delete_error=invalid');
            exit;
        }

        $categoryName = '';
        $stmtCategory = $conn->prepare("SELECT category_name FROM category_inventory WHERE category_id = ? LIMIT 1");
        if (!$stmtCategory) {
            if ($isAjax) inventory_json_exit(['success' => false, 'error' => 'Database error.']);
            header('Location: inventory.php?category_delete_error=db');
            exit;
        }
        $stmtCategory->bind_param('i', $categoryId);
        $stmtCategory->execute();
        $stmtCategory->bind_result($categoryName);
        $foundCategory = $stmtCategory->fetch();
        $stmtCategory->close();

        if (!$foundCategory) {
            if ($isAjax) inventory_json_exit(['success' => false, 'error' => 'Category not found.']);
            header('Location: inventory.php?category_delete_error=not_found');
            exit;
        }

        $itemCount = 0;
        $stmtCount = $conn->prepare("SELECT COUNT(*) FROM inventory WHERE category_id = ?");
        if (!$stmtCount) {
            if ($isAjax) inventory_json_exit(['success' => false, 'error' => 'Database error.']);
            header('Location: inventory.php?category_delete_error=db');
            exit;
        }
        $stmtCount->bind_param('i', $categoryId);
        $stmtCount->execute();
        $stmtCount->bind_result($itemCount);
        $stmtCount->fetch();
        $stmtCount->close();

        if ($itemCount > 0) {
            if ($isAjax) inventory_json_exit(['success' => false, 'error' => 'Cannot delete this category because it still has inventory items.']);
            header('Location: inventory.php?category_delete_error=in_use');
            exit;
        }

        $stmtDelete = $conn->prepare("DELETE FROM category_inventory WHERE category_id = ? LIMIT 1");
        if (!$stmtDelete) {
            if ($isAjax) inventory_json_exit(['success' => false, 'error' => 'Database error.']);
            header('Location: inventory.php?category_delete_error=db');
            exit;
        }
        $stmtDelete->bind_param('i', $categoryId);
        $okDelete = $stmtDelete->execute() && $stmtDelete->affected_rows > 0;
        $stmtDelete->close();

        if (!$okDelete) {
            if ($isAjax) inventory_json_exit(['success' => false, 'error' => 'Failed to delete category.']);
            header('Location: inventory.php?category_delete_error=failed');
            exit;
        }

        log_user_activity(
            $conn,
            (int)($_SESSION['user_id'] ?? 0),
            'inventory_delete_category',
            'Deleted category: ' . ($categoryName !== '' ? $categoryName : ('ID ' . $categoryId))
        );
        if ($isAjax) inventory_json_exit(['success' => true, 'message' => 'Category deleted successfully!']);
        header('Location: inventory.php?category_deleted=1');
        exit;
    }

    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        inventory_json_exit([
            'success' => false,
            'errors' => ['Invalid or missing action. Refresh the page and try again.'],
        ]);
    }
}

$inventoryItems = [];
$hasUncategorized = false;
$uncategorizedCount = 0;
$invResult = $conn->query("SELECT i.inventory_id, i.item_name, i.category_id, c.category_name, i.quantity, i.unit, i.expiration_date, i.last_updated FROM inventory i LEFT JOIN category_inventory c ON i.category_id = c.category_id ORDER BY i.item_name");
if ($invResult && $invResult->num_rows > 0) {
    while ($row = $invResult->fetch_assoc()) {
        $categoryLabel = $row['category_name'] ?: 'Uncategorized';
        if ($row['category_name'] === null || $row['category_name'] === '') {
            $hasUncategorized = true;
            $uncategorizedCount++;
        }
        $row['category_label'] = $categoryLabel;
        $row['category_key'] = strtolower($categoryLabel);
        $inventoryItems[] = $row;
    }
}

$logRows = [];
$logsResult = null;
if ($inventoryLogRefColumn !== null) {
    $logsResult = $conn->query("SELECT il.{$inventoryLogRefColumn} AS inventory_ref_id, i.item_name, il.child_id, c.first_name, c.middle_name, c.last_name, c.suffix, il.transaction_type, il.quantity, il.transaction_date, il.remarks
        FROM inventory_logs il
        LEFT JOIN inventory i ON il.{$inventoryLogRefColumn} = i.inventory_id
        LEFT JOIN children c ON il.child_id = c.child_id");
}
if ($logsResult && $logsResult->num_rows > 0) {
    while ($log = $logsResult->fetch_assoc()) {
        $logRows[] = $log;
    }
}

$giveOutTypeId = null;
$stmtGiveOut = $conn->prepare('SELECT type_id, type_name FROM intervention_types');
if ($stmtGiveOut) {
    $stmtGiveOut->execute();
    $resultGiveOut = $stmtGiveOut->get_result();
    while ($resultGiveOut && ($row = $resultGiveOut->fetch_assoc())) {
        if (is_give_out_type_name($row['type_name'] ?? '')) {
            $giveOutTypeId = (int)$row['type_id'];
            break;
        }
    }
    $stmtGiveOut->close();
}

if ($giveOutTypeId !== null) {
    $stmtGiveOutLogs = $conn->prepare(
        'SELECT inv.item_name, i.child_id, c.first_name, c.middle_name, c.last_name, c.suffix,
                ii.quantity_given AS quantity, i.intervention_date, i.description
         FROM interventions i
         INNER JOIN intervention_items ii ON ii.intervention_id = i.intervention_id
         LEFT JOIN inventory inv ON inv.inventory_id = ii.inventory_id
         LEFT JOIN children c ON c.child_id = i.child_id
         WHERE i.type_id = ?'
    );
    if ($stmtGiveOutLogs) {
        $stmtGiveOutLogs->bind_param('i', $giveOutTypeId);
        $stmtGiveOutLogs->execute();
        $resultGiveOutLogs = $stmtGiveOutLogs->get_result();
        while ($row = $resultGiveOutLogs->fetch_assoc()) {
            $logRows[] = [
                'item_name' => $row['item_name'] ?? '—',
                'child_id' => $row['child_id'] ?? null,
                'first_name' => $row['first_name'] ?? null,
                'middle_name' => $row['middle_name'] ?? null,
                'last_name' => $row['last_name'] ?? null,
                'suffix' => $row['suffix'] ?? null,
                'transaction_type' => 'GIVE OUT (INTERVENTION)',
                'quantity' => $row['quantity'] ?? 0,
                'transaction_date' => ($row['intervention_date'] ?? '') !== ''
                    ? $row['intervention_date'] . ' 00:00:00'
                    : null,
                'remarks' => $row['description'] ?? '',
            ];
        }
        $stmtGiveOutLogs->close();
    }
}

usort($logRows, static function ($a, $b) {
    $aTime = isset($a['transaction_date']) ? strtotime((string)$a['transaction_date']) : 0;
    $bTime = isset($b['transaction_date']) ? strtotime((string)$b['transaction_date']) : 0;
    return $bTime <=> $aTime;
});

$logRows = array_slice($logRows, 0, 100);

$logMonths = [];
foreach ($logRows as $log) {
    if (!empty($log['transaction_date'])) {
        $ts = strtotime($log['transaction_date']);
        if ($ts !== false) {
            $key = date('Y-m', $ts);
            $logMonths[$key] = date('F Y', $ts);
        }
    }
}
if (!empty($logMonths)) {
    krsort($logMonths);
}

$totalItems = count($inventoryItems);
$lowStock = 0; $expiringSoon = 0; $totalStock = 0; 
$expiredUnique = 0; $expiredTotalUnits = 0;
$maxQty = 1;
$today = new DateTime();
foreach ($inventoryItems as $item) {
    $qty = (int)$item['quantity'];
    $totalStock += $qty;
    if ($qty > $maxQty) $maxQty = $qty;
    if ($qty <= 5) $lowStock++;
    if ($item['expiration_date']) {
        $exp = new DateTime($item['expiration_date']);
        $diff = (int)$today->diff($exp)->days;
        if ($exp > $today && $diff <= 30) $expiringSoon++;
        if ($exp <= $today) {
            $expiredUnique++;
            $expiredTotalUnits += $qty;
        }
    }
}

// Inventory Category stats based on category_inventory (category_id, category_name)
$inventoryCategoriesCount = 0;
$totalInventoryItems = count($inventoryItems);
$stmtCat = $conn->prepare("SELECT COUNT(*) AS cat_count FROM category_inventory");
if ($stmtCat) {
    $stmtCat->execute();
    $resCat = $stmtCat->get_result();
    if ($resCat && ($rowCat = $resCat->fetch_assoc())) {
        $inventoryCategoriesCount = (int)($rowCat['cat_count'] ?? 0);
    }
    $stmtCat->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
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
<body data-open-add-modal="<?= !empty($addErrors) ? '1' : '0' ?>" data-open-category-modal="<?= !empty($categoryErrors) ? '1' : '0' ?>" data-open-distribute-modal="<?= !empty($distributeErrors) ? '1' : '0' ?>">
<?php include 'sidebar.php'; ?>

<main class="main-content">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-icon">📦</div>
        <div class="page-header-text">
            <h1>Inventory Management</h1>
            <p>Track supplies, monitor stock levels, and record distributions to children</p>
        </div>
        <div class="page-header-actions">
            <button type="button" class="btn btn-outline open-category-modal">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Category
            </button>
        </div>
    </div>

    <!-- Instructions -->
    <div class="alert alert-info" style="margin-bottom: 24px; display: flex; gap: 12px; align-items: center; padding: 16px 18px; border-radius: 14px; background: rgba(254, 242, 242, 0.85); border: 1px solid #fee2e2; color: #000000; backdrop-filter: blur(4px); box-shadow: 0 1px 3px rgba(0,0,0,0.02); font-family: inherit;">
        <div style="flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #000000;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
        </div>
        <div style="font-size: 0.85rem; line-height: 1.5; margin: 0;">
            Instructions: Manage inventory and track resources. Use the Add Category button before registering a new item. Record distributions correctly to manage stock levels.
        </div>
    </div>

    <!-- Alerts Container (for dynamic JS alerts) -->
    <div id="dynamicAlertsContainer"></div>
    <div id="toastContainer"
         data-success="<?= htmlspecialchars($successMessage ?? '', ENT_QUOTES) ?>"
         data-error="<?= htmlspecialchars($errorMessage ?? '', ENT_QUOTES) ?>"></div>

    <?php if (isset($_GET['added']) && $_GET['added'] == '1'): ?>
    <div class="alert alert-success">
        <span class="alert-icon">✅</span>
        <div class="alert-body">
            <strong>Item added successfully!</strong>
            <span>The new inventory item has been saved and is ready to use.</span>
        </div>
    </div>
    <?php elseif (isset($_GET['category_added']) && $_GET['category_added'] == '1'): ?>
    <div class="alert alert-success">
        <span class="alert-icon">✅</span>
        <div class="alert-body">
            <strong>Category added!</strong>
            <span>You can now select it in the category dropdown.</span>
        </div>
    </div>
    <?php elseif (isset($_GET['category_deleted']) && $_GET['category_deleted'] == '1'): ?>
    <div class="alert alert-success">
        <span class="alert-icon">✅</span>
        <div class="alert-body">
            <strong>Category deleted!</strong>
            <span>The category was removed successfully.</span>
        </div>
    </div>
    <?php elseif (isset($_GET['category_delete_error'])): ?>
    <div class="alert alert-error">
        <span class="alert-icon">⚠️</span>
        <div class="alert-body">
            <strong>Category deletion failed.</strong>
            <span>
                <?php
                    $deleteErr = (string)($_GET['category_delete_error'] ?? '');
                    if ($deleteErr === 'in_use') {
                        echo 'Cannot delete this category because it still has inventory items.';
                    } elseif ($deleteErr === 'not_found') {
                        echo 'Category not found.';
                    } elseif ($deleteErr === 'invalid') {
                        echo 'Invalid category selected.';
                    } else {
                        echo 'Please try again.';
                    }
                ?>
            </span>
        </div>
    </div>
    <?php elseif (isset($_GET['distributed']) && (int)$_GET['distributed'] > 0):
        $dc = (int)$_GET['distributed']; ?>
    <div class="alert alert-success">
        <span class="alert-icon">✅</span>
        <div class="alert-body">
            <strong>Distribution recorded!</strong>
            <span><?= $dc ?> item type<?= $dc > 1 ? 's were' : ' was' ?> successfully given out and stock counts have been updated.</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="summary-grid" id="summaryGrid">
        <div class="summary-card">
            <div class="s-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;">🏷️</div>
            <div>
                <div class="s-value"><?= number_format($inventoryCategoriesCount) ?></div>
                <div class="s-label">Inventory Categories</div>
                <div class="s-note"><?= number_format($totalInventoryItems) ?> total items in inventory</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="s-icon s-blue">🔢</div>
            <div>
                <div class="s-value"><?= number_format($totalStock) ?></div>
                <div class="s-label">Total Units in Stock</div>
                <div class="s-note">Across all <?= $totalItems ?> item</div>
            </div>
        </div>
        <div class="summary-card <?= $lowStock > 0 ? 'warn' : '' ?>">
            <div class="s-icon s-amber">⚠️</div>
            <div>
                <div class="s-value"><?= $lowStock ?></div>
                <div class="s-label">Low Stock Items</div>
                <div class="s-note">5 or fewer units left</div>
            </div>
        </div>
        <div class="summary-card <?= $expiringSoon > 0 ? 'danger' : '' ?>">
            <div class="s-icon s-red">📅</div>
            <div>
                <div class="s-value"><?= $expiringSoon ?></div>
                <div class="s-label">Expiring Soon</div>
                <div class="s-note">Within the next 30 days</div>
            </div>
        </div>
        <div class="summary-card <?= $expiredUnique > 0 ? 'danger' : '' ?>">
            <div class="s-icon" style="background:linear-gradient(135deg,#b91c1c,#991b1b);color:#fff;">🚫</div>
            <div>
                <div class="s-value"><?= number_format($expiredUnique) ?></div>
                <div class="s-label">Expired Items</div>
                <div class="s-note"><?= number_format($expiredTotalUnits) ?> total units expired</div>
            </div>
        </div>
    </div>

    <!-- Category List -->
    <div class="card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-header-icon icon-green">🏷️</div>
                <div>
                    <h2>Inventory Categories</h2>
                    <p>Manage categories before adding new items</p>
                </div>
            </div>
            <button type="button" class="btn btn-green" id="openAddModalBtn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add New Item
            </button>
        </div>
        <div class="table-scroll" id="categoryTableWrap">
        <table class="table-stack">
            <thead>
                <tr>
                    <th>Category Name</th>
                    <th class="center">Items</th>
                    <th class="center">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($categories) || $hasUncategorized): ?>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td data-label="Category" style="font-weight:600;color:#111827;"><?= htmlspecialchars($cat['category_name']) ?></td>
                    <td data-label="Items" class="qty-cell"><span class="badge badge-blue"><?= (int)$cat['item_count'] ?></span></td>
                    <td data-label="View" class="qty-cell">
                        <div style="display:flex;align-items:center;justify-content:center;gap:8px;">
                            <a class="btn btn-outline btn-view" href="inventory_items.php?category_id=<?= (int)$cat['category_id'] ?>">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                View
                            </a>
                            <form method="post" class="js-delete-category-form" style="display:inline-flex;" data-inventory-ajax="1">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="category_id" value="<?= (int)$cat['category_id'] ?>">
                                <button
                                    type="button"
                                    class="tbl-btn-delete js-open-delete-category-modal<?= ((int)$cat['item_count'] > 0) ? ' disabled' : '' ?>"
                                    data-category-name="<?= htmlspecialchars((string)($cat['category_name'] ?? 'this category'), ENT_QUOTES, 'UTF-8') ?>"
                                    <?= ((int)$cat['item_count'] > 0) ? 'disabled aria-disabled="true" title="Cannot delete because this category has items."' : '' ?>
                                >
                                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ($hasUncategorized): ?>
                <tr>
                    <td data-label="Category" style="font-weight:600;color:#111827;">Uncategorized</td>
                    <td data-label="Items" class="qty-cell"><span class="badge badge-gray"><?= $uncategorizedCount ?></span></td>
                    <td data-label="View" class="qty-cell">
                        <a class="btn btn-outline btn-view" href="inventory_items.php?category=uncategorized">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            View
                        </a>
                    </td>
                </tr>
                <?php endif; ?>
            <?php else: ?>
                <tr><td colspan="3" class="empty-cell">
                    <div class="empty-state">
                        <div class="empty-icon">🏷️</div>
                        <h3>No categories yet</h3>
                        <p>Click "Add Category" to create your first one.</p>
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Distribution History -->
    <div class="card">
        <div class="card-header">
            <div class="card-header-left">
                <div class="card-header-icon icon-blue">📜</div>
                <div>
                    <h2>Distribution History</h2>
                    <p>Includes Give Out interventions and inventory distributions (last 100 entries)</p>
                </div>
            </div>
            <div class="card-header-actions dist-filter">
                <div class="dist-search">
                    <input type="text" id="distSearch" class="dist-search-input" placeholder="Search item, child, notes...">
                </div>
                <select id="distMonthFilter" class="filter-select">
                    <option value="">All months</option>
                    <?php foreach ($logMonths as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="table-scroll" id="distHistoryWrap">
        <table class="table-stack">
            <thead>
                <tr>
                    <th>Item Given</th>
                    <th>Given To (Child)</th>
                    <th class="center">Qty Given</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th class="notes-col hide-mobile">Notes</th>
                </tr>
            </thead>
            <tbody id="distBody">
            <?php if (!empty($logRows)):
                foreach ($logRows as $i => $log):
                    $txDate = $log['transaction_date'] ? date('M d, Y', strtotime($log['transaction_date'])) : '—';
                    $txTime = $log['transaction_date'] ? date('g:i A', strtotime($log['transaction_date'])) : '—';
                    $txMonth = $log['transaction_date'] ? date('Y-m', strtotime($log['transaction_date'])) : '';
                    $n = trim(($log['first_name']??'').' '.($log['middle_name']??'').' '.($log['last_name']??''));
                    if (!empty($log['suffix'])) $n .= ' '.$log['suffix'];
                    $childLabel = $log['child_id']!==null ? ($n?:'Child #'.(int)$log['child_id']) : '—';
                    $searchText = strtolower(trim(($log['item_name'] ?? '') . ' ' . $childLabel . ' ' . ($log['remarks'] ?? '')));
            ?>
            <tr data-month="<?= htmlspecialchars($txMonth) ?>" data-search="<?= htmlspecialchars($searchText) ?>">
                <td data-label="Item" style="font-weight:600;color:#111827;"><?= htmlspecialchars($log['item_name']??'—') ?></td>
                <td data-label="Child" style="color:#374151;"><?= htmlspecialchars($childLabel) ?></td>
                <td data-label="Qty" style="text-align:center;"><span class="badge badge-blue"><?= htmlspecialchars($log['quantity']) ?></span></td>
                <td data-label="Date" style="font-size:0.82rem;color:#374151;"><?= $txDate ?></td>
                <td data-label="Time" style="font-size:0.82rem;color:#6b7280;"><?= $txTime ?></td>
                <td data-label="Notes" class="notes-col hide-mobile" style="font-size:0.82rem;color:#6b7280;font-style:<?= empty($log['remarks'])?'italic':'normal' ?>;"><?= htmlspecialchars($log['remarks']??'—') ?></td>
            </tr>
            <?php endforeach;
            ?>
            <tr id="distNoResults" style="display:none;">
                <td colspan="6" class="empty-cell">
                    <div class="empty-state">
                        <div class="empty-icon">📅</div>
                        <h3>No records for that month</h3>
                        <p>Try another month or clear the filter.</p>
                    </div>
                </td>
            </tr>
            <?php
            else: ?>
            <tr><td colspan="6" class="empty-cell">
                <div class="empty-state">
                    <div class="empty-icon">📜</div>
                    <h3>No distributions yet</h3>
                    <p>When you give out items, they will appear here.</p>
                </div>
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

</main>

<!-- ══ ADD ITEM MODAL ══ -->
<div class="modal-overlay" id="addItemModal">
    <div class="modal-backdrop" id="addBackdrop"></div>
    <div class="modal-box">
        <div class="modal-accent accent-green"></div>
        <div class="modal-head">
            <div class="modal-head-left">
                <div class="modal-head-icon icon-green">➕</div>
                <div><h3>Add New Inventory Item</h3><p>Fill in the details to add an item to your stock</p></div>
            </div>
            <button type="button" class="modal-close-btn" id="addModalClose">✕</button>
        </div>
        <div class="modal-body">
            <?php if (!empty($addErrors)): ?>
            <div class="modal-alert error">
                <strong>Please fix the following:</strong>
                <ul><?php foreach ($addErrors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
            </div>
            <?php endif; ?>
            <form method="post" action="" id="addItemForm" data-inventory-ajax="1">
                <input type="hidden" name="action" value="add_item">
                <div class="section-label">Item Information</div>
                <div class="form-grid-2">
                    <div class="field">
                        <label>Item Name <span class="req">*</span></label>
                        <input type="text" name="item_name" placeholder="e.g. Vitamin A Capsule" required value="<?= htmlspecialchars($add_item_name) ?>">
                    </div>
                    <div class="field">
                        <label>Category <span class="req">*</span></label>
                        <select name="category_id" id="addItemCategorySelect" required>
                            <option value="">— Select category —</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['category_id'] ?>" <?= ($add_category_id !== '' && (int)$add_category_id === (int)$cat['category_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['category_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <hr class="hr-divider">
                <div class="section-label">Stock Details</div>
                <div class="form-grid-3">
                    <div class="field">
                        <label>Starting Quantity <span class="req">*</span></label>
                        <input type="number" name="quantity" min="0" step="1" required value="<?= htmlspecialchars($add_quantity) ?>" placeholder="0">
                        <span class="field-hint">How many units on hand?</span>
                    </div>
                    <div class="field">
                        <label>Unit <span class="req">*</span></label>
                        <input type="text" name="unit" placeholder="pcs, bottles, sachets…" required value="<?= htmlspecialchars($add_unit) ?>">
                        <span class="field-hint">What is each unit called?</span>
                    </div>
                    <div class="field">
                        <label>Expiration Date</label>
                        <input type="date" name="expiration_date" value="<?= htmlspecialchars($add_expiration_date) ?>">
                        <span class="field-hint">Leave blank if none</span>
                    </div>
                </div>
                <div class="field">
                    <label>Notes / Remarks</label>
                    <textarea name="remarks" placeholder="Any additional notes about this item…"><?= htmlspecialchars($add_remarks) ?></textarea>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-outline" id="addModalCancel">Cancel</button>
                    <button type="submit" class="btn btn-green">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Save Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ ADD CATEGORY MODAL ══ -->
<div class="modal-overlay" id="categoryModal">
    <div class="modal-backdrop" id="categoryBackdrop"></div>
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-accent accent-green"></div>
        <div class="modal-head">
            <div class="modal-head-left">
                <div class="modal-head-icon icon-green">🏷️</div>
                <div><h3>Add Category</h3><p>Create a new inventory category</p></div>
            </div>
            <button type="button" class="modal-close-btn" id="categoryModalClose">✕</button>
        </div>
        <div class="modal-body">
            <?php if (!empty($categoryErrors)): ?>
            <div class="modal-alert error">
                <strong>Please fix the following:</strong>
                <ul><?php foreach ($categoryErrors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
            </div>
            <?php endif; ?>
            <form method="post" action="" id="addCategoryForm" data-inventory-ajax="1">
                <input type="hidden" name="action" value="add_category">
                <div class="field">
                    <label>Category Name <span class="req">*</span></label>
                    <input type="text" name="category_name" placeholder="e.g. Supplements" required value="<?= htmlspecialchars($category_name) ?>">
                    <span class="field-hint">This will appear in the item category dropdown.</span>
                </div>
                <div class="field" style="margin-top:12px;">
                    <label>Existing Categories</label>
                    <div id="addCategoryBadges">
                    <?php if (!empty($categories)): ?>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;">
                            <?php foreach ($categories as $cat): ?>
                                <span class="badge badge-blue"><?= htmlspecialchars($cat['category_name']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size:0.8rem;color:#6b7280;">No categories yet.</div>
                    <?php endif; ?>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-outline" id="categoryModalCancel">Cancel</button>
                    <button type="submit" class="btn btn-green">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ DELETE CATEGORY MODAL ══ -->
<div class="modal-overlay" id="deleteCategoryModal">
    <div class="modal-backdrop" id="deleteCategoryBackdrop"></div>
    <div class="modal-box" style="max-width:440px;border-radius:14px;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #e5e7eb;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;border-radius:10px;background:#fee2e2;display:flex;align-items:center;justify-content:center;">🗑️</div>
                <div>
                    <div style="font-size:15px;font-weight:700;color:#0f172a;">Delete Category</div>
                    <div style="font-size:12px;color:#64748b;">This action cannot be undone</div>
                </div>
            </div>
            <button type="button" id="deleteCategoryClose" style="border:none;background:transparent;color:#64748b;cursor:pointer;font-size:16px;line-height:1;">✕</button>
        </div>
        <div style="padding:16px;">
            <div style="font-size:13px;color:#374151;line-height:1.6;">
                Delete this category?
                <div id="deleteCategoryNameText" style="margin-top:8px;font-weight:700;color:#111827;"></div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;padding:12px 16px;border-top:1px solid #e5e7eb;">
            <button type="button" id="deleteCategoryCancel" class="btn btn-outline" style="padding:8px 14px;font-size:12px;">Cancel</button>
            <button type="button" id="deleteCategoryConfirmBtn" class="btn-delete" style="width:auto;margin-top:0;">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                Delete
            </button>
        </div>
    </div>
</div>

<!-- ══ DISTRIBUTE MODAL ══ -->
<div class="modal-overlay" id="distributeModal">
    <div class="modal-backdrop" id="distBackdrop"></div>
    <div class="modal-box" style="max-width:800px;">
        <div class="modal-accent accent-blue"></div>
        <div class="modal-head">
            <div class="modal-head-left">
                <div class="modal-head-icon icon-blue">📤</div>
                <div><h3>Give Out Items to a Child</h3><p>Select a child, then add items and quantities to give out</p></div>
            </div>
            <button type="button" class="modal-close-btn" id="distModalClose">✕</button>
        </div>
        <div class="modal-body">
            <?php if (!empty($distributeErrors)): ?>
            <div class="modal-alert error">
                <strong>Could not complete distribution:</strong>
                <ul><?php foreach ($distributeErrors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
            </div>
            <?php endif; ?>

            <form method="post" action="" id="distributeForm" data-inventory-ajax="1">
                <input type="hidden" name="action" value="multi_distribute">

                <!-- Step 1: Child -->
                <div class="section-label">Step 1 — Who is receiving the items?</div>
                <div class="field" style="margin-bottom:18px;">
                    <label>Select Child <span class="req">*</span></label>
                    <select id="dist_child_id" name="child_id" required>
                        <option value="">— Choose a child —</option>
                        <?php foreach ($childrenList as $child):
                            $n = trim(($child['first_name']??'').' '.($child['middle_name']??'').' '.($child['last_name']??''));
                            if (!empty($child['suffix'])) $n .= ' '.$child['suffix'];
                            $n = $n ?: 'Child #'.(int)$child['child_id'];
                        ?>
                        <option value="<?= (int)$child['child_id'] ?>"><?= htmlspecialchars($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <hr class="hr-divider">

                <!-- Step 2: Add items -->
                <div class="section-label">Step 2 — Add items to give out</div>

                <!-- Item picker row -->
                <div class="item-picker-row">
                    <div class="item-picker-select">
                        <select id="pickerItemSelect">
                            <option value="">— Select an item —</option>
                            <?php foreach ($inventoryItems as $item):
                                if ((int)$item['quantity'] <= 0) continue;
                            ?>
                            <option value="<?= (int)$item['inventory_id'] ?>"
                                data-name="<?= htmlspecialchars($item['item_name'],ENT_QUOTES) ?>"
                                data-unit="<?= htmlspecialchars($item['unit'],ENT_QUOTES) ?>"
                                data-max="<?= (int)$item['quantity'] ?>"
                                data-category="<?= htmlspecialchars($item['category_label'],ENT_QUOTES) ?>">
                                <?= htmlspecialchars($item['item_name']) ?> (<?= (int)$item['quantity'] ?> <?= htmlspecialchars($item['unit']) ?> available)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="item-picker-qty">
                        <input type="number" id="pickerQty" min="1" value="1" placeholder="Qty">
                    </div>
                    <button type="button" class="btn btn-blue" id="addToCartBtn" style="flex-shrink:0;padding:9px 14px;">
                        + Add
                    </button>
                </div>
                <div id="pickerError" class="qty-warning" style="margin-top:6px;margin-bottom:0;"></div>

                <!-- Cart -->
                <div id="cartWrap" style="margin-top:14px;">
                    <div id="cartEmpty" style="text-align:center;padding:20px 0;color:#9ca3af;font-size:0.82rem;border:1.5px dashed #e5e7eb;border-radius:8px;">
                        No items added yet. Use the picker above to add items.
                    </div>
                    <table id="cartTable" style="display:none;width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                        <thead>
                            <tr>
                                <th style="background:#f9fafb;padding:8px 12px;text-align:left;font-size:0.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb;">Item</th>
                                <th style="background:#f9fafb;padding:8px 12px;text-align:center;font-size:0.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb;">Available</th>
                                <th style="background:#f9fafb;padding:8px 12px;text-align:center;font-size:0.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb;">Qty to Give</th>
                                <th style="background:#f9fafb;padding:8px 4px;text-align:center;font-size:0.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb;"></th>
                            </tr>
                        </thead>
                        <tbody id="cartBody"></tbody>
                    </table>
                    <!-- Hidden inputs for submission -->
                    <div id="cartInputs"></div>
                </div>

                <hr class="hr-divider" style="margin-top:16px;">

                <!-- Notes -->
                <div class="field" style="margin-bottom:0;">
                    <label>Notes / Reason <span style="color:#9ca3af;font-weight:400;text-transform:none;font-size:0.7rem;">(optional)</span></label>
                    <textarea name="remarks" placeholder="e.g. Monthly vitamin supplementation, feeding program…"><?= htmlspecialchars($dist_remarks) ?></textarea>
                </div>
                <div class="modal-foot">
                    <span id="cartSummaryText" style="margin-right:auto;font-size:0.8rem;color:#6b7280;align-self:center;"></span>
                    <button type="button" class="btn btn-outline" id="distModalCancel">Cancel</button>
                    <button type="submit" class="btn btn-blue" id="distSubmitBtn" disabled style="opacity:.45;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        Confirm & Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="javascript/inventory.js"></script>
</body>
</html>