<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access();
require_once __DIR__ . '/database.php';

header('Content-Type: application/json');

$sessionFile = __DIR__ . '/measurement_session.txt';
$cutoffRecordId = file_exists($sessionFile) ? (int)trim(file_get_contents($sessionFile)) : 0;
$sessionFileExists = file_exists($sessionFile);
$sessionFileMtime = $sessionFileExists ? date('Y-m-d H:i:s', filemtime($sessionFile)) : null;
$sessionFileContent = $sessionFileExists ? trim(file_get_contents($sessionFile)) : null;

// Max record_id
$res = $conn->query("SELECT MAX(record_id) as max_id FROM growth_records");
$maxRow = $res->fetch_assoc();
$maxId = (int)($maxRow['max_id'] ?? 0);

// Total active children
$activeRes = $conn->query("SELECT COUNT(*) as cnt FROM children WHERE status = 'Active'");
$activeRow = $activeRes->fetch_assoc();
$totalActive = (int)$activeRow['cnt'];

// Children measured after cutoff (is_muac_only=FALSE, weight>0, height>0)
$measuredAfterSql = "
    SELECT COUNT(DISTINCT c.child_id) as cnt
    FROM children c
    WHERE c.status = 'Active'
    AND EXISTS (
        SELECT 1 FROM growth_records gr
        WHERE gr.child_id = c.child_id
        AND gr.record_id > $cutoffRecordId
        AND gr.is_muac_only = FALSE
        AND COALESCE(gr.weight, 0) > 0
        AND COALESCE(gr.height, 0) > 0
    )
";
$measuredRes = $conn->query($measuredAfterSql);
$measuredRow = $measuredRes->fetch_assoc();
$measuredAfterCutoff = (int)$measuredRow['cnt'];

// List of unmeasured children
$unmeasuredSql = "
    SELECT c.child_id, c.first_name, c.last_name, c.status,
        (SELECT MAX(gr2.record_id) FROM growth_records gr2 WHERE gr2.child_id = c.child_id) as max_record_id,
        (SELECT MAX(gr3.record_id) FROM growth_records gr3 WHERE gr3.child_id = c.child_id AND gr3.record_id > $cutoffRecordId AND gr3.is_muac_only = FALSE AND COALESCE(gr3.weight,0) > 0 AND COALESCE(gr3.height,0) > 0) as qualifying_record_id,
        (SELECT gr4.weight FROM growth_records gr4 WHERE gr4.child_id = c.child_id ORDER BY gr4.record_id DESC LIMIT 1) as latest_weight,
        (SELECT gr5.height FROM growth_records gr5 WHERE gr5.child_id = c.child_id ORDER BY gr5.record_id DESC LIMIT 1) as latest_height,
        (SELECT gr6.is_muac_only FROM growth_records gr6 WHERE gr6.child_id = c.child_id ORDER BY gr6.record_id DESC LIMIT 1) as latest_is_muac_only
    FROM children c
    WHERE c.status = 'Active'
    AND NOT EXISTS (
        SELECT 1 FROM growth_records gr
        WHERE gr.child_id = c.child_id
        AND gr.record_id > $cutoffRecordId
        AND gr.is_muac_only = FALSE
        AND COALESCE(gr.weight, 0) > 0
        AND COALESCE(gr.height, 0) > 0
    )
    ORDER BY c.last_name, c.first_name
";
$unmeasuredRes = $conn->query($unmeasuredSql);
$unmeasuredChildren = [];
if ($unmeasuredRes) {
    while ($row = $unmeasuredRes->fetch_assoc()) {
        $unmeasuredChildren[] = $row;
    }
}

echo json_encode([
    'session_file_exists' => $sessionFileExists,
    'session_file_mtime' => $sessionFileMtime,
    'session_file_content' => $sessionFileContent,
    'cutoff_record_id' => $cutoffRecordId,
    'max_record_id' => $maxId,
    'total_active_children' => $totalActive,
    'measured_after_cutoff' => $measuredAfterCutoff,
    'unmeasured_count' => count($unmeasuredChildren),
    'unmeasured_children' => $unmeasuredChildren,
], JSON_PRETTY_PRINT);
