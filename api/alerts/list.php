<?php
/**
 * List Alerts Endpoint
 * GET /api/alerts/list.php
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

// Require authentication (viewer minimum)
requireAuth();

// Get optional filters
$switchId = isset($_GET['switch_id']) ? (int)$_GET['switch_id'] : null;
$severity = $_GET['severity'] ?? null;
$acknowledged = isset($_GET['acknowledged']) ? $_GET['acknowledged'] : null;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = ($page - 1) * $limit;

// Build query
$db = Database::getInstance();
$where = [];
$params = [];

if ($switchId) {
    $where[] = "a.switch_id = ?";
    $params[] = $switchId;
}

if ($severity && in_array($severity, ['info', 'warning', 'critical'])) {
    $where[] = "a.severity = ?";
    $params[] = $severity;
}

if ($acknowledged !== null) {
    $acknowledged = filter_var($acknowledged, FILTER_VALIDATE_BOOLEAN);
    $where[] = "a.acknowledged = ?";
    $params[] = $acknowledged ? 1 : 0;
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Get total count
$countSql = "SELECT COUNT(*) FROM alerts a {$whereClause}";
$total = $db->queryValue($countSql, $params);

// Get alerts
$sql = "SELECT a.id, a.switch_id, a.severity, a.message, a.timestamp, a.acknowledged, 
               a.acknowledged_by, a.acknowledged_at,
               s.hostname, s.ip_address,
               u.username as acknowledged_by_username
        FROM alerts a
        LEFT JOIN switches s ON a.switch_id = s.id
        LEFT JOIN users u ON a.acknowledged_by = u.id
        {$whereClause}
        ORDER BY a.timestamp DESC
        LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$alerts = $db->query($sql, $params);

// Return response
echo json_encode([
    'success' => true,
    'alerts' => $alerts,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => (int)$total,
        'pages' => ceil($total / $limit)
    ]
]);

