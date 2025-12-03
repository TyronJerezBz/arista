<?php
/**
 * Get Switch Environment Status Endpoint
 * GET /api/switches/environment/get.php?switch_id=<id>
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
$switch = $db->queryOne("SELECT id, hostname, status FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Don't attempt to load environment if switch is down
if ($switch['status'] === 'down') {
    echo json_encode([
        'success' => false,
        'error' => 'Cannot load environment data: switch is offline',
        'message' => 'Environment data is not available when the switch is down'
    ]);
    exit;
}

try {
    $eapi = new AristaEAPI($switchId);
    
    // Fetch environment and locator LED status in parallel if possible, 
    // but for now sequential is fine as eAPI calls are relatively fast
    $environment = $eapi->getEnvironment();
    $locatorLed = $eapi->getLocatorLed();

    echo json_encode([
        'success' => true,
        'environment' => $environment,
        'locator_led' => $locatorLed
    ]);
} catch (Exception $e) {
    // Even if one fails, we might want to return partial data, 
    // but simpler to just return error for now if critical failure
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve environment status: ' . $e->getMessage()
    ]);
}

