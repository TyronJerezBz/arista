<?php
/**
 * Update User Endpoint
 * PUT /api/users/update.php?id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../classes/Validator.php';
require_once __DIR__ . '/../log_action.php';

// Only allow PUT requests
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require admin role
requireRole('admin');

// Get user ID
$userId = $_GET['id'] ?? null;
if (!$userId || !is_numeric($userId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}
$userId = (int)$userId;

// Require CSRF token
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

// Check if user exists
$db = Database::getInstance();
$user = $db->queryOne("SELECT id, username FROM users WHERE id = ?", [$userId]);
if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

// Prevent self-deletion of admin role
$currentUser = getCurrentUser();
if ($userId === $currentUser['id'] && isset($input['role']) && $input['role'] !== 'admin') {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot change your own admin role']);
    exit;
}

// Get updatable fields
$updateData = [];
$errors = [];

if (isset($input['username'])) {
    $username = trim($input['username']);
    if (empty($username)) {
        $errors[] = 'Username cannot be empty';
    } elseif (!Validator::validateUsername($username)) {
        $errors[] = 'Invalid username format';
    } else {
        // Check if username already exists (excluding current user)
        $existing = $db->queryOne("SELECT id FROM users WHERE username = ? AND id != ?", [$username, $userId]);
        if ($existing) {
            $errors[] = 'Username already exists';
        } else {
            $updateData['username'] = $username;
        }
    }
}

if (isset($input['password'])) {
    $password = $input['password'];
    if (!empty($password)) {
        $passwordValidation = Security::validatePassword($password);
        if (!$passwordValidation['valid']) {
            $errors = array_merge($errors, $passwordValidation['errors']);
        } else {
            $updateData['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
    }
}

if (isset($input['role'])) {
    if (!Validator::validateUserRole($input['role'])) {
        $errors[] = 'Invalid role';
    } else {
        $updateData['role'] = $input['role'];
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => 'Validation failed', 'errors' => $errors]);
    exit;
}

// Update user
if (!empty($updateData)) {
    $db->update('users', $updateData, 'id = ?', [$userId]);
}

// Log action
logUserAction('Update user', $userId, $updateData);

// Get updated user
$updatedUser = $db->queryOne("SELECT id, username, role FROM users WHERE id = ?", [$userId]);

echo json_encode([
    'success' => true,
    'message' => 'User updated successfully',
    'user' => $updatedUser
]);

