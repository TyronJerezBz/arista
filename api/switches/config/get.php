<?php
/**
 * Get Configuration Backup Endpoint
 * GET /api/switches/config/get.php?backup_id=<id>
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

// Get backup ID
$backupId = $_GET['backup_id'] ?? null;
if (!$backupId || !is_numeric($backupId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid backup ID']);
    exit;
}
$backupId = (int)$backupId;

// Get backup
$db = Database::getInstance();
$sql = "SELECT c.*, u.username as created_by_username, s.hostname
        FROM switch_configs c
        LEFT JOIN users u ON c.created_by = u.id
        LEFT JOIN switches s ON c.switch_id = s.id
        WHERE c.id = ?";
$backup = $db->queryOne($sql, [$backupId]);

if (!$backup) {
    http_response_code(404);
    echo json_encode(['error' => 'Backup not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'backup' => $backup
]);


