<?php
/**
 * Get VLAN Details Endpoint
 * GET /api/switches/vlans/get.php?switch_id=<id>&vlan_id=<vlan_id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require authentication (viewer minimum)
requireAuth();

// Get parameters
$switchId = $_GET['switch_id'] ?? null;
$vlanId = $_GET['vlan_id'] ?? null;

if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}

if (!$vlanId || !is_numeric($vlanId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid VLAN ID']);
    exit;
}

$switchId = (int)$switchId;
$vlanId = (int)$vlanId;

// Check if switch exists
$db = Database::getInstance();
$switch = $db->queryOne("SELECT id FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Get VLAN from database
$sql = "SELECT id, switch_id, vlan_id, name, description, last_updated 
        FROM switch_vlans 
        WHERE switch_id = ? AND vlan_id = ?";
$vlan = $db->queryOne($sql, [$switchId, $vlanId]);

if (!$vlan) {
    http_response_code(404);
    echo json_encode(['error' => 'VLAN not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'vlan' => $vlan
]);


