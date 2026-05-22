<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/growth_utils.php';

function buildFullName($first, $middle, $last, $suffix): string
{
    $parts = [];
    if ($first) $parts[] = $first;
    if ($middle) $parts[] = $middle;
    if ($last) $parts[] = $last;
    if ($suffix) $parts[] = $suffix;
    return trim(implode(' ', $parts));
}

function computeAgeInMonths($birthdate, $measurementDate): ?int
{
    if (!$birthdate || !$measurementDate) return null;
    try {
        $b = new DateTime($birthdate);
        $m = new DateTime($measurementDate);
    } catch (Exception $e) {
        return null;
    }
    if ($m < $b) return null;
    $diff = $b->diff($m);
    $months = ($diff->y * 12) + $diff->m;
    return $months < 0 ? 0 : $months;
}

function computeStatuses(mysqli $conn, string $sex, int $ageMonths, float $height, float $weight, float $muac = 0): array
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

    $weightStatus = $weightRef ? (determineWeightForAgeStatus($weight, $weightRef) ?? 'N/A') : 'N/A';
    $heightStatus = $heightRef ? (determineHeightForAgeStatus($height, $heightRef) ?? 'N/A') : 'N/A';
    $wflStatus    = $wflRef    ? (determineWeightForLengthStatus($weight, $wflRef) ?? 'N/A') : 'N/A';
    $muacStatus   = $muac > 0 ? (determineMuacStatus($muac) ?? 'N/A') : 'N/A';

    return [
        'height_for_age_status'  => $heightStatus,
        'weight_for_age_status'  => $weightStatus,
        'weight_for_ltht_status' => $wflStatus,
        'muac_status'            => $muacStatus,
    ];
}

// Added $muac parameter to computeStatuses signature
function computeStatusesWithMuac(mysqli $conn, string $sex, int $ageMonths, float $height, float $weight, float $muac): array
{
    return computeStatuses($conn, $sex, $ageMonths, $height, $weight, $muac);
}

function statusClass(string $status): string
{
    $v = strtolower(trim($status));
    if (in_array($v, ['normal', 'tall'], true))                                       return 'ok';
    if (in_array($v, ['stunted', 'underweight', 'wasted'], true))                    return 'warn';
    if (in_array($v, ['overweight', 'obese'], true))                                 return 'alert';
    if (in_array($v, ['severely stunted', 'severely underweight', 'severely wasted'], true)) return 'bad';
    return '';
}

function statusIcon(string $status): string
{
    $v = strtolower(trim($status));
    if (in_array($v, ['normal', 'tall'], true))                                       return '✓';
    if (in_array($v, ['stunted', 'underweight', 'wasted'], true))                    return '▲';
    if (in_array($v, ['overweight', 'obese'], true))                                 return '●';
    if (in_array($v, ['severely stunted', 'severely underweight', 'severely wasted'], true)) return '✕';
    return '–';
}

function status_abbrev(string $status): string
{
    $value = strtolower(trim($status));
    if ($value === '' || $value === '—' || $value === 'n/a') return 'N/A';
    if ($value === 'out of range') return 'OOR';
    $map = [
        'severely underweight' => 'SUW',
        'underweight'          => 'UW',
        'normal'               => 'N',
        'severely stunted'     => 'SSt',
        'stunted'              => 'St',
        'tall'                 => 'T',
        'severely wasted'      => 'SW',
        'moderately wasted'    => 'MW',
        'wasted'               => 'W',
        'overweight'           => 'OW',
        'obese'                => 'Ob',
    ];
    if (isset($map[$value])) return $map[$value];
    foreach ($map as $key => $abbr) {
        if (strpos($value, $key) !== false) return $abbr;
    }
    return strtoupper($status);
}

function status_cell_class_vcp(string $status): string
{
    $abbr = strtolower(status_abbrev($status));
    if ($abbr === 'n/a')                              return 'vcp-status-na';
    if ($abbr === 'oor')                              return 'vcp-status-oor';
    if (in_array($abbr, ['suw', 'sst', 'sw'], true)) return 'vcp-status-severe';
    if (in_array($abbr, ['uw', 'st', 'w', 'mw'], true)) return 'vcp-status-moderate';
    if (in_array($abbr, ['ow', 'ob'], true))          return 'vcp-status-over';
    if (in_array($abbr, ['n', 't'], true))            return 'vcp-status-normal';
    return 'vcp-status-na';
}

$childId = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$errorMessage = '';
$child = null;
$records = [];
$interventions = [];
$wfaRefs = [];
$hfaRefs = [];
$wflRefs = [];
$wflAgeGroup = null;

if ($childId <= 0) {
    $errorMessage = 'Invalid child ID.';
} else {
    $childSql = "SELECT c.*, b.barangay_name,
                       g.first_name AS guardian_first, g.middle_name AS guardian_middle,
                       g.last_name AS guardian_last, g.suffix AS guardian_suffix,
                       g.relationship_to_child, g.contact_number
                FROM children c
                LEFT JOIN barangays b ON c.barangay_id = b.barangay_id
                LEFT JOIN guardians g ON c.guardian_id = g.guardian_id
                WHERE c.child_id = ?
                LIMIT 1";
    $childStmt = $conn->prepare($childSql);
    if ($childStmt) {
        $childStmt->bind_param('i', $childId);
        $childStmt->execute();
        $childResult = $childStmt->get_result();
        if ($childResult && $row = $childResult->fetch_assoc()) {
            $child = $row;
        } else {
            $errorMessage = 'Child not found.';
        }
        $childStmt->close();
    } else {
        $errorMessage = 'Database error.';
    }

    if ($errorMessage === '' && $child) {
        $recordSql = "SELECT gr.record_id, gr.measurement_date, gr.weight, gr.height,
                             gr.muac_measurement,
                             gr.weight_id, gr.height_id, gr.wfl_id, gr.recorded_by,
                             u.first_name AS recorder_first, u.middle_name AS recorder_middle,
                             u.last_name AS recorder_last, u.suffix AS recorder_suffix
                      FROM growth_records gr
                      LEFT JOIN users u ON u.user_id = gr.recorded_by
                      WHERE gr.child_id = ?
                      ORDER BY gr.measurement_date DESC, gr.record_id DESC";
        $recordStmt = $conn->prepare($recordSql);
        if ($recordStmt) {
            $recordStmt->bind_param('i', $childId);
            $recordStmt->execute();
            $recordResult = $recordStmt->get_result();
            if ($recordResult) {
                while ($rec = $recordResult->fetch_assoc()) {
                    $ageMonths = computeAgeInMonths($child['birthdate'], $rec['measurement_date']);
                    $rec['age_in_months'] = $ageMonths;
                    if ($ageMonths !== null && $rec['weight'] !== null && $rec['height'] !== null) {
                        $statuses = computeStatuses($conn, $child['sex'], $ageMonths, (float)$rec['height'], (float)$rec['weight'], (float)($rec['muac_measurement'] ?? 0));
                        $rec = array_merge($rec, $statuses);
                    } else {
                        $rec['height_for_age_status'] = 'N/A';
                        $rec['weight_for_age_status'] = 'N/A';
                        $rec['weight_for_ltht_status'] = 'N/A';
                        $rec['muac_status'] = 'N/A';
                    }
                    $rec['recorded_by_name'] = buildFullName($rec['recorder_first'] ?? '', $rec['recorder_middle'] ?? '', $rec['recorder_last'] ?? '', $rec['recorder_suffix'] ?? '');
                    if ($rec['recorded_by_name'] === '') $rec['recorded_by_name'] = 'System';
                    $records[] = $rec;
                }
            }
            $recordStmt->close();
        }

        $interventionSql = "SELECT i.intervention_id, i.intervention_date, i.description,
                                   t.type_name, ii.quantity_given, inv.item_name, inv.unit
                            FROM interventions i
                            LEFT JOIN intervention_types t ON t.type_id = i.type_id
                            LEFT JOIN intervention_items ii ON ii.intervention_id = i.intervention_id
                            LEFT JOIN inventory inv ON inv.inventory_id = ii.inventory_id
                            WHERE i.child_id = ?
                            ORDER BY i.intervention_date DESC, i.intervention_id DESC, ii.item_id ASC";
        $interventionStmt = $conn->prepare($interventionSql);
        if ($interventionStmt) {
            $interventionStmt->bind_param('i', $childId);
            $interventionStmt->execute();
            $interventionResult = $interventionStmt->get_result();
            if ($interventionResult) {
                $interventionMap = [];
                while ($row = $interventionResult->fetch_assoc()) {
                    $interventionId = (int)($row['intervention_id'] ?? 0);
                    if ($interventionId <= 0) {
                        continue;
                    }
                    if (!isset($interventionMap[$interventionId])) {
                        $dateVal = (string)($row['intervention_date'] ?? '');
                        if ($dateVal === '' || $dateVal === '0000-00-00') {
                            $dateVal = '—';
                            $dateDisplay = '—';
                        } else {
                            $dateDisplay = date('M d, Y', strtotime($dateVal));
                        }
                        $interventionMap[$interventionId] = [
                            'intervention_id' => $interventionId,
                            'intervention_date' => $dateVal,
                            'intervention_date_display' => $dateDisplay,
                            'date_display' => $dateDisplay,
                            'type_name' => $row['type_name'] ?? '—',
                            'description' => $row['description'] ?? '',
                            'items' => [],
                        ];
                    }

                    if (!empty($row['item_name'])) {
                        $qty = $row['quantity_given'] !== null ? (int)$row['quantity_given'] : null;
                        $unit = $row['unit'] ?? '';
                        $itemLabel = $row['item_name'];
                        if ($qty !== null) {
                            $itemLabel .= ' (' . $qty . ($unit ? ' ' . $unit : '') . ')';
                        }
                        $interventionMap[$interventionId]['items'][] = $itemLabel;
                    }
                }
                $interventions = array_values($interventionMap);
            }
            $interventionStmt->close();
        }

        $assignedBns = null;
        $bnsSql = "SELECT u.first_name, u.middle_name, u.last_name, u.suffix, u.role
                   FROM growth_records gr
                   JOIN users u ON gr.recorded_by = u.user_id
                   WHERE gr.child_id = ?
                   ORDER BY gr.measurement_date ASC, gr.record_id ASC
                   LIMIT 1";
        $bnsStmt = $conn->prepare($bnsSql);
        if ($bnsStmt) {
            $bnsStmt->bind_param('i', $childId);
            $bnsStmt->execute();
            $bnsResult = $bnsStmt->get_result();
            if ($bnsResult && $brow = $bnsResult->fetch_assoc()) {
                $assignedBns = $brow;
            }
            $bnsStmt->close();
        }

        $sex = ucfirst(strtolower($child['sex'] ?? ''));
        if ($sex === 'Male' || $sex === 'Female') {
            $wfaSql = "SELECT age_month, severely_underweight_max, underweight_max, normal_max, overweight
                       FROM " . GROWTH_WEIGHT_TABLE . "
                       WHERE sex = ?
                       ORDER BY age_month ASC";
            $wfaStmt = $conn->prepare($wfaSql);
            if ($wfaStmt) {
                $wfaStmt->bind_param('s', $sex);
                $wfaStmt->execute();
                $wfaRes = $wfaStmt->get_result();
                if ($wfaRes) {
                    while ($row = $wfaRes->fetch_assoc()) {
                        $wfaRefs[] = $row;
                    }
                }
                $wfaStmt->close();
            }

            $hfaSql = "SELECT age_month, severely_stunted, stunted_to, normal_to, tall
                       FROM " . GROWTH_HEIGHT_TABLE . "
                       WHERE sex = ?
                       ORDER BY age_month ASC";
            $hfaStmt = $conn->prepare($hfaSql);
            if ($hfaStmt) {
                $hfaStmt->bind_param('s', $sex);
                $hfaStmt->execute();
                $hfaRes = $hfaStmt->get_result();
                if ($hfaRes) {
                    while ($row = $hfaRes->fetch_assoc()) {
                        $hfaRefs[] = $row;
                    }
                }
                $hfaStmt->close();
            }

            $ageForWfl = null;
            if (!empty($child['birthdate'])) {
                $ageForWfl = computeAgeInMonths($child['birthdate'], date('Y-m-d'));
            }
            if ($ageForWfl === null && !empty($records)) {
                $ageForWfl = $records[0]['age_in_months'] ?? null;
            }
            $wflAgeGroup = $ageForWfl !== null ? resolveWeightForLengthAgeGroup((int)$ageForWfl) : null;
            if ($wflAgeGroup === null) {
                $wflAgeGroup = '0-23months';
            }

            $wflSql = "SELECT length_cm, severely_wasted, wasted_to, normal_to, overweight_to, obese
                       FROM " . GROWTH_WFL_TABLE . "
                       WHERE sex = ? AND age_group = ?
                       ORDER BY length_cm ASC";
            $wflStmt = $conn->prepare($wflSql);
            if ($wflStmt) {
                $wflStmt->bind_param('ss', $sex, $wflAgeGroup);
                $wflStmt->execute();
                $wflRes = $wflStmt->get_result();
                if ($wflRes) {
                    while ($row = $wflRes->fetch_assoc()) {
                        $wflRefs[] = $row;
                    }
                }
                $wflStmt->close();
            }
        }
    }
}

$childName        = $child ? buildFullName($child['first_name'] ?? '', $child['middle_name'] ?? '', $child['last_name'] ?? '', $child['suffix'] ?? '') : '';
$guardianName     = $child ? buildFullName($child['guardian_first'] ?? '', $child['guardian_middle'] ?? '', $child['guardian_last'] ?? '', $child['guardian_suffix'] ?? '') : '';
$assignedBnsName  = isset($assignedBns) && $assignedBns ? buildFullName($assignedBns['first_name'] ?? '', $assignedBns['middle_name'] ?? '', $assignedBns['last_name'] ?? '', $assignedBns['suffix'] ?? '') : 'None';
$currentAgeMonths = $child ? computeAgeInMonths($child['birthdate'] ?? '', date('Y-m-d')) : null;
$latestMeasurement = $records ? $records[0]['measurement_date'] : null;
$highlightProfile  = isset($_GET['highlight']) && $_GET['highlight'] === '1';

// Latest record stats for hero cards
$latestRecord = $records ? $records[0] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($childName ?: 'Child Profile') ?> — Child Profile</title>
    <link rel="stylesheet" href="css/view_child_profile.css">
    <link rel="stylesheet" href="css/growth-status.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<main class="main-content">

    <!-- PAGE HEADER -->
    <header class="page-header">
        <?php
            $isArchivedStatus = $child && in_array($child['status'] ?? '', ['Archive', 'Disease', 'OverAge']);
            $backUrl = $isArchivedStatus ? 'archive_children.php' : 'child_profiles.php';
        ?>
        <a class="back-btn" href="<?= $backUrl ?>">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Back to <?= $isArchivedStatus ? 'Archive' : 'Child' ?> List
        </a>
        <div class="page-title-row">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div class="page-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                    </svg>
                </div>
                <div>
                    <h1 class="page-title">Child Profile</h1>
                    <p class="page-sub">Growth history &amp; nutrition status overview</p>
                </div>
            </div>
            <?php if ($child): ?>
            <div class="header-assigned-bns" title="Assigned Barangay Nutrition Scholar">
                <div class="bns-avatar">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                </div>
                <div class="bns-details">
                    <span class="label">Assigned BNS</span>
                    <span class="val"><?= htmlspecialchars($assignedBnsName) ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </header>



    <?php if ($errorMessage): ?>
        <div class="alert-box">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= htmlspecialchars($errorMessage) ?>
        </div>
    <?php elseif ($child): ?>

    <!-- PROFILE HERO -->
    <div class="profile-hero<?= $highlightProfile ? ' profile-highlight' : '' ?>" id="childProfileCard">
        <div class="hero-identity">
            <div class="avatar">
                <span class="avatar-letter"><?= htmlspecialchars(mb_strtoupper(mb_substr($child['first_name'] ?? 'C', 0, 1))) ?></span>
                <div class="avatar-sex-badge <?= strtolower($child['sex'] ?? '') === 'male' ? 'badge-blue' : 'badge-pink' ?>">
                    <?= strtolower($child['sex'] ?? '') === 'male' ? '♂' : '♀' ?>
                </div>
            </div>
            <div class="hero-name-block">
                <h2 class="hero-name"><?= htmlspecialchars($childName ?: 'Unknown Child') ?></h2>
                <div class="hero-meta-row">
                    <?php if ($child['barangay_name']): ?>
                    <span class="hero-tag">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M21 10c0 6-9 13-9 13S3 16 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <?= htmlspecialchars($child['barangay_name']) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($currentAgeMonths !== null): ?>
                    <span class="hero-tag">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?= $currentAgeMonths ?> months old
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($child['is_ip']) && strtolower($child['is_ip']) !== 'no'): ?>
                    <span class="hero-tag tag-ip">IP</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="hero-info-grid">
            <div class="info-cell">
                <div class="info-label">Birthdate</div>
                <div class="info-val"><?= htmlspecialchars($child['birthdate'] ?? 'N/A') ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Guardian</div>
                <div class="info-val"><?= htmlspecialchars($guardianName ?: 'N/A') ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Relationship</div>
                <div class="info-val"><?= htmlspecialchars($child['relationship_to_child'] ?? 'N/A') ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Contact</div>
                <div class="info-val"><?= htmlspecialchars($child['contact_number'] ?? 'N/A') ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Total Records</div>
                <div class="info-val"><?= count($records) ?></div>
            </div>
        </div>
    </div>



    <!-- ECCD GRAPH MODAL TRIGGER -->
    <?php if ($records): ?>
    <section class="section-card">
        <div class="section-head">
            <div class="section-title-group">
                <div class="section-dot dot-teal"></div>
                <h2 class="section-title">ECCD Growth Graphs</h2>
            </div>
            <div class="eccd-btn-group">
                <button class="btn-eccd" id="openEccdWfa" type="button">View WFA</button>
                <button class="btn-eccd" id="openEccdHfa" type="button">View HFA</button>
                <button class="btn-eccd" id="openEccdWfl" type="button">View WFL/HT</button>
            </div>
        </div>
        <div class="section-note">
            Open each graph in its own modal view.
        </div>
    </section>
    <?php endif; ?>

    <!-- GROWTH RECORDS TABLE -->
    <section class="section-card">
        <div class="section-head">
            <div class="section-title-group">
                <div class="section-dot dot-blue"></div>
                <h2 class="section-title">Growth Records</h2>
                <div class="sorting-instruction">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    Note: Table is sorted from Recent to Oldest
                </div>
            </div>
            <span class="record-count"><?= count($records) ?> record<?= count($records) !== 1 ? 's' : '' ?></span>
        </div>

        <?php if ($records): ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Age (mo.)</th>
                        <th>Height (cm)</th>
                        <th>Weight (kg)</th>
                        <th>HFA Status</th>
                        <th>WFA Status</th>
                        <th>WFL/HT Status</th>
                        <th>MUAC (cm)</th>
                        <th>MUAC Status</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $i => $rec): ?>
                    <tr class="record-row" style="animation-delay: <?= $i * 0.04 ?>s">
                        <td class="td-date">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <?= htmlspecialchars($rec['measurement_date']) ?>
                        </td>
                        <td><?= $rec['age_in_months'] !== null ? htmlspecialchars((string)$rec['age_in_months']) : '—' ?></td>
                        <td class="td-measure"><?= htmlspecialchars(number_format((float)$rec['height'], 1)) ?></td>
                        <td class="td-measure"><?= htmlspecialchars(number_format((float)$rec['weight'], 1)) ?></td>
                        <td class="vcp-status-cell <?= status_cell_class_vcp($rec['height_for_age_status']) ?>" title="<?= htmlspecialchars($rec['height_for_age_status']) ?>"><?= htmlspecialchars(status_abbrev($rec['height_for_age_status'])) ?></td>
                        <td class="vcp-status-cell <?= status_cell_class_vcp($rec['weight_for_age_status']) ?>" title="<?= htmlspecialchars($rec['weight_for_age_status']) ?>"><?= htmlspecialchars(status_abbrev($rec['weight_for_age_status'])) ?></td>
                        <td class="vcp-status-cell <?= status_cell_class_vcp($rec['weight_for_ltht_status']) ?>" title="<?= htmlspecialchars($rec['weight_for_ltht_status']) ?>"><?= htmlspecialchars(status_abbrev($rec['weight_for_ltht_status'])) ?></td>
                        <td class="td-measure"><?= $rec['muac_measurement'] > 0 ? htmlspecialchars(number_format((float)$rec['muac_measurement'], 1)) : '—' ?></td>
                        <td class="vcp-status-cell <?= status_cell_class_vcp($rec['muac_status'] ?? 'N/A') ?>" title="<?= htmlspecialchars($rec['muac_status'] ?? 'N/A') ?>"><?= htmlspecialchars(status_abbrev($rec['muac_status'] ?? 'N/A')) ?></td>
                        <td class="td-recorder"><?= htmlspecialchars($rec['recorded_by_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-records">
            <div class="empty-icon">📋</div>
            <p class="empty-title">No records yet</p>
            <p class="empty-sub">No growth records have been added for this child.</p>
        </div>
        <?php endif; ?>
    </section>

    <!-- INTERVENTIONS TABLE -->
    <section class="section-card">
        <div class="section-head">
            <div class="section-title-group">
                <div class="section-dot dot-teal"></div>
                <h2 class="section-title">Interventions</h2>
            </div>
            <span class="record-count"><?= count($interventions) ?> record<?= count($interventions) !== 1 ? 's' : '' ?></span>
        </div>

        <?php if ($interventions): ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Description / Notes</th>
                        <th>Give Out Items</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($interventions as $i => $intervention): ?>
                    <?php
                        $itemsText = !empty($intervention['items']) ? implode(', ', $intervention['items']) : '—';
                        $descText = trim((string)($intervention['description'] ?? ''));
                    ?>
                    <tr class="record-row" style="animation-delay: <?= $i * 0.04 ?>s">
                        <td class="td-date">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <?= htmlspecialchars($intervention['intervention_date']) ?>
                        </td>
                        <td><?= htmlspecialchars($intervention['type_name'] ?? '—') ?></td>
                        <td><?= $descText !== '' ? htmlspecialchars($descText) : 'No description' ?></td>
                        <td><?= htmlspecialchars($itemsText) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-records">
            <div class="empty-icon">🧾</div>
            <p class="empty-title">No interventions yet</p>
            <p class="empty-sub">No intervention records have been added for this child.</p>
        </div>
        <?php endif; ?>
    </section>

    <?php endif; ?>
</main>

<?php if ($records): ?>
    <div id="eccdModalWfa" class="eccd-modal" aria-hidden="true">
        <div class="eccd-backdrop" id="eccdBackdropWfa"></div>
        <div class="eccd-modal-box" role="dialog" aria-modal="true" aria-labelledby="eccdModalTitleWfa">
            <div class="eccd-modal-head">
                <div>
                    <h3 id="eccdModalTitleWfa">Weight-for-Age (WFA)</h3>
                    <p>Reference curves with the child's measurements overlayed.</p>
                </div>
                <button class="eccd-close" id="closeEccdWfa" type="button" aria-label="Close">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div class="charts-grid eccd-charts">
                <div class="chart-panel">
                    <div class="chart-label">
                        <span class="chart-dot" style="background:#16a34a"></span>
                        Weight-for-Age (kg)
                    </div>
                    <div class="chart-wrap"><canvas id="wfaEccdChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div id="eccdModalHfa" class="eccd-modal" aria-hidden="true">
        <div class="eccd-backdrop" id="eccdBackdropHfa"></div>
        <div class="eccd-modal-box" role="dialog" aria-modal="true" aria-labelledby="eccdModalTitleHfa">
            <div class="eccd-modal-head">
                <div>
                    <h3 id="eccdModalTitleHfa">Height-for-Age (HFA)</h3>
                    <p>Reference curves with the child's measurements overlayed.</p>
                </div>
                <button class="eccd-close" id="closeEccdHfa" type="button" aria-label="Close">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div class="charts-grid eccd-charts">
                <div class="chart-panel">
                    <div class="chart-label">
                        <span class="chart-dot" style="background:#2563eb"></span>
                        Height-for-Age (cm)
                    </div>
                    <div class="chart-wrap"><canvas id="hfaEccdChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div id="eccdModalWfl" class="eccd-modal" aria-hidden="true">
        <div class="eccd-backdrop" id="eccdBackdropWfl"></div>
        <div class="eccd-modal-box" role="dialog" aria-modal="true" aria-labelledby="eccdModalTitleWfl">
            <div class="eccd-modal-head">
                <div>
                    <h3 id="eccdModalTitleWfl">Weight-for-Length/Height (WFL/HT)</h3>
                    <p>Reference curves with the child's measurements overlayed.</p>
                </div>
                <button class="eccd-close" id="closeEccdWfl" type="button" aria-label="Close">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div class="charts-grid eccd-charts">
                <div class="chart-panel">
                    <div class="chart-label">
                        <span class="chart-dot" style="background:#f59e0b"></span>
                        Weight-for-Length/Height (kg vs cm)
                    </div>
                    <div class="chart-wrap"><canvas id="wflEccdChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($records): ?>
<script>
window.childRecords = <?= json_encode($records) ?>;
window.eccdRefs = <?= json_encode([
    'wfa' => $wfaRefs,
    'hfa' => $hfaRefs,
    'wfl' => $wflRefs,
    'wflAgeGroup' => $wflAgeGroup
]) ?>;
window.childSex = <?= json_encode(strtolower($child['sex'] ?? '')) ?>;
</script>
<script src="javascript/view_child_profile.js"></script>
<?php endif; ?>
</body>
</html>