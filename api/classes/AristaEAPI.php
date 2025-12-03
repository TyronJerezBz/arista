<?php
/**
 * Arista eAPI Client Class
 * 
 * Provides methods to interact with Arista switches via eAPI (JSON-RPC)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Security.php';

class AristaEAPI {
    private $switchId;
    private $ipAddress;
    private $username;
    private $password;
    private $port;
    private $useHttps;
    private $timeout;
    private $verifySSL;
    private $baseUrl;
    
    /**
     * Constructor
     * @param int $switchId Switch ID from database
     */
    public function __construct($switchId) {
        $this->switchId = $switchId;
        $this->loadCredentials();
    }
    
    /**
     * Load switch credentials from database
     */
    private function loadCredentials() {
        $db = getDB();
        
        // Get switch IP address
        $stmt = $db->prepare("SELECT ip_address FROM switches WHERE id = ?");
        $stmt->execute([$this->switchId]);
        $switch = $stmt->fetch();
        
        if (!$switch) {
            throw new Exception("Switch not found");
        }
        
        $this->ipAddress = $switch['ip_address'];
        
        // Get credentials
        $stmt = $db->prepare("
            SELECT username, password_encrypted, port, use_https, timeout
            FROM switch_credentials
            WHERE switch_id = ?
        ");
        $stmt->execute([$this->switchId]);
        $creds = $stmt->fetch();
        
        if (!$creds) {
            throw new Exception("Switch credentials not found");
        }
        
        $this->username = $creds['username'];
        $this->password = Security::decrypt($creds['password_encrypted']);
        $this->port = $creds['port'] ?? EAPI_DEFAULT_PORT;
        $this->useHttps = (bool)($creds['use_https'] ?? EAPI_DEFAULT_HTTPS);
        $this->timeout = $creds['timeout'] ?? EAPI_DEFAULT_TIMEOUT;
        $this->verifySSL = EAPI_VERIFY_SSL;
        
        // Build base URL
        $protocol = $this->useHttps ? 'https' : 'http';
        $this->baseUrl = "{$protocol}://{$this->ipAddress}:{$this->port}/command-api";
    }
    
    /**
     * Execute a single command
     * @param string $command Command to execute
     * @param string $format Response format ('json' or 'text')
     * @return mixed Response data
     */
    public function runCommand($command, $format = 'json') {
        return $this->runCommands([$command], $format);
    }
    
    /**
     * Execute multiple commands
     * @param array $commands Array of commands to execute
     * @param string $format Response format ('json' or 'text')
     * @return array Response data
     */
    public function runCommands($commands, $format = 'json') {
        // Normalize commands array - convert objects to proper format
        $normalizedCommands = [];
        foreach ($commands as $cmd) {
            if (is_array($cmd) && isset($cmd['cmd'])) {
                // This is a command object with potential 'input' parameter
                $normalizedCommands[] = $cmd;
            } elseif (is_string($cmd)) {
                // Simple string command
                $normalizedCommands[] = $cmd;
            }
        }
        
        // Build JSON-RPC request
        $request = [
            'jsonrpc' => '2.0',
            'method' => 'runCmds',
            'params' => [
                'version' => 1,
                'cmds' => $normalizedCommands,
                'format' => $format
            ],
            'id' => 1
        ];
        
        // Make HTTP request
        $response = $this->makeRequest($request);
        
        // Check for errors
        if (isset($response['error'])) {
            throw new Exception("eAPI Error: " . ($response['error']['message'] ?? 'Unknown error'));
        }
        
        return $response['result'] ?? [];
    }
    
    /**
     * Make HTTP request to eAPI
     * @param array $request JSON-RPC request
     * @return array Response data
     */
    private function makeRequest($request) {
        $json = json_encode($request);
        
        // Initialize cURL
        $ch = curl_init($this->baseUrl);
        
        // Set cURL options
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json)
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_VERBOSE => APP_DEBUG
        ]);
        
        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        // Check for cURL errors
        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }
        
        // Check HTTP status code
        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: {$httpCode}");
        }
        
        // Decode JSON response
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON Decode Error: " . json_last_error_msg());
        }
        
        return $data;
    }
    
    /**
     * Get switch version information
     * @return array Version information
     */
    public function getVersion() {
        $result = $this->runCommand('show version');
        return $result[0] ?? [];
    }
    
    /**
     * Get switch hostname
     * @return string Hostname
     */
    public function getHostname() {
        $result = $this->runCommand('show hostname');
        return $result[0]['hostname'] ?? '';
    }
    
    /**
     * Get VLAN list
     * @return array VLAN list
     */
    public function getVlans() {
        // EOS uses 'show vlan' (singular)
        $result = $this->runCommand('show vlan');
        $payload = $result[0] ?? [];

        $normalized = [];
        $vlans = $payload['vlans'] ?? $payload;

        if (is_array($vlans)) {
            // Case 1: Associative array keyed by VLAN ID
            foreach ($vlans as $key => $value) {
                $possibleId = is_numeric($key) ? (int)$key : null;
                if ($possibleId !== null && $possibleId >= 1 && $possibleId <= 4094) {
                    $normalized[] = [
                        'vlan_id' => $possibleId,
                        'name' => $value['name'] ?? ($value['vlanName'] ?? ($value['nameAlias'] ?? null)),
                        'description' => $value['description'] ?? null
                    ];
                    continue;
                }
                // Case 2: Array of objects; detect ID field inside
                if (is_array($value)) {
                    $id = null;
                    if (isset($value['vlanId']) && is_numeric($value['vlanId'])) {
                        $id = (int)$value['vlanId'];
                    } elseif (isset($value['id']) && is_numeric($value['id'])) {
                        $id = (int)$value['id'];
                    } elseif (isset($value['vlan']) && is_numeric($value['vlan'])) {
                        $id = (int)$value['vlan'];
                    }
                    if ($id !== null && $id >= 1 && $id <= 4094) {
                        $normalized[] = [
                            'vlan_id' => $id,
                            'name' => $value['name'] ?? ($value['vlanName'] ?? ($value['nameAlias'] ?? null)),
                            'description' => $value['description'] ?? null
                        ];
                    }
                }
            }
        }

        return $normalized;
    }
    
    /**
     * Get interface list
     * @return array Interface list
     */
    public function getInterfaces() {
        $result = $this->runCommand('show interfaces');
        return $result[0]['interfaces'] ?? [];
    }

    /**
     * Get interface operational status (Speed, Type, Duplex, VLAN, etc)
     * @return array Interface status map
     */
    public function getInterfacesStatus() {
        $result = $this->runCommand('show interfaces status');
        $payload = $result[0] ?? [];
        
        // The eAPI response for 'show interfaces status' typically has interfaces keyed by name
        // Structure: { "Ethernet1": { "status": "connected", ... }, "Ethernet2": { ... } }
        // Or nested under 'interfaceStatuses' key
        
        if (isset($payload['interfaceStatuses']) && is_array($payload['interfaceStatuses'])) {
            return $payload['interfaceStatuses'];
        }
        if (isset($payload['interfaces']) && is_array($payload['interfaces'])) {
            return $payload['interfaces'];
        }
        if (isset($payload['ports']) && is_array($payload['ports'])) {
            return $payload['ports'];
        }
        
        // If payload itself is an associative array keyed by interface names, return it directly
        // Check if it looks like interface data (keys like "Ethernet1", "Ethernet2", etc.)
        $hasInterfaceKeys = false;
        if (is_array($payload) && count($payload) > 0) {
            $firstKey = array_key_first($payload);
            if (is_string($firstKey) && (stripos($firstKey, 'ethernet') === 0 || stripos($firstKey, 'management') === 0 || stripos($firstKey, 'loopback') === 0)) {
                $hasInterfaceKeys = true;
            }
        }
        
        if ($hasInterfaceKeys) {
            return $payload;
        }
        
        return $payload;
    }

    /**
     * Get switchport information for interfaces (mode, allowed/native VLANs)
     * Attempts multiple keys to be robust across EOS versions.
     * @return array
     */
    public function getInterfacesSwitchport() {
        $result = $this->runCommand('show interfaces switchport');
        $payload = $result[0] ?? [];
        if (isset($payload['switchports']) && is_array($payload['switchports'])) {
            return $payload['switchports'];
        }
        if (isset($payload['interfaces']) && is_array($payload['interfaces'])) {
            return $payload['interfaces'];
        }
        if (isset($payload['ports']) && is_array($payload['ports'])) {
            return $payload['ports'];
        }
        // Fallback to basic interfaces if switchport data unavailable
        return $this->getInterfaces();
    }
    
    /**
     * Get running configuration
     * @return string Running configuration
     */
    public function getRunningConfig() {
        $result = $this->runCommand('show running-config', 'text');
        return $result[0] ?? '';
    }

    /**
     * Save running configuration to startup configuration.
     * @return array
     */
    public function saveRunningConfig()
    {
        // EOS typically requires enable before copy
        $commands = ['enable', 'copy running-config startup-config'];
        return $this->runCommands($commands);
    }

    /**
     * Show current clock.
     * @return string|null
     */
    public function showClock()
    {
        // Attempt 1: text format
        try {
            $res = $this->runCommand('show clock', 'text');
            if (isset($res[0]) && is_string($res[0]) && trim($res[0]) !== '') {
                return $res[0];
            }
        } catch (\Exception $e) {
            // fall through to next attempt
        }
        // Attempt 2: json format with enable
        try {
            $res = $this->runCommands(['enable', 'show clock'], 'json');
            // Result is an array; the second element corresponds to 'show clock'
            if (isset($res[1])) {
                $second = $res[1];
                if (is_array($second)) {
                    // Some eAPI versions return { output: "..." }
                    if (isset($second['output']) && is_string($second['output'])) {
                        return $second['output'];
                    }
                    // Fallback: stringify
                    return json_encode($second);
                } elseif (is_string($second)) {
                    return $second;
                }
            }
        } catch (\Exception $e) {
            // ignore
        }
        return null;
    }

    /**
     * Get configured timezone line from running-config.
     * @return string|null
     */
    public function getClockTimezoneConfig()
    {
        $result = $this->runCommand('show running-config | include ^clock timezone', 'text');
        return isset($result[0]) ? trim($result[0]) : null;
    }

    /**
     * Set clock to specified DateTime (ISO 8601).
     * @param string $isoDatetime
     * @return array
     * @throws Exception
     */
    public function setClock($isoDatetime)
    {
        $dt = new \DateTime($isoDatetime);
        $time = $dt->format('H:i:s');
        $day = (int)$dt->format('j');
        $month = strtoupper($dt->format('M'));
        $year = (int)$dt->format('Y');
        $command = sprintf('clock set %s %d %s %d', $time, $day, $month, $year);
        return $this->runCommands(['enable', $command]);
    }

    /**
     * Set clock timezone.
     * @param string $zone
     * @param string|null $offset
     * @return array
     */
    public function setClockTimezone($zone, $offset = null)
    {
        $zone = trim($zone);
        $offset = $offset !== null ? trim($offset) : null;
        $cmd = 'clock timezone ' . $zone;
        if ($offset !== null && $offset !== '') {
            $cmd .= ' ' . $offset;
        }
        return $this->runConfigSequences([$cmd]);
    }

    /**
     * Get system logs.
     *
     * @param int|null $lines Tail last N lines (null for full output)
     * @return string|null
     */
    public function getLogs($lines = null)
    {
        // Prefer plain text; some EOS versions don't support JSON for 'show logging'
        // Disable pager to avoid truncation
        try {
            $result = $this->runCommands(['enable', 'terminal length 0', 'show logging'], 'text');
            if (is_array($result) && !empty($result)) {
                $last = end($result);
                // Most of the time, the last command holds the log output
                if (is_array($last)) {
                    if (isset($last['output']) && is_string($last['output']) && trim($last['output']) !== '') {
                        return $last['output'];
                    }
                    if (isset($last['messages']) && is_array($last['messages'])) {
                        $linesOut = array_filter(array_map(function ($m) {
                            if (is_string($m)) { return $m; }
                            if (is_array($m) && isset($m['message'])) { return $m['message']; }
                            return null;
                        }, $last['messages']));
                        if (!empty($linesOut)) {
                            return implode("\n", $linesOut);
                        }
                    }
                }
                // Fallback: scan any item for 'output' or string content
                foreach ($result as $item) {
                    if (is_array($item)) {
                        if (isset($item['output']) && is_string($item['output']) && trim($item['output']) !== '') {
                            return $item['output'];
                        }
                        if (isset($item['messages']) && is_array($item['messages'])) {
                            $linesOut = array_filter(array_map(function ($m) {
                                if (is_string($m)) { return $m; }
                                if (is_array($m) && isset($m['message'])) { return $m['message']; }
                                return null;
                            }, $item['messages']));
                            if (!empty($linesOut)) {
                                return implode("\n", $linesOut);
                            }
                        }
                    } elseif (is_string($item) && trim($item) !== '') {
                        return $item;
                    }
                }
            }
        } catch (\Exception $e) {
            // Continue to fallback below
        }

        // Fallback: try without 'terminal length 0'
        try {
            $result = $this->runCommands(['enable', 'show logging'], 'text');
            if (is_array($result) && !empty($result)) {
                $last = end($result);
                if (is_array($last) && isset($last['output']) && is_string($last['output']) && trim($last['output']) !== '') {
                    return $last['output'];
                }
                foreach ($result as $item) {
                    if (is_array($item) && isset($item['output']) && is_string($item['output']) && trim($item['output']) !== '') {
                        return $item['output'];
                    }
                    if (is_string($item) && trim($item) !== '') {
                        return $item;
                    }
                }
            }
        } catch (\Exception $e) {
            // No further fallback
        }

        return null;
    }
    
    /**
     * Test connection to switch
     * @return bool True if connection successful
     */
    public function testConnection() {
        try {
            $this->getVersion();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Apply configuration changes
     * @param array $commands Configuration commands
     * @return array Response data
     */
    public function applyConfig($commands) {
        // Prepend 'configure' command
        array_unshift($commands, 'configure');
        
        return $this->runCommands($commands);
    }
    
    /**
     * Create VLAN
     * @param int $vlanId VLAN ID
     * @param string|null $name VLAN name
     * @return array Response data
     */
    public function createVlan($vlanId, $name = null) {
        // Try multiple config entry sequences to be compatible across EOS/eAPI setups
        $configBody = ["vlan {$vlanId}"];
        if ($name) {
            // Arista VLAN names cannot contain spaces or special characters
            // Sanitize: replace spaces and other invalid chars with underscores
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
            // Trim to 32 chars
            $safeName = substr($safeName, 0, 32);
            
            $configBody[] = "name {$safeName}";
        }
        return $this->runConfigSequences($configBody);
    }
    
    /**
     * Delete VLAN
     * @param int $vlanId VLAN ID
     * @return array Response data
     */
    public function deleteVlan($vlanId) {
        return $this->runConfigSequences(["no vlan {$vlanId}"]);
    }
    
    /**
     * Configure interface
     * @param string $interface Interface name
     * @param array $config Configuration options
     * @return array Response data
     */
    public function configureInterface($interface, $config) {
        $commands = ["interface {$interface}"];

        if (isset($config['mode'])) {
            if ($config['mode'] === 'access' && isset($config['vlan'])) {
                $commands[] = 'switchport mode access';
                $commands[] = "switchport access vlan {$config['vlan']}";
            } elseif ($config['mode'] === 'trunk' && isset($config['vlans'])) {
                $commands[] = 'switchport mode trunk';
                // Set native (untagged) VLAN first if provided
                if (isset($config['native_vlan']) && is_numeric($config['native_vlan'])) {
                    $commands[] = "switchport trunk native vlan {$config['native_vlan']}";
                }
                // Then set allowed VLANs (tagged). Skip if empty.
                $vlans = is_array($config['vlans']) ? implode(',', $config['vlans']) : (string)$config['vlans'];
                if (trim($vlans) !== '') {
                    $commands[] = "switchport trunk allowed vlan {$vlans}";
                }
            }
        }

        // Apply admin state if requested (shutdown/no shutdown)
        if (isset($config['admin_state'])) {
            $state = strtolower((string)$config['admin_state']);
            if ($state === 'down') {
                $commands[] = 'shutdown';
            } elseif ($state === 'up') {
                $commands[] = 'no shutdown';
            }
        }
        
        if (isset($config['description'])) {
            $desc = $config['description'];
            $commands[] = "description {$desc}";
        }

        return $this->runConfigSequences($commands);
    }

    /**
     * Get port channels (LAG/LACP)
     * @return array Port channel list
     */
    public function getPortChannels() {
        $result = $this->runCommand('show port-channel');
        $payload = $result[0] ?? [];
        
        // Normalize response structure across EOS versions
        $portChannels = [];
        if (isset($payload['portChannels']) && is_array($payload['portChannels'])) {
            foreach ($payload['portChannels'] as $name => $data) {
                $portChannels[] = [
                    'name' => $name,
                    'interfaces' => $data['interfaces'] ?? [],
                    'protocol' => $data['protocol'] ?? null,
                    'flags' => $data['flags'] ?? null
                ];
            }
        } elseif (isset($payload['portChannels']) && is_array($payload['portChannels'])) {
            $portChannels = array_values($payload['portChannels']);
        }
        
        return $portChannels;
    }

    /**
     * Get port channel detail including members
     * @param string $portChannelName Port channel name (e.g., Port-Channel1)
     * @return array Port channel details
     */
    public function getPortChannelDetail($portChannelName) {
        $result = $this->runCommand("show port-channel {$portChannelName}");
        return $result[0] ?? [];
    }

    /**
     * Create and configure a port channel
     * @param string $portChannelName Port channel name (e.g., Port-Channel1)
     * @param array $config Configuration options
     *   - mode: 'access', 'trunk', or 'routed'
     *   - vlan: VLAN ID for access mode
     *   - trunk_vlans: Comma-separated VLAN list for trunk mode
     *   - native_vlan: Native VLAN for trunk mode
     *   - lacp_mode: 'active', 'passive', or 'on'
     *   - description: Description string
     * @return array Response data
     */
    public function createPortChannel($portChannelName, $config = []) {
        $commands = ["interface {$portChannelName}"];
        
        // Configure switchport mode if specified
        if (isset($config['mode'])) {
            if ($config['mode'] === 'access' && isset($config['vlan'])) {
                $commands[] = 'switchport mode access';
                $commands[] = "switchport access vlan {$config['vlan']}";
            } elseif ($config['mode'] === 'trunk') {
                $commands[] = 'switchport mode trunk';
                if (isset($config['native_vlan'])) {
                    $commands[] = "switchport trunk native vlan {$config['native_vlan']}";
                }
                if (isset($config['trunk_vlans']) && !empty($config['trunk_vlans'])) {
                    $vlans = is_array($config['trunk_vlans']) ? implode(',', $config['trunk_vlans']) : (string)$config['trunk_vlans'];
                    $commands[] = "switchport trunk allowed vlan {$vlans}";
                }
            } elseif ($config['mode'] === 'routed') {
                $commands[] = 'no switchport';
            }
        }
        
        // Note: LACP mode is configured on member interfaces, not the port channel itself
        // The port channel interface just needs to exist and have switchport settings
        
        if (isset($config['description'])) {
            $commands[] = "description {$config['description']}";
        }
        
        if (isset($config['admin_state'])) {
            $state = strtolower((string)$config['admin_state']);
            if ($state === 'down') {
                $commands[] = 'shutdown';
            } elseif ($state === 'up') {
                $commands[] = 'no shutdown';
            }
        }
        
        return $this->runConfigSequences($commands);
    }

    /**
     * Configure existing port channel
     * @param string $portChannelName Port channel name
     * @param array $config Configuration options (same as createPortChannel)
     * @return array Response data
     */
    public function configurePortChannel($portChannelName, $config) {
        return $this->createPortChannel($portChannelName, $config);
    }

    /**
     * Add interface as member to port channel
     * @param string $interface Interface name (e.g., Ethernet1)
     * @param string $portChannelName Port channel name (e.g., Port-Channel1)
     * @param string $lacpMode LACP mode: 'active', 'passive', or 'on'
     * @return array Response data
     */
    public function addPortChannelMember($interface, $portChannelName, $lacpMode = 'active') {
        $channelNumber = $this->extractPortChannelNumber($portChannelName);
        $commands = ["interface {$interface}"];
        
        if ($lacpMode === 'active') {
            $commands[] = "channel-group {$channelNumber} mode active";
        } elseif ($lacpMode === 'passive') {
            $commands[] = "channel-group {$channelNumber} mode passive";
        } elseif ($lacpMode === 'on') {
            $commands[] = "channel-group {$channelNumber} mode on";
        } else {
            $commands[] = "channel-group {$channelNumber} mode active";
        }
        
        return $this->runConfigSequences($commands);
    }

    /**
     * Remove interface from port channel
     * @param string $interface Interface name
     * @return array Response data
     */
    public function removePortChannelMember($interface) {
        $commands = ["interface {$interface}", "no channel-group"];
        return $this->runConfigSequences($commands);
    }

    /**
     * Delete port channel
     * @param string $portChannelName Port channel name
     * @return array Response data
     */
    public function deletePortChannel($portChannelName) {
        $commands = ["no interface {$portChannelName}"];
        return $this->runConfigSequences($commands);
    }

    /**
     * Extract port channel number from name (e.g., "Port-Channel1" -> "1")
     * @param string $portChannelName Port channel name
     * @return string Port channel number
     */
    private function extractPortChannelNumber($portChannelName) {
        if (preg_match('/(\d+)$/', $portChannelName, $matches)) {
            return $matches[1];
        }
        // Fallback: extract any number
        if (preg_match('/(\d+)/', $portChannelName, $matches)) {
            return $matches[1];
        }
        throw new Exception("Unable to extract port channel number from: {$portChannelName}");
    }

    /**
     * Run a set of configuration commands.
     * 
     * Arista eAPI requires:
     * - 'enable' to enter privileged EXEC mode
     * - 'configure terminal' or 'configure' to enter config mode
     * - Then the actual configuration commands
     *
     * @param array $configCommands Commands to run inside config mode
     * @return array
     * @throws Exception if all sequences fail
     */
    private function runConfigSequences(array $configCommands) {
        // Try different command sequences
        // The issue might be that we need enable before configure
        $sequences = [
            // Most reliable: enable + configure + commands
            array_merge(['enable', 'configure'], $configCommands),
            // Alternative: just configure + commands
            array_merge(['configure'], $configCommands),
            // Try with 'configure terminal'
            array_merge(['enable', 'configure terminal'], $configCommands),
            array_merge(['configure terminal'], $configCommands),
        ];
        
        $lastException = null;
        foreach ($sequences as $index => $commandSequence) {
            try {
                return $this->runCommands($commandSequence);
            } catch (Exception $e) {
                $msg = strtolower($e->getMessage());
                
                // Try next on recoverable errors
                if (strpos($msg, 'invalid command') !== false ||
                    strpos($msg, 'failed') !== false ||
                    strpos($msg, 'permission') !== false) {
                    $lastException = $e;
                    continue;
                }
                
                // Don't try more sequences on other errors
                throw $e;
            }
        }
        
        // All sequences failed
        if ($lastException) {
            throw $lastException;
        }
        throw new Exception('Failed to enter configuration mode');
    }

    /**
     * Get port channel load balance statistics
     * @param string|null $portChannelName Optional port channel name (e.g., Port-Channel1). If null, returns all port channels
     * @return array Load balance statistics
     */
    public function getPortChannelLoadBalance($portChannelName = null) {
        // Try specific command first if name provided
        if ($portChannelName) {
            try {
                $result = $this->runCommand("show port-channel load-balance {$portChannelName}");
                return $result[0] ?? [];
            } catch (Exception $e) {
                // Fallback to global command if specific one fails
            }
        }

        // Try global command
        try {
            $result = $this->runCommand('show port-channel load-balance');
            return $result[0] ?? [];
        } catch (Exception $e) {
            // Fallback to 'show port-channel traffic' which shows distribution
            try {
                $result = $this->runCommand('show port-channel traffic');
                return $result[0] ?? [];
            } catch (Exception $e2) {
                 // Last resort: show details which might contain some info
                 try {
                    if ($portChannelName) {
                        return $this->getPortChannelDetail($portChannelName);
                    }
                 } catch (Exception $e3) {
                     // Ignore
                 }
                 throw $e; // Throw original error if all else fails
            }
        }
    }

    /**
     * Get system environment status (temperature, fans, power)
     * @return array Environment status
     */
    public function getEnvironment() {
        // Try 'show system environment all' first
        try {
            $result = $this->runCommand('show system environment all');
            return $result[0] ?? [];
        } catch (Exception $e) {
            // Fallback 1: 'show environment all'
            try {
                $result = $this->runCommand('show environment all');
                return $result[0] ?? [];
            } catch (Exception $e2) {
                // Fallback 2: Fetch components separately and combine
                $envData = [];
                try {
                    $power = $this->runCommand('show environment power');
                    if (isset($power[0])) $envData['powerSupplySlots'] = $power[0]['powerSupplySlots'] ?? $power[0]['powerSupplies'] ?? [];
                } catch (Exception $ep) {}

                try {
                    $cooling = $this->runCommand('show environment cooling');
                    if (isset($cooling[0])) $envData['fanTraySlots'] = $cooling[0]['fanTraySlots'] ?? $cooling[0]['fans'] ?? [];
                } catch (Exception $ec) {}

                try {
                    $temp = $this->runCommand('show environment temperature');
                    if (isset($temp[0])) $envData['tempSensors'] = $temp[0]['tempSensors'] ?? $temp[0]['temperature'] ?? [];
                } catch (Exception $et) {}

                if (!empty($envData)) {
                    // Infer system status
                    $envData['systemStatus'] = 'normal'; // Default
                    // Check if any component is bad
                    // (Simplified check - real logic would verify each component)
                    return $envData;
                }
                
                return [];
            }
        }
    }

    /**
     * Get locator LED status
     * @return array Locator LED status
     */
    public function getLocatorLed() {
        try {
            $result = $this->runCommand('show locator-led');
            return $result[0] ?? [];
        } catch (Exception $e) {
            try {
                // Fallback for some platforms
                $result = $this->runCommand('show chassis locator-led');
                return $result[0] ?? [];
            } catch (Exception $e2) {
                return [];
            }
        }
    }

    /**
     * Get MAC address table
     * @param string|null $vlan Optional VLAN ID to filter
     * @param string|null $interface Optional interface to filter
     * @return array MAC address table entries
     */
    public function getMacAddressTable($vlan = null, $interface = null) {
        $command = 'show mac address-table';
        
        // Add filters if provided
        if ($vlan) {
            $command .= " vlan {$vlan}";
        } elseif ($interface) {
            $command .= " interface {$interface}";
        }
        
        try {
            $result = $this->runCommand($command);
            return $result[0] ?? [];
        } catch (Exception $e) {
            // Try alternative command format
            try {
                $command = 'show mac address-table dynamic';
                if ($vlan) {
                    $command .= " vlan {$vlan}";
                }
                $result = $this->runCommand($command);
                return $result[0] ?? [];
            } catch (Exception $e2) {
                return [];
            }
        }
    }

    /**
     * Get transceiver details for interfaces
     * @param string|null $interface Optional interface name to get specific transceiver
     * @return array Transceiver details
     */
    public function getInterfacesTransceiver($interface = null) {
        // Always get all interfaces first, then filter if needed
        // Some switches return all interfaces even when a specific one is requested
        $command = 'show interfaces transceiver';
        
        try {
            // Try JSON format first (default)
            $result = $this->runCommand($command);
            $output = $result[0] ?? [];
            
            // Check for various eAPI response structures
            $transceivers = null;
            
            // Check if output contains text format (even if JSON was requested)
            if (isset($output['output']) && is_string($output['output'])) {
                if (preg_match('/Temp.*Voltage.*Current.*Tx Power.*Rx Power/i', $output['output']) ||
                    preg_match('/^Port\s+\(Celsius\)/i', $output['output'])) {
                    // This is text format, parse it
                    $parsed = $this->parseTransceiverText($output['output'], $interface);
                    return $parsed;
                }
            }
            
            // Structure 1: Direct interfaces object
            if (isset($output['interfaces']) && is_array($output['interfaces'])) {
                $transceivers = $output['interfaces'];
            }
            // Structure 2: Top-level object keyed by interface name
            elseif (isset($output['output']) && is_array($output['output'])) {
                $transceivers = $output['output'];
            }
            // Structure 3: Already an array/object
            elseif (is_array($output) && !empty($output)) {
                // Check if keys look like interface names
                $keys = array_keys($output);
                $hasInterfaceKeys = false;
                foreach ($keys as $key) {
                    if (preg_match('/^(Ethernet|Management|Port-Channel)/i', $key)) {
                        $hasInterfaceKeys = true;
                        break;
                    }
                }
                if ($hasInterfaceKeys) {
                    $transceivers = $output;
                } else {
                    // Try to find nested structure
                    foreach ($output as $key => $value) {
                        if (is_array($value) && isset($value['interfaces'])) {
                            $transceivers = $value['interfaces'];
                            break;
                        }
                    }
                }
            }
            
            // If we found JSON structure, filter by interface if needed
            if ($transceivers && is_array($transceivers)) {
                if ($interface) {
                    // Look for the specific interface
                    $ifaceLower = strtolower($interface);
                    foreach ($transceivers as $key => $value) {
                        if (strtolower($key) === $ifaceLower || 
                            strtolower($key) === str_replace(' ', '', $ifaceLower)) {
                            return [$key => $value];
                        }
                    }
                    // If not found, return empty
                    return [];
                }
                return $transceivers;
            }
            
            // Fallback: Always try text format parsing (eAPI may return text even when JSON is requested)
            try {
                $result = $this->runCommand($command, 'text');
                $output = $result[0] ?? [];
                if (isset($output['output']) && is_string($output['output'])) {
                    $parsed = $this->parseTransceiverText($output['output'], $interface);
                    if (!empty($parsed)) {
                        return $parsed;
                    }
                }
            } catch (Exception $e2) {
                // Silently fail and return empty array
            }
            
            return [];
        } catch (Exception $e) {
            // Try text format as fallback
            try {
                $result = $this->runCommand($command, 'text');
                $output = $result[0] ?? [];
                if (isset($output['output']) && is_string($output['output'])) {
                    return $this->parseTransceiverText($output['output'], $interface);
                }
            } catch (Exception $e2) {
                // Silently fail
            }
            return [];
        }
    }
    
    /**
     * Parse transceiver text output
     * @param string $text Raw text output from show interfaces transceiver
     * @param string|null $interface Optional interface name filter
     * @return array Parsed transceiver data
     */
    private function parseTransceiverText($text, $interface = null) {
        $result = [];
        $lines = explode("\n", $text);
        
        // Look for table header to determine format
        $inTable = false;
        $headerLine = -1;
        
        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Check for table header (contains "Temp", "Voltage", "Tx Power", etc.)
            if (preg_match('/Temp.*Voltage.*Current.*Tx Power.*Rx Power/i', $line)) {
                $inTable = true;
                $headerLine = $lineNum;
                continue;
            }
            
            // Check for separator line (dashes)
            if ($inTable && preg_match('/^-+$/', $line)) {
                continue;
            }
            
            // Skip header rows like "Port      (Celsius)  (Volts)   (mA)      (dBm)     (dBm)     Last Update"
            if ($inTable && preg_match('/^Port\s+\(Celsius\)/i', $line)) {
                continue;
            }
            
            // Parse table rows - format: "Et2        46.71      3.31      33.48    -2.97     -3.86     0:00:00 ago"
            // More flexible regex to match interface name followed by whitespace-separated values
            if ($inTable && preg_match('/^(Et\d+|Ethernet\d+(?:\/\d+)*|Management\d+|Port-Channel\d+)\s+([^\n]+)$/i', $line, $matches)) {
                $ifaceName = trim($matches[1]);
                $ifaceName = preg_replace('/^Et(\d+)$/i', 'Ethernet$1', $ifaceName); // Convert Et2 to Ethernet2
                
                // Skip if interface filter is set and doesn't match
                if ($interface) {
                    $ifaceMatch = false;
                    $requestedLower = strtolower(trim($interface));
                    $foundLower = strtolower(trim($ifaceName));
                    
                    // Normalize both: "ethernet2" -> "et2", "et2" -> "et2"
                    $requestedNormalized = preg_replace('/^ethernet(\d+)$/i', 'et$1', $requestedLower);
                    $foundNormalized = preg_replace('/^ethernet(\d+)$/i', 'et$1', $foundLower);
                    
                    // Try various matching strategies
                    if ($foundLower === $requestedLower ||
                        $foundNormalized === $requestedNormalized ||
                        str_replace([' ', '-', '_'], '', $foundLower) === str_replace([' ', '-', '_'], '', $requestedLower) ||
                        strpos($foundLower, $requestedLower) !== false ||
                        strpos($requestedLower, $foundLower) !== false ||
                        strpos($foundNormalized, $requestedNormalized) !== false ||
                        strpos($requestedNormalized, $foundNormalized) !== false) {
                        $ifaceMatch = true;
                    }
                    if (!$ifaceMatch) {
                        continue;
                    }
                }
                
                // Parse the data values - split by multiple whitespace
                $dataStr = isset($matches[2]) ? trim($matches[2]) : '';
                // Split on whitespace (handles multiple spaces/tabs)
                $values = preg_split('/\s+/', $dataStr);
                
                // Filter out timestamp values (like "0:00:00 ago")
                $values = array_filter($values, function($val) {
                    return !preg_match('/^\d+:\d+:\d+/', trim($val));
                });
                $values = array_values($values); // Re-index array
                
                // Map values to fields (Temp, Voltage, Current, Tx Power, Rx Power)
                $transceiverData = [];
                
                if (isset($values[0])) {
                    $temp = trim($values[0]);
                    if (strtoupper($temp) !== 'N/A' && $temp !== '-' && is_numeric($temp)) {
                        $transceiverData['temperature'] = floatval($temp);
                    }
                }
                
                if (isset($values[1])) {
                    $voltage = trim($values[1]);
                    if (strtoupper($voltage) !== 'N/A' && $voltage !== '-' && is_numeric($voltage)) {
                        $transceiverData['voltage'] = floatval($voltage);
                    }
                }
                
                if (isset($values[2])) {
                    $current = trim($values[2]);
                    if (strtoupper($current) !== 'N/A' && $current !== '-' && is_numeric($current)) {
                        $transceiverData['biasCurrent'] = floatval($current);
                    }
                }
                
                if (isset($values[3])) {
                    $txPower = trim($values[3]);
                    if (strtoupper($txPower) !== 'N/A' && $txPower !== '-' && (is_numeric($txPower) || preg_match('/^-?\d+\.?\d*$/', $txPower))) {
                        $transceiverData['txPower'] = floatval($txPower);
                    }
                }
                
                if (isset($values[4])) {
                    $rxPower = trim($values[4]);
                    if (strtoupper($rxPower) !== 'N/A' && $rxPower !== '-' && (is_numeric($rxPower) || preg_match('/^-?\d+\.?\d*$/', $rxPower))) {
                        $transceiverData['rxPower'] = floatval($rxPower);
                    }
                }
                
                // Only add if we have at least one valid value (not all N/A)
                if (!empty($transceiverData)) {
                    $result[$ifaceName] = $transceiverData;
                }
            }
            // Alternative: Parse key-value format
            else if (preg_match('/^([Ee]thernet\d+(?:\/\d+)*|Management\d+|Port-Channel\d+)/', $line, $matches)) {
                $currentInterface = $matches[1];
                $currentData = [];
            } else if (isset($currentInterface)) {
                // Parse key-value pairs
                if (preg_match('/([^:]+):\s*(.+)/', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    if (strtoupper($value) !== 'N/A' && $value !== '-') {
                        $currentData[strtolower(str_replace([' ', '-'], '_', $key))] = $value;
                    }
                }
            }
        }
        
        // Save last interface from key-value format
        if (isset($currentInterface) && !empty($currentData)) {
            if (!$interface || strcasecmp($currentInterface, $interface) === 0) {
                $result[$currentInterface] = $currentData;
            }
        }
        
        return $result;
    }
    
    /**
     * Get Management interface IP address and gateway
     * @return array Management interface configuration
     */
    public function getManagementInterface() {
        $result = [];
        
        try {
            // Get Management1 interface IP configuration
            $mgmtIpResult = $this->runCommand('show ip interface Management1');
            $mgmtIp = $mgmtIpResult[0] ?? [];
            
            // Get default gateway - try multiple commands
            $gatewayAddress = null;
            
            // Try show ip route 0.0.0.0/0
            try {
                $gatewayResult = $this->runCommand('show ip route 0.0.0.0/0');
                $gateway = $gatewayResult[0] ?? [];
                
                if (isset($gateway['vrfs']['default']['routes']['0.0.0.0/0'][0])) {
                    $route = $gateway['vrfs']['default']['routes']['0.0.0.0/0'][0];
                    $gatewayAddress = $route['via'] ?? $route['nextHop'] ?? null;
                } elseif (isset($gateway['routes']['0.0.0.0/0'][0])) {
                    $route = $gateway['routes']['0.0.0.0/0'][0];
                    $gatewayAddress = $route['via'] ?? $route['nextHop'] ?? null;
                }
            } catch (Exception $e) {
                // Ignore
            }
            
            // Try show ip route default or show ip default-gateway
            if (!$gatewayAddress) {
                try {
                    $defRoute = $this->runCommand('show ip default-gateway');
                    $def = $defRoute[0] ?? [];
                    $gatewayAddress = $def['defaultGateway'] ?? $def['gateway'] ?? null;
                } catch (Exception $e) {
                    // Ignore
                }
            }
            
            // Try text format
            if (!$gatewayAddress) {
                try {
                    $textResult = $this->runCommand('show ip route', 'text');
                    $text = $textResult[0]['output'] ?? '';
                    if (preg_match('/0\.0\.0\.0\/0.*via\s+([0-9.]+)/', $text, $matches)) {
                        $gatewayAddress = $matches[1];
                    }
                } catch (Exception $e) {
                    // Ignore
                }
            }
            
            // Extract IP address and mask from Management1 interface
            $ipAddress = null;
            $subnetMask = null;
            
            if (isset($mgmtIp['interfaces']['Management1']['interfaceAddress']['primaryIp'])) {
                $primaryIp = $mgmtIp['interfaces']['Management1']['interfaceAddress']['primaryIp'];
                $ipAddress = $primaryIp['address'] ?? null;
                $subnetMask = $primaryIp['maskLen'] ?? null;
            } elseif (isset($mgmtIp['Management1']['interfaceAddress']['primaryIp'])) {
                $primaryIp = $mgmtIp['Management1']['interfaceAddress']['primaryIp'];
                $ipAddress = $primaryIp['address'] ?? null;
                $subnetMask = $primaryIp['maskLen'] ?? null;
            }
            
            // Try show ip interface brief as fallback
            if (!$ipAddress) {
                try {
                    $briefResult = $this->runCommand('show ip interface brief');
                    $brief = $briefResult[0] ?? [];
                    if (isset($brief['interfaces']['Management1'])) {
                        $mgmt = $brief['interfaces']['Management1'];
                        $ipAddress = $mgmt['ipAddress'] ?? $mgmt['address'] ?? null;
                        if (isset($mgmt['maskLen'])) {
                            $subnetMask = $mgmt['maskLen'];
                        }
                    }
                } catch (Exception $e) {
                    // Ignore
                }
            }
            
            return [
                'ip_address' => $ipAddress,
                'subnet_mask' => $subnetMask,
                'gateway' => $gatewayAddress
            ];
        } catch (Exception $e) {
            error_log("Failed to get management interface: " . $e->getMessage());
            return [
                'ip_address' => null,
                'subnet_mask' => null,
                'gateway' => null
            ];
        }
    }
    
    /**
     * Configure Management interface IP address and gateway
     * @param string $ipAddress IP address with CIDR notation (e.g., "192.168.1.100/24")
     * @param string|null $gateway Default gateway IP address
     * @return bool True if successful
     */
    public function configureManagementInterface($ipAddress, $gateway = null) {
        try {
            $commands = [];
            
            // Validate IP address format (should include CIDR notation)
            if (!preg_match('/^[\d.]+(?:\/\d+)?$/', $ipAddress)) {
                throw new Exception("Invalid IP address format. Expected format: IP/CIDR (e.g., 192.168.1.100/24)");
            }
            
            // Build configuration commands
            $commands[] = 'interface Management1';
            
            // Remove existing IP if any, then set new one
            // Note: In Arista, we might need to remove old IP first
            $commands[] = "ip address {$ipAddress}";
            
            // Configure gateway if provided
            if ($gateway && filter_var($gateway, FILTER_VALIDATE_IP)) {
                $commands[] = "ip default-gateway {$gateway}";
            }
            
            // Use applyConfig to execute configuration commands
            $this->applyConfig($commands);
            
            return true;
        } catch (Exception $e) {
            error_log("Failed to configure management interface: " . $e->getMessage());
            throw $e;
        }
    }
}

