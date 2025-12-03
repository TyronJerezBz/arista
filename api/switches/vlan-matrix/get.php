<?php
/**
 * VLAN Matrix - Get grid data
 * GET /api/switches/vlan-matrix/get.php?switch_id=<id>
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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
$switch = $db->queryOne("SELECT id FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

// VLANs for this switch
$vlans = $db->query("SELECT vlan_id, name FROM switch_vlans WHERE switch_id = ? ORDER BY vlan_id ASC", [$switchId]);
$hasVlan1 = false;
foreach ($vlans as $v) {
    if ((int)$v['vlan_id'] === 1) { $hasVlan1 = true; break; }
}

// Interfaces (only physical Ethernet*) - build backward-compatible SELECT
$cols = $db->query("SHOW COLUMNS FROM switch_interfaces");
$colNames = array_map(function ($r) { return $r['Field'] ?? null; }, $cols);
$has = function ($name) use ($colNames) { return in_array($name, $colNames, true); };

$parts = [];
$parts[] = $has('interface_name') ? 'interface_name' : "'Unknown' AS interface_name";
$parts[] = $has('mode') ? 'mode' : "'unknown' AS mode";
$parts[] = $has('vlan_id') ? 'vlan_id' : 'NULL AS vlan_id';
$parts[] = $has('native_vlan_id') ? 'native_vlan_id' : 'NULL AS native_vlan_id';
$parts[] = $has('trunk_vlans') ? 'trunk_vlans' : 'NULL AS trunk_vlans';
$select = implode(', ', $parts);

// Check if port_channels table exists
$hasPortChannels = false;
try {
    $result = $db->query("SHOW TABLES LIKE 'port_channel_members'");
    $hasPortChannels = !empty($result);
} catch (Exception $e) {
    // Table doesn't exist or error, continue without filtering
}

// Always fetch interfaces from switch first (interfaces should always be pulled from switch)
// Fall back to database cache only if switch is unavailable
$ifs = [];
$memberInterfaces = [];

// Get port channel member interfaces to exclude (if port_channels table exists)
if ($hasPortChannels) {
    try {
        $memberRows = $db->query("
            SELECT DISTINCT pcm.interface_name
            FROM port_channel_members pcm
            INNER JOIN port_channels pc ON pcm.port_channel_id = pc.id
            WHERE pc.switch_id = ?
        ", [$switchId]);
        $memberInterfaces = array_column($memberRows, 'interface_name');
    } catch (Exception $e) {
        // Ignore
    }
}

// Try to fetch from switch first
try {
    require_once __DIR__ . '/../../classes/AristaEAPI.php';
    $eapi = new AristaEAPI($switchId);
    $live = $eapi->getInterfaces();
    
    foreach ($live as $key => $value) {
        $name = is_array($value) ? ($value['name'] ?? $value['interface'] ?? null) : (is_string($key) ? $key : null);
        if (!$name) continue;
        
        // Skip port channel members
        if ($hasPortChannels && in_array($name, $memberInterfaces, true)) {
            continue;
        }
        
        $lower = strtolower($name);
        // Include all Ethernet interfaces (Ethernet, Et, etc.)
        if (!(str_starts_with($lower, 'ethernet') || str_starts_with($lower, 'et'))) continue;
        
        $mode = strtolower($value['mode'] ?? $value['switchportMode'] ?? ($value['switchportInfo']['mode'] ?? 'unknown'));
        $accessVlan = null;
        if (isset($value['accessVlan']) && is_numeric($value['accessVlan'])) {
            $accessVlan = (int)$value['accessVlan'];
        } elseif (isset($value['vlanId']) && is_numeric($value['vlanId'])) {
            $accessVlan = (int)$value['vlanId'];
        } elseif (isset($value['switchportInfo']['accessVlan']) && is_numeric($value['switchportInfo']['accessVlan'])) {
            $accessVlan = (int)$value['switchportInfo']['accessVlan'];
        }
        $nativeVlan = null;
        if (isset($value['switchportInfo']['nativeVlan']) && is_numeric($value['switchportInfo']['nativeVlan'])) {
            $nativeVlan = (int)$value['switchportInfo']['nativeVlan'];
        } elseif (isset($value['nativeVlanId']) && is_numeric($value['nativeVlanId'])) {
            $nativeVlan = (int)$value['nativeVlanId'];
        } elseif (isset($value['trunkingNativeVlanId']) && is_numeric($value['trunkingNativeVlanId'])) {
            $nativeVlan = (int)$value['trunkingNativeVlanId'];
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
        $ifs[] = [
            'interface_name' => $name,
            'mode' => $mode,
            'vlan_id' => $accessVlan,
            'native_vlan_id' => $nativeVlan,
            'trunk_vlans' => $trunk,
            'is_port_channel_member' => 0,
            'port_channel_name' => null
        ];
    }
} catch (Exception $e) {
    // If switch fetch fails, fall back to database cache
    try {
        $results = $db->query("
            SELECT interface_name, mode, vlan_id, native_vlan_id, trunk_vlans
            FROM switch_interfaces
            WHERE switch_id = ? AND (interface_name LIKE 'Ethernet%' OR interface_name LIKE 'Et%')
            ORDER BY interface_name ASC
        ", [$switchId]);
        
        foreach ($results as $row) {
            $ifs[] = array_merge($row, [
                'is_port_channel_member' => 0,
                'port_channel_name' => null
            ]);
        }
    } catch (Exception $dbException) {
        // Continue if query fails - will return empty grid below
    }
}

// Always add port channels to the interface list (if they exist)
if ($hasPortChannels) {
    try {
        $portChannels = $db->query("
            SELECT 
                port_channel_name as interface_name,
                mode,
                vlan_id,
                native_vlan_id,
                trunk_vlans
            FROM port_channels
            WHERE switch_id = ?
            ORDER BY port_channel_name ASC
        ", [$switchId]);
        
        foreach ($portChannels as $pc) {
            // Check if port channel already exists in the list (avoid duplicates)
            $exists = false;
            $pcName = $pc['interface_name'];
            foreach ($ifs as $existing) {
                if (isset($existing['interface_name']) && $existing['interface_name'] === $pcName) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                $ifs[] = [
                    'interface_name' => $pcName,
                    'mode' => strtolower($pc['mode'] ?? 'unknown'),
                    'vlan_id' => $pc['vlan_id'],
                    'native_vlan_id' => $pc['native_vlan_id'],
                    'trunk_vlans' => $pc['trunk_vlans'],
                    'is_port_channel_member' => 0,
                    'port_channel_name' => null
                ];
            }
        }
        
        // Add port channel members if they exist
        try {
            $members = $db->query("
                SELECT 
                    pcm.interface_name,
                    pc.port_channel_name
                FROM port_channel_members pcm
                INNER JOIN port_channels pc ON pcm.port_channel_id = pc.id
                WHERE pc.switch_id = ?
                ORDER BY pc.port_channel_name, pcm.interface_name ASC
            ", [$switchId]);
            
            foreach ($members as $member) {
                // Check if member already exists in the list
                $exists = false;
                $memberName = $member['interface_name'];
                foreach ($ifs as $existing) {
                    if (isset($existing['interface_name']) && $existing['interface_name'] === $memberName) {
                        $exists = true;
                        break;
                    }
                }
                
                // Add member if not already in list
                if (!$exists) {
                    $ifs[] = [
                        'interface_name' => $memberName,
                        'mode' => 'unknown',
                        'vlan_id' => null,
                        'native_vlan_id' => null,
                        'trunk_vlans' => null,
                        'is_port_channel_member' => 1,
                        'port_channel_name' => $member['port_channel_name']
                    ];
                }
            }
        } catch (Exception $e) {
            // Port channel members might not exist, ignore
        }
    } catch (Exception $e) {
        // Port channels table might not exist, ignore
    }
}

// Build grid: interfaces x vlans with values: none|tagged|untagged
$grid = [];
foreach ($ifs as $row) {
    $iface = $row['interface_name'];
    $mode = strtolower($row['mode'] ?? 'unknown');
    $isPortChannelMember = (int)($row['is_port_channel_member'] ?? 0);
    $portChannelName = $row['port_channel_name'] ?? null;
    $accessVlan = $row['vlan_id'] ?? null;
    $nativeVlan = $row['native_vlan_id'] ?? null;
    $trunkList = $row['trunk_vlans'] ? array_filter(array_map('trim', explode(',', $row['trunk_vlans']))) : [];
    $trunkSet = array_flip($trunkList);

    $gridRow = [
        'interface' => $iface,
        'mode' => $mode,
        'assignments' => [], // vlan_id => status
        'is_port_channel_member' => (bool)$isPortChannelMember,
        'port_channel_name' => $portChannelName
    ];

    foreach ($vlans as $v) {
        $vid = (int)$v['vlan_id'];
        $status = 'none';
        if ($mode === 'access') {
            // Determine if access VLAN is effectively unset
            $accessUnset = ($accessVlan === null || $accessVlan === '' || (is_numeric($accessVlan) && (int)$accessVlan === 0));
            // If access VLAN is unknown/unset, assume default VLAN 1 when present
            if ($accessUnset && $hasVlan1 && $vid === 1) {
                $status = 'untagged';
            } elseif (is_numeric($accessVlan) && (int)$accessVlan === $vid) {
                $status = 'untagged';
            }
        } elseif ($mode === 'trunk') {
            if (isset($trunkSet[(string)$vid])) {
                $status = 'tagged';
            }
            if ($nativeVlan !== null && (int)$nativeVlan === $vid) {
                $status = 'untagged';
            }
        }
        $gridRow['assignments'][(string)$vid] = $status;
    }

    $grid[] = $gridRow;
}

echo json_encode([
    'success' => true,
    'vlans' => $vlans,
    'interfaces' => $grid
]);


