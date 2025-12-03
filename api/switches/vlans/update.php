<?php
/**
 * Update VLAN Endpoint
 * PUT /api/switches/vlans/update.php?switch_id=<id>&vlan_id=<vlan_id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';
require_once __DIR__ . '/../../log_action.php';

// Only allow PUT requests
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require operator role minimum
requireRole(['operator', 'admin']);

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

// Require CSRF token
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

// Check if switch exists
$db = Database::getInstance();
$switch = $db->queryOne("SELECT id FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Check if VLAN exists
$vlan = $db->queryOne("SELECT * FROM switch_vlans WHERE switch_id = ? AND vlan_id = ?", [$switchId, $vlanId]);
if (!$vlan) {
    http_response_code(404);
    echo json_encode(['error' => 'VLAN not found']);
    exit;
}

// Get updatable fields
$updateData = [];
$updateSwitch = false;

if (isset($input['name'])) {
    $name = trim($input['name']);
    // Sanitize name
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    $name = substr($name, 0, 32);
    
    $updateData['name'] = $name ?: null;
    $updateSwitch = true;
}

if (isset($input['description'])) {
    $updateData['description'] = $input['description'] ?: null;
}

// Update on switch if name changed
if ($updateSwitch && isset($updateData['name'])) {
    try {
        $eapi = new AristaEAPI($switchId);
        // Reuse createVlan as it handles quoting and idempotent updates
        $eapi->createVlan($vlanId, $updateData['name']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update VLAN on switch: ' . $e->getMessage()]);
        exit;
    }
}

// Update database
if (!empty($updateData)) {
    $db->update('switch_vlans', $updateData, 'switch_id = ? AND vlan_id = ?', [$switchId, $vlanId]);
}

// Log action
logVlanAction('Update VLAN', $switchId, $vlanId, $updateData);

// Get updated VLAN
$updatedVlan = $db->queryOne("SELECT * FROM switch_vlans WHERE switch_id = ? AND vlan_id = ?", [$switchId, $vlanId]);

echo json_encode([
    'success' => true,
    'message' => 'VLAN updated successfully',
    'vlan' => $updatedVlan
]);


