<?php
/**
 * Get Switch Details Endpoint
 * GET /api/switches/get.php?id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Database.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require authentication (viewer minimum)
requireAuth();

// Get switch ID
$switchId = $_GET['id'] ?? null;

if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}

$switchId = (int)$switchId;

// Get switch details
$db = Database::getInstance();
$sql = "SELECT id, hostname, ip_address, model, role, firmware_version, tags, status, last_seen, last_polled, created_at, updated_at 
        FROM switches 
        WHERE id = ?";
$switch = $db->queryOne($sql, [$switchId]);

if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Get credentials info (masked, no password)
$sql = "SELECT id, username, port, use_https, timeout, created_at, updated_at 
        FROM switch_credentials 
        WHERE switch_id = ?";
$credentials = $db->queryOne($sql, [$switchId]);

if ($credentials) {
    // Return full credentials for editing (only admin/operator can see this via EditSwitch)
    // This works even when switch is offline - we're just reading from database
    $switch['has_credentials'] = true;
    $switch['credentials'] = [
        'username' => $credentials['username'],
        'port' => $credentials['port'] ?? 443,
        'use_https' => (bool)($credentials['use_https'] ?? true),
        'timeout' => $credentials['timeout'] ?? 10
    ];
} else {
    $switch['has_credentials'] = false;
    $switch['credentials'] = null;
}

// Return response
echo json_encode([
    'success' => true,
    'switch' => $switch
]);


