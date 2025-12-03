<?php
/**
 * Alert Generator Class
 * 
 * Generates alerts based on switch status and events
 */

require_once __DIR__ . '/../config.php';

class AlertGenerator {
    /**
     * Generate alert for switch status change
     * @param int $switchId Switch ID
     * @param string $oldStatus Old status
     * @param string $newStatus New status
     */
    public static function generateStatusAlert($switchId, $oldStatus, $newStatus) {
        if ($oldStatus === $newStatus) {
            return;
        }
        
        $db = getDB();
        
        $severity = 'info';
        $message = "Switch status changed from {$oldStatus} to {$newStatus}";
        
        if ($newStatus === 'down') {
            $severity = 'critical';
            $message = "Switch is down";
        } elseif ($newStatus === 'unknown') {
            $severity = 'warning';
            $message = "Switch status is unknown";
        }
        
        // Check if similar alert already exists
        $existing = $db->queryOne(
            "SELECT id FROM alerts WHERE switch_id = ? AND message = ? AND acknowledged = 0 ORDER BY id DESC LIMIT 1",
            [$switchId, $message]
        );
        
        if (!$existing) {
            $db->insert('alerts', [
                'switch_id' => $switchId,
                'severity' => $severity,
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s'),
                'acknowledged' => false
            ]);
        }
    }
    
    /**
     * Generate alert for interface status change
     * @param int $switchId Switch ID
     * @param string $interfaceName Interface name
     * @param string $oldStatus Old status
     * @param string $newStatus New status
     */
    public static function generateInterfaceAlert($switchId, $interfaceName, $oldStatus, $newStatus) {
        if ($oldStatus === $newStatus) {
            return;
        }
        
        $db = getDB();
        
        $severity = 'info';
        $message = "Interface {$interfaceName} status changed from {$oldStatus} to {$newStatus}";
        
        if ($newStatus === 'down') {
            $severity = 'warning';
            $message = "Interface {$interfaceName} is down";
        }
        
        // Check if similar alert already exists
        $existing = $db->queryOne(
            "SELECT id FROM alerts WHERE switch_id = ? AND message = ? AND acknowledged = 0 ORDER BY id DESC LIMIT 1",
            [$switchId, $message]
        );
        
        if (!$existing) {
            $db->insert('alerts', [
                'switch_id' => $switchId,
                'severity' => $severity,
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s'),
                'acknowledged' => false
            ]);
        }
    }
    
    /**
     * Generate alert for connection failure
     * @param int $switchId Switch ID
     * @param string $errorMessage Error message
     */
    public static function generateConnectionAlert($switchId, $errorMessage) {
        $db = getDB();
        
        $message = "Failed to connect to switch: " . $errorMessage;
        
        // Check if similar alert already exists
        $existing = $db->queryOne(
            "SELECT id FROM alerts WHERE switch_id = ? AND message LIKE ? AND acknowledged = 0 ORDER BY id DESC LIMIT 1",
            [$switchId, "%Failed to connect to switch%"]
        );
        
        if (!$existing) {
            $db->insert('alerts', [
                'switch_id' => $switchId,
                'severity' => 'critical',
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s'),
                'acknowledged' => false
            ]);
        }
    }
    
    /**
     * Generate alert for configuration change
     * @param int $switchId Switch ID
     * @param string $changeDescription Description of change
     */
    public static function generateConfigAlert($switchId, $changeDescription) {
        $db = getDB();
        
        $db->insert('alerts', [
            'switch_id' => $switchId,
            'severity' => 'info',
            'message' => "Configuration changed: {$changeDescription}",
            'timestamp' => date('Y-m-d H:i:s'),
            'acknowledged' => false
        ]);
    }
}

