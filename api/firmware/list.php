<?php
/**
 * Firmware List Endpoint
 * GET /api/firmware/list.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireAuth();

$db = Database::getInstance();

try {
    $files = $db->query("
        SELECT ff.id,
               ff.original_filename,
               ff.stored_filename,
               ff.version,
               ff.model,
               ff.size,
               ff.checksum_sha256,
               ff.notes,
               ff.uploaded_at,
               ff.uploaded_by,
               u.username AS uploaded_by_username
        FROM firmware_files ff
        LEFT JOIN users u ON ff.uploaded_by = u.id
        ORDER BY ff.uploaded_at DESC
    ");

    $baseDownload = BASE_URL . '/api/firmware/download.php?id=';
    foreach ($files as &$file) {
        $file['download_url'] = $baseDownload . $file['id'];
    }
    unset($file);

    echo json_encode([
        'success' => true,
        'firmware' => $files
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load firmware list: ' . $e->getMessage()]);
}

