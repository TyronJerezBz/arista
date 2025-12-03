<?php
/**
 * Apply Configuration Endpoint
 * POST /api/switches/config/apply.php?id=<switch_id>&config_id=<config_id>
 * 
 * Apply a previously saved configuration to the switch
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';
require_once __DIR__ . '/../../log_action.php';

// Only admin can apply configs
requireRole(['admin']);
requirePermission('config.apply');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$switchId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$configId = isset($_GET['config_id']) ? (int)$_GET['config_id'] : null;

if (!$switchId || !$configId) {
    http_response_code(400);
    echo json_encode(['error' => 'Switch ID and config ID required']);
    exit;
}

$db = Database::getInstance();

// Verify switch exists
$switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Require CSRF token
    if (!$input || !isset($input['csrf_token'])) {
        http_response_code(400);
        echo json_encode(['error' => 'CSRF token required']);
        exit;
    }
    requireCSRF($input['csrf_token']);
    
    $autoBackup = isset($input['auto_backup']) ? (bool)$input['auto_backup'] : true;
    $reloadOnComplete = isset($input['reload_on_complete']) ? (bool)$input['reload_on_complete'] : false;
    
    // Verify config exists and belongs to this switch
    $config = $db->queryOne(
        "SELECT id, config_text FROM switch_configs WHERE id = ? AND switch_id = ?",
        [$configId, $switchId]
    );
    
    if (!$config) {
        http_response_code(404);
        echo json_encode(['error' => 'Configuration not found']);
        exit;
    }
    
    // Create auto-backup if enabled
    $backupConfigId = null;
    if ($autoBackup) {
        $currentConfig = getCurrentConfig($switchId);
        if (!empty($currentConfig)) {
            $configHash = hash('sha256', $currentConfig);
            $backupConfigId = $db->insert('switch_configs', [
                'switch_id' => $switchId,
                'config_text' => $currentConfig,
                'config_hash' => $configHash,
                'backup_type' => 'before_change',
                'created_by' => getCurrentUserId(),
                'notes' => 'Auto-backup before applying config'
            ]);
        }
    }
    
    // Apply config to switch via eAPI
    try {
        $eapi = new AristaEAPI($switchId);
        
        // Parse config lines and send as commands
        $lines = array_filter(array_map('trim', explode("\n", $config['config_text'])));
        $commands = ['configure'];
        
        foreach ($lines as $line) {
            // Skip comments and empty lines
            if (empty($line) || substr($line, 0, 1) === '!' || substr($line, 0, 1) === '#') {
                continue;
            }
            $commands[] = $line;
        }
        
        // Execute commands on switch
        $result = $eapi->runCommands($commands);
        
        // Optional: reload after apply
        $reloadAttempted = false;
        if ($reloadOnComplete) {
            try {
                $eapi->runCommand('reload');
                $reloadAttempted = true;
            } catch (Exception $reloadError) {
                // Log but don't fail - reload might not be needed
                error_log("Reload after config apply failed: " . $reloadError->getMessage());
            }
        }
        
        // Log action
        logSwitchAction('Apply Configuration', $switchId, [
            'applied_config_id' => $configId,
            'backup_config_id' => $backupConfigId,
            'reload_attempted' => $reloadAttempted
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Configuration applied successfully',
            'applied_config_id' => $configId,
            'backup_id' => $backupConfigId,
            'reload_attempted' => $reloadAttempted,
            'switch_id' => $switchId
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to apply configuration to switch: ' . $e->getMessage(),
            'backup_id' => $backupConfigId,
            'recommendation' => 'Check switch connectivity and configuration syntax'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error: ' . $e->getMessage(),
        'debug' => APP_DEBUG ? $e->getTraceAsString() : null
    ]);
}

/**
 * Get current running config
 */
function getCurrentConfig($switchId) {
    try {
        $db = Database::getInstance();
        
        $lastConfig = $db->queryOne(
            "SELECT config_text FROM switch_configs 
             WHERE switch_id = ? AND backup_type IN ('manual', 'scheduled', 'before_change')
             ORDER BY created_at DESC LIMIT 1",
            [$switchId]
        );
        
        return $lastConfig['config_text'] ?? '';
    } catch (Exception $e) {
        return '';
    }
}

