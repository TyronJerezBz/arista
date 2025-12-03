<?php
/**
 * Update Switch Endpoint
 * PUT /api/switches/update.php?id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../classes/Validator.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/AristaEAPI.php';
require_once __DIR__ . '/../log_action.php';

// Only allow PUT requests
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require operator role minimum
requireRole(['operator', 'admin']);

// Get switch ID
$switchId = $_GET['id'] ?? null;
if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}
$switchId = (int)$switchId;

// Require CSRF token
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

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
$originalHostname = $switch['hostname'] ?? null;

// Get updatable fields
$updateData = [];
$errors = [];

if (isset($input['hostname'])) {
    $hostname = trim($input['hostname']);
    if (empty($hostname)) {
        $errors[] = 'Hostname cannot be empty';
    } elseif (!Validator::validateHostname($hostname)) {
        $errors[] = 'Invalid hostname format';
    } else {
        $updateData['hostname'] = $hostname;
    }
}

if (isset($input['model'])) {
    $updateData['model'] = $input['model'] ?: null;
}

if (isset($input['role'])) {
    if ($input['role'] && !Validator::validateSwitchRole($input['role'])) {
        $errors[] = 'Invalid switch role';
    } else {
        $updateData['role'] = $input['role'] ?: null;
    }
}

if (isset($input['tags'])) {
    $updateData['tags'] = $input['tags'] ?: null;
}

if (isset($input['ip_address'])) {
    $ipAddress = trim($input['ip_address']);
    if (empty($ipAddress)) {
        $errors[] = 'IP address cannot be empty';
    } elseif (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
        $errors[] = 'Invalid IP address format';
    } else {
        // Check if IP address is already in use by another switch
        $existingSwitch = $db->queryOne("SELECT id, hostname FROM switches WHERE ip_address = ? AND id != ?", [$ipAddress, $switchId]);
        if ($existingSwitch) {
            $errors[] = "IP address {$ipAddress} is already in use by switch '{$existingSwitch['hostname']}'";
        } else {
            $updateData['ip_address'] = $ipAddress;
        }
    }
}

// Update credentials if provided
if (isset($input['username']) || isset($input['password']) || isset($input['port']) || isset($input['use_https']) || isset($input['timeout'])) {
    $credUpdate = [];
    
    if (isset($input['username'])) {
        if (empty($input['username'])) {
            $errors[] = 'Username cannot be empty';
        } else {
            $credUpdate['username'] = $input['username'];
        }
    }
    
    if (isset($input['password'])) {
        if (empty($input['password'])) {
            $errors[] = 'Password cannot be empty';
        } else {
            $credUpdate['password_encrypted'] = Security::encrypt($input['password']);
        }
    }
    
    if (isset($input['port'])) {
        $port = (int)$input['port'];
        if (!Validator::validatePort($port)) {
            $errors[] = 'Invalid port number';
        } else {
            $credUpdate['port'] = $port;
        }
    }
    
    if (isset($input['use_https'])) {
        $credUpdate['use_https'] = (bool)$input['use_https'] ? 1 : 0;
    }
    
    if (isset($input['timeout'])) {
        $timeout = (int)$input['timeout'];
        if (!Validator::validateInteger($timeout, 1, 300)) {
            $errors[] = 'Timeout must be between 1 and 300 seconds';
        } else {
            $credUpdate['timeout'] = $timeout;
        }
    }
    
    if (!empty($credUpdate) && empty($errors)) {
        $credUpdate['updated_at'] = date('Y-m-d H:i:s');
        $db->update('switch_credentials', $credUpdate, 'switch_id = ?', [$switchId]);
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Update switch if there's data to update
if (!empty($updateData)) {
    $db->update('switches', $updateData, 'id = ?', [$switchId]);
}

// If hostname changed, push to device via eAPI
$pushedToDevice = false;
$devicePushError = null;
if (isset($updateData['hostname']) && $updateData['hostname'] !== $originalHostname) {
    try {
        $eapi = new AristaEAPI($switchId);
        // Use configuration sequence to set hostname
        $eapi->applyConfig(["hostname {$updateData['hostname']}"]);
        $pushedToDevice = true;
        logSwitchAction('Update device hostname via eAPI', $switchId, [
            'old_hostname' => $originalHostname,
            'new_hostname' => $updateData['hostname']
        ]);
    } catch (Exception $e) {
        // Do not fail the whole update; report back the device push failure
        $devicePushError = $e->getMessage();
        if (APP_DEBUG) {
            error_log("Failed to push hostname to device (switch {$switchId}): " . $devicePushError);
        }
    }
}

// Log action
logSwitchAction('Update switch', $switchId, $updateData);

// Get updated switch
$updatedSwitch = $db->queryOne("SELECT * FROM switches WHERE id = ?", [$switchId]);

echo json_encode([
    'success' => true,
    'message' => 'Switch updated successfully',
    'switch' => $updatedSwitch,
    'device_hostname_updated' => $pushedToDevice,
    'device_update_error' => $devicePushError
]);


