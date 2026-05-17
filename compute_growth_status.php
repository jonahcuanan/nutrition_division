<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access(['expectsJson' => true]);

header('Content-Type: application/json');

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/growth_utils.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
	exit;
}

$sex = trim($_POST['sex'] ?? '');
$age_in_months = isset($_POST['age_in_months']) ? (int)$_POST['age_in_months'] : null;
$height = isset($_POST['height']) ? (float)$_POST['height'] : null;
$weight = isset($_POST['weight']) ? (float)$_POST['weight'] : null;

if ($sex === '' || $age_in_months === null || $height === null || $weight === null) {
	echo json_encode(['success' => false, 'message' => 'Missing required inputs.']);
	exit;
}

if ($age_in_months < 0 || $height <= 0 || $weight <= 0) {
	echo json_encode(['success' => false, 'message' => 'Invalid measurement values.']);
	exit;
}

$normalizedSex = ucfirst(strtolower($sex));


$weightOutOfRange = false;
$heightOutOfRange = false;
$wflOutOfRange = false;

$weightRef = fetchClosestGrowthReference($conn, GROWTH_WEIGHT_TABLE, $age_in_months, $normalizedSex, $weightOutOfRange);
$heightRef = fetchClosestGrowthReference($conn, GROWTH_HEIGHT_TABLE, $age_in_months, $normalizedSex, $heightOutOfRange);
$wflAgeGroup = resolveWeightForLengthAgeGroup($age_in_months);
$wflRef = null;

if ($wflAgeGroup === null) {
    $wflOutOfRange = true;
} else {
    $wflRef = fetchWeightForLengthReferenceByAgeGroup($conn, $normalizedSex, $wflAgeGroup, $height, $wflOutOfRange);
}

$weightStatus = null;
$heightStatus = null;
$wflStatus = null;
$muacStatus = null;

$muac = isset($_POST['muac']) ? (float)$_POST['muac'] : null;

if (!$weightRef) {
	echo json_encode(['success' => false, 'message' => 'No matching weight-for-age reference found for this sex.']);
	exit;
} else {
	$weightStatus = determineWeightForAgeStatus($weight, $weightRef);
    if ($weightStatus === null) {
        echo json_encode(['success' => false, 'message' => 'Unable to determine weight-for-age status.']);
        exit;
    }
}

if (!$heightRef) {
	echo json_encode(['success' => false, 'message' => 'No matching height-for-age reference found for this sex.']);
	exit;
} else {
	$heightStatus = determineHeightForAgeStatus($height, $heightRef);
    if ($heightStatus === null) {
        echo json_encode(['success' => false, 'message' => 'Unable to determine height-for-age status.']);
        exit;
    }
}

if (!$wflRef) {
	echo json_encode(['success' => false, 'message' => 'No matching weight-for-length reference found for this sex.']);
	exit;
} else {
	$wflStatus = determineWeightForLengthStatus($weight, $wflRef);
    if ($wflStatus === null) {
        echo json_encode(['success' => false, 'message' => 'Unable to determine weight-for-length status.']);
        exit;
    }
}

if ($muac !== null) {
    $muacStatus = determineMuacStatus($muac);
}

echo json_encode([
	'success' => true,
	'data' => [
		'height_for_age_status' => $heightStatus,
		'weight_for_age_status' => $weightStatus,
		'weight_for_ltht_status' => $wflStatus,
        'muac_status' => $muacStatus,
		'height_id' => $heightRef && isset($heightRef['height_id']) ? (int)$heightRef['height_id'] : null,
		'weight_id' => $weightRef && isset($weightRef['weight_id']) ? (int)$weightRef['weight_id'] : null,
		'wfl_id' => $wflRef && isset($wflRef['wfl_id']) ? (int)$wflRef['wfl_id'] : null,
        'muac_id' => $muacStatus ? 1 : null
	]
]);
