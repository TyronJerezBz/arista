<?php
/**
 * Audit Logging Helper
 * 
 * Provides functions for logging user actions to the audit log
 */

require_once __DIR__ . '/config.php';

/**
 * Log an action to the audit log
 * 
 * @param string $action Action description (e.g., "Create switch", "Delete VLAN")
 * @param string $targetType Type of target (e.g., "switch", "vlan", "user")
 * @param int|null $targetId ID of the target (if applicable)
 * @param array|null $details Additional details as array (will be JSON encoded)
 * @param int|null $userId User ID (defaults to current user)
 * @return bool Success status
 */
function logAction($action, $targetType = null, $targetId = null, $details = null, $userId = null) {
    try {
        // Get user ID
        if ($userId === null) {
            require_once __DIR__ . '/auth.php';
            $userId = getCurrentUserId();
        }
        
        if ($userId === null) {
            return false; // Cannot log without user ID
        }
        
        // Get client IP address
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        
        // Encode details if provided
        $detailsJson = null;
        if ($details !== null) {
            $detailsJson = json_encode($details);
        }
        
        // Insert into audit log
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO audit_log (user_id, action, target_type, target_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $action,
            $targetType,
            $targetId,
            $detailsJson,
            $ipAddress
        ]);
        
        return true;
    } catch (Exception $e) {
        // Log error if logging fails (but don't break the application)
        if (LOG_ENABLED && APP_DEBUG) {
            error_log("Failed to log action: " . $e->getMessage());
        }
        return false;
    }
}

/**
 * Log switch-related actions
 */
function logSwitchAction($action, $switchId, $details = null) {
    return logAction($action, 'switch', $switchId, $details);
}

/**
 * Log VLAN-related actions
 */
function logVlanAction($action, $switchId, $vlanId, $details = null) {
    $details = array_merge($details ?? [], ['switch_id' => $switchId]);
    return logAction($action, 'vlan', $vlanId, $details);
}

/**
 * Log interface-related actions
 */
function logInterfaceAction($action, $switchId, $interfaceName, $details = null) {
    $details = array_merge($details ?? [], ['switch_id' => $switchId, 'interface' => $interfaceName]);
    return logAction($action, 'interface', null, $details);
}

/**
 * Log user-related actions
 */
function logUserAction($action, $targetUserId, $details = null) {
    return logAction($action, 'user', $targetUserId, $details);
}

/**
 * Log configuration-related actions
 */
function logConfigAction($action, $switchId, $details = null) {
    return logAction($action, 'config', $switchId, $details);
}

/**
 * Log alert-related actions
 */
function logAlertAction($action, $alertId, $details = null) {
    return logAction($action, 'alert', $alertId, $details);
}

