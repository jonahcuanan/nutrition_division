<?php
session_start();
require_once __DIR__ . '/access_control.php';

$isArchiveChildAction = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'archive_child');

if ($isArchiveChildAction) {
    enforce_page_access(['expectsJson' => true, 'page' => 'archive_child.php']);
    require_once __DIR__ . '/database.php';
    require_once __DIR__ . '/activity_logger.php';
    header('Content-Type: application/json');

    $childId = isset($_POST['child_id']) ? (int)$_POST['child_id'] : 0;
    $archiveStatus = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
    $statusDate = isset($_POST['status_date']) ? trim((string)$_POST['status_date']) : '';
    $allowedArchiveStatuses = ['Archive', 'Decease', 'OverAge'];

    if ($childId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid child ID.']);
        exit;
    }

    if (!in_array($archiveStatus, $allowedArchiveStatuses, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid archive status selected.']);
        exit;
    }

    if ($statusDate === '') {
        echo json_encode(['success' => false, 'message' => 'Archival date is required.']);
        exit;
    }

    $childFullName = '';
    $nameStmt = $conn->prepare('SELECT first_name, middle_name, last_name, suffix FROM children WHERE child_id = ?');
    if ($nameStmt) {
        $nameStmt->bind_param('i', $childId);
        if ($nameStmt->execute()) {
            $nameStmt->bind_result($firstName, $middleName, $lastName, $suffix);
            if ($nameStmt->fetch()) {
                $ln = trim((string)$lastName);
                $fn = trim((string)$firstName);
                $mn = trim((string)$middleName);
                $sx = trim((string)$suffix);

                $parts = [];
                if ($ln !== '') {
                    $parts[] = $ln . ','; // Lastname,
                }
                $firstMiddle = trim($fn . ' ' . $mn);
                if ($firstMiddle !== '') {
                    $parts[] = $firstMiddle;
                }
                if ($sx !== '') {
                    $parts[] = $sx;
                }

                $childFullName = trim(implode(' ', $parts));
            }
        }
        $nameStmt->close();
    }

    // Server-side: validate OverAge threshold against birthdate
    $birthdateStmt = $conn->prepare('SELECT birthdate FROM children WHERE child_id = ? LIMIT 1');
    $childBirthdate = '';
    if ($birthdateStmt) {
        $birthdateStmt->bind_param('i', $childId);
        if ($birthdateStmt->execute()) {
            $birthdateStmt->bind_result($bd);
            if ($birthdateStmt->fetch()) {
                $childBirthdate = trim((string)$bd);
            }
        }
        $birthdateStmt->close();
    }

    if ($archiveStatus === 'OverAge' && $childBirthdate !== '') {
        try {
            $bdObj = new DateTime($childBirthdate);
            $threshold = clone $bdObj;
            $threshold->modify('+4 years');
            $threshold->modify('+11 months');
            $selDateObj = new DateTime($statusDate);
            if ($selDateObj < $threshold) {
                echo json_encode(['success' => false, 'message' => 'Selected archival date is earlier than 4 years and 11 months from birth.']);
                exit;
            }
        } catch (Exception $e) {
            // ignore parse errors; proceed
        }
    }

    $stmt = $conn->prepare('UPDATE children SET status = ?, status_date = ? WHERE child_id = ?');
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to prepare archive query.']);
        exit;
    }
    $stmt->bind_param('ssi', $archiveStatus, $statusDate, $childId);

    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Failed to archive child profile.']);
        exit;
    }

    if ($stmt->affected_rows < 1) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Child profile not found or already archived.']);
        exit;
    }

    $stmt->close();

    $detailParts = ['Status: ' . $archiveStatus];
    if ($childFullName !== '') {
        $detailParts[] = 'Child: ' . $childFullName;
    }
    $details = 'Archived child profile (' . implode(', ', $detailParts) . ')';
    log_user_activity($conn, (int)($_SESSION['user_id'] ?? 0), 'archive_child', $details);

    echo json_encode(['success' => true, 'message' => 'Child profile archived successfully.']);
    exit;
}

enforce_page_access(['page' => 'archive_children.php']);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/activity_logger.php';

/**
 * AUTO-CLEANUP:
 * Automatically remove children who have been archived for more than 1 year.
 */
$cleanupStatuses = ["'Archive'", "'Decease'", "'OverAge'"];
$statusListStr = implode(',', $cleanupStatuses);

// 1. Identify children to be deleted
$expiredChildrenQuery = "SELECT child_id FROM children 
                         WHERE status IN ($statusListStr) 
                         AND status_date < DATE_SUB(NOW(), INTERVAL 1 YEAR)";
$expiredRes = $conn->query($expiredChildrenQuery);

if ($expiredRes && $expiredRes->num_rows > 0) {
    $expiredIds = [];
    while ($row = $expiredRes->fetch_assoc()) {
        $expiredIds[] = (int)$row['child_id'];
    }
    $idsStr = implode(',', $expiredIds);

    // 2. Delete linked intervention items manually (no cascade on this table)
    $delItems = "DELETE FROM intervention_items 
                 WHERE intervention_id IN (SELECT intervention_id FROM interventions WHERE child_id IN ($idsStr))";
    $conn->query($delItems);

    // 3. Delete children (cascades handle growth_records and interventions)
    $delChildren = "DELETE FROM children WHERE child_id IN ($idsStr)";
    if ($conn->query($delChildren)) {
        log_user_activity($conn, (int)($_SESSION['user_id'] ?? 1), 'Delete Children', "Automatically removed " . count($expiredIds) . " records archived for over 1 year.");
    }
}

$tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : 'all';
$allowedTabs = ['all', 'Archive', 'Decease', 'OverAge'];
if (!in_array($tab, $allowedTabs, true)) { $tab = 'all'; }

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentRole = $_SESSION['role'] ?? '';
$isBns = ($currentRole === 'Barangay Nutrition Scholars');
$isHw = ($currentRole === 'Health Worker');
$assignedBarangayId = isset($_SESSION['barangay_id']) ? (int)$_SESSION['barangay_id'] : 0;

// Tab counts
$countQuery = "SELECT
    SUM(CASE WHEN c.status = 'Archive' THEN 1 ELSE 0 END) AS archive_count,
    SUM(CASE WHEN c.status = 'Decease' THEN 1 ELSE 0 END) AS decease_count,
    SUM(CASE WHEN c.status = 'OverAge' THEN 1 ELSE 0 END) AS overage_count
    FROM children c";

$hasWhereCount = false;
if ($isBns) {
    $countQuery .= " WHERE EXISTS (SELECT 1 FROM growth_records grb WHERE grb.child_id = c.child_id AND grb.recorded_by = " . (int)$currentUserId . ")";
    $hasWhereCount = true;
} elseif ($isHw && $assignedBarangayId > 0) {
    $countQuery .= " WHERE c.barangay_id = " . (int)$assignedBarangayId;
    $hasWhereCount = true;
}

$countResult = $conn->query($countQuery);
$counts = ['Archive' => 0, 'Decease' => 0, 'OverAge' => 0];
if ($countResult && $countRow = $countResult->fetch_assoc()) {
    $counts['Archive'] = (int)($countRow['archive_count'] ?? 0);
    $counts['Decease'] = (int)($countRow['decease_count'] ?? 0);
    $counts['OverAge'] = (int)($countRow['overage_count'] ?? 0);
}
$counts['all'] = $counts['Archive'] + $counts['Decease'] + $counts['OverAge'];

// Main query — include is_ip
$sql = "SELECT c.child_id, c.first_name, c.middle_name, c.last_name, c.suffix,
               c.sex, c.birthdate, c.address, c.is_ip,
               c.status, c.status_date,
               b.barangay_name,
               g.first_name AS guardian_first, g.last_name AS guardian_last,
               g.middle_name AS guardian_middle, g.suffix AS guardian_suffix
        FROM children c
        LEFT JOIN barangays b ON c.barangay_id = b.barangay_id
        LEFT JOIN guardians g ON c.guardian_id = g.guardian_id
        WHERE c.status IN ('Archive', 'Decease', 'OverAge')";
if ($tab !== 'all') {
    $sql .= " AND c.status = '" . $conn->real_escape_string($tab) . "'";
}

if ($isBns) {
    $sql .= " AND EXISTS (SELECT 1 FROM growth_records grb WHERE grb.child_id = c.child_id AND grb.recorded_by = " . (int)$currentUserId . ")";
} elseif ($isHw && $assignedBarangayId > 0) {
    $sql .= " AND c.barangay_id = " . (int)$assignedBarangayId;
}

$sql .= " ORDER BY b.barangay_name ASC, g.last_name ASC, g.first_name ASC, g.middle_name ASC, g.suffix ASC, c.last_name ASC, c.first_name ASC, c.middle_name ASC, c.suffix ASC";
$result = $conn->query($sql);

// Collect rows + build barangay list
$rows = [];
$uniqueBarangays = [];
$today = new DateTime();
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Age in months from birthdate to today
        $ageMonths = null;
        if (!empty($row['birthdate'])) {
            try {
                $birth = new DateTime($row['birthdate']);
                if ($today >= $birth) {
                    $diff = $birth->diff($today);
                    $ageMonths = ($diff->y * 12) + $diff->m;
                    if ($ageMonths < 0) $ageMonths = 0;
                }
            } catch (Exception $e) { $ageMonths = null; }
        }
        $row['_age_months'] = $ageMonths;
        $rows[] = $row;
        $bn = $row['barangay_name'] ?? '';
        if ($bn !== '' && !in_array($bn, $uniqueBarangays)) {
            $uniqueBarangays[] = $bn;
        }
    }
}
sort($uniqueBarangays);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Children</title>
    <link rel="stylesheet" href="css/tailwind.css">
    <link rel="stylesheet" href="css/child_profiles.css">
    <link rel="stylesheet" href="css/growth-status.css">
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
<body class="bg-slate-100 text-slate-900 font-sans text-[14px]">
<?php include 'sidebar.php'; ?>

<main class="main-content min-h-screen px-4 md:px-9 py-6 md:py-8 pb-16 space-y-5">

    <div id="toastContainer"
         data-success="<?= isset($_GET['toast_success']) ? htmlspecialchars((string)$_GET['toast_success'], ENT_QUOTES) : '' ?>"></div>

    <!-- Page Header -->
    <div class="flex items-center gap-3 mb-5">
        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50 text-lg">🗂️</div>
        <div>
            <h1 class="text-lg font-bold text-slate-900">Archived Children</h1>
            <p class="mt-0.5 text-xs text-slate-500">Children profiles with status Archive, Decease, or OverAge.</p>
        </div>
    </div>

    <!-- Tab Filters -->
    <div class="flex flex-wrap gap-2 mb-4">
        <a href="archive_children.php?tab=all"     class="rounded-full px-3 py-1.5 text-xs font-semibold <?= $tab === 'all'     ? 'bg-slate-800 text-white'   : 'bg-white text-slate-700 border border-slate-300' ?>">All (<?= $counts['all'] ?>)</a>
        <a href="archive_children.php?tab=Archive"  class="rounded-full px-3 py-1.5 text-xs font-semibold <?= $tab === 'Archive'  ? 'bg-rose-700 text-white'    : 'bg-white text-slate-700 border border-slate-300' ?>">Archive (<?= $counts['Archive'] ?>)</a>
        <a href="archive_children.php?tab=Decease"  class="rounded-full px-3 py-1.5 text-xs font-semibold <?= $tab === 'Decease'  ? 'bg-amber-700 text-white'   : 'bg-white text-slate-700 border border-slate-300' ?>">Decease (<?= $counts['Decease'] ?>)</a>
        <a href="archive_children.php?tab=OverAge"  class="rounded-full px-3 py-1.5 text-xs font-semibold <?= $tab === 'OverAge'  ? 'bg-indigo-700 text-white'  : 'bg-white text-slate-700 border border-slate-300' ?>">OverAge (<?= $counts['OverAge'] ?>)</a>
    </div>

    <!-- Toolbar -->
    <div class="mb-4 flex flex-col gap-2.5">
        <div class="flex flex-wrap items-center gap-2.5">
            <!-- Search -->
            <div class="relative flex-1 min-w-[200px] max-w-xs">
                <svg class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="searchInput" placeholder="Search name, address, barangay, guardian…"
                    class="w-full rounded-md border border-slate-300 bg-white py-2 pl-7 pr-3 text-[0.85rem] text-slate-900 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100" />
            </div>
            <!-- Sex -->
            <select id="sexFilter" class="h-9 rounded-md border border-slate-300 bg-white px-3 pr-8 text-[0.85rem] text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
                <option value="">All Sex(Boys & Girls)</option>
                <option value="male">Male (Boys)</option>
                <option value="female">Female (Girls)</option>
            </select>
            <!-- IP Status -->
            <select id="ipFilter" class="h-9 rounded-md border border-slate-300 bg-white px-3 pr-8 text-[0.85rem] text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
                <option value="">All Groups</option>
                <option value="yes">Indigenous</option>
                <option value="no">Non-Indigenous</option>
            </select>
            <!-- Barangay -->
            <select id="barangayFilter" class="h-9 rounded-md border border-slate-300 bg-white px-3 pr-8 text-[0.85rem] text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100">
                <option value="">All Barangays</option>
                <?php foreach ($uniqueBarangays as $bn): ?>
                    <option value="<?= strtolower(htmlspecialchars($bn)) ?>"><?= htmlspecialchars($bn) ?></option>
                <?php endforeach; ?>
            </select>
            <!-- Age Direct Input -->
            <div class="flex items-center gap-2">
                <input type="number" id="ageFilter" placeholder="Age (months)" min="0"
                    class="h-9 w-[120px] rounded-md border border-slate-300 bg-white px-3 text-[0.85rem] text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100" />
            </div>
            <!-- Reset -->
            <button type="button" id="btnResetFilters"
                class="h-9 inline-flex items-center justify-center gap-1.5 rounded-md border border-rose-200 bg-rose-50 px-3 text-[0.85rem] font-semibold text-rose-600 shadow-sm hover:bg-rose-100 transition-colors">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                Reset
            </button>
            <span id="rowCount" class="ml-auto text-[0.78rem] text-slate-400 whitespace-nowrap"><?= count($rows) ?> records</span>
        </div>
    </div>

    <!-- Table -->
    <div class="child-table-wrap overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table id="archiveTable" class="table-stack min-w-full border border-slate-300 text-left text-[0.68rem] leading-tight">
            <thead class="text-[0.58rem] font-semibold uppercase tracking-wide text-white">
                <tr>
                    <th class="border border-slate-300 bg-black px-3 py-2 align-middle">Address / Location</th>
                    <th class="border border-slate-300 bg-black px-3 py-2 align-middle">Barangay</th>
                    <th class="border border-slate-300 bg-black px-3 py-2 align-middle">Guardian / Caregiver</th>
                    <th class="border border-slate-300 bg-black px-3 py-2 align-middle">Full Name of Child</th>
                    <th class="border border-slate-300 bg-black px-3 py-2 text-center align-middle">
                        <span class="block">Belongs</span>
                        <span class="block">to IP</span>
                        <span class="block">Group?</span>
                    </th>
                    <th class="border border-slate-300 bg-black px-3 py-2 text-center align-middle">Sex</th>
                    <th class="border border-slate-300 bg-black px-3 py-2 text-center align-middle">Date of Birth</th>
                    <th class="border border-slate-300 bg-black px-3 py-2 text-center align-middle">Age (Months)</th>
                    <th class="border border-slate-300 bg-black px-3 py-2 text-center align-middle">Date of Archival</th>
                    <th class="border border-slate-300 bg-black px-3 py-2 text-center align-middle">Archive Status</th>
                    <th class="border border-slate-300 bg-black px-3 py-2 text-center align-middle actions-col">Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody" class="bg-white">
            <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $row): ?>
                <?php
                    $ln = trim((string)($row['last_name'] ?? ''));
                    $fn = trim((string)($row['first_name'] ?? ''));
                    $mn = trim((string)($row['middle_name'] ?? ''));
                    $sx = trim((string)($row['suffix'] ?? ''));

                    $nameParts = [];
                    if ($ln !== '') {
                        $nameParts[] = $ln . ',';
                    }
                    $firstMiddle = trim($fn . ' ' . $mn);
                    if ($firstMiddle !== '') {
                        $nameParts[] = $firstMiddle;
                    }
                    if ($sx !== '') {
                        $nameParts[] = $sx;
                    }

                    $fullName = trim(implode(' ', $nameParts));
                    $g_ln = trim((string)($row['guardian_last'] ?? ''));
                    $g_fn = trim((string)($row['guardian_first'] ?? ''));
                    $g_mn = trim((string)($row['guardian_middle'] ?? ''));
                    $g_sx = trim((string)($row['guardian_suffix'] ?? ''));

                    $g_parts = [];
                    if ($g_ln !== '') {
                        $g_parts[] = $g_ln . ',';
                    }
                    $g_firstMiddle = trim($g_fn . ' ' . $g_mn);
                    if ($g_firstMiddle !== '') {
                        $g_parts[] = $g_firstMiddle;
                    }
                    if ($g_sx !== '') {
                        $g_parts[] = $g_sx;
                    }

                    $guardian = trim(implode(' ', $g_parts));
                    $guardian = $guardian ?: '—';
                    $address     = trim($row['address'] ?? '');
                    $address     = $address !== '' ? $address : ($row['barangay_name'] ?? '—');
                    $barangay    = $row['barangay_name'] ?? '—';
                    $ageMonths   = $row['_age_months'];
                    $ageDisplay  = $ageMonths !== null ? $ageMonths : '—';
                    $isIp        = ($row['is_ip'] ?? 'No') === 'Yes' ? 'Yes' : 'No';
                    $sexDisplay  = $row['sex'] === 'Male' ? 'M' : ($row['sex'] === 'Female' ? 'F' : '—');
                    $birthdateDisplay = !empty($row['birthdate']) ? date('M-d-y', strtotime($row['birthdate'])) : '—';
                    $archivedDateDisplay = !empty($row['status_date']) ? date('M-d-y', strtotime($row['status_date'])) : '—';

                    $badgeClass = 'bg-slate-200 text-slate-700';
                    if ($row['status'] === 'Archive') $badgeClass = 'bg-rose-100 text-rose-700';
                    if ($row['status'] === 'Decease')  $badgeClass = 'bg-amber-100 text-amber-700';
                    if ($row['status'] === 'OverAge')  $badgeClass = 'bg-indigo-100 text-indigo-700';
                ?>
                <tr class="hover:bg-slate-50"
                    data-name="<?= strtolower(htmlspecialchars($fullName)) ?>"
                    data-guardian="<?= strtolower(htmlspecialchars($guardian)) ?>"
                    data-address="<?= strtolower(htmlspecialchars($address)) ?>"
                    data-barangay="<?= strtolower(htmlspecialchars($barangay)) ?>"
                    data-sex="<?= strtolower(htmlspecialchars($row['sex'] ?? '')) ?>"
                    data-ip="<?= strtolower($isIp) ?>"
                    data-age="<?= $ageMonths !== null ? (int)$ageMonths : '' ?>">
                    <td class="border border-slate-300 px-3 py-2 align-middle text-slate-700" data-label="Address"><?= htmlspecialchars($address) ?></td>
                    <td class="border border-slate-300 px-3 py-2 align-middle text-slate-700" data-label="Barangay"><?= htmlspecialchars($barangay) ?></td>
                    <td class="border border-slate-300 px-3 py-2 align-middle text-slate-700" data-label="Guardian"><?= htmlspecialchars($guardian) ?></td>
                    <td class="border border-slate-300 px-3 py-2 align-middle" data-label="Full Name">
                        <div class="font-semibold text-slate-900"><?= htmlspecialchars($fullName) ?></div>
                    </td>
                    <td class="border border-slate-300 px-3 py-2 align-middle text-center text-slate-700 font-semibold" data-label="IP Group">
                        <?= $isIp ?>
                    </td>
                    <td class="border border-slate-300 px-3 py-2 align-middle text-center text-slate-700" data-label="Sex"><?= htmlspecialchars($sexDisplay) ?></td>
                    <td class="border border-slate-300 px-3 py-2 align-middle text-center text-slate-700 whitespace-nowrap" data-label="Date of Birth"><?= htmlspecialchars($birthdateDisplay) ?></td>
                    <td class="border border-slate-300 px-3 py-2 align-middle text-center font-bold age-months" data-label="Age (Months)">
                        <?= htmlspecialchars((string)$ageDisplay) ?>
                    </td>
                    <td class="border border-slate-300 px-3 py-2 align-middle text-center text-slate-700 whitespace-nowrap" data-label="Date of Archival"><?= htmlspecialchars($archivedDateDisplay) ?></td>
                    <td class="border border-slate-300 px-3 py-2 align-middle text-center" data-label="Status">
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[0.68rem] font-semibold <?= $badgeClass ?>"><?= htmlspecialchars($row['status']) ?></span>
                    </td>
                    <td class="border border-slate-300 px-3 py-2 align-middle text-center actions-cell" data-label="Actions">
                        <div class="flex items-center justify-center gap-1.5">
                            <a href="view_child_profile.php?child_id=<?= (int)$row['child_id'] ?>"
                               class="inline-flex rounded-md bg-emerald-600 px-2.5 py-1 text-[0.72rem] text-white font-semibold hover:bg-emerald-700">View</a>
                            <?php if ($row['status'] === 'Archive'): ?>
                            <button type="button" 
                                    class="btn-restore inline-flex rounded-md bg-blue-600 px-2.5 py-1 text-[0.72rem] text-white font-semibold hover:bg-blue-700"
                                    data-child-id="<?= (int)$row['child_id'] ?>"
                                    data-full-name="<?= htmlspecialchars($fullName) ?>">
                                Restore
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr id="noDataRow">
                    <td colspan="11" class="border border-slate-300 px-3 py-8 text-center text-slate-400">No archived children found.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>

    <!-- Restore Confirmation Modal -->
    <div id="restoreModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-5 opacity-0 invisible pointer-events-none transition-opacity duration-200" aria-hidden="true">
        <div id="restoreBackdrop" class="absolute inset-0 bg-slate-900/55 backdrop-blur-md"></div>
        <div class="relative z-10 w-full max-w-sm transform overflow-hidden rounded-2xl bg-white shadow-2xl transition duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] translate-y-4 scale-95" id="restoreBox" role="dialog" aria-modal="true" aria-labelledby="restoreTitle">
            <div class="p-6 text-center">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-blue-100">
                    <svg class="h-8 w-8 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                </div>
                <h3 class="mb-2 text-lg font-bold text-slate-900" id="restoreTitle">Restore Child Profile?</h3>
                <p class="text-sm text-slate-600 leading-relaxed">Are you sure you want to restore <span id="restoreChildName" class="font-bold text-slate-900"></span> to Active status?</p>
            </div>
            <div class="flex items-center justify-center gap-3 bg-slate-50 px-6 py-4">
                <button type="button" id="btnCancelRestore" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">Cancel</button>
                <form id="restoreForm" method="POST" action="restore_child.php">
                    <input type="hidden" name="child_id" id="restoreChildId">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors">Yes, Restore</button>
                </form>
            </div>
        </div>
    </div>

<script>
(function () {
    function showToast(type, message) {
        const host = document.getElementById('toastContainer');
        if (!host || !message) return;

        const toast = document.createElement('div');
        const isSuccess = type === 'success';
        toast.className = `toast ${isSuccess ? 'toast-success' : 'toast-error'}`;

        const label = document.createElement('span');
        label.className = 'toast-label';
        label.textContent = message;
        toast.appendChild(label);

        host.prepend(toast);

        setTimeout(() => {
            toast.style.transition = 'opacity .25s, transform .25s';
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-6px)';
            setTimeout(() => toast.remove(), 260);
        }, 3800);
    }

    const searchInput   = document.getElementById('searchInput');
    const sexFilter     = document.getElementById('sexFilter');
    const ipFilter      = document.getElementById('ipFilter');
    const barangayFilter= document.getElementById('barangayFilter');
    const ageFilter     = document.getElementById('ageFilter');
    const rowCountEl    = document.getElementById('rowCount');

    const toastHost = document.getElementById('toastContainer');
    if (toastHost) {
        const successMsg = toastHost.dataset.success || '';
        if (successMsg) showToast('success', successMsg);
    }

    // Reset
    document.getElementById('btnResetFilters').addEventListener('click', () => {
        searchInput.value   = '';
        sexFilter.value     = '';
        ipFilter.value      = '';
        barangayFilter.value= '';
        ageFilter.value     = '';
        applyFilters();
    });

    function applyFilters() {
        const search   = searchInput.value.toLowerCase().trim();
        const sex      = sexFilter.value;
        const ip       = ipFilter.value;
        const barangay = barangayFilter.value;
        const ageVal   = ageFilter.value !== '' ? parseInt(ageFilter.value, 10) : null;

        const rows = document.querySelectorAll('#tableBody tr[data-name]');
        let visible = 0;

        rows.forEach(row => {
            const matchSearch   = !search || row.dataset.name.includes(search) || row.dataset.guardian.includes(search) || row.dataset.address.includes(search) || row.dataset.barangay.includes(search);
            const matchSex      = !sex      || row.dataset.sex === sex;
            const matchIp       = !ip       || row.dataset.ip  === ip;
            const matchBarangay = !barangay || row.dataset.barangay === barangay;
            const rowAge        = row.dataset.age !== '' ? parseInt(row.dataset.age, 10) : null;
            const matchAge      = ageVal === null || (rowAge !== null && rowAge === ageVal);

            const show = matchSearch && matchSex && matchIp && matchBarangay && matchAge;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        rowCountEl.textContent = visible + ' record' + (visible !== 1 ? 's' : '');

        // Empty state
        let emptyRow = document.getElementById('dynamicEmpty');
        if (visible === 0 && rows.length > 0) {
            if (!emptyRow) {
                const tbody = document.getElementById('tableBody');
                const tr = document.createElement('tr');
                tr.id = 'dynamicEmpty';
                tr.innerHTML = `<td colspan="10" class="border border-slate-300 px-3 py-8 text-center text-slate-400">No records match your filters.</td>`;
                tbody.appendChild(tr);
            }
        } else {
            if (emptyRow) emptyRow.remove();
        }
    }

    [searchInput, sexFilter, ipFilter, barangayFilter, ageFilter]
        .forEach(el => el.addEventListener('input', applyFilters));

    // Set initial count
    rowCountEl.textContent = document.querySelectorAll('#tableBody tr[data-name]').length + ' records';

    // ── Restore Modal Logic ──
    const restoreModal = document.getElementById('restoreModal');
    const restoreBox = document.getElementById('restoreBox');
    const restoreBackdrop = document.getElementById('restoreBackdrop');
    const restoreChildName = document.getElementById('restoreChildName');
    const restoreChildId = document.getElementById('restoreChildId');
    const btnCancelRestore = document.getElementById('btnCancelRestore');

    function openRestoreModal(id, name) {
        restoreChildId.value = id;
        restoreChildName.textContent = name;
        restoreModal.classList.remove('invisible', 'pointer-events-none');
        restoreModal.classList.add('opacity-100');
        restoreBox.classList.remove('translate-y-4', 'scale-95');
        restoreBox.classList.add('translate-y-0', 'scale-100');
    }

    function closeRestoreModal() {
        restoreModal.classList.add('opacity-0');
        restoreBox.classList.add('translate-y-4', 'scale-95');
        restoreBox.classList.remove('translate-y-0', 'scale-100');
        setTimeout(() => {
            restoreModal.classList.add('invisible', 'pointer-events-none');
            restoreModal.classList.remove('opacity-100');
        }, 200);
    }

    document.querySelectorAll('.btn-restore').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.getAttribute('data-child-id');
            const name = btn.getAttribute('data-full-name');
            openRestoreModal(id, name);
        });
    });

    btnCancelRestore.addEventListener('click', closeRestoreModal);
    restoreBackdrop.addEventListener('click', closeRestoreModal);

    // Close on ESC
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeRestoreModal();
    });
})();
</script>
</body>
</html>
