<?php
/**
 * Sync VLANs from Switch Endpoint
 * POST /api/switches/vlans/sync.php?switch_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';
require_once __DIR__ . '/../../classes/Validator.php';
require_once __DIR__ . '/../../log_action.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require operator or admin
requireRole(['operator','admin']);

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

// Fetch VLANs from switch
try {
    $eapi = new AristaEAPI($switchId);
    $vlans = $eapi->getVlans();
    
    // Clear existing VLANs
    $db->delete('switch_vlans', 'switch_id = ?', [$switchId]);
    
    // Insert new VLANs
    $synced = 0;
    $errors = [];
    
    if (is_array($vlans)) {
        foreach ($vlans as $key => $item) {
            // Support both normalized list and legacy map formats
            $vlanId = null;
            $name = null;
            $description = null;

            if (is_array($item) && isset($item['vlan_id'])) {
                $vlanId = (int)$item['vlan_id'];
                $name = $item['name'] ?? null;
                $description = $item['description'] ?? null;
            } elseif (is_numeric($key)) {
                $vlanId = (int)$key;
                $name = is_array($item) ? ($item['name'] ?? null) : null;
                $description = is_array($item) ? ($item['description'] ?? null) : null;
            }

            if ($vlanId !== null && Validator::validateVlanId($vlanId)) {
                try {
                    $db->insert('switch_vlans', [
                        'switch_id' => $switchId,
                        'vlan_id' => $vlanId,
                        'name' => $name,
                        'description' => $description
                    ]);
                    $synced++;
                } catch (Exception $e) {
                    $errors[] = "Failed to sync VLAN {$vlanId}: " . $e->getMessage();
                }
            }
        }
    }
    
    // Log action
    logSwitchAction('Sync VLANs from switch', $switchId, [
        'synced_count' => $synced,
        'errors' => count($errors)
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => "Synced {$synced} VLANs",
        'synced_count' => $synced,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to sync VLANs: ' . $e->getMessage()]);
}


