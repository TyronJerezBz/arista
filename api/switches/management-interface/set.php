<?php
/**
 * Set Management Interface Configuration Endpoint
 * POST /api/switches/management-interface/set.php?switch_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';
require_once __DIR__ . '/../../log_action.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require operator role minimum
requireRole(['operator', 'admin']);

// Get switch ID
$switchId = $_GET['switch_id'] ?? null;
if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}
$switchId = (int) $switchId;

$db = Database::getInstance();
$switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Get request data
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

// Validate input
$ipAddress = $input['ip_address'] ?? null;
$gateway = $input['gateway'] ?? null;
$errors = [];

if (!$ipAddress || empty(trim($ipAddress))) {
    $errors[] = 'IP address is required';
} else {
    $ipAddress = trim($ipAddress);
    // Validate format: IP/CIDR (e.g., 192.168.1.100/24)
    if (!preg_match('/^((?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(?:\/([0-9]|[12][0-9]|3[0-2]))?$/', $ipAddress)) {
        $errors[] = 'Invalid IP address format. Expected format: IP/CIDR (e.g., 192.168.1.100/24)';
    }
}

if ($gateway && !empty(trim($gateway))) {
    $gateway = trim($gateway);
    if (!filter_var($gateway, FILTER_VALIDATE_IP)) {
        $errors[] = 'Invalid gateway IP address format';
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => 'Validation failed', 'errors' => $errors]);
    exit;
}

try {
    $eapi = new AristaEAPI($switchId);
    $eapi->configureManagementInterface($ipAddress, $gateway);
    
    // Log action
    logSwitchAction('Configure Management Interface', $switchId, [
        'ip_address' => $ipAddress,
        'gateway' => $gateway
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Management interface configuration updated successfully'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to configure management interface: ' . $e->getMessage()
    ]);
}

