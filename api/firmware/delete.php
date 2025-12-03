<?php
/**
 * Firmware Delete Endpoint
 * DELETE /api/firmware/delete.php?id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../log_action.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Only admins can delete firmware files
requireRole('admin');

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid firmware ID']);
    exit;
}
$id = (int)$id;

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

$db = Database::getInstance();
$record = $db->queryOne("SELECT * FROM firmware_files WHERE id = ?", [$id]);
if (!$record) {
    http_response_code(404);
    echo json_encode(['error' => 'Firmware file not found']);
    exit;
}

$filePath = rtrim(FIRMWARE_STORAGE_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $record['stored_filename'];

try {
    $db->delete('firmware_files', 'id = ?', [$id]);
    @unlink($filePath);

    logAction('Delete firmware', 'firmware', $id, [
        'filename' => $record['original_filename'],
        'version' => $record['version'],
        'model' => $record['model']
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to delete firmware: ' . $e->getMessage()]);
}


