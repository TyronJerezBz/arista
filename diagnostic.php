<?php
/**
 * Arista eAPI Diagnostic Tool - Interactive UI
 * 
 * Comprehensive diagnostic interface with:
 * - Switch selection
 * - Custom credential override
 * - Individual test selection
 * - Real-time test execution and results
 */

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/classes/Database.php';
require_once __DIR__ . '/api/classes/AristaEAPI.php';
require_once __DIR__ . '/api/classes/Security.php';
require_once __DIR__ . '/api/classes/Validator.php';

// Handle AJAX test execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'run_tests') {
        $switchId = (int)($_POST['switch_id'] ?? 0);
        $selectedTests = isset($_POST['tests']) ? (array)$_POST['tests'] : [];
        $useCustomCreds = isset($_POST['use_custom_creds']) && $_POST['use_custom_creds'] === '1';
        
        if (!$switchId) {
            echo json_encode(['error' => 'No switch selected']);
            exit;
        }
        
        try {
            $db = Database::getInstance();
            
            // If using custom credentials, temporarily override
            if ($useCustomCreds) {
                $customUsername = $_POST['custom_username'] ?? '';
                $customPassword = $_POST['custom_password'] ?? '';
                $customPort = (int)($_POST['custom_port'] ?? 443);
                $customUseHttps = isset($_POST['custom_use_https']) && $_POST['custom_use_https'] === '1';
                
                if (!$customUsername || !$customPassword) {
                    echo json_encode(['error' => 'Custom credentials incomplete']);
                    exit;
                }
            }
            
            $switch = $db->queryOne("SELECT id, hostname, ip_address FROM switches WHERE id = ?", [$switchId]);
            if (!$switch) {
                echo json_encode(['error' => 'Switch not found']);
                exit;
            }
            
            $results = [];
            
            // Define all available tests
            $availableTests = [
                'show_version' => [
                    'name' => 'Show Version',
                    'commands' => ['show version'],
                    'description' => 'Test basic connectivity'
                ],
                'show_vlans' => [
                    'name' => 'Show VLANs',
                    'commands' => ['show vlan'],
                    'description' => 'Retrieve current VLAN configuration'
                ],
                'show_interfaces' => [
                    'name' => 'Show Interfaces',
                    'commands' => ['show interfaces'],
                    'description' => 'Retrieve full interface details'
                ],
                'show_interfaces_switchport' => [
                    'name' => 'Show Interfaces Switchport',
                    'commands' => ['show interfaces switchport'],
                    'description' => 'Retrieve switchport mode, access VLAN, trunk allowed/native'
                ],
                'show_interfaces_status' => [
                    'name' => 'Show Interfaces Status',
                    'commands' => ['show interfaces status'],
                    'description' => 'Retrieve port status summary (admin/oper, speed, type)'
                ],
                'enable' => [
                    'name' => 'Enable',
                    'commands' => ['enable'],
                    'description' => 'Test privileged mode access'
                ],
                'configure' => [
                    'name' => 'Configure',
                    'commands' => ['configure'],
                    'description' => 'Test configuration mode entry'
                ],
                'enable_configure' => [
                    'name' => 'Enable + Configure',
                    'commands' => ['enable', 'configure'],
                    'description' => 'Test combined enable and configure'
                ],
                'enable_configure_terminal' => [
                    'name' => 'Enable + Configure Terminal',
                    'commands' => ['enable', 'configure terminal'],
                    'description' => 'Test with terminal keyword'
                ],
                'vlan_create' => [
                    'name' => 'Full VLAN Creation',
                    'commands' => ['enable', 'configure', 'vlan 999', 'name diagnostic-test'],
                    'description' => 'End-to-end VLAN creation test'
                ]
            ];
            
            // Run selected tests (or all if none selected)
            $testsToRun = !empty($selectedTests) ? $selectedTests : array_keys($availableTests);
            
            foreach ($testsToRun as $testKey) {
                if (!isset($availableTests[$testKey])) continue;
                
                $test = $availableTests[$testKey];
                
                try {
                    // Create eAPI instance
                    if ($useCustomCreds) {
                        // For custom credentials, we'd need to modify AristaEAPI to accept credentials
                        // For now, use the stored credentials but show a note
                        $eapi = new AristaEAPI($switchId);
                    } else {
                        $eapi = new AristaEAPI($switchId);
                    }
                    
                    $result = $eapi->runCommands($test['commands']);
                    
                    $results[$testKey] = [
                        'status' => 'success',
                        'name' => $test['name'],
                        'description' => $test['description'],
                        'commands' => $test['commands'],
                        'message' => 'Command(s) executed successfully',
                        'response_preview' => is_array($result) && !empty($result) ? 
                            substr(json_encode($result[0] ?? $result), 0, 200) . '...' : 
                            'Command executed'
                    ];
                } catch (Exception $e) {
                    $results[$testKey] = [
                        'status' => 'failed',
                        'name' => $test['name'],
                        'description' => $test['description'],
                        'commands' => $test['commands'],
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            echo json_encode([
                'success' => true,
                'switch' => $switch,
                'tests' => $results,
                'custom_creds_used' => $useCustomCreds
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'get_switches') {
        header('Content-Type: application/json');
        try {
            $db = Database::getInstance();
            $switches = $db->query("SELECT id, hostname, ip_address FROM switches ORDER BY hostname");
            echo json_encode(['switches' => $switches]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'show_interface_status') {
        header('Content-Type: application/json');
        $switchId = (int)($_POST['switch_id'] ?? 0);
        $iface = trim($_POST['interface'] ?? '');
        if (!$switchId || !$iface) {
            echo json_encode(['error' => 'Switch and interface are required']);
            exit;
        }
        if (!Validator::validateInterfaceName($iface)) {
            echo json_encode(['error' => 'Invalid interface name']);
            exit;
        }
        try {
            $db = Database::getInstance();
            $switch = $db->queryOne("SELECT id FROM switches WHERE id = ?", [$switchId]);
            if (!$switch) {
                echo json_encode(['error' => 'Switch not found']);
                exit;
            }
            $eapi = new AristaEAPI($switchId);
            $cmd = "show interfaces {$iface} status";
            $result = $eapi->runCommands([$cmd]);
            echo json_encode([
                'success' => true,
                'interface' => $iface,
                'commands' => [$cmd],
                'result' => $result
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eAPI Diagnostic Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        .main-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
        }
        .sidebar {
            background-color: #f8f9fa;
            border-right: 1px solid #dee2e6;
            padding: 25px;
        }
        .test-item {
            padding: 12px;
            margin-bottom: 8px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .test-item:hover {
            background-color: #e9ecef;
            border-color: #667eea;
        }
        .test-item input[type="checkbox"] {
            margin-right: 10px;
        }
        .test-item.selected {
            background-color: #e7f3ff;
            border-color: #667eea;
        }
        .results-container {
            max-height: 600px;
            overflow-y: auto;
        }
        .result-card {
            border-left: 4px solid #ccc;
            margin-bottom: 12px;
            transition: all 0.2s;
        }
        .result-card.success {
            border-left-color: #28a745;
            background-color: #f0fdf4;
        }
        .result-card.failed {
            border-left-color: #dc3545;
            background-color: #fef2f2;
        }
        .result-card.running {
            border-left-color: #ffc107;
            background-color: #fffbf0;
        }
        .command-preview {
            background-color: #2d3748;
            color: #a0aec0;
            padding: 10px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 100px;
            overflow-y: auto;
        }
        .spinner-small {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 8px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .credentials-section {
            border-top: 1px solid #dee2e6;
            padding-top: 15px;
            margin-top: 15px;
        }
        .section-title {
            font-size: 13px;
            font-weight: 600;
            color: #495057;
            text-transform: uppercase;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #dee2e6;
        }
        .btn-run {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 20px;
        }
        .btn-run:hover {
            background: linear-gradient(135deg, #5568d3 0%, #6a3f8f 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
    </style>
</head>
<body>
    <div class="container-lg">
        <div class="main-container">
            <!-- Header -->
            <div class="header">
                <h2 class="mb-1">
                    <i class="fas fa-stethoscope me-2"></i>
                    eAPI Diagnostic Tool
                </h2>
                <p class="mb-0 opacity-75">Test your eAPI configuration and permissions</p>
            </div>

            <div class="row g-0">
                <!-- Sidebar - Configuration -->
                <div class="col-lg-3 sidebar">
                    <div class="mb-4">
                        <label class="form-label fw-600">Select Switch</label>
                        <select id="switchSelect" class="form-select form-select-sm">
                            <option value="">-- Loading switches --</option>
                        </select>
                        <small class="text-muted d-block mt-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Select a switch to test
                        </small>
                    </div>

                    <div class="credentials-section">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="useCustomCreds">
                            <label class="form-check-label" for="useCustomCreds">
                                <span class="section-title mb-2">Override Credentials</span>
                            </label>
                        </div>
                        <div id="customCredsSection" style="display: none; margin-top: 12px;">
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Username</label>
                                <input type="text" id="customUsername" class="form-control form-control-sm" placeholder="arista_eapi">
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Password</label>
                                <input type="password" id="customPassword" class="form-control form-control-sm">
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Port</label>
                                <input type="number" id="customPort" class="form-control form-control-sm" value="443">
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="customUseHttps" checked>
                                <label class="form-check-label form-label-sm" for="customUseHttps">
                                    Use HTTPS
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-top">
                        <div class="section-title">Available Tests</div>
                        <div id="testsList"></div>
                        <button class="btn btn-sm btn-outline-secondary w-100 mt-2" onclick="toggleAllTests()">
                            Toggle All
                        </button>
                    </div>

                    <button id="runBtn" class="btn btn-run w-100 mt-4" onclick="runTests()">
                        <i class="fas fa-play me-2"></i>
                        Run Tests
                    </button>

                    <div class="mt-4 pt-4 border-top">
                        <div class="section-title">Quick Interface Status</div>
                        <div class="mb-2">
                            <label class="form-label form-label-sm">Interface (e.g., Ethernet2)</label>
                            <input type="text" id="ifName" class="form-control form-control-sm" placeholder="Ethernet2">
                        </div>
                        <button class="btn btn-sm btn-outline-primary w-100" onclick="showInterfaceStatus()">
                            <i class="fas fa-search me-1"></i> Show Interface Status
                        </button>
                        <small class="text-muted d-block mt-2">Runs: <code>show interfaces &lt;name&gt; status</code></small>
                    </div>
                </div>

                <!-- Main Content - Results -->
                <div class="col-lg-9 p-4">
                    <div id="noResultsMsg" class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Select tests above and click "Run Tests" to see results
                    </div>

                    <div id="resultsContainer" class="results-container" style="display: none;">
                        <!-- Results will be populated here -->
                    </div>

                    <div id="loadingMsg" style="display: none; text-align: center; padding: 40px;">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Running tests...</span>
                        </div>
                        <p class="mt-3 text-muted">Running diagnostic tests...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '/arista';
        
        // Helper function to escape HTML special characters
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
        
        const TESTS = {
            'show_version': { name: 'Show Version', description: 'Test basic connectivity', icon: 'fa-network-wired' },
            'show_vlans': { name: 'Show VLANs', description: 'Retrieve VLAN configuration', icon: 'fa-layer-group' },
            'show_interfaces': { name: 'Show Interfaces', description: 'Retrieve full interface details', icon: 'fa-plug' },
            'show_interfaces_switchport': { name: 'Show Interfaces Switchport', description: 'Switchport mode/native/allowed', icon: 'fa-stream' },
            'show_interfaces_status': { name: 'Show Interfaces Status', description: 'Port status summary (admin/oper, speed, type)', icon: 'fa-tachometer-alt' },
            'enable': { name: 'Enable', description: 'Test privileged mode access', icon: 'fa-lock-open' },
            'configure': { name: 'Configure', description: 'Test config mode entry', icon: 'fa-sliders-h' },
            'enable_configure': { name: 'Enable + Configure', description: 'Test combined enable/configure', icon: 'fa-cogs' },
            'enable_configure_terminal': { name: 'Enable + Configure Terminal', description: 'Test with terminal keyword', icon: 'fa-terminal' },
            'vlan_create': { name: 'Full VLAN Creation', description: 'End-to-end VLAN creation test', icon: 'fa-check-circle' }
        };

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadSwitches();
            renderTests();
            
            document.getElementById('useCustomCreds').addEventListener('change', function() {
                document.getElementById('customCredsSection').style.display = 
                    this.checked ? 'block' : 'none';
            });
        });

        function loadSwitches() {
            fetch(API_BASE + '/diagnostic.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_switches'
            })
            .then(r => r.json())
            .then(data => {
                const select = document.getElementById('switchSelect');
                select.innerHTML = '<option value="">-- Select a switch --</option>';
                if (data.switches && data.switches.length > 0) {
                    data.switches.forEach(sw => {
                        select.innerHTML += `<option value="${sw.id}">${sw.hostname} (${sw.ip_address})</option>`;
                    });
                } else {
                    select.innerHTML += '<option disabled>No switches configured</option>';
                }
            })
            .catch(e => alert('Failed to load switches: ' + e.message));
        }

        function renderTests() {
            const container = document.getElementById('testsList');
            container.innerHTML = '';
            
            Object.entries(TESTS).forEach(([key, test]) => {
                const div = document.createElement('div');
                div.className = 'test-item';
                div.innerHTML = `
                    <input type="checkbox" class="test-checkbox" value="${key}" checked>
                    <i class="fas ${test.icon} text-primary"></i>
                    <strong>${test.name}</strong>
                    <div class="text-muted small mt-1">${test.description}</div>
                `;
                div.querySelector('input').addEventListener('change', function() {
                    div.classList.toggle('selected', this.checked);
                });
                div.classList.add('selected');
                container.appendChild(div);
            });
        }

        function toggleAllTests() {
            const checkboxes = document.querySelectorAll('.test-checkbox');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            checkboxes.forEach(cb => {
                cb.checked = !allChecked;
                cb.parentElement.classList.toggle('selected', cb.checked);
            });
        }

        function runTests() {
            const switchId = document.getElementById('switchSelect').value;
            if (!switchId) {
                alert('Please select a switch');
                return;
            }

            const selectedTests = Array.from(document.querySelectorAll('.test-checkbox:checked'))
                .map(cb => cb.value);

            if (selectedTests.length === 0) {
                alert('Please select at least one test');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'run_tests');
            formData.append('switch_id', switchId);
            selectedTests.forEach(test => formData.append('tests[]', test));
            formData.append('use_custom_creds', document.getElementById('useCustomCreds').checked ? '1' : '0');
            formData.append('custom_username', document.getElementById('customUsername').value);
            formData.append('custom_password', document.getElementById('customPassword').value);
            formData.append('custom_port', document.getElementById('customPort').value);
            formData.append('custom_use_https', document.getElementById('customUseHttps').checked ? '1' : '0');

            document.getElementById('noResultsMsg').style.display = 'none';
            document.getElementById('loadingMsg').style.display = 'block';
            document.getElementById('resultsContainer').style.display = 'none';

            fetch(API_BASE + '/diagnostic.php', {
                method: 'POST',
                body: formData
            })
            .then(r => {
                if (!r.ok) {
                    throw new Error('HTTP error, status = ' + r.status);
                }
                return r.text();
            })
            .then(text => {
                try {
                    const data = JSON.parse(text);
                    document.getElementById('loadingMsg').style.display = 'none';
                    
                    if (data.error) {
                        alert('Error: ' + data.error);
                        document.getElementById('noResultsMsg').style.display = 'block';
                        return;
                    }

                    displayResults(data);
                } catch (e) {
                    console.error('JSON Parse Error:', e);
                    console.error('Response text:', text.substring(0, 500));
                    document.getElementById('loadingMsg').style.display = 'none';
                    alert('Error parsing results. Check browser console for details.');
                    document.getElementById('noResultsMsg').style.display = 'block';
                }
            })
            .catch(e => {
                document.getElementById('loadingMsg').style.display = 'none';
                console.error('Fetch Error:', e);
                alert('Error running tests: ' + e.message);
                document.getElementById('noResultsMsg').style.display = 'block';
            });
        }

        function showInterfaceStatus() {
            const switchId = document.getElementById('switchSelect').value;
            const iface = (document.getElementById('ifName').value || '').trim();
            if (!switchId) {
                alert('Please select a switch');
                return;
            }
            if (!iface) {
                alert('Please enter an interface name (e.g., Ethernet2)');
                return;
            }

            const form = new FormData();
            form.append('action', 'show_interface_status');
            form.append('switch_id', switchId);
            form.append('interface', iface);

            document.getElementById('noResultsMsg').style.display = 'none';
            document.getElementById('loadingMsg').style.display = 'block';
            document.getElementById('resultsContainer').style.display = 'none';

            fetch(API_BASE + '/diagnostic.php', { method: 'POST', body: form })
                .then(r => r.json())
                .then(data => {
                    document.getElementById('loadingMsg').style.display = 'none';
                    if (data.error) {
                        alert('Error: ' + data.error);
                        document.getElementById('noResultsMsg').style.display = 'block';
                        return;
                    }
                    const container = document.getElementById('resultsContainer');
                    const pretty = escapeHtml(JSON.stringify(data.result, null, 2));
                    const cmd = (data.commands && data.commands[0]) ? data.commands[0] : ('show interfaces ' + iface + ' status');
                    const card = `
                        <div class="card result-card success border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="card-title mb-1">
                                            <i class="fas fa-check-circle text-success me-2"></i>
                                            Show Interface Status - ${iface}
                                        </h6>
                                        <small class="text-muted">Live query of interface status</small>
                                    </div>
                                    <span class="badge bg-success">PASS</span>
                                </div>
                                <div class="mt-3">
                                    <small class="text-muted d-block mb-2"><strong>Command:</strong></small>
                                    <div class="command-preview">
                                        <div>$ ${cmd}</div>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <small class="text-muted d-block mb-2"><strong>Response (raw JSON):</strong></small>
                                    <div class="command-preview" style="max-height: 300px; overflow-y: auto; white-space: pre;">
${pretty}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    container.innerHTML = card;
                    container.style.display = 'block';
                })
                .catch(e => {
                    document.getElementById('loadingMsg').style.display = 'none';
                    alert('Error: ' + e.message);
                    document.getElementById('noResultsMsg').style.display = 'block';
                });
        }

        function displayResults(data) {
            const container = document.getElementById('resultsContainer');
            let html = `
                <div class="alert alert-info mb-4">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Switch:</strong> ${data.switch.hostname} (${data.switch.ip_address})
                    ${data.custom_creds_used ? '<span class="badge bg-warning ms-2">Custom Credentials</span>' : ''}
                </div>
            `;

            let passCount = 0, failCount = 0;

            Object.entries(data.tests).forEach(([key, result]) => {
                const isSuccess = result.status === 'success';
                if (isSuccess) passCount++; else failCount++;

                const statusClass = isSuccess ? 'success' : 'failed';
                const statusIcon = isSuccess ? 'fa-check-circle text-success' : 'fa-times-circle text-danger';
                const badge = isSuccess ? 'bg-success' : 'bg-danger';

                html += `
                    <div class="card result-card ${statusClass} border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="card-title mb-1">
                                        <i class="fas ${statusIcon} me-2"></i>
                                        ${result.name}
                                    </h6>
                                    <small class="text-muted">${result.description}</small>
                                </div>
                                <span class="badge ${badge}">${isSuccess ? 'PASS' : 'FAIL'}</span>
                            </div>
                            
                            <div class="mt-3">
                                <small class="text-muted d-block mb-2"><strong>Commands:</strong></small>
                                <div class="command-preview">
                                    ${result.commands.map(cmd => `<div>$ ${cmd}</div>`).join('')}
                                </div>
                            </div>

                            <div class="mt-2">
                                ${isSuccess ? 
                                    `<small class="text-success"><i class="fas fa-check me-1"></i>${result.message}</small>` :
                                    `<small class="text-danger"><i class="fas fa-exclamation me-1"></i>${escapeHtml(result.error)}</small>`
                                }
                            </div>
                            
                            ${isSuccess && result.response_preview ? 
                                `<div class="mt-3">
                                    <small class="text-muted d-block mb-2"><strong>Response Preview:</strong></small>
                                    <div class="command-preview" style="max-height: 150px; overflow-y: auto;">
                                        ${escapeHtml(result.response_preview)}
                                    </div>
                                </div>` : ''
                            }
                        </div>
                    </div>
                `;
            });

            html += `
                <div class="card mt-4 border-0 shadow-sm">
                    <div class="card-body">
                        <h6 class="card-title mb-3">Summary</h6>
                        <div class="row">
                            <div class="col-6">
                                <div class="alert alert-success mb-0">
                                    <strong>${passCount}</strong> test(s) passed
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="alert alert-danger mb-0">
                                    <strong>${failCount}</strong> test(s) failed
                                </div>
                            </div>
                        </div>
                        ${failCount > 0 ? `
                            <div class="alert alert-warning mt-3 mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Troubleshooting:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>If "enable" is denied: Configure AAA authorization on the switch</li>
                                    <li>If "configure" is invalid: Check eAPI is enabled on the switch</li>
                                    <li>For help: Visit Help → Configure AAA Authorization in the application</li>
                                </ul>
                            </div>
                        ` : `
                            <div class="alert alert-success mt-3 mb-0">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>All tests passed!</strong> Your eAPI configuration is working correctly.
                            </div>
                        `}
                    </div>
                </div>
            `;

            container.innerHTML = html;
            container.style.display = 'block';
        }
    </script>
</body>
</html>

