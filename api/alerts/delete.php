<?php
/**
 * Delete Alert Endpoint
 * DELETE /api/alerts/delete.php?id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../log_action.php';

// Only allow DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require admin role
requireRole('admin');

// Get alert ID
$alertId = $_GET['id'] ?? null;
if (!$alertId || !is_numeric($alertId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid alert ID']);
    exit;
}
$alertId = (int)$alertId;

// Require CSRF token
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

// Check if alert exists
$db = getDB();
$alert = $db->queryOne("SELECT id, switch_id, severity, message FROM alerts WHERE id = ?", [$alertId]);
if (!$alert) {
    http_response_code(404);
    echo json_encode(['error' => 'Alert not found']);
    exit;
}

// Log action before deletion
logAlertAction('Delete alert', $alertId, [
    'switch_id' => $alert['switch_id'],
    'severity' => $alert['severity']
]);

// Delete alert
$db->delete('alerts', 'id = ?', [$alertId]);

echo json_encode([
    'success' => true,
    'message' => 'Alert deleted successfully'
]);

