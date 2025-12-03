<?php
/**
 * Get MAC Address Table Endpoint
 * GET /api/switches/mac-address-table/get.php?switch_id=<id>[&vlan=<vlan_id>&interface=<interface>]
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

// Optional filters
$vlan = $_GET['vlan'] ?? null;
$interface = $_GET['interface'] ?? null;

try {
    $eapi = new AristaEAPI($switchId);
    $macTable = $eapi->getMacAddressTable($vlan, $interface);
    
    echo json_encode([
        'success' => true,
        'mac_address_table' => $macTable
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve MAC address table: ' . $e->getMessage()
    ]);
}

