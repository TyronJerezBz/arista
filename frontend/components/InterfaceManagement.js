// Interface Management Component
const API_BASE_URL = '/arista/api';

export default {
  name: 'InterfaceManagement',
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
      interfaces: [],
      loading: false,
      syncing: false,
      saving: false,
      error: null,
      searchTerm: '',
      showConfigureModal: false,
      showDetailsModal: false,
      showTransceiverModal: false,
      selectedInterface: null,
      selectedTransceiverInterface: null,
      transceiverData: null,
      loadingTransceiver: false,
      detailsInterface: null,
      form: {
        interface: '',
        adminState: '',
        description: '',
        customTag: ''
      },
      validationErrors: [],
      vlans: [],
      trunkTomSelect: null,
      justSaved: false,
      refreshTrigger: 0 // Incremented to trigger reload
    };
  },
  computed: {
    canManageInterfaces() {
      return this.user.role === 'admin' || this.user.role === 'operator';
    },
    filteredInterfaces() {
      const list = this.interfaces;
      const term = this.searchTerm?.toLowerCase() || '';
      const filtered = term
        ? list.filter(i =>
        i.interface_name.toLowerCase().includes(term) ||
        (i.mode && i.mode.toLowerCase().includes(term)) ||
        (i.oper_status && i.oper_status.toLowerCase().includes(term)) ||
        (i.description && i.description.toLowerCase().includes(term))
        )
        : [...list];
      // Sort by interface number (natural order)
      filtered.sort(this.compareInterfaces);
      return filtered;
    },
    modalTitle() {
      if (!this.selectedInterface) return 'Configure Interface';
      return `Configure ${this.selectedInterface.interface_name}`;
    }
  },
  mounted() {
    this.loadInterfaces();
    this.loadVlans();
  },
  watch: {
    refreshTrigger() {
      this.loadInterfaces();
    }
  },
  methods: {
    openDetailsModal(interfaceRow) {
      this.detailsInterface = interfaceRow;
      this.showDetailsModal = true;
    },
    async loadVlans() {
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/vlans/list.php`, {
          params: { switch_id: this.switchId },
          withCredentials: true
        });
        if (response.data?.success) {
          this.vlans = response.data.vlans || [];
        } else {
          this.vlans = [];
        }
      } catch (e) {
        this.vlans = [];
      }
    },
    async loadInterfaces() {
      this.loading = true;
      this.error = null;
      try {
        // Always load from switch
        const response = await axios.get(`${API_BASE_URL}/switches/interfaces/list.php`, {
          params: { switch_id: this.switchId, source: 'switch' },
          withCredentials: true
        });
        if (response.data.success) {
          this.interfaces = response.data.interfaces || [];
        } else {
          this.error = response.data.error || 'Failed to load interfaces';
        }
      } catch (error) {
        this.error = 'Failed to load interfaces: ' + (error.response?.data?.error || error.message);
        console.error('Error loading interfaces:', error);
      } finally {
        this.loading = false;
      }
    },
    async syncInterfaces() {
      if (!confirm('This will fetch interface details directly from the switch and update the database. Continue?')) {
        return;
      }
      this.syncing = true;
      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/interfaces/sync.php?switch_id=${this.switchId}`,
          {},
          {
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );
        if (response.data.success) {
          const count = response.data.synced_count ?? 0;
          this.$emit('show-message', `Synced ${count} interface${count === 1 ? '' : 's'} from switch`, 'success');
        } else {
          this.$emit('show-message', response.data.error || 'Failed to sync interfaces', 'error');
        }
        await this.loadInterfaces();
      } catch (error) {
        this.$emit('show-message', 'Failed to sync interfaces: ' + (error.response?.data?.error || error.message), 'error');
      } finally {
        this.syncing = false;
      }
    },
    openConfigureModal(interfaceRow) {
      // Don't allow configuring port channel members
      if (interfaceRow.is_port_channel_member) {
        this.$emit('show-message', 'Cannot edit interfaces that are members of a port channel. Remove from port channel first.', 'warning');
        return;
      }
      this.validationErrors = [];
      this.selectedInterface = interfaceRow;
      this.form.interface = interfaceRow.interface_name;
      this.form.adminState = (interfaceRow.admin_status && interfaceRow.admin_status !== 'unknown')
        ? interfaceRow.admin_status
        : 'up';
      this.form.mode = interfaceRow.mode && interfaceRow.mode !== 'unknown' ? interfaceRow.mode : 'access';
      // Default access VLAN to 1 if missing/empty
      const accessVlan = interfaceRow.vlan_id;
      this.form.accessVlan = (this.form.mode === 'access')
        ? (accessVlan || 1)
        : '';
      this.form.trunkVlans = interfaceRow.trunk_vlans || '';
      this.form.description = interfaceRow.description || '';
      this.form.customTag = interfaceRow.custom_tag || '';
      this.showConfigureModal = true;
    },
    // VLAN mode and trunk editing disabled in this UI by request
    async configureInterface() {
      this.validationErrors = [];
      if (!this.csrfToken) {
        this.$emit('show-message', 'CSRF token not available', 'error');
        return;
      }
      if (!this.form.interface) {
        this.validationErrors.push('Interface name is required');
      }
      // Only description and custom tag are editable from this UI

      if (this.validationErrors.length > 0) return;

      this.saving = true;
      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/interfaces/configure.php?switch_id=${this.switchId}`,
          {
            csrf_token: this.csrfToken,
            interface: this.form.interface,
            admin_state: this.form.adminState || null,
            description: this.form.description || null,
            custom_tag: this.form.customTag || null
          },
          {
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );
        if (response.data.success) {
          this.$emit('show-message', response.data.message || 'Interface configured successfully', 'success');
          this.showConfigureModal = false;
          // Set flag to indicate we just saved, so loadInterfaces won't fall back to live data
          this.justSaved = true;
          // Reload from DB cache (which was just updated by configure.php)
          await this.loadInterfaces();
          this.$emit('config-changed');
        } else {
          this.$emit('show-message', response.data.error || 'Failed to configure interface', 'error');
        }
      } catch (error) {
        this.$emit('show-message', 'Failed to configure interface: ' + (error.response?.data?.error || error.message), 'error');
      } finally {
        this.saving = false;
      }
    },
    modeBadgeClass(mode) {
      if (!mode) return 'bg-secondary';
      switch (mode.toLowerCase()) {
        case 'access': return 'bg-primary';
        case 'trunk': return 'bg-warning text-dark';
        case 'routed': return 'bg-info text-dark';
        default: return 'bg-secondary';
      }
    },
    statusBadgeClass(status) {
      if (!status) return 'bg-secondary';
      switch (status.toLowerCase()) {
        case 'up': return 'bg-success';
        case 'down': return 'bg-danger';
        default: return 'bg-secondary';
      }
    },
    // Sorting helpers
    interfaceTypeRank(name) {
      const n = name.toLowerCase();
      if (n.startsWith('ethernet')) return 1;
      if (n.startsWith('et')) return 1; // short form
      if (n.startsWith('management')) return 2;
      if (n.startsWith('ma')) return 2;
      if (n.startsWith('port-channel') || n.startsWith('portchannel') || n.startsWith('po')) return 3;
      if (n.startsWith('vlan')) return 4;
      if (n.startsWith('loopback') || n.startsWith('lo')) return 5;
      if (n.startsWith('tunnel') || n.startsWith('tu')) return 6;
      return 99;
    },
    extractNumbers(name) {
      // Grab all integer sequences to create a numeric tuple (e.g., Ethernet1/2 -> [1,2])
      const nums = [];
      const re = /(\d+)/g;
      let m;
      while ((m = re.exec(name)) !== null) {
        nums.push(parseInt(m[1], 10));
      }
      return nums.length ? nums : [Number.MAX_SAFE_INTEGER];
    },
    compareInterfaces(a, b) {
      const aRank = this.interfaceTypeRank(a.interface_name || '');
      const bRank = this.interfaceTypeRank(b.interface_name || '');
      if (aRank !== bRank) return aRank - bRank;
      // Same type: compare numeric components
      const aNums = this.extractNumbers(a.interface_name || '');
      const bNums = this.extractNumbers(b.interface_name || '');
      const len = Math.max(aNums.length, bNums.length);
      for (let i = 0; i < len; i++) {
        const av = aNums[i] ?? -1;
        const bv = bNums[i] ?? -1;
        if (av !== bv) return av - bv;
      }
      // Fallback to string compare
      return (a.interface_name || '').localeCompare(b.interface_name || '');
    },
    formatSpeed(speed) {
      if (!speed || speed === '-' || speed === null) return '-';
      // Speed is in bits per second (bps)
      const speedNum = typeof speed === 'string' ? parseFloat(speed.replace(/[^0-9.]/g, '')) : parseFloat(speed);
      if (isNaN(speedNum) || speedNum === 0) return '-';
      
      // Convert to Gbps
      const gbps = speedNum / 1000000000;
      if (gbps >= 1) {
        // Show as Gb/s
        return gbps % 1 === 0 ? `${gbps}Gb/s` : `${gbps.toFixed(2)}Gb/s`;
      } else {
        // Show as Mbps if less than 1 Gbps
        const mbps = speedNum / 1000000;
        return mbps % 1 === 0 ? `${mbps}Mb/s` : `${mbps.toFixed(2)}Mb/s`;
      }
    },
    async setAdminState(interfaceRow, state) {
      if (!this.canManageInterfaces) return;
      if (!this.csrfToken) {
        this.$emit('show-message', 'CSRF token not available', 'error');
        return;
      }
      const iface = interfaceRow?.interface_name;
      if (!iface) return;

      this.saving = true;
      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/interfaces/configure.php?switch_id=${this.switchId}`,
          {
            csrf_token: this.csrfToken,
            interface: iface,
            admin_state: state
          },
          { withCredentials: true, headers: { 'Content-Type': 'application/json' } }
        );
        if (response.data?.success) {
          this.$emit('show-message', `Set ${iface} admin ${state === 'down' ? 'down (shutdown)' : 'up (no shutdown)'}`, 'success');
          this.justSaved = true;
          await this.loadInterfaces();
          this.$emit('config-changed');
        } else {
          this.$emit('show-message', response.data?.error || 'Failed to change admin state', 'error');
        }
      } catch (e) {
        this.$emit('show-message', e.response?.data?.error || e.message, 'error');
      } finally {
        this.saving = false;
      }
    },
    async openTransceiverModal(interfaceRow) {
      this.selectedTransceiverInterface = interfaceRow.interface_name;
      this.transceiverData = null;
      this.loadingTransceiver = true;
      this.showTransceiverModal = true;
      
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/interfaces/transceiver.php`, {
          params: {
            switch_id: this.switchId,
            interface: interfaceRow.interface_name
          },
          withCredentials: true
        });
        
        if (response.data.success) {
          this.transceiverData = response.data.transceiver;
          
          // If data is empty or invalid, show warning
          if (!this.transceiverData || (typeof this.transceiverData === 'object' && Object.keys(this.transceiverData).length === 0)) {
            // Data is empty, will show appropriate message in UI
          }
        } else {
          this.$emit('show-message', response.data.error || 'Failed to load transceiver details', 'error');
          this.showTransceiverModal = false;
        }
      } catch (error) {
        this.$emit('show-message', 'Failed to load transceiver details: ' + (error.response?.data?.error || error.message), 'error');
        this.showTransceiverModal = false;
      } finally {
        this.loadingTransceiver = false;
      }
    },
    closeTransceiverModal() {
      this.showTransceiverModal = false;
      this.selectedTransceiverInterface = null;
      this.transceiverData = null;
    },
    normalizeTransceiverData(data) {
      // Handle various eAPI response structures for transceiver data
      if (!data || typeof data !== 'object') {
        return {};
      }
      
      // If data is directly a transceiver object (has temperature, voltage, etc.)
      const transceiverIndicators = ['temperature', 'voltage', 'txPower', 'tx_power', 'rxPower', 'rx_power', 
                                     'temp', 'temp_c', 'serialNumber', 'serial_number', 'partNumber', 'part_number',
                                     'biasCurrent', 'bias_current', 'wavelength'];
      const hasTransceiverFields = transceiverIndicators.some(key => data[key] !== undefined);
      
      if (hasTransceiverFields) {
        return data;
      }
      
      // If data is nested in an interface key
      if (this.selectedTransceiverInterface) {
        const interfaceName = this.selectedTransceiverInterface;
        const ifaceLower = interfaceName.toLowerCase().trim();
        const ifaceClean = ifaceLower.replace(/[\s\-_]/g, '');
        
        // Try exact match
        if (data[interfaceName] && typeof data[interfaceName] === 'object') {
          return data[interfaceName];
        }
        
        // Try case-insensitive match
        const keys = Object.keys(data);
        for (const key of keys) {
          const keyLower = key.toLowerCase().trim();
          const keyClean = keyLower.replace(/[\s\-_]/g, '');
          
          // Exact match (case-insensitive)
          if (keyLower === ifaceLower || keyClean === ifaceClean) {
            if (typeof data[key] === 'object' && data[key] !== null) {
              return data[key];
            }
          }
          
          // Partial match
          if (keyLower.includes(ifaceLower) || ifaceLower.includes(keyLower) ||
              keyClean.includes(ifaceClean) || ifaceClean.includes(keyClean)) {
            if (typeof data[key] === 'object' && data[key] !== null) {
              // Verify it looks like transceiver data
              const testData = data[key];
              if (transceiverIndicators.some(k => testData[k] !== undefined) ||
                  Object.keys(testData).length > 0) {
                return testData;
              }
            }
          }
        }
      }
      
      // Try common nested keys
      if (data.transceiver && typeof data.transceiver === 'object') return data.transceiver;
      if (data.dom && typeof data.dom === 'object') return data.dom;
      if (data.optical && typeof data.optical === 'object') return data.optical;
      if (data.interfaces && typeof data.interfaces === 'object') {
        // If interfaces key exists, try to find our interface
        if (this.selectedTransceiverInterface) {
          const ifaceName = this.selectedTransceiverInterface.toLowerCase().trim();
          for (const key in data.interfaces) {
            if (key.toLowerCase().trim() === ifaceName) {
              return data.interfaces[key];
            }
          }
        }
      }
      
      // Check if data has interface-like keys (Ethernet*, Management*, etc.)
      const interfaceKeys = Object.keys(data).filter(k => 
        /^(ethernet|management|port-channel)/i.test(k) && 
        typeof data[k] === 'object' &&
        data[k] !== null
      );
      
      if (interfaceKeys.length > 0) {
        if (this.selectedTransceiverInterface) {
          const ifaceName = this.selectedTransceiverInterface.toLowerCase().trim();
          const match = interfaceKeys.find(k => {
            const kLower = k.toLowerCase().trim();
            return kLower === ifaceName || 
                   kLower.includes(ifaceName) || 
                   ifaceName.includes(kLower);
          });
          if (match) return data[match];
        }
        // If no match found but we have interface keys, return first one
        if (interfaceKeys.length === 1) {
          return data[interfaceKeys[0]];
        }
      }
      
      // Return first object value if it's a nested structure (skip arrays)
      const values = Object.values(data);
      const objectValues = values.filter(v => 
        v !== null && 
        typeof v === 'object' && 
        !Array.isArray(v) &&
        Object.keys(v).length > 0
      );
      
      if (objectValues.length > 0) {
        // Check if first value looks like transceiver data
        const firstValue = objectValues[0];
        if (transceiverIndicators.some(k => firstValue[k] !== undefined)) {
          return firstValue;
        }
        // If it has meaningful keys, return it
        if (Object.keys(firstValue).length > 2) {
          return firstValue;
        }
      }
      
      // Last resort: return data as-is if it's not empty
      if (Object.keys(data).length > 0) {
        return data;
      }
      
      return {};
    },
    formatTransceiverKey(key) {
      // Convert camelCase or snake_case to readable format
      const replacements = {
        'temperature': 'Temperature (°C)',
        'temp': 'Temperature (°C)',
        'voltage': 'Voltage (V)',
        'txPower': 'TX Power (dBm)',
        'tx_power': 'TX Power (dBm)',
        'rxPower': 'RX Power (dBm)',
        'rx_power': 'RX Power (dBm)',
        'biasCurrent': 'Bias Current (mA)',
        'bias_current': 'Bias Current (mA)',
        'serialNumber': 'Serial Number',
        'serial_number': 'Serial Number',
        'partNumber': 'Part Number',
        'part_number': 'Part Number',
        'vendor': 'Vendor',
        'manufacturer': 'Manufacturer',
        'model': 'Model',
        'type': 'Type',
        'wavelength': 'Wavelength (nm)',
        'maxTemperature': 'Max Temperature (°C)',
        'max_temperature': 'Max Temperature (°C)',
        'minTemperature': 'Min Temperature (°C)',
        'min_temperature': 'Min Temperature (°C)'
      };
      
      if (replacements[key]) return replacements[key];
      
      // Format camelCase
      return key.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase());
    },
    formatTransceiverValue(key, value) {
      if (value === null || value === undefined) return '-';
      
      // Format numeric values with appropriate units
      const numValue = parseFloat(value);
      if (!isNaN(numValue)) {
        if (key.toLowerCase().includes('temp') || key.toLowerCase().includes('temperature')) {
          return numValue.toFixed(2) + '°C';
        }
        if (key.toLowerCase().includes('voltage')) {
          return numValue.toFixed(3) + 'V';
        }
        if (key.toLowerCase().includes('power')) {
          return numValue.toFixed(2) + 'dBm';
        }
        if (key.toLowerCase().includes('current')) {
          return numValue.toFixed(2) + 'mA';
        }
        if (key.toLowerCase().includes('wavelength')) {
          return numValue.toFixed(0) + 'nm';
        }
        return numValue.toString();
      }
      
      return String(value);
    },
    hasValidTransceiver(iface) {
      // Check if interface has valid transceiver temperature
      if (!iface.transceiver_temp || iface.transceiver_temp === null || iface.transceiver_temp === undefined) {
        return false;
      }
      
      // Check if port type indicates "Not Present"
      const portType = (iface.interfaceType || iface.port_type || '').toLowerCase().trim();
      const notPresentIndicators = ['not present', 'notpresent', 'none', 'n/a'];
      
      for (const indicator of notPresentIndicators) {
        if (portType.includes(indicator)) {
          return false;
        }
      }
      
      // Check if temperature is valid (not 0, not negative, reasonable range)
      const temp = parseFloat(iface.transceiver_temp);
      if (isNaN(temp) || temp <= 0 || temp >= 200) {
        return false;
      }
      
      return true;
    },
    flattenTransceiverData(data, prefix = '') {
      // Flatten nested objects for display
      const flattened = {};
      
      if (!data || typeof data !== 'object' || Array.isArray(data)) {
        return flattened;
      }
      
      for (const [key, value] of Object.entries(data)) {
        const newKey = prefix ? `${prefix}.${key}` : key;
        
        if (value === null || value === undefined) {
          flattened[newKey] = '-';
        } else if (typeof value === 'object' && !Array.isArray(value)) {
          // Recursively flatten nested objects
          const nested = this.flattenTransceiverData(value, newKey);
          Object.assign(flattened, nested);
        } else if (Array.isArray(value)) {
          flattened[newKey] = value.join(', ');
        } else {
          flattened[newKey] = value;
        }
      }
      
      return flattened;
    }
  },
  template: `
    <div class="interface-management">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="fas fa-network-wired me-2"></i>
            Interfaces
          </h5>
          <div>
            <button
              class="btn btn-secondary btn-sm me-2"
              @click="syncInterfaces"
              :disabled="!canManageInterfaces || syncing"
            >
              <span v-if="syncing" class="spinner-border spinner-border-sm me-1" role="status"></span>
              <i class="fas fa-download me-1"></i>
              {{ syncing ? 'Syncing...' : 'Sync from Switch' }}
            </button>
          </div>
        </div>

        <div class="card-body">
          <div v-if="error" class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i>
            {{ error }}
          </div>

          <div v-if="loading" class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
          </div>

          <div v-else>
            <div class="mb-3">
              <input
                type="text"
                class="form-control"
                placeholder="Search interfaces by name, status, mode, description..."
                v-model="searchTerm"
              />
            </div>

            <div v-if="filteredInterfaces.length === 0" class="alert alert-info">
              <i class="fas fa-info-circle me-2"></i>
              No interfaces found. Try syncing from the switch.
            </div>

            <div v-else class="table-responsive">
              <table class="table table-hover align-middle">
                <thead>
                  <tr>
                    <th>Interface</th>
                    <th>Admin</th>
                    <th>Oper</th>
                    <th>Mode</th>
                    <th>VLAN / Trunk</th>
                    <th>Speed</th>
                    <th>Type</th>
                    <th>Temperature</th>
                    <th>Bandwidth</th>
                    <th>Description</th>
                    <th>Tag</th>
                <th>Last Synced</th>
                <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr 
                    v-for="iface in filteredInterfaces" 
                    :key="iface.interface_name"
                    :style="iface.is_port_channel_member ? 'background-color: #e8f4f8;' : ''"
                  >
                    <td>
                      <strong>{{ iface.interface_name }}</strong>
                      <span v-if="iface.is_port_channel_member" class="badge bg-warning ms-2" title="Member of port channel">
                        <i class="fas fa-link me-1"></i>{{ iface.port_channel_name }}
                      </span>
                    </td>
                    <td>
                      <i v-if=\"(iface.admin_status || 'unknown').toLowerCase() === 'up'\" class=\"fas fa-toggle-on text-success\" title=\"Admin: up\"></i>
                      <i v-else-if=\"(iface.admin_status || 'unknown').toLowerCase() === 'down'\" class=\"fas fa-toggle-off text-danger\" title=\"Admin: down\"></i>
                      <i v-else class=\"fas fa-question-circle text-muted\" title=\"Admin: unknown\"></i>
                    </td>
                    <td>
                      <span :class="['badge', statusBadgeClass(iface.oper_status)]">
                        {{ iface.oper_status || 'unknown' }}
                      </span>
                    </td>
                    <td>
                      <span :class="['badge', modeBadgeClass(iface.mode)]">
                        {{ iface.mode || 'unknown' }}
                      </span>
                    </td>
                    <td>
                      <div v-if="iface.mode === 'access'">
                        <div>Untagged: <strong v-if="iface.vlan_id">VLAN {{ iface.vlan_id }}</strong><span v-else>-</span></div>
                        <div>Tagged: <span class="text-muted">None</span></div>
                      </div>
                      <div v-else-if="iface.mode === 'trunk'">
                        <div>Untagged: <strong v-if="iface.native_vlan_id">VLAN {{ iface.native_vlan_id }}</strong><span v-else>-</span></div>
                        <div>Tagged: <span v-if="iface.trunk_vlans">{{ iface.trunk_vlans }}</span><span v-else>-</span></div>
                      </div>
                      <span v-else class="text-muted">-</span>
                    </td>
                    <td>{{ formatSpeed(iface.speed) }}</td>
                    <td>{{ iface.interfaceType || iface.port_type || '-' }}</td>
                    <td>
                      <span v-if="hasValidTransceiver(iface)">
                        {{ parseFloat(iface.transceiver_temp).toFixed(2) }}°C
                      </span>
                      <span v-else class="text-muted">-</span>
                    </td>
                    <td>{{ formatSpeed(iface.bandwidth || iface.speed) }}</td>
                    <td>{{ iface.description || '-' }}</td>
                    <td>{{ iface.custom_tag || '-' }}</td>
                    <td>
                      <small class="text-muted">
                        {{ iface.last_synced ? new Date(iface.last_synced).toLocaleString() : '-' }}
                      </small>
                    </td>
                <td class="text-end">
                  <div class="btn-group">
                    <button
                      v-if=\"canManageInterfaces && (iface.admin_status || '').toLowerCase() === 'up' && !iface.is_port_channel_member\"
                      class="btn btn-sm btn-outline-danger"
                      :disabled="saving"
                      @click="setAdminState(iface, 'down')"
                      title="Shutdown (admin down)"
                    >
                      <i class="fas fa-power-off"></i>
                    </button>
                    <button
                      v-if=\"canManageInterfaces && (iface.admin_status || '').toLowerCase() === 'down' && !iface.is_port_channel_member\"
                      class="btn btn-sm btn-outline-success"
                      :disabled="saving"
                      @click="setAdminState(iface, 'up')"
                      title="No Shutdown (admin up)"
                    >
                      <i class="fas fa-play"></i>
                    </button>
                    <button
                      class="btn btn-sm btn-outline-secondary"
                      :disabled="iface.is_port_channel_member"
                      @click="openDetailsModal(iface)"
                      :title="iface.is_port_channel_member ? 'Cannot edit port channel members' : 'View/Edit details'"
                    >
                      <i class="fas fa-eye"></i>
                    </button>
                    <button
                      class="btn btn-sm btn-outline-info"
                      @click="openTransceiverModal(iface)"
                      title="View transceiver details"
                    >
                      <i class="fas fa-satellite-dish"></i>
                    </button>
                    <button
                      v-if="canManageInterfaces"
                      class="btn btn-sm btn-outline-primary"
                      :disabled="iface.is_port_channel_member || saving"
                      @click="openConfigureModal(iface)"
                      :title="iface.is_port_channel_member ? 'Cannot edit port channel members' : 'Configure interface'"
                    >
                      <i class="fas fa-edit"></i>
                    </button>
                  </div>
                </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

  <!-- Read-only Interface Details Modal -->
  <div v-if="showDetailsModal" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Interface Details - {{ detailsInterface?.interface_name }}</h5>
          <button type="button" class="btn-close" @click="showDetailsModal = false"></button>
        </div>
        <div class="modal-body">
          <!-- Status Section -->
          <div class="card mb-3">
            <div class="card-body d-flex justify-content-between align-items-center">
              <div>
                <div class="mb-1">
                  <span class="me-2"><strong>Admin:</strong></span>
                  <span :class="['badge', statusBadgeClass(detailsInterface?.admin_status)]">{{ detailsInterface?.admin_status || 'unknown' }}</span>
                </div>
                <div>
                  <span class="me-2"><strong>Oper:</strong></span>
                  <span :class="['badge', statusBadgeClass(detailsInterface?.oper_status)]">{{ detailsInterface?.oper_status || 'unknown' }}</span>
                </div>
              </div>
              <div class="text-end">
                <div><strong>Mode</strong></div>
                <span :class="['badge', modeBadgeClass(detailsInterface?.mode)]">{{ detailsInterface?.mode || 'unknown' }}</span>
              </div>
            </div>
          </div>

          <!-- VLAN Details Section -->
          <div class="card mb-3">
            <div class="card-header bg-light"><strong>VLAN Details</strong></div>
            <div class="card-body">
              <template v-if="detailsInterface?.mode === 'access'">
                <div class="row mb-2">
                  <div class="col-6"><strong>Access VLAN (untagged):</strong></div>
                  <div class="col-6 text-end">
                    <span v-if="detailsInterface?.vlan_id" class="badge bg-primary">VLAN {{ detailsInterface.vlan_id }}</span>
                    <span v-else class="text-muted">-</span>
                  </div>
                </div>
                <div class="row">
                  <div class="col-6"><strong>Tagged VLANs:</strong></div>
                  <div class="col-6 text-end"><span class="text-muted">None</span></div>
                </div>
              </template>
              <template v-else-if="detailsInterface?.mode === 'trunk'">
                <div class="row mb-2">
                  <div class="col-6"><strong>Native VLAN (untagged):</strong></div>
                  <div class="col-6 text-end">
                    <span v-if="detailsInterface?.native_vlan_id" class="badge bg-warning text-dark">VLAN {{ detailsInterface.native_vlan_id }}</span>
                    <span v-else class="text-muted">-</span>
                  </div>
                </div>
                <div class="row">
                  <div class="col-12"><strong>Allowed Tagged VLANs:</strong></div>
                  <div class="col-12 mt-1">
                    <template v-if="detailsInterface?.trunk_vlans">
                      <span
                        v-for="v in detailsInterface.trunk_vlans.split(',').map(s => s.trim()).filter(Boolean)"
                        :key="'v-' + v"
                        class="badge bg-secondary me-1 mb-1"
                      >
                        VLAN {{ v }}
                      </span>
                    </template>
                    <span v-else class="text-muted">-</span>
                  </div>
                </div>
              </template>
              <template v-else>
                <div class="text-muted">VLAN information not applicable for this mode.</div>
              </template>
            </div>
          </div>

          <!-- Attributes Section -->
          <div class="card">
            <div class="card-header bg-light"><strong>Attributes</strong></div>
            <div class="card-body">
              <div class="row mb-2">
                <div class="col-6"><strong>Speed:</strong></div>
                <div class="col-6 text-end">{{ formatSpeed(detailsInterface?.speed) }}</div>
              </div>
              <div class="row mb-2">
                <div class="col-6"><strong>Type:</strong></div>
                <div class="col-6 text-end">{{ detailsInterface?.interfaceType || detailsInterface?.port_type || '-' }}</div>
              </div>
              <div class="row mb-2">
                <div class="col-6"><strong>Link Status:</strong></div>
                <div class="col-6 text-end">{{ detailsInterface?.linkStatus || '-' }}</div>
              </div>
              <div class="row mb-2">
                <div class="col-6"><strong>Bandwidth:</strong></div>
                <div class="col-6 text-end">{{ detailsInterface?.bandwidth || '-' }}</div>
              </div>
              <div class="row mb-2">
                <div class="col-6"><strong>Description:</strong></div>
                <div class="col-6 text-end">{{ detailsInterface?.description || '-' }}</div>
              </div>
              <div class="row">
                <div class="col-6"><strong>Last Synced:</strong></div>
                <div class="col-6 text-end">{{ detailsInterface?.last_synced ? new Date(detailsInterface.last_synced).toLocaleString() : '-' }}</div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="showDetailsModal = false">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Configure Interface Modal -->
  <div v-if="showConfigureModal" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit Interface - {{ form.interface }}</h5>
          <button type="button" class="btn-close" @click="showConfigureModal = false"></button>
        </div>
        <div class="modal-body">
          <div v-if="validationErrors.length" class="alert alert-danger">
            <ul class="mb-0">
              <li v-for="(err, idx) in validationErrors" :key="'err-' + idx">{{ err }}</li>
            </ul>
          </div>

          <div class="alert alert-info small">
            Port mode and VLANs are read-only here. Use the VLAN Matrix page to change access/trunk settings and VLAN assignments.
          </div>

          <div class="mb-3">
            <label class="form-label">Admin State</label>
            <select class="form-select" v-model="form.adminState">
              <option value="up">Up (no shutdown)</option>
              <option value="down">Down (shutdown)</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Description</label>
            <input type="text" class="form-control" v-model="form.description" maxlength="255" placeholder="Optional interface description" />
          </div>

          <div class="mb-3">
            <label class="form-label">Custom Tag</label>
            <input type="text" class="form-control" v-model="form.customTag" maxlength="64" placeholder="e.g., uplink, core, camera, voip" />
            <small class="text-muted">Stored in database for reporting/filters (not pushed to device)</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="showConfigureModal = false">Cancel</button>
          <button type="button" class="btn btn-primary" :disabled="saving" @click="configureInterface">
            <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
            {{ saving ? 'Saving...' : 'Save Changes' }}
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Transceiver Details Modal -->
  <div v-if="showTransceiverModal" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="fas fa-satellite-dish me-2"></i>
            Transceiver Details - {{ selectedTransceiverInterface }}
          </h5>
          <button type="button" class="btn-close" @click="closeTransceiverModal"></button>
        </div>
        <div class="modal-body">
          <div v-if="loadingTransceiver" class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
          </div>
          <div v-else-if="transceiverData">
            <div v-if="Object.keys(normalizeTransceiverData(transceiverData)).length === 0" class="alert alert-warning">
              <i class="fas fa-exclamation-triangle me-2"></i>
              Transceiver data received but could not be parsed. This interface may not have a transceiver module installed.
            </div>
            <div v-else>
              <div class="table-responsive">
                <table class="table table-sm table-bordered">
                  <thead>
                    <tr>
                      <th>Parameter</th>
                      <th>Value</th>
                    </tr>
                  </thead>
                  <tbody>
                    <template v-for="(value, key) in flattenTransceiverData(normalizeTransceiverData(transceiverData))" :key="key">
                      <tr>
                        <td><strong>{{ formatTransceiverKey(key) }}</strong></td>
                        <td>{{ formatTransceiverValue(key, value) }}</td>
                      </tr>
                    </template>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div v-else class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No transceiver data available for this interface.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="closeTransceiverModal">Close</button>
        </div>
      </div>
    </div>
  </div>

    </div>
  `
};


