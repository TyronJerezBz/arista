<?php
/**
 * Firmware Download Endpoint
 * GET /api/firmware/download.php?id=<id>
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireAuth();

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid firmware ID']);
    exit;
}
$id = (int)$id;

$db = Database::getInstance();
$record = $db->queryOne("SELECT * FROM firmware_files WHERE id = ?", [$id]);
if (!$record) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Firmware file not found']);
    exit;
}

$filePath = rtrim(FIRMWARE_STORAGE_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $record['stored_filename'];
if (!file_exists($filePath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Firmware file missing on server']);
    exit;
}

$filename = $record['original_filename'] ?: ('firmware_' . $record['id']);
$filesize = filesize($filePath);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: no-cache');
header('Pragma: no-cache');

readfile($filePath);
exit;

