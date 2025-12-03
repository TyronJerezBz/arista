<?php
/**
 * List All Switches Endpoint
 * GET /api/switches/list.php
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

// Ensure Database class is loaded
if (!class_exists('Database')) {
    http_response_code(500);
    echo json_encode(['error' => 'Database class not found']);
    exit;
}

// Get optional filters
$status = $_GET['status'] ?? null;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = ($page - 1) * $limit;

// Build query - ensure we get Database instance
try {
    $db = Database::getInstance();
    
    // Critical safety check: ensure we have a Database instance, not a PDO object
    if (!($db instanceof Database)) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Database initialization error',
            'debug' => [
                'type' => get_class($db),
                'is_database' => $db instanceof Database,
                'is_pdo' => $db instanceof PDO,
                'class_exists' => class_exists('Database')
            ]
        ]);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database initialization failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

$where = [];
$params = [];

if ($status && in_array($status, ['up', 'down', 'unknown'])) {
    $where[] = "status = ?";
    $params[] = $status;
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Get total count
$countSql = "SELECT COUNT(*) FROM switches {$whereClause}";
$total = $db->queryValue($countSql, $params);

// Get switches
$sql = "SELECT id, hostname, ip_address, model, role, firmware_version, tags, status, last_seen, last_polled, created_at, updated_at 
        FROM switches 
        {$whereClause}
        ORDER BY hostname ASC
        LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$switches = $db->query($sql, $params);

// Return response
echo json_encode([
    'success' => true,
    'switches' => $switches,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => (int)$total,
        'pages' => ceil($total / $limit)
    ]
]);


