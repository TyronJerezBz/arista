<?php
/**
 * Backup Configuration Endpoint
 * POST /api/switches/config/backup.php?switch_id=<id>
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

// Get switch ID
$switchId = $_GET['switch_id'] ?? null;
if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}
$switchId = (int)$switchId;

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

// Get backup type and notes
$backupType = $input['backup_type'] ?? 'manual';
$notes = $input['notes'] ?? null;

if (!in_array($backupType, ['manual', 'scheduled', 'before_change'])) {
    $backupType = 'manual';
}

// Get running configuration
try {
    $eapi = new AristaEAPI($switchId);
    $configText = $eapi->getRunningConfig();
    
    // Calculate config hash
    $configHash = hash('sha256', $configText);
    
    // Check if identical config already exists
    $existing = $db->queryOne("SELECT id FROM switch_configs WHERE switch_id = ? AND config_hash = ?", 
                              [$switchId, $configHash]);
    
    if ($existing) {
        echo json_encode([
            'success' => true,
            'message' => 'Configuration already backed up (identical)',
            'backup_id' => $existing['id']
        ]);
        exit;
    }
    
    // Insert backup
    $userId = getCurrentUserId();
    $backupId = $db->insert('switch_configs', [
        'switch_id' => $switchId,
        'config_text' => $configText,
        'config_hash' => $configHash,
        'backup_type' => $backupType,
        'created_by' => $userId,
        'notes' => $notes
    ]);
    
    // Log action
    logConfigAction('Backup configuration', $switchId, [
        'backup_id' => $backupId,
        'backup_type' => $backupType
    ]);
    
    // Get created backup
    $backup = $db->queryOne("SELECT * FROM switch_configs WHERE id = ?", [$backupId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Configuration backed up successfully',
        'backup' => $backup
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to backup configuration: ' . $e->getMessage()]);
}


