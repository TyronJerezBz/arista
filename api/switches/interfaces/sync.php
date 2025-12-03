<?php
/**
 * Sync Interfaces Endpoint
 * POST /api/switches/interfaces/sync.php?switch_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
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

$db = Database::getInstance();
$switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

try {
    $eapi = new AristaEAPI($switchId);
	$interfaces = $eapi->getInterfaces();

    // Get list of port channel member interfaces to exclude
    $memberInterfaces = [];
    try {
        $memberInterfaces = $db->query("
            SELECT DISTINCT pcm.interface_name
            FROM port_channel_members pcm
            INNER JOIN port_channels pc ON pcm.port_channel_id = pc.id
            WHERE pc.switch_id = ?
        ", [$switchId]);
        $memberInterfaces = array_column($memberInterfaces, 'interface_name');
    } catch (Exception $e) {
        // Port channels table might not exist, ignore
    }

    // Clear existing cache (but keep port channel members - they shouldn't be in switch_interfaces)
    $db->delete('switch_interfaces', 'switch_id = ?', [$switchId]);

    $synced = 0;
    $errors = [];

    foreach ($interfaces as $key => $value) {
        $name = is_array($value) ? ($value['name'] ?? $value['interface'] ?? null) : (is_string($key) ? $key : null);
        
        // Skip if no name
        if (!$name) {
            continue;
        }
        
        // Skip port channel MEMBERS - they should not appear in the interfaces list
        // But DO include port channels themselves
        if (in_array($name, $memberInterfaces, true)) {
            continue;
        }
        
        // Skip port channel interfaces - they're stored in a different table
        if (stripos($name, 'port-channel') === 0 || stripos($name, 'po') === 0) {
            continue;
        }

        $admin = strtolower($value['adminStatus'] ?? $value['admin_state'] ?? 'unknown');
        $oper = strtolower($value['operStatus'] ?? $value['linkStatus'] ?? 'unknown');
        $mode = strtolower($value['mode'] ?? $value['switchportMode'] ?? ($value['switchportInfo']['mode'] ?? 'unknown'));

        $accessVlan = null;
		if (isset($value['accessVlan']) && is_numeric($value['accessVlan'])) {
            $accessVlan = (int)$value['accessVlan'];
        } elseif (isset($value['vlanId']) && is_numeric($value['vlanId'])) {
            $accessVlan = (int)$value['vlanId'];
		} elseif (isset($value['switchportInfo']['accessVlan']) && is_numeric($value['switchportInfo']['accessVlan'])) {
			$accessVlan = (int)$value['switchportInfo']['accessVlan'];
        }

        $trunk = null;
        if (isset($value['trunkVlans'])) {
            $trunk = is_array($value['trunkVlans']) ? implode(',', $value['trunkVlans']) : (string)$value['trunkVlans'];
        } elseif (isset($value['switchportInfo']['trunkVlans'])) {
            $trunkInfo = $value['switchportInfo']['trunkVlans'];
            $trunk = is_array($trunkInfo) ? implode(',', $trunkInfo) : (string)$trunkInfo;
		} elseif (isset($value['trunkAllowedVlans'])) {
			$trunk = (string)$value['trunkAllowedVlans'];
		} elseif (isset($value['switchportInfo']['trunkAllowedVlans'])) {
			$trunk = (string)$value['switchportInfo']['trunkAllowedVlans'];
		} elseif (isset($value['trunkingVlans'])) {
			$trunk = (string)$value['trunkingVlans'];
		} elseif (isset($value['switchportInfo']['trunkingVlans'])) {
			$trunk = (string)$value['switchportInfo']['trunkingVlans'];
        }

        $speed = $value['bandwidth'] ?? $value['speed'] ?? ($value['linkSpeed'] ?? null);
        $description = $value['description'] ?? ($value['desc'] ?? null);

		// Native VLAN for trunks
		$nativeVlan = null;
		if (isset($value['nativeVlan']) && is_numeric($value['nativeVlan'])) {
			$nativeVlan = (int)$value['nativeVlan'];
		} elseif (isset($value['nativeVlanId']) && is_numeric($value['nativeVlanId'])) {
			$nativeVlan = (int)$value['nativeVlanId'];
		} elseif (isset($value['trunkNativeVlan']) && is_numeric($value['trunkNativeVlan'])) {
			$nativeVlan = (int)$value['trunkNativeVlan'];
		} elseif (isset($value['trunkingNativeVlanId']) && is_numeric($value['trunkingNativeVlanId'])) {
			$nativeVlan = (int)$value['trunkingNativeVlanId'];
		} elseif (isset($value['switchportInfo']['nativeVlan']) && is_numeric($value['switchportInfo']['nativeVlan'])) {
			$nativeVlan = (int)$value['switchportInfo']['nativeVlan'];
		} elseif (isset($value['switchportInfo']['nativeVlanId']) && is_numeric($value['switchportInfo']['nativeVlanId'])) {
			$nativeVlan = (int)$value['switchportInfo']['nativeVlanId'];
		} elseif (isset($value['switchportInfo']['trunkNativeVlan']) && is_numeric($value['switchportInfo']['trunkNativeVlan'])) {
			$nativeVlan = (int)$value['switchportInfo']['trunkNativeVlan'];
		} elseif (isset($value['switchportInfo']['trunkingNativeVlanId']) && is_numeric($value['switchportInfo']['trunkingNativeVlanId'])) {
			$nativeVlan = (int)$value['switchportInfo']['trunkingNativeVlanId'];
		}

        try {
			$data = [
                'switch_id' => $switchId,
                'interface_name' => $name,
                'mode' => $mode ?: 'unknown',
                'vlan_id' => $accessVlan,
                'trunk_vlans' => $trunk,
                'description' => $description
			];
			
			// Add columns only if they exist in the table
			$columns = $db->query("SHOW COLUMNS FROM switch_interfaces");
			$columnNames = array_column($columns, 'Field');
			
			if (in_array('admin_status', $columnNames)) {
				$data['admin_status'] = $admin ?: 'unknown';
			}
			if (in_array('oper_status', $columnNames)) {
				$data['oper_status'] = $oper ?: 'unknown';
			}
			if (in_array('speed', $columnNames)) {
				$data['speed'] = $speed;
			}
			if (in_array('native_vlan_id', $columnNames)) {
				$data['native_vlan_id'] = $nativeVlan;
			}
			if (in_array('last_synced', $columnNames)) {
				$data['last_synced'] = date('Y-m-d H:i:s');
			} elseif (in_array('last_updated', $columnNames)) {
				$data['last_updated'] = date('Y-m-d H:i:s');
			}
			
			$db->insert('switch_interfaces', $data);
            $synced++;
        } catch (Exception $e) {
            $errors[] = "Failed to cache interface {$name}: " . $e->getMessage();
        }
    }

    logSwitchAction('Sync interfaces from switch', $switchId, [
        'synced_count' => $synced,
        'errors' => count($errors)
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Synced {$synced} interfaces",
        'synced_count' => $synced,
        'errors' => $errors
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to sync interfaces: ' . $e->getMessage()]);
}
