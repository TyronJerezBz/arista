<?php
/**
 * Get Interface Details Endpoint
 * GET /api/switches/interfaces/get.php?switch_id=<id>&interface=<name>[&source=database|switch]
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireAuth();

$switchId = $_GET['switch_id'] ?? null;
$interface = $_GET['interface'] ?? null;

if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}
$switchId = (int)$switchId;

if (!$interface) {
    http_response_code(400);
    echo json_encode(['error' => 'Interface name is required']);
    exit;
}

$db = Database::getInstance();
$switch = $db->queryOne("SELECT id FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

$source = $_GET['source'] ?? 'database';

if ($source === 'switch') {
    try {
        $eapi = new AristaEAPI($switchId);
        $interfaces = $eapi->getInterfaces();

        foreach ($interfaces as $key => $value) {
            $name = is_array($value) ? ($value['name'] ?? $value['interface'] ?? null) : (is_string($key) ? $key : null);
            if ($name && strcasecmp($name, $interface) === 0) {
                echo json_encode([
                    'success' => true,
                    'interface' => [
                        'interface_name' => $name,
                        'admin_status' => strtolower($value['adminStatus'] ?? $value['admin_state'] ?? 'unknown'),
                        'oper_status' => strtolower($value['operStatus'] ?? $value['linkStatus'] ?? 'unknown'),
                        'mode' => strtolower($value['mode'] ?? $value['switchportMode'] ?? 'unknown'),
                        'vlan_id' => isset($value['accessVlan']) ? (int)$value['accessVlan'] : (isset($value['vlanId']) ? (int)$value['vlanId'] : null),
                        'trunk_vlans' => isset($value['trunkVlans']) ? (is_array($value['trunkVlans']) ? implode(',', $value['trunkVlans']) : (string)$value['trunkVlans']) : null,
                        'speed' => $value['bandwidth'] ?? $value['speed'] ?? null,
                        'description' => $value['description'] ?? ($value['desc'] ?? null)
                    ],
                    'source' => 'switch'
                ]);
                exit;
            }
        }

        http_response_code(404);
        echo json_encode(['error' => 'Interface not found on switch']);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch interface: ' . $e->getMessage()]);
        exit;
    }
}

$sql = "SELECT interface_name, admin_status, oper_status, mode, vlan_id, trunk_vlans, speed, description, last_synced
        FROM switch_interfaces
        WHERE switch_id = ? AND interface_name = ?
        LIMIT 1";
$interfaceRow = $db->queryOne($sql, [$switchId, $interface]);

if (!$interfaceRow) {
    http_response_code(404);
    echo json_encode(['error' => 'Interface not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'interface' => $interfaceRow,
    'source' => 'database'
]);

<?php
/**
 * Get Interface Details Endpoint
 * GET /api/switches/interfaces/get.php?switch_id=<id>&interface=<name>
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
$interfaceName = $_GET['interface'] ?? null;

if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}

if (empty($interfaceName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Interface name is required']);
    exit;
}

$switchId = (int)$switchId;
$interfaceName = trim($interfaceName);

// Check if switch exists
$db = Database::getInstance();
$switch = $db->queryOne("SELECT id FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Get interface from database
$sql = "SELECT id, switch_id, interface_name, vlan_id, mode, description, status, last_updated 
        FROM switch_interfaces 
        WHERE switch_id = ? AND interface_name = ?";
$interface = $db->queryOne($sql, [$switchId, $interfaceName]);

if (!$interface) {
    http_response_code(404);
    echo json_encode(['error' => 'Interface not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'interface' => $interface
]);


