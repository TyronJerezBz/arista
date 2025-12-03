<?php
/**
 * Get Switch Time / Timezone
 * GET /api/switches/time/get.php?switch_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireRole(['operator', 'admin']);

$switchId = $_GET['switch_id'] ?? null;
if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}
$switchId = (int)$switchId;

$db = Database::getInstance();
$switch = $db->queryOne("SELECT id FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// Be tolerant: try each fetch independently and still return success
$clockText = null;
$timezoneLine = null;
$warnings = [];

try {
    $eapi = new AristaEAPI($switchId);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to initialize eAPI: ' . $e->getMessage()]);
    exit;
}

try {
    $clockText = $eapi->showClock();
    if ($clockText === null) {
        $warnings[] = 'Clock output unavailable';
    }
} catch (Exception $e) {
    $warnings[] = 'Clock fetch failed: ' . $e->getMessage();
}

try {
    $timezoneLine = $eapi->getClockTimezoneConfig();
} catch (Exception $e) {
    $warnings[] = 'Timezone fetch failed: ' . $e->getMessage();
}

echo json_encode([
    'success' => true,
    'clock' => $clockText,
    'timezone' => $timezoneLine,
    'warnings' => $warnings
]);

