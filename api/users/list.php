<?php
/**
 * List Users Endpoint
 * GET /api/users/list.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../auth.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require admin role
requireRole('admin');

// Get users
$db = Database::getInstance();
$sql = "SELECT id, username, role
        FROM users 
        ORDER BY username ASC";
$users = $db->query($sql);

// Remove password hashes from response (shouldn't be in DB query anyway, but just in case)
foreach ($users as &$user) {
    unset($user['password_hash']);
}

echo json_encode([
    'success' => true,
    'users' => $users
]);

