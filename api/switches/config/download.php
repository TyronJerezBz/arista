<?php
/**
 * Download/Retrieve Configuration Endpoint
 * GET /api/switches/config/download.php?id=<switch_id>&[current|history|download]
 * 
 * Modes:
 * - current=true: Get current running config (JSON)
 * - history=true: Get config history (JSON)
 * - download: Download config file as .cfg
 * - default: Get config as JSON
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Database.php';

// Require operator or admin to view configurations
requireRole(['operator', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$switchId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$getCurrent = isset($_GET['current']) && $_GET['current'] === 'true';
$getHistory = isset($_GET['history']) && $_GET['history'] === 'true';
$download = isset($_GET['download']) && $_GET['download'] === 'true';

if (!$switchId) {
    http_response_code(400);
    echo json_encode(['error' => 'Switch ID required']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Verify switch exists
    $switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
    if (!$switch) {
        http_response_code(404);
        echo json_encode(['error' => 'Switch not found']);
        exit;
    }
    
    // MODE 1: Get current running config
    if ($getCurrent) {
        requirePermission('config.view');
        $config = $db->queryOne(
            "SELECT config_text FROM switch_configs 
             WHERE switch_id = ? AND backup_type IN ('manual', 'scheduled', 'before_change')
             ORDER BY created_at DESC LIMIT 1",
            [$switchId]
        );
        
        echo json_encode([
            'success' => true,
            'config' => $config['config_text'] ?? ''
        ]);
        exit;
    }
    
    // MODE 2: Get config history
    if ($getHistory) {
        requirePermission('config.history');
        $configs = $db->query(
            "SELECT id, config_text, backup_type, created_at, notes
             FROM switch_configs 
             WHERE switch_id = ?
             ORDER BY created_at DESC LIMIT 20",
            [$switchId]
        );
        
        echo json_encode([
            'success' => true,
            'history' => $configs
        ]);
        exit;
    }
    
    // MODE 3: Download as file (if specific config ID provided)
    if ($download) {
        requirePermission('config.download');
        $configId = isset($_GET['config_id']) ? (int)$_GET['config_id'] : null;
        if (!$configId) {
            http_response_code(400);
            echo json_encode(['error' => 'Config ID required for download']);
            exit;
        }
        
        $config = $db->queryOne(
            "SELECT sc.id, sc.config_text, sc.backup_type, sc.created_at
             FROM switch_configs sc
             WHERE sc.id = ? AND sc.switch_id = ?",
            [$configId, $switchId]
        );
        
        if (!$config) {
            http_response_code(404);
            echo json_encode(['error' => 'Config not found']);
            exit;
        }
        
        // Generate filename
        $timestamp = strtotime($config['created_at']);
        $dateStr = date('Y-m-d_H-i-s', $timestamp);
        $filename = sprintf(
            '%s_%s_%s.cfg',
            $switch['hostname'],
            $config['backup_type'],
            $dateStr
        );
        
        // Set headers for file download
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($config['config_text']));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Output config content
        echo $config['config_text'];
        exit;
    }
    
    // Default: Get latest config
    $config = $db->queryOne(
        "SELECT id, config_text, backup_type, created_at, notes
         FROM switch_configs 
         WHERE switch_id = ?
         ORDER BY created_at DESC LIMIT 1",
        [$switchId]
    );
    
    echo json_encode([
        'success' => true,
        'config' => $config ? $config['config_text'] : '',
        'metadata' => $config ? [
            'id' => $config['id'],
            'type' => $config['backup_type'],
            'created_at' => $config['created_at'],
            'notes' => $config['notes']
        ] : null
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve config: ' . $e->getMessage()
    ]);
}

