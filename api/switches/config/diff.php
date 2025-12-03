<?php
/**
 * Compare Configuration Backups Endpoint
 * GET /api/switches/config/diff.php?backup1=<id>&backup2=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require authentication (viewer minimum)
requireAuth();

// Get backup IDs
$backup1Id = $_GET['backup1'] ?? null;
$backup2Id = $_GET['backup2'] ?? null;

if (!$backup1Id || !is_numeric($backup1Id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid backup1 ID']);
    exit;
}

if (!$backup2Id || !is_numeric($backup2Id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid backup2 ID']);
    exit;
}

$backup1Id = (int)$backup1Id;
$backup2Id = (int)$backup2Id;

// Get backups
$db = Database::getInstance();
$backup1 = $db->queryOne("SELECT * FROM switch_configs WHERE id = ?", [$backup1Id]);
$backup2 = $db->queryOne("SELECT * FROM switch_configs WHERE id = ?", [$backup2Id]);

if (!$backup1) {
    http_response_code(404);
    echo json_encode(['error' => 'Backup 1 not found']);
    exit;
}

if (!$backup2) {
    http_response_code(404);
    echo json_encode(['error' => 'Backup 2 not found']);
    exit;
}

// Calculate diff using simple line-by-line comparison
$config1Lines = explode("\n", $backup1['config_text']);
$config2Lines = explode("\n", $backup2['config_text']);

$diff = [];
$maxLines = max(count($config1Lines), count($config2Lines));

for ($i = 0; $i < $maxLines; $i++) {
    $line1 = isset($config1Lines[$i]) ? trim($config1Lines[$i]) : null;
    $line2 = isset($config2Lines[$i]) ? trim($config2Lines[$i]) : null;
    
    if ($line1 === $line2) {
        $diff[] = ['type' => 'unchanged', 'line' => $line1, 'line_number' => $i + 1];
    } elseif ($line1 === null) {
        $diff[] = ['type' => 'added', 'line' => $line2, 'line_number' => $i + 1];
    } elseif ($line2 === null) {
        $diff[] = ['type' => 'removed', 'line' => $line1, 'line_number' => $i + 1];
    } else {
        $diff[] = ['type' => 'modified', 'old_line' => $line1, 'new_line' => $line2, 'line_number' => $i + 1];
    }
}

// Count changes
$stats = [
    'unchanged' => 0,
    'added' => 0,
    'removed' => 0,
    'modified' => 0
];

foreach ($diff as $change) {
    $stats[$change['type']]++;
}

echo json_encode([
    'success' => true,
    'backup1' => [
        'id' => $backup1['id'],
        'created_at' => $backup1['created_at'],
        'backup_type' => $backup1['backup_type']
    ],
    'backup2' => [
        'id' => $backup2['id'],
        'created_at' => $backup2['created_at'],
        'backup_type' => $backup2['backup_type']
    ],
    'diff' => $diff,
    'stats' => $stats
]);


