<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();
$currentRole = $_SESSION['role'] ?? '';
if ($currentRole === 'Health Worker') {
    header('Location: dashboard.php');
    exit;
}
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/activity_logger.php';

// ── AJAX ENDPOINT: CHECK BARANGAY OR PSGC EXISTS ──
if (isset($_GET['action']) && $_GET['action'] === 'check_exists') {
    header('Content-Type: application/json');
    
    if (!function_exists('normalize_barangay_key')) {
        function normalize_barangay_key($value) {
            $normalized = strtoupper(trim($value));
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            return preg_replace('/\s+/', '', $normalized);
        }
    }

    $barangay_name = trim($_POST['barangay_name'] ?? '');
    $psgc = trim($_POST['psgc'] ?? '');
    $exclude_id = isset($_POST['exclude_id']) ? (int)$_POST['exclude_id'] : 0;

    if ($barangay_name !== '') {
        $name_key = normalize_barangay_key($barangay_name);
        $sql = "SELECT 1 FROM barangays WHERE REPLACE(REPLACE(REPLACE(UPPER(barangay_name), ' ', ''), '\t', ''), '\n', '') = ?";
        if ($exclude_id > 0) $sql .= " AND barangay_id != ?";
        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($exclude_id > 0) $stmt->bind_param('si', $name_key, $exclude_id);
            else $stmt->bind_param('s', $name_key);
            $stmt->execute();
            $stmt->store_result();
            $exists = ($stmt->num_rows > 0);
            $stmt->close();
            if ($exists) {
                echo json_encode(['success' => true, 'exists' => true, 'message' => 'Barangay name already exists. Please use a unique name.']);
                exit;
            }
        }
    }

    if ($psgc !== '') {
        $sql = "SELECT 1 FROM barangays WHERE psgc = ?";
        if ($exclude_id > 0) $sql .= " AND barangay_id != ?";
        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($exclude_id > 0) $stmt->bind_param('si', $psgc, $exclude_id);
            else $stmt->bind_param('s', $psgc);
            $stmt->execute();
            $stmt->store_result();
            $exists = ($stmt->num_rows > 0);
            $stmt->close();
            if ($exists) {
                echo json_encode(['success' => true, 'exists' => true, 'message' => 'PSGC code already exists. Please use a unique PSGC code.']);
                exit;
            }
        }
    }

    echo json_encode(['success' => true, 'exists' => false]);
    exit;
}



$successMessage = '';
$errorMessage   = '';
$ajaxPayload = null;

$isAjaxRequest = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || (
    !empty($_SERVER['HTTP_ACCEPT'])
    && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
);

$formData = [
    'barangay_id'                => '',
    'barangay_name'              => '',
    'city'                       => 'Bislig City',
    'province'                   => 'Surigao Del Sur',
    'total_population'           => '',
    'estimated_children_measured'=> '',
    'psgc'                       => '',
];

function format_optional_number($value) {
    if ($value === null || $value === '') return '—';
    return number_format((int)$value);
}

function format_optional_text($value) {
    if ($value === null || $value === '') return '—';
    return htmlspecialchars($value);
}

function normalize_barangay_name($value) {
    $normalized = strtoupper(trim($value));
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return $normalized;
}

function normalize_barangay_key($value) {
    $normalized = normalize_barangay_name($value);
    return preg_replace('/\s+/', '', $normalized);
}

/* ── Delete ── */
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $checkStmt = $conn->prepare('SELECT COUNT(*) FROM children WHERE barangay_id = ?');
    if ($checkStmt) {
        $checkStmt->bind_param('i', $delete_id);
        $checkStmt->execute();
        $checkStmt->bind_result($childCount);
        $checkStmt->fetch();
        $checkStmt->close();
        if ($childCount > 0) {
            $errorMessage = 'Cannot delete barangay — child records exist.';
        }
    }
    if ($errorMessage === '') {
        $barangayName = '';
        $nameStmt = $conn->prepare('SELECT barangay_name FROM barangays WHERE barangay_id = ?');
        if ($nameStmt) {
            $nameStmt->bind_param('i', $delete_id);
            $nameStmt->execute();
            $nameStmt->bind_result($barangayName);
            $nameStmt->fetch();
            $nameStmt->close();
        }

        $stmt = $conn->prepare('DELETE FROM barangays WHERE barangay_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $delete_id);
            if ($stmt->execute()) {
                $successMessage = 'Barangay deleted successfully.';
                log_user_activity($conn, (int)$_SESSION['user_id'], 'barangay_delete', 'Deleted barangay: ' . $barangayName);
                $ajaxPayload = [
                    'action' => 'delete',
                    'barangay_id' => $delete_id,
                ];
            } else {
                $errorMessage = 'Failed to delete barangay.';
            }
            $stmt->close();
        }
    }

}

/* ── Add / Edit ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['barangay_id']                 = trim($_POST['barangay_id'] ?? '');
    $formData['barangay_name']               = trim($_POST['barangay_name'] ?? '');
    $formData['city']                        = trim($_POST['city'] ?? '');
    $formData['province']                    = trim($_POST['province'] ?? '');
    $formData['total_population']            = trim($_POST['total_population'] ?? '');
    $formData['estimated_children_measured'] = trim($_POST['estimated_children_measured'] ?? '');
    $formData['psgc']                        = trim($_POST['psgc'] ?? '');

    $total_population = $estimated_children_measured = null;
    $psgc = $formData['psgc'];

    if ($formData['barangay_name'] === '' || $formData['city'] === '' || $formData['province'] === '' ||
        $formData['total_population'] === '' || $formData['estimated_children_measured'] === '' || $psgc === '') {
        $errorMessage = 'All fields are required.';
    }
    if ($errorMessage === '' && !ctype_digit($formData['total_population'])) {
        $errorMessage = 'Total population must be a whole number.';
    } else if ($errorMessage === '') {
        $total_population = (int)$formData['total_population'];
    }
    if ($errorMessage === '' && !ctype_digit($formData['estimated_children_measured'])) {
        $errorMessage = 'Estimated children measured must be a whole number.';
    } else if ($errorMessage === '') {
        $estimated_children_measured = (int)$formData['estimated_children_measured'];
    }
    if ($errorMessage === '' && !preg_match('/^\d{1,10}$/', $psgc)) {
        $errorMessage = 'PSGC must be 1–10 digits.';
    }
    if ($errorMessage === '' && $estimated_children_measured > $total_population) {
        $errorMessage = 'Estimated children measured cannot exceed total population.';
    }
    if ($errorMessage === '') {
        $isUpdate = ctype_digit($formData['barangay_id']) && (int)$formData['barangay_id'] > 0;
        if (!$isUpdate) {
            $nameKey = normalize_barangay_key($formData['barangay_name']);
            if ($nameKey !== '') {
                $dupSql = "SELECT 1 FROM barangays
                           WHERE REPLACE(REPLACE(REPLACE(UPPER(barangay_name), ' ', ''), '\t', ''), '\n', '') = ?
                           LIMIT 1";
                $dupStmt = $conn->prepare($dupSql);
                if ($dupStmt) {
                    $dupStmt->bind_param('s', $nameKey);
                    $dupStmt->execute();
                    $dupStmt->store_result();
                    if ($dupStmt->num_rows > 0) {
                        $errorMessage = 'Barangay name already exists. Please use a unique name.';
                    }
                    $dupStmt->close();
                } else {
                    $errorMessage = 'Database error while validating barangay name.';
                }
            }
        }

        // ── Check for duplicate PSGC ──
        if ($errorMessage === '') {
            $psgcSql = $isUpdate 
                ? "SELECT 1 FROM barangays WHERE psgc = ? AND barangay_id != ? LIMIT 1"
                : "SELECT 1 FROM barangays WHERE psgc = ? LIMIT 1";
            $psgcStmt = $conn->prepare($psgcSql);
            if ($psgcStmt) {
                if ($isUpdate) {
                    $psgcStmt->bind_param('si', $psgc, $formData['barangay_id']);
                } else {
                    $psgcStmt->bind_param('s', $psgc);
                }
                $psgcStmt->execute();
                $psgcStmt->store_result();
                if ($psgcStmt->num_rows > 0) {
                    $errorMessage = 'PSGC code already exists. Please use a unique PSGC code.';
                }
                $psgcStmt->close();
            } else {
                $errorMessage = 'Database error while validating PSGC.';
            }
        }
    }
    if ($errorMessage === '') {
        if ($isUpdate) {
            $stmt = $conn->prepare('UPDATE barangays SET barangay_name=?, city=?, province=?, total_population=?, estimated_children_measured=?, psgc=? WHERE barangay_id=?');
            if ($stmt) {
                $stmt->bind_param('sssiisi', $formData['barangay_name'], $formData['city'], $formData['province'], $total_population, $estimated_children_measured, $psgc, $formData['barangay_id']);
                if ($stmt->execute()) {
                    $successMessage = 'Barangay updated successfully.';
                    log_user_activity($conn, (int)$_SESSION['user_id'], 'barangay_edit', 'Updated barangay: ' . $formData['barangay_name']);
                    $ajaxPayload = [
                        'action' => 'update',
                        'barangay_id' => (int)$formData['barangay_id'],
                        'barangay_name' => $formData['barangay_name'],
                        'city' => $formData['city'],
                        'province' => $formData['province'],
                        'total_population' => $total_population,
                        'estimated_children_measured' => $estimated_children_measured,
                        'psgc' => $psgc,
                    ];
                    $formData = array_fill_keys(array_keys($formData), '');
                } else {
                    $errorMessage = 'Failed to update barangay.';
                }
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare('INSERT INTO barangays (barangay_name, city, province, total_population, estimated_children_measured, psgc) VALUES (?, ?, ?, ?, ?, ?)');
            if ($stmt) {
                $stmt->bind_param('sssiis', $formData['barangay_name'], $formData['city'], $formData['province'], $total_population, $estimated_children_measured, $psgc);
                if ($stmt->execute()) {
                    $newId = (int)$conn->insert_id;
                    $successMessage = 'Barangay added successfully.';
                    log_user_activity($conn, (int)$_SESSION['user_id'], 'barangay_add', 'Added new barangay: ' . $formData['barangay_name']);
                    $ajaxPayload = [
                        'action' => 'add',
                        'barangay_id' => $newId,
                        'barangay_name' => $formData['barangay_name'],
                        'city' => $formData['city'],
                        'province' => $formData['province'],
                        'total_population' => $total_population,
                        'estimated_children_measured' => $estimated_children_measured,
                        'psgc' => $psgc,
                    ];
                    $formData = array_fill_keys(array_keys($formData), '');
                } else {
                    $errorMessage = 'Failed to add barangay.';
                }
                $stmt->close();
            }
        }

    }
}

if ($isAjaxRequest && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['delete_id']))) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => $successMessage !== '',
        'message' => $successMessage !== '' ? $successMessage : $errorMessage,
        'payload' => $ajaxPayload,
    ]);
    exit;
}

$openModal = ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '');

$countResult   = $conn->query('SELECT COUNT(*) as total FROM barangays');
$totalBarangays = $countResult ? $countResult->fetch_assoc()['total'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangays — Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Lora:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/barangay.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main class="main-content">

        <!-- ── Page Header ── -->
        <div class="page-header">
            <div class="page-header-left">
                <h1>
                    <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:#0d9488;flex-shrink:0"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    Barangays
                </h1>
                <p>Manage all registered barangays and their demographic data.</p>
                <div class="header-stats">
                    <span class="stat-chip">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                        <strong id="barangayTotalCount"><?= $totalBarangays ?></strong> Barangay<?= $totalBarangays !== 1 ? 's' : '' ?> Registered
                    </span>
                </div>
            </div>
            <button class="btn-add" type="button" onclick="openBarangayModal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.8" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Barangay
            </button>
        </div>


        <!-- ── Alerts ── -->
        <div id="pageAlert">
            <?php if ($successMessage): ?>
                <div class="alert alert-success">
                    <span class="alert-icon">✅</span>
                    <?= htmlspecialchars($successMessage) ?>
                </div>
            <?php endif; ?>
            <?php if ($errorMessage && !$openModal): ?>
                <div class="alert alert-error">
                    <span class="alert-icon">⚠️</span>
                    <?= htmlspecialchars($errorMessage) ?>
                </div>
            <?php endif; ?>
        </div>

        <div id="toastContainer"
             data-success="<?= htmlspecialchars($successMessage, ENT_QUOTES) ?>"
             data-error="<?= htmlspecialchars($errorMessage, ENT_QUOTES) ?>"></div>

        <!-- ── Table Card ── -->
        <div class="table-card">
            <div class="table-card-header">
                <div class="table-card-header-left">
                    <span class="table-card-title">Directory</span>
                </div>
                <div class="table-header-controls">
                    <div class="search-wrap">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="searchInput" placeholder="Search name, city, PSGC…" oninput="filterTable()">
                    </div>
                </div>
            </div>

            <div class="table-scroll">
                <table id="barangayTable">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Barangay Name</th>
                        <th>City / Municipality</th>
                        <th>Province</th>
                        <th>Population</th>
                        <th>Est. Children</th>
                        <th>PSGC</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $result = $conn->query('
                        SELECT b.barangay_id, b.barangay_name, b.city, b.province,
                               b.total_population, b.estimated_children_measured, b.psgc,
                               (SELECT COUNT(*) FROM children c WHERE c.barangay_id = b.barangay_id) AS child_count
                        FROM barangays b
                        ORDER BY b.barangay_name ASC
                    ');
                    if ($result && $result->num_rows > 0):
                        $i = 1;
                        while ($row = $result->fetch_assoc()): ?>
                            <tr data-barangay-id="<?= (int)$row['barangay_id'] ?>">
                                <td><span class="row-num"><?= $i++ ?></span></td>
                                <td style="font-weight:600;" data-col="name">
                                    <span class="badge"><?= htmlspecialchars($row['barangay_name']) ?></span>
                                </td>
                                <td data-col="city"><?= htmlspecialchars($row['city']) ?></td>
                                <td style="color:var(--text-muted);" data-col="province">
                                    <?= htmlspecialchars($row['province']) ?>
                                </td>
                                <td data-col="population"><span class="num-cell"><?= format_optional_number($row['total_population']) ?></span></td>
                                <td data-col="estimated"><span class="num-cell"><?= format_optional_number($row['estimated_children_measured']) ?></span></td>
                                <td data-col="psgc"><span class="psgc-cell"><?= format_optional_text($row['psgc']) ?></span></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="#"
                                           class="btn-edit"
                                           onclick="return startEditBarangay(event, this)"
                                           data-id="<?= $row['barangay_id'] ?>"
                                           data-name="<?= htmlspecialchars($row['barangay_name']) ?>"
                                           data-city="<?= htmlspecialchars($row['city']) ?>"
                                           data-province="<?= htmlspecialchars($row['province']) ?>"
                                           data-total="<?= htmlspecialchars($row['total_population']) ?>"
                                           data-estimated="<?= htmlspecialchars($row['estimated_children_measured']) ?>"
                                           data-psgc="<?= htmlspecialchars($row['psgc']) ?>">
                                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25z"/><path d="M14.06 6.19l1.77-1.77a1.5 1.5 0 1 1 2.12 2.12l-1.77 1.77"/></svg>
                                            Edit
                                        </a>
                                        <a href="?delete_id=<?= $row['barangay_id'] ?>"
                                           class="btn-delete<?= ($row['child_count'] ?? 0) > 0 ? ' disabled' : '' ?>"
                                           <?= ($row['child_count'] ?? 0) > 0 ? 'aria-disabled="true" title="Cannot delete: child records exist"' : '' ?>
                                           onclick="return confirmDelete(event, this)">
                                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                            Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile;
                    else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <span class="empty-icon">🏘️</span>
                                    <p>No barangays registered yet. Add your first one!</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="no-results" id="noResults">
                <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 10px;display:block;opacity:.4"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                No results match your search.
            </div>
        </div><!-- /table-card -->


        <!-- ── Delete Confirmation Modal ── -->
        <div id="delete-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="delete-title">
            <div class="modal confirm-modal">
                <div class="confirm-header">
                    <div class="confirm-icon">🗑️</div>
                    <div class="confirm-text">
                        <h2 id="delete-title">Delete Barangay?</h2>
                        <p class="confirm-subtitle">This action cannot be undone.</p>
                    </div>
                    <button type="button" class="modal-close" onclick="closeDeleteModal()" aria-label="Close">✕</button>
                </div>
                <div class="modal-body">
                    <p class="modal-subtitle">You are about to permanently remove <strong id="delete-name"></strong> from the system.</p>
                    <div class="modal-warning">⚠️ &nbsp;All associated data will be permanently lost.</div>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" onclick="closeDeleteModal()">
                            Cancel
                        </button>
                        <button type="button" id="confirm-delete-btn" class="btn-danger">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                            Yes, Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>


        <!-- ── Add / Edit Barangay Modal ── -->
        <div id="barangay-modal" class="modal-overlay<?= $openModal ? ' active' : '' ?>" role="dialog" aria-modal="true" aria-labelledby="modal-title">
            <div class="modal">
                <div class="modal-header">
                    <h2 id="modal-title">Add Barangay</h2>
                    <button type="button" class="modal-close" onclick="closeBarangayModal()" aria-label="Close">✕</button>
                </div>
                <div class="modal-body">
                    <p class="modal-subtitle" id="modal-subtitle">Fill in the details below to register a new barangay.</p>
                    <div class="modal-divider"></div>

                    <form method="post" action="">
                        <input type="hidden" name="barangay_id" id="barangay_id" value="<?= htmlspecialchars($formData['barangay_id']) ?>">

                        <div class="form-group" id="group-barangay_name">
                            <label>Barangay Name <span>*</span></label>
                            <input type="text" name="barangay_name" placeholder="e.g. San Isidro" value="<?= htmlspecialchars($formData['barangay_name']) ?>" required autocomplete="off">
                            <div class="field-error" id="error-barangay_name"></div>
                        </div>

                        <div class="form-row">
                            <div class="form-group" id="group-city">
                                <label>City / Municipality <span>*</span></label>
                                <input type="text" name="city" placeholder="e.g. Bislig City" value="<?= htmlspecialchars($formData['city']) ?>" required autocomplete="off">
                                <div class="field-error" id="error-city"></div>
                            </div>
                            <div class="form-group" id="group-province">
                                <label>Province <span>*</span></label>
                                <input type="text" name="province" placeholder="e.g. Surigao Del Sur" value="<?= htmlspecialchars($formData['province']) ?>" required autocomplete="off">
                                <div class="field-error" id="error-province"></div>
                            </div>
                        </div>

                        <div class="form-section-label">Demographic Data</div>

                        <div class="form-row">
                            <div class="form-group" id="group-total_population">
                                <label>Total Population <span>*</span></label>
                                <input type="number" name="total_population" min="0" step="1" placeholder="e.g. 1200" value="<?= htmlspecialchars($formData['total_population']) ?>" required>
                                <div class="field-error" id="error-total_population"></div>
                            </div>
                            <div class="form-group" id="group-estimated_children_measured">
                                <label>Est. Children Measured <span>*</span></label>
                                <input type="number" name="estimated_children_measured" min="0" step="1" placeholder="e.g. 350" value="<?= htmlspecialchars($formData['estimated_children_measured']) ?>" required>
                                <div class="field-error" id="error-estimated_children_measured"></div>
                            </div>
                        </div>

                        <div class="form-group" id="group-psgc">
                            <label>PSGC Code <span>*</span></label>
                            <input type="text" name="psgc" maxlength="10" inputmode="numeric" pattern="[0-9]{1,10}" placeholder="e.g. 1600200002" value="<?= htmlspecialchars($formData['psgc']) ?>" required autocomplete="off">
                            <p class="form-hint">Philippine Standard Geographic Code — 1 to 10 digits.</p>
                            <div class="field-error" id="error-psgc"></div>
                        </div>

                        <button type="submit" class="btn-submit">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            Save Barangay
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </main>

    <script src="javascript/barangay.js"></script>
</body>
</html>
