<?php
/**
 * Upload Configuration Endpoint
 * POST /api/switches/config/upload.php?id=<switch_id>
 * 
 * Accepts multipart form with config file
 * Validates config and stores as backup
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../log_action.php';

// Only admin can upload configs
requireRole(['admin']);
requirePermission('config.upload');

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
    // Check if file was uploaded
    if (!isset($_FILES['config_file']) || $_FILES['config_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Config file upload failed',
            'upload_error' => $_FILES['config_file']['error'] ?? 'Unknown'
        ]);
        exit;
    }
    
    $file = $_FILES['config_file'];
    
    // Validate file
    if ($file['size'] > 1048576) { // 1MB limit
        http_response_code(400);
        echo json_encode(['error' => 'Config file too large (max 1MB)']);
        exit;
    }
    
    if ($file['size'] < 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Config file appears invalid (too small)']);
        exit;
    }
    
    // Read config content
    $configText = file_get_contents($file['tmp_name']);
    if ($configText === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to read uploaded file']);
        exit;
    }
    
    // Validate config syntax (basic checks)
    if (!validateConfigSyntax($configText)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Config syntax validation failed',
            'details' => 'Config appears to be invalid or incomplete'
        ]);
        exit;
    }
    
    // Calculate config hash and changes
    $currentConfig = getCurrentConfig($switchId);
    $configHash = hash('sha256', $configText);
    $changes = calculateConfigChanges($currentConfig, $configText);
    
    // Check if this config already exists
    $existing = $db->queryOne(
        "SELECT id FROM switch_configs WHERE switch_id = ? AND config_hash = ?",
        [$switchId, $configHash]
    );
    
    if ($existing) {
        http_response_code(409);
        echo json_encode([
            'error' => 'This configuration already exists',
            'config_id' => $existing['id']
        ]);
        exit;
    }
    
    // Store config as backup
    $configId = $db->insert('switch_configs', [
        'switch_id' => $switchId,
        'config_text' => $configText,
        'config_hash' => $configHash,
        'backup_type' => 'manual',
        'created_by' => getCurrentUserId(),
        'notes' => 'Uploaded from web UI'
    ]);
    
    // Update with config changes if table supports it
    if (isset($changes)) {
        $db->update(
            'switch_configs',
            ['config_changes' => json_encode($changes)],
            'id = ?',
            [$configId]
        );
    }
    
    // Log action
    logSwitchAction('Upload Configuration', $switchId, [
        'config_id' => $configId,
        'file_name' => $file['name'],
        'file_size' => $file['size'],
        'lines' => substr_count($configText, "\n") + 1,
        'changes' => $changes
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Configuration uploaded successfully',
        'config_id' => $configId,
        'switch_id' => $switchId,
        'file_info' => [
            'name' => $file['name'],
            'size' => $file['size'],
            'lines' => substr_count($configText, "\n") + 1
        ],
        'changes' => $changes
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error: ' . $e->getMessage(),
        'debug' => APP_DEBUG ? $e->getTraceAsString() : null
    ]);
}

/**
 * Basic config syntax validation
 */
function validateConfigSyntax($config) {
    if (empty($config)) {
        return false;
    }
    
    // Check for common Arista config patterns
    $lines = explode("\n", $config);
    $validLines = 0;
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Skip empty lines and comments
        if (empty($trimmed) || substr($trimmed, 0, 1) === '!' || substr($trimmed, 0, 1) === '#') {
            continue;
        }
        
        // Check for valid command syntax
        if (preg_match('/^[a-zA-Z0-9\-\s\.]+/', $trimmed)) {
            $validLines++;
        }
    }
    
    // Config should have at least a few valid lines
    return $validLines >= 5;
}

/**
 * Get current running config for comparison
 */
function getCurrentConfig($switchId) {
    try {
        $db = Database::getInstance();
        
        // Try to get from last backup
        $lastConfig = $db->queryOne(
            "SELECT config_text FROM switch_configs 
             WHERE switch_id = ? AND backup_type IN ('manual', 'scheduled')
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
    $oldLines = explode("\n", $oldConfig ?? '');
    $newLines = explode("\n", $newConfig);
    
    $added = array_diff($newLines, $oldLines);
    $removed = array_diff($oldLines, $newLines);
    
    return [
        'lines_added' => count($added),
        'lines_removed' => count($removed),
        'total_lines' => count($newLines),
        'size_before' => strlen($oldConfig ?? ''),
        'size_after' => strlen($newConfig)
    ];
}

