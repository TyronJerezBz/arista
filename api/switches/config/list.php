<?php
/**
 * List Configuration Backups Endpoint
 * GET /api/switches/config/list.php?switch_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require authentication (viewer minimum)
requireAuth();

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

// Get backups
$sql = "SELECT c.id, c.switch_id, c.config_hash, c.backup_type, c.created_at, c.created_by, c.notes,
               u.username as created_by_username
        FROM switch_configs c
        LEFT JOIN users u ON c.created_by = u.id
        WHERE c.switch_id = ?
        ORDER BY c.created_at DESC";
$backups = $db->query($sql, [$switchId]);

// Remove config_text from response (too large)
foreach ($backups as &$backup) {
    unset($backup['config_text']);
}

echo json_encode([
    'success' => true,
    'backups' => $backups
]);


