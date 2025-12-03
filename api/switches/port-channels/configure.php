<?php
/**
 * Configure Port Channel Endpoint
 * POST /api/switches/port-channels/configure.php?switch_id=<id>&port_channel_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/Validator.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';
require_once __DIR__ . '/../../log_action.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireRole(['operator', 'admin']);

$switchId = $_GET['switch_id'] ?? null;
$portChannelId = $_GET['port_channel_id'] ?? null;

if (!$switchId || !is_numeric($switchId) || !$portChannelId || !is_numeric($portChannelId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID or port channel ID']);
    exit;
}
$switchId = (int)$switchId;
$portChannelId = (int)$portChannelId;

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

$db = Database::getInstance();
$switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

$portChannel = $db->queryOne("SELECT * FROM port_channels WHERE id = ? AND switch_id = ?", [$portChannelId, $switchId]);
if (!$portChannel) {
    http_response_code(404);
    echo json_encode(['error' => 'Port channel not found']);
    exit;
}

// Get input data (all optional - only update what's provided)
$mode = isset($input['mode']) ? strtolower(trim($input['mode'])) : null;
$vlanId = $input['vlan_id'] ?? null;
$nativeVlanId = $input['native_vlan_id'] ?? null;
$trunkVlans = $input['trunk_vlans'] ?? null;
$description = isset($input['description']) ? trim($input['description']) : null;
$adminState = isset($input['admin_state']) ? strtolower(trim($input['admin_state'])) : null;

// Validate input
$errors = [];

// Get existing VLANs for this switch
$existingVlans = [];
try {
    $vlanRows = $db->query("SELECT vlan_id FROM switch_vlans WHERE switch_id = ?", [$switchId]);
    $existingVlans = array_map(function($row) { return (int)$row['vlan_id']; }, $vlanRows);
} catch (Exception $e) {
    // If VLANs table doesn't exist or error, continue without validation
}

if ($mode !== null) {
    $allowedModes = ['access', 'trunk', 'routed'];
    if (!in_array($mode, $allowedModes, true)) {
        $errors[] = 'Mode must be access, trunk, or routed';
    }
    
    if ($mode === 'access' && $vlanId !== null) {
        if (!Validator::validateVlanId($vlanId)) {
            $errors[] = 'Invalid VLAN ID (1-4094)';
        } elseif (!empty($existingVlans) && !in_array((int)$vlanId, $existingVlans, true)) {
            $errors[] = "VLAN {$vlanId} does not exist on this switch";
        }
    }
    
    if ($mode === 'trunk' && !empty($trunkVlans)) {
        $vlans = array_filter(array_map('trim', explode(',', is_array($trunkVlans) ? implode(',', $trunkVlans) : $trunkVlans)));
        foreach ($vlans as $vlan) {
            if (!Validator::validateVlanId($vlan)) {
                $errors[] = "Invalid VLAN ID in trunk list: {$vlan}";
            } elseif (!empty($existingVlans) && !in_array((int)$vlan, $existingVlans, true)) {
                $errors[] = "VLAN {$vlan} does not exist on this switch";
            }
        }
        $trunkVlans = implode(',', $vlans);
    }
    
    if ($mode === 'trunk' && $nativeVlanId !== null) {
        if (!Validator::validateVlanId($nativeVlanId)) {
            $errors[] = 'Invalid native VLAN ID (1-4094)';
        } elseif (!empty($existingVlans) && !in_array((int)$nativeVlanId, $existingVlans, true)) {
            $errors[] = "Native VLAN {$nativeVlanId} does not exist on this switch";
        }
    }
}

if ($adminState !== null && !in_array($adminState, ['up', 'down'], true)) {
    $errors[] = 'Admin state must be up or down';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => 'Validation failed', 'errors' => $errors]);
    exit;
}

try {
    $eapi = new AristaEAPI($switchId);
    
    // Build configuration (only include fields that are being updated)
    $config = [];
    
    if ($mode !== null) {
        $config['mode'] = $mode;
        if ($mode === 'access' && $vlanId !== null) {
            $config['vlan'] = (int)$vlanId;
        } elseif ($mode === 'trunk') {
            if ($nativeVlanId !== null) {
                $config['native_vlan'] = (int)$nativeVlanId;
            }
            if (!empty($trunkVlans)) {
                $config['trunk_vlans'] = $trunkVlans;
            }
        }
    }
    
    if ($description !== null) {
        $config['description'] = $description;
    }
    
    if ($adminState !== null) {
        $config['admin_state'] = $adminState;
    }
    
    // Only configure if there are changes
    if (!empty($config)) {
        $eapi->configurePortChannel($portChannel['port_channel_name'], $config);
    }
    
    // Update database
    $updateFields = [];
    if ($mode !== null) {
        $updateFields['mode'] = $mode;
        if ($mode === 'access') {
            $updateFields['vlan_id'] = $vlanId ? (int)$vlanId : null;
            $updateFields['native_vlan_id'] = null;
            $updateFields['trunk_vlans'] = null;
        } elseif ($mode === 'trunk') {
            $updateFields['vlan_id'] = null;
            $updateFields['native_vlan_id'] = $nativeVlanId ? (int)$nativeVlanId : null;
            $updateFields['trunk_vlans'] = $trunkVlans;
        }
    }
    if ($description !== null) {
        $updateFields['description'] = $description;
    }
    if ($adminState !== null) {
        $updateFields['admin_status'] = $adminState;
    }
    
    if (!empty($updateFields)) {
        $updateFields['last_updated'] = date('Y-m-d H:i:s');
        $db->update('port_channels', $updateFields, 'id = ?', [$portChannelId]);
    }
    
    logSwitchAction('Configure port channel', $switchId, [
        'port_channel_name' => $portChannel['port_channel_name'],
        'config' => $config
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Port channel configured successfully'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to configure port channel: ' . $e->getMessage()]);
}

