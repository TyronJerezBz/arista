<?php
/**
 * Acknowledge Alert Endpoint
 * POST /api/alerts/acknowledge.php?id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../log_action.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require operator role minimum
requireRole(['operator', 'admin']);

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

// Update alert
$userId = getCurrentUserId();
$db->update('alerts', [
    'acknowledged' => true,
    'acknowledged_by' => $userId,
    'acknowledged_at' => date('Y-m-d H:i:s')
], 'id = ?', [$alertId]);

// Log action
logAlertAction('Acknowledge alert', $alertId, [
    'switch_id' => $alert['switch_id'],
    'severity' => $alert['severity']
]);

// Get updated alert
$updatedAlert = $db->queryOne("SELECT * FROM alerts WHERE id = ?", [$alertId]);

echo json_encode([
    'success' => true,
    'message' => 'Alert acknowledged successfully',
    'alert' => $updatedAlert
]);

