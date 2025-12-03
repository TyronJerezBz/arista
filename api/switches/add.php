<?php
/**
 * Add New Switch Endpoint
 * POST /api/switches/add.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../classes/Validator.php';
require_once __DIR__ . '/../classes/AristaEAPI.php';
require_once __DIR__ . '/../log_action.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require operator role minimum
requireRole(['operator', 'admin']);

// Require CSRF token
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required', 'received_input' => $input, 'debug' => APP_DEBUG ? ['raw_length' => strlen($rawInput), 'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set'] : null]);
    exit;
}
requireCSRF($input['csrf_token']);

// Get input data
$hostname = $input['hostname'] ?? '';
$ipAddress = $input['ip_address'] ?? '';
$model = $input['model'] ?? null;
$role = $input['role'] ?? null;
$tags = $input['tags'] ?? null;
$username = $input['username'] ?? '';
$password = $input['password'] ?? '';
$port = isset($input['port']) ? (int)$input['port'] : EAPI_DEFAULT_PORT;
$useHttps = isset($input['use_https']) ? (bool)$input['use_https'] : EAPI_DEFAULT_HTTPS;
$timeout = isset($input['timeout']) ? (int)$input['timeout'] : EAPI_DEFAULT_TIMEOUT;

// Validate input
$errors = [];

if (empty($hostname)) {
    $errors[] = 'Hostname is required';
} elseif (!Validator::validateHostname($hostname)) {
    $errors[] = 'Invalid hostname format';
}

if (empty($ipAddress)) {
    $errors[] = 'IP address is required';
} elseif (!Validator::validateIP($ipAddress)) {
    $errors[] = 'Invalid IP address format';
}

if (empty($username)) {
    $errors[] = 'Username is required';
}

if (empty($password)) {
    $errors[] = 'Password is required';
}

if (!Validator::validatePort($port)) {
    $errors[] = 'Invalid port number';
}

if (!Validator::validateInteger($timeout, 1, 300)) {
    $errors[] = 'Timeout must be between 1 and 300 seconds';
}

if ($role && !Validator::validateSwitchRole($role)) {
    $errors[] = 'Invalid switch role';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Check if IP address already exists
require_once __DIR__ . '/../classes/Database.php';
$db = Database::getInstance();
$existing = $db->queryOne("SELECT id FROM switches WHERE ip_address = ?", [$ipAddress]);
if ($existing) {
    http_response_code(409);
    echo json_encode(['error' => 'Switch with this IP address already exists']);
    exit;
}

// Encrypt password
$passwordEncrypted = Security::encrypt($password);

// Start transaction
$db->beginTransaction();

try {
    // Insert switch
    $switchId = $db->insert('switches', [
        'hostname' => $hostname,
        'ip_address' => $ipAddress,
        'model' => $model,
        'role' => $role,
        'tags' => $tags,
        'status' => 'unknown'
    ]);
    
    // Insert credentials
    $db->insert('switch_credentials', [
        'switch_id' => $switchId,
        'username' => $username,
        'password_encrypted' => $passwordEncrypted,
        'port' => $port,
        'use_https' => $useHttps ? 1 : 0,
        'timeout' => $timeout
    ]);
    
    // Test connection to switch
    try {
        $eapi = new AristaEAPI($switchId);
        $version = $eapi->getVersion();
        
        // Update switch with version info
        $db->update('switches', [
            'model' => $version['modelName'] ?? $model,
            'firmware_version' => $version['version'] ?? null,
            'status' => 'up',
            'last_seen' => date('Y-m-d H:i:s')
        ], 'id = ?', [$switchId]);
        
        // Log action
        logSwitchAction('Create switch', $switchId, [
            'hostname' => $hostname,
            'ip_address' => $ipAddress,
            'model' => $version['modelName'] ?? $model
        ]);
        
        $db->commit();
        
        // Get created switch
        $switch = $db->queryOne("SELECT * FROM switches WHERE id = ?", [$switchId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Switch added successfully',
            'switch' => $switch
        ]);
        
    } catch (Exception $e) {
        // Connection failed but switch was created
        $db->update('switches', [
            'status' => 'down'
        ], 'id = ?', [$switchId]);
        
        $db->commit();
        
        logSwitchAction('Create switch (connection failed)', $switchId, [
            'hostname' => $hostname,
            'ip_address' => $ipAddress,
            'error' => $e->getMessage()
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Switch added but connection test failed',
            'warning' => $e->getMessage(),
            'switch' => $db->queryOne("SELECT * FROM switches WHERE id = ?", [$switchId])
        ]);
    }
    
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to add switch: ' . $e->getMessage()]);
}


