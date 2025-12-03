<?php
/**
 * Add/Remove Port Channel Members Endpoint
 * POST /api/switches/port-channels/members.php?switch_id=<id>&port_channel_id=<id>&action=add|remove
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
$action = $_GET['action'] ?? null;

if (!$switchId || !is_numeric($switchId) || !$portChannelId || !is_numeric($portChannelId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID or port channel ID']);
    exit;
}

if (!$action || !in_array($action, ['add', 'remove'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action. Must be "add" or "remove"']);
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

$interfaceName = isset($input['interface_name']) ? trim($input['interface_name']) : null;
if (!$interfaceName || !Validator::validateInterfaceName($interfaceName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Valid interface name is required']);
    exit;
}

try {
    $eapi = new AristaEAPI($switchId);
    
    if ($action === 'add') {
        $lacpMode = isset($input['lacp_mode']) ? strtolower(trim($input['lacp_mode'])) : $portChannel['lacp_mode'];
        $allowedLacpModes = ['active', 'passive', 'on'];
        if (!in_array($lacpMode, $allowedLacpModes, true)) {
            $lacpMode = 'active';
        }
        
        // Add member to port channel on switch
        $eapi->addPortChannelMember($interfaceName, $portChannel['port_channel_name'], $lacpMode);
        
        // Check if member already exists in database
        $existing = $db->queryOne(
            "SELECT id FROM port_channel_members WHERE port_channel_id = ? AND interface_name = ?",
            [$portChannelId, $interfaceName]
        );
        
        if (!$existing) {
            // Store in database
            $db->insert('port_channel_members', [
                'port_channel_id' => $portChannelId,
                'interface_name' => $interfaceName
            ]);
        }
        
        logSwitchAction('Add port channel member', $switchId, [
            'port_channel_name' => $portChannel['port_channel_name'],
            'interface_name' => $interfaceName,
            'lacp_mode' => $lacpMode
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Member added to port channel successfully'
        ]);
        
    } else { // remove
        // Remove member from port channel on switch
        $eapi->removePortChannelMember($interfaceName);
        
        // Remove from database
        $db->query("DELETE FROM port_channel_members WHERE port_channel_id = ? AND interface_name = ?", [$portChannelId, $interfaceName]);
        
        logSwitchAction('Remove port channel member', $switchId, [
            'port_channel_name' => $portChannel['port_channel_name'],
            'interface_name' => $interfaceName
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Member removed from port channel successfully'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to ' . $action . ' member: ' . $e->getMessage()]);
}

