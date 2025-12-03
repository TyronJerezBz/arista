<?php
/**
 * Export Switches to CSV Endpoint
 * GET /api/import-export/export/switches.php
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die('Method not allowed');
}

// Require authentication (viewer minimum)
requireAuth();

// Get switches
$db = getDB();
$sql = "SELECT s.id, s.hostname, s.ip_address, s.model, s.role, s.tags, s.status,
               sc.username, sc.port, sc.use_https, sc.timeout
        FROM switches s
        LEFT JOIN switch_credentials sc ON s.id = sc.switch_id
        ORDER BY s.hostname ASC";
$switches = $db->query($sql);

// Set headers for CSV download
$filename = 'switches_export_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Open output stream
$output = fopen('php://output', 'w');

// Write CSV header
fputcsv($output, ['hostname', 'ip_address', 'username', 'password', 'model', 'role', 'tags', 'port', 'use_https', 'timeout']);

// Write data rows (password will be masked in export)
foreach ($switches as $switch) {
    fputcsv($output, [
        $switch['hostname'],
        $switch['ip_address'],
        $switch['username'] ?? '',
        '***MASKED***', // Passwords are never exported
        $switch['model'] ?? '',
        $switch['role'] ?? '',
        $switch['tags'] ?? '',
        $switch['port'] ?? EAPI_DEFAULT_PORT,
        $switch['use_https'] ?? EAPI_DEFAULT_HTTPS ? 'true' : 'false',
        $switch['timeout'] ?? EAPI_DEFAULT_TIMEOUT
    ]);
}

fclose($output);
exit;

