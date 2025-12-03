<?php
/**
 * Export Audit Log Endpoint
 * GET /api/audit/export.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die('Method not allowed');
}

// Require admin role
requireRole('admin');

// Get optional filters
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$targetType = $_GET['target_type'] ?? null;
$targetId = isset($_GET['target_id']) ? (int)$_GET['target_id'] : null;
$action = $_GET['action'] ?? null;
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

// Build query
$db = getDB();
$where = [];
$params = [];

if ($userId) {
    $where[] = "a.user_id = ?";
    $params[] = $userId;
}

if ($targetType) {
    $where[] = "a.target_type = ?";
    $params[] = $targetType;
}

if ($targetId) {
    $where[] = "a.target_id = ?";
    $params[] = $targetId;
}

if ($action) {
    $where[] = "a.action LIKE ?";
    $params[] = "%{$action}%";
}

if ($startDate) {
    $where[] = "a.timestamp >= ?";
    $params[] = $startDate;
}

if ($endDate) {
    $where[] = "a.timestamp <= ?";
    $params[] = $endDate . ' 23:59:59';
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Get audit log entries
$sql = "SELECT a.id, a.user_id, a.action, a.target_type, a.target_id, a.timestamp, a.details, a.ip_address,
               u.username
        FROM audit_log a
        LEFT JOIN users u ON a.user_id = u.id
        {$whereClause}
        ORDER BY a.timestamp DESC";
$entries = $db->query($sql, $params);

// Set headers for CSV download
$filename = 'audit_log_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Open output stream
$output = fopen('php://output', 'w');

// Write CSV header
fputcsv($output, ['ID', 'Timestamp', 'User', 'Action', 'Target Type', 'Target ID', 'Details', 'IP Address']);

// Write data rows
foreach ($entries as $entry) {
    $details = $entry['details'] ? json_encode(json_decode($entry['details'], true)) : '';
    
    fputcsv($output, [
        $entry['id'],
        $entry['timestamp'],
        $entry['username'] ?? 'N/A',
        $entry['action'],
        $entry['target_type'] ?? '',
        $entry['target_id'] ?? '',
        $details,
        $entry['ip_address'] ?? ''
    ]);
}

fclose($output);
exit;

