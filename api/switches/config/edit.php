<?php
/**
 * Edit Configuration Endpoint
 * POST /api/switches/config/edit.php?id=<switch_id>
 * 
 * Edit configuration text, validate, and optionally apply
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';
require_once __DIR__ . '/../../log_action.php';

// Only admin can edit configs
requireRole(['admin']);
requirePermission('config.edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$switchId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$switchId) {
    http_response_code(400);
    echo json_encode(['error' => 'Switch ID required']);
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
    
    $configText = $input['config_text'] ?? null;
    $validateOnly = isset($input['validate_only']) ? (bool)$input['validate_only'] : false;
    $apply = isset($input['apply']) ? (bool)$input['apply'] : false;
    $autoBackup = isset($input['auto_backup']) ? (bool)$input['auto_backup'] : true;
    
    if (empty($configText)) {
        http_response_code(400);
        echo json_encode(['error' => 'Configuration text required']);
        exit;
    }
    
    if (strlen($configText) > 1048576) { // 1MB
        http_response_code(400);
        echo json_encode(['error' => 'Configuration too large (max 1MB)']);
        exit;
    }
    
    // Validate config syntax
    $validation = validateConfigSyntax($configText);
    
    if (!$validation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Configuration validation failed',
            'validation_errors' => $validation['errors'],
            'warnings' => $validation['warnings']
        ]);
        exit;
    }
    
    // Calculate changes
    $currentConfig = getCurrentConfig($switchId);
    $changes = calculateConfigChanges($currentConfig, $configText);
    
    // If validation only, return results
    if ($validateOnly) {
        echo json_encode([
            'success' => true,
            'message' => 'Configuration validation passed',
            'changes' => $changes,
            'validation' => $validation
        ]);
        exit;
    }
    
    // Apply configuration if requested
    if ($apply) {
        // Create auto-backup if enabled
        $backupConfigId = null;
        if ($autoBackup && !empty($currentConfig)) {
            $configHash = hash('sha256', $currentConfig);
            $backupConfigId = $db->insert('switch_configs', [
                'switch_id' => $switchId,
                'config_text' => $currentConfig,
                'config_hash' => $configHash,
                'backup_type' => 'before_change',
                'created_by' => getCurrentUserId(),
                'notes' => 'Auto-backup before config edit'
            ]);
        }
        
        // Apply config to switch via eAPI
        try {
            $eapi = new AristaEAPI($switchId);
            
            // Parse config lines and send as commands
            $lines = array_filter(array_map('trim', explode("\n", $configText)));
            $commands = ['configure'];
            
            foreach ($lines as $line) {
                // Skip comments and empty lines
                if (empty($line) || substr($line, 0, 1) === '!' || substr($line, 0, 1) === '#') {
                    continue;
                }
                $commands[] = $line;
            }
            
            // Execute commands
            $result = $eapi->runCommands($commands);
            
            // Save applied config as backup
            $configHash = hash('sha256', $configText);
            $appliedConfigId = $db->insert('switch_configs', [
                'switch_id' => $switchId,
                'config_text' => $configText,
                'config_hash' => $configHash,
                'backup_type' => 'manual',
                'created_by' => getCurrentUserId(),
                'notes' => 'Edited and applied via web UI',
                'auto_backup_of_task_id' => null
            ]);
            
            // Update with config changes
            if (isset($changes)) {
                $db->update(
                    'switch_configs',
                    ['config_changes' => json_encode($changes)],
                    'id = ?',
                    [$appliedConfigId]
                );
            }
            
            // Log action
            logSwitchAction('Edit and Apply Configuration', $switchId, [
                'config_id' => $appliedConfigId,
                'backup_config_id' => $backupConfigId,
                'lines_changed' => $changes['lines_added'] + $changes['lines_removed'],
                'changes' => $changes
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Configuration applied successfully',
                'config_id' => $appliedConfigId,
                'backup_id' => $backupConfigId,
                'changes' => $changes,
                'applied' => true
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to apply configuration: ' . $e->getMessage(),
                'backup_id' => $backupConfigId
            ]);
        }
    } else {
        // Just save as draft/backup without applying
        $configHash = hash('sha256', $configText);
        
        $configId = $db->insert('switch_configs', [
            'switch_id' => $switchId,
            'config_text' => $configText,
            'config_hash' => $configHash,
            'backup_type' => 'manual',
            'created_by' => getCurrentUserId(),
            'notes' => 'Edited configuration (not yet applied)'
        ]);
        
        $db->update(
            'switch_configs',
            ['config_changes' => json_encode($changes)],
            'id = ?',
            [$configId]
        );
        
        logSwitchAction('Edit Configuration', $switchId, [
            'config_id' => $configId,
            'changes' => $changes
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Configuration saved as draft',
            'config_id' => $configId,
            'changes' => $changes,
            'applied' => false
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
 * Validate config syntax
 */
function validateConfigSyntax($config) {
    $result = [
        'valid' => true,
        'errors' => [],
        'warnings' => []
    ];
    
    if (empty($config)) {
        $result['valid'] = false;
        $result['errors'][] = 'Configuration is empty';
        return $result;
    }
    
    $lines = explode("\n", $config);
    $validLines = 0;
    $inBlock = false;
    $lastIndent = 0;
    
    foreach ($lines as $lineNum => $line) {
        $trimmed = trim($line);
        
        // Skip empty lines and comments
        if (empty($trimmed) || substr($trimmed, 0, 1) === '!' || substr($trimmed, 0, 1) === '#') {
            continue;
        }
        
        // Check indentation
        $indent = strlen($line) - strlen(ltrim($line));
        
        // Check for valid command patterns
        if (preg_match('/^[a-zA-Z0-9\-\s\!]+/', $trimmed)) {
            $validLines++;
            
            // Track indentation for block detection
            if ($indent > $lastIndent) {
                $inBlock = true;
            } elseif ($indent < $lastIndent) {
                $inBlock = false;
            }
            $lastIndent = $indent;
        } else {
            $result['warnings'][] = "Line " . ($lineNum + 1) . ": Suspicious syntax: " . substr($trimmed, 0, 50);
        }
    }
    
    // Config should have at least a few valid lines
    if ($validLines < 3) {
        $result['valid'] = false;
        $result['errors'][] = "Configuration has insufficient valid commands (found $validLines, need at least 3)";
    }
    
    // Check for balanced blocks (basic)
    if ($inBlock) {
        $result['warnings'][] = 'Configuration may have unclosed blocks';
    }
    
    return $result;
}

/**
 * Get current running config for comparison
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

/**
 * Calculate diff between two configs
 */
function calculateConfigChanges($oldConfig, $newConfig) {
    $oldLines = array_filter(array_map('trim', explode("\n", $oldConfig ?? '')));
    $newLines = array_filter(array_map('trim', explode("\n", $newConfig)));
    
    $added = array_diff($newLines, $oldLines);
    $removed = array_diff($oldLines, $newLines);
    
    return [
        'lines_added' => count($added),
        'lines_removed' => count($removed),
        'total_lines_before' => count($oldLines),
        'total_lines_after' => count($newLines),
        'size_before' => strlen($oldConfig ?? ''),
        'size_after' => strlen($newConfig)
    ];
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

