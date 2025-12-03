<?php
/**
 * List Interfaces Endpoint
 * GET /api/switches/interfaces/list.php?switch_id=<id>[&source=database|switch]
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../classes/AristaEAPI.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require authentication (viewer minimum)
requireAuth();

// Get switch ID
$switchId = $_GET['switch_id'] ?? null;
if (!$switchId || !is_numeric($switchId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid switch ID']);
    exit;
}
$switchId = (int) $switchId;

$db = Database::getInstance();
$switch = $db->queryOne("SELECT id, hostname FROM switches WHERE id = ?", [$switchId]);
if (!$switch) {
    http_response_code(404);
    echo json_encode(['error' => 'Switch not found']);
    exit;
}

$source = $_GET['source'] ?? 'database';

// Get list of port channel member interfaces to mark them as "joined"
$portChannelMembers = [];
try {
    $pcMembers = $db->query("
        SELECT DISTINCT pcm.interface_name
        FROM port_channel_members pcm
        INNER JOIN port_channels pc ON pcm.port_channel_id = pc.id
        WHERE pc.switch_id = ?
    ", [$switchId]);
    $portChannelMembers = array_column($pcMembers, 'interface_name');
} catch (Exception $e) {
    // Port channels table might not exist, ignore
}

if ($source === 'switch') {
    try {
        $eapi = new AristaEAPI($switchId);
        $interfaces = $eapi->getInterfaces();
        

        // Normalize payload
        $normalized = [];
        foreach ($interfaces as $key => $value) {
            $entry = [
                'interface_name' => null,
                'admin_status' => 'unknown',
                'oper_status' => 'unknown',
                'mode' => 'unknown',
                'vlan_id' => null,
                'native_vlan_id' => null,
                'trunk_vlans' => null,
                'speed' => null,
                'port_type' => null,
                'description' => null,
                'transceiver_temp' => null,
                'is_port_channel_member' => false,
                'port_channel_name' => null,
            ];

            if (is_array($value)) {
                $entry['interface_name'] = $value['name'] ?? ($value['interface'] ?? (is_string($key) ? $key : null));
                $entry['admin_status'] = strtolower($value['adminStatus'] ?? $value['admin_state'] ?? 'unknown');
                $entry['oper_status'] = strtolower($value['operStatus'] ?? $value['linkStatus'] ?? 'unknown');
                $entry['mode'] = strtolower($value['mode'] ?? $value['switchportMode'] ?? 'unknown');
                if (isset($value['accessVlan']) && is_numeric($value['accessVlan'])) {
                    $entry['vlan_id'] = (int)$value['accessVlan'];
                } elseif (isset($value['vlanId']) && is_numeric($value['vlanId'])) {
                    $entry['vlan_id'] = (int)$value['vlanId'];
                }
                if (!empty($value['trunkVlans'])) {
                    $entry['trunk_vlans'] = is_array($value['trunkVlans']) ? implode(',', $value['trunkVlans']) : (string)$value['trunkVlans'];
                }
                if (isset($value['nativeVlan']) && is_numeric($value['nativeVlan'])) {
                    $entry['native_vlan_id'] = (int)$value['nativeVlan'];
                } elseif (isset($value['nativeVlanId']) && is_numeric($value['nativeVlanId'])) {
                    $entry['native_vlan_id'] = (int)$value['nativeVlanId'];
                } elseif (isset($value['trunkNativeVlan']) && is_numeric($value['trunkNativeVlan'])) {
                    $entry['native_vlan_id'] = (int)$value['trunkNativeVlan'];
                }
                $entry['speed'] = $value['bandwidth'] ?? $value['speed'] ?? null;
                $entry['description'] = $value['description'] ?? ($value['desc'] ?? null);
            } else {
                $entry['interface_name'] = is_string($key) ? $key : null;
            }

            if ($entry['interface_name']) {
                // Mark if this interface is a port channel member and get parent port channel name
                if (in_array($entry['interface_name'], $portChannelMembers, true)) {
                    $entry['is_port_channel_member'] = true;
                    // Get the port channel name from the database
                    try {
                        $pcData = $db->queryOne("
                            SELECT pc.port_channel_name
                            FROM port_channel_members pcm
                            INNER JOIN port_channels pc ON pcm.port_channel_id = pc.id
                            WHERE pc.switch_id = ? AND pcm.interface_name = ?
                        ", [$switchId, $entry['interface_name']]);
                        if ($pcData) {
                            $entry['port_channel_name'] = $pcData['port_channel_name'];
                        }
                    } catch (Exception $e) {
                        // If query fails, port_channel_name stays null
                    }
                }
                $normalized[] = $entry;
            }
        }

        // Enrich admin/oper from 'show interfaces status' when unknown
        try {
            $status = $eapi->getInterfacesStatus();
            $statusByName = [];
            if (is_array($status)) {
                foreach ($status as $sk => $sv) {
                    $sname = is_array($sv) ? ($sv['name'] ?? ($sv['interface'] ?? (is_string($sk) ? $sk : null))) : (is_string($sk) ? $sk : null);
                    if ($sname) {
                        $statusByName[strtolower($sname)] = is_array($sv) ? $sv : [];
                    }
                }
                foreach ($normalized as &$row) {
                    $lname = strtolower($row['interface_name'] ?? '');
                    $s = $statusByName[$lname] ?? null;
                    if (!$s) continue;

                    $link = strtolower($s['linkStatus'] ?? '');
                    $lps = strtolower($s['lineProtocolStatus'] ?? '');

                    // Enrich admin_status
                    if ($row['admin_status'] === 'unknown' || $row['admin_status'] === null || $row['admin_status'] === '') {
                        if ($link !== '') {
                            $row['admin_status'] = ($link === 'disabled') ? 'down' : 'up';
                        }
                    }
                    // Enrich oper_status
                    if ($row['oper_status'] === 'unknown' || $row['oper_status'] === null || $row['oper_status'] === '') {
                        if ($link !== '' || $lps !== '') {
                            $row['oper_status'] = ($link === 'connected' || $lps === 'up') ? 'up' : 'down';
                        }
                    }
                    // Extract port type (Type field from show interfaces status)
                    // Try multiple field name variations - Arista eAPI may use different keys
                    $portType = $s['type'] ?? $s['Type'] ?? $s['portType'] ?? $s['interfaceType'] 
                        ?? $s['moduleType'] ?? $s['mediaType'] ?? $s['transceiverType'] 
                        ?? $s['physicalMediaType'] ?? null;
                    if ($portType) {
                        $portTypeStr = trim((string)$portType);
                        if ($portTypeStr !== '' && strtolower($portTypeStr) !== 'unknown') {
                            $row['port_type'] = $portTypeStr;
                        }
                    }
                    // Extract speed from status if not already set
                    if (empty($row['speed']) || $row['speed'] === null) {
                        $speedValue = $s['speed'] ?? $s['Speed'] ?? $s['bandwidth'] ?? null;
                        if ($speedValue) {
                            // Convert string speed like "10G" to bits per second if needed
                            // But if it's already numeric, use as is
                            if (is_numeric($speedValue)) {
                                $row['speed'] = $speedValue;
                            } elseif (is_string($speedValue)) {
                                // Try to parse "10G" format
                                $speedValue = str_replace([' ', 'G', 'g', 'M', 'm', 'K', 'k'], '', $speedValue);
                                if (is_numeric($speedValue)) {
                                    // Assume Gbps if no unit specified
                                    $row['speed'] = (int)$speedValue * 1000000000;
                                }
                            }
                        }
                    }
                }
                unset($row);
            }
        } catch (Exception $e) {
            // Ignore enrichment failure
        }

        // Enrich interfaces with transceiver temperature (optional)
        try {
            $transceiverData = $eapi->getInterfacesTransceiver();
            if (!empty($transceiverData)) {
                // Normalize transceiver data structure - eAPI returns data keyed by interface name
                $transceiverMap = [];
                if (is_array($transceiverData)) {
                    foreach ($transceiverData as $ifaceName => $value) {
                        if (is_array($value)) {
                            // First, check if transceiver module is actually present
                            $modulePresent = false;
                            
                            // Check for indicators that module is NOT present
                            $notPresentIndicators = [
                                'not present', 'notpresent', 'none', 'n/a', 'na', 
                                'absent', 'missing', 'unplugged', 'empty'
                            ];
                            
                            // Check port type - if "Not Present", no module
                            $portType = null;
                            $typeFields = ['type', 'portType', 'moduleType', 'transceiverType', 'mediaType'];
                            foreach ($typeFields as $field) {
                                if (isset($value[$field])) {
                                    $portType = strtolower(trim((string)$value[$field]));
                                    break;
                                }
                            }
                            
                            // Also check nested structures
                            if (!$portType && isset($value['dom']) && is_array($value['dom'])) {
                                foreach ($typeFields as $field) {
                                    if (isset($value['dom'][$field])) {
                                        $portType = strtolower(trim((string)$value['dom'][$field]));
                                        break;
                                    }
                                }
                            }
                            
                            // Check if port type indicates "Not Present"
                            if ($portType) {
                                $modulePresent = !in_array($portType, $notPresentIndicators);
                            }
                            
                            // Check for serial number or part number - if present, module exists
                            $serialFields = ['serialNumber', 'serial_number', 'serial', 'serialNum'];
                            $partFields = ['partNumber', 'part_number', 'partNum', 'part'];
                            $hasSerial = false;
                            $hasPart = false;
                            
                            foreach ($serialFields as $field) {
                                if (isset($value[$field]) && !empty($value[$field])) {
                                    $serial = strtolower(trim((string)$value[$field]));
                                    if (!empty($serial) && !in_array($serial, $notPresentIndicators)) {
                                        $hasSerial = true;
                                        break;
                                    }
                                }
                            }
                            
                            foreach ($partFields as $field) {
                                if (isset($value[$field]) && !empty($value[$field])) {
                                    $part = strtolower(trim((string)$value[$field]));
                                    if (!empty($part) && !in_array($part, $notPresentIndicators)) {
                                        $hasPart = true;
                                        break;
                                    }
                                }
                            }
                            
                            // Module is present if we have serial/part number OR port type indicates presence
                            if (!$modulePresent) {
                                $modulePresent = $hasSerial || $hasPart;
                            }
                            
                            // Only proceed if module is present
                            if (!$modulePresent) {
                                continue;
                            }
                            
                            // Extract temperature from various possible field names
                            $temp = null;
                            
                            // Try common field names (case-insensitive search)
                            $tempFields = ['temperature', 'temp', 'temp_c', 'tempC', 'Temperature', 'Temp'];
                            foreach ($tempFields as $field) {
                                if (isset($value[$field])) {
                                    $tempVal = $value[$field];
                                    // Skip if it's a "not present" indicator
                                    $tempStr = strtolower(trim((string)$tempVal));
                                    if (!in_array($tempStr, $notPresentIndicators) && $tempVal !== '' && $tempVal !== null) {
                                        $temp = $tempVal;
                                        break;
                                    }
                                }
                            }
                            
                            // Also check snake_case and camelCase variations in nested structures
                            if ($temp === null) {
                                foreach ($value as $k => $v) {
                                    $kLower = strtolower($k);
                                    if (strpos($kLower, 'temp') !== false) {
                                        $tempStr = strtolower(trim((string)$v));
                                        if (is_numeric($v) && !in_array($tempStr, $notPresentIndicators)) {
                                            $temp = $v;
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            // Check nested structures (dom, optical, etc.)
                            if ($temp === null && isset($value['dom']) && is_array($value['dom'])) {
                                $dom = $value['dom'];
                                foreach ($tempFields as $field) {
                                    if (isset($dom[$field])) {
                                        $tempVal = $dom[$field];
                                        $tempStr = strtolower(trim((string)$tempVal));
                                        if (!in_array($tempStr, $notPresentIndicators) && $tempVal !== '' && $tempVal !== null) {
                                            $temp = $tempVal;
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            if ($temp !== null) {
                                // Clean up temperature value (remove units, etc.)
                                $tempStr = (string)$temp;
                                // Remove non-numeric characters except decimal point and minus sign
                                $tempStr = preg_replace('/[^0-9.\-]/', '', $tempStr);
                                $tempFloat = floatval($tempStr);
                                
                                // Sanity check: reasonable temperature range for electronics (exclude 0 and invalid values)
                                if ($tempFloat > 0 && $tempFloat < 200) {
                                    // Normalize interface name for matching
                                    $normalizedIfaceName = strtolower(trim($ifaceName));
                                    $transceiverMap[$normalizedIfaceName] = $tempFloat;
                                }
                            }
                        }
                    }
                }
                
                // Add temperature to normalized interfaces
                foreach ($normalized as &$row) {
                    $lname = strtolower(trim($row['interface_name'] ?? ''));
                    
                    // Cross-check: If port_type indicates "Not Present", don't show temperature
                    $portType = strtolower(trim($row['port_type'] ?? ''));
                    $notPresentIndicators = ['not present', 'notpresent', 'none', 'n/a'];
                    $hasModule = true;
                    
                    if (!empty($portType)) {
                        foreach ($notPresentIndicators as $indicator) {
                            if (strpos($portType, $indicator) !== false) {
                                $hasModule = false;
                                break;
                            }
                        }
                    }
                    
                    // Only add temperature if module is present
                    if ($hasModule) {
                        // Try exact match first
                        if (isset($transceiverMap[$lname])) {
                            $row['transceiver_temp'] = $transceiverMap[$lname];
                        } else {
                            // Try partial match (in case interface name format differs)
                            foreach ($transceiverMap as $mapKey => $temp) {
                                if ($lname === $mapKey || 
                                    str_replace([' ', '-', '_'], '', $lname) === str_replace([' ', '-', '_'], '', $mapKey) ||
                                    strpos($mapKey, $lname) !== false || 
                                    strpos($lname, $mapKey) !== false) {
                                    $row['transceiver_temp'] = $temp;
                                    break;
                                }
                            }
                        }
                    }
                }
                unset($row);
            }
        } catch (Exception $e) {
            error_log("Failed to enrich interfaces with transceiver data: " . $e->getMessage());
            // Ignore transceiver enrichment failure - not all switches/interfaces have transceivers
        }

        // Enrich port channels with VLAN config from database
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
            
            // Create a map of port channel names to their config
            $pcConfigMap = [];
            foreach ($portChannels as $pc) {
                $pcConfigMap[strtolower($pc['interface_name'])] = $pc;
            }
            
            // Update existing port channels in normalized list with VLAN config
            foreach ($normalized as &$row) {
                $pcNameLower = strtolower($row['interface_name'] ?? '');
                if (isset($pcConfigMap[$pcNameLower])) {
                    $pcConfig = $pcConfigMap[$pcNameLower];
                    $row['mode'] = strtolower($pcConfig['mode'] ?? 'unknown');
                    $row['vlan_id'] = $pcConfig['vlan_id'];
                    $row['native_vlan_id'] = $pcConfig['native_vlan_id'];
                    $row['trunk_vlans'] = $pcConfig['trunk_vlans'];
                }
            }
            unset($row);
        } catch (Exception $e) {
            // Port channels table might not exist, ignore
        }
        
        echo json_encode([
            'success' => true,
            'interfaces' => $normalized,
            'source' => 'switch'
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch interfaces from switch: ' . $e->getMessage()]);
        exit;
    }
}

// Build a backward-compatible column list in case migrations haven't been applied yet
$columns = $db->query("SHOW COLUMNS FROM switch_interfaces");
$columnNames = array_map(function ($row) {
    return $row['Field'] ?? null;
}, $columns);

$has = function ($name) use ($columnNames) {
    return in_array($name, $columnNames, true);
};

$selectParts = [];
// interface_name
$selectParts[] = $has('interface_name') ? 'interface_name' : "'Unknown' AS interface_name";
// admin_status and oper_status (fallback to single 'status' if present, else 'unknown')
if ($has('admin_status')) {
    $selectParts[] = 'admin_status';
} elseif ($has('status')) {
    $selectParts[] = 'status AS admin_status';
} else {
    $selectParts[] = "'unknown' AS admin_status";
}
if ($has('oper_status')) {
    $selectParts[] = 'oper_status';
} elseif ($has('status')) {
    $selectParts[] = 'status AS oper_status';
} else {
    $selectParts[] = "'unknown' AS oper_status";
}
// mode
$selectParts[] = $has('mode') ? 'mode' : "'unknown' AS mode";
// vlan_id
$selectParts[] = $has('vlan_id') ? 'vlan_id' : 'NULL AS vlan_id';
// native_vlan_id (for trunk untagged/native VLAN)
$selectParts[] = $has('native_vlan_id') ? 'native_vlan_id' : 'NULL AS native_vlan_id';
// trunk_vlans
$selectParts[] = $has('trunk_vlans') ? 'trunk_vlans' : 'NULL AS trunk_vlans';
// speed
$selectParts[] = $has('speed') ? 'speed' : 'NULL AS speed';
// description
$selectParts[] = $has('description') ? 'description' : 'NULL AS description';
// last_synced (fallback to last_updated)
if ($has('last_synced')) {
    $selectParts[] = 'last_synced';
} elseif ($has('last_updated')) {
    $selectParts[] = 'last_updated AS last_synced';
} else {
    $selectParts[] = 'NULL AS last_synced';
}

$selectClause = implode(', ', $selectParts);
// Exclude interfaces that are port channel members (if port_channels table exists)
// Check if port_channels table exists first
$hasPortChannels = false;
try {
    $result = $db->query("SHOW TABLES LIKE 'port_channel_members'");
    $hasPortChannels = !empty($result);
} catch (Exception $e) {
    // Table doesn't exist or error, continue without filtering
}

$rows = [];
try {
    if ($hasPortChannels) {
        // Use NOT EXISTS to exclude port channel members
        // Note: Use column names/aliases as they appear in SELECT, not table.column
        $sql = "SELECT {$selectClause}
                FROM switch_interfaces si
                WHERE si.switch_id = ?
                  AND NOT EXISTS (
                    SELECT 1 
                    FROM port_channel_members pcm
                    INNER JOIN port_channels pc ON pcm.port_channel_id = pc.id
                    WHERE pc.switch_id = si.switch_id 
                      AND pcm.interface_name = si.interface_name
                  )
                ORDER BY interface_name ASC, last_synced DESC";
        $rows = $db->query($sql, [$switchId]);
    } else {
        // Port channels table doesn't exist, return all interfaces
        $sql = "SELECT {$selectClause}
                FROM switch_interfaces
                WHERE switch_id = ?
                ORDER BY interface_name ASC, last_synced DESC";
        $rows = $db->query($sql, [$switchId]);
    }
} catch (Exception $e) {
    // If query fails (e.g., port_channels table structure changed), fallback to simple query
    error_log("Interface list query failed: " . $e->getMessage());
    try {
        $sql = "SELECT {$selectClause}
                FROM switch_interfaces
                WHERE switch_id = ?
                ORDER BY interface_name ASC, last_synced DESC";
        $rows = $db->query($sql, [$switchId]);
    } catch (Exception $e2) {
        error_log("Fallback interface query also failed: " . $e2->getMessage());
        $rows = [];
    }
}

// Ensure rows is always an array
if (!is_array($rows)) {
    $rows = [];
}

// Deduplicate by interface_name, keeping the most recent row (due to ORDER BY last_synced DESC)
$byName = [];
if (is_array($rows)) {
    foreach ($rows as $row) {
        $name = $row['interface_name'] ?? null;
        if (!$name) {
            continue;
        }
        if (!isset($byName[$name])) {
            $byName[$name] = $row;
        }
    }
}
$interfaces = array_values($byName);

// Add port channel membership status to each interface
foreach ($interfaces as &$row) {
    $row['is_port_channel_member'] = in_array($row['interface_name'], $portChannelMembers, true);
}
unset($row);

// Enrich speed and type from 'show interfaces status'
try {
    $eapi = new AristaEAPI($switchId);
    $status = $eapi->getInterfacesStatus();
    $statusByName = [];
    if (is_array($status)) {
        foreach ($status as $sk => $sv) {
            $sname = is_array($sv) ? ($sv['name'] ?? ($sv['interface'] ?? ($sv['port'] ?? (is_string($sk) ? $sk : null)))) : (is_string($sk) ? $sk : null);
            if ($sname) {
                $statusByName[strtolower($sname)] = is_array($sv) ? $sv : [];
            }
        }
        foreach ($interfaces as &$row) {
            $lname = strtolower($row['interface_name'] ?? '');
            $s = $statusByName[$lname] ?? null;
            if (!$s) continue;
            // Speed: take status speed if DB value is null/empty
            $spd = $row['speed'] ?? null;
            if ($spd === null || $spd === '') {
                $row['speed'] = $s['speed'] ?? ($s['portSpeed'] ?? ($s['linkSpeed'] ?? $spd));
            }
            // Port type: add as 'port_type' for UI
            if (!isset($row['port_type']) || $row['port_type'] === null || $row['port_type'] === '') {
                $row['port_type'] = $s['type'] ?? ($s['medium'] ?? ($s['interfaceType'] ?? null));
            }
            // Additional fields
            if (!isset($row['bandwidth'])) {
                $row['bandwidth'] = $s['bandwidth'] ?? null;
            }
            if (!isset($row['interfaceType'])) {
                $row['interfaceType'] = $s['interfaceType'] ?? null;
            }
            if (!isset($row['linkStatus'])) {
                $row['linkStatus'] = $s['linkStatus'] ?? null;
            }
        }
        unset($row);
    }
} catch (Exception $e) {
    // Ignore enrichment failure
}

// Enrich admin/oper from 'show interfaces status' per-interface when unknown
try {
    $eapi = new AristaEAPI($switchId);
    $status = $eapi->getInterfacesStatus();
    $statusByName = [];
    if (is_array($status)) {
        foreach ($status as $sk => $sv) {
            $sname = is_array($sv) ? ($sv['name'] ?? ($sv['interface'] ?? (is_string($sk) ? $sk : null))) : (is_string($sk) ? $sk : null);
            if ($sname) {
                $statusByName[strtolower($sname)] = is_array($sv) ? $sv : [];
            }
        }
        foreach ($interfaces as &$row) {
            $lname = strtolower($row['interface_name'] ?? '');
            $s = $statusByName[$lname] ?? null;
            if (!$s) continue;

            $link = strtolower($s['linkStatus'] ?? '');
            $lps = strtolower($s['lineProtocolStatus'] ?? '');

            if (!isset($row['admin_status']) || $row['admin_status'] === null || $row['admin_status'] === '' || $row['admin_status'] === 'unknown') {
                if ($link !== '') {
                    $row['admin_status'] = ($link === 'disabled') ? 'down' : 'up';
                }
            }
            if (!isset($row['oper_status']) || $row['oper_status'] === null || $row['oper_status'] === '' || $row['oper_status'] === 'unknown') {
                if ($link !== '' || $lps !== '') {
                    $row['oper_status'] = ($link === 'connected' || $lps === 'up') ? 'up' : 'down';
                }
            }
        }
        unset($row);
    }
} catch (Exception $e) {
    // Ignore enrichment failure
}

// Always try to enrich from live switch data if status is still unknown
// This is a fallback if getInterfacesStatus() didn't provide complete data
$needsEnrichment = false;
foreach ($interfaces as $it) {
    $admin = strtolower($it['admin_status'] ?? 'unknown');
    $oper = strtolower($it['oper_status'] ?? 'unknown');
    if ($admin === 'unknown' || $admin === '' || $oper === 'unknown' || $oper === '') {
        $needsEnrichment = true;
        break;
    }
}

if ($needsEnrichment) {
    try {
        $eapi = new AristaEAPI($switchId);
        $live = $eapi->getInterfaces();
        $liveByName = [];
        foreach ($live as $lk => $lv) {
            $lname = is_array($lv) ? ($lv['name'] ?? ($lv['interface'] ?? (is_string($lk) ? $lk : null))) : (is_string($lk) ? $lk : null);
            if ($lname) {
                $liveByName[strtolower($lname)] = is_array($lv) ? $lv : [];
            }
        }
        foreach ($interfaces as &$row) {
            $lname = strtolower($row['interface_name'] ?? '');
            if (isset($liveByName[$lname])) {
                $lv = $liveByName[$lname];
                $currentAdmin = strtolower($row['admin_status'] ?? 'unknown');
                $currentOper = strtolower($row['oper_status'] ?? 'unknown');
                
                // Update admin_status if unknown
                if ($currentAdmin === 'unknown' || $currentAdmin === '' || $currentAdmin === null) {
                    $newAdmin = strtolower($lv['adminStatus'] ?? $lv['admin_state'] ?? 'unknown');
                    if ($newAdmin !== 'unknown') {
                        $row['admin_status'] = $newAdmin;
                    }
                }
                
                // Update oper_status if unknown
                if ($currentOper === 'unknown' || $currentOper === '' || $currentOper === null) {
                    $newOper = strtolower($lv['operStatus'] ?? $lv['linkStatus'] ?? 'unknown');
                    if ($newOper !== 'unknown') {
                        $row['oper_status'] = $newOper;
                    }
                }
            }
        }
        unset($row);
    } catch (Exception $e) {
        // Log but don't fail - return what we have
        error_log("Fallback interface status enrichment failed: " . $e->getMessage());
    }
}

// Add port channels as interfaces (they should appear in the interfaces list)
try {
    $portChannels = $db->query("
        SELECT 
            port_channel_name as interface_name,
            mode,
            vlan_id,
            native_vlan_id,
            trunk_vlans,
            admin_status,
            oper_status,
            description,
            'port-channel' as port_type
        FROM port_channels
        WHERE switch_id = ?
        ORDER BY port_channel_name ASC
    ", [$switchId]);
    
    foreach ($portChannels as $pc) {
        // Add port channels to interfaces list (they are virtual interfaces)
        $pcInterface = [
            'interface_name' => $pc['interface_name'],
            'admin_status' => strtolower($pc['admin_status'] ?? 'unknown'),
            'oper_status' => strtolower($pc['oper_status'] ?? 'unknown'),
            'mode' => strtolower($pc['mode'] ?? 'unknown'),
            'vlan_id' => $pc['vlan_id'],
            'native_vlan_id' => $pc['native_vlan_id'],
            'trunk_vlans' => $pc['trunk_vlans'],
            'speed' => null, // Port channels aggregate speed
            'description' => $pc['description'],
            'port_type' => 'Port-Channel',
            'last_synced' => null
        ];
        $interfaces[] = $pcInterface;
    }
} catch (Exception $e) {
    // Ignore if port_channels table doesn't exist yet
    error_log("Failed to load port channels for interface list: " . $e->getMessage());
}

// Ensure interfaces is always an array
if (!is_array($interfaces)) {
    $interfaces = [];
}

echo json_encode([
    'success' => true,
    'interfaces' => $interfaces,
    'source' => 'database'
]);
