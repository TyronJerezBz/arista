<?php
/**
 * Get Port Channel Load Balance Statistics Endpoint
 * GET /api/switches/port-channels/load-balance.php?switch_id=<id>[&port_channel=<name>]
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require authentication (viewer minimum)
requireAuth();

// Get switch ID
$switchId = $_GET['switch_id'] ?? null;
if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}
$switchId = (int) $switchId;

$db = Database::getInstance();
$switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Optional port channel name filter
$portChannelName = $_GET['port_channel'] ?? null;
if ($portChannelName) {
    $portChannelName = trim($portChannelName);
}

try {
    $eapi = new AristaEAPI($switchId);
    $loadBalanceStats = $eapi->getPortChannelLoadBalance($portChannelName);
    
    echo json_encode([
        'success' => true,
        'load_balance_stats' => $loadBalanceStats
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve load balance statistics: ' . $e->getMessage()
    ]);
}

