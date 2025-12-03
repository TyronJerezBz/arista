<?php
/**
 * Configure Interface Endpoint
 * POST /api/switches/interfaces/configure.php?switch_id=<id>
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

// Operators and admins only
requireRole(['operator','admin']);

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

$interface = trim($input['interface'] ?? '');
$mode = isset($input['mode']) ? strtolower(trim($input['mode'])) : null; // optional
$accessVlan = $input['access_vlan'] ?? null; // optional
$trunkVlans = $input['trunk_vlans'] ?? null; // optional
$nativeVlan = $input['native_vlan'] ?? null; // optional (for trunk - untagged/native VLAN)
$description = isset($input['description']) ? trim($input['description']) : null;
$customTag = isset($input['custom_tag']) ? trim($input['custom_tag']) : null;
$adminState = isset($input['admin_state']) ? strtolower(trim($input['admin_state'])) : null; // optional ('up'|'down')

if (!$interface || !Validator::validateInterfaceName($interface)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid interface name']);
    exit;
}

// Validate mode only if provided (UI may update only description/tag)
if ($mode !== null) {
    $allowedModes = ['access','trunk','routed'];
    if (!in_array($mode, $allowedModes, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid interface mode']);
        exit;
    }

    if ($mode === 'access') {
        if ($accessVlan === null || !Validator::validateVlanId($accessVlan)) {
            http_response_code(400);
            echo json_encode(['error' => 'Access mode requires a valid VLAN ID (1-4094)']);
            exit;
        }
    } elseif ($mode === 'trunk') {
        if (empty($trunkVlans)) {
            http_response_code(400);
            echo json_encode(['error' => 'Trunk mode requires VLAN list']);
            exit;
        }
        $vlans = array_filter(array_map('trim', explode(',', is_array($trunkVlans) ? implode(',', $trunkVlans) : $trunkVlans)));
        foreach ($vlans as $vlan) {
            if (!Validator::validateVlanId($vlan)) {
                http_response_code(400);
                echo json_encode(['error' => "Invalid VLAN ID in trunk list: {$vlan}"]);
                exit;
            }
        }
        $trunkVlans = implode(',', $vlans);

        // Validate native VLAN if provided
        if ($nativeVlan !== null && $nativeVlan !== '') {
            if (!Validator::validateVlanId($nativeVlan)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid native VLAN ID (1-4094)']);
                exit;
            }
            $nativeVlan = (int)$nativeVlan;
        } else {
            $nativeVlan = null;
        }
    }
}

// Validate admin state if provided
if ($adminState !== null) {
    if (!in_array($adminState, ['up','down'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid admin state (must be up or down)']);
        exit;
    }
}

$db = Database::getInstance();
$switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

try {
    $eapi = new AristaEAPI($switchId);
    // Build config payload only with fields we want to push to device
    $config = [];
    if ($mode !== null) {
        $config['mode'] = $mode;
        if ($mode === 'access') {
            $config['vlan'] = (int)$accessVlan;
        } elseif ($mode === 'trunk') {
            $config['vlans'] = $trunkVlans;
            if ($nativeVlan !== null) {
                $config['native_vlan'] = (int)$nativeVlan;
            }
        }
    }
    if ($description !== null) {
        $config['description'] = $description;
    }
    if ($adminState !== null) {
        $config['admin_state'] = $adminState;
    }
    // Only call eAPI if there is something to push (desc or mode/vlans)
    if (!empty($config)) {
        $eapi->configureInterface($interface, $config);
    }

    // Update database cache (backward-compatible with older schemas)
    // Discover available columns
    $cols = $db->query("SHOW COLUMNS FROM switch_interfaces");
    $colNames = array_map(function ($r) { return $r['Field'] ?? null; }, $cols);
    $has = function ($name) use ($colNames) { return in_array($name, $colNames, true); };

    // Update existing row instead of deleting, to avoid wiping mode/VLANs when editing only description/tag
    $updateFields = [];
    if ($mode !== null && $has('mode')) $updateFields['mode'] = $mode;
    if ($mode === 'access') {
        if ($has('vlan_id')) $updateFields['vlan_id'] = (int)$accessVlan;
        // Clear trunk-related columns when switching to access
        if ($has('trunk_vlans')) $updateFields['trunk_vlans'] = null;
        if ($has('native_vlan_id')) $updateFields['native_vlan_id'] = null;
    }
    if ($mode === 'trunk') {
        // When trunk, access vlan should be null
        if ($has('vlan_id')) $updateFields['vlan_id'] = null;
        if ($has('trunk_vlans')) $updateFields['trunk_vlans'] = $trunkVlans;
        if ($has('native_vlan_id')) $updateFields['native_vlan_id'] = $nativeVlan;
    }
    if ($description !== null && $has('description')) $updateFields['description'] = $description;
    if ($customTag !== null && $has('custom_tag')) $updateFields['custom_tag'] = $customTag;
    // Reflect admin state into cache (prefer admin_status, fallback to status)
    if ($adminState !== null) {
        if ($has('admin_status')) {
            $updateFields['admin_status'] = $adminState;
        } elseif ($has('status')) {
            $updateFields['status'] = $adminState;
        }
    }
    if ($has('last_synced')) $updateFields['last_synced'] = date('Y-m-d H:i:s');
    if ($has('last_updated')) $updateFields['last_updated'] = date('Y-m-d H:i:s');

    if (!empty($updateFields)) {
        // Try update; if row doesn't exist, insert minimal row then update
        $existing = $db->queryOne("SELECT id FROM switch_interfaces WHERE switch_id = ? AND interface_name = ?", [$switchId, $interface]);
        if (!$existing) {
            $db->insert('switch_interfaces', ['switch_id' => $switchId, 'interface_name' => $interface]);
        }
        $db->update('switch_interfaces', $updateFields, 'switch_id = ? AND interface_name = ?', [$switchId, $interface]);
    }

    logSwitchAction('Configure interface', $switchId, [
        'interface' => $interface,
        'mode' => $mode,
        'access_vlan' => $mode === 'access' ? (int)$accessVlan : null,
        'trunk_vlans' => $mode === 'trunk' ? $trunkVlans : null,
        'native_vlan' => $mode === 'trunk' ? $nativeVlan : null,
        'description' => $description,
        'admin_state' => $adminState
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Interface updated successfully'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to configure interface: ' . $e->getMessage()]);
}

