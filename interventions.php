<?php
ob_start();
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/inventory_utils.php';
require_once __DIR__ . '/growth_utils.php';
require_once __DIR__ . '/activity_logger.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$errors = [];
$hasDateColumn = false;
$ajaxPayload = null;
$ajaxMessage = '';

$isAjaxRequest = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || (
    !empty($_SERVER['HTTP_ACCEPT'])
    && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
);

$colCheck = $conn->query("SHOW COLUMNS FROM interventions LIKE 'intervention_date'");
if ($colCheck && $colCheck->num_rows > 0) {
    $hasDateColumn = true;
}
if ($colCheck instanceof mysqli_result) {
    $colCheck->free();
}

if (isset($_GET['action']) && $_GET['action'] === 'latest_intervention') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_clean();

    $payload = json_decode((string)file_get_contents('php://input'), true);
    $typeId = isset($payload['type_id']) ? (int)$payload['type_id'] : 0;
    $childIds = isset($payload['child_ids']) && is_array($payload['child_ids'])
        ? array_values(array_unique(array_map('intval', $payload['child_ids'])))
        : [];

    if ($typeId <= 0 || empty($childIds)) {
        echo json_encode(['success' => false, 'message' => 'Missing type or children.']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $types = 'i' . str_repeat('i', count($childIds)) . 'i';

    $sql = "SELECT i.child_id, i.description, i.intervention_date, i.intervention_id,
                   GROUP_CONCAT(CONCAT(inv.item_name, ' (', ii.quantity_given, ' ', inv.unit, ')') SEPARATOR ', ') AS items_summary
            FROM interventions i
            INNER JOIN (
                SELECT child_id, MAX(intervention_date) AS max_date
                FROM interventions
                WHERE type_id = ? AND child_id IN ($placeholders)
                GROUP BY child_id
            ) latest ON latest.child_id = i.child_id AND latest.max_date = i.intervention_date
            LEFT JOIN intervention_items ii ON ii.intervention_id = i.intervention_id
            LEFT JOIN inventory inv ON inv.inventory_id = ii.inventory_id
            WHERE i.type_id = ?
            GROUP BY i.intervention_id";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
        exit;
    }

    $params = array_merge([$typeId], $childIds, [$typeId]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];

    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'child_id' => (int)($row['child_id'] ?? 0),
            'description' => (string)($row['description'] ?? ''),
            'intervention_date' => $hasDateColumn ? (string)($row['intervention_date'] ?? '') : '',
            'items_summary' => (string)($row['items_summary'] ?? ''),
        ];
    }

    $stmt->close();

    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

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

function ensure_give_out_type(mysqli $conn): ?int
{
    $stmt = $conn->prepare('SELECT type_id, type_name FROM intervention_types');
    if (!$stmt) {
        return null;
    }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && ($row = $result->fetch_assoc())) {
        if (is_give_out_type_name($row['type_name'] ?? '')) {
            $stmt->close();
            return (int)$row['type_id'];
        }
    }
    $stmt->close();

    $name = 'Give Out';
    $insert = $conn->prepare('INSERT INTO intervention_types (type_name) VALUES (?)');
    if (!$insert) {
        return null;
    }
    $insert->bind_param('s', $name);
    if (!$insert->execute()) {
        $insert->close();
        return null;
    }
    $newId = (int)$conn->insert_id;
    $insert->close();
    return $newId > 0 ? $newId : null;
}

function build_full_name(string $first = '', string $middle = '', string $last = '', string $suffix = ''): string
{
    $parts = [];
    if ($first !== '') $parts[] = $first;
    if ($middle !== '') $parts[] = $middle;
    if ($last !== '') $parts[] = $last;
    if ($suffix !== '') $parts[] = $suffix;
    return trim(implode(' ', $parts));
}

function build_inventory_label_map(mysqli $conn, array $ids): array
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

    $weightStatus = $weightRef ? (determineWeightForAgeStatus($weight, $weightRef) ?? 'N/A') : 'N/A';
    $heightStatus = $heightRef ? (determineHeightForAgeStatus($height, $heightRef) ?? 'N/A') : 'N/A';
    $wflStatus    = $wflRef    ? (determineWeightForLengthStatus($weight, $wflRef) ?? 'N/A') : 'N/A';

    return [
        'weight_for_age_status' => $weightStatus,
        'height_for_age_status' => $heightStatus,
        'weight_for_ltht_status' => $wflStatus,
    ];
}

function is_malnutrition_status(?string $status): bool
{
    $normalized = strtolower(trim((string)$status));
    return in_array($normalized, [
        'severely underweight',
        'underweight',
        'severely stunted',
        'stunted',
        'severely wasted',
        'wasted',
    ], true);
}

function status_pill_class(?string $status): string
{
    $normalized = strtolower(trim((string)$status));

    if (in_array($normalized, ['normal', 'tall'], true)) {
        return 'is-good';
    }

    if (in_array($normalized, ['severely underweight', 'severely stunted', 'severely wasted'], true)) {
        return 'is-bad';
    }

    if (in_array($normalized, ['underweight', 'stunted', 'wasted'], true)) {
        return 'is-warn';
    }

    if (in_array($normalized, ['overweight', 'obese'], true)) {
        return 'is-alert';
    }

    return 'is-muted';
}

function status_cell_class(?string $status): string
{
    $abbr = strtolower(status_abbrev($status));
    if ($abbr === 'n/a') return 'status-na';
    if ($abbr === 'oor') return 'status-oor';
    if (in_array($abbr, ['suw', 'sst', 'sw'], true)) return 'status-severe';
    if (in_array($abbr, ['uw', 'st', 'w', 'mw'], true)) return 'status-moderate';
    if (in_array($abbr, ['ow', 'ob'], true)) return 'status-over';
    if (in_array($abbr, ['n', 't'], true)) return 'status-normal';
    return 'status-na';
}

function status_abbrev(?string $status): string
{
    $value = strtolower(trim((string)$status));
    if ($value === '' || $value === '—' || $value === 'n/a') {
        return 'N/A';
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

function load_intervention_children(mysqli $conn): array
{
    $cutoffRecordId = file_exists(__DIR__ . '/measurement_session.txt') ? (int)trim(file_get_contents(__DIR__ . '/measurement_session.txt')) : 0;
    $children = [];
    $sql = "SELECT c.child_id, c.first_name, c.middle_name, c.last_name, c.suffix, c.address, c.birthdate, c.sex,
                   b.barangay_name,
                   gr.measurement_date, gr.weight, gr.height, gr.record_id
            FROM children c
            LEFT JOIN barangays b ON b.barangay_id = c.barangay_id
            LEFT JOIN growth_records gr ON gr.record_id = (
                SELECT gr2.record_id
                FROM growth_records gr2
                WHERE gr2.child_id = c.child_id
                  AND gr2.weight > 0
                  AND gr2.height > 0
                ORDER BY gr2.measurement_date DESC, gr2.record_id DESC
                LIMIT 1
            )
            WHERE c.status = 'Active'
            ORDER BY c.first_name ASC, c.last_name ASC, c.child_id ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $ageMonths = compute_age_in_months($row['birthdate'] ?? null, $row['measurement_date'] ?? null);

        $statuses = [
            'weight_for_age_status' => 'N/A',
            'height_for_age_status' => 'N/A',
            'weight_for_ltht_status' => 'N/A',
        ];

        $isEligible = false; // Only malnourished children are eligible
        if ($ageMonths !== null && $row['weight'] !== null && $row['height'] !== null
            && (float)$row['weight'] > 0 && (float)$row['height'] > 0) {
            $statuses = computeStatuses(
                $conn,
                (string)($row['sex'] ?? ''),
                $ageMonths,
                (float)$row['height'],
                (float)$row['weight']
            );
            
            $isMalnourished = false;
            foreach ($statuses as $status) {
                $abbr = status_abbrev($status);
                if (!in_array($abbr, ['N', 'T', 'N/A', 'OOR'], true)) {
                    $isMalnourished = true;
                    break;
                }
            }
            $isEligible = $isMalnourished;
        }

        $name = build_full_name(
            (string)($row['first_name'] ?? ''),
            (string)($row['middle_name'] ?? ''),
            (string)($row['last_name'] ?? ''),
            (string)($row['suffix'] ?? '')
        );
        $locationParts = [];
        if (!empty($row['address'])) {
            $locationParts[] = trim((string)$row['address']);
        }
        if (!empty($row['barangay_name'])) {
            $locationParts[] = trim((string)$row['barangay_name']);
        }
        $addressLocation = !empty($locationParts) ? implode(' — ', $locationParts) : 'N/A';

        $recordId = (int)($row['record_id'] ?? 0);
        $measurementStatus = 'N/A';
        if ($recordId > 0) {
            $measurementStatus = ($recordId > $cutoffRecordId) ? 'New/Update' : 'Recent';
        }

        $children[] = [
            'child_id'               => (int)$row['child_id'],
            'name'                   => $name,
            'address'                => $row['address'] ?? '—',
            'barangay_name'          => $row['barangay_name'] ?? '—',
            'address_location'       => $addressLocation,
            'sex'                    => !empty($row['sex']) ? (string)$row['sex'] : 'N/A',
            'weight'                 => $row['weight'] !== null ? (float)$row['weight'] : null,
            'height'                 => $row['height'] !== null ? (float)$row['height'] : null,
            'age_in_months'          => $ageMonths,
            'weight_for_age_status'  => $statuses['weight_for_age_status'] ?? 'N/A',
            'height_for_age_status'  => $statuses['height_for_age_status'] ?? 'N/A',
            'weight_for_ltht_status' => $statuses['weight_for_ltht_status'] ?? 'N/A',
            'is_eligible'            => $isEligible,
            'measurement_status'     => $measurementStatus,
            'measurement_date'       => $row['measurement_date'] ?? '—',
        ];
    }

    $stmt->close();
    return $children;
}

$children = load_intervention_children($conn);
$eligibleChildIds = array_map('intval', array_column(array_filter($children, fn($child) => !empty($child['is_eligible'])), 'child_id'));
$childrenById = [];
foreach ($children as $childRow) {
    $childrenById[(int)$childRow['child_id']] = $childRow;
}

$giveOutTypeId = ensure_give_out_type($conn) ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_type') {
        $typeName = trim((string)($_POST['type_name'] ?? ''));
        if ($typeName === '') {
            $errors[] = 'Type name is required.';
        } else {
            $stmt = $conn->prepare('SELECT COUNT(*) FROM intervention_types WHERE LOWER(type_name) = LOWER(?)');
            if ($stmt) {
                $stmt->bind_param('s', $typeName);
                $stmt->execute();
                $stmt->bind_result($existsCount);
                $stmt->fetch();
                $stmt->close();
            } else {
                $errors[] = 'Database error while checking type.';
            }

            if (empty($errors)) {
                if ($existsCount > 0) {
                    $errors[] = 'Type already exists.';
                } else {
                    $stmtInsert = $conn->prepare('INSERT INTO intervention_types (type_name) VALUES (?)');
                    if ($stmtInsert) {
                        $stmtInsert->bind_param('s', $typeName);
                        $stmtInsert->execute();
                        $stmtInsert->close();
                        log_user_activity(
                            $conn,
                            (int)($_SESSION['user_id'] ?? 0),
                            'intervention_type_add',
                            'Added intervention type: ' . $typeName
                        );
                        $newTypeId = (int)$conn->insert_id;
                        if ($isAjaxRequest) {
                            $ajaxMessage = 'Intervention type added successfully.';
                            $ajaxPayload = [
                                'action' => 'add_type',
                                'type_id' => $newTypeId,
                                'type_name' => $typeName,
                            ];
                        } else {
                            set_flash('success', 'Intervention type added successfully.');
                            header('Location: interventions.php');
                            exit;
                        }
                    } else {
                        $errors[] = 'Unable to insert type.';
                    }
                }
            }
        }
    }

    if ($action === 'delete_type') {
        $typeId = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;

        if ($typeId <= 0) {
            $errors[] = 'Invalid intervention type selected for deletion.';
        } else {
            $typeName = '';
            $stmtType = $conn->prepare('SELECT type_name FROM intervention_types WHERE type_id = ? LIMIT 1');
            if (!$stmtType) {
                $errors[] = 'Database error while checking intervention type.';
            } else {
                $stmtType->bind_param('i', $typeId);
                $stmtType->execute();
                $stmtType->bind_result($typeName);
                $foundType = $stmtType->fetch();
                $stmtType->close();

                if (!$foundType) {
                    $errors[] = 'Intervention type was not found.';
                }
            }
        }

        if (empty($errors)) {
            $usageCount = 0;
            $stmtUsage = $conn->prepare('SELECT COUNT(*) FROM interventions WHERE type_id = ?');
            if (!$stmtUsage) {
                $errors[] = 'Database error while validating intervention records.';
            } else {
                $stmtUsage->bind_param('i', $typeId);
                $stmtUsage->execute();
                $stmtUsage->bind_result($usageCount);
                $stmtUsage->fetch();
                $stmtUsage->close();
            }

            if ($usageCount > 0) {
                $errors[] = 'This intervention type cannot be deleted because it is already assigned to child intervention records.';
            }
        }

        if (empty($errors)) {
            $stmtDeleteType = $conn->prepare('DELETE FROM intervention_types WHERE type_id = ? LIMIT 1');
            if (!$stmtDeleteType) {
                $errors[] = 'Unable to delete intervention type.';
            } else {
                $stmtDeleteType->bind_param('i', $typeId);
                    if ($stmtDeleteType->execute() && $stmtDeleteType->affected_rows > 0) {
                        log_user_activity(
                            $conn,
                            (int)($_SESSION['user_id'] ?? 0),
                            'intervention_type_delete',
                            'Deleted intervention type: ' . ($typeName !== '' ? $typeName : ('ID ' . $typeId))
                        );
                        $stmtDeleteType->close();
                        if ($isAjaxRequest) {
                            $ajaxMessage = 'Intervention type deleted successfully.';
                            $ajaxPayload = [
                                'action' => 'delete_type',
                                'type_id' => $typeId,
                            ];
                        } else {
                            set_flash('success', 'Intervention type deleted successfully.');
                            header('Location: interventions.php');
                            exit;
                        }
                    } else {
                        $stmtDeleteType->close();
                        $errors[] = 'Intervention type could not be deleted.';
                    }
            }
        }
    }

    if ($action === 'add_intervention') {
        $childIds = isset($_POST['child_ids']) && is_array($_POST['child_ids']) ? array_map('intval', $_POST['child_ids']) : [];
        $typeId = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
        $description = trim((string)($_POST['description'] ?? ''));
        $interventionDate = trim((string)($_POST['intervention_date'] ?? ''));
        $confirmOverride = isset($_POST['confirm_override']) ? (int)$_POST['confirm_override'] : 0;
        $giveOutInventoryIds = isset($_POST['giveout_inventory_ids']) && is_array($_POST['giveout_inventory_ids'])
            ? array_map('intval', $_POST['giveout_inventory_ids'])
            : [];
        $giveOutQtys = isset($_POST['giveout_qtys']) && is_array($_POST['giveout_qtys'])
            ? array_map('intval', $_POST['giveout_qtys'])
            : [];
        $isGiveOutType = false;
        $giveOutLines = [];

        $childIds = array_values(array_unique(array_filter($childIds)));
        // Make sure we only assign to children that exist in our loaded list
        $allValidChildIds = array_map('intval', array_column($children, 'child_id'));
        $invalidChildIds = array_values(array_diff($childIds, $allValidChildIds));
        if (!empty($invalidChildIds)) {
            $errors[] = 'One or more selected children are invalid or not active.';
        }
        $childIds = array_values(array_intersect($childIds, $allValidChildIds));

        $dateValid = true;
        if ($hasDateColumn) {
            if ($interventionDate === '') {
                $dateValid = false;
            } else {
                $d = DateTime::createFromFormat('Y-m-d', $interventionDate);
                $dateValid = $d && $d->format('Y-m-d') === $interventionDate;
            }
        }

        if ($typeId <= 0 || empty($childIds)) {
            $errors[] = 'Select an intervention type and at least one child.';
        }
        if ($hasDateColumn && !$dateValid) {
            $errors[] = 'Please provide a valid intervention date.';
        }

        $mergeTarget = null;
        if (empty($errors)) {
            $typeName = null;
            $stmtType = $conn->prepare('SELECT type_name FROM intervention_types WHERE type_id = ? LIMIT 1');
            if ($stmtType) {
                $stmtType->bind_param('i', $typeId);
                $stmtType->execute();
                $stmtType->bind_result($typeName);
                $stmtType->fetch();
                $stmtType->close();
            }
            if ($typeName === null) {
                $errors[] = 'Selected intervention type does not exist.';
            } else {
                $isGiveOutType = is_give_out_type_name($typeName);
            }
        }

        if (empty($errors)) {
            $stmtLatest = $conn->prepare('SELECT description, intervention_date FROM interventions WHERE type_id = ? ORDER BY intervention_date DESC, intervention_id DESC LIMIT 1');
            if ($stmtLatest) {
                $stmtLatest->bind_param('i', $typeId);
                $stmtLatest->execute();
                $stmtLatest->bind_result($latestDescription, $latestDate);
                if ($stmtLatest->fetch()) {
                    $mergeTarget = [
                        'description' => (string)($latestDescription ?? ''),
                        'intervention_date' => (string)($latestDate ?? ''),
                    ];
                }
                $stmtLatest->close();
            }
        }

        if ($mergeTarget) {
            // Only adopt from merge target if user left it blank
            if ($description === '') {
                $description = $mergeTarget['description'];
            }
            if ($hasDateColumn && $interventionDate === '') {
                $interventionDate = $mergeTarget['intervention_date'];
            }
        }

        if (empty($errors) && $isGiveOutType) {
            if (empty($giveOutInventoryIds)) {
                $errors[] = 'Please add at least one inventory item for Give Out intervention.';
            } else {
                foreach ($giveOutInventoryIds as $idx => $inventoryId) {
                    $qty = $giveOutQtys[$idx] ?? 0;
                    if ($inventoryId <= 0 || $qty <= 0) {
                        $errors[] = 'Invalid inventory item or quantity in Give Out list.';
                        continue;
                    }
                    if (!isset($giveOutLines[$inventoryId])) {
                        $giveOutLines[$inventoryId] = 0;
                    }
                    $giveOutLines[$inventoryId] += (int)$qty;
                }
            }

            if (empty($errors) && !empty($giveOutLines)) {
                $childrenCount = count($childIds);
                if (inventory_status_column_exists($conn)) {
                    $checkInv = $conn->prepare('SELECT item_name, quantity, status FROM inventory WHERE inventory_id = ? LIMIT 1');
                } else {
                    $checkInv = $conn->prepare('SELECT item_name, quantity FROM inventory WHERE inventory_id = ? LIMIT 1');
                }
                if (!$checkInv) {
                    $errors[] = 'Database error while validating inventory items.';
                } else {
                    foreach ($giveOutLines as $inventoryId => $totalQtyGiveOut) {
                        $totalQtyGiveOut = (int)$totalQtyGiveOut;
                        $itemName = null;
                        $availableQty = null;
                        $itemStatus = 'Available';
                        $checkInv->bind_param('i', $inventoryId);
                        $checkInv->execute();
                        if (inventory_status_column_exists($conn)) {
                            $checkInv->bind_result($itemName, $availableQty, $itemStatus);
                        } else {
                            $checkInv->bind_result($itemName, $availableQty);
                        }
                        if (!$checkInv->fetch()) {
                            $errors[] = 'Selected inventory item does not exist.';
                            $checkInv->free_result();
                            continue;
                        }
                        $checkInv->free_result();

                        if ($itemStatus === 'Expired') {
                            $errors[] = '"' . $itemName . '" is expired and cannot be given out.';
                            continue;
                        }
                        if ((int)$availableQty <= 0) {
                            $errors[] = '"' . $itemName . '" has no stock available.';
                            continue;
                        }

                        if ($childrenCount > 0 && $totalQtyGiveOut !== $childrenCount) {
                            $errors[] = 'For "' . $itemName . '", quantity (' . $totalQtyGiveOut . ' pcs) must match the number of selected children (' . $childrenCount . ').';
                            continue;
                        }
                        if ((int)$availableQty < $totalQtyGiveOut) {
                            $errors[] = 'Not enough stock for ' . $itemName . '. Required: ' . $totalQtyGiveOut . ', available: ' . (int)$availableQty . '.';
                        }
                    }
                    $checkInv->close();
                }
            }
        }

        if (empty($errors)) {
            if ($hasDateColumn) {
                $interventionDateDb = $interventionDate;
            }
            $stmtChild = $conn->prepare("SELECT COUNT(*) FROM children WHERE child_id = ? AND status = 'Active'");
            $stmtInsert = $hasDateColumn
                ? $conn->prepare('INSERT INTO interventions (child_id, type_id, description, intervention_date) VALUES (?, ?, ?, ?)')
                : $conn->prepare('INSERT INTO interventions (child_id, type_id, description) VALUES (?, ?, ?)');
            $stmtInsertItem = $isGiveOutType
                ? $conn->prepare('INSERT INTO intervention_items (intervention_id, inventory_id, quantity_given) VALUES (?, ?, ?)')
                : null;
            $stmtDeductStock = $isGiveOutType
                ? $conn->prepare('UPDATE inventory SET quantity = quantity - ?, last_updated = NOW() WHERE inventory_id = ?')
                : null;

            if (!$stmtChild || !$stmtInsert || ($isGiveOutType && (!$stmtInsertItem || !$stmtDeductStock))) {
                if ($stmtChild) $stmtChild->close();
                if ($stmtInsert) $stmtInsert->close();
                if ($stmtInsertItem) $stmtInsertItem->close();
                if ($stmtDeductStock) $stmtDeductStock->close();
                $errors[] = 'Database error while saving intervention.';
            } else {
                $inserted = 0;
                $giveOutChildCount = count($childIds);
                $conn->begin_transaction();
                try {
                    foreach ($childIds as $cid) {
                        $exists = 0;
                        $stmtChild->bind_param('i', $cid);
                        if (!$stmtChild->execute()) {
                            throw new Exception('Failed to validate selected child.');
                        }
                        $stmtChild->bind_result($exists);
                        $stmtChild->fetch();
                        $stmtChild->free_result();
                        if ($exists === 0) {
                            continue;
                        }

                        if ($hasDateColumn) {
                            $stmtInsert->bind_param('iiss', $cid, $typeId, $description, $interventionDateDb);
                        } else {
                            $stmtInsert->bind_param('iis', $cid, $typeId, $description);
                        }
                        if (!$stmtInsert->execute()) {
                            throw new Exception('Failed to save intervention.');
                        }

                        $interventionId = (int)$conn->insert_id;
                        if ($isGiveOutType && $interventionId > 0) {
                            foreach ($giveOutLines as $inventoryId => $totalQtyGiveOut) {
                                $totalQtyGiveOut = (int)$totalQtyGiveOut;
                                $qtyGiven = $giveOutChildCount > 0 ? intdiv($totalQtyGiveOut, $giveOutChildCount) : 0;
                                if ($qtyGiven < 1 || $qtyGiven * $giveOutChildCount !== $totalQtyGiveOut) {
                                    throw new Exception('Invalid give-out quantity distribution.');
                                }
                                $stmtInsertItem->bind_param('iii', $interventionId, $inventoryId, $qtyGiven);
                                if (!$stmtInsertItem->execute()) {
                                    throw new Exception('Failed to save intervention item transaction.');
                                }
                            }
                        }

                        $inserted++;
                    }

                    if ($inserted <= 0) {
                        throw new Exception('No interventions were saved. Please verify the children selection.');
                    }

                    if ($isGiveOutType && $inserted !== $giveOutChildCount) {
                        throw new Exception('Could not record give-out for every selected child. Refresh the page and try again.');
                    }

                    if ($isGiveOutType) {
                        foreach ($giveOutLines as $inventoryId => $totalQtyGiveOut) {
                            $totalDeduction = (int)$totalQtyGiveOut;
                            $stmtDeductStock->bind_param('ii', $totalDeduction, $inventoryId);
                            if (!$stmtDeductStock->execute()) {
                                throw new Exception('Failed to update inventory stock.');
                            }
                        }
                    }

                    $conn->commit();
                    $stmtChild->close();
                    $stmtInsert->close();
                    if ($stmtInsertItem) $stmtInsertItem->close();
                    if ($stmtDeductStock) $stmtDeductStock->close();

                    $detailsParts = [];
                    if (!empty($typeName)) {
                        $detailsParts[] = 'Type: ' . $typeName;
                    }
                    if ($hasDateColumn && $interventionDate !== '') {
                        $detailsParts[] = 'Date: ' . $interventionDate;
                    }
                    $detailsParts[] = 'Children: ' . $inserted;
                    if ($description !== '') {
                        $detailsParts[] = 'Notes: ' . $description;
                    }
                    if ($isGiveOutType && !empty($giveOutLines)) {
                        $itemMap = build_inventory_label_map($conn, array_keys($giveOutLines));
                        $itemParts = [];
                        foreach ($giveOutLines as $invId => $totalQtyGiveOut) {
                            $label = $itemMap[$invId]['name'] ?? ('Item #' . $invId);
                            $unit = $itemMap[$invId]['unit'] ?? '';
                            $itemParts[] = $label . ' — ' . (int)$totalQtyGiveOut . ' pcs total' . ($unit ? ' (' . $unit . ')' : '');
                        }
                        if (!empty($itemParts)) {
                            $detailsParts[] = 'Give out: ' . implode(', ', $itemParts);
                        }
                    }
                    log_user_activity(
                        $conn,
                        (int)($_SESSION['user_id'] ?? 0),
                        'intervention_add',
                        implode(' | ', $detailsParts)
                    );

                    $successText = 'Intervention saved for ' . $inserted . ' child(ren).';
                    if ($isAjaxRequest) {
                        $ajaxMessage = $successText;
                        $viewKey = base64_encode($typeId . '::' . ($description ?? '') . '::' . ($hasDateColumn ? ($interventionDate ?? '') : ''));
                        $ajaxPayload = [
                            'action' => 'add_intervention',
                            'type_id' => $typeId,
                            'type_name' => $typeName ?? '',
                            'child_count' => $inserted,
                            'child_ids' => $childIds,
                            'description' => $description ?? '',
                            'intervention_date' => $hasDateColumn ? ($interventionDate ?? '') : '',
                            'view_url' => $mergeTarget ? ('view_interventions.php?k=' . urlencode($viewKey)) : '',
                        ];
                    } else {
                        set_flash('success', $successText);
                        if ($mergeTarget) {
                            $redirectKey = base64_encode($typeId . '::' . ($description ?? '') . '::' . ($hasDateColumn ? ($interventionDate ?? '') : ''));
                            header('Location: view_interventions.php?k=' . urlencode($redirectKey));
                            exit;
                        }
                        header('Location: interventions.php');
                        exit;
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $stmtChild->close();
                    $stmtInsert->close();
                    if ($stmtInsertItem) $stmtInsertItem->close();
                    if ($stmtDeductStock) $stmtDeductStock->close();
                    $errors[] = $e->getMessage();
                }
            }
        }
    }

    if ($action === 'edit_intervention') {
        $childIds = isset($_POST['child_ids']) && is_array($_POST['child_ids']) ? array_map('intval', $_POST['child_ids']) : [];
        $typeId = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
        $description = trim((string)($_POST['description'] ?? ''));
        $interventionDate = trim((string)($_POST['intervention_date'] ?? ''));

        $originalTypeId = isset($_POST['original_type_id']) ? (int)$_POST['original_type_id'] : 0;
        $originalDescription = trim((string)($_POST['original_description'] ?? ''));
        $originalDate = trim((string)($_POST['original_date'] ?? ''));

        $childIds = array_values(array_unique(array_filter($childIds)));

        $dateValid = true;
        if ($hasDateColumn) {
            if ($interventionDate === '') {
                $dateValid = false;
            } else {
                $d = DateTime::createFromFormat('Y-m-d', $interventionDate);
                $dateValid = $d && $d->format('Y-m-d') === $interventionDate;
            }
        }

        if ($typeId <= 0 || empty($childIds)) {
            $errors[] = 'Select an intervention type and at least one child.';
        }
        if ($hasDateColumn && !$dateValid) {
            $errors[] = 'Please provide a valid intervention date.';
        }
        if ($originalTypeId <= 0) {
            $errors[] = 'Unable to locate the intervention to edit.';
        }
        if ($hasDateColumn && $originalDate === '') {
            $errors[] = 'Unable to locate the intervention date to edit.';
        }

        if (empty($errors)) {
            $typeExists = 0;
            $stmtType = $conn->prepare('SELECT COUNT(*) FROM intervention_types WHERE type_id = ?');
            if ($stmtType) {
                $stmtType->bind_param('i', $typeId);
                $stmtType->execute();
                $stmtType->bind_result($typeExists);
                $stmtType->fetch();
                $stmtType->close();
            }
            if ($typeExists === 0) {
                $errors[] = 'Selected intervention type does not exist.';
            }
        }

        if (empty($errors)) {
            $stmtDeleteItems = $hasDateColumn
                ? $conn->prepare('DELETE ii FROM intervention_items ii INNER JOIN interventions i ON i.intervention_id = ii.intervention_id WHERE i.type_id = ? AND i.description = ? AND i.intervention_date = ?')
                : $conn->prepare('DELETE ii FROM intervention_items ii INNER JOIN interventions i ON i.intervention_id = ii.intervention_id WHERE i.type_id = ? AND i.description = ?');
            $stmtDelete = $hasDateColumn
                ? $conn->prepare('DELETE FROM interventions WHERE type_id = ? AND description = ? AND intervention_date = ?')
                : $conn->prepare('DELETE FROM interventions WHERE type_id = ? AND description = ?');

            if (!$stmtDeleteItems || !$stmtDelete) {
                $errors[] = 'Database error while updating intervention.';
            } else {
                if ($hasDateColumn) {
                    $stmtDeleteItems->bind_param('iss', $originalTypeId, $originalDescription, $originalDate);
                    $stmtDelete->bind_param('iss', $originalTypeId, $originalDescription, $originalDate);
                } else {
                    $stmtDeleteItems->bind_param('is', $originalTypeId, $originalDescription);
                    $stmtDelete->bind_param('is', $originalTypeId, $originalDescription);
                }
                $stmtDeleteItems->execute();
                $stmtDeleteItems->close();
                $stmtDelete->execute();
                $stmtDelete->close();
            }
        }

        if (empty($errors)) {
            $stmtChild = $conn->prepare("SELECT COUNT(*) FROM children WHERE child_id = ? AND status = 'Active'");
            $stmtInsert = $hasDateColumn
                ? $conn->prepare('INSERT INTO interventions (child_id, type_id, description, intervention_date) VALUES (?, ?, ?, ?)')
                : $conn->prepare('INSERT INTO interventions (child_id, type_id, description) VALUES (?, ?, ?)');
            if (!$stmtChild || !$stmtInsert) {
                $errors[] = 'Database error while saving intervention.';
            } else {
                $inserted = 0;
                foreach ($childIds as $cid) {
                    $exists = 0;
                    $stmtChild->bind_param('i', $cid);
                    $stmtChild->execute();
                    $stmtChild->bind_result($exists);
                    $stmtChild->fetch();
                    $stmtChild->free_result();
                    if ($exists === 0) continue;
                    if ($hasDateColumn) {
                        $stmtInsert->bind_param('iiss', $cid, $typeId, $description, $interventionDate);
                    } else {
                        $stmtInsert->bind_param('iis', $cid, $typeId, $description);
                    }
                    $stmtInsert->execute();
                    $inserted++;
                }
                $stmtChild->close();
                $stmtInsert->close();

                if ($inserted > 0) {
                    $typeName = null;
                    $stmtTypeName = $conn->prepare('SELECT type_name FROM intervention_types WHERE type_id = ? LIMIT 1');
                    if ($stmtTypeName) {
                        $stmtTypeName->bind_param('i', $typeId);
                        $stmtTypeName->execute();
                        $stmtTypeName->bind_result($typeName);
                        $stmtTypeName->fetch();
                        $stmtTypeName->close();
                    }
                    $detailsParts = [];
                    if (!empty($typeName)) {
                        $detailsParts[] = 'Type: ' . $typeName;
                    } else {
                        $detailsParts[] = 'Type ID: ' . $typeId;
                    }
                    if ($hasDateColumn && $interventionDate !== '') {
                        $detailsParts[] = 'Date: ' . $interventionDate;
                    }
                    $detailsParts[] = 'Children: ' . $inserted;
                    if ($description !== '') {
                        $detailsParts[] = 'Notes: ' . $description;
                    }
                    log_user_activity(
                        $conn,
                        (int)($_SESSION['user_id'] ?? 0),
                        'intervention_edit',
                        implode(' | ', $detailsParts)
                    );
                    $successText = 'Intervention updated for ' . $inserted . ' child(ren).';
                    if ($isAjaxRequest) {
                        $ajaxMessage = $successText;
                        $ajaxPayload = [
                            'action' => 'edit_intervention',
                            'type_id' => $typeId,
                            'original_type_id' => $originalTypeId,
                            'type_name' => $typeName ?? '',
                            'child_count' => $inserted,
                            'description' => $description ?? '',
                            'intervention_date' => $hasDateColumn ? ($interventionDate ?? '') : '',
                        ];
                    } else {
                        set_flash('success', $successText);
                        header('Location: interventions.php');
                        exit;
                    }
                } else {
                    $errors[] = 'No interventions were saved. Please verify the children selection.';
                }
            }
        }
    }
}

if ($isAjaxRequest && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    if (ob_get_length()) ob_clean();
    $message = $ajaxMessage;
    if (!$message && !empty($errors)) {
        $message = $errors[0];
    }
    echo json_encode([
        'success' => empty($errors),
        'message' => $message,
        'errors' => $errors,
        'payload' => $ajaxPayload,
    ]);
    exit;
}

$types = [];
$resultTypes = $conn->query('SELECT type_id, type_name FROM intervention_types ORDER BY type_name ASC');
if ($resultTypes && $resultTypes->num_rows > 0) {
    while ($row = $resultTypes->fetch_assoc()) $types[] = $row;
}

sync_inventory_statuses($conn);

$inventoryItems = [];
$inventorySql = inventory_status_column_exists($conn)
    ? 'SELECT inventory_id, item_name, quantity, unit, category_id, status FROM inventory WHERE quantity > 0 AND status = \'Available\' ORDER BY item_name ASC'
    : 'SELECT inventory_id, item_name, quantity, unit, category_id FROM inventory WHERE quantity > 0 ORDER BY item_name ASC';
$inventoryResult = $conn->query($inventorySql);
if ($inventoryResult && $inventoryResult->num_rows > 0) {
    while ($row = $inventoryResult->fetch_assoc()) {
        $inventoryItems[] = $row;
    }
}

$categoriesInventory = [];
$catResult = $conn->query("SELECT category_id, category_name FROM category_inventory ORDER BY category_name ASC");
if ($catResult && $catResult->num_rows > 0) {
    while ($row = $catResult->fetch_assoc()) {
        $categoriesInventory[] = $row;
    }
}

$allChildren = [];
$sqlChildren = "SELECT c.child_id, c.first_name, c.middle_name, c.last_name, c.suffix, c.sex, b.barangay_name
FROM children c
LEFT JOIN barangays b ON c.barangay_id = b.barangay_id
WHERE c.status = 'Active'
ORDER BY c.first_name ASC, c.last_name ASC";
$resultChildren = $conn->query($sqlChildren);
if ($resultChildren && $resultChildren->num_rows > 0) {
    while ($row = $resultChildren->fetch_assoc()) $allChildren[] = $row;
}

$groupedInterventions = [];
$selectDateField = $hasDateColumn ? 'i.intervention_date' : "'' AS intervention_date";

// Fetch the most recent intervention record per type (one row per type_id)
$sqlInterventions = "SELECT i.intervention_id, i.description, i.type_id, {$selectDateField}, t.type_name,
        c.child_id, c.first_name, c.middle_name, c.last_name, c.suffix, b.barangay_name
    FROM interventions i
    INNER JOIN children c ON c.child_id = i.child_id AND c.status = 'Active'
    LEFT JOIN barangays b ON b.barangay_id = c.barangay_id
    LEFT JOIN intervention_types t ON t.type_id = i.type_id
    ORDER BY i.intervention_date DESC, i.intervention_id DESC";
$resultInterventions = $conn->query($sqlInterventions);
if ($resultInterventions && $resultInterventions->num_rows > 0) {
    while ($row = $resultInterventions->fetch_assoc()) {
        $typeId_row = (string)$row['type_id'];
        // Only store the first (most recent) record per type
        if (!isset($groupedInterventions[$typeId_row])) {
            $groupedInterventions[$typeId_row] = [
                'type_id'           => $row['type_id'],
                'type_name'         => $row['type_name'] ?? '—',
                'description'       => $row['description'] ?? '',
                'intervention_date' => $row['intervention_date'] ?? '',
                'children'          => [],
                'children_ids'      => [],
            ];
        }
        // Accumulate all children assigned to this type
        $childId = (int)($row['child_id'] ?? 0);
        if ($childId > 0 && !in_array($childId, $groupedInterventions[$typeId_row]['children_ids'], true)) {
            $childName = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if (!empty($row['suffix'])) $childName .= ' ' . $row['suffix'];
            $label = $childName;
            if (!empty($row['barangay_name'])) $label .= ' — ' . $row['barangay_name'];
            if ($childName !== '') {
                $groupedInterventions[$typeId_row]['children'][] = [
                    'id'    => $childId,
                    'label' => $label,
                ];
                $groupedInterventions[$typeId_row]['children_ids'][] = $childId;
            }
        }
    }
}

// Add placeholder rows for types that have no intervention records yet
$sqlTypes = "SELECT type_id, type_name FROM intervention_types ORDER BY type_name ASC";
$resultAllTypes = $conn->query($sqlTypes);
if ($resultAllTypes && $resultAllTypes->num_rows > 0) {
    while ($rowType = $resultAllTypes->fetch_assoc()) {
        $typeIdStr = (string)$rowType['type_id'];
        if (!isset($groupedInterventions[$typeIdStr])) {
            $groupedInterventions[$typeIdStr] = [
                'type_id'           => $rowType['type_id'],
                'type_name'         => $rowType['type_name'] ?? '—',
                'description'       => '',
                'intervention_date' => '',
                'children'          => [],
                'children_ids'      => [],
            ];
        }
    }
}

// Sort: types with records first (most recent date), then empty types
uasort($groupedInterventions, function ($a, $b) {
    $aHas = !empty($a['children_ids']);
    $bHas = !empty($b['children_ids']);
    if ($aHas !== $bHas) return $aHas ? -1 : 1;
    return strcmp($b['intervention_date'], $a['intervention_date']);
});

$editPrefill = null;
$editKeyEncoded = $_GET['edit_k'] ?? '';
$editKey = base64_decode((string)$editKeyEncoded, true);
if ($editKey && isset($groupedInterventions[$editKey])) {
    $editItem = $groupedInterventions[$editKey];
    $editPrefill = [
        'type_id' => (int)($editItem['type_id'] ?? 0),
        'description' => (string)($editItem['description'] ?? ''),
        'date' => (string)($editItem['intervention_date'] ?? ''),
        'child_ids' => array_map('intval', array_column($editItem['children'] ?? [], 'id')),
    ];
}

function child_label(array $child): string {
    $name = trim(($child['first_name'] ?? '') . ' ' . ($child['middle_name'] ?? '') . ' ' . ($child['last_name'] ?? ''));
    if (!empty($child['suffix'])) $name .= ' ' . $child['suffix'];
    $barangay = $child['barangay_name'] ?? '';
    return $barangay ? "$name — $barangay" : $name;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interventions</title>
    <link rel="stylesheet" href="css/tailwind.css">
    <link rel="stylesheet" href="css/interventions.css">
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
<body>
<?php include 'sidebar.php'; ?>

<style>
    .intervention-row .tbl-btn-view,
    .intervention-row .btn-view-page,
    .intervention-row .tbl-btn-view:hover,
    .intervention-row .btn-view-page:hover {
        background: #059669 !important;
        color: #fff !important;
        border-color: #059669 !important;
        box-shadow: 0 4px 12px rgba(5, 150, 105, .22) !important;
        transform: none !important;
    }
</style>

<main class="main-content min-h-screen px-4 md:px-8 py-7 pb-16" style="display:flex;flex-direction:column;gap:20px;">

    <!-- Page header -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div class="stat-icon green" style="width:48px;height:48px;font-size:22px;">💊</div>
            <div>
                <h1 style="font-size:18px;font-weight:700;color:var(--slate-900);margin:0;">Interventions</h1>
                <p style="font-size:12px;color:var(--slate-500);margin:2px 0 0;">Manage intervention types and assign them to children</p>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn-primary-green" id="btnOpenTypeModal" style="background:#2563eb !important; box-shadow:0 4px 12px rgba(37,99,235,.24) !important;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                Add Type
            </button>
            <button class="btn-primary-blue" id="btnOpenInterventionModal" style="background:#2563eb !important; box-shadow:0 4px 12px rgba(37,99,235,.24) !important;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                Add Intervention
            </button>
        </div>
    </div>



    <div id="toastContainer"
         data-success="<?= $flash ? htmlspecialchars($flash['message'], ENT_QUOTES) : '' ?>"
         data-error="<?= !empty($errors) ? htmlspecialchars((string)$errors[0], ENT_QUOTES) : '' ?>"></div>



    <!-- Table card -->
    <div class="section-card">
        <div class="section-card-header">
            <div>
                <div class="section-card-title" style="display:flex;align-items:center;gap:10px;">
                    Intervention Records
                    <span id="typeBadgeCount" style="background:var(--blue-light);color:var(--blue);border:1px solid var(--blue-border);border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700;">
                        <?= count($types) ?> Names
                    </span>
                </div>
                <div class="section-card-sub">Grouped by intervention name and description</div>
            </div>
            <div class="search-wrap">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                <input class="search-input" type="text" id="tableSearch" placeholder="Search interventions…">
            </div>
        </div>

        <div style="overflow:visible;">
            <table class="data-table" id="interventionTable">
                <thead>
                    <tr>
                        <th style="width:120px;">Intervention Name</th>
                        <th style="width:110px;">Per Child</th>
                        <th style="width:130px;text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                <?php if (!empty($groupedInterventions)): ?>
                    <?php foreach ($groupedInterventions as $key => $item): ?>
                        <?php
                            $childLabels = array_column($item['children'], 'label');
                            $childIds    = array_column($item['children'], 'id');
                            $childRows   = [];
                            foreach ($item['children'] as $selectedChild) {
                                $selectedChildId = (int)($selectedChild['id'] ?? 0);
                                if ($selectedChildId > 0 && isset($childrenById[$selectedChildId])) {
                                    $record = $childrenById[$selectedChildId];
                                    $childRows[] = [
                                        'child_id' => $selectedChildId,
                                        'name' => (string)($record['name'] ?? '—'),
                                        'address_location' => (string)($record['address_location'] ?? '—'),
                                        'sex' => (string)($record['sex'] ?? 'N/A'),
                                        'weight' => isset($record['weight']) ? number_format((float)$record['weight'], 1) : 'N/A',
                                        'height' => isset($record['height']) ? number_format((float)$record['height'], 1) : 'N/A',
                                        'age_in_months' => $record['age_in_months'] !== null ? (string)$record['age_in_months'] : 'N/A',
                                        'weight_for_age_status' => (string)($record['weight_for_age_status'] ?? 'N/A'),
                                        'height_for_age_status' => (string)($record['height_for_age_status'] ?? 'N/A'),
                                        'weight_for_ltht_status' => (string)($record['weight_for_ltht_status'] ?? 'N/A'),
                                    ];
                                    continue;
                                }

                                $childRows[] = [
                                    'child_id' => 0,
                                    'name' => (string)($selectedChild['label'] ?? '—'),
                                    'address_location' => 'N/A',
                                    'sex' => 'N/A',
                                    'weight' => 'N/A',
                                    'height' => 'N/A',
                                    'age_in_months' => 'N/A',
                                    'weight_for_age_status' => 'N/A',
                                    'height_for_age_status' => 'N/A',
                                    'weight_for_ltht_status' => 'N/A',
                                ];
                            }
                        ?>
                        <tr class="intervention-row" 
                            data-type-id="<?= (int)$item['type_id'] ?>" 
                            data-type-name="<?= htmlspecialchars((string)$item['type_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>"
                            data-description="<?= htmlspecialchars((string)$item['description'], ENT_QUOTES, 'UTF-8') ?>"
                            data-date="<?= htmlspecialchars((string)$item['intervention_date'], ENT_QUOTES, 'UTF-8') ?>"
                            data-child-ids='<?= json_encode(array_values($item['children_ids'])) ?>'>
                            <td class="type-cell">
                                <span class="type-badge">
                                    <?= htmlspecialchars($item['type_name']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="child-count-badge" data-child-count>
                                    <?= count($item['children']) ?> child<?= count($item['children']) !== 1 ? 'ren' : '' ?>
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                                    <?php $encodeKeyStr = $item['type_id'] . '::' . $item['description'] . '::' . $item['intervention_date']; ?>
                                    <a
                                        href="view_interventions.php?k=<?= urlencode(base64_encode($encodeKeyStr)) ?>"
                                        class="tbl-btn-view btn-view-page" style="background:#059669 !important; border-color:#059669 !important; box-shadow:0 4px 12px rgba(5,150,105,.22) !important;">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                        View
                                    </a>

                                    <?php $hasAssignedChildren = !empty($item['children_ids']); ?>
                                    <form method="POST" class="js-delete-type-form" style="display:inline-flex;">
                                        <input type="hidden" name="action" value="delete_type">
                                        <input type="hidden" name="type_id" value="<?= (int)$item['type_id'] ?>">
                                        <button
                                            type="button"
                                            class="tbl-btn-delete js-open-delete-type-modal"
                                            data-type-name="<?= htmlspecialchars((string)($item['type_name'] ?? 'this intervention type'), ENT_QUOTES, 'UTF-8') ?>"
                                            <?= $hasAssignedChildren ? 'disabled title="Cannot delete because this type has child intervention records."' : '' ?>
                                            style="display:inline-flex;align-items:center;gap:5px;padding:6px 10px;border-radius:8px;border:1px solid #fecaca;background:#fff1f2;color:#b91c1c;font-size:11px;font-weight:700;line-height:1;cursor:<?= $hasAssignedChildren ? 'not-allowed' : 'pointer' ?>;opacity:<?= $hasAssignedChildren ? '0.55' : '1' ?>;"
                                        >
                                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3">
                            <div class="empty-state">
                                <div class="empty-icon">💊</div>
                                <div class="empty-title">No interventions yet</div>
                                <div class="empty-sub">Click "Add Intervention" to get started.</div>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- ═══════════════════════════════════════════
     MODAL: Add Intervention Type
 ════════════════════════════════════════════ -->
<div class="modal-overlay" id="typeModal">
    <div class="modal-box" style="max-width:440px;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="modal-header-icon" style="background:var(--green-light);">🏷️</div>
                <div>
                    <div class="modal-title">Add Intervention Name</div>
                    <div class="modal-sub">Create a new category for interventions</div>
                </div>
            </div>
            <button class="modal-close btn-close" data-target="typeModal">✕</button>
        </div>
        <form method="POST" id="typeForm">
            <input type="hidden" name="action" value="add_type">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:16px;">
                <div>
                    <label class="field-label" for="type_name_modal">Intervention Name <span style="color:#e11d48;">*</span></label>
                    <input class="field-input" id="type_name_modal" name="type_name" type="text" required placeholder="e.g., Deworming, Vitamin A Supplementation">
                    <div id="typeModalAlert"></div>
                    <div id="duplicateWarning" style="display:none;margin-top:8px;padding:8px 12px;background:var(--red-light);border:1px solid var(--red-border);border-radius:6px;font-size:12px;color:var(--red-text);font-weight:500;">
                        ⚠️ This type already exists
                    </div>
                    <p style="font-size:11px;color:var(--slate-400);margin-top:6px;">This name will appear in the intervention name dropdown.</p>
                </div>

                <div>
                    <label class="field-label" style="margin-bottom:8px;">Existing Names</label>
                    <div style="border:1px solid var(--slate-200);border-radius:10px;max-height:200px;overflow-y:auto;background:#fff;">
                        <?php if (!empty($types)): ?>
                            <div id="existingTypeList" style="display:flex;flex-direction:column;">
                                <?php foreach ($types as $type): ?>
                                    <div style="display:flex;align-items:center;gap:8px;padding:9px 12px;border-bottom:1px solid var(--slate-100);font-size:12px;color:var(--slate-700);">
                                        <span style="color:var(--green);font-weight:600;">✓</span>
                                        <span><?= htmlspecialchars($type['type_name']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="padding:16px;text-align:center;color:var(--slate-400);font-size:12px;">
                                No types added yet
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost btn-close" data-target="typeModal">Cancel</button>
                <button type="submit" class="btn-primary-green" id="saveTypeBtn" style="background:#2563eb !important; box-shadow:0 4px 12px rgba(37,99,235,.24) !important;">Save Type</button>
            </div>
        </form>
    </div>
    <div style="position:absolute;inset:0;z-index:0;" data-backdrop="typeModal"></div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: Add / Edit Intervention
 ════════════════════════════════════════════ -->
<div class="modal-overlay" id="interventionModal">
    <div class="modal-box" style="max-width: 1200px; border-radius: 20px;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="modal-header-icon" style="background:var(--blue-light);">💊</div>
                <div>
                    <div class="modal-title" id="interventionModalTitle">Add Intervention</div>
                    <div class="modal-sub">Select children and assign an intervention</div>
                </div>
            </div>
            <button class="modal-close btn-close" data-target="interventionModal">✕</button>
        </div>
        <form method="POST" id="interventionForm">
            <input type="hidden" name="action" id="form_action" value="add_intervention">
            <input type="hidden" name="original_type_id" id="original_type_id" value="">
            <input type="hidden" name="original_description" id="original_description" value="">
            <input type="hidden" name="confirm_override" id="confirm_override" value="0">
            <?php if ($hasDateColumn): ?>
                <input type="hidden" name="original_date" id="original_date" value="">
            <?php endif; ?>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:24px; padding: 24px;">
                
                <!-- Section: Basic Info -->
                <div style="background: var(--slate-50); padding: 18px; border-radius: 12px; border: 1px solid var(--slate-200);">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border-bottom: 1px solid var(--slate-200); padding-bottom: 8px;">
                        <span style="font-size: 16px;">📝</span>
                        <h3 style="font-size: 14px; font-weight: 700; color: var(--slate-800); margin: 0;">Basic Intervention Information</h3>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                        <div>
                            <label class="field-label" for="type_id_modal">Intervention Name <span style="color:#e11d48;">*</span></label>
                            <select class="field-select" id="type_id_modal" name="type_id" required style="height: 42px;">
                                <option value="">— Select type —</option>
                                <?php foreach ($types as $type): ?>
                                    <option value="<?= (int)$type['type_id'] ?>"><?= htmlspecialchars($type['type_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="field-label" for="intervention_date_modal">Intervention Date <span style="color:#e11d48;">*</span></label>
                            <input class="field-input" id="intervention_date_modal" name="intervention_date" type="date" required value="<?= date('Y-m-d') ?>" style="height: 42px;">
                        </div>
                    </div>
                    <div style="margin-top: 16px;">
                        <label class="field-label" for="description_modal">Description / Notes</label>
                        <textarea class="field-textarea" id="description_modal" name="description" rows="2" placeholder="Any additional details or remarks…" style="min-height: 80px;"></textarea>
                    </div>
                </div>

                <!-- Section: Give Out -->
                <div class="giveout-wrap" id="giveoutWrap" style="background: var(--blue-light); border: 1px solid var(--blue-border); border-radius: 12px; padding: 18px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; border-bottom: 1px solid var(--blue-border); padding-bottom: 8px;">
                        <span style="font-size: 16px;">📦</span>
                        <h3 style="font-size: 14px; font-weight: 700; color: var(--blue-dark); margin: 0;">Inventory Distribution (Give Out)</h3>
                    </div>
                    <div class="giveout-picker" style="display: grid; grid-template-columns: 1fr 1fr 120px 100px; gap: 12px; align-items: end;">
                        <div>
                            <label class="field-label" style="font-size: 11px; margin-bottom: 4px; color: var(--blue-dark);">Category</label>
                            <select class="field-select" id="giveoutCategorySelect" style="border-color: var(--blue-border);">
                                <option value="">— Select category —</option>
                                <?php foreach ($categoriesInventory as $cat): ?>
                                    <option value="<?= (int)$cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="field-label" style="font-size: 11px; margin-bottom: 4px; color: var(--blue-dark);">Inventory Item</label>
                            <select class="field-select" id="giveoutItemSelect" style="border-color: var(--blue-border);">
                                <option value="">— Select item —</option>
                                <?php foreach ($inventoryItems as $inv): ?>
                                    <option value="<?= (int)$inv['inventory_id'] ?>"
                                            data-category="<?= (int)$inv['category_id'] ?>"
                                            data-name="<?= htmlspecialchars($inv['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-unit="<?= htmlspecialchars($inv['unit'] ?? 'unit', ENT_QUOTES, 'UTF-8') ?>"
                                            data-max="<?= (int)$inv['quantity'] ?>">
                                        <?= htmlspecialchars($inv['item_name']) ?> (<?= (int)$inv['quantity'] ?> <?= htmlspecialchars($inv['unit'] ?? 'unit') ?> available)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="field-label" style="font-size: 11px; margin-bottom: 4px; color: var(--blue-dark);">Qty (pcs)</label>
                            <input class="field-input" id="giveoutQtyInput" type="number" min="1" value="1" placeholder="Same as # of children" title="Total pieces to give out — must equal how many children you select (1 per child)." style="border-color: var(--blue-border);">
                        </div>
                        <button type="button" class="btn-primary-blue" id="giveoutAddBtn" style="padding:10px 14px; height: 42px; width: 100%; border-radius: 8px;">+ Add Item</button>
                    </div>
                    <div class="giveout-error" id="giveoutError"></div>
                    <div id="giveoutCartWrap" style="margin-top:16px;">
                        <div class="giveout-cart-empty" id="giveoutCartEmpty" style="background: rgba(255,255,255,0.5);">Please add at least one inventory item for Give Out intervention.</div>
                        <table class="giveout-cart-table" id="giveoutCartTable" style="display:none; background: #fff; border-radius: 10px;">
                            <thead>
                                <tr style="background: var(--blue-dark); color: #fff;">
                                    <th style="color: #fff;">Item Name</th>
                                    <th style="text-align:center; color: #fff;">Available Stock</th>
                                    <th style="text-align:center; color: #fff;">Qty (pcs)<span style="font-weight:400;font-size:10px;display:block;opacity:.9;">= selected children</span></th>
                                    <th style="text-align:center; color: #fff;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="giveoutCartBody"></tbody>
                        </table>
                        <div id="giveoutCartInputs"></div>
                    </div>
                </div>

                <!-- Section: Child Selection -->
                <div style="background: #fff; padding: 18px; border-radius: 12px; border: 1px solid var(--slate-200); box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid var(--slate-200); padding-bottom: 8px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 16px;">👧</span>
                            <h3 style="font-size: 14px; font-weight: 700; color: var(--slate-800); margin: 0;">Select Eligible Children</h3>
                        </div>
                        <div style="display:flex;gap:12px;">
                            <button type="button" id="selectAllBtn" style="font-size:12px;color:var(--green);background:var(--green-light);border:1px solid var(--green-border);padding: 4px 12px; border-radius: 6px; cursor:pointer;font-weight:600;">Select All Visible</button>
                            <button type="button" id="clearAllBtn" style="font-size:12px;color:var(--slate-500);background:var(--slate-100);border:1px solid var(--slate-200);padding: 4px 12px; border-radius: 6px; cursor:pointer;font-weight:600;">Clear All</button>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;">
                        <div class="search-wrap" style="flex:1;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input class="search-input" type="text" id="childSearch" placeholder="Search children by name, location, or health status…" style="width:100%; height: 42px; font-size: 14px;">
                        </div>
                        <div id="childrenCountWrap" style="white-space:nowrap;color:var(--slate-700);font-size:13px;">
                            Showing <strong id="childrenVisibleCount">0</strong> / <strong id="childrenTotalCount"><?= count($children) ?></strong> children
                        </div>
                    </div>
                    <div class="children-table-wrap" id="childrenList" style="border-radius: 8px; border: 1px solid var(--slate-200);">
                        <table class="child-select-table" id="editChildTable">
                            <thead>
                                <tr>
                                    <th style="width:44px;">Select</th>
                                    <th style="width:12%; text-align:left;">Address</th>
                                    <th style="width:10%; text-align:left;">Barangay</th>
                                    <th style="width:12%; text-align:left;">Full Name</th>
                                    <th style="width:5%;"><span class="child-table-header"><span class="top">Sex</span></span></th>
                                    <th style="width:6%;"><span class="child-table-header"><span class="top">Age</span><span class="bottom">(months)</span></span></th>
                                    <th style="width:8%;"><span class="child-table-header"><span class="top">Height</span><span class="bottom">(cm)</span></span></th>
                                    <th style="width:8%;"><span class="child-table-header"><span class="top">Weight</span><span class="bottom">(kg)</span></span></th>
                                    <th style="width:10%;"><span class="child-table-header"><span class="top">Measurement</span><span class="bottom">Date</span></span></th>
                                    <th style="width:9%;"><span class="child-table-header"><span class="top">Height for Age</span><span class="bottom">Status</span></span></th>
                                    <th style="width:9%;"><span class="child-table-header"><span class="top">Weight for Age</span><span class="bottom">Status</span></span></th>
                                    <th style="width:9%;"><span class="child-table-header"><span class="top">Weight for Ht/L</span><span class="bottom">Status</span></span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($children)): ?>
                                    <?php foreach ($children as $child): ?>
                                            <?php
                                            $searchText = strtolower(trim(
                                                ($child['name'] ?? '') . ' ' .
                                                ($child['address'] ?? '') . ' ' .
                                                ($child['barangay_name'] ?? '') . ' ' .
                                                ($child['measurement_date'] ?? '') . ' ' .
                                                ($child['sex'] ?? '') . ' ' .
                                                (isset($child['height']) ? (string)$child['height'] : '') . ' ' .
                                                (isset($child['weight']) ? (string)$child['weight'] : '') . ' ' .
                                                ($child['weight_for_age_status'] ?? '') . ' ' .
                                                ($child['height_for_age_status'] ?? '') . ' ' .
                                                ($child['weight_for_ltht_status'] ?? '')
                                            ));
                                            $rowClass = !empty($child['is_eligible']) ? '' : ' is-hidden';
                                        ?>
                                        <tr class="child-check-row child-profile-row<?= $rowClass ?>" data-child-id="<?= (int)$child['child_id'] ?>" data-search="<?= htmlspecialchars($searchText) ?>" data-eligible="<?= !empty($child['is_eligible']) ? '1' : '0' ?>" data-measurement-date="<?= htmlspecialchars($child['measurement_date'] ?? '') ?>">
                                            <td class="child-check-cell">
                                                <input type="checkbox" name="child_ids[]" value="<?= (int)$child['child_id'] ?>">
                                            </td>
                                            <td class="child-location-cell" title="<?= htmlspecialchars($child['address'] ?? '—') ?>">
                                                <?= htmlspecialchars($child['address'] ?? '—') ?>
                                            </td>
                                            <td class="child-barangay-cell" title="<?= htmlspecialchars($child['barangay_name'] ?? '—') ?>">
                                                <?= htmlspecialchars($child['barangay_name'] ?? '—') ?>
                                            </td>
                                            <td class="child-name-cell">
                                                <?= htmlspecialchars($child['name'] ?? '—') ?>
                                            </td>
                                            <td class="child-sex-cell">
                                                <?= htmlspecialchars($child['sex'] ?? 'N/A') ?>
                                            </td>
                                            <td class="child-age-cell">
                                                <?= $child['age_in_months'] !== null ? htmlspecialchars((string)$child['age_in_months']) : 'N/A' ?>
                                            </td>
                                            <td class="child-height-cell">
                                                <?= $child['height'] !== null ? htmlspecialchars((string)$child['height']) : '—' ?>
                                            </td>
                                            <td class="child-weight-cell">
                                                <?= $child['weight'] !== null ? htmlspecialchars((string)$child['weight']) : '—' ?>
                                            </td>
                                            <td class="child-date-cell">
                                                <?= htmlspecialchars($child['measurement_date'] ?? '—') ?>
                                            </td>
                                            <td class="child-status-cell <?= htmlspecialchars(status_cell_class($child['height_for_age_status'] ?? 'N/A')) ?>" title="<?= htmlspecialchars($child['height_for_age_status'] ?? 'N/A') ?>">
                                                <?= htmlspecialchars(status_abbrev($child['height_for_age_status'] ?? 'N/A')) ?>
                                            </td>
                                            <td class="child-status-cell <?= htmlspecialchars(status_cell_class($child['weight_for_age_status'] ?? 'N/A')) ?>" title="<?= htmlspecialchars($child['weight_for_age_status'] ?? 'N/A') ?>">
                                                <?= htmlspecialchars(status_abbrev($child['weight_for_age_status'] ?? 'N/A')) ?>
                                            </td>
                                            <td class="child-status-cell <?= htmlspecialchars(status_cell_class($child['weight_for_ltht_status'] ?? 'N/A')) ?>" title="<?= htmlspecialchars($child['weight_for_ltht_status'] ?? 'N/A') ?>">
                                                <?= htmlspecialchars(status_abbrev($child['weight_for_ltht_status'] ?? 'N/A')) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="12" class="empty-state" style="padding:28px 16px;">
                                            <div class="empty-title">No children found</div>
                                            <div class="empty-sub">There are no active children right now.</div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <p style="font-size:11px;color:var(--slate-400);margin-top:6px;">
                        <span id="checkedCount">0</span> child(ren) selected
                    </p>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost btn-close" data-target="interventionModal">Cancel</button>
                <button type="submit" class="btn-primary-blue" style="background:#2563eb !important; box-shadow:0 4px 12px rgba(37,99,235,.24) !important;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                    Save Intervention
                </button>
            </div>
        </form>
    </div>
    <div style="position:absolute;inset:0;z-index:0;" data-backdrop="interventionModal"></div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: View Details
 ════════════════════════════════════════════ -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-box" style="max-width:min(1400px, 98vw);">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="modal-header-icon" style="background:var(--slate-100);">📋</div>
                <div>
                    <div class="modal-title">Intervention Details</div>
                    <div class="modal-sub" id="viewModalType"></div>
                </div>
            </div>
            <button class="modal-close btn-close" data-target="viewModal">✕</button>
        </div>
        <div class="modal-body" style="display:flex;flex-direction:column;gap:0;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
                <div class="view-detail-block">
                    <div class="view-detail-label">Intervention Type</div>
                    <div class="view-detail-value" id="viewModalTypeName" style="display:inline-flex;align-items:center;background:var(--green-light);color:var(--green-dark);border:1px solid var(--green-border);border-radius:20px;padding:3px 11px;font-size:12px;font-weight:600;"></div>
                </div>
                <div class="view-detail-block">
                    <div class="view-detail-label">Intervention Date</div>
                    <div class="view-detail-value" id="viewModalDate"></div>
                </div>
            </div>
            <div class="view-detail-block" style="margin-bottom:18px;">
                <div class="view-detail-label">Description / Notes</div>
                <div class="view-detail-value" id="viewModalDescription" style="white-space:pre-line;line-height:1.6;"></div>
            </div>
            <div>
                <div class="view-detail-label" style="margin-bottom:10px;">Children Enrolled (<span id="viewChildCount">0</span>)</div>
                <div class="view-children-table-wrap" id="viewModalChildren"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-ghost btn-close" data-target="viewModal">Close</button>
        </div>
    </div>
    <div style="position:absolute;inset:0;z-index:0;" data-backdrop="viewModal"></div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: Confirm Child Profile
 ════════════════════════════════════════════ -->
<div class="modal-overlay" id="confirmChildModal">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="modal-header-icon" style="background:var(--blue-light);">👶</div>
                <div>
                    <div class="modal-title">View Child Profile</div>
                    <div class="modal-sub">Confirm before leaving this page</div>
                </div>
            </div>
            <button class="modal-close btn-close" data-target="confirmChildModal">✕</button>
        </div>
        <div class="modal-body">
            <div style="font-size:13px;color:var(--slate-700);line-height:1.6;">
                Are you sure you want to view this child profile?
                <div id="confirmChildName" style="margin-top:6px;font-weight:700;color:var(--slate-900);"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-ghost btn-close" data-target="confirmChildModal">Cancel</button>
            <button type="button" class="btn-primary-blue inline-flex items-center gap-1.5" id="confirmChildGo">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                Yes, view profile
            </button>
        </div>
    </div>
    <div style="position:absolute;inset:0;z-index:0;" data-backdrop="confirmChildModal"></div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: Past Intervention Confirmation
 ════════════════════════════════════════════ -->
<div class="modal-overlay" id="pastInterventionModal">
    <div class="modal-box" style="max-width:560px;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="modal-header-icon" style="background:var(--red-light);">⚠️</div>
                <div>
                    <div class="modal-title">Past Intervention Found</div>
                    <div class="modal-sub">Review before adding the same type</div>
                </div>
            </div>
            <button class="modal-close btn-close" data-target="pastInterventionModal">✕</button>
        </div>
        <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">
            <div style="font-size:13px;color:var(--slate-700);line-height:1.6;">
                These children already have a past intervention of the selected type. The most recent record is shown below.
            </div>
            <div id="pastInterventionList" style="display:flex;flex-direction:column;gap:10px;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-ghost btn-close" data-target="pastInterventionModal">Cancel</button>
            <button type="button" class="btn-primary-blue" id="pastInterventionProceed">Continue Adding</button>
        </div>
    </div>
    <div style="position:absolute;inset:0;z-index:0;" data-backdrop="pastInterventionModal"></div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: Confirm Delete Type
════════════════════════════════════════════ -->
<div class="modal-overlay" id="deleteTypeModal">
    <div class="modal-box" style="max-width:440px;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="modal-header-icon" style="background:var(--red-light);">🗑️</div>
                <div>
                    <div class="modal-title">Delete Intervention Type</div>
                    <div class="modal-sub">This action cannot be undone</div>
                </div>
            </div>
            <button class="modal-close btn-close" data-target="deleteTypeModal">✕</button>
        </div>
        <div class="modal-body">
            <div style="font-size:13px;color:var(--slate-700);line-height:1.6;">
                Delete this intervention type?
                <div id="deleteTypeNameText" style="margin-top:8px;font-weight:700;color:var(--slate-900);"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-ghost btn-close" data-target="deleteTypeModal">Cancel</button>
            <button type="button" class="btn-delete" id="confirmDeleteTypeBtn" style="width:auto;margin-top:0;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                <span class="js-delete-type-btn-label">Delete</span>
            </button>
        </div>
    </div>
    <div style="position:absolute;inset:0;z-index:0;" data-backdrop="deleteTypeModal"></div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: Validation Error
 ════════════════════════════════════════════ -->
<div class="modal-overlay" id="validationErrorModal">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="modal-header-icon" style="background:var(--red-light);">⚠️</div>
                <div>
                    <div class="modal-title">Validation Error</div>
                    <div class="modal-sub">Please review your input</div>
                </div>
            </div>
            <button class="modal-close btn-close" data-target="validationErrorModal">✕</button>
        </div>
        <div class="modal-body">
            <div id="validationErrorMessage" style="font-size:13px;color:var(--slate-700);line-height:1.6;padding:8px 0;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-primary-blue btn-close" data-target="validationErrorModal">OK</button>
        </div>
    </div>
    <div style="position:absolute;inset:0;z-index:0;" data-backdrop="validationErrorModal"></div>
</div>

<script>
    window.existingTypes = <?= json_encode(array_map(function($t) { return strtolower($t['type_name']); }, $types)) ?>;
    window.giveOutTypeId = <?= (int)$giveOutTypeId ?>;
    window.currentDate = '<?= date('Y-m-d') ?>';
    window.inventoryItems = <?= json_encode($inventoryItems) ?>;
    window.inventoryCategories = <?= json_encode($categoriesInventory) ?>;
</script>
<script src="javascript/interventions.js?v=2"></script>
<?php if ($editPrefill): ?>
<script>
    openEditIntervention({
        type_id: <?= (int)$editPrefill['type_id'] ?>,
        description: <?= json_encode($editPrefill['description']) ?>,
        date: <?= json_encode($editPrefill['date']) ?>,
        child_ids: <?= json_encode(array_values($editPrefill['child_ids'])) ?>
    });
</script>
<?php endif; ?>
</body>
</html>
  
