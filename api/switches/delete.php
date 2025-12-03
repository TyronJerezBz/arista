<?php
/**
 * Delete Switch Endpoint
 * DELETE /api/switches/delete.php?id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../log_action.php';

// Only allow DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require admin role
requireRole('admin');

// Require CSRF token
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

// Get switch ID
$switchId = $_GET['id'] ?? null;
if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}
$switchId = (int)$switchId;

// Check if switch exists
$db = Database::getInstance();
$switch = $db->queryOne("SELECT hostname, ip_address FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Log action before deletion
logSwitchAction('Delete switch', $switchId, [
    'hostname' => $switch['hostname'],
    'ip_address' => $switch['ip_address']
]);

// Delete switch (cascade will delete credentials, VLANs, interfaces, configs, alerts)
$db->delete('switches', 'id = ?', [$switchId]);

echo json_encode([
    'success' => true,
    'message' => 'Switch deleted successfully'
]);


