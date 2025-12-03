<?php
/**
 * Switch Restart Endpoint
 * POST   /api/switches/restart.php?id=<id> - Immediate or scheduled restart
 * DELETE /api/switches/restart.php?id=<id>&task_id=<task_id> - Cancel restart
 * GET    /api/switches/restart.php?id=<id> - Get restart status/scheduled tasks
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/AristaEAPI.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../log_action.php';

// Only admin can restart switches
requireRole(['admin']);

$method = $_SERVER['REQUEST_METHOD'];
$switchId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$switchId) {
    http_response_code(400);
    echo json_encode(['error' => 'Switch ID required']);
    exit;
}

$db = Database::getInstance();

// Verify switch exists
$switch = $db->queryOne("SELECT id, hostname, ip_address FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// ============================================
// GET - View scheduled restarts
// ============================================
if ($method === 'GET') {
    requirePermission('restart.view');
    try {
        $tasks = $db->query(
            "SELECT id, task_type, scheduled_time, status, result, created_by, created_at 
             FROM scheduled_tasks 
             WHERE switch_id = ? AND status IN ('pending', 'running')
             ORDER BY scheduled_time ASC",
            [$switchId]
        );
        
        // Add creator names
        foreach ($tasks as &$task) {
            $creator = $db->queryOne("SELECT username FROM users WHERE id = ?", [$task['created_by']]);
            $task['created_by_name'] = $creator['username'] ?? 'Unknown';
            $task['task_data'] = json_decode($task['task_data'], true);
        }
        
        echo json_encode([
            'success' => true,
            'switch' => $switch,
            'scheduled_tasks' => $tasks
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch scheduled tasks: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// DELETE - Cancel scheduled restart
// ============================================
if ($method === 'DELETE') {
    requirePermission('restart.cancel');
    $taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : null;
    
    if (!$taskId) {
        http_response_code(400);
        echo json_encode(['error' => 'Task ID required to cancel']);
        exit;
    }
    
    try {
        // Verify task exists and belongs to this switch
        $task = $db->queryOne(
            "SELECT id, switch_id, status FROM scheduled_tasks WHERE id = ? AND switch_id = ?",
            [$taskId, $switchId]
        );
        
        if (!$task) {
            http_response_code(404);
            echo json_encode(['error' => 'Scheduled task not found']);
            exit;
        }
        
        if ($task['status'] === 'cancelled') {
            http_response_code(400);
            echo json_encode(['error' => 'Task is already cancelled']);
            exit;
        }
        
        if ($task['status'] === 'completed') {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot cancel completed task']);
            exit;
        }
        
        // Update task status to cancelled
        $db->update(
            'scheduled_tasks',
            ['status' => 'cancelled'],
            'id = ?',
            [$taskId]
        );
        
        // Log action
        logSwitchAction('Cancel Scheduled Restart', $switchId, ['task_id' => $taskId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Scheduled restart cancelled'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to cancel restart: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// POST - Immediate or Scheduled Restart
// ============================================
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Require CSRF token
    if (!$input || !isset($input['csrf_token'])) {
        http_response_code(400);
        echo json_encode(['error' => 'CSRF token required']);
        exit;
    }
    requireCSRF($input['csrf_token']);
    
    $scheduleAt = $input['schedule_at'] ?? null;
    $reason = $input['reason'] ?? null;
    $force = isset($input['force']) ? (bool)$input['force'] : false;
    
    try {
        // ---- IMMEDIATE RESTART ----
        if (!$scheduleAt) {
            requirePermission('restart.immediate');
            // Confirm with user that they want to restart NOW
            if (!isset($input['confirm_immediate']) || !$input['confirm_immediate']) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Immediate restart requires confirmation',
                    'requires_confirmation' => true
                ]);
                exit;
            }
            
            // Execute restart on switch
            try {
                $eapi = new AristaEAPI($switchId);
                
                if ($force) {
                    // Force reload without saving
                    $eapi->runCommand('reload force');
                } else {
                    // Normal reload (saves running-config first)
                    $eapi->runCommand('reload');
                }
                
                // Log action
                logSwitchAction(
                    'Immediate Restart',
                    $switchId,
                    ['force' => $force, 'reason' => $reason]
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Switch restarting now',
                    'switch_id' => $switchId,
                    'restart_time' => date('Y-m-d H:i:s'),
                    'note' => 'Switch will be unavailable for 2-5 minutes'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Failed to execute restart: ' . $e->getMessage()
                ]);
            }
        }
        // ---- SCHEDULED RESTART ----
        else {
            requirePermission('restart.schedule');
            // Parse and validate scheduled time
            $scheduledTime = strtotime($scheduleAt);
            if ($scheduledTime === false || $scheduledTime <= time()) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid scheduled time (must be in future)']);
                exit;
            }
            
            $scheduledTimeFormatted = date('Y-m-d H:i:s', $scheduledTime);
            
            // Create scheduled task
            $taskData = [
                'reason' => $reason,
                'force' => $force,
                'created_by_id' => getCurrentUserId()
            ];
            
            $taskId = $db->insert('scheduled_tasks', [
                'switch_id' => $switchId,
                'task_type' => 'restart',
                'task_data' => json_encode($taskData),
                'scheduled_time' => $scheduledTimeFormatted,
                'status' => 'pending',
                'created_by' => getCurrentUserId()
            ]);
            
            // Log action
            logSwitchAction(
                'Schedule Restart',
                $switchId,
                [
                    'scheduled_time' => $scheduledTimeFormatted,
                    'reason' => $reason,
                    'task_id' => $taskId
                ]
            );
            
            echo json_encode([
                'success' => true,
                'message' => 'Restart scheduled successfully',
                'task_id' => $taskId,
                'switch_id' => $switchId,
                'scheduled_time' => $scheduledTimeFormatted,
                'reason' => $reason
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

