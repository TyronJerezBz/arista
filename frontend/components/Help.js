// Help Component
export default {
  name: 'Help',
  data() {
    return {
      activeSection: 'factory-reset'
    }
  },
  methods: {
    openDiagnosticTool() {
      // Open the new interactive diagnostic tool in a new tab
      window.open('/arista/diagnostic.php', '_blank');
    }
  },
  template: `
    <div class="help-container">
      <div class="container-lg py-4">
        <div class="row mb-4">
          <div class="col-12">
            <h2 class="mb-1">
              <i class="fas fa-question-circle me-2 text-primary"></i>
              Help & Setup Guide
            </h2>
            <p class="text-muted">Step-by-step guides for switch configuration and setup</p>
          </div>
        </div>

        <div class="row">
          <!-- Navigation Sidebar -->
          <div class="col-lg-3 mb-4">
            <div class="card border-0 shadow-sm sticky-top" style="top: 20px;">
              <div class="list-group list-group-flush">
                <button
                  class="list-group-item list-group-item-action border-0"
                  :class="{ active: activeSection === 'factory-reset' }"
                  @click="activeSection = 'factory-reset'"
                >
                  <i class="fas fa-sync-alt me-2"></i>
                  Factory Reset Switch
                </button>
                <button
                  class="list-group-item list-group-item-action border-0"
                  :class="{ active: activeSection === 'set-ip' }"
                  @click="activeSection = 'set-ip'"
                >
                  <i class="fas fa-network-wired me-2"></i>
                  Set IP Address
                </button>
                <button
                  class="list-group-item list-group-item-action border-0"
                  :class="{ active: activeSection === 'set-password' }"
                  @click="activeSection = 'set-password'"
                >
                  <i class="fas fa-lock me-2"></i>
                  Set Admin Password
                </button>
                <button
                  class="list-group-item list-group-item-action border-0"
                  :class="{ active: activeSection === 'enable-eapi' }"
                  @click="activeSection = 'enable-eapi'"
                >
                  <i class="fas fa-cogs me-2"></i>
                  Enable & Configure eAPI
                </button>
                <button
                  class="list-group-item list-group-item-action border-0"
                  :class="{ active: activeSection === 'configure-aaa' }"
                  @click="activeSection = 'configure-aaa'"
                >
                  <i class="fas fa-shield-alt me-2"></i>
                  Configure AAA Authorization
                </button>
              </div>
            </div>
          </div>

          <!-- Content Area -->
          <div class="col-lg-9">
            <!-- Factory Reset Section -->
            <div v-if="activeSection === 'factory-reset'" class="card border-0 shadow-sm">
              <div class="card-body">
                <h4 class="card-title mb-4">
                  <i class="fas fa-sync-alt me-2 text-warning"></i>
                  Factory Reset Switch
                </h4>
                
                <div class="alert alert-warning" role="alert">
                  <i class="fas fa-exclamation-triangle me-2"></i>
                  <strong>Warning:</strong> This will erase all configuration on the switch. Ensure you have a backup if needed.
                </div>

                <h6 class="mt-4 mb-3">Steps:</h6>
                <ol>
                  <li class="mb-3">
                    <strong>Connect to the switch console</strong>
                    <p class="text-muted small">Use a serial connection or console access (via terminal/PuTTY)</p>
                  </li>
                  <li class="mb-3">
                    <strong>Enter privileged mode</strong>
                    <div class="bg-dark p-3 rounded text-light font-monospace small">
                      Switch&gt; <span class="text-info">enable</span>
                    </div>
                  </li>
                  <li class="mb-3">
                    <strong>Delete the startup configuration</strong>
                    <div class="bg-dark p-3 rounded text-light font-monospace small">
                      Switch# <span class="text-info">delete startup-config</span><br>
                      <span class="text-warning">Delete boot flash:/startup-config? [Y/N]: Y</span>
                    </div>
                  </li>
                  <li class="mb-3">
                    <strong>Reload the switch</strong>
                    <div class="bg-dark p-3 rounded text-light font-monospace small">
                      Switch# <span class="text-info">reload</span><br>
                      <span class="text-warning">Proceed with reload? [Y/N]: Y</span>
                    </div>
                  </li>
                  <li class="mb-3">
                    <strong>During boot, press NO when prompted to enter setup</strong>
                    <p class="text-muted small">When you see "Would you like to enter the initial configuration dialog?", press <kbd>N</kbd> and <kbd>Enter</kbd></p>
                  </li>
                  <li>
                    <strong>Wait for the switch to boot completely</strong>
                    <p class="text-muted small">The switch is now at factory defaults and ready for configuration</p>
                  </li>
                </ol>
              </div>
            </div>

            <!-- Set IP Address Section -->
            <div v-if="activeSection === 'set-ip'" class="card border-0 shadow-sm">
              <div class="card-body">
                <h4 class="card-title mb-4">
                  <i class="fas fa-network-wired me-2 text-info"></i>
                  Set IP Address
                </h4>

                <p class="text-muted mb-4">Configure the Management1 interface with an IP address so you can manage the switch remotely.</p>

                <h6 class="mt-4 mb-3">Commands:</h6>
                <div class="bg-dark p-4 rounded text-light font-monospace small mb-3">
                  <div>Switch&gt; <span class="text-info">enable</span></div>
                  <div>Switch# <span class="text-info">configure</span></div>
                  <div>Switch(config)# <span class="text-info">interface Management1</span></div>
                  <div>Switch(config-if)# <span class="text-info">ip address 10.10.50.191 255.255.255.0</span></div>
                  <div>Switch(config-if)# <span class="text-info">no shutdown</span></div>
                  <div>Switch(config-if)# <span class="text-info">exit</span></div>
                  <div>Switch(config)# <span class="text-info">ip route 0.0.0.0/0 10.10.50.1</span></div>
                  <div>Switch(config)# <span class="text-info">exit</span></div>
                  <div>Switch# <span class="text-info">write memory</span></div>
                </div>

                <h6 class="mt-4 mb-3">Verify:</h6>
                <div class="bg-dark p-3 rounded text-light font-monospace small mb-3">
                  <div>Switch# <span class="text-info">show ip interface brief</span></div>
                </div>
                <p class="text-muted small">Management1 should show your configured IP and status "up"</p>
              </div>
            </div>

            <!-- Set Password Section -->
            <div v-if="activeSection === 'set-password'" class="card border-0 shadow-sm">
              <div class="card-body">
                <h4 class="card-title mb-4">
                  <i class="fas fa-lock me-2 text-danger"></i>
                  Set Admin Password
                </h4>

                <p class="text-muted mb-4">Create a local admin user account with full privileges for authentication and eAPI access.</p>

                <h6 class="mt-4 mb-3">Commands:</h6>
                <div class="bg-dark p-4 rounded text-light font-monospace small mb-3">
                  <div>Switch&gt; <span class="text-info">enable</span></div>
                  <div>Switch# <span class="text-info">configure</span></div>
                  <div>Switch(config)# <span class="text-info">username admin privilege 15 secret password</span></div>
                  <div>Switch(config)# <span class="text-info">exit</span></div>
                  <div>Switch# <span class="text-info">write memory</span></div>
                </div>

                <div class="alert alert-info mt-4">
                  <i class="fas fa-info-circle me-2"></i>
                  <strong>Important:</strong> Replace <code>password</code> with your desired password. Privilege level 15 is required for full CLI access.
                </div>

                <h6 class="mt-4 mb-3">Verify:</h6>
                <div class="bg-dark p-3 rounded text-light font-monospace small">
                  <div>Switch# <span class="text-info">show users</span></div>
                </div>
                <p class="text-muted small">Should show "admin" with privilege 15</p>
              </div>
            </div>

            <!-- Enable eAPI Section -->
            <div v-if="activeSection === 'enable-eapi'" class="card border-0 shadow-sm">
              <div class="card-body">
                <h4 class="card-title mb-4">
                  <i class="fas fa-cogs me-2 text-success"></i>
                  Enable & Configure eAPI
                </h4>

                <p class="text-muted mb-4">Enable the Extensible API (eAPI) service on the default VRF so the Arista Switch Management Platform can communicate with the switch.</p>

                <h6 class="mt-4 mb-3">Commands:</h6>
                <div class="bg-dark p-4 rounded text-light font-monospace small mb-3">
                  <div>Switch&gt; <span class="text-info">enable</span></div>
                  <div>Switch# <span class="text-info">configure</span></div>
                  <div>Switch(config)# <span class="text-info">management api http-commands</span></div>
                  <div>Switch(config-mgmt-api-http-cmds)# <span class="text-info">protocol https port 443</span></div>
                  <div>Switch(config-mgmt-api-http-cmds)# <span class="text-info">protocol http port 80</span></div>
                  <div>Switch(config-mgmt-api-http-cmds)# <span class="text-info">no shutdown</span></div>
                  <div>Switch(config-mgmt-api-http-cmds)# <span class="text-info">exit</span></div>
                  <div>Switch(config)# <span class="text-info">exit</span></div>
                  <div>Switch# <span class="text-info">write memory</span></div>
                </div>

                <h6 class="mt-4 mb-3">Verify:</h6>
                <div class="bg-dark p-3 rounded text-light font-monospace small mb-3">
                  <div>Switch# <span class="text-info">show management api http-commands</span></div>
                </div>
                <p class="text-muted small mb-3">Should show:</p>
                <ul class="text-muted small">
                  <li><code>Enabled: Yes</code></li>
                  <li><code>HTTPS server: running</code></li>
                  <li><code>VRFs: default</code></li>
                </ul>

                <h6 class="mt-4 mb-3">Test from your PC:</h6>
                <div class="bg-dark p-3 rounded text-light font-monospace small mb-3">
                  Test-NetConnection &lt;switch-ip&gt; -Port 443
                </div>
                <p class="text-muted small">Should show <code>TcpTestSucceeded: True</code></p>

                <div class="alert alert-success mt-4">
                  <i class="fas fa-check-circle me-2"></i>
                  <strong>Ready!</strong> You can now add the switch to the platform with the IP, protocol HTTPS, port 443, username "admin", and the password you set earlier.
                </div>

                <div class="mt-4 pt-4 border-top">
                  <h6 class="mb-3">Troubleshooting:</h6>
                  <p class="text-muted small mb-3">
                    Having issues with VLAN creation or configuration commands? Use the diagnostic tool to test your eAPI connection and permissions:
                  </p>
                  <button 
                    class="btn btn-outline-info btn-sm"
                    @click="openDiagnosticTool"
                  >
                    <i class="fas fa-stethoscope me-2"></i>
                    Run eAPI Diagnostic Tool
                  </button>
                </div>
              </div>
            </div>

            <!-- Configure AAA Authorization Section -->
            <div v-if="activeSection === 'configure-aaa'" class="card border-0 shadow-sm">
              <div class="card-body">
                <h4 class="card-title mb-4">
                  <i class="fas fa-shield-alt me-2 text-warning"></i>
                  Configure AAA Authorization for eAPI
                </h4>

                <p class="text-muted mb-4">
                  For eAPI users to have permission to run configuration commands (like creating VLANs), 
                  you must configure AAA authorization on the switch. This grants the eAPI user privilege 
                  level 15 (full admin access).
                </p>

                <div class="alert alert-info">
                  <i class="fas fa-info-circle me-2"></i>
                  <strong>Why is this needed?</strong> By default, eAPI users have limited permissions and cannot 
                  enter configuration mode. AAA authorization enables them to execute admin commands via eAPI.
                </div>

                <h6 class="mt-4 mb-3">Commands:</h6>
                <div class="bg-dark p-4 rounded text-light font-monospace small mb-3">
                  <div>Switch&gt; <span class="text-info">enable</span></div>
                  <div>Switch# <span class="text-info">configure</span></div>
                  <div>Switch(config)# <span class="text-info">aaa authorization exec default local</span></div>
                  <div>Switch(config)# <span class="text-info">aaa authorization commands all default local</span></div>
                  <div>Switch(config)# <span class="text-info">username arista_eapi privilege 15</span></div>
                  <div>Switch(config)# <span class="text-info">exit</span></div>
                  <div>Switch# <span class="text-info">write memory</span></div>
                </div>

                <h6 class="mt-4 mb-3">Explanation:</h6>
                <ul class="text-muted small">
                  <li><code>aaa authorization exec default local</code> - Use local authentication for login sessions</li>
                  <li><code>aaa authorization commands all default local</code> - Use local authentication for command execution</li>
                  <li><code>username arista_eapi privilege 15</code> - Grant the eAPI user full admin privileges (level 15)</li>
                </ul>

                <h6 class="mt-4 mb-3">Verify:</h6>
                <div class="bg-dark p-3 rounded text-light font-monospace small mb-3">
                  <div>Switch# <span class="text-info">show aaa authorization</span></div>
                  <div>Switch# <span class="text-info">show users</span></div>
                </div>
                <p class="text-muted small mb-3">You should see:</p>
                <ul class="text-muted small">
                  <li>Authorization method lists configured for "exec" and "commands"</li>
                  <li><code>arista_eapi</code> user with privilege 15</li>
                </ul>

                <div class="alert alert-success mt-4">
                  <i class="fas fa-check-circle me-2"></i>
                  <strong>Complete!</strong> Your eAPI user now has full permissions to create/modify VLANs, 
                  interfaces, and other configurations via the platform.
                </div>

                <div class="mt-4 pt-4 border-top">
                  <h6 class="mb-3">Verify Configuration:</h6>
                  <p class="text-muted small mb-3">
                    Use the diagnostic tool to verify your eAPI permissions are working correctly:
                  </p>
                  <button 
                    class="btn btn-outline-success btn-sm"
                    @click="openDiagnosticTool"
                  >
                    <i class="fas fa-stethoscope me-2"></i>
                    Run eAPI Diagnostic Tool
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
};

