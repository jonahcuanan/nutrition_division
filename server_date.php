<?php
session_start();
require_once __DIR__ . '/access_control.php';
enforce_page_access(['expectsJson' => true]);

header('Content-Type: application/json');

try {
    $today = (new DateTime('today'))->format('Y-m-d');
    echo json_encode(['success' => true, 'today' => $today]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to determine server date.']);
}
