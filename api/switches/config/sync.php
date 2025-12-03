<?php
/**
 * Sync Running Configuration Endpoint
 * POST /api/switches/config/sync.php?id=<switch_id>
 * 
 * Fetches running configuration from switch via eAPI and saves to database
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';

// Require operator or admin (read operation with DB write for history)
requireRole(['operator', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$switchId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$switchId) {
    http_response_code(400);
    echo json_encode(['error' => 'Switch ID required']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Require CSRF token
    if (!$input || !isset($input['csrf_token'])) {
        http_response_code(400);
        echo json_encode(['error' => 'CSRF token required']);
        exit;
    }
    requireCSRF($input['csrf_token']);
    
    $db = Database::getInstance();
    requirePermission('config.sync');
    
    // Verify switch exists
    $switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
    if (!$switch) {
        http_response_code(404);
        echo json_encode(['error' => 'Switch not found']);
        exit;
    }
    
    // Fetch running config from switch via eAPI
    $eapi = new AristaEAPI($switchId);
    
    $result = null;
    $lastError = null;
    
    // Try multiple commands to get running config
    $commands = [
        'show running-config',     // preferred - plain text
        'show startup-config',     // fallback
        'show config',             // some platforms
        'show configuration'       // legacy wording on some images
    ];
    
    foreach ($commands as $cmd) {
        try {
            // Request TEXT format directly from eAPI to avoid JSON-structured output
            $result = $eapi->runCommand($cmd, 'text');
            if ($result && !empty($result)) {
                break;
            }
        } catch (Exception $e) {
            $lastError = $e->getMessage();
            continue;
        }
    }
    
    if (!$result || empty($result)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to retrieve running config: ' . ($lastError ?: 'Empty response')]);
        exit;
    }
    
    // Extract config text from response
    $runningConfig = null;
    
    // Prefer plain text result (format=text returns array with first element as text)
    if (is_array($result) && isset($result[0]) && is_string($result[0])) {
        $runningConfig = $result[0];
    } elseif (is_string($result)) {
        $runningConfig = $result;
    } elseif (is_array($result) && isset($result[0]) && is_array($result[0])) {
        // Fallback: if platform still returns structured JSON, convert it
        $first = $result[0];
        if (isset($first['output'])) {
            $runningConfig = $first['output'];
        } elseif (isset($first['text'])) {
            $runningConfig = $first['text'];
        } elseif (isset($first['result']['output'])) {
            $runningConfig = $first['result']['output'];
        } elseif (isset($first['cmds']) || isset($first['result']['cmds'])) {
            $runningConfig = convertJsonConfigToText($first['cmds'] ?? $first['result']);
        }
    }
    
    // Final validation
    if (!is_string($runningConfig) || strlen(trim($runningConfig)) === 0) {
        http_response_code(500);
        if (APP_DEBUG) {
        }
        echo json_encode(['error' => 'Invalid configuration response from switch']);
        exit;
    }
    
    // Calculate hash to check if it's different from current
    $configHash = hash('sha256', $runningConfig);
    
    // Check if we already have this config
    $existing = $db->queryOne(
        "SELECT id FROM switch_configs WHERE switch_id = ? AND config_hash = ?",
        [$switchId, $configHash]
    );
    
    if ($existing) {
        // Config hasn't changed
        echo json_encode([
            'success' => true,
            'message' => 'Configuration is already synced (no changes detected)',
            'config_id' => $existing['id'],
            'changed' => false
        ]);
        exit;
    }
    
    // Save new config
    $configId = $db->insert('switch_configs', [
        'switch_id' => $switchId,
        'config_text' => $runningConfig,
        'config_hash' => $configHash,
        'backup_type' => 'scheduled',
        'created_by' => $_SESSION['user_id'] ?? null,
        'notes' => 'Synced from running config'
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Configuration synced successfully',
        'config_id' => $configId,
        'changed' => true,
        'size' => strlen($runningConfig),
        'lines' => count(array_filter(array_map('trim', explode("\n", $runningConfig))))
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to sync configuration: ' . $e->getMessage(),
        'debug' => APP_DEBUG ? $e->getTraceAsString() : null
    ]);
}

/**
 * Convert JSON config format to plain text format
 * Converts Arista structured JSON config to readable CLI format
 */
function convertJsonConfigToText($jsonConfig) {
    $output = [];
    
    // Add header if present
    if (isset($jsonConfig['header']) && is_array($jsonConfig['header'])) {
        foreach ($jsonConfig['header'] as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $output[] = $line;
            }
        }
    }
    
    // Process commands recursively
    if (isset($jsonConfig['cmds']) && is_array($jsonConfig['cmds'])) {
        $output = array_merge($output, processCommands($jsonConfig['cmds'], 0));
    }
    
    // Filter out empty lines and clean up
    $output = array_filter($output, function($line) {
        return trim($line) !== '';
    });
    
    // Add end marker if not already there
    if (end($output) !== 'end') {
        $output[] = 'end';
    }
    
    return implode("\n", $output);
}

/**
 * Recursively process command structure
 */
function processCommands($cmds, $depth = 0) {
    $output = [];
    $indent = str_repeat('   ', $depth);
    
    foreach ($cmds as $cmd => $subcmds) {
        // SKIP metadata keys - these are NOT configuration commands
        if (in_array($cmd, ['comments', 'cmds'])) {
            continue;
        }
        
        // Skip empty commands
        if (empty($cmd)) {
            continue;
        }
        
        // Add the command line
        $output[] = $indent . $cmd;
        
        // Check if there are actual sub-commands to process
        $hasRealSubcommands = false;
        
        // Process sub-commands if they exist and are not just metadata
        if ($subcmds === null) {
            // null means this command has no sub-commands - do nothing
            $hasRealSubcommands = false;
        } elseif (is_array($subcmds) && !empty($subcmds)) {
            // Check if array contains only metadata keys
            $hasOnlyMetadata = true;
            foreach ($subcmds as $key => $val) {
                if (!in_array($key, ['comments', 'cmds'])) {
                    $hasOnlyMetadata = false;
                    break;
                }
            }
            
            // If this is a structure with 'cmds' key, process those
            if (isset($subcmds['cmds']) && is_array($subcmds['cmds']) && !empty($subcmds['cmds'])) {
                $subOutput = processCommands($subcmds['cmds'], $depth + 1);
                if (!empty($subOutput)) {
                    $output = array_merge($output, $subOutput);
                    $hasRealSubcommands = true;
                }
            } elseif (!$hasOnlyMetadata) {
                // Process direct sub-commands (not wrapped in 'cmds')
                $subOutput = processCommands($subcmds, $depth + 1);
                if (!empty($subOutput)) {
                    $output = array_merge($output, $subOutput);
                    $hasRealSubcommands = true;
                }
            }
            
            // Only add separator if there were actual sub-commands
            if ($hasRealSubcommands) {
                $output[] = '!';
            }
        }
        
        // Add separator after command if no sub-commands
        if (!$hasRealSubcommands && $depth === 0) {
            $output[] = '!';
        }
    }
    
    return $output;
}
