<?php
/**
 * Import Switches from CSV Endpoint
 * POST /api/import-export/import/switches.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/Validator.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';
require_once __DIR__ . '/../../log_action.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require operator role minimum
requireRole(['operator', 'admin']);

// Require CSRF token
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

// Check if file was uploaded
if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['csv_file'];

// Validate file type
$fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($fileExt !== 'csv') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Only CSV files are allowed']);
    exit;
}

// Validate file size
if ($file['size'] > UPLOAD_MAX_SIZE) {
    http_response_code(400);
    echo json_encode(['error' => 'File size exceeds maximum allowed size']);
    exit;
}

// Read CSV file
$handle = fopen($file['tmp_name'], 'r');
if ($handle === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read CSV file']);
    exit;
}

// Read header row
$headers = fgetcsv($handle);
if (!$headers) {
    fclose($handle);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid CSV format']);
    exit;
}

// Expected columns: hostname, ip_address, username, password, model, role, tags, port, use_https, timeout
$expectedColumns = ['hostname', 'ip_address', 'username', 'password'];
$columnMap = [];
foreach ($headers as $index => $header) {
    $header = strtolower(trim($header));
    $columnMap[$header] = $index;
}

// Check required columns
foreach ($expectedColumns as $col) {
    if (!isset($columnMap[$col])) {
        fclose($handle);
        http_response_code(400);
        echo json_encode(['error' => "Missing required column: {$col}"]);
        exit;
    }
}

// Process rows
$db = getDB();
$imported = 0;
$skipped = 0;
$errors = [];

while (($row = fgetcsv($handle)) !== false) {
    // Skip empty rows
    if (empty(array_filter($row))) {
        continue;
    }
    
    try {
        // Extract data
        $hostname = trim($row[$columnMap['hostname']] ?? '');
        $ipAddress = trim($row[$columnMap['ip_address'] ?? '']);
        $username = trim($row[$columnMap['username'] ?? '']);
        $password = trim($row[$columnMap['password'] ?? '']);
        $model = isset($columnMap['model']) ? trim($row[$columnMap['model']] ?? '') : null;
        $role = isset($columnMap['role']) ? trim($row[$columnMap['role']] ?? '') : null;
        $tags = isset($columnMap['tags']) ? trim($row[$columnMap['tags']] ?? '') : null;
        $port = isset($columnMap['port']) ? (int)($row[$columnMap['port']] ?? EAPI_DEFAULT_PORT) : EAPI_DEFAULT_PORT;
        $useHttps = isset($columnMap['use_https']) ? filter_var($row[$columnMap['use_https']] ?? true, FILTER_VALIDATE_BOOLEAN) : EAPI_DEFAULT_HTTPS;
        $timeout = isset($columnMap['timeout']) ? (int)($row[$columnMap['timeout']] ?? EAPI_DEFAULT_TIMEOUT) : EAPI_DEFAULT_TIMEOUT;
        
        // Validate
        if (empty($hostname) || empty($ipAddress) || empty($username) || empty($password)) {
            $skipped++;
            $errors[] = "Row skipped: Missing required fields";
            continue;
        }
        
        if (!Validator::validateIP($ipAddress)) {
            $skipped++;
            $errors[] = "Row skipped: Invalid IP address: {$ipAddress}";
            continue;
        }
        
        // Check if switch already exists
        $existing = $db->queryOne("SELECT id FROM switches WHERE ip_address = ?", [$ipAddress]);
        if ($existing) {
            $skipped++;
            $errors[] = "Switch already exists: {$ipAddress}";
            continue;
        }
        
        // Encrypt password
        $passwordEncrypted = Security::encrypt($password);
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Insert switch
            $switchId = $db->insert('switches', [
                'hostname' => $hostname,
                'ip_address' => $ipAddress,
                'model' => $model,
                'role' => $role,
                'tags' => $tags,
                'status' => 'unknown'
            ]);
            
            // Insert credentials
            $db->insert('switch_credentials', [
                'switch_id' => $switchId,
                'username' => $username,
                'password_encrypted' => $passwordEncrypted,
                'port' => $port,
                'use_https' => $useHttps ? 1 : 0,
                'timeout' => $timeout
            ]);
            
            // Test connection (optional, but good to verify)
            try {
                $eapi = new AristaEAPI($switchId);
                $version = $eapi->getVersion();
                
                $db->update('switches', [
                    'model' => $version['modelName'] ?? $model,
                    'firmware_version' => $version['version'] ?? null,
                    'status' => 'up',
                    'last_seen' => date('Y-m-d H:i:s')
                ], 'id = ?', [$switchId]);
            } catch (Exception $e) {
                // Connection failed but switch was created
                $db->update('switches', [
                    'status' => 'down'
                ], 'id = ?', [$switchId]);
            }
            
            $db->commit();
            $imported++;
            
            // Log action
            logSwitchAction('Import switch', $switchId, [
                'hostname' => $hostname,
                'ip_address' => $ipAddress
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            $skipped++;
            $errors[] = "Failed to import switch {$hostname}: " . $e->getMessage();
        }
        
    } catch (Exception $e) {
        $skipped++;
        $errors[] = "Error processing row: " . $e->getMessage();
    }
}

fclose($handle);

echo json_encode([
    'success' => true,
    'message' => "Import completed",
    'imported' => $imported,
    'skipped' => $skipped,
    'errors' => $errors
]);

