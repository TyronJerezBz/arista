<?php
/**
 * Delete Port Channel Endpoint
 * DELETE /api/switches/port-channels/delete.php?switch_id=<id>&port_channel_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';
require_once __DIR__ . '/../../log_action.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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

// For DELETE requests, CSRF token might come in query string or request body
$input = json_decode(file_get_contents('php://input'), true);
$csrfToken = $_GET['csrf_token'] ?? ($input['csrf_token'] ?? null);

if (!$csrfToken) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($csrfToken);

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

try {
    $eapi = new AristaEAPI($switchId);
    
    // Delete port channel on switch
    $eapi->deletePortChannel($portChannel['port_channel_name']);
    
    // Members will be deleted automatically via CASCADE foreign key
    // Delete port channel from database
    $db->query("DELETE FROM port_channels WHERE id = ?", [$portChannelId]);
    
    logSwitchAction('Delete port channel', $switchId, [
        'port_channel_name' => $portChannel['port_channel_name']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Port channel deleted successfully'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to delete port channel: ' . $e->getMessage()]);
}

