<?php
// growth_utils.php
// Helpers for resolving growth chart references and determining statuses

// Table name constants for clarity
if (!defined('GROWTH_WEIGHT_TABLE')) {
    define('GROWTH_WEIGHT_TABLE', 'weight_for_age');
}

if (!defined('GROWTH_HEIGHT_TABLE')) {
    define('GROWTH_HEIGHT_TABLE', 'height_for_age');
}

if (!defined('GROWTH_WFL_TABLE')) {
    define('GROWTH_WFL_TABLE', 'weight_for_length');
}

/**
 * Fetch a single growth reference row for the given age (in months) and sex.
 *
 * @param mysqli $conn
 * @param string $table  Either GROWTH_WEIGHT_TABLE or GROWTH_HEIGHT_TABLE
 * @param int    $ageMonth
 * @param string $sex    'Male' or 'Female'
 *
 * @return array|null
 */
function fetchGrowthReference(mysqli $conn, string $table, int $ageMonth, string $sex): ?array
{
    // Simple protection: only allow the two expected tables
    if ($table !== GROWTH_WEIGHT_TABLE && $table !== GROWTH_HEIGHT_TABLE) {
        return null;
    }

    $sql = "SELECT * FROM {$table} WHERE age_month = ? AND sex = ? LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('is', $ageMonth, $sex);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function fetchClosestGrowthReference(mysqli $conn, string $table, int $ageMonth, string $sex, bool &$outOfRange = false): ?array
{
    $outOfRange = false;

    if ($table !== GROWTH_WEIGHT_TABLE && $table !== GROWTH_HEIGHT_TABLE) {
        return null;
    }

    // Determine the actual range available in the database for this sex
    $rangeSql = "SELECT MIN(age_month) AS min_age, MAX(age_month) AS max_age FROM {$table} WHERE sex = ?";
    $rangeStmt = $conn->prepare($rangeSql);
    if (!$rangeStmt) {
        return null;
    }

    $rangeStmt->bind_param('s', $sex);
    $rangeStmt->execute();
    $rangeResult = $rangeStmt->get_result();
    $rangeRow = $rangeResult ? $rangeResult->fetch_assoc() : null;
    $rangeStmt->close();

    if (!$rangeRow || $rangeRow['min_age'] === null || $rangeRow['max_age'] === null) {
        return null;
    }

    $minAge = (int)$rangeRow['min_age'];
    $maxAge = (int)$rangeRow['max_age'];

    // If age is outside the reference table, mark it as outOfRange but proceed with the closest available age
    if ($ageMonth < $minAge || $ageMonth > $maxAge) {
        $outOfRange = true;
    }

    // Clamp the age to the available range for the search
    $queryAge = max($minAge, min($maxAge, $ageMonth));

    $sql = "SELECT * FROM {$table} WHERE sex = ? ORDER BY ABS(age_month - ?) ASC, age_month ASC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('si', $sex, $queryAge);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

/**
 * Determine Height-for-Age status using height_for_age row.
 *
 * Expected columns on $ref:
 *  - severely_stunted_max
 *  - stunted_min, stunted_max
 *  - normal_min, normal_max
 *  - tall_min
 */
function normalizeHeightForAgeRef(array $ref): ?array
{
    if (isset(
        $ref['severely_stunted_max'],
        $ref['stunted_min'], $ref['stunted_max'],
        $ref['normal_min'],  $ref['normal_max'],
        $ref['tall_min']
    )) {
        return [
            'severely_stunted_max' => (float)$ref['severely_stunted_max'],
            'stunted_min' => (float)$ref['stunted_min'],
            'stunted_max' => (float)$ref['stunted_max'],
            'normal_min' => (float)$ref['normal_min'],
            'normal_max' => (float)$ref['normal_max'],
            'tall_min' => (float)$ref['tall_min'],
        ];
    }

    if (isset(
        $ref['severely_stunted'],
        $ref['stunted_from'], $ref['stunted_to'],
        $ref['normal_from'],  $ref['normal_to'],
        $ref['tall']
    )) {
        return [
            'severely_stunted_max' => (float)$ref['severely_stunted'],
            'stunted_min' => (float)$ref['stunted_from'],
            'stunted_max' => (float)$ref['stunted_to'],
            'normal_min' => (float)$ref['normal_from'],
            'normal_max' => (float)$ref['normal_to'],
            'tall_min' => (float)$ref['tall'],
        ];
    }

    return null;
}

function determineHeightForAgeStatus(float $heightCm, array $ref): ?string
{
    $bands = normalizeHeightForAgeRef($ref);
    if (!$bands) {
        return null;
    }

    $h  = round($heightCm, 1);
    $s0 = $bands['severely_stunted_max'];
    $s2 = $bands['stunted_max'];
    $n2 = $bands['normal_max'];
    // $t1 is typically $n2 + 0.1

    if ($h <= $s0) {
        return 'Severely Stunted';
    }
    if ($h <= $s2) {
        return 'Stunted';
    }
    if ($h <= $n2) {
        return 'Normal';
    }
    return 'Tall';
}

function fetchWeightForLengthReference(mysqli $conn, string $sex, float $lengthCm, bool &$outOfRange = false): ?array
{
    $outOfRange = false;
    $lengthCm = round($lengthCm, 1);
    $ageGroup = null;

    return fetchWeightForLengthReferenceByAgeGroup($conn, $sex, $ageGroup, $lengthCm, $outOfRange);
}

function resolveWeightForLengthAgeGroup(int $ageInMonths): ?string
{
    if ($ageInMonths >= 0 && $ageInMonths <= 23) {
        return '0-23months';
    }
    if ($ageInMonths >= 24 && $ageInMonths <= 60) {
        return '24-60months';
    }

    return null;
}

function fetchWeightForLengthReferenceByAgeGroup(
    mysqli $conn,
    string $sex,
    ?string $ageGroup,
    float $lengthCm,
    bool &$outOfRange = false
): ?array
{
    $outOfRange = false;
    $lengthCm = round($lengthCm, 1);

    $rangeSql = "SELECT MIN(length_cm) AS min_len, MAX(length_cm) AS max_len
                 FROM " . GROWTH_WFL_TABLE . "
                 WHERE sex = ?" . ($ageGroup !== null ? " AND age_group = ?" : "");
    $rangeStmt = $conn->prepare($rangeSql);
    if (!$rangeStmt) {
        return null;
    }

    if ($ageGroup !== null) {
        $rangeStmt->bind_param('ss', $sex, $ageGroup);
    } else {
        $rangeStmt->bind_param('s', $sex);
    }
    $rangeStmt->execute();
    $rangeResult = $rangeStmt->get_result();
    $rangeRow = $rangeResult ? $rangeResult->fetch_assoc() : null;
    $rangeStmt->close();

    if (!$rangeRow || $rangeRow['min_len'] === null || $rangeRow['max_len'] === null) {
        return null;
    }

    $minLen = (float)$rangeRow['min_len'];
    $maxLen = (float)$rangeRow['max_len'];

    if ($lengthCm < $minLen || $lengthCm > $maxLen) {
        $outOfRange = true;
    }

    // Clamp length to available range
    $queryLen = max($minLen, min($maxLen, $lengthCm));

    $sql = "SELECT * FROM " . GROWTH_WFL_TABLE . "
            WHERE sex = ?" . ($ageGroup !== null ? " AND age_group = ?" : "") . "
            ORDER BY ABS(length_cm - ?) ASC, length_cm ASC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    if ($ageGroup !== null) {
        $stmt->bind_param('ssd', $sex, $ageGroup, $lengthCm);
    } else {
        $stmt->bind_param('sd', $sex, $lengthCm);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function normalizeWeightForLengthRef(array $ref): ?array
{
    if (isset(
        $ref['severely_wasted'],
        $ref['wasted_from'], $ref['wasted_to'],
        $ref['normal_from'], $ref['normal_to'],
        $ref['overweight_from'], $ref['overweight_to'],
        $ref['obese']
    )) {
        return [
            'severely_wasted' => (float)$ref['severely_wasted'],
            'wasted_from' => (float)$ref['wasted_from'],
            'wasted_to' => (float)$ref['wasted_to'],
            'normal_from' => (float)$ref['normal_from'],
            'normal_to' => (float)$ref['normal_to'],
            'overweight_from' => (float)$ref['overweight_from'],
            'overweight_to' => (float)$ref['overweight_to'],
            'obese' => (float)$ref['obese'],
        ];
    }

    if (isset(
        $ref['severely_wasted_max'],
        $ref['wasted_min'], $ref['wasted_max'],
        $ref['normal_min'], $ref['normal_max'],
        $ref['overweight_min'], $ref['overweight_max'],
        $ref['obese_min']
    )) {
        return [
            'severely_wasted' => (float)$ref['severely_wasted_max'],
            'wasted_from' => (float)$ref['wasted_min'],
            'wasted_to' => (float)$ref['wasted_max'],
            'normal_from' => (float)$ref['normal_min'],
            'normal_to' => (float)$ref['normal_max'],
            'overweight_from' => (float)$ref['overweight_min'],
            'overweight_to' => (float)$ref['overweight_max'],
            'obese' => (float)$ref['obese_min'],
        ];
    }

    return null;
}

function determineWeightForLengthStatus(float $weightKg, array $ref): ?string
{
    $bands = normalizeWeightForLengthRef($ref);
    if (!$bands) {
        return null;
    }

    $w = $weightKg;
    $s0 = $bands['severely_wasted'];
    $w2 = $bands['wasted_to'];
    $n2 = $bands['normal_to'];
    $o2 = $bands['overweight_to'];

    if ($w <= $s0) {
        return 'Severely Wasted';
    }
    if ($w <= $w2) {
        return 'Wasted';
    }
    if ($w <= $n2) {
        return 'Normal';
    }
    if ($w <= $o2) {
        return 'Overweight';
    }
    return 'Obese';
}

/**
 * Determine Weight-for-Age status using weight_for_age row.
 *
 * Expected columns on $ref:
 *  - severely_underweight_max
 *  - underweight_min, underweight_max
 *  - normal_min, normal_max
 */
function determineWeightForAgeStatus(float $weightKg, array $ref): ?string
{
    if (!isset(
        $ref['severely_underweight_max'],
        $ref['underweight_max'],
        $ref['normal_max']
    )) {
        return null;
    }

    $w  = $weightKg;
    $u0 = (float)$ref['severely_underweight_max'];
    $u2 = (float)$ref['underweight_max'];
    $n2 = (float)$ref['normal_max'];
    $ow = isset($ref['overweight']) ? (float)$ref['overweight'] : null;

    if ($w <= $u0) {
        return 'Severely Underweight';
    }
    if ($w <= $u2) {
        return 'Underweight';
    }
    if ($w <= $n2) {
        return 'Normal';
    }
    
    // Overweight threshold is a single cutoff (>= overweight).
    if ($ow !== null && $ow > 0 && $w >= $ow) {
        return 'Overweight';
    }
    
    // If no overweight threshold or not reached, it's still Normal or slightly above Normal
    return 'Normal';
}

/**
 * Determine MUAC status based on the measurement (cm).
 *
 * @param float $muacCm
 * @return string|null
 */
function determineMuacStatus(float $muacCm): ?string
{
    if ($muacCm < 0) {
        return null;
    }
    
    // Red Zone / Severely Wasted = below 11.5 cm
    if ($muacCm < 11.5) {
        return 'Severely Wasted';
    }
    
    // Yellow Zone / Moderately Wasted = 11.5 cm to less than 12.5 cm
    if ($muacCm >= 11.5 && $muacCm < 12.5) {
        return 'Moderately Wasted';
    }
    
    // Green Zone / Normal = 12.5 cm and above
    if ($muacCm >= 12.5) {
        return 'Normal';
    }
    
    return null;
}

/**
 * Get the muac_id based on the status string.
 *
 * @param string $status
 * @return int|null
 */
function getMuacIdFromStatus(string $status): ?int
{
    // The muac table currently contains threshold references in a single row (ID 1).
    // We link to this reference row to indicate which thresholds were used.
    // In the future, if age-based MUAC thresholds are added, this logic should be updated.
    return 1;
}

