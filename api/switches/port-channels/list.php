<?php
/**
 * List Port Channels Endpoint
 * GET /api/switches/port-channels/list.php?switch_id=<id>
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
if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}
$switchId = (int)$switchId;

$db = Database::getInstance();
$switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

try {
    // Get port channels from database
    $portChannels = $db->query("
        SELECT 
            pc.id,
            pc.port_channel_name,
            pc.port_channel_number,
            pc.mode,
            pc.vlan_id,
            pc.native_vlan_id,
            pc.trunk_vlans,
            pc.lacp_mode,
            pc.description,
            pc.admin_status,
            pc.oper_status,
            pc.last_updated,
            pc.created_at,
            COUNT(pcm.id) as member_count
        FROM port_channels pc
        LEFT JOIN port_channel_members pcm ON pc.id = pcm.port_channel_id
        WHERE pc.switch_id = ?
        GROUP BY pc.id
        ORDER BY pc.port_channel_number ASC
    ", [$switchId]);

    // Get members for each port channel
    foreach ($portChannels as &$pc) {
        $members = $db->query("
            SELECT 
                interface_name,
                admin_status,
                oper_status,
                lacp_state,
                last_updated
            FROM port_channel_members
            WHERE port_channel_id = ?
            ORDER BY interface_name ASC
        ", [$pc['id']]);
        $pc['members'] = $members;
    }
    unset($pc);

    echo json_encode([
        'success' => true,
        'port_channels' => $portChannels
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to list port channels: ' . $e->getMessage()]);
}

