<?php
/**
 * Poll Switch Status Endpoint
 * POST /api/switches/poll.php?id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/AristaEAPI.php';
require_once __DIR__ . '/../classes/Validator.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../log_action.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

// Check if switch exists
$db = Database::getInstance();
$switch = $db->queryOne("SELECT id, hostname, ip_address FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Try to connect and poll switch
try {
    $eapi = new AristaEAPI($switchId);
    
    // Get switch information
    $version = $eapi->getVersion();
    $hostname = $eapi->getHostname();
    
    // Update switch status
    $updateData = [
        'status' => 'up',
        'last_seen' => date('Y-m-d H:i:s'),
        'last_polled' => date('Y-m-d H:i:s')
    ];
    
    if (isset($version['modelName'])) {
        $updateData['model'] = $version['modelName'];
    }
    
    if (isset($version['version'])) {
        $updateData['firmware_version'] = $version['version'];
    }
    
    if ($hostname) {
        $updateData['hostname'] = $hostname;
    }
    
    // Check environment status
    try {
        $env = $eapi->getEnvironment();
        $envAlert = false;
        
        // Check power supplies
        $psSource = $env['powerSupplySlots'] ?? $env['powerSupplies'] ?? [];
        foreach ($psSource as $ps) {
            $status = strtolower($ps['status'] ?? $ps['state'] ?? 'ok');
            if ($status !== 'ok' && $status !== 'connected') {
                $envAlert = true;
                break;
            }
        }
        
        // Check fans
        if (!$envAlert) {
            $fanSource = $env['fanTraySlots'] ?? $env['fans'] ?? [];
            foreach ($fanSource as $fan) {
                $status = strtolower($fan['status'] ?? $fan['state'] ?? 'ok');
                if ($status !== 'ok' && $status !== 'connected') {
                    $envAlert = true;
                    break;
                }
            }
        }
        
        // Check temperatures
        if (!$envAlert) {
            $tempSource = $env['tempSensors'] ?? $env['temperature'] ?? [];
            // Handle object or array
            $tempEntries = is_array($tempSource) ? $tempSource : array_values($tempSource);
            foreach ($tempEntries as $temp) {
                $isAlert = $temp['inAlertState'] ?? $temp['alert'] ?? false;
                if ($isAlert) {
                    $envAlert = true;
                    break;
                }
            }
        }
        
        // Check system status
        if (!$envAlert) {
            $sysStatus = strtolower($env['systemStatus'] ?? $env['status'] ?? 'normal');
            if ($sysStatus !== 'normal' && $sysStatus !== 'ok') {
                $envAlert = true;
            }
        }
        
        // Only add to update if column exists (safe check)
        $cols = $db->query("SHOW COLUMNS FROM switches LIKE 'environment_alert'");
        if (!empty($cols)) {
            $updateData['environment_alert'] = $envAlert ? 1 : 0;
        }
    } catch (Exception $e) {
        // Ignore environment check failure
    }
    
    $db->update('switches', $updateData, 'id = ?', [$switchId]);
    
    // Sync VLANs
    try {
        $vlans = $eapi->getVlans();
        if (is_array($vlans)) {
            // Clear existing VLANs
            $db->delete('switch_vlans', 'switch_id = ?', [$switchId]);
            
            // Insert new VLANs
            foreach ($vlans as $vlanId => $vlanData) {
                if (is_numeric($vlanId) && Validator::validateVlanId($vlanId)) {
                    $db->insert('switch_vlans', [
                        'switch_id' => $switchId,
                        'vlan_id' => (int)$vlanId,
                        'name' => $vlanData['name'] ?? null,
                        'description' => $vlanData['description'] ?? null
                    ]);
                }
            }
        }
    } catch (Exception $e) {
        // VLAN sync failed, but continue
        error_log("VLAN sync failed for switch {$switchId}: " . $e->getMessage());
    }
    
    // Sync interfaces
    try {
        $interfaces = $eapi->getInterfaces();
        if (is_array($interfaces)) {
            // Clear existing interfaces
            $db->delete('switch_interfaces', 'switch_id = ?', [$switchId]);
            
            // Insert new interfaces
            foreach ($interfaces as $interfaceName => $interfaceData) {
                if (Validator::validateInterfaceName($interfaceName)) {
                    $status = 'unknown';
                    if (isset($interfaceData['interfaceStatus'])) {
                        $status = $interfaceData['interfaceStatus'] === 'connected' ? 'up' : 'down';
                    }
                    
                    $db->insert('switch_interfaces', [
                        'switch_id' => $switchId,
                        'interface_name' => $interfaceName,
                        'vlan_id' => null, // Will be set from configuration
                        'mode' => 'access',
                        'description' => $interfaceData['description'] ?? null,
                        'status' => $status
                    ]);
                }
            }
        }
    } catch (Exception $e) {
        // Interface sync failed, but continue
        error_log("Interface sync failed for switch {$switchId}: " . $e->getMessage());
    }
    
    // Log action
    logSwitchAction('Poll switch', $switchId);
    
    // Get updated switch
    $updatedSwitch = $db->queryOne("SELECT * FROM switches WHERE id = ?", [$switchId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Switch polled successfully',
        'switch' => $updatedSwitch
    ]);
    
} catch (Exception $e) {
    // Connection failed
    $errorMessage = $e->getMessage();
    
    // Determine if this is a connection error
    $isConnectionError = false;
    $connectionErrorMessages = [
        'connection timed out',
        'connection refused',
        'could not resolve host',
        'failed to connect',
        'connection reset',
        'no route to host',
        'network is unreachable'
    ];
    
    $errorLower = strtolower($errorMessage);
    foreach ($connectionErrorMessages as $msg) {
        if (strpos($errorLower, $msg) !== false) {
            $isConnectionError = true;
            break;
        }
    }
    
    // If it's a connection error or any error during polling, set status to down
    // ALWAYS set status to 'down' on any connection/polling error
    $updateData = [
        'status' => 'down',
        'last_polled' => date('Y-m-d H:i:s')
    ];
    
    // Try to store error reason if column exists
    try {
        $cols = $db->query("SHOW COLUMNS FROM switches LIKE 'last_error'");
        if (!empty($cols)) {
            // Truncate error message if too long (TEXT field but better safe)
            $updateData['last_error'] = mb_substr($errorMessage, 0, 65535);
        }
    } catch (Exception $e) {
        // Column doesn't exist, ignore
        if (APP_DEBUG) {
            error_log("last_error column check failed (not critical): " . $e->getMessage());
        }
    }
    
    // CRITICAL: Always update status to 'down' - wrap in try-catch but log if fails
    try {
        $affectedRows = $db->update('switches', $updateData, 'id = ?', [$switchId]);
    } catch (Exception $updateException) {
        // Log but continue - this is critical so log as error
        error_log("CRITICAL: Failed to update switch status to 'down' for switch {$switchId}: " . $updateException->getMessage());
        error_log("Update exception trace: " . $updateException->getTraceAsString());
    }
    
    // Return detailed error information
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to connect to switch: ' . $errorMessage,
        'reason' => $errorMessage,
        'details' => [
            'switch_id' => $switchId,
            'switch_info' => $switch ?? null
        ]
    ]);
}


