<?php
/**
 * List Audit Log Entries Endpoint
 * GET /api/audit/list.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Database.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
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
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = ($page - 1) * $limit;

// Build query
$db = Database::getInstance();
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

// Get total count
$countSql = "SELECT COUNT(*) FROM audit_log a {$whereClause}";
$total = $db->queryValue($countSql, $params);

// Get audit log entries
$sql = "SELECT a.id, a.user_id, a.action, a.target_type, a.target_id, a.timestamp, a.details, a.ip_address,
               u.username
        FROM audit_log a
        LEFT JOIN users u ON a.user_id = u.id
        {$whereClause}
        ORDER BY a.timestamp DESC
        LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$entries = $db->query($sql, $params);

// Parse details JSON
foreach ($entries as &$entry) {
    if ($entry['details']) {
        $entry['details'] = json_decode($entry['details'], true);
    }
}

// Return response
echo json_encode([
    'success' => true,
    'entries' => $entries,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => (int)$total,
        'pages' => ceil($total / $limit)
    ]
]);

