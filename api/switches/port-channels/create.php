<?php
/**
 * Create Port Channel Endpoint
 * POST /api/switches/port-channels/create.php?switch_id=<id>
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
if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}
$switchId = (int)$switchId;

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

// Get input data
$portChannelNumber = $input['port_channel_number'] ?? null;
$mode = isset($input['mode']) ? strtolower(trim($input['mode'])) : 'trunk';
$vlanId = $input['vlan_id'] ?? null;
$nativeVlanId = $input['native_vlan_id'] ?? null;
$trunkVlans = $input['trunk_vlans'] ?? null;
$lacpMode = isset($input['lacp_mode']) ? strtolower(trim($input['lacp_mode'])) : 'active';
$description = isset($input['description']) ? trim($input['description']) : null;
$members = $input['members'] ?? []; // Array of interface names to add as members

// Validate input
$errors = [];

if (!$portChannelNumber || !is_numeric($portChannelNumber)) {
    $errors[] = 'Port channel number is required and must be a number';
} elseif ((int)$portChannelNumber < 1 || (int)$portChannelNumber > 4096) {
    $errors[] = 'Port channel number must be between 1 and 4096';
}

$allowedModes = ['access', 'trunk', 'routed'];
if (!in_array($mode, $allowedModes, true)) {
    $errors[] = 'Mode must be access, trunk, or routed';
}

$allowedLacpModes = ['active', 'passive', 'on'];
if (!in_array($lacpMode, $allowedLacpModes, true)) {
    $errors[] = 'LACP mode must be active, passive, or on';
}

// Get existing VLANs for this switch
$existingVlans = [];
try {
    $vlanRows = $db->query("SELECT vlan_id FROM switch_vlans WHERE switch_id = ?", [$switchId]);
    $existingVlans = array_map(function($row) { return (int)$row['vlan_id']; }, $vlanRows);
} catch (Exception $e) {
    // If VLANs table doesn't exist or error, continue without validation
}

if ($mode === 'access') {
    if (!$vlanId || !Validator::validateVlanId($vlanId)) {
        $errors[] = 'Access mode requires a valid VLAN ID (1-4094)';
    } elseif (!empty($existingVlans) && !in_array((int)$vlanId, $existingVlans, true)) {
        $errors[] = "VLAN {$vlanId} does not exist on this switch";
    }
} elseif ($mode === 'trunk') {
    if (!empty($trunkVlans)) {
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
    if ($nativeVlanId !== null) {
        if (!Validator::validateVlanId($nativeVlanId)) {
            $errors[] = 'Invalid native VLAN ID (1-4094)';
        } elseif (!empty($existingVlans) && !in_array((int)$nativeVlanId, $existingVlans, true)) {
            $errors[] = "Native VLAN {$nativeVlanId} does not exist on this switch";
        }
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => 'Validation failed', 'errors' => $errors]);
    exit;
}

$portChannelNumber = (int)$portChannelNumber;
$portChannelName = "Port-Channel{$portChannelNumber}";

// Check if port channel already exists
$existing = $db->queryOne("SELECT id FROM port_channels WHERE switch_id = ? AND port_channel_name = ?", [$switchId, $portChannelName]);
if ($existing) {
    http_response_code(409);
    echo json_encode(['error' => 'Port channel already exists']);
    exit;
}

try {
    $eapi = new AristaEAPI($switchId);
    
    // Build configuration
    $config = [
        'mode' => $mode,
        'lacp_mode' => $lacpMode
    ];
    
    if ($mode === 'access' && $vlanId) {
        $config['vlan'] = (int)$vlanId;
    } elseif ($mode === 'trunk') {
        if ($nativeVlanId) {
            $config['native_vlan'] = (int)$nativeVlanId;
        }
        if (!empty($trunkVlans)) {
            $config['trunk_vlans'] = $trunkVlans;
        }
    }
    
    if ($description) {
        $config['description'] = $description;
    }
    
    // Create port channel on switch
    $eapi->createPortChannel($portChannelName, $config);
    
    // Add members if provided
    foreach ($members as $interfaceName) {
        if (Validator::validateInterfaceName($interfaceName)) {
            try {
                $eapi->addPortChannelMember($interfaceName, $portChannelName, $lacpMode);
            } catch (Exception $e) {
                // Log but continue - member addition can fail if interface is already in use
                error_log("Failed to add member {$interfaceName} to {$portChannelName}: " . $e->getMessage());
            }
        }
    }
    
    // Store in database
    $portChannelId = $db->insert('port_channels', [
        'switch_id' => $switchId,
        'port_channel_name' => $portChannelName,
        'port_channel_number' => $portChannelNumber,
        'mode' => $mode,
        'vlan_id' => $mode === 'access' ? (int)$vlanId : null,
        'native_vlan_id' => $mode === 'trunk' ? ($nativeVlanId ? (int)$nativeVlanId : null) : null,
        'trunk_vlans' => $mode === 'trunk' ? $trunkVlans : null,
        'lacp_mode' => $lacpMode,
        'description' => $description
    ]);
    
    // Store members
    foreach ($members as $interfaceName) {
        if (Validator::validateInterfaceName($interfaceName)) {
            $db->insert('port_channel_members', [
                'port_channel_id' => $portChannelId,
                'interface_name' => $interfaceName
            ]);
        }
    }
    
    logSwitchAction('Create port channel', $switchId, [
        'port_channel_name' => $portChannelName,
        'mode' => $mode,
        'lacp_mode' => $lacpMode,
        'members' => $members
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Port channel created successfully',
        'port_channel' => [
            'id' => $portChannelId,
            'port_channel_name' => $portChannelName,
            'port_channel_number' => $portChannelNumber
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create port channel: ' . $e->getMessage()]);
}

