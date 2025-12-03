<?php
/**
 * Create VLAN Endpoint
 * POST /api/switches/vlans/create.php?switch_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/Validator.php';
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
$switchId = (int)$switchId;

// Require CSRF token
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

// Check if switch exists
$db = Database::getInstance();
$switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Get input data
$vlanId = $input['vlan_id'] ?? null;
$name = $input['name'] ?? null;
$description = $input['description'] ?? null;

// Sanitize VLAN name (Arista requirements: alphanumeric, underscores, hyphens only, max 32 chars)
if ($name) {
    $originalName = $name;
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    $name = substr($name, 0, 32);
    
    // If description is empty but we changed the name, use original name as description
    if (empty($description) && $name !== $originalName) {
        $description = $originalName;
    }
}

// Validate input
$errors = [];

if (!$vlanId || !is_numeric($vlanId)) {
    $errors[] = 'VLAN ID is required and must be a number';
} elseif (!Validator::validateVlanId($vlanId)) {
    $errors[] = 'VLAN ID must be between 1 and 4094';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => 'Validation failed', 'errors' => $errors]);
    exit;
}

$vlanId = (int)$vlanId;

// Check if VLAN already exists
$existing = $db->queryOne("SELECT id FROM switch_vlans WHERE switch_id = ? AND vlan_id = ?", [$switchId, $vlanId]);
if ($existing) {
    http_response_code(409);
    echo json_encode(['error' => 'VLAN already exists']);
    exit;
}

// Create VLAN on switch
try {
    $eapi = new AristaEAPI($switchId);
    $eapi->createVlan($vlanId, $name);
    
    // Insert into database
    $db->insert('switch_vlans', [
        'switch_id' => $switchId,
        'vlan_id' => $vlanId,
        'name' => $name,
        'description' => $description
    ]);
    
    // Log action
    logVlanAction('Create VLAN', $switchId, $vlanId, [
        'name' => $name,
        'description' => $description
    ]);
    
    // Get created VLAN
    $vlan = $db->queryOne("SELECT * FROM switch_vlans WHERE switch_id = ? AND vlan_id = ?", [$switchId, $vlanId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'VLAN created successfully',
        'vlan' => $vlan
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    $errorMsg = $e->getMessage();
    echo json_encode([
        'error' => 'Failed to create VLAN: ' . $errorMsg
    ]);
}


