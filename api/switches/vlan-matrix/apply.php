<?php
/**
 * VLAN Matrix - Apply changes
 * POST /api/switches/vlan-matrix/apply.php?switch_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/Validator.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';
require_once __DIR__ . '/../../log_action.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireRole(['operator','admin']);

$switchId = $_GET['switch_id'] ?? null;
if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}
$switchId = (int)$switchId;

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'CSRF token required']);
    exit;
}
requireCSRF($input['csrf_token']);

$changes = $input['changes'] ?? null;
if (!is_array($changes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload: changes required']);
    exit;
}

$db = Database::getInstance();
$switch = $db->queryOne("SELECT id FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// We will validate VLAN IDs by numeric range (1-4094) instead of requiring they already exist in DB.
// This allows assigning trunks to VLANs that will be added later.

$eapi = new AristaEAPI($switchId);
$applied = 0;
$errors = [];

// Discover available columns on switch_interfaces for backward compatibility
$cols = $db->query("SHOW COLUMNS FROM switch_interfaces");
$colNames = array_map(function ($r) { return $r['Field'] ?? null; }, $cols);
$has = function ($name) use ($colNames) { return in_array($name, $colNames, true); };

foreach ($changes as $row) {
    $iface = $row['interface'] ?? null;
    $mode = strtolower($row['mode'] ?? '');
    $assign = $row['assignments'] ?? []; // vlan_id => 'none'|'tagged'|'untagged'

    if (!$iface || !Validator::validateInterfaceName($iface)) {
        $errors[] = "Invalid interface: " . ($iface ?? 'unknown');
        continue;
    }
    if (!in_array($mode, ['access','trunk'], true)) {
        $errors[] = "Invalid mode for {$iface}";
        continue;
    }

    // Validate VLANs exist and rules
    $untagged = null;
    $tagged = [];
    foreach ($assign as $vid => $state) {
        // Basic numeric validation (1-4094). Do not require presence in DB at this stage.
        if (!is_numeric($vid) || (int)$vid < 1 || (int)$vid > 4094) {
            $errors[] = "Interface {$iface}: VLAN {$vid} is invalid (must be 1-4094)";
            continue 2;
        }
        if ($state === 'untagged') {
            if ($untagged !== null) {
                $errors[] = "Interface {$iface}: multiple untagged VLANs selected";
                continue 2;
            }
            $untagged = (int)$vid;
        } elseif ($state === 'tagged') {
            $tagged[] = (int)$vid;
        }
    }

    if ($mode === 'access') {
        if ($untagged === null) {
            $errors[] = "Interface {$iface}: access mode requires exactly one untagged VLAN";
            continue;
        }
        // Apply
        try {
            $eapi->configureInterface($iface, ['mode' => 'access', 'vlan' => $untagged]);
            // Update DB cache
            $db->delete('switch_interfaces', 'switch_id = ? AND interface_name = ?', [$switchId, $iface]);
            $insert = [
                'switch_id' => $switchId,
                'interface_name' => $iface
            ];
            if ($has('mode')) $insert['mode'] = 'access';
            if ($has('vlan_id')) $insert['vlan_id'] = $untagged;
            if ($has('native_vlan_id')) $insert['native_vlan_id'] = null;
            if ($has('trunk_vlans')) $insert['trunk_vlans'] = null;
            if ($has('last_synced')) {
                $insert['last_synced'] = date('Y-m-d H:i:s');
            } elseif ($has('last_updated')) {
                $insert['last_updated'] = date('Y-m-d H:i:s');
            }
            $db->insert('switch_interfaces', $insert);
            $applied++;
        } catch (Exception $e) {
            $errors[] = "Interface {$iface}: " . $e->getMessage();
            continue;
        }
    } else { // trunk
        if (empty($tagged) && $untagged === null) {
            $errors[] = "Interface {$iface}: trunk mode requires at least one VLAN (tagged or untagged)";
            continue;
        }
        $allowedStr = implode(',', $tagged);
        try {
            $cfg = [
                'mode' => 'trunk',
                'native_vlan' => $untagged
            ];
            if (trim($allowedStr) !== '') {
                $cfg['vlans'] = $allowedStr;
            }
            $eapi->configureInterface($iface, $cfg);
            // Update DB cache
            $db->delete('switch_interfaces', 'switch_id = ? AND interface_name = ?', [$switchId, $iface]);
            $insert = [
                'switch_id' => $switchId,
                'interface_name' => $iface
            ];
            if ($has('mode')) $insert['mode'] = 'trunk';
            if ($has('vlan_id')) $insert['vlan_id'] = null;
            if ($has('native_vlan_id')) $insert['native_vlan_id'] = $untagged;
            if ($has('trunk_vlans')) $insert['trunk_vlans'] = $allowedStr ?: null;
            if ($has('last_synced')) {
                $insert['last_synced'] = date('Y-m-d H:i:s');
            } elseif ($has('last_updated')) {
                $insert['last_updated'] = date('Y-m-d H:i:s');
            }
            $db->insert('switch_interfaces', $insert);
            $applied++;
        } catch (Exception $e) {
            $errors[] = "Interface {$iface}: " . $e->getMessage();
            continue;
        }
    }
}

logSwitchAction('Apply VLAN matrix', $switchId, [
    'applied' => $applied,
    'errors' => count($errors)
]);

echo json_encode([
    'success' => count($errors) === 0,
    'applied' => $applied,
    'errors' => $errors
]);


