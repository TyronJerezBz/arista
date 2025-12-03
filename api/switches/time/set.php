<?php
/**
 * Set Switch Time / Timezone
 * POST /api/switches/time/set.php?switch_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';
require_once __DIR__ . '/../../log_action.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

$timezone = isset($input['timezone']) ? trim($input['timezone']) : null;
$offset = isset($input['offset']) ? trim($input['offset']) : null;
$datetime = isset($input['datetime']) ? trim($input['datetime']) : null;

if (($timezone === null || $timezone === '') && ($datetime === null || $datetime === '')) {
    http_response_code(400);
    echo json_encode(['error' => 'No changes specified']);
    exit;
}

if ($timezone !== null && $timezone !== '') {
    if (!preg_match('/^[A-Za-z0-9_\\/-]+$/', $timezone)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid timezone value']);
        exit;
    }
}

if ($offset !== null && $offset !== '') {
    if (!preg_match('/^[A-Za-z0-9:+-]+$/', $offset)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid offset value']);
        exit;
    }
}

$db = Database::getInstance();
$switch = $db->queryOne("SELECT id FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

try {
    $eapi = new AristaEAPI($switchId);
    $results = [];

    if ($timezone !== null && $timezone !== '') {
        $results['timezone'] = $eapi->setClockTimezone($timezone, $offset);
    }

    if ($datetime !== null && $datetime !== '') {
        $results['clock'] = $eapi->setClock($datetime);
    }

    logSwitchAction('Update clock/timezone', $switchId, [
        'timezone' => $timezone,
        'offset' => $offset,
        'datetime' => $datetime
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Clock settings updated',
        'results' => $results
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update clock: ' . $e->getMessage()]);
}

