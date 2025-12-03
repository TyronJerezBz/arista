<?php
/**
 * Get Interface Transceiver Details Endpoint
 * GET /api/switches/interfaces/transceiver.php?switch_id=<id>[&interface=<interface_name>]
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require authentication (viewer minimum)
requireAuth();

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

// Optional interface filter
$interface = $_GET['interface'] ?? null;

try {
    $eapi = new AristaEAPI($switchId);
    $transceiver = $eapi->getInterfacesTransceiver($interface);
    
    // Normalize response - if interface is specified, extract just that interface's data
    $normalized = null;
    
    if ($interface) {
        // Clean interface name for matching
        $ifaceLower = strtolower(trim($interface));
        $ifaceClean = str_replace([' ', '-', '_'], '', $ifaceLower);
        
        // Try different matching strategies
        if (is_array($transceiver)) {
            // Strategy 1: Exact key match
            foreach ($transceiver as $key => $value) {
                $keyLower = strtolower(trim($key));
                $keyClean = str_replace([' ', '-', '_'], '', $keyLower);
                
                // Exact match
                if ($keyLower === $ifaceLower || $keyClean === $ifaceClean) {
                    $normalized = is_array($value) ? $value : $transceiver;
                    break;
                }
                
                // Partial match (e.g., "Ethernet2" matches "Ethernet 2")
                if (strpos($keyLower, $ifaceLower) !== false || strpos($ifaceLower, $keyLower) !== false) {
                    $normalized = is_array($value) ? $value : null;
                    if ($normalized) break;
                }
            }
            
            // Strategy 2: If no match found, check if transceiver is already the interface data
            if (!$normalized && !empty($transceiver)) {
                // Check if the array itself contains interface-like keys
                $keys = array_keys($transceiver);
                $firstKey = reset($keys);
                if (preg_match('/^(Ethernet|Management|Port-Channel)/i', $firstKey)) {
                    // This is an array of interfaces, try to find match
                    foreach ($transceiver as $key => $value) {
                        $keyLower = strtolower(trim($key));
                        if ($keyLower === $ifaceLower || 
                            str_replace([' ', '-', '_'], '', $keyLower) === $ifaceClean) {
                            $normalized = is_array($value) ? $value : null;
                            if ($normalized) break;
                        }
                    }
                } else {
                    // Might already be the interface data directly
                    $normalized = $transceiver;
                }
            }
        }
        
        // Strategy 3: If still empty and we have data, use first entry
        if (!$normalized && is_array($transceiver) && !empty($transceiver)) {
            $firstValue = reset($transceiver);
            if (is_array($firstValue)) {
                $normalized = $firstValue;
            } else {
                $normalized = $transceiver;
            }
        }
    } else {
        $normalized = $transceiver;
    }
    
    // If normalized is still null, set to empty array
    if ($normalized === null) {
        $normalized = [];
    }
    
    echo json_encode([
        'success' => true,
        'transceiver' => $normalized
    ]);
} catch (Exception $e) {
    error_log("Transceiver API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve transceiver details: ' . $e->getMessage()
    ]);
}

