<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();

require_once __DIR__ . '/database.php';

$roleRaw = $_SESSION['role'] ?? '';
$roleRaw = trim($roleRaw);
$isBnsDash = ($roleRaw === 'Barangay Nutrition Scholars');
$currentUserIdDash = (int)($_SESSION['user_id'] ?? 0);
$displayName = trim($_SESSION['full_name'] ?? '');
if ($displayName === '') {
    $nameParts = array_filter([
        $_SESSION['first_name'] ?? '',
        $_SESSION['middle_name'] ?? '',
        $_SESSION['last_name'] ?? '',
    ]);
    $displayName = trim(implode(' ', $nameParts));
}
if ($displayName === '') {
    $displayName = 'User';
}
$canViewUserAndOperationalOverview = ($roleRaw === 'Admin');
$canViewBarangaysCovered = ($roleRaw === 'Admin' || $roleRaw === 'Health Worker');

$stats = [
    'children'     => 0,
    'active'       => 0,
    'archived'     => 0,
    'measurements' => 0,
    'barangays'    => 0,
    'users'        => 0,
    'ip_count'     => 0,
    'male'         => 0,
    'female'       => 0,
];

$userBarangayId = $_SESSION['barangay_id'] ?? null;
$userBarangayName = '';

$barangay_chart_data = [];
$barangay_chart_labels = [];
$recent_activities = [];

$gender_barangay_labels = [];
$gender_barangay_male = [];
$gender_barangay_female = [];

$inventory_alerts = [];
$recent_interventions = [];

$wfa_by_barangay = [];
$hfa_by_barangay = [];
$wflh_by_barangay = [];
$muac_by_barangay = [];

require_once 'growth_utils.php';

if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno === 0) {
    if ($userBarangayId) {
        $bn_sql = "SELECT barangay_name FROM barangays WHERE barangay_id = $userBarangayId";
        if ($bn_res = $conn->query($bn_sql)) {
            $bn_row = $bn_res->fetch_assoc();
            $userBarangayName = $bn_row['barangay_name'] ?? '';
        }
    }

    if ($roleRaw === 'Health Worker' && $userBarangayId > 0) {
        // Health Worker: scope counts to all children in their assigned barangay
        $scoped_queries = [
            'children' => "SELECT COUNT(*) FROM children WHERE barangay_id = $userBarangayId AND status = 'Active'",
            'active'   => "SELECT COUNT(*) FROM children WHERE barangay_id = $userBarangayId AND status = 'Active'",
            'ip_count' => "SELECT COUNT(*) FROM children WHERE barangay_id = $userBarangayId AND status = 'Active' AND is_ip = 'Yes'",
            'male'     => "SELECT COUNT(*) FROM children WHERE barangay_id = $userBarangayId AND status = 'Active' AND sex = 'Male'",
            'female'   => "SELECT COUNT(*) FROM children WHERE barangay_id = $userBarangayId AND status = 'Active' AND sex = 'Female'",
        ];
        foreach ($scoped_queries as $key => $sql) {
            if ($result = $conn->query($sql)) {
                $row = $result->fetch_row();
                $stats[$key] = $row ? (int)$row[0] : 0;
            }
        }
        $stats['barangays'] = 1; // They only cover their own
    } elseif ($isBnsDash && $currentUserIdDash > 0) {
        // BNS: scope counts to children they have recorded
        $scoped_queries = [
            'children' => "SELECT COUNT(DISTINCT c.child_id) FROM children c JOIN growth_records gr ON gr.child_id = c.child_id WHERE gr.recorded_by = $currentUserIdDash AND c.status = 'Active'",
            'active'   => "SELECT COUNT(DISTINCT c.child_id) FROM children c JOIN growth_records gr ON gr.child_id = c.child_id WHERE gr.recorded_by = $currentUserIdDash AND c.status = 'Active'",
            'ip_count' => "SELECT COUNT(DISTINCT c.child_id) FROM children c JOIN growth_records gr ON gr.child_id = c.child_id WHERE gr.recorded_by = $currentUserIdDash AND c.status = 'Active' AND c.is_ip = 'Yes'",
            'male'     => "SELECT COUNT(DISTINCT c.child_id) FROM children c JOIN growth_records gr ON gr.child_id = c.child_id WHERE gr.recorded_by = $currentUserIdDash AND c.status = 'Active' AND c.sex = 'Male'",
            'female'   => "SELECT COUNT(DISTINCT c.child_id) FROM children c JOIN growth_records gr ON gr.child_id = c.child_id WHERE gr.recorded_by = $currentUserIdDash AND c.status = 'Active' AND c.sex = 'Female'",
        ];
        foreach ($scoped_queries as $key => $sql) {
            if ($result = $conn->query($sql)) {
                $row = $result->fetch_row();
                $stats[$key] = $row ? (int)$row[0] : 0;
            }
        }
        // For barangays, count unique barangays they have records in
        $b_count_sql = "SELECT COUNT(DISTINCT c.barangay_id) FROM children c JOIN growth_records gr ON gr.child_id = c.child_id WHERE gr.recorded_by = $currentUserIdDash";
        if ($b_res = $conn->query($b_count_sql)) {
            $b_row = $b_res->fetch_row();
            $stats['barangays'] = $b_row ? (int)$b_row[0] : 0;
        }
    } else {
        $queries = [
            'children' => "SELECT COUNT(*) FROM children WHERE status = 'Active'",
            'active'   => "SELECT COUNT(*) FROM children WHERE status = 'Active'",
            'ip_count' => "SELECT COUNT(*) FROM children WHERE status = 'Active' AND is_ip = 'Yes'",
            'measurements' => "SELECT COUNT(*) FROM growth_records",
            'barangays'    => "SELECT COUNT(*) FROM barangays",
            'users'        => "SELECT COUNT(*) FROM users",
            'male'         => "SELECT COUNT(*) FROM children WHERE status = 'Active' AND sex = 'Male'",
            'female'       => "SELECT COUNT(*) FROM children WHERE status = 'Active' AND sex = 'Female'",
        ];
        foreach ($queries as $key => $sql) {
            if ($result = $conn->query($sql)) {
                $row = $result->fetch_row();
                $stats[$key] = $row ? (int)$row[0] : 0;
            }
        }
    }

    // Chart Data: Barangay
    $chart_sql = "SELECT b.barangay_name, COUNT(DISTINCT c.child_id) as count 
                  FROM children c 
                  JOIN barangays b ON c.barangay_id = b.barangay_id 
                  JOIN growth_records gr ON gr.child_id = c.child_id
                  WHERE c.status = 'Active'";
    if ($roleRaw === 'Health Worker' && $userBarangayId > 0) {
        $chart_sql .= " AND c.barangay_id = $userBarangayId";
    } elseif ($isBnsDash && $currentUserIdDash > 0) {
        $chart_sql .= " AND gr.recorded_by = $currentUserIdDash";
    }
    $chart_sql .= " GROUP BY b.barangay_id";

    if ($chart_result = $conn->query($chart_sql)) {
        while ($r = $chart_result->fetch_assoc()) {
            $barangay_chart_labels[] = $r['barangay_name'];
            $barangay_chart_data[] = (int)$r['count'];
        }
    }

    // Chart Data: Demographics per Barangay
    $gender_barangay_labels = [];
    $gender_barangay_male = [];
    $gender_barangay_female = [];
    $gb_sql = "SELECT b.barangay_name, 
                      COUNT(DISTINCT CASE WHEN c.sex = 'Male' THEN c.child_id END) as male_count, 
                      COUNT(DISTINCT CASE WHEN c.sex = 'Female' THEN c.child_id END) as female_count 
               FROM children c 
               JOIN barangays b ON c.barangay_id = b.barangay_id 
               JOIN growth_records gr ON gr.child_id = c.child_id
               WHERE c.status = 'Active'";
    if ($roleRaw === 'Health Worker' && $userBarangayId > 0) {
        $gb_sql .= " AND c.barangay_id = $userBarangayId";
    } elseif ($isBnsDash && $currentUserIdDash > 0) {
        $gb_sql .= " AND gr.recorded_by = $currentUserIdDash";
    }
    $gb_sql .= " GROUP BY b.barangay_id";
    if ($res = $conn->query($gb_sql)) {
        while ($r = $res->fetch_assoc()) {
            $gender_barangay_labels[] = $r['barangay_name'];
            $gender_barangay_male[] = (int)$r['male_count'];
            $gender_barangay_female[] = (int)$r['female_count'];
        }
    }

    // Inventory Alerts
    $inv_sql = "SELECT item_name, quantity, expiration_date FROM inventory WHERE quantity <= 20 OR expiration_date <= DATE_ADD(NOW(), INTERVAL 30 DAY) ORDER BY quantity ASC, expiration_date ASC LIMIT 4";
    if ($res = $conn->query($inv_sql)) {
        while ($r = $res->fetch_assoc()) {
            $inventory_alerts[] = $r;
        }
    }

    // Recent Interventions
    $interv_sql = "SELECT c.first_name, c.last_name, i.intervention_date, i.description FROM interventions i JOIN children c ON i.child_id = c.child_id ORDER BY i.intervention_date DESC LIMIT 4";
    if ($res = $conn->query($interv_sql)) {
        while ($r = $res->fetch_assoc()) {
            $recent_interventions[] = $r;
        }
    }

    // Recent Activity Data
    $activity_sql = "SELECT u.user_id, a.activity_type, a.activity_time FROM user_activity_log a JOIN users u ON a.user_id = u.user_id ORDER BY a.activity_time DESC LIMIT 4";
    if ($activity_result = $conn->query($activity_sql)) {
        while ($r = $activity_result->fetch_assoc()) {
            $recent_activities[] = $r;
        }
    }

    // Nutritional Status Counts — mirrors child_profiles.php measurement-session cutoff logic
    $wfa_counts  = ['Normal' => 0, 'Underweight' => 0, 'Severely Underweight' => 0, 'Overweight' => 0];
    $hfa_counts  = ['Normal' => 0, 'Stunted' => 0, 'Severely Stunted' => 0, 'Tall' => 0];
    $wflh_counts = ['Normal' => 0, 'Wasted' => 0, 'Severely Wasted' => 0, 'Overweight' => 0, 'Obese' => 0];
    $muac_counts = ['Normal' => 0, 'Moderately Wasted' => 0, 'Severely Wasted' => 0];

    // Read the cutoff record_id set by "New Measurement Period"
    $sessionFile = __DIR__ . '/measurement_session.txt';
    $cutoffRecordId = file_exists($sessionFile)
        ? (int)trim(file_get_contents($sessionFile))
        : 0;

    // Only treat the cutoff as active if the session file is from the current month
    // and a clear_measurements activity exists in the current month.
    $periodIsNew = false;
    if (file_exists($sessionFile)) {
        clearstatcache(true, $sessionFile);
        $mtime = filemtime($sessionFile);
        if (date('Y-m', $mtime) === date('Y-m')) {
            $hasClearThisMonth = false;
            if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno === 0) {
                $clearSql = "SELECT 1 FROM user_activity_log WHERE activity_type = 'clear_measurements' AND DATE_FORMAT(activity_time, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m') LIMIT 1";
                if ($clearRes = $conn->query($clearSql)) {
                    $hasClearThisMonth = ($clearRes->num_rows > 0);
                }
            }

            if ($hasClearThisMonth) {
                $periodIsNew = true;
            } else {
                $cutoffRecordId = 0;
            }
        } else {
            $cutoffRecordId = 0;
        }
    }

    // Safeguard: If the cutoff is greater than the max record_id, the database was likely reset.
    if ($cutoffRecordId > 0 && isset($conn) && $conn instanceof mysqli && $conn->connect_errno === 0) {
        $maxQuery = $conn->query("SELECT MAX(record_id) FROM growth_records");
        if ($maxQuery) {
            $maxRow = $maxQuery->fetch_row();
            $maxId = $maxRow ? (int)$maxRow[0] : 0;
            if ($cutoffRecordId > $maxId) {
                $cutoffRecordId = 0;
            }
        }
    }

    // Count how many children have been measured in the current period (after cutoff)
    // For BNS: scope to children they recorded; for others: all active children
    $measuredThisPeriod = 0;
    $totalActiveChildren = $stats['active'];

    if ($roleRaw === 'Health Worker' && $userBarangayId > 0) {
        // Health Worker scope: all children in their barangay
        if ($cutoffRecordId > 0) {
            $mtp_sql = "SELECT COUNT(DISTINCT gr.child_id) FROM growth_records gr JOIN children c ON gr.child_id = c.child_id WHERE gr.record_id > $cutoffRecordId AND c.barangay_id = $userBarangayId AND gr.is_muac_only = 0 AND c.status = 'Active'";
        } else {
            $mtp_sql = "SELECT COUNT(DISTINCT child_id) FROM children WHERE barangay_id = $userBarangayId AND status = 'Active'";
        }
    } elseif ($isBnsDash && $currentUserIdDash > 0) {
        // Recorded by scope: only children the user added
        if ($cutoffRecordId > 0) {
            $mtp_sql = "SELECT COUNT(DISTINCT gr.child_id) FROM growth_records gr JOIN children c ON gr.child_id = c.child_id WHERE gr.record_id > $cutoffRecordId AND gr.recorded_by = $currentUserIdDash AND gr.is_muac_only = 0 AND c.status = 'Active'";
        } else {
            $mtp_sql = "SELECT COUNT(DISTINCT gr.child_id) FROM growth_records gr JOIN children c ON gr.child_id = c.child_id WHERE gr.recorded_by = $currentUserIdDash AND gr.is_muac_only = 0 AND c.status = 'Active'";
        }
    } else {
        if ($cutoffRecordId > 0) {
            $mtp_sql = "SELECT COUNT(DISTINCT gr.child_id) FROM growth_records gr JOIN children c ON gr.child_id = c.child_id WHERE gr.record_id > $cutoffRecordId AND gr.is_muac_only = 0 AND c.status = 'Active'";
        } else {
            $mtp_sql = "SELECT COUNT(DISTINCT gr.child_id) FROM growth_records gr JOIN children c ON gr.child_id = c.child_id WHERE gr.is_muac_only = 0 AND c.status = 'Active'";
        }
    }
    if ($mtp_res = $conn->query($mtp_sql)) {
        $mtp_row = $mtp_res->fetch_row();
        $measuredThisPeriod = $mtp_row ? (int)$mtp_row[0] : 0;
    }
    $unmeasuredThisPeriod = max(0, $totalActiveChildren - $measuredThisPeriod);

    // Nutritional status counts — scoped to BNS/Health Worker recorded data or all active children
    // Now mirrors the logic in child_profiles.php: honors the measurement period cutoff if set.
    $status_where = "WHERE c.status = 'Active'";
    if ($roleRaw === 'Health Worker' && $userBarangayId > 0) {
        $status_where .= " AND c.barangay_id = $userBarangayId";
    } elseif ($isBnsDash && $currentUserIdDash > 0) {
        $status_where .= " AND EXISTS (SELECT 1 FROM growth_records grb WHERE grb.child_id = c.child_id AND grb.recorded_by = $currentUserIdDash)";
    }

    $subquery_where = "";
    if ($cutoffRecordId > 0) {
        $subquery_where = "WHERE record_id > $cutoffRecordId";
    }

    $status_sql = "
        SELECT 
            c.birthdate, g.measurement_date, c.barangay_id, b.barangay_name,
            g.record_id, g.weight, g.height, g.muac_measurement,
            g.weight_id, g.height_id, g.wfl_id,
            wfa.severely_underweight_max, wfa.underweight_min, wfa.underweight_max, wfa.normal_min, wfa.normal_max, wfa.overweight,
            hfa.severely_stunted, hfa.stunted_from, hfa.stunted_to, hfa.normal_from, hfa.normal_to, hfa.tall,
            wfl.severely_wasted, wfl.wasted_from, wfl.wasted_to, wfl.normal_from, wfl.normal_to, wfl.overweight_from, wfl.overweight_to, wfl.obese
        FROM children c
        JOIN barangays b ON c.barangay_id = b.barangay_id
        JOIN (
            SELECT child_id, record_id, weight, height, muac_measurement, measurement_date, weight_id, height_id, wfl_id,
                   ROW_NUMBER() OVER(PARTITION BY child_id ORDER BY measurement_date DESC, record_id DESC) as rn
            FROM growth_records
            $subquery_where
        ) g ON c.child_id = g.child_id AND g.rn = 1
        LEFT JOIN weight_for_age wfa ON g.weight_id = wfa.weight_id
        LEFT JOIN height_for_age hfa ON g.height_id = hfa.height_id
        LEFT JOIN weight_for_length wfl ON g.wfl_id = wfl.wfl_id
        $status_where
    ";

    if ($status_res = $conn->query($status_sql)) {
        while ($row = $status_res->fetch_assoc()) {
            $hasWeight = !empty($row['weight']) && (float)$row['weight'] > 0;
            $hasHeight = !empty($row['height']) && (float)$row['height'] > 0;
            $hasMuac = isset($row['muac_measurement']) && (float)$row['muac_measurement'] > 0;

            if (!$hasWeight && !$hasHeight && !$hasMuac) continue;

            // Age filtering: Only include children aged 0-59 months (4 years and 11 months)
            if (!empty($row['birthdate']) && !empty($row['measurement_date'])) {
                try {
                    $b = new DateTime($row['birthdate']);
                    $m = new DateTime($row['measurement_date']);
                    if ($m >= $b) {
                        $diff = $b->diff($m);
                        $ageMonths = ($diff->y * 12) + $diff->m;
                        if ($ageMonths < 0 || $ageMonths > 59) continue;
                    } else {
                        continue; // Measured before birth?
                    }
                } catch (Exception $e) {
                    continue;
                }
            } else {
                continue;
            }

            $bName = $row['barangay_name'] ?? 'Unknown';
            if (!isset($wfa_by_barangay[$bName])) {
                $wfa_by_barangay[$bName]  = ['Normal' => 0, 'Underweight' => 0, 'Severely Underweight' => 0, 'Overweight' => 0];
                $hfa_by_barangay[$bName]  = ['Normal' => 0, 'Stunted' => 0, 'Severely Stunted' => 0, 'Tall' => 0];
                $wflh_by_barangay[$bName] = ['Normal' => 0, 'Wasted' => 0, 'Severely Wasted' => 0, 'Overweight' => 0, 'Obese' => 0];
                $muac_by_barangay[$bName] = ['Normal' => 0, 'Moderately Wasted' => 0, 'Severely Wasted' => 0];
            }

            if ($hasWeight && $row['severely_underweight_max'] !== null) {
                $stat = determineWeightForAgeStatus((float)$row['weight'], $row);
                if ($stat && isset($wfa_by_barangay[$bName][$stat])) {
                    $wfa_by_barangay[$bName][$stat]++;
                    $wfa_counts[$stat]++;
                }
            }
            if ($hasHeight && $row['severely_stunted'] !== null) {
                $stat = determineHeightForAgeStatus((float)$row['height'], $row);
                if ($stat && isset($hfa_by_barangay[$bName][$stat])) {
                    $hfa_by_barangay[$bName][$stat]++;
                    $hfa_counts[$stat]++;
                }
            }
            if ($hasWeight && $hasHeight && $row['severely_wasted'] !== null) {
                $stat = determineWeightForLengthStatus((float)$row['weight'], $row);
                if ($stat && isset($wflh_by_barangay[$bName][$stat])) {
                    $wflh_by_barangay[$bName][$stat]++;
                    $wflh_counts[$stat]++;
                }
            }
            if ($hasMuac && isset($ageMonths) && $ageMonths >= 6 && $ageMonths <= 59) {
                $stat = determineMuacStatus((float)$row['muac_measurement']);
                if ($stat && isset($muac_by_barangay[$bName][$stat])) {
                    $muac_by_barangay[$bName][$stat]++;
                    $muac_counts[$stat]++;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — NutriChild</title>
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main class="main-content dashboard-main">

        <!-- Welcome -->
        <div class="welcome-card">
            <div class="welcome-card-body">
                <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <span class="welcome-role"><?= htmlspecialchars($_SESSION['role']) ?></span>
                    <span id="philippineClock" style="font-weight: 700; color: var(--red-500); background: rgba(224, 82, 82, 0.08); padding: 6px 12px; border-radius: 999px; border: 1px solid rgba(224, 82, 82, 0.2); font-size: 0.85rem; letter-spacing: 0.04em;"></span>
                </div>
                <h2>Welcome back, <?= htmlspecialchars($displayName) ?>!</h2>
                <p>
                    <?php if ($userBarangayName): ?>
                        Data overview for your contributions in <strong>Barangay <?= htmlspecialchars($userBarangayName) ?></strong>
                    <?php else: ?>
                        Here is a quick snapshot of the program today.
                    <?php endif; ?>
                </p>
            </div>
            <div class="welcome-icon">
                <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
            </div>
        </div>
        <!-- Stats -->
        <span class="section-label">Key Metrics</span>
        <section class="stats">
            <div class="stat-card">
                <div class="stat-card-top">
                    <span class="stat-card-label">Children Enrolled</span>
                    <div class="stat-card-icon icon-green"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></div>
                </div>
                <h3 class="stat-number"><?= number_format($stats['children']) ?></h3>
                <p class="stat-note">Total child records</p>
            </div>

            <div class="stat-card">
                <div class="stat-card-top">
                    <span class="stat-card-label">Indigenous People</span>
                    <div class="stat-card-icon icon-amber"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                </div>
                <h3 class="stat-number"><?= number_format($stats['ip_count']) ?></h3>
                <p class="stat-note">Active IP children</p>
            </div>

            <?php if ($canViewUserAndOperationalOverview): ?>
            <div class="stat-card">
                <div class="stat-card-top">
                    <span class="stat-card-label">Total Users</span>
                    <div class="stat-card-icon icon-blue"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                </div>
                <h3 class="stat-number"><?= number_format($stats['users']) ?></h3>
                <p class="stat-note">System users</p>
            </div>
            <?php endif; ?>


            <?php if ($canViewBarangaysCovered && !$userBarangayId): ?>
            <div class="stat-card">
                <div class="stat-card-top">
                    <span class="stat-card-label">Barangays Covered</span>
                    <div class="stat-card-icon icon-teal">
                        <svg viewBox="0 0 24 24"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>
                    </div>
                </div>
                <h3 class="stat-number"><?= number_format($stats['barangays']) ?></h3>
                <p class="stat-note">Total locations</p>
            </div>
            <?php elseif ($userBarangayId): ?>
            <div class="stat-card">
                <div class="stat-card-top">
                    <span class="stat-card-label">Assigned Barangay</span>
                    <div class="stat-card-icon icon-teal">
                        <svg viewBox="0 0 24 24"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>
                    </div>
                </div>
                <h3 class="stat-number" style="font-size: 1.2rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($userBarangayName) ?></h3>
                <p class="stat-note">Your coverage area</p>
            </div>
            <?php endif; ?>
        </section>

        <!-- Nutritional Status -->
        <span class="section-label">Nutritional Status Overview</span>

        <?php
        $progressPct = $totalActiveChildren > 0 ? round(($measuredThisPeriod / $totalActiveChildren) * 100) : 0;
        $bannerBg    = $periodIsNew ? 'rgba(26,110,216,0.07)' : 'rgba(46,168,106,0.07)';
        $bannerBorder= $periodIsNew ? 'rgba(26,110,216,0.25)' : 'rgba(46,168,106,0.25)';
        $bannerColor = $periodIsNew ? '#1a4f9c' : '#1e6b3b';
        $barColor    = $periodIsNew ? '#1a6ed8' : '#2ea86a';
        ?>
        <div style="background: <?= $bannerBg ?>; border: 1px solid <?= $bannerBorder ?>; border-radius: 14px; padding: 14px 18px; margin-bottom: 16px; font-family: Arial, Helvetica, sans-serif;">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="<?= $bannerColor ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <div>
                        <strong style="color: <?= $bannerColor ?>; font-size: 0.9rem;">
                            <?php if ($periodIsNew): ?>
                                Current Measurement Period — Data shown is based on children already measured this period.
                            <?php else: ?>
                                All-Time Data — No measurement period has been started yet.
                            <?php endif; ?>
                        </strong>
                        <div style="font-size: 0.82rem; color: #555; margin-top: 3px;">
                            <?php if ($unmeasuredThisPeriod > 0): ?>
                                <strong><?= $measuredThisPeriod ?></strong> of <strong><?= $totalActiveChildren ?></strong> active children measured so far —
                                <span style="color: #c0392b;"><strong><?= $unmeasuredThisPeriod ?></strong> still pending measurement.</span>
                                The status counts below only reflect children who have been measured this period.
                            <?php else: ?>
                                All <strong><?= $totalActiveChildren ?></strong> active children have been measured this period. Status counts are complete.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="font-size: 1rem; font-weight: 700; color: <?= $barColor ?>; white-space: nowrap;">
                    <?= $measuredThisPeriod ?> / <?= $totalActiveChildren ?>
                    <span style="font-size: 0.75rem; font-weight: 500; color: #888; margin-left: 4px;">(<?= $progressPct ?>%)</span>
                </div>
            </div>
            <!-- Progress bar -->
            <div style="margin-top: 10px; height: 6px; border-radius: 99px; background: rgba(0,0,0,0.08); overflow: hidden;">
                <div style="height: 100%; width: <?= $progressPct ?>%; background: <?= $barColor ?>; border-radius: 99px; transition: width 0.4s ease;"></div>
            </div>
        </div>
        <section class="charts-section detailed-charts" style="margin-bottom: 24px;">
            <div class="chart-panel">
                <div class="chart-header">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                        <div class="panel-head-icon" style="color: var(--blue-500); border-color: rgba(26,110,216,0.2); background: rgba(26,110,216,0.08); width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center;"><svg viewBox="0 0 24 24" style="width:16px; height:16px; stroke:currentColor; fill:none; stroke-width:2;"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg></div>
                        <h3 style="margin:0; font-size:1.05rem; font-weight:700;">Weight for Age (WFA) per Barangay</h3>
                    </div>
                    <span class="muted">Distribution of active children's weight classification by location</span>
                </div>
                <div class="chart-container">
                    <?php if (empty($wfa_by_barangay)): ?>
                        <div class="chart-empty">No data available</div>
                    <?php else: ?>
                        <canvas id="wfaChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chart-panel">
                <div class="chart-header">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                        <div class="panel-head-icon" style="color: var(--teal-500); border-color: rgba(42,167,160,0.2); background: rgba(42,167,160,0.08); width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center;"><svg viewBox="0 0 24 24" style="width:16px; height:16px; stroke:currentColor; fill:none; stroke-width:2;"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
                        <h3 style="margin:0; font-size:1.05rem; font-weight:700;">Height for Age (HFA) per Barangay</h3>
                    </div>
                    <span class="muted">Distribution of active children's height classification by location</span>
                </div>
                <div class="chart-container">
                    <?php if (empty($hfa_by_barangay)): ?>
                        <div class="chart-empty">No data available</div>
                    <?php else: ?>
                        <canvas id="hfaChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chart-panel">
                <div class="chart-header">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                        <div class="panel-head-icon" style="color: var(--purple-500); border-color: rgba(107,88,214,0.2); background: rgba(107,88,214,0.08); width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center;"><svg viewBox="0 0 24 24" style="width:16px; height:16px; stroke:currentColor; fill:none; stroke-width:2;"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                        <h3 style="margin:0; font-size:1.05rem; font-weight:700;">Weight for Length (WFL/H) per Barangay</h3>
                    </div>
                    <span class="muted">Distribution of active children's wasting and obesity classification by location</span>
                </div>
                <div class="chart-container">
                    <?php if (empty($wflh_by_barangay)): ?>
                        <div class="chart-empty">No data available</div>
                    <?php else: ?>
                        <canvas id="wflhChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <span class="section-label">Mid-Upper Arm Circumference (MUAC)</span>
        <section class="charts-section detailed-charts" style="margin-bottom: 24px;">
            <div class="chart-panel">
                <div class="chart-header">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                        <div class="panel-head-icon" style="color: #ec4899; border-color: rgba(236,72,153,0.2); background: rgba(236,72,153,0.08); width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px; height:16px;"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg></div>
                        <h3 style="margin:0; font-size:1.05rem; font-weight:700;">Mid-Upper Arm Circumference (MUAC) per Barangay</h3>
                    </div>
                    <span class="muted">Distribution of active children's (6-59 mos) upper arm circumference status by location</span>
                </div>
                <div class="chart-container">
                    <?php if (empty($muac_by_barangay)): ?>
                        <div class="chart-empty">No data available</div>
                    <?php else: ?>
                        <canvas id="muacChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Visual Analytics -->
        <span class="section-label">Data Visualizations</span>
        <section class="charts-section detailed-charts">

            <div class="chart-panel">
                <div class="chart-header">
                    <h3>Demographics <?php if ($userBarangayName) echo 'for ' . htmlspecialchars($userBarangayName); else echo 'per Barangay'; ?></h3>
                    <span class="muted">Distribution by sex <?php if ($userBarangayName) echo 'in your area'; else echo 'and location'; ?></span>
                </div>
                <div class="chart-container">
                    <?php if (empty($gender_barangay_labels)): ?>
                        <div class="chart-empty">No data available</div>
                    <?php else: ?>
                        <canvas id="genderBarangayChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chart-panel">
                <div class="chart-header">
                    <h3>Children <?php if ($userBarangayName) echo 'in ' . htmlspecialchars($userBarangayName); else echo 'per Barangay'; ?></h3>
                    <span class="muted">Distribution of enrolled children</span>
                </div>
                <div class="chart-container">
                    <?php if (empty($barangay_chart_data)): ?>
                        <div class="chart-empty">No data available</div>
                    <?php else: ?>
                        <canvas id="barangayChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($canViewUserAndOperationalOverview): ?>
        <!-- Operational Dashboards -->
        <span class="section-label">Operational Overview</span>
        <section class="panels detailed-panels">
            
            <div class="panel">
                <div class="panel-head">
                    <div class="panel-head-icon icon-amber" style="color: white; background: var(--amber-500); border:none;">
                        <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                    <div>
                        <h3>Inventory Alerts</h3>
                        <span class="muted">Low stock or expiring soon</span>
                    </div>
                </div>
                <div class="activity-feed">
                    <?php if (!empty($inventory_alerts)): ?>
                        <?php foreach($inventory_alerts as $alert): 
                            $isLow = $alert['quantity'] <= 20;
                            $iconClass = $isLow ? 'act-logout' : 'act-amber';
                        ?>
                            <div class="activity-item">
                                <div class="activity-icon <?= $iconClass ?>">
                                    <svg viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                                </div>
                                <div class="activity-details">
                                    <span class="act-user"><?= htmlspecialchars($alert['item_name']) ?></span>
                                    <span class="act-type">Qty: <?= htmlspecialchars($alert['quantity']) ?></span>
                                    <span class="act-time">Expires: <?= $alert['expiration_date'] ? date('M d, Y', strtotime($alert['expiration_date'])) : 'N/A' ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="muted">No alerts at this time.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div class="panel-head-icon icon-blue" style="color: white; background: var(--blue-500); border:none;">
                        <svg viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                    </div>
                    <div>
                        <h3>Recent Interventions</h3>
                        <span class="muted">Latest nutritional support logged</span>
                    </div>
                </div>
                <div class="activity-feed">
                    <?php if (!empty($recent_interventions)): ?>
                        <?php foreach($recent_interventions as $intv): ?>
                            <div class="activity-item">
                                <div class="activity-icon act-login">
                                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </div>
                                <div class="activity-details" style="width:100%;">
                                    <span class="act-user"><?= htmlspecialchars($intv['first_name'] . ' ' . $intv['last_name']) ?></span>
                                    <span class="act-type" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px; display: inline-block;"><?= htmlspecialchars($intv['description']) ?></span>
                                    <span class="act-time"><?= date('M d, Y', strtotime($intv['intervention_date'])) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="muted">No recent interventions.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div class="panel-head-icon">
                        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <div>
                        <h3>Recent Activity</h3>
                        <span class="muted">Latest user interactions</span>
                    </div>
                </div>
                <div class="activity-feed">
                    <?php if (!empty($recent_activities)): ?>
                        <?php foreach($recent_activities as $act): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?= $act['activity_type'] === 'login' ? 'act-login' : 'act-logout' ?>">
                                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                                </div>
                                <div class="activity-details">
                                    <span class="act-user"><?= htmlspecialchars(str_pad((string)$act['user_id'], 6, '0', STR_PAD_LEFT)) ?></span>
                                    <span class="act-type"><?= htmlspecialchars(ucfirst($act['activity_type'])) ?></span>
                                    <span class="act-time"><?= date('M d, h:i A', strtotime($act['activity_time'])) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="muted">No recent activity.</p>
                    <?php endif; ?>
                </div>
            </div>

        </section>
        <?php endif; ?>

    </main>
    
    <script>
        window.barangayLabels = <?= json_encode($barangay_chart_labels) ?>;
        window.barangayData = <?= json_encode($barangay_chart_data) ?>;
        window.genderBarangayLabels = <?= json_encode($gender_barangay_labels) ?>;
        window.genderBarangayMale = <?= json_encode($gender_barangay_male) ?>;
        window.genderBarangayFemale = <?= json_encode($gender_barangay_female) ?>;

        window.wfaByBarangay = <?= json_encode($wfa_by_barangay) ?>;
        window.hfaByBarangay = <?= json_encode($hfa_by_barangay) ?>;
        window.wflhByBarangay = <?= json_encode($wflh_by_barangay) ?>;
        window.muacByBarangay = <?= json_encode($muac_by_barangay) ?>;
    </script>
    <script src="javascript/dashboard.js"></script>

</body>
</html>