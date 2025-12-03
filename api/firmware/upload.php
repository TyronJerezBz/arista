<?php
/**
 * Firmware Upload Endpoint
 * POST /api/firmware/upload.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../log_action.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireRole(['operator', 'admin']);

$csrfToken = $_POST['csrf_token'] ?? null;
if (!$csrfToken) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($csrfToken);

if (!isset($_FILES['firmware'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No firmware file uploaded']);
    exit;
}

$file = $_FILES['firmware'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
    ];
    $msg = $errorMessages[$file['error']] ?? 'Unknown upload error';
    echo json_encode(['error' => $msg]);
    exit;
}

$size = $file['size'] ?? 0;
if ($size <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty firmware file']);
    exit;
}
if ($size > FIRMWARE_MAX_SIZE) {
    http_response_code(400);
    echo json_encode(['error' => 'Firmware file exceeds maximum size (' . round(FIRMWARE_MAX_SIZE / (1024 * 1024), 2) . ' MB)']);
    exit;
}

$originalName = $file['name'];
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($extension && !in_array($extension, FIRMWARE_ALLOWED_EXTENSIONS, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'File type not allowed. Allowed extensions: ' . implode(', ', FIRMWARE_ALLOWED_EXTENSIONS)]);
    exit;
}

$version = trim($_POST['version'] ?? '');
$model = trim($_POST['model'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$storageDir = FIRMWARE_STORAGE_PATH;
if (!is_dir($storageDir)) {
    if (!mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create firmware storage directory']);
        exit;
    }
}

$uniquePart = bin2hex(random_bytes(8));
$storedFilename = $uniquePart . ($extension ? '.' . $extension : '');
$destination = rtrim($storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedFilename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store firmware file']);
    exit;
}

$checksum = hash_file('sha256', $destination);
$finalSize = filesize($destination);
$uploadedBy = getCurrentUserId();

try {
    $db = Database::getInstance();
    $db->insert('firmware_files', [
        'original_filename' => $originalName,
        'stored_filename' => $storedFilename,
        'version' => $version ?: null,
        'model' => $model ?: null,
        'size' => $finalSize,
        'checksum_sha256' => $checksum,
        'notes' => $notes ?: null,
        'uploaded_by' => $uploadedBy
    ]);
    $id = $db->lastInsertId();

    logAction('Upload firmware', 'firmware', (int)$id, [
        'filename' => $originalName,
        'version' => $version,
        'model' => $model,
        'size' => $finalSize
    ]);

    $record = $db->queryOne("
        SELECT ff.*, u.username AS uploaded_by_username
        FROM firmware_files ff
        LEFT JOIN users u ON ff.uploaded_by = u.id
        WHERE ff.id = ?
    ", [$id]);

    $record['download_url'] = BASE_URL . "/api/firmware/download.php?id=" . $id;

    echo json_encode([
        'success' => true,
        'firmware' => $record
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    @unlink($destination);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save firmware metadata: ' . $e->getMessage()]);
}


