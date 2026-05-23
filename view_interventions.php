<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/growth_utils.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$errors = [];

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

$current_role = $_SESSION['role'] ?? '';
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_bns = ($current_role === 'Barangay Nutrition Scholars');
$is_hw = ($current_role === 'Health Worker');
$is_staff = ($current_role === 'Staff');
$assigned_barangay_id = isset($_SESSION['barangay_id']) ? (int)$_SESSION['barangay_id'] : 0;

if (isset($_GET['action']) && $_GET['action'] === 'child_history') {
    header('Content-Type: application/json');

    $typeIdParam = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
    $childIdParam = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;

    if ($typeIdParam <= 0 || $childIdParam <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $stmtHistory = $conn->prepare("SELECT i.intervention_id, i.intervention_date, i.description,
           (SELECT GROUP_CONCAT(inv.item_name SEPARATOR '||') FROM intervention_items ii JOIN inventory inv ON ii.inventory_id = inv.inventory_id WHERE ii.intervention_id = i.intervention_id) as given_items,
           (SELECT GROUP_CONCAT(ii.quantity_given SEPARATOR '||') FROM intervention_items ii JOIN inventory inv ON ii.inventory_id = inv.inventory_id WHERE ii.intervention_id = i.intervention_id) as given_qtys
        FROM interventions i WHERE i.type_id = ? AND i.child_id = ? ORDER BY i.intervention_date DESC, i.intervention_id DESC");
    if (!$stmtHistory) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
        exit;
    }

    $stmtHistory->bind_param('ii', $typeIdParam, $childIdParam);
    $stmtHistory->execute();
    $result = $stmtHistory->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'intervention_date' => (string)($row['intervention_date'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'given_items' => (string)($row['given_items'] ?? ''),
            'given_qtys' => (string)($row['given_qtys'] ?? ''),
        ];
    }
    $stmtHistory->close();

    echo json_encode(['success' => true, 'data' => $items]);
    exit;
}

function compute_age_in_months(?string $birthdate, ?string $measurementDate): ?int
{
    if (!$birthdate || !$measurementDate) {
        return null;
    }

    try {
        $birth = new DateTime($birthdate);
        $measure = new DateTime($measurementDate);
    } catch (Exception $e) {
        return null;
    }

    if ($measure < $birth) {
        return null;
    }

    $diff = $birth->diff($measure);
    return ($diff->y * 12) + $diff->m;
}

function computeStatuses(mysqli $conn, string $sex, int $ageMonths, float $height, float $weight): array
{
    $normalizedSex = ucfirst(strtolower($sex));
    $weightOutOfRange = false;
    $heightOutOfRange = false;
    $wflOutOfRange = false;

    $weightRef = fetchClosestGrowthReference($conn, GROWTH_WEIGHT_TABLE, $ageMonths, $normalizedSex, $weightOutOfRange);
    $heightRef = fetchClosestGrowthReference($conn, GROWTH_HEIGHT_TABLE, $ageMonths, $normalizedSex, $heightOutOfRange);
    $wflAgeGroup = resolveWeightForLengthAgeGroup($ageMonths);
    $wflRef = null;
    if ($wflAgeGroup === null) {
        $wflOutOfRange = true;
    } else {
        $wflRef = fetchWeightForLengthReferenceByAgeGroup($conn, $normalizedSex, $wflAgeGroup, $height, $wflOutOfRange);
    }

    $weightStatus = 'N/A';
    $heightStatus = 'N/A';
    $wflStatus = 'N/A';

    if (!$weightOutOfRange && $weightRef) {
        $weightStatus = determineWeightForAgeStatus($weight, $weightRef) ?? 'N/A';
    } elseif ($weightOutOfRange) {
        $weightStatus = 'Out of Range';
    }

    if (!$heightOutOfRange && $heightRef) {
        $heightStatus = determineHeightForAgeStatus($height, $heightRef) ?? 'N/A';
    } elseif ($heightOutOfRange) {
        $heightStatus = 'Out of Range';
    }

    if (!$wflOutOfRange && $wflRef) {
        $wflStatus = determineWeightForLengthStatus($weight, $wflRef) ?? 'N/A';
    } elseif ($wflOutOfRange) {
        $wflStatus = 'Out of Range';
    }

    return [
        'weight_for_age_status' => $weightStatus,
        'height_for_age_status' => $heightStatus,
        'weight_for_ltht_status' => $wflStatus,
    ];
}

function status_cell_class(?string $status): string
{
    $abbr = strtolower(status_abbrev($status));

    if ($abbr === 'n/a') {
        return 'status-na';
    }
    if ($abbr === 'oor') {
        return 'status-oor';
    }
    if (in_array($abbr, ['suw', 'sst', 'sw'], true)) {
        return 'status-severe';
    }
    if (in_array($abbr, ['uw', 'st', 'w', 'mw'], true)) {
        return 'status-moderate';
    }
    if (in_array($abbr, ['ow', 'ob'], true)) {
        return 'status-over';
    }
    if (in_array($abbr, ['n', 't'], true)) {
        return 'status-normal';
    }
    return 'status-na';
}

function status_abbrev(?string $status): string
{
    $value = strtolower(trim((string)$status));
    if ($value === '' || $value === '—' || $value === 'n/a') {
        return 'N/A';
    }
    if ($value === 'out of range') {
        return 'OOR';
    }

    $map = [
        'severely underweight' => 'SUW',
        'underweight' => 'UW',
        'normal' => 'N',
        'severely stunted' => 'SSt',
        'stunted' => 'St',
        'tall' => 'T',
        'severely wasted' => 'SW',
        'moderately wasted' => 'MW',
        'wasted' => 'W',
        'overweight' => 'OW',
        'obese' => 'Ob',
    ];

    if (isset($map[$value])) {
        return $map[$value];
    }

    foreach ($map as $key => $abbr) {
        if (strpos($value, $key) !== false) {
            return $abbr;
        }
    }

    return strtoupper((string)$status);
}

$encodedKey = $_GET['k'] ?? '';
$key = base64_decode((string)$encodedKey, true);

if (!$key) {
    header('Location: interventions.php');
    exit;
}

$parts = explode('::', $key, 3);
if (count($parts) < 3) {
    header('Location: interventions.php');
    exit;
}

$typeId = (int)$parts[0];
$description = $parts[1];
$interventionDate = $parts[2];

$typeName = '—';
$stmt = $conn->prepare('SELECT type_name FROM intervention_types WHERE type_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $typeId);
    $stmt->execute();
    $stmt->bind_result($typeNameResult);
    if ($stmt->fetch()) {
        $typeName = $typeNameResult ?: '—';
    }
    $stmt->close();
}

$isGiveOut = in_array(strtolower(trim($typeName)), ['give out', 'giveout'], true);


$children = [];
$sql = "SELECT c.child_id, c.first_name, c.middle_name, c.last_name, c.suffix, c.address, c.birthdate, c.sex,
           b.barangay_name, i.description, i.intervention_date, t.type_name, i.intervention_id,
           gr.measurement_date, gr.weight, gr.height,
           (SELECT GROUP_CONCAT(inv.item_name SEPARATOR '||') FROM intervention_items ii JOIN inventory inv ON ii.inventory_id = inv.inventory_id WHERE ii.intervention_id = i.intervention_id) as given_items,
           (SELECT GROUP_CONCAT(ii.quantity_given SEPARATOR '||') FROM intervention_items ii JOIN inventory inv ON ii.inventory_id = inv.inventory_id WHERE ii.intervention_id = i.intervention_id) as given_qtys
        FROM interventions i
        INNER JOIN (
            SELECT child_id, MAX(intervention_id) AS max_id
            FROM interventions
            WHERE type_id = ?
            GROUP BY child_id
        ) latest ON latest.child_id = i.child_id AND latest.max_id = i.intervention_id
        INNER JOIN children c ON c.child_id = i.child_id AND c.status = 'Active'
        LEFT JOIN barangays b ON b.barangay_id = c.barangay_id
        LEFT JOIN intervention_types t ON t.type_id = i.type_id
        LEFT JOIN growth_records gr ON gr.record_id = (
            SELECT gr2.record_id
            FROM growth_records gr2
            WHERE gr2.child_id = c.child_id
              AND gr2.measurement_date <= i.intervention_date
              AND gr2.weight > 0
              AND gr2.height > 0
            ORDER BY gr2.measurement_date DESC, gr2.record_id DESC
            LIMIT 1
        )
        WHERE i.type_id = ?";

// Restrict HW and Staff to their own barangay; BNS to their barangay AND their currently assigned children
if (($is_hw || $is_bns || $is_staff) && $assigned_barangay_id > 0) {
    $sql .= " AND c.barangay_id = ?";
    if ($is_bns && $current_user_id > 0) {
        $sql .= " AND (
            (
                SELECT grb.recorded_by FROM growth_records grb
                JOIN users u ON grb.recorded_by = u.user_id
                WHERE grb.child_id = c.child_id AND u.role = 'Barangay Nutrition Scholars'
                ORDER BY grb.measurement_date DESC, grb.record_id DESC
                LIMIT 1
            ) IS NULL OR (
                SELECT grb.recorded_by FROM growth_records grb
                JOIN users u ON grb.recorded_by = u.user_id
                WHERE grb.child_id = c.child_id AND u.role = 'Barangay Nutrition Scholars'
                ORDER BY grb.measurement_date DESC, grb.record_id DESC
                LIMIT 1
            ) = ?
        )";
    }
}
$sql .= " ORDER BY c.first_name ASC, c.last_name ASC, c.child_id ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (($is_hw || $is_bns || $is_staff) && $assigned_barangay_id > 0) {
        if ($is_bns && $current_user_id > 0) {
            // typeId, typeId, barangayId, userId
            $stmt->bind_param('iiii', $typeId, $typeId, $assigned_barangay_id, $current_user_id);
        } else {
            // typeId, typeId, barangayId
            $stmt->bind_param('iii', $typeId, $typeId, $assigned_barangay_id);
        }
    } else {
        $stmt->bind_param('ii', $typeId, $typeId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        if (!empty($r['suffix'])) $name .= ' ' . $r['suffix'];
        $location = trim((string)($r['address'] ?? ''));
        if (!empty($r['barangay_name'])) {
            $location = $location !== '' ? $location . ' — ' . $r['barangay_name'] : $r['barangay_name'];
        }
        $ageMonths = compute_age_in_months($r['birthdate'] ?? null, $r['measurement_date'] ?? null);
        $statuses = [
            'weight_for_age_status' => 'N/A',
            'height_for_age_status' => 'N/A',
            'weight_for_ltht_status' => 'N/A',
        ];
        if ($ageMonths !== null && $r['weight'] !== null && $r['height'] !== null) {
            $statuses = computeStatuses(
                $conn,
                (string)($r['sex'] ?? ''),
                $ageMonths,
                (float)$r['height'],
                (float)$r['weight']
            );
        }
        // Normalize sex to single-letter: male -> 'm', female -> 'f'
        $sexVal = 'N/A';
        if (!empty($r['sex'])) {
            $sRaw = strtolower(trim((string)$r['sex']));
            if ($sRaw === 'male' || $sRaw === 'M') $sexVal = 'M';
            elseif ($sRaw === 'female' || $sRaw === 'F') $sexVal = 'F';
            else $sexVal = substr($sRaw, 0, 1);
        }

        $children[] = [
            'child_id' => (int)($r['child_id'] ?? 0),
            'name' => $name !== '' ? $name : '—',
            'address' => $r['address'] ?? 'N/A',
            'barangay' => $r['barangay_name'] ?? 'N/A',
            'sex' => $sexVal,
            'date' => $r['intervention_date'] ?? '—',
            'description' => $r['description'] ?? '',
            'weight' => $r['weight'] !== null ? number_format((float)$r['weight'], 1) : 'N/A',
            'height' => $r['height'] !== null ? number_format((float)$r['height'], 1) : 'N/A',
            'age_in_months' => $ageMonths !== null ? (string)$ageMonths : 'N/A',
            'weight_for_age_status' => $statuses['weight_for_age_status'] ?? 'N/A',
            'height_for_age_status' => $statuses['height_for_age_status'] ?? 'N/A',
            'weight_for_ltht_status' => $statuses['weight_for_ltht_status'] ?? 'N/A',
            'given_items' => $r['given_items'] ?? '',
            'given_qtys' => $r['given_qtys'] ?? '',
            'measurement_date' => $r['measurement_date'] ?? '—',
        ];
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'edit_intervention_view')) {
    $newDescription = trim((string)($_POST['description'] ?? ''));
    $newDate = trim((string)($_POST['intervention_date'] ?? ''));
    $selectedChildIds = isset($_POST['child_ids']) && is_array($_POST['child_ids'])
        ? array_values(array_unique(array_filter(array_map('intval', $_POST['child_ids']))))
        : [];

    $allowedChildIds = array_map('intval', array_column($children, 'child_id'));
    $invalidSelected = array_values(array_diff($selectedChildIds, $allowedChildIds));

    if (empty($selectedChildIds)) {
        $errors[] = 'Select at least one child.';
    }
    if (!empty($invalidSelected)) {
        $errors[] = 'You can only edit children already included in this intervention.';
    }

    $dateValid = false;
    if ($newDate !== '') {
        $d = DateTime::createFromFormat('Y-m-d\TH:i', $newDate);
        $dateValid = $d && $d->format('Y-m-d\TH:i') === $newDate;
    }
    if (!$dateValid) {
        $errors[] = 'Please provide a valid intervention date.';
    }

    if (empty($errors)) {
        $stmtLatest = $conn->prepare('SELECT intervention_id FROM interventions WHERE type_id = ? AND child_id = ? ORDER BY intervention_id DESC LIMIT 1');
        $stmtUpdate = $conn->prepare('UPDATE interventions SET description = ?, intervention_date = ? WHERE intervention_id = ?');

        if (!$stmtLatest || !$stmtUpdate) {
            if ($stmtLatest) $stmtLatest->close();
            if ($stmtUpdate) $stmtUpdate->close();
            $errors[] = 'Database error while updating intervention.';
        } else {
            $updatedCount = 0;

            $conn->begin_transaction();
            try {
                foreach ($selectedChildIds as $childId) {
                    $interventionId = 0;
                    $stmtLatest->bind_param('ii', $typeId, $childId);
                    $stmtLatest->execute();
                    $stmtLatest->bind_result($interventionId);
                    $stmtLatest->fetch();
                    $stmtLatest->free_result();

                    if ((int)$interventionId <= 0) {
                        continue;
                    }

                    $stmtUpdate->bind_param('ssi', $newDescription, $newDate, $interventionId);
                                        $stmtUpdate->bind_param('ssi', $newDescription, $newDate, $interventionId);
                    if (!$stmtUpdate->execute()) {
                        throw new Exception('Failed to update intervention rows.');
                    }
                    $updatedCount++;
                }

                if ($updatedCount <= 0) {
                    throw new Exception('No interventions were saved.');
                }

                $conn->commit();
                $stmtLatest->close();
                $stmtUpdate->close();

                $newKey = base64_encode($typeId . '::' . $newDescription . '::' . $newDate);
                set_flash('success', 'Intervention updated for ' . $updatedCount . ' child(ren).');
                header('Location: view_interventions.php?k=' . urlencode($newKey));
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $stmtLatest->close();
                $stmtUpdate->close();
                $errors[] = $e->getMessage();
            }
        }
    }
}

$childCount = count($children);

// Build year options
$yearOptions = [];
foreach ($children as $child) {
    $dateVal = $child['date'] ?? '';
    if (!$dateVal || $dateVal === '—' || strlen($dateVal) < 4) continue;
    $yr = substr($dateVal, 0, 4);
    $yearOptions[$yr] = true;
}
$yearOptions = array_keys($yearOptions);
rsort($yearOptions);

// Build barangay options
$barangayOptions = [];
foreach ($children as $child) {
    $br = trim((string)($child['barangay'] ?? ''));
    if ($br !== '' && $br !== 'N/A') {
        $barangayOptions[$br] = true;
    }
}
$barangayOptions = array_keys($barangayOptions);
sort($barangayOptions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Intervention</title>
    <link rel="stylesheet" href="css/tailwind.css">
    <link rel="stylesheet" href="css/growth-status.css">
    <style>
        * { font-family: Arial, Helvetica, sans-serif; }
        body { background-color: #f1f5f9; color: #0f172a; font-size: 14px; }
        .page-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; }
        .page-header { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .btn-back { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:10px; border:1.5px solid #cbd5e1; color:#334155; background:#fff; text-decoration:none; font-weight:700; font-size:12px; }
        .btn-back:hover { background:#f8fafc; }
        .view-detail-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; margin-bottom:4px; }
        .view-detail-value { color:#1e293b; font-weight:500; }
        .view-chip { display:inline-flex; align-items:center; background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0; border-radius:20px; padding:3px 11px; font-size:12px; font-weight:600; }
        .table-wrap { overflow:hidden; border:1px solid #cbd5e1; border-radius:12px; background:#fff; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .view-table { width:100%; border-collapse:separate; border-spacing:0; font-size:11px; table-layout:fixed; }
        .view-table thead th { position:sticky; top:0; background:#0f172a; color:#fff; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; text-align:center; padding:10px 4px; border-bottom:1px solid #cbd5e1; white-space:normal; line-height:1.2; vertical-align:middle; z-index:10; word-break:break-word; }
        .view-table thead th { border-right:1px solid rgba(255,255,255,.2); }
        .view-table thead th:last-child { border-right:none; }
        .view-table tbody td { padding:8px 4px; border-bottom:1px solid #cbd5e1; border-right:1px solid #cbd5e1; color:#334155; vertical-align:middle; transition: background-color 0.2s; word-break:break-word; }
        .view-table tbody td:last-child { border-right:none; }
        .view-table tbody tr:last-child td { border-bottom:none; }
        .child-profile-row {
            cursor: pointer;
            transition: background .14s ease, box-shadow .14s ease;
        }
        .child-profile-row:hover {
            background:#e0f2fe;
            box-shadow: inset 3px 0 0 #0284c7;
        }
        .name-cell { font-weight:600; color:#0f172a; text-align:left; }
        .location-cell { line-height:1.45; color:#475569; text-align:left; }
        .two-row-text {
            display:-webkit-box;
            line-clamp:2;
            -webkit-line-clamp:2;
            -webkit-box-orient:vertical;
            overflow:hidden;
            line-height:1.35;
            max-height:2.7em;
            word-break:break-word;
        }
        .sex-cell,
        .metric-cell { text-align:center; }
        .metric-cell { white-space:nowrap; text-align:center; }
        .status-cell { text-align:center; }
        .status-cell { text-align:center; font-weight:700; font-size: 10px; }
        .btn-view {
            display:inline-flex; align-items:center; gap:3px;
            padding:6px 10px; font-size:11px; font-weight:700;
            border-radius:6px; border:1px solid #059669 !important;
            background:#059669 !important; color:#fff !important; text-decoration:none;
            white-space:nowrap;
            z-index: 5;
            min-width: 60px;
        }
        .btn-view:hover { background:#047857 !important; }
        .btn-edit {
            display:inline-flex; align-items:center; gap:4px;
            padding:6px 10px; font-size:11px; font-weight:700;
            border-radius:8px; border:1px solid #2563eb;
            background:#2563eb; color:#fff;
        }
        .btn-edit:hover { background:#1d4ed8; }
        .btn-edit-row {
            display:inline-flex; align-items:center; gap:5px;
            padding:5px 10px; font-size:11px; font-weight:700;
            border-radius:8px; border:1.5px solid #2563eb;
            background:#2563eb; color:#fff; text-decoration:none;
        }
        .btn-edit-row:hover { background:#1d4ed8; border-color:#1d4ed8; }
        .banner-success {
            display:flex; align-items:center; gap:8px;
            padding:10px 12px; border-radius:10px; margin-bottom:14px;
            border:1px solid #bbf7d0; background:#f0fdf4; color:#166534; font-size:12px; font-weight:600;
        }
        .banner-error {
            display:flex; align-items:flex-start; gap:8px;
            padding:10px 12px; border-radius:10px; margin-bottom:14px;
            border:1px solid #fecdd3; background:#fff1f2; color:#be123c; font-size:12px; font-weight:600;
        }
        .modal-overlay {
            position:fixed; inset:0; background:rgba(15,23,42,.58);
            display:none; align-items:center; justify-content:center; z-index:9999; padding:14px;
        }
        .modal-overlay.is-open { display:flex; }
        .modal-card {
            width:min(960px, 96vw); max-height:92vh; overflow:auto;
            background:#fff; border-radius:14px; border:1px solid #e2e8f0;
            box-shadow:0 30px 70px rgba(2,6,23,.22);
        }
        .modal-head { padding:14px 16px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
        .modal-title { font-size:16px; font-weight:700; color:#0f172a; }
        .modal-body { padding:14px 16px; display:flex; flex-direction:column; gap:12px; }
        .field-grid { display:grid; grid-template-columns:1fr 180px; gap:10px; }
        .field-label { font-size:11px; font-weight:700; color:#64748b; margin-bottom:6px; text-transform:uppercase; letter-spacing:.05em; }

        .field-input, .field-textarea, #filterSearch {
            width:100%; border:1px solid #cbd5e1; border-radius:8px; background:#fff;
            padding:8px 10px; font-size:12px; color:#000000 !important; font-weight:500;
        }
        .field-textarea { min-height:80px; resize:vertical; }
        .child-grid { display:grid; gap:6px; max-height:280px; overflow:auto; border:1px solid #e2e8f0; border-radius:10px; padding:8px; }
        .child-pill { display:flex; align-items:flex-start; gap:8px; padding:8px; border:1px solid #f1f5f9; border-radius:8px; }
        .child-pill-name { font-size:12px; font-weight:600; color:#0f172a; }
        .child-pill-sub { font-size:11px; color:#64748b; }
        .modal-table-wrap { border:1px solid #e2e8f0; border-radius:10px; overflow:auto; max-height:280px; }
        .modal-table { width:100%; border-collapse:collapse; font-size:12px; border:1px solid #e2e8f0; }
        .modal-table thead th { position:sticky; top:0; background:#0f172a; color:#fff; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; text-align:center; padding:10px 12px; border-bottom:1px solid #cbd5e1; }
        .modal-table thead th { border-right:1px solid rgba(255,255,255,.2); }
        .modal-table tbody td { padding:8px 10px; border-bottom:1px solid #cbd5e1; border-right:1px solid #cbd5e1; color:#0f172a; vertical-align:top; }
        .modal-table tbody tr:last-child td { border-bottom:none; }
        .modal-table .cell-left { text-align:left; }
        .modal-table .cell-center { text-align:center; }
        .history-table-wrap { border:1px solid #cbd5e1; border-radius:10px; overflow:auto; max-height:360px; }
        .history-table { width:100%; border-collapse:collapse; font-size:12px; border:1px solid #cbd5e1; table-layout:fixed; word-break:break-word; }
        .history-table thead th { position:sticky; top:0; background:#0f172a; color:#fff; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; text-align:center; padding:10px 12px; border-bottom:1px solid #cbd5e1; }
        .history-table thead th { border-right:1px solid rgba(255,255,255,.2); }
        .history-table tbody td { padding:8px 10px; border-bottom:1px solid #cbd5e1; border-right:1px solid #cbd5e1; color:#0f172a; vertical-align:top; }
        .history-table tbody tr:last-child td { border-bottom:none; }
        .history-table .cell-left { text-align:left; }
        .history-table .cell-center { text-align:center; }
        .modal-actions { padding:12px 16px; border-top:1px solid #f1f5f9; display:flex; justify-content:flex-end; gap:8px; }
        .btn-secondary { border:1px solid #cbd5e1; background:#fff; color:#334155; border-radius:8px; font-size:12px; font-weight:700; padding:8px 12px; }
        .btn-primary { border:1px solid #2563eb; background:#2563eb; color:#fff; border-radius:8px; font-size:12px; font-weight:700; padding:8px 12px; }
        .empty-state { text-align:center; padding:32px 20px; color:#94a3b8; }
        .history-card {
            border:1px solid #e2e8f0;
            border-radius:10px;
            padding:10px 12px;
            background:#fff;
        }
        .history-date { font-size:12px; color:#64748b; }
        .history-desc { font-size:13px; color:#0f172a; margin-top:4px; }

        @media (max-width: 760px) {
            .field-grid { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<main class="main-content min-h-screen px-4 md:px-8 py-7 pb-16" style="display:flex;flex-direction:column;gap:20px;">
    <div class="page-header">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <div style="width:48px;height:48px;border-radius:12px;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:22px;">📋</div>
            <div>
                <h1 style="font-size:18px;font-weight:700;color:#0f172a;margin:0;">Intervention Details</h1>
                <div style="margin-top: 6px;">
                    <span style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Intervention Name:</span>
                    <span style="display:inline-flex; align-items:center; background:#eff6ff; color:#2563eb; border:1.5px solid #bfdbfe; border-radius:20px; padding:4px 12px; font-size:0.85rem; font-weight:600; margin-left:4px;"><?= htmlspecialchars($typeName) ?></span>
                </div>
            </div>
        </div>
        <a class="btn-back" href="interventions.php">← Back to Interventions</a>
    </div>

    <div class="page-card">
        <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;flex-direction:column;gap:14px;">

            <!-- Count + Search row -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div style="width:38px;height:38px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:18px;">👶</div>
                    <div>
                        <div class="view-detail-label" style="margin-bottom:1px;">Total Children</div>
                        <div style="font-size:20px;font-weight:800;color:#0f172a;line-height:1;"><?= $childCount ?></div>
                    </div>
                </div>
                <!-- Search bar -->
                <div style="position:relative;flex:1;min-width:220px;max-width:360px;">
                    <svg style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;" width="15" height="15" fill="none" stroke="#94a3b8" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input id="filterSearch" type="text" placeholder="Search by name, address…" style="width:100%;padding:8px 10px 8px 32px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:12px;color:#0f172a;background:#f8fafc;outline:none;transition:border .2s;" onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#e2e8f0'">
                </div>
            </div>

            <!-- Filters row -->
            <div style="background-color: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 16px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                    <svg width="13" height="13" fill="none" stroke="#64748b" stroke-width="2" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                    <span class="view-detail-label" style="margin-bottom:0;">Filters</span>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">

                    <!-- Year -->
                    <div>
                        <div class="view-detail-label" style="margin-bottom:4px;">Year</div>
                        <select id="filterYear" class="field-input" style="min-width:100px;">
                            <option value="">All Years</option>
                            <?php foreach ($yearOptions as $yr): ?>
                                <option value="<?= htmlspecialchars($yr) ?>"><?= htmlspecialchars($yr) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Month -->
                    <div>
                        <div class="view-detail-label" style="margin-bottom:4px;">Month</div>
                        <select id="filterMonth" class="field-input" style="min-width:130px;">
                            <option value="">All Months</option>
                            <option value="01">January</option>
                            <option value="02">February</option>
                            <option value="03">March</option>
                            <option value="04">April</option>
                            <option value="05">May</option>
                            <option value="06">June</option>
                            <option value="07">July</option>
                            <option value="08">August</option>
                            <option value="09">September</option>
                            <option value="10">October</option>
                            <option value="11">November</option>
                            <option value="12">December</option>
                        </select>
                    </div>

                    <!-- Barangay -->
                    <div>
                        <div class="view-detail-label" style="margin-bottom:4px;">Barangay</div>
                        <select id="filterBarangay" class="field-input" style="min-width:160px;">
                            <option value="">All Barangays</option>
                            <?php foreach ($barangayOptions as $br): ?>
                                <option value="<?= htmlspecialchars($br) ?>"><?= htmlspecialchars($br) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Sex -->
                    <div>
                        <div class="view-detail-label" style="margin-bottom:4px;">Sex</div>
                        <select id="filterSex" class="field-input" style="min-width:110px;">
                            <option value="">All Sex (Boys & Girls)</option>
                            <option value="Male">Male (Boys)</option>
                            <option value="Female">Female (Girls)</option>
                        </select>
                    </div>

                    <!-- Age (months) -->
                    <div>
                        <div class="view-detail-label" style="margin-bottom:4px;">Age (months)</div>
                        <input id="filterAge" class="field-input" type="number" min="0" placeholder="Any age" style="width:110px;">
                    </div>

                    <!-- Reset button -->
                    <div>
                        <button type="button" id="btnResetFilters" class="h-9 inline-flex items-center justify-center gap-1.5 rounded-md border border-rose-200 bg-rose-50 px-4 text-[0.8rem] font-semibold text-rose-600 shadow-sm hover:bg-rose-100 transition-colors" style="cursor:pointer; font-family:inherit;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                            Reset
                        </button>
                    </div>

                </div>
            </div>
        </div>
        <div style="padding:20px;display:flex;flex-direction:column;gap:18px;">
            <?php if ($flash && !empty($flash['message'])): ?>
                <div class="<?= ($flash['type'] ?? '') === 'success' ? 'banner-success' : 'banner-error' ?>">
                    <?= htmlspecialchars((string)$flash['message']) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="banner-error">
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?= htmlspecialchars($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;">
                    <div class="view-detail-label" style="margin-bottom:0;">Children Enrolled</div>
                    <button type="button" class="btn-edit inline-flex items-center gap-1.5" id="btnOpenEditModal">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25z"/><path d="M14.06 6.19l1.77-1.77a1.5 1.5 0 1 1 2.12 2.12l-1.77 1.77"/></svg>
                        Edit Intervention
                    </button>
                </div>
                <div class="table-wrap">
                    <table class="view-table">
                        <thead>
                            <tr>
                                <?php if ($isGiveOut): ?>
                                <th style="width:8%;">Address</th>
                                <th style="width:8%;">Barangay</th>
                                <th style="width:10%;">Full Name</th>
                                <th style="width:3%;">Sex</th>
                                <th style="width:3%;">Age</th>
                                <th style="width:8%;">Measurement Date</th>
                                <th style="width:6%;">Height<br>for Age<br>Status</th>
                                <th style="width:6%;">Weight<br>for Age<br>Status</th>
                                <th style="width:6%;">Weight<br>for L/HT<br>Status</th>
                                <th style="width:6%;">Date</th>
                                <th style="width:11%;">What Given</th>
                                <th style="width:4%;">Qty</th>
                                <th style="width:11%;">Notes</th>
                                <th style="width:10%;">Action</th>
                                <?php else: ?>
                                <th style="width:10%;">Address</th>
                                <th style="width:10%;">Barangay</th>
                                <th style="width:12%;">Full Name</th>
                                <th style="width:4%;">Sex</th>
                                <th style="width:4%;">Age</th>
                                <th style="width:10%;">Measurement Date</th>
                                <th style="width:7%;">Height<br>for Age<br>Status</th>
                                <th style="width:7%;">Weight<br>for Age<br>Status</th>
                                <th style="width:7%;">Weight<br>for L/HT<br>Status</th>
                                <th style="width:7%;">Date</th>
                                <th style="width:12%;">Notes</th>
                                <th style="width:10%;">Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($children)): ?>
                                <?php foreach ($children as $child): ?>
                                    <?php
                                        $rowYear = '';
                                        $rowMonthNum = '';
                                        if (!empty($child['date']) && $child['date'] !== '—' && strlen($child['date']) >= 7) {
                                            $rowYear = substr($child['date'], 0, 4);
                                            $rowMonthNum = substr($child['date'], 5, 2);
                                        }
                                        $rowAge = is_numeric($child['age_in_months']) ? (string)$child['age_in_months'] : '';
                                        $rowBarangay = trim((string)($child['barangay'] ?? ''));
                                    ?>
                                    <tr class="child-profile-row" data-child-id="<?= (int)$child['child_id'] ?>">
                                        <td class="location-cell"
                                            data-year="<?= htmlspecialchars($rowYear) ?>"
                                            data-month="<?= htmlspecialchars($rowMonthNum) ?>"
                                            data-sex="<?= htmlspecialchars($child['sex']) ?>"
                                            data-age="<?= htmlspecialchars($rowAge) ?>"
                                            data-barangay="<?= htmlspecialchars($rowBarangay) ?>"
                                            data-name="<?= htmlspecialchars(strtolower($child['name'] ?? '')) ?>"
                                            data-address="<?= htmlspecialchars(strtolower($child['address'] ?? '')) ?>">
                                            <div title="<?= htmlspecialchars($child['address']) ?>"><?= htmlspecialchars($child['address']) ?></div>
                                        </td>
                                        <td class="location-cell">
                                            <div title="<?= htmlspecialchars($child['barangay']) ?>"><?= htmlspecialchars($child['barangay']) ?></div>
                                        </td>
                                        <td class="name-cell"><div title="<?= htmlspecialchars($child['name']) ?>"><?= htmlspecialchars($child['name']) ?></div></td>
                                        <td class="sex-cell"><?= htmlspecialchars($child['sex']) ?></td>
                                        <td class="metric-cell"><?= htmlspecialchars($child['age_in_months']) ?></td>
                                        <td class="metric-cell" style="white-space:nowrap;"><?= htmlspecialchars($child['measurement_date'] ?? '—') ?></td>
                                        <td class="status-cell <?= htmlspecialchars(status_cell_class($child['height_for_age_status'])) ?>" title="<?= htmlspecialchars($child['height_for_age_status']) ?>">
                                            <?= htmlspecialchars(status_abbrev($child['height_for_age_status'])) ?>
                                        </td>
                                        <td class="status-cell <?= htmlspecialchars(status_cell_class($child['weight_for_age_status'])) ?>" title="<?= htmlspecialchars($child['weight_for_age_status']) ?>">
                                            <?= htmlspecialchars(status_abbrev($child['weight_for_age_status'])) ?>
                                        </td>
                                        <td class="status-cell <?= htmlspecialchars(status_cell_class($child['weight_for_ltht_status'])) ?>" title="<?= htmlspecialchars($child['weight_for_ltht_status']) ?>">
                                            <?= htmlspecialchars(status_abbrev($child['weight_for_ltht_status'])) ?>
                                        </td>
                                        <td class="metric-cell" style="white-space:nowrap;"><?= htmlspecialchars($child['date']) ?></td>
                                        <?php if ($isGiveOut): ?>
                                        <td class="location-cell">
                                            <?php if ($child['given_items'] !== ''): ?>
                                                <?php foreach (explode('||', $child['given_items']) as $item): ?>
                                                    <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($item) ?>"><?= htmlspecialchars($item) ?></div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td class="metric-cell">
                                            <?php if ($child['given_qtys'] !== ''): ?>
                                                <?php foreach (explode('||', $child['given_qtys']) as $qty): ?>
                                                    <div><?= htmlspecialchars($qty) ?></div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td class="location-cell">
                                            <div>
                                                <?php 
                                                    $desc = $child['description'] !== '' ? htmlspecialchars($child['description']) : 'No description';
                                                    if (mb_strlen(trim($desc)) > 20) {
                                                        $truncated = mb_substr(trim($desc), 0, 20);
                                                        echo '<span class="note-short">' . $truncated . '...</span>';
                                                        echo '<span class="note-full" style="display:none;">' . $desc . '</span>';
                                                        echo ' <button type="button" class="btn-see-more" style="color:#2563eb; background:none; border:none; padding:0; font-size:11px; font-weight:700; cursor:pointer; margin-left:4px;">See more</button>';
                                                    } else {
                                                        echo $desc;
                                                    }
                                                ?>
                                            </div>
                                        </td>
                                        <td class="metric-cell">
                                            <button type="button" class="btn-view btn-view-history" data-child-id="<?= (int)$child['child_id'] ?>" data-child-name="<?= htmlspecialchars($child['name']) ?>" style="background:#059669 !important; border-color:#059669 !important; box-shadow:0 4px 12px rgba(5,150,105,.22) !important; color:#fff !important;">
                                                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                                View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?= $isGiveOut ? '14' : '12' ?>">
                                        <div class="empty-state">No children found for this intervention.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<div id="editModal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <div class="modal-head">
            <div class="modal-title" id="editModalTitle">Edit Intervention</div>
        </div>
        <form method="post" action="view_interventions.php?k=<?= urlencode($encodedKey) ?>">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_intervention_view">

                <div class="field-grid">
                    <div>
                        <div class="field-label">Description / Notes</div>
                        <textarea class="field-textarea" name="description" placeholder="Enter intervention description"><?= htmlspecialchars($description) ?></textarea>
                    </div>
                    <div>
                        <div class="field-label">Intervention Date</div>
                        <input class="field-input" type="date" name="intervention_date" value="<?= htmlspecialchars($interventionDate) ?>" required>
                    </div>
                </div>

                <div>
                    <div class="field-label">Children Included In This Intervention</div>
                    <div class="modal-table-wrap">
                        <table class="modal-table">
                            <thead>
                                <tr>
                                    <th style="width:10%;">Include</th>
                                    <th style="width:30%;">Name</th>
                                    <th style="width:30%;">Address</th>
                                    <th style="width:30%;">Barangay</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($children as $child): ?>
                                    <tr>
                                        <td class="cell-center">
                                            <input type="checkbox" name="child_ids[]" value="<?= (int)$child['child_id'] ?>" checked>
                                        </td>
                                        <td class="cell-left">
                                            <div style="font-weight:600; color:#0f172a;">
                                                <?= htmlspecialchars($child['name']) ?>
                                            </div>
                                        </td>
                                        <td class="cell-left">
                                            <div style="color:#64748b;">
                                                <?= htmlspecialchars($child['address']) ?>
                                            </div>
                                        </td>
                                        <td class="cell-left">
                                            <div style="color:#64748b;">
                                                <?= htmlspecialchars($child['barangay']) ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" id="btnCloseEditModal">Cancel</button>
                <button type="submit" class="btn-submit" style="width:auto !important; margin-top:0 !important; padding:8px 12px !important; font-size:12px !important; border-radius:8px !important;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="confirmChildModal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="confirmChildTitle" style="width:min(420px, 96vw);">
        <div class="modal-head">
            <div class="modal-title" id="confirmChildTitle">View Child Profile</div>
            <button type="button" class="btn-secondary" id="btnCloseConfirmTop">Close</button>
        </div>
        <div class="modal-body">
            <div style="font-size:13px;color:#334155;line-height:1.6;">
                Are you sure you want to view this child profile?
                <div id="confirmChildName" style="margin-top:6px;font-weight:700;color:#0f172a;"></div>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-secondary" id="btnCloseConfirm">Cancel</button>
            <button type="button" class="btn-view inline-flex items-center gap-1.5" id="btnConfirmGo" style="width:auto !important; margin-top:0 !important; padding:8px 12px !important; font-size:12px !important; border-radius:8px !important; background:#059669 !important; border-color:#059669 !important; box-shadow:0 4px 12px rgba(5,150,105,.22) !important; color:#fff !important;">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                Yes, view profile
            </button>
        </div>
    </div>
</div>

<div id="historyModal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="historyModalTitle" style="width:min(720px, 96vw);">
        <div class="modal-head">
            <div class="modal-title" id="historyModalTitle">Intervention History</div>
            <button type="button" class="btn-secondary" id="btnCloseHistoryTop">Close</button>
        </div>
        <div class="modal-body">
            <div class="view-detail-label" id="historyChildName"></div>
            <div class="history-table-wrap">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th style="width:25%;">Date</th>
                            <?php if ($isGiveOut): ?>
                            <th style="width:25%;">What has given</th>
                            <th style="width:15%;">How many pcs</th>
                            <th style="width:35%;">Description</th>
                            <?php else: ?>
                            <th style="width:75%;">Description</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="historyList"></tbody>
                </table>
            </div>
        </div>
      
    </div>
</div>

<script>
window.interventionConfig = {
    historyTypeId: <?= (int)$typeId ?>,
    isGiveOut: <?= $isGiveOut ? 'true' : 'false' ?>
};
</script>
<script src="javascript/view_interventions.js?v=<?= filemtime(__DIR__ . '/javascript/view_interventions.js') ?>"></script>
</body>
</html>