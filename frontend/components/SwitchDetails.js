// Switch Details Component
const API_BASE_URL = '/arista/api';
import VLANManagement from './VLANManagement.js';
import InterfaceManagement from './InterfaceManagement.js';
import VlanMatrix from './VlanMatrix.js';
import PortChannelManagement from './PortChannelManagement.js';
import MaintenanceTab from './MaintenanceTab.js';
import RestartScheduler from './RestartScheduler.js';
import ConfigEditor from './ConfigEditor.js';
import ConfigViewer from './ConfigViewer.js';
import MacAddressTable from './MacAddressTable.js';
import PortActivity from './PortActivity.js';

export default {
  name: 'SwitchDetails',
  components: {
    VLANManagement,
    InterfaceManagement,
    VlanMatrix,
    PortChannelManagement,
    MaintenanceTab,
    RestartScheduler,
    ConfigEditor,
    ConfigViewer,
    MacAddressTable,
    PortActivity
  },
  props: {
    switchId: {
      type: [String, Number],
      required: true
    },
    user: {
      type: Object,
      required: true
    },
    csrfToken: {
      type: String,
      default: null
    }
  },
  data() {
    return {
      switchInfo: null,
      loading: true,
      error: null,
      activeTab: 'overview',
      polling: false,
      deleting: false,
      runningConfigDirty: false,
      savingRunningConfig: false,
      environmentData: null,
      loadingEnvironment: false,
      showEditManagementModal: false,
      editingManagement: false,
      managementIp: '',
      managementGateway: '',
      managementSubnet: '/24',
      managementError: null,
      managementData: null,
      loadingManagement: false
    }
  },
  computed: {
    canManageSwitch() {
      return this.user.role === 'admin' || this.user.role === 'operator';
    },
    canDeleteSwitch() {
      return this.user.role === 'admin';
    },
    statusBadgeClass() {
      const status = this.switchInfo?.status;
      return {
        'up': 'bg-success',
        'down': 'bg-danger',
        'unknown': 'bg-secondary'
      }[status] || 'bg-secondary';
    },
    parsedEnvironment() {
      if (!this.environmentData?.environment) return { powerSupplies: [], fans: [], temperatures: [] };
      const env = this.environmentData.environment;
      
      // Power Supplies
      const powerSupplies = [];
      const psSource = env.powerSupplySlots || env.powerSupplies || {};
      // Handle both array and object (keyed by slot)
      for (const [key, val] of Object.entries(psSource)) {
        powerSupplies.push({
          label: val.label || val.name || `PS ${key}`,
          status: val.status || val.state || 'unknown',
          model: val.modelName || ''
        });
      }

      // Fans
      const fans = [];
      const fanSource = env.fanTraySlots || env.fans || {};
      for (const [key, val] of Object.entries(fanSource)) {
        fans.push({
          label: val.label || val.name || `Fan ${key}`,
          status: val.status || val.state || 'unknown',
          speed: val.speed || null
        });
      }
      
      // If no top-level fans, check inside power supplies
      if (fans.length === 0) {
         const psSourceForFans = env.powerSupplySlots || env.powerSupplies || {};
         for (const [key, val] of Object.entries(psSourceForFans)) {
            if (val.fans) {
               for (const [fKey, fVal] of Object.entries(val.fans)) {
                  fans.push({
                     label: fVal.label || fVal.name || fKey || `Fan (PS ${key})`,
                     status: fVal.status || fVal.state || 'unknown',
                     speed: fVal.speed || null
                  });
               }
            }
         }
      }

      // Temperatures
      const temperatures = [];
      const tempSource = env.tempSensors || env.temperature || [];
      // Handle array or object
      const tempEntries = Array.isArray(tempSource) ? tempSource : Object.values(tempSource);
      
      for (const t of tempEntries) {
        temperatures.push({
          name: t.description || t.name, // Use description as requested
          current: t.currentTemperature !== undefined ? t.currentTemperature : t.value,
          max: t.maxTemperature !== undefined ? t.maxTemperature : null,
          status: t.inAlertState ? 'Alert' : (t.hwStatus === 'ok' ? 'OK' : (t.hwStatus || 'Unknown')),
          isAlert: t.inAlertState || t.alert
        });
      }

      return { powerSupplies, fans, temperatures };
    }
  },
  mounted() {
    this.loadSwitchInfo();
  },
  methods: {
    async loadSwitchInfo() {
      this.loading = true;
      this.error = null;
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/get.php?id=${this.switchId}`, {
          withCredentials: true
        });
        if (response.data.success) {
          this.switchInfo = response.data.switch;
          // Load environment data only if switch is up
          if (this.switchInfo.status === 'up') {
            this.loadEnvironment();
          } else {
            // Skip loading environment for offline switches
            this.environmentData = null;
          }
        } else {
          this.error = response.data.error || 'Failed to load switch';
        }
      } catch (error) {
        this.error = 'Failed to load switch: ' + (error.response?.data?.error || error.message);
      } finally {
        this.loading = false;
      }
    },

    async loadEnvironment() {
      // Don't attempt to load environment if switch is down
      if (this.switchInfo?.status === 'down') {
        this.environmentData = null;
        return;
      }
      
      this.loadingEnvironment = true;
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/environment/get.php?switch_id=${this.switchId}`, {
          withCredentials: true
        });
        if (response.data.success) {
          this.environmentData = response.data;
        }
      } catch (error) {
        console.error('Failed to load environment:', error);
      } finally {
        this.loadingEnvironment = false;
      }
    },

    async pollSwitch() {
      this.polling = true;
      try {
        const response = await axios.post(`${API_BASE_URL}/switches/poll.php?id=${this.switchId}`, {}, {
          withCredentials: true,
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          }
        });

        if (response.data.success) {
          this.$emit('show-message', 'Switch polled successfully', 'success');
          // Just reload switch info; don't refresh session/CSRF token
          // to avoid invalidating tokens for concurrent operations
          await this.loadSwitchInfo();
        } else {
          // Even on failure, reload switch info to get updated status (down)
          await this.loadSwitchInfo();
          
          const errorMsg = response.data.error || response.data.reason || 'Failed to poll switch';
          this.$emit('show-message', errorMsg, 'error');
        }
      } catch (error) {
        // Even on error, reload switch info to get updated status
        await this.loadSwitchInfo();
        
        const errorMsg = error.response?.data?.error || error.response?.data?.reason || error.message || 'Failed to poll switch';
        this.$emit('show-message', errorMsg, 'error');
      } finally {
        this.polling = false;
      }
    },

    editSwitch() {
      this.$emit('edit-switch', this.switchId);
    },
    async openEditManagementModal() {
      this.showEditManagementModal = true;
      this.managementError = null;
      this.loadingManagement = true;
      
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/management-interface/get.php`, {
          params: { switch_id: this.switchId },
          withCredentials: true
        });
        
        if (response.data.success) {
          this.managementData = response.data.management_interface || {};
          const currentIp = this.managementData.ip_address || '';
          
          // Extract IP and subnet mask
          if (currentIp.includes('/')) {
            const parts = currentIp.split('/');
            this.managementIp = parts[0];
            this.managementSubnet = '/' + parts[1];
          } else {
            this.managementIp = currentIp;
            this.managementSubnet = this.managementData.subnet_mask ? '/' + this.managementData.subnet_mask : '/24';
          }
          
          this.managementGateway = this.managementData.gateway || '';
        }
      } catch (error) {
        this.managementError = 'Failed to load management interface configuration: ' + (error.response?.data?.error || error.message);
      } finally {
        this.loadingManagement = false;
      }
    },
    closeEditManagementModal() {
      this.showEditManagementModal = false;
      this.managementIp = '';
      this.managementGateway = '';
      this.managementSubnet = '/24';
      this.managementError = null;
      this.managementData = null;
    },
    async saveManagementInterface() {
      if (!this.csrfToken) {
        this.$emit('show-message', 'CSRF token not available', 'error');
        return;
      }

      // Validate IP address with CIDR
      const ipAddress = this.managementIp.trim();
      if (!ipAddress) {
        this.managementError = 'IP address cannot be empty';
        return;
      }
      
      // Validate IPv4 format
      const ipRegex = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
      if (!ipRegex.test(ipAddress)) {
        this.managementError = 'Invalid IP address format. Please enter a valid IPv4 address (e.g., 192.168.1.100)';
        return;
      }
      
      // Validate subnet mask (CIDR)
      const subnet = this.managementSubnet.trim();
      if (!subnet.match(/^\/\d+$/) || parseInt(subnet.substring(1)) < 0 || parseInt(subnet.substring(1)) > 32) {
        this.managementError = 'Invalid subnet mask. Please enter a valid CIDR notation (e.g., /24)';
        return;
      }
      
      // Validate gateway if provided
      const gateway = this.managementGateway.trim();
      if (gateway && !ipRegex.test(gateway)) {
        this.managementError = 'Invalid gateway IP address format';
        return;
      }

      const fullIpAddress = ipAddress + subnet;

      this.editingManagement = true;
      this.managementError = null;

      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/management-interface/set.php?switch_id=${this.switchId}`,
          {
            csrf_token: this.csrfToken,
            ip_address: fullIpAddress,
            gateway: gateway || null
          },
          {
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );

        if (response.data.success) {
          this.$emit('show-message', 'Management interface configuration updated successfully', 'success');
          this.closeEditManagementModal();
          this.markConfigChanged(); // Mark config as changed
        } else {
          this.managementError = response.data.error || 'Failed to update management interface';
          if (response.data.errors && Array.isArray(response.data.errors)) {
            this.managementError = response.data.errors.join(', ');
          }
        }
      } catch (error) {
        this.managementError = error.response?.data?.error || error.message || 'Failed to update management interface';
        if (error.response?.data?.errors && Array.isArray(error.response.data.errors)) {
          this.managementError = error.response.data.errors.join(', ');
        }
      } finally {
        this.editingManagement = false;
      }
    },

    manageVLANs() {
      this.$emit('manage-vlans', this.switchId);
    },

    async deleteSwitch() {
      if (!confirm(`Are you sure you want to delete switch "${this.switchInfo.hostname}"?\n\nThis action cannot be undone and will delete all related data.`)) {
        return;
      }

      if (!this.csrfToken) {
        this.$emit('show-message', 'CSRF token not available', 'error');
        return;
      }

      this.deleting = true;
      try {
        const response = await axios.delete(`${API_BASE_URL}/switches/delete.php?id=${this.switchId}`, {
          data: { csrf_token: this.csrfToken },
          withCredentials: true,
          headers: { 'Content-Type': 'application/json' }
        });

        if (response.data.success) {
          this.$emit('show-message', `Switch "${this.switchInfo.hostname}" deleted successfully`, 'success');
          this.$emit('switch-deleted');
        } else {
          this.$emit('show-message', response.data.error || 'Failed to delete switch', 'error');
        }
      } catch (error) {
        const errorMsg = error.response?.data?.error || error.message;
        this.$emit('show-message', 'Failed to delete switch: ' + errorMsg, 'error');
      } finally {
        this.deleting = false;
      }
    },

    markConfigChanged() {
      this.runningConfigDirty = true;
      // Trigger VLAN matrix refresh when configuration changes
      if (this.$refs.vlanMatrix) {
        this.$refs.vlanMatrix.refreshTrigger++;
      }
      // Trigger Interface Management refresh when configuration changes (e.g., port channel members added/removed)
      if (this.$refs.interfaceManagement) {
        this.$refs.interfaceManagement.refreshTrigger++;
      }
    },

    async saveRunningConfig() {
      if (!this.csrfToken) {
        this.$emit('show-message', 'CSRF token not available', 'error');
        return;
      }
      this.savingRunningConfig = true;
      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/config/save.php?switch_id=${this.switchId}`,
          { csrf_token: this.csrfToken },
          {
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );
        if (response.data?.success) {
          this.$emit('show-message', response.data.message || 'Running config saved to startup config', 'success');
          this.runningConfigDirty = false;
        } else {
          this.$emit('show-message', response.data?.error || 'Failed to save configuration', 'error');
        }
      } catch (error) {
        this.$emit('show-message', 'Failed to save configuration: ' + (error.response?.data?.error || error.message), 'error');
      } finally {
        this.savingRunningConfig = false;
      }
    }
  },
  template: `
    <div class="switch-details">
      <div class="mb-3">
        <button class="btn btn-secondary btn-sm" @click="$emit('back')">
          <i class="fas fa-arrow-left me-1"></i>
          Back to Switches
        </button>
      </div>

      <div v-if="loading" class="text-center py-5">
        <div class="spinner-border text-primary" role="status">
          <span class="visually-hidden">Loading...</span>
        </div>
      </div>

      <div v-else-if="error" class="alert alert-danger">
        <i class="fas fa-exclamation-circle me-2"></i>
        {{ error }}
      </div>

      <div v-else-if="switchInfo" class="card">
        <!-- Header with Switch Info -->
        <div class="card-header bg-light">
          <div class="d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
              <div>
                <h5 class="mb-0">
                  <i class="fas fa-network-wired me-2"></i>
                  {{ switchInfo.hostname }}
                </h5>
                <small class="text-muted">{{ switchInfo.ip_address }}</small>
              </div>
              <button
                v-if="canManageSwitch"
                class="btn btn-sm btn-outline-success ms-3"
                @click="saveRunningConfig"
                :disabled="(!runningConfigDirty && !savingRunningConfig) || savingRunningConfig"
                title="Copy running-config to startup-config"
              >
                <span v-if="savingRunningConfig" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <i class="fas fa-save me-1"></i>
                {{ savingRunningConfig ? 'Saving...' : 'Save Running Config' }}
              </button>
            </div>
            <div class="text-end">
              <span :class="['badge', statusBadgeClass]">
                {{ switchInfo.status }}
              </span>
            </div>
          </div>
        </div>

        <div class="card-body">
          <!-- Tab Navigation -->
          <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
              <a class="nav-link" :class="{ active: activeTab === 'overview' }" 
                 href="#" @click.prevent="activeTab = 'overview'">
                <i class="fas fa-info-circle me-1"></i> Overview
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" :class="{ active: activeTab === 'vlans' }" 
                 href="#" @click.prevent="activeTab = 'vlans'">
                <i class="fas fa-layer-group me-1"></i> VLANs
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" :class="{ active: activeTab === 'vlan-matrix' }" 
                 href="#" @click.prevent="activeTab = 'vlan-matrix'">
                <i class="fas fa-table me-1"></i> VLAN Matrix
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" :class="{ active: activeTab === 'interfaces' }"
                 href="#" @click.prevent="activeTab = 'interfaces'">
                <i class="fas fa-network-wired me-1"></i> Interfaces
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" :class="{ active: activeTab === 'port-channels' }"
                 href="#" @click.prevent="activeTab = 'port-channels'">
                <i class="fas fa-link me-1"></i> Port Channels
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" :class="{ active: activeTab === 'mac-table' }"
                 href="#" @click.prevent="activeTab = 'mac-table'">
                <i class="fas fa-list me-1"></i> MAC Table
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" :class="{ active: activeTab === 'port-activity' }"
                 href="#" @click.prevent="activeTab = 'port-activity'">
                <i class="fas fa-th me-1"></i> Port Activity
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" :class="{ active: activeTab === 'maintenance' }"
                 href="#" @click.prevent="activeTab = 'maintenance'">
                <i class="fas fa-tools me-1"></i> Maintenance
              </a>
            </li>
          </ul>

          <!-- Overview Tab -->
          <div v-if="activeTab === 'overview'">
            <div class="row mb-4">
              <div class="col-md-6">
                <h6 class="text-muted mb-3">General Information</h6>
                <table class="table table-sm table-borderless">
                  <tr>
                    <td class="fw-bold">Hostname:</td>
                    <td>{{ switchInfo.hostname }}</td>
                  </tr>
                  <tr>
                    <td class="fw-bold">IP Address:</td>
                    <td>{{ switchInfo.ip_address }}</td>
                  </tr>
                  <tr>
                    <td class="fw-bold">Model:</td>
                    <td>{{ switchInfo.model || 'N/A' }}</td>
                  </tr>
                  <tr>
                    <td class="fw-bold">Firmware:</td>
                    <td>{{ switchInfo.firmware_version || 'N/A' }}</td>
                  </tr>
                  <tr>
                    <td class="fw-bold">Role:</td>
                    <td>
                      <span v-if="switchInfo.role" class="badge bg-secondary">
                        {{ switchInfo.role }}
                      </span>
                      <span v-else class="text-muted">Not assigned</span>
                    </td>
                  </tr>
                  <tr>
                    <td class="fw-bold">Status:</td>
                    <td>
                      <span :class="['badge', statusBadgeClass]">
                        {{ switchInfo.status }}
                      </span>
                    </td>
                  </tr>
                </table>
              </div>
              <div class="col-md-6">
                <h6 class="text-muted mb-3">Timestamps</h6>
                <table class="table table-sm table-borderless">
                  <tr>
                    <td class="fw-bold">Last Seen:</td>
                    <td>{{ switchInfo.last_seen ? new Date(switchInfo.last_seen).toLocaleString() : 'Never' }}</td>
                  </tr>
                  <tr>
                    <td class="fw-bold">Last Polled:</td>
                    <td>{{ switchInfo.last_polled ? new Date(switchInfo.last_polled).toLocaleString() : 'Never' }}</td>
                  </tr>
                  <tr>
                    <td class="fw-bold">Created:</td>
                    <td>{{ switchInfo.created_at ? new Date(switchInfo.created_at).toLocaleString() : 'N/A' }}</td>
                  </tr>
                  <tr>
                    <td class="fw-bold">Updated:</td>
                    <td>{{ switchInfo.updated_at ? new Date(switchInfo.updated_at).toLocaleString() : 'N/A' }}</td>
                  </tr>
                </table>
              </div>
            </div>

            <!-- Environment Section -->
            <div class="mb-4">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-muted mb-0">Environmental Status</h6>
                <button 
                  v-if="switchInfo?.status === 'up'" 
                  class="btn btn-sm btn-link text-decoration-none" 
                  @click="loadEnvironment" 
                  :disabled="loadingEnvironment"
                >
                  <i class="fas fa-sync-alt" :class="{ 'fa-spin': loadingEnvironment }"></i> Refresh
                </button>
              </div>
              
              <div v-if="switchInfo?.status === 'down'" class="alert alert-warning mb-0">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Environment data unavailable:</strong> Cannot load environment data when the switch is offline. 
                Please check the switch connection and try again.
              </div>
              
              <div v-else-if="environmentData || loadingEnvironment">
                <div v-if="loadingEnvironment && !environmentData" class="text-center py-3">
                  <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                  <span class="ms-2 text-muted">Loading environment data...</span>
                </div>
                
                <div v-else-if="environmentData" class="card card-body bg-light border-0">
                <div class="row">
                  <!-- System Status -->
                  <div class="col-md-3 mb-3">
                    <strong>System Status:</strong>
                    <div class="mt-1">
                      <span v-if="(environmentData.environment?.systemStatus === 'normal' || environmentData.environment?.status === 'ok')" class="badge bg-success">Normal</span>
                      <span v-else class="badge bg-warning">{{ environmentData.environment?.systemStatus || environmentData.environment?.status || 'Unknown' }}</span>
                    </div>
                  </div>
                  
                  <!-- Locator LED -->
                  <div class="col-md-3 mb-3">
                    <strong>Locator LED:</strong>
                    <div class="mt-1">
                      <span v-if="environmentData.locator_led?.locatorLedStatus === 'active'" class="badge bg-primary blinking-badge">
                        <i class="fas fa-lightbulb me-1"></i> On
                      </span>
                      <span v-else class="badge bg-secondary">Off</span>
                    </div>
                  </div>

                  <!-- Power Supplies -->
                  <div class="col-md-3 mb-3">
                    <strong>Power Supplies:</strong>
                    <div class="mt-1" v-if="parsedEnvironment.powerSupplies.length > 0">
                      <div v-for="ps in parsedEnvironment.powerSupplies" :key="ps.label" class="small mb-1">
                        <i class="fas fa-plug me-1" :class="ps.status === 'ok' ? 'text-success' : 'text-danger'"></i>
                        {{ ps.label }}: {{ ps.status }}
                      </div>
                    </div>
                    <div v-else class="text-muted small">N/A</div>
                  </div>

                  <!-- Fans -->
                  <div class="col-md-3 mb-3">
                    <strong>Fans:</strong>
                    <div class="mt-1" v-if="parsedEnvironment.fans.length > 0">
                      <div v-for="fan in parsedEnvironment.fans" :key="fan.label" class="small mb-1">
                        <i class="fas fa-fan me-1" :class="fan.status === 'ok' ? 'text-success' : 'text-danger'"></i>
                        {{ fan.label }}: {{ fan.status }} <span v-if="fan.speed">({{ fan.speed }}%)</span>
                      </div>
                    </div>
                    <div v-else class="text-muted small">N/A</div>
                  </div>
                </div>
                
                <!-- Temperature Sensors (Collapsible) -->
                <div v-if="parsedEnvironment.temperatures.length > 0" class="mt-2">
                  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#tempSensors" aria-expanded="false">
                    <i class="fas fa-thermometer-half me-1"></i> Show Temperatures
                  </button>
                  <div class="collapse mt-2" id="tempSensors">
                    <div class="table-responsive bg-white border rounded p-2">
                      <table class="table table-sm table-borderless mb-0">
                        <thead>
                          <tr class="text-muted small">
                            <th>Sensor</th>
                            <th>Temp</th>
                            <th>Status</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr v-for="sensor in parsedEnvironment.temperatures" :key="sensor.name">
                            <td>{{ sensor.name }}</td>
                            <td>
                                {{ typeof sensor.current === 'number' ? sensor.current.toFixed(2) : sensor.current }}°C
                                <small v-if="sensor.max" class="text-muted ms-1">(Max: {{ typeof sensor.max === 'number' ? sensor.max.toFixed(2) : sensor.max }}°C)</small>
                            </td>
                            <td>
                              <span class="badge" :class="sensor.isAlert ? 'bg-danger' : 'bg-success'">
                                {{ sensor.status }}
                              </span>
                            </td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
              </div>
            </div>

            <!-- Action Buttons -->
            <div class="border-top pt-3">
              <h6 class="text-muted mb-3">Actions</h6>
              <div class="btn-group" role="group">
                <button
                  class="btn btn-outline-primary"
                  @click="pollSwitch"
                  :disabled="polling"
                  title="Check switch online status"
                >
                  <span v-if="polling" class="spinner-border spinner-border-sm me-2" role="status"></span>
                  <i class="fas fa-sync-alt me-1"></i>
                  {{ polling ? 'Polling...' : 'Poll Switch' }}
                </button>

                <button
                  v-if="canManageSwitch"
                  class="btn btn-outline-primary"
                  @click="openEditManagementModal"
                  title="Edit switch Management interface IP and gateway"
                >
                  <i class="fas fa-network-wired me-1"></i>
                  Edit
                </button>

                <button
                  v-if="canManageSwitch"
                  class="btn btn-outline-warning"
                  @click="editSwitch"
                  title="Edit switch connection settings (IP, credentials, port, etc.)"
                >
                  <i class="fas fa-cog me-1"></i>
                  Edit Connection
                </button>

                <button
                  v-if="canDeleteSwitch"
                  class="btn btn-outline-danger"
                  @click="deleteSwitch"
                  :disabled="deleting"
                  title="Delete this switch"
                >
                  <span v-if="deleting" class="spinner-border spinner-border-sm me-2" role="status"></span>
                  <i class="fas fa-trash me-1"></i>
                  {{ deleting ? 'Deleting...' : 'Delete' }}
                </button>
              </div>
            </div>
          </div>

          <!-- VLANs Tab -->
          <div v-if="activeTab === 'vlans'">
            <VLANManagement
              :switch-id="switchId"
              :user="user"
              :csrf-token="csrfToken"
              :is-polling="polling"
              @config-changed="markConfigChanged"
              @show-message="(message, type) => $emit('show-message', message, type)"
            />
          </div>

          <div v-if="activeTab === 'interfaces'">
            <InterfaceManagement
              ref="interfaceManagement"
              :switch-id="switchId"
              :user="user"
              :csrf-token="csrfToken"
              @config-changed="markConfigChanged"
              @show-message="(message, type) => $emit('show-message', message, type)"
            />
          </div>

          <div v-if="activeTab === 'vlan-matrix'">
            <VlanMatrix
              ref="vlanMatrix"
              :switch-id="switchId"
              :user="user"
              :csrf-token="csrfToken"
              @config-changed="markConfigChanged"
              @show-message="(message, type) => $emit('show-message', message, type)"
            />
          </div>

          <!-- Port Channels Tab -->
          <div v-if="activeTab === 'port-channels'">
            <PortChannelManagement
              :switch-id="switchId"
              :user="user"
              :csrf-token="csrfToken"
              @config-changed="markConfigChanged"
              @show-message="(message, type) => $emit('show-message', message, type)"
            />
          </div>

          <!-- MAC Address Table Tab -->
          <div v-if="activeTab === 'mac-table'">
            <MacAddressTable :switch-id="switchId" />
          </div>

          <!-- Port Activity Tab -->
          <div v-if="activeTab === 'port-activity'">
            <PortActivity :switch-id="switchId" />
          </div>

          <!-- Maintenance Tab -->
          <div v-if="activeTab === 'maintenance'">
            <MaintenanceTab
              :switch-id="switchId"
              :switch-hostname="switchInfo.hostname"
              :csrf-token="csrfToken"
              :user="user"
              @show-message="(message, type) => $emit('show-message', message, type)"
              @config-changed="markConfigChanged"
            />
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Edit Management Interface Modal -->
  <div v-if="showEditManagementModal" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="fas fa-network-wired me-2"></i>
            Edit Management Interface
          </h5>
          <button type="button" class="btn-close" @click="closeEditManagementModal"></button>
        </div>
        <div class="modal-body">
          <div v-if="managementError" class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            {{ managementError }}
          </div>

          <div v-if="loadingManagement" class="text-center py-3">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-muted">Loading management interface configuration...</p>
          </div>

          <div v-else>
            <div class="alert alert-info">
              <i class="fas fa-info-circle me-2"></i>
              <strong>Note:</strong> This will configure the Management1 interface IP address and gateway on the switch itself. Changes will be applied to the running configuration.
            </div>

            <div class="mb-3">
              <label for="managementIp" class="form-label">IP Address <span class="text-danger">*</span></label>
              <div class="input-group">
                <input
                  type="text"
                  class="form-control"
                  id="managementIp"
                  v-model="managementIp"
                  placeholder="192.168.1.100"
                  :disabled="editingManagement"
                  @keyup.enter="saveManagementInterface"
                />
                <select class="form-select" v-model="managementSubnet" :disabled="editingManagement" style="max-width: 120px;">
                  <option value="/24">/24</option>
                  <option value="/16">/16</option>
                  <option value="/8">/8</option>
                  <option value="/25">/25</option>
                  <option value="/26">/26</option>
                  <option value="/27">/27</option>
                  <option value="/28">/28</option>
                  <option value="/29">/29</option>
                  <option value="/30">/30</option>
                  <option value="/32">/32</option>
                </select>
              </div>
              <small class="form-text text-muted">IP address and subnet mask for Management1 interface</small>
            </div>

            <div class="mb-3">
              <label for="managementGateway" class="form-label">Default Gateway</label>
              <input
                type="text"
                class="form-control"
                id="managementGateway"
                v-model="managementGateway"
                placeholder="192.168.1.1"
                :disabled="editingManagement"
                @keyup.enter="saveManagementInterface"
              />
              <small class="form-text text-muted">Default gateway for Management1 interface (optional)</small>
            </div>

            <div v-if="managementData && (managementData.ip_address || managementData.gateway)" class="mb-3">
              <label class="form-label">Current Configuration</label>
              <div class="form-control-plaintext bg-light p-2 rounded">
                <div><strong>IP Address:</strong> <code>{{ managementData.ip_address || 'Not configured' }}</code></div>
                <div class="mt-1"><strong>Gateway:</strong> <code>{{ managementData.gateway || 'Not configured' }}</code></div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="closeEditManagementModal" :disabled="editingManagement || loadingManagement">
            Cancel
          </button>
          <button type="button" class="btn btn-primary" @click="saveManagementInterface" :disabled="editingManagement || loadingManagement || !managementIp.trim()">
            <span v-if="editingManagement" class="spinner-border spinner-border-sm me-2" role="status"></span>
            <i v-else class="fas fa-save me-2"></i>
            {{ editingManagement ? 'Saving...' : 'Save Configuration' }}
          </button>
        </div>
      </div>
    </div>
  </div>
  `
};

