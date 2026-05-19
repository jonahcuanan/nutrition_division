<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();
$current_role = $_SESSION['role'] ?? '';
$assigned_barangay_id = isset($_SESSION['barangay_id']) ? (int)$_SESSION['barangay_id'] : null;
$limit_barangay = in_array($current_role, ['Barangay Nutrition Scholars', 'Health Worker'], true);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/growth_utils.php';
require_once __DIR__ . '/activity_logger.php';

$successMessage = '';
$errorMessage = '';
$createdChildId = null;

function normalize_name_input($value) {
    $normalized = strtoupper(trim($value));
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return $normalized;
}

function normalize_name_key($value) {
    $normalized = normalize_name_input($value);
    return preg_replace('/\s+/', '', $normalized);
}

$isAjaxRequest = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || (
    !empty($_SERVER['HTTP_ACCEPT'])
    && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
);

// ──────────────────────────────────────────────────────────────────
// Inline AJAX: Fetch active BNS users for the User Selection Modal.
// Only BNS (Barangay Nutrition Scholars) can be designated for a child.
// Triggered by POST { action: 'get_barangay_users', barangay_id: N }
// ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_barangay_users') {
    header('Content-Type: application/json; charset=UTF-8');
    $bid = isset($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : 0;
    if ($bid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid barangay.', 'users' => []]);
        exit;
    }
    $ustmt = $conn->prepare(
        "SELECT user_id, first_name, middle_name, last_name, suffix, role
         FROM users
         WHERE barangay_id = ?
           AND status = 'Active'
           AND role = 'Barangay Nutrition Scholars'
         ORDER BY last_name ASC, first_name ASC"
    );
    if (!$ustmt) {
        echo json_encode(['success' => false, 'message' => 'Database error.', 'users' => []]);
        exit;
    }
    $ustmt->bind_param('i', $bid);
    $ustmt->execute();
    $ures = $ustmt->get_result();
    $ulist = [];
    while ($urow = $ures->fetch_assoc()) {
        $parts = array_filter([
            trim($urow['first_name'] ?? ''),
            trim($urow['middle_name'] ?? ''),
            trim($urow['last_name'] ?? ''),
        ]);
        $fullName = implode(' ', $parts);
        $sfx = trim($urow['suffix'] ?? '');
        if ($sfx !== '') $fullName .= ' ' . $sfx;
        $ulist[] = [
            'user_id'   => (int)$urow['user_id'],
            'full_name' => $fullName,
            'role'      => $urow['role'],
        ];
    }
    $ustmt->close();
    echo json_encode(['success' => true, 'users' => $ulist]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Convert all relevant fields to uppercase for storage
    $first_name   = normalize_name_input($_POST['first_name'] ?? '');
    $middle_name  = normalize_name_input($_POST['middle_name'] ?? '');
    $last_name    = normalize_name_input($_POST['last_name'] ?? '');
    $suffix       = normalize_name_input($_POST['suffix'] ?? '');
    $sex          = strtoupper($_POST['sex'] ?? '');
    $is_ip        = strtoupper($_POST['is_ip'] ?? 'No');
    $birthdate    = $_POST['birthdate'] ?? '';
    $measurement_date = $_POST['measurement_date'] ?? '';
    $age_in_months_raw = $_POST['age_in_months'] ?? '';
    $address      = strtoupper(trim($_POST['address'] ?? ''));
    $barangay_id_raw   = $_POST['barangay_id'] ?? '';
    $guardian_id_raw   = $_POST['guardian_id'] ?? '';

    $guardian_first_name  = normalize_name_input($_POST['guardian_first_name'] ?? '');
    $guardian_middle_name = normalize_name_input($_POST['guardian_middle_name'] ?? '');
    $guardian_last_name   = normalize_name_input($_POST['guardian_last_name'] ?? '');
    $guardian_suffix      = normalize_name_input($_POST['guardian_suffix'] ?? '');
    $guardian_relationship = strtoupper($_POST['relationship_to_child'] ?? '');
    $guardian_contact     = strtoupper(trim($_POST['contact_number'] ?? ''));
    $guardian_address     = strtoupper(trim($_POST['guardian_address'] ?? ''));

    // Initial growth measurement inputs
    $height_raw = $_POST['height'] ?? '';
    $weight_raw = $_POST['weight'] ?? '';

    if ($first_name === '' || $last_name === '' || $sex === '' || $birthdate === '' || $measurement_date === '') {
        $errorMessage = 'First name, last name, sex, birthdate, and measurement date are required.';
    } else {
        $age_in_months = null;
        if ($limit_barangay) {
            if ($assigned_barangay_id === null) {
                $errorMessage = 'Barangay assignment is missing. Please contact an administrator.';
            }
            $barangay_id = $assigned_barangay_id;
        } else {
            $barangay_id   = ($barangay_id_raw !== '') ? (int)$barangay_id_raw : null;
        }

        try {
            $birthDateObj = new DateTime($birthdate);
            $measureDateObj = new DateTime($measurement_date);
        } catch (Exception $e) {
            $errorMessage = 'Invalid date provided.';
        }

        if ($errorMessage === '') {
            if ($address === '') {
                $errorMessage = 'Complete address is required.';
            } elseif (preg_match('/^PUROK\s*$/', $address)) {
                $errorMessage = 'Please enter a complete address (add details after Purok).';
            }
        }

        if ($errorMessage === '') {
            if ($measureDateObj < $birthDateObj) {
                $errorMessage = 'Measurement date cannot be before birthdate.';
            }
        }

        if ($errorMessage === '') {
            $today = new DateTime('today');
            if ($measureDateObj > $today) {
                $errorMessage = 'Measurement date cannot be in the future.';
            }
        }

        if ($errorMessage === '') {
            $diff = $birthDateObj->diff($measureDateObj);
            $age_in_months = ($diff->y * 12) + $diff->m;
            if ($age_in_months < 0) {
                $age_in_months = 0;
            }
        }

        // Validate height/weight/muac for initial measurement
        $height = null;
        $weight = null;
        $muac_measurement = null;
        if ($height_raw === '' || $weight_raw === '') {
            $errorMessage = 'Height and weight are required for the initial measurement.';
        } else {
            $height = (float)$height_raw;
            $weight = (float)$weight_raw;
            $muac_measurement = (isset($_POST['muac_measurement']) && $_POST['muac_measurement'] !== '') ? (float)$_POST['muac_measurement'] : null;

            // MUAC measurement is only valid for children 6 months and older
            if ($age_in_months < 6) {
                $muac_measurement = null;
            }

            if ($height <= 0 || $weight <= 0) {
                $errorMessage = 'Height and weight must be greater than zero.';
            }
            if ($muac_measurement !== null && $muac_measurement < 0) {
                $errorMessage = 'MUAC measurement cannot be negative.';
            }
        }

        if ($errorMessage === '') {
            // Global check: first_name + last_name across ALL barangays
            $dupSql = "SELECT COUNT(*) FROM children
                       WHERE REPLACE(REPLACE(REPLACE(UPPER(first_name), ' ', ''), '\t', ''), '\n', '') = ?
                         AND REPLACE(REPLACE(REPLACE(UPPER(last_name), ' ', ''), '\t', ''), '\n', '') = ?";
            $dupStmt = $conn->prepare($dupSql);
            if ($dupStmt) {
                $dupFirstKey = normalize_name_key($first_name);
                $dupLastKey = normalize_name_key($last_name);
                $dupStmt->bind_param('ss', $dupFirstKey, $dupLastKey);
                $dupStmt->execute();
                $dupStmt->bind_result($dupCount);
                $dupStmt->fetch();
                $dupStmt->close();
                if ($dupCount > 0) {
                    $errorMessage = 'This child is already existing on the system.';
                }
            } else {
                $errorMessage = 'Database error when checking existing child.';
            }
        }

        $guardian_id = null;
        if ($guardian_id_raw !== '') {
            $guardian_id = (int)$guardian_id_raw;
        } elseif ($guardian_first_name !== '' || $guardian_last_name !== '') {
            if ($guardian_first_name === '' || $guardian_last_name === '') {
                $errorMessage = 'Guardian first name and last name are required.';
            } else {
                $guardian_contact_digits = preg_replace('/\D/', '', $guardian_contact);
                if ($guardian_contact === '') {
                    $errorMessage = 'Contact number is required.';
                } elseif (!preg_match('/^\d{1,11}$/', $guardian_contact_digits)) {
                    $errorMessage = 'Contact number must be between 1 and 11 digits only.';
                } else {
                    $guardian_contact = $guardian_contact_digits;
                    $gStmt = $conn->prepare('INSERT INTO guardians (first_name, middle_name, last_name, suffix, relationship_to_child, contact_number) VALUES (?, ?, ?, ?, ?, ?)');
                    if ($gStmt) {
                        $gStmt->bind_param('ssssss', $guardian_first_name, $guardian_middle_name, $guardian_last_name, $guardian_suffix, $guardian_relationship, $guardian_contact);
                        if ($gStmt->execute()) $guardian_id = $conn->insert_id;
                        else $errorMessage = 'Failed to save guardian information.';
                        $gStmt->close();
                    } else { $errorMessage = 'Database error when saving guardian.'; }
                }
            }
        }

        if ($errorMessage === '') {
            // Attempt to resolve growth references and statuses for the initial measurement
            $weightRef = null;
            $heightRef = null;
            $wflRef = null;
            $weightStatus = null;
            $heightStatus = null;
            $wflStatus = null;
            $muacStatus = null;
            $muac_id = null;
            $weightOutOfRange = false;
            $heightOutOfRange = false;
            $wflOutOfRange = false;

            if ($age_in_months === null) {
                $errorMessage = 'Unable to determine age in months.';
            } else {
                $normalizedSex = ucfirst(strtolower($sex));
                $weightRef = fetchClosestGrowthReference($conn, GROWTH_WEIGHT_TABLE, $age_in_months, $normalizedSex, $weightOutOfRange);
                $heightRef = fetchClosestGrowthReference($conn, GROWTH_HEIGHT_TABLE, $age_in_months, $normalizedSex, $heightOutOfRange);
                $wflAgeGroup = resolveWeightForLengthAgeGroup($age_in_months);
                $wflRef = null;
                if ($wflAgeGroup === null) {
                    $wflOutOfRange = true;
                } else {
                    $wflRef = fetchWeightForLengthReferenceByAgeGroup($conn, $normalizedSex, $wflAgeGroup, $height, $wflOutOfRange);
                }

                if (!$weightRef) {
                    $errorMessage = 'No matching weight-for-age reference found for this sex.';
                } else {
                    $weightStatus = determineWeightForAgeStatus($weight, $weightRef);
                    if ($weightStatus === null) {
                        $errorMessage = 'Unable to determine weight-for-age status.';
                    }
                }

                if ($errorMessage === '' && !$heightRef) {
                    $errorMessage = 'No matching height-for-age reference found for this sex.';
                } elseif ($errorMessage === '') {
                    $heightStatus = determineHeightForAgeStatus($height, $heightRef);
                    if ($heightStatus === null) {
                        $errorMessage = 'Unable to determine height-for-age status.';
                    }
                }

                if ($errorMessage === '' && !$wflRef) {
                    $errorMessage = 'No matching weight-for-length reference found for this sex.';
                } elseif ($errorMessage === '') {
                    $wflStatus = determineWeightForLengthStatus($weight, $wflRef);
                    if ($wflStatus === null) {
                        $errorMessage = 'Unable to determine weight-for-length status.';
                    }
                }

                if ($errorMessage === '' && $muac_measurement !== null) {
                    $muacStatus = determineMuacStatus($muac_measurement);
                    if ($muacStatus) {
                        $muac_id = 1; // Link to the single reference row in muac table
                    }
                }
            }
        }

        if ($errorMessage === '') {
            // Insert child record
            $stmt = $conn->prepare('INSERT INTO children (first_name, middle_name, last_name, suffix, sex, is_ip, birthdate, age_in_months, address, barangay_id, guardian_id, status_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            if ($stmt) {
                $stmt->bind_param('sssssssisii', $first_name, $middle_name, $last_name, $suffix, $sex, $is_ip, $birthdate, $age_in_months, $address, $barangay_id, $guardian_id);
                if ($stmt->execute()) {
                    $child_id = $conn->insert_id;

                    if ($child_id) {
                        $createdChildId = (int)$child_id;
                        $weight_id = $weightRef && isset($weightRef['weight_id']) ? (int)$weightRef['weight_id'] : null;
                        $height_id = $heightRef && isset($heightRef['height_id']) ? (int)$heightRef['height_id'] : null;
                        $wfl_id = $wflRef && isset($wflRef['wfl_id']) ? (int)$wflRef['wfl_id'] : null;
                        $sessionUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
                        $designated_user_id_raw = $_POST['designated_user_id'] ?? '';
                        $recorderId = ($designated_user_id_raw !== '') ? (int)$designated_user_id_raw : $sessionUserId;

                        if ($recorderId) {
                            $growthSql = 'INSERT INTO growth_records (child_id, measurement_date, weight, height, weight_id, height_id, wfl_id, recorded_by, muac_id, muac_measurement) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                            $growthStmt = $conn->prepare($growthSql);
                            if ($growthStmt) {
                                $growthStmt->bind_param(
                                    'isddiiiiid',
                                    $child_id,
                                    $measurement_date,
                                    $weight,
                                    $height,
                                    $weight_id,
                                    $height_id,
                                    $wfl_id,
                                    $recorderId,
                                    $muac_id,
                                    $muac_measurement
                                );
                                if (!$growthStmt->execute()) {
                                    $errorMessage = 'Failed to record growth data.';
                                }
                                $growthStmt->close();
                            } else {
                                $errorMessage = 'Failed to record growth data.';
                            }
                        } else {
                            $errorMessage = 'Unable to determine recorder.';
                        }

                    }

                    if ($errorMessage === '') {
                        $childFullName = implode(' ', array_filter([$first_name, $middle_name, $last_name]));
                        log_user_activity($conn, (int)($_SESSION['user_id'] ?? 0), 'add_profile', 'Added profile for ' . $childFullName);
                        $successMessage = 'Child profile saved successfully!';
                    }
                } else {
                    $errorMessage = 'Failed to save child profile.';
                }
                $stmt->close();
            } else { $errorMessage = 'Database error.'; }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjaxRequest) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => $successMessage !== '',
        'message' => $successMessage !== '' ? $successMessage : $errorMessage,
        'child_id' => $createdChildId
    ]);
    exit;
}

$barangays = [];
if ($limit_barangay && $assigned_barangay_id !== null) {
    $stmtBarangay = $conn->prepare('SELECT barangay_id, barangay_name FROM barangays WHERE barangay_id = ?');
    if ($stmtBarangay) {
        $stmtBarangay->bind_param('i', $assigned_barangay_id);
        $stmtBarangay->execute();
        $res = $stmtBarangay->get_result();
        if ($res) while ($row = $res->fetch_assoc()) $barangays[] = $row;
        $stmtBarangay->close();
    }
} else {
    $result = $conn->query('SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC');
    if ($result) while ($row = $result->fetch_assoc()) $barangays[] = $row;
}

$barangaySelectClasses = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.88rem] text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100';
if ($limit_barangay && $assigned_barangay_id !== null) {
    $barangaySelectClasses .= ' bg-emerald-50 text-emerald-800 border-emerald-200 font-semibold pointer-events-none ring-1 ring-emerald-100';
}

$guardians = [];
$gResult = $conn->query('SELECT guardian_id, first_name, middle_name, last_name, suffix, relationship_to_child FROM guardians ORDER BY last_name, first_name');
if ($gResult) while ($g = $gResult->fetch_assoc()) $guardians[] = $g;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Profile Registration</title>
    <link rel="stylesheet" href="css/tailwind.css">
    <link rel="stylesheet" href="css/growth-status.css">
    <style>
        body.modal-open #sidebar,
        body.modal-open .sb-mobile-bar {
            filter: blur(2px);
            pointer-events: none;
        }

        #toastContainer {
            position: fixed;
            top: 16px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 1100;
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
<body class="min-h-screen bg-slate-100 text-sm text-slate-900 font-sans">
    <?php include 'sidebar.php' ?>

    <main class="main-content min-h-screen px-4 py-8 pb-14 sm:px-6 lg:px-9">

        <!-- Title -->
        <div class="mb-6 flex items-center gap-3">
            <span class="text-2xl">👶</span>
            <div>
                <h1 class="text-xl font-bold text-slate-900">Child Profile Registration</h1>
                <p class="text-xs text-slate-500">Register a new child and their guardian information</p>
            </div>
        </div>

        <!-- Instructions -->
        <div class="mb-6 flex items-center gap-3 rounded-xl border border-red-100 bg-red-50/80 p-4 text-[0.85rem] text-black shadow-sm backdrop-blur-sm font-sans">
            <div class="flex shrink-0 items-center justify-center text-black">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            </div>
            <div class="leading-relaxed">
                Instructions: Fill out the form below to register a child. Ensure all required fields marked with an asterisk (*) are completed accurately. Verify the age is automatically calculated correctly before saving.
            </div>
        </div>

        <!-- Alerts -->
        <div id="formAlert">
            <?php if ($successMessage): ?>
                <div class="mb-5 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-[0.85rem] font-medium text-emerald-800">✅ <?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>
            <?php if ($errorMessage): ?>
                <div class="mb-5 flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-[0.85rem] font-medium text-rose-800">⚠️ <?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>
        </div>

        <div id="toastContainer"
             data-success="<?= htmlspecialchars($successMessage, ENT_QUOTES) ?>"
             data-error="<?= htmlspecialchars($errorMessage, ENT_QUOTES) ?>"></div>

        <form method="post" id="childForm" class="space-y-5">
            <input type="hidden" name="designated_user_id" id="designatedUserId" value="">
            <!-- ── Child Details Card ── -->
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-base">🧒</div>
                    <div>
                        <h2 class="text-[0.95rem] font-semibold text-slate-900">Child Information</h2>
                        <p class="text-[0.75rem] text-slate-400">Personal details of the child being registered</p>
                    </div>
                </div>
                <div class="px-5 py-5">

                    <!-- Name row -->
                    <div class="mb-3 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">First Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="first_name" id="childFirstName" placeholder="e.g. Maria" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] uppercase text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                            <span id="childFirstNameError" class="text-[0.68rem] font-semibold text-rose-600 hidden">⚠ Duplicate name detected.</span>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Middle Name</label>
                            <input type="text" name="middle_name" id="childMiddleName" placeholder="e.g. Santos" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] uppercase text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="flex items-center gap-1.5 text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Last Name <span class="text-rose-500">*</span>
                                <span id="childCheckSpinner" class="hidden inline-block h-3 w-3 animate-spin rounded-full border-2 border-slate-300 border-t-blue-500"></span>
                            </label>
                            <input type="text" name="last_name" id="childLastName" placeholder="e.g. Dela Cruz" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] uppercase text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                            <span id="childExistsError" class="text-[0.68rem] font-semibold text-rose-600 hidden">⚠ Duplicate name detected.</span>
                        </div>
                    </div>

                    <!-- Duplicate child warning banner -->
                    <div id="childExistsBanner" class="hidden mb-4 flex items-start gap-2.5 rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-[0.82rem] font-medium text-rose-800 shadow-sm">
                        <svg class="mt-0.5 shrink-0" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span id="childExistsMsg">This child is already existing on the system. Please verify the name before proceeding.</span>
                    </div>

                    <!-- Suffix + Sex -->
                    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Suffix</label>
                            <input type="text" name="suffix" id="childSuffix" placeholder="Jr., III, etc." class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] uppercase text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Sex <span class="text-rose-500">*</span></label>
                            <select name="sex" id="childSex" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>

                    <hr class="my-4 border-slate-100">

                    <!-- Birthdate + Age -->
                    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Birthdate <span class="text-rose-500">*</span></label>
                            <input type="date" name="birthdate" id="birthdateField" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                            <span class="text-[0.68rem] text-slate-400">Child must be 0–59 months old</span>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Age in Months</label>
                            <div class="flex items-center gap-2">
                                <input type="number" name="age_in_months" id="ageField" readonly placeholder="Auto-calculated" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 read-only:bg-emerald-50 read-only:cursor-default read-only:font-semibold read-only:text-emerald-800">
                                <span class="hidden whitespace-nowrap rounded-full bg-blue-600 px-2 py-0.5 text-[0.68rem] font-bold text-white" id="ageBadge"></span>
                            </div>
                            <span class="text-[0.68rem] text-slate-400">Calculated from birthdate and measurement date</span>
                        </div>
                    </div>
                    
                    <!-- Initial Measurement -->
                    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Measurement Date <span class="text-rose-500">*</span></label>
                            <input type="date" name="measurement_date" id="measurementDateField" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                            <span class="text-[0.68rem] text-slate-400">Used to compute age at measurement</span>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Initial Height (cm) <span class="text-rose-500">*</span></label>
                            <input type="number" name="height" id="heightField" step="0.1" min="0" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                            <span class="text-[0.68rem] text-slate-400">Current measured height in centimeters</span>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Initial Weight (kg) <span class="text-rose-500">*</span></label>
                            <input type="number" name="weight" id="weightField" step="0.1" min="0" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                            <span class="text-[0.68rem] text-slate-400">Current measured weight in kilograms</span>
                        </div>
                        <div id="muacContainer" class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">MUAC Measurement (cm)</label>
                            <input type="number" name="muac_measurement" id="muacField" step="0.1" min="0" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                            <span class="text-[0.68rem] text-slate-400">Mid-Upper Arm Circumference</span>
                        </div>
                    </div>

                    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Height-for-Age Status</label>
                            <input type="text" name="height_for_age_status" id="hfaStatusField" readonly class="growth-status-field w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 read-only:cursor-default read-only:font-semibold">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Weight-for-Age Status</label>
                            <input type="text" name="weight_for_age_status" id="wfaStatusField" readonly class="growth-status-field w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 read-only:cursor-default read-only:font-semibold">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Weight-for-Length/Height Status</label>
                            <input type="text" name="weight_for_ltht_status" id="wflStatusField" readonly class="growth-status-field w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 read-only:cursor-default read-only:font-semibold">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">MUAC Status</label>
                            <input type="text" name="muac_status" id="muacStatusField" readonly class="growth-status-field w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 read-only:cursor-default read-only:font-semibold">
                        </div>
                    </div>
                    <div id="statusMessage" class="mt-1 text-[0.72rem] text-rose-600" aria-live="polite"></div>

                    <hr class="my-4 border-slate-100">

                    <!-- Address / Barangay / IP -->
                    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Address/Location <span class="text-rose-500">*</span></label>
                            <input type="text" name="address" id="addressField" placeholder="e.g. Purok 3" value="PUROK " required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] uppercase text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400" aria-describedby="addressWarning">
                            <span id="addressWarning" class="text-[0.68rem] font-semibold text-rose-600 hidden">Add details after Purok (e.g., Purok 3).</span>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Barangay <span class="text-rose-500">*</span></label>
                            <?php if ($limit_barangay && $assigned_barangay_id !== null): ?>
                                <input type="hidden" name="barangay_id" value="<?= htmlspecialchars($assigned_barangay_id) ?>">
                            <?php endif; ?>
                            <select name="barangay_id" id="barangayField" required class="<?= $barangaySelectClasses ?>" <?= $limit_barangay ? 'aria-readonly="true"' : '' ?>>
                                <option value="">Select barangay</option>
                                <?php foreach ($barangays as $b): ?>
                                    <option value="<?= htmlspecialchars($b['barangay_id']) ?>" <?= ($limit_barangay && $assigned_barangay_id == $b['barangay_id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['barangay_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Indigenous Person (IP)?</label>
                            <select name="is_ip" id="isIpField" disabled class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                                <option value="No">No</option>
                                <option value="Yes">Yes</option>
                            </select>
                            <span class="text-[0.68rem] text-slate-400">Enabled after entering address</span>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ── Guardian Details Card ── -->
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-50 text-base">👨‍👧</div>
                    <div>
                        <h2 class="text-[0.95rem] font-semibold text-slate-900">Guardian Information</h2>
                        <p class="text-[0.75rem] text-slate-400">Parent or legal guardian of the child</p>
                    </div>
                </div>
                <div class="px-5 py-5">

                    <!-- Name row -->
                    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">First Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="guardian_first_name" id="guardianFirstName" placeholder="e.g. Ana" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] uppercase text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Middle Name</label>
                            <input type="text" name="guardian_middle_name" id="guardianMiddleName" placeholder="e.g. Reyes" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] uppercase text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Last Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="guardian_last_name" id="guardianLastName" placeholder="e.g. Dela Cruz" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.8rem] uppercase text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                        </div>
                    </div>

                    <!-- Suffix + Relationship + Contact -->
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Suffix</label>
                            <input type="text" name="guardian_suffix" id="guardianSuffix" placeholder="Jr., Sr., etc." class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.88rem] uppercase text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Relationship <span class="text-rose-500">*</span></label>
                            <select name="relationship_to_child" id="guardianRelationship" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.88rem] text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                                <option value="">Select</option>
                                <option value="Mother">Mother</option>
                                <option value="Father">Father</option>
                                <option value="Guardian">Guardian</option>
                            </select>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[0.72rem] font-semibold uppercase tracking-[0.04em] text-slate-700">Contact Number <span class="text-rose-500">*</span></label>
                            <input type="text" name="contact_number" id="contactNumber" placeholder="e.g. 09123456789" maxlength="11" pattern="\d{1,11}" title="Contact number must be between 1 and 11 digits only (numbers only)." inputmode="numeric" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-[0.88rem] uppercase text-slate-900 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50 disabled:text-slate-400">
                        </div>
                    </div>

                </div>

                <!-- Footer -->
                <div class="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <span class="mr-auto text-[0.72rem] text-slate-400"><span class="text-rose-500">*</span> Required fields</span>
                    <button type="reset" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-[0.85rem] font-medium text-slate-700 shadow-sm transition hover:border-slate-400 hover:bg-slate-50">Clear</button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-5 py-2 text-[0.88rem] font-semibold text-white shadow-sm transition hover:bg-blue-700 hover:shadow-blue-500/30" id="openModalBtn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Save Profile
                    </button>
                </div>
            </div>

        </form>
    </main>

    <!-- ══════════════════════════════
         CONFIRMATION MODAL
    ══════════════════════════════ -->
    <div class="fixed inset-0 z-[9999] flex items-center justify-center p-5 opacity-0 invisible pointer-events-none transition-opacity duration-200" id="confirmModal" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-md" id="modalBackdrop"></div>
        <div class="relative z-10 w-full max-w-5xl max-h-[85vh] transform overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/70 shadow-[0_30px_80px_-40px_rgba(15,23,42,0.6)] transition duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] translate-y-4 scale-95" id="confirmModalBox" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">

            <div class="h-1.5 bg-gradient-to-r from-blue-600 via-blue-500 to-sky-400"></div>

            <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-xl">📝</div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900" id="confirmTitle">Review and Confirm</h3>
                    <p class="mt-0.5 text-[0.76rem] text-slate-500">Please verify all details before saving</p>
                </div>
            </div>

            <div class="px-6 py-5 overflow-y-auto max-h-[calc(85vh-140px)]">
                <div class="grid gap-4 lg:grid-cols-3">
                    <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
                        <div class="mb-3 flex items-center gap-2 text-[0.7rem] font-bold uppercase tracking-[0.08em] text-slate-500">
                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-[0.78rem]">👶</span>
                            Child Identity
                        </div>
                        <div class="grid grid-cols-[120px_1fr] gap-x-3 gap-y-2">
                            <span class="text-[0.78rem] font-medium text-slate-500">First Name</span> <span class="text-[0.84rem] font-semibold text-slate-900" id="sumChildFirstName">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Middle Name</span><span class="text-[0.84rem] font-semibold text-slate-900" id="sumChildMiddleName">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Last Name</span>  <span class="text-[0.84rem] font-semibold text-slate-900" id="sumChildLastName">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Suffix</span>     <span class="text-[0.84rem] font-semibold text-slate-900" id="sumChildSuffix">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Birthdate</span>  <span class="text-[0.84rem] font-semibold text-slate-900" id="sumBirthdate">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Sex</span>        <span class="text-[0.84rem] font-semibold text-slate-900" id="sumSex">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Age</span>        <span class="text-[0.84rem] font-semibold text-slate-900" id="sumAge">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Address</span>    <span class="text-[0.84rem] font-semibold text-slate-900" id="sumAddress">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Barangay</span>   <span class="text-[0.84rem] font-semibold text-slate-900" id="sumBarangay">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Indigenous</span> <span class="text-[0.84rem] font-semibold text-slate-500" id="sumIp">—</span>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
                        <div class="mb-3 flex items-center gap-2 text-[0.7rem] font-bold uppercase tracking-[0.08em] text-slate-500">
                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-[0.78rem]">📏</span>
                            Measurements
                        </div>
                        <div class="grid grid-cols-[140px_1fr] gap-x-3 gap-y-2">
                            <span class="text-[0.78rem] font-medium text-slate-500">Measurement Date</span> <span class="text-[0.84rem] font-semibold text-slate-900" id="sumMeasurementDate">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Height (cm)</span>      <span class="text-[0.84rem] font-semibold text-slate-900" id="sumHeight">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Weight (kg)</span>      <span class="text-[0.84rem] font-semibold text-slate-900" id="sumWeight">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Height-for-Age</span>  <span class="text-[0.84rem] font-semibold text-slate-900" id="sumHfaStatus">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Weight-for-Age</span>  <span class="text-[0.84rem] font-semibold text-slate-900" id="sumWfaStatus">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">Weight-for-Length</span><span class="text-[0.84rem] font-semibold text-slate-900" id="sumWflStatus">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">MUAC (cm)</span>          <span class="text-[0.84rem] font-semibold text-slate-900" id="sumMuac">—</span>
                            <span class="text-[0.78rem] font-medium text-slate-500">MUAC Status</span>        <span class="text-[0.84rem] font-semibold text-slate-900" id="sumMuacStatus">—</span>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
                    <div class="mb-3 flex items-center gap-2 text-[0.7rem] font-bold uppercase tracking-[0.08em] text-slate-500">
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-amber-100 text-[0.78rem]">🧑‍🍼</span>
                        Guardian Details
                    </div>
                    <div class="grid grid-cols-[120px_1fr] gap-x-3 gap-y-2 md:grid-cols-[140px_1fr]">
                        <span class="text-[0.78rem] font-medium text-slate-500">First Name</span>  <span class="text-[0.84rem] font-semibold text-slate-900" id="sumGuardianFirstName">—</span>
                        <span class="text-[0.78rem] font-medium text-slate-500">Middle Name</span> <span class="text-[0.84rem] font-semibold text-slate-900" id="sumGuardianMiddleName">—</span>
                        <span class="text-[0.78rem] font-medium text-slate-500">Last Name</span>   <span class="text-[0.84rem] font-semibold text-slate-900" id="sumGuardianLastName">—</span>
                        <span class="text-[0.78rem] font-medium text-slate-500">Suffix</span>      <span class="text-[0.84rem] font-semibold text-slate-900" id="sumGuardianSuffix">—</span>
                        <span class="text-[0.78rem] font-medium text-slate-500">Relationship</span><span class="text-[0.84rem] font-semibold text-slate-900" id="sumRelationship">—</span>
                        <span class="text-[0.78rem] font-medium text-slate-500">Contact</span>     <span class="text-[0.84rem] font-semibold text-slate-900" id="sumContact">—</span>
                    </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 bg-slate-50 px-6 py-4">
                <div class="text-[0.75rem] text-slate-500">If anything looks wrong, tap Cancel to edit.</div>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-[0.85rem] font-medium text-slate-700 shadow-sm transition hover:bg-slate-50" id="cancelBtn">Cancel</button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-5 py-2 text-[0.88rem] font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:bg-blue-300 disabled:cursor-not-allowed" id="confirmBtn">
                        <span class="btn-label">✓ Save Profile</span>
                        <span class="spinner hidden h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- ══════════════════════════════
         USER SELECTION MODAL (Step 2)
    ══════════════════════════════ -->
    <div class="fixed inset-0 z-[10000] flex items-center justify-center p-5 opacity-0 invisible pointer-events-none transition-opacity duration-200" id="userSelectModal" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-md" id="userSelectBackdrop"></div>
        <div class="relative z-10 w-full max-w-md transform overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200/70 shadow-[0_30px_80px_-40px_rgba(15,23,42,0.6)] transition duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] translate-y-4 scale-95" id="userSelectBox" role="dialog" aria-modal="true" aria-labelledby="userSelectTitle">

            <div class="h-1.5 bg-gradient-to-r from-blue-600 via-blue-500 to-sky-400"></div>

            <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-xl">👤</div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900" id="userSelectTitle">Assign Designated User</h3>
                    <p class="mt-0.5 text-[0.76rem] text-slate-500">Select the user responsible for this barangay</p>
                </div>
            </div>

            <!-- Barangay indicator -->
            <div class="flex items-center gap-2 bg-blue-50/50 border-b border-blue-100/50 px-5 py-2.5">
                <span class="text-[0.68rem] font-bold uppercase tracking-wider text-blue-500">Barangay:</span>
                <span class="text-[0.78rem] font-bold text-blue-800" id="userSelectBarangayName">—</span>
            </div>

            <div class="px-5 py-5 min-h-[160px]">
                <!-- Loading state -->
                <div id="userSelectLoading" class="flex flex-col items-center justify-center py-10 gap-3">
                    <div class="h-8 w-8 animate-spin rounded-full border-[3px] border-slate-100 border-t-blue-500"></div>
                    <span class="text-[0.78rem] font-medium text-slate-400">Searching active users…</span>
                </div>

                <!-- Empty state -->
                <div id="userSelectEmpty" class="hidden flex-col items-center justify-center py-10 gap-2 text-center">
                    <div class="text-3xl mb-1 filter grayscale">🔍</div>
                    <p class="text-[0.82rem] font-bold text-slate-700">No active users found</p>
                    <p class="text-[0.72rem] text-slate-400 max-w-[220px] leading-relaxed">No Barangay Nutrition Scholar or Health Worker is currently assigned to this barangay.</p>
                </div>

                <!-- User cards list -->
                <div id="userSelectList" class="hidden space-y-2 max-h-60 overflow-y-auto pr-1 custom-scrollbar"></div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 bg-slate-50 px-5 py-4">
                <button type="button" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-[0.82rem] font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50" id="userSelectBackBtn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    Back
                </button>
                <button type="button" class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-5 py-2 text-[0.82rem] font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:bg-blue-300 disabled:cursor-not-allowed" id="userSelectConfirmBtn" disabled>
                    <span class="us-btn-label">✓ Confirm &amp; Save</span>
                    <span class="us-spinner hidden h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                </button>
            </div>

        </div>
    </div>

<script src="javascript/add_profile.js?v=7"></script>
</body>
</html>