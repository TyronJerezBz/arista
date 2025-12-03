<?php
/**
 * List VLANs for a Switch Endpoint
 * GET /api/switches/vlans/list.php?switch_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Database.php';

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
$switchId = (int)$switchId;

// Check if switch exists
$db = Database::getInstance();
$switch = $db->queryOne("SELECT id FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Get source parameter (database or switch)
$source = $_GET['source'] ?? 'database'; // 'database' or 'switch'

if ($source === 'switch') {
    // Fetch VLANs directly from switch
    try {
        require_once __DIR__ . '/../../classes/AristaEAPI.php';
        $eapi = new AristaEAPI($switchId);
        $vlans = $eapi->getVlans();
        
        // Already normalized by AristaEAPI::getVlans()
        $vlanList = is_array($vlans) ? $vlans : [];
        
        echo json_encode([
            'success' => true,
            'vlans' => $vlanList,
            'source' => 'switch'
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        $errorMsg = $e->getMessage();
        
        // Provide helpful error messages
        if (strpos($errorMsg, 'invalid command') !== false || strpos($errorMsg, 'show vlans') !== false) {
            $errorMsg = 'Switch does not support VLAN retrieval. This may be a non-Arista device or an older model without eAPI support.';
        } elseif (strpos($errorMsg, 'Connection refused') !== false || strpos($errorMsg, 'timed out') !== false) {
            $errorMsg = 'Unable to connect to switch. Check credentials and switch IP address.';
        }
        
        echo json_encode(['error' => 'Failed to fetch VLANs from switch: ' . $errorMsg]);
        exit;
    }
}

// Get VLANs from database
$sql = "SELECT id, switch_id, vlan_id, name, description
        FROM switch_vlans 
        WHERE switch_id = ?
        ORDER BY vlan_id ASC";
$vlans = $db->query($sql, [$switchId]);

echo json_encode([
    'success' => true,
    'vlans' => $vlans,
    'source' => 'database'
]);


