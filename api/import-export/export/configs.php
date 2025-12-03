<?php
/**
 * Export Configurations Endpoint
 * GET /api/import-export/export/configs.php?switch_id=<id>
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die('Method not allowed');
}

// Require authentication (viewer minimum)
requireAuth();

// Get switch ID
$switchId = $_GET['switch_id'] ?? null;

$db = getDB();

if ($switchId) {
    // Export single switch configurations
    $switchId = (int)$switchId;
    
    // Check if switch exists
    $switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
    if (!$switch) {
        http_response_code(404);
        die('Switch not found');
    }
    
    // Get backups
    $backups = $db->query("SELECT * FROM switch_configs WHERE switch_id = ? ORDER BY created_at DESC", [$switchId]);
    
    // Set headers for text file download
    $filename = 'config_' . $switch['hostname'] . '_' . date('Y-m-d_H-i-s') . '.txt';
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Output configurations
    foreach ($backups as $index => $backup) {
        echo "=== Configuration Backup #{$backup['id']} ===\n";
        echo "Created: {$backup['created_at']}\n";
        echo "Type: {$backup['backup_type']}\n";
        if ($backup['notes']) {
            echo "Notes: {$backup['notes']}\n";
        }
        echo "==========================================\n\n";
        echo $backup['config_text'];
        echo "\n\n";
    }
    
} else {
    // Export all configurations as ZIP
    // Note: This is a simplified version - full ZIP would require ZipArchive
    
    // Get all switches with backups
    $switches = $db->query("SELECT DISTINCT s.id, s.hostname FROM switches s INNER JOIN switch_configs sc ON s.id = sc.switch_id ORDER BY s.hostname");
    
    // Set headers for text file download (simplified - multiple files would need ZIP)
    $filename = 'all_configs_' . date('Y-m-d_H-i-s') . '.txt';
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    foreach ($switches as $switch) {
        $backups = $db->query("SELECT * FROM switch_configs WHERE switch_id = ? ORDER BY created_at DESC LIMIT 1", [$switch['id']]);
        
        foreach ($backups as $backup) {
            echo "=== {$switch['hostname']} - Latest Config ===\n";
            echo "Created: {$backup['created_at']}\n";
            echo "==========================================\n\n";
            echo $backup['config_text'];
            echo "\n\n";
        }
    }
}

exit;

