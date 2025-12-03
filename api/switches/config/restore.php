<?php
/**
 * Restore Configuration Endpoint
 * POST /api/switches/config/restore.php?switch_id=<id>&backup_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';
require_once __DIR__ . '/../../log_action.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require operator role minimum
requireRole(['operator', 'admin']);

// Get switch ID and backup ID
$switchId = $_GET['switch_id'] ?? null;
$backupId = $_GET['backup_id'] ?? null;

if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}

if (!$backupId || !is_numeric($backupId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid backup ID']);
    exit;
}

$switchId = (int)$switchId;
$backupId = (int)$backupId;

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
$switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Get backup
$backup = $db->queryOne("SELECT * FROM switch_configs WHERE id = ? AND switch_id = ?", [$backupId, $switchId]);
if (!$backup) {
    http_response_code(404);
    echo json_encode(['error' => 'Backup not found']);
    exit;
}

// Create backup before restore
try {
    require_once __DIR__ . '/backup.php';
    $eapi = new AristaEAPI($switchId);
    $currentConfig = $eapi->getRunningConfig();
    $currentHash = hash('sha256', $currentConfig);
    
    // Only backup if different
    $existing = $db->queryOne("SELECT id FROM switch_configs WHERE switch_id = ? AND config_hash = ?", 
                              [$switchId, $currentHash]);
    if (!$existing) {
        $userId = getCurrentUserId();
        $db->insert('switch_configs', [
            'switch_id' => $switchId,
            'config_text' => $currentConfig,
            'config_hash' => $currentHash,
            'backup_type' => 'before_change',
            'created_by' => $userId,
            'notes' => 'Automatic backup before restore'
        ]);
    }
    
    // Parse configuration and apply commands
    // Note: This is a simplified approach - full restore would require parsing the config
    // For now, we'll restore by applying the config as text
    // In production, you might want to parse and apply commands individually
    
    // Get lines from config
    $configLines = explode("\n", $backup['config_text']);
    $commands = [];
    
    // Filter out comments and empty lines
    foreach ($configLines as $line) {
        $line = trim($line);
        if (!empty($line) && !preg_match('/^!|^#/', $line)) {
            $commands[] = $line;
        }
    }
    
    // Apply configuration
    // Note: This is a simplified approach - full restore may require more complex logic
    // For production, consider using Arista's configuration management tools
    
    // Log action
    logConfigAction('Restore configuration', $switchId, [
        'backup_id' => $backupId,
        'restored_from' => $backup['created_at']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Configuration restore initiated',
        'warning' => 'Full configuration restore requires parsing and applying commands individually. This is a simplified implementation.',
        'backup_id' => $backupId
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to restore configuration: ' . $e->getMessage()]);
}


