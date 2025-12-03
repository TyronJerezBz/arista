// Port Channel Management Component
const API_BASE_URL = '/arista/api';

export default {
  name: 'PortChannelManagement',
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
      portChannels: [],
      interfaces: [],
      vlans: [],
      loading: false,
      saving: false,
      deleting: false,
      error: null,
      searchTerm: '',
      showCreateModal: false,
      showConfigureModal: false,
      showMemberModal: false,
      showVlanModal: false,
      showDeleteConfirm: false,
      showLoadBalanceModal: false,
      selectedPortChannel: null,
      portChannelToDelete: null,
      vlanConfigPortChannel: null,
      loadBalanceStats: null,
      loadingLoadBalance: false,
      newPortChannel: {
        port_channel_number: '',
        mode: 'trunk',
        vlan_id: null,
        native_vlan_id: null,
        trunk_vlans: '',
        lacp_mode: 'active',
        description: '',
        members: []
      },
      configurePortChannel: {
        mode: 'trunk',
        vlan_id: null,
        native_vlan_id: null,
        trunk_vlans: '',
        description: '',
        admin_state: null
      },
      vlanConfiguration: {
        mode: 'trunk',
        vlan_id: null,
        native_vlan_id: null,
        trunk_vlans: ''
      },
      selectedTrunkVlans: [], // Array of VLAN IDs selected for trunk mode
      memberInterface: '',
      memberLacpMode: 'active',
      memberAction: 'add', // 'add' or 'remove'
      validationErrors: []
    }
  },
  computed: {
    canManagePortChannels() {
      return this.user.role === 'admin' || this.user.role === 'operator';
    },
    filteredPortChannels() {
      if (!this.searchTerm) return this.portChannels;
      const term = this.searchTerm.toLowerCase();
      return this.portChannels.filter(pc =>
        pc.port_channel_name.toLowerCase().includes(term) ||
        (pc.description && pc.description.toLowerCase().includes(term)) ||
        (pc.mode && pc.mode.toLowerCase().includes(term))
      );
    },
    availableInterfaces() {
      // Filter out interfaces that are already members of port channels
      const memberInterfaces = new Set();
      this.portChannels.forEach(pc => {
        if (pc.members && Array.isArray(pc.members)) {
          pc.members.forEach(m => {
            if (m.interface_name) memberInterfaces.add(m.interface_name);
          });
        }
      });
      
      const filtered = this.interfaces.filter(iface => {
        const name = iface.interface_name || iface.name;
        // Exclude port channels themselves and already-used interfaces
        return name && 
               !name.toLowerCase().startsWith('port-channel') &&
               !name.toLowerCase().startsWith('portchannel') &&
               !memberInterfaces.has(name);
      });
      
      // Sort interfaces in correct order
      return filtered.sort(this.compareInterfaces);
    },
    vlanDisplayText() {
      if (!this.vlanConfigPortChannel) return '';
      const pc = this.vlanConfigPortChannel;
      const mode = (pc.mode || 'unknown').toUpperCase();
      
      if (pc.mode === 'access' && pc.vlan_id) {
        return `${mode} - VLAN ${pc.vlan_id}`;
      } else if (pc.mode === 'trunk') {
        let display = `${mode}`;
        if (pc.native_vlan_id) {
          display += ` - Native: ${pc.native_vlan_id}`;
        }
        if (pc.trunk_vlans) {
          const vlanCount = pc.trunk_vlans.split(',').filter(v => v.trim()).length;
          display += `, Tagged: ${vlanCount} VLAN${vlanCount !== 1 ? 's' : ''}`;
        }
        return display;
      } else if (pc.mode === 'routed') {
        return `${mode}`;
      }
      return mode;
    }
  },
  mounted() {
    this.loadPortChannels();
    this.loadInterfaces();
    this.loadVlans();
    this.$nextTick(() => {
      this.initializeTooltips();
    });
  },
  updated() {
    // Re-initialize tooltips after DOM updates (especially when modals open)
    this.$nextTick(() => {
      this.initializeTooltips();
    });
  },
  methods: {
    async loadPortChannels() {
      this.loading = true;
      this.error = null;
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/port-channels/list.php`, {
          params: { switch_id: this.switchId },
          withCredentials: true
        });
        if (response.data.success) {
          this.portChannels = response.data.port_channels || [];
        } else {
          this.error = response.data.error || 'Failed to load port channels';
        }
      } catch (error) {
        this.error = 'Failed to load port channels: ' + (error.response?.data?.error || error.message);
      } finally {
        this.loading = false;
      }
    },
    async loadInterfaces() {
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/interfaces/list.php`, {
          params: { switch_id: this.switchId },
          withCredentials: true
        });
        if (response.data.success) {
          this.interfaces = response.data.interfaces || [];
        }
      } catch (error) {
        this.interfaces = [];
      }
    },
    async loadVlans() {
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/vlans/list.php`, {
          params: { switch_id: this.switchId },
          withCredentials: true
        });
        if (response.data?.success) {
          this.vlans = response.data.vlans || [];
        }
      } catch (error) {
        this.vlans = [];
      }
    },
    openCreateModal() {
      this.newPortChannel = {
        port_channel_number: '',
        mode: 'trunk',
        vlan_id: null,
        native_vlan_id: null,
        trunk_vlans: '',
        lacp_mode: 'active',
        description: '',
        members: []
      };
      this.validationErrors = [];
      this.showCreateModal = true;
      this.$nextTick(() => {
        this.initializeTooltips();
      });
    },
    openConfigureModal(portChannel) {
      this.selectedPortChannel = portChannel;
      this.configurePortChannel = {
        mode: portChannel.mode || 'trunk',
        vlan_id: portChannel.vlan_id || null,
        native_vlan_id: portChannel.native_vlan_id || null,
        trunk_vlans: portChannel.trunk_vlans || '',
        description: portChannel.description || '',
        admin_state: null
      };
      this.validationErrors = [];
      this.showConfigureModal = true;
      this.$nextTick(() => {
        this.initializeTooltips();
      });
    },
    openMemberModal(portChannel, action = 'add') {
      this.selectedPortChannel = portChannel;
      this.memberAction = action;
      this.memberInterface = '';
      this.memberLacpMode = portChannel.lacp_mode || 'active';
      this.validationErrors = [];
      this.showMemberModal = true;
      this.$nextTick(() => {
        this.initializeTooltips();
      });
    },
    async createPortChannel() {
      this.validationErrors = [];
      if (!this.csrfToken) {
        this.validationErrors.push('CSRF token not available');
        return;
      }
      
      const errors = [];
      if (!this.newPortChannel.port_channel_number || !/^\d+$/.test(this.newPortChannel.port_channel_number)) {
        errors.push('Port channel number is required and must be a number');
      }
      
      if (this.newPortChannel.mode === 'access' && !this.newPortChannel.vlan_id) {
        errors.push('VLAN ID is required for access mode');
      }
      
      if (errors.length > 0) {
        this.validationErrors = errors;
        return;
      }
      
      this.saving = true;
      try {
        const payload = {
          csrf_token: this.csrfToken,
          port_channel_number: parseInt(this.newPortChannel.port_channel_number),
          mode: this.newPortChannel.mode,
          lacp_mode: this.newPortChannel.lacp_mode,
          description: this.newPortChannel.description || null,
          members: this.newPortChannel.members || []
        };
        
        if (this.newPortChannel.mode === 'access') {
          payload.vlan_id = parseInt(this.newPortChannel.vlan_id);
        } else if (this.newPortChannel.mode === 'trunk') {
          if (this.newPortChannel.native_vlan_id) {
            payload.native_vlan_id = parseInt(this.newPortChannel.native_vlan_id);
          }
          if (this.newPortChannel.trunk_vlans) {
            payload.trunk_vlans = this.newPortChannel.trunk_vlans;
          }
        }
        
        const response = await axios.post(
          `${API_BASE_URL}/switches/port-channels/create.php?switch_id=${this.switchId}`,
          payload,
          {
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );
        
        if (response.data.success) {
          this.$emit('show-message', 'Port channel created successfully', 'success');
          this.$emit('config-changed');
          this.showCreateModal = false;
          await this.loadPortChannels();
          await this.loadInterfaces(); // Refresh to update available interfaces
        } else {
          this.validationErrors = [response.data.error || 'Failed to create port channel'];
        }
      } catch (error) {
        this.validationErrors = [error.response?.data?.error || error.message || 'Failed to create port channel'];
      } finally {
        this.saving = false;
      }
    },
    async configurePortChannelSubmit() {
      this.validationErrors = [];
      if (!this.csrfToken || !this.selectedPortChannel) {
        this.validationErrors.push('Invalid request');
        return;
      }
      
      this.saving = true;
      try {
        const payload = {
          csrf_token: this.csrfToken,
          mode: this.configurePortChannel.mode,
          description: this.configurePortChannel.description || null
        };
        
        if (this.configurePortChannel.mode === 'access' && this.configurePortChannel.vlan_id) {
          payload.vlan_id = parseInt(this.configurePortChannel.vlan_id);
        } else if (this.configurePortChannel.mode === 'trunk') {
          if (this.configurePortChannel.native_vlan_id) {
            payload.native_vlan_id = parseInt(this.configurePortChannel.native_vlan_id);
          }
          if (this.configurePortChannel.trunk_vlans) {
            payload.trunk_vlans = this.configurePortChannel.trunk_vlans;
          }
        }
        
        if (this.configurePortChannel.admin_state) {
          payload.admin_state = this.configurePortChannel.admin_state;
        }
        
        const response = await axios.post(
          `${API_BASE_URL}/switches/port-channels/configure.php?switch_id=${this.switchId}&port_channel_id=${this.selectedPortChannel.id}`,
          payload,
          {
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );
        
        if (response.data.success) {
          this.$emit('show-message', 'Port channel configured successfully', 'success');
          this.$emit('config-changed');
          this.showConfigureModal = false;
          await this.loadPortChannels();
        } else {
          this.validationErrors = [response.data.error || 'Failed to configure port channel'];
        }
      } catch (error) {
        this.validationErrors = [error.response?.data?.error || error.message || 'Failed to configure port channel'];
      } finally {
        this.saving = false;
      }
    },
    async manageMember() {
      this.validationErrors = [];
      if (!this.csrfToken || !this.selectedPortChannel || !this.memberInterface) {
        this.validationErrors.push('Interface name is required');
        return;
      }
      
      this.saving = true;
      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/port-channels/members.php?switch_id=${this.switchId}&port_channel_id=${this.selectedPortChannel.id}&action=${this.memberAction}`,
          {
            csrf_token: this.csrfToken,
            interface_name: this.memberInterface,
            lacp_mode: this.memberAction === 'add' ? this.memberLacpMode : undefined
          },
          {
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );
        
        if (response.data.success) {
          this.$emit('show-message', `Member ${this.memberAction === 'add' ? 'added' : 'removed'} successfully`, 'success');
          this.$emit('config-changed');
          this.showMemberModal = false;
          await this.loadPortChannels();
          await this.loadInterfaces(); // Refresh to update available interfaces
        } else {
          this.validationErrors = [response.data.error || `Failed to ${this.memberAction} member`];
        }
      } catch (error) {
        this.validationErrors = [error.response?.data?.error || error.message || `Failed to ${this.memberAction} member`];
      } finally {
        this.saving = false;
      }
    },
    confirmDelete(portChannel) {
      this.portChannelToDelete = portChannel;
      this.showDeleteConfirm = true;
    },
    async deletePortChannel() {
      if (!this.csrfToken || !this.portChannelToDelete) {
        return;
      }
      
      this.deleting = true;
      try {
        const response = await axios.delete(
          `${API_BASE_URL}/switches/port-channels/delete.php?switch_id=${this.switchId}&port_channel_id=${this.portChannelToDelete.id}&csrf_token=${encodeURIComponent(this.csrfToken)}`,
          {
            withCredentials: true
          }
        );
        
        if (response.data.success) {
          this.$emit('show-message', 'Port channel deleted successfully', 'success');
          this.$emit('config-changed');
          this.showDeleteConfirm = false;
          this.portChannelToDelete = null;
          await this.loadPortChannels();
          await this.loadInterfaces(); // Refresh to update available interfaces
        } else {
          this.$emit('show-message', response.data.error || 'Failed to delete port channel', 'error');
        }
      } catch (error) {
        this.$emit('show-message', error.response?.data?.error || error.message || 'Failed to delete port channel', 'error');
      } finally {
        this.deleting = false;
      }
    },
    toggleMemberSelection(interfaceName) {
      const idx = this.newPortChannel.members.indexOf(interfaceName);
      if (idx >= 0) {
        this.newPortChannel.members.splice(idx, 1);
      } else {
        this.newPortChannel.members.push(interfaceName);
      }
    },
    isMemberSelected(interfaceName) {
      return this.newPortChannel.members.indexOf(interfaceName) >= 0;
    },
    openVlanModal(portChannel) {
      this.vlanConfigPortChannel = portChannel;
      this.vlanConfiguration = {
        mode: portChannel.mode || 'trunk',
        vlan_id: portChannel.vlan_id || null,
        native_vlan_id: portChannel.native_vlan_id || null,
        trunk_vlans: portChannel.trunk_vlans || ''
      };
      // Convert trunk_vlans string to array for the checkbox selector
      this.selectedTrunkVlans = [];
      if (portChannel.trunk_vlans) {
        this.selectedTrunkVlans = portChannel.trunk_vlans
          .split(',')
          .map(v => parseInt(v.trim(), 10))
          .filter(v => !isNaN(v));
      }
      this.validationErrors = [];
      this.showVlanModal = true;
    },
    closeVlanModal() {
      this.showVlanModal = false;
      this.vlanConfigPortChannel = null;
      this.validationErrors = [];
    },
    async openLoadBalanceModal(portChannel) {
      this.selectedPortChannel = portChannel;
      this.loadBalanceStats = null;
      this.loadingLoadBalance = true;
      this.showLoadBalanceModal = true;
      
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/port-channels/load-balance.php`, {
          params: {
            switch_id: this.switchId,
            port_channel: portChannel.port_channel_name
          },
          withCredentials: true
        });
        
        if (response.data.success) {
          this.loadBalanceStats = response.data.load_balance_stats;
        } else {
          this.$emit('show-message', response.data.error || 'Failed to load balance statistics', 'error');
          this.showLoadBalanceModal = false;
        }
      } catch (error) {
        this.$emit('show-message', 'Failed to load balance statistics: ' + (error.response?.data?.error || error.message), 'error');
        this.showLoadBalanceModal = false;
      } finally {
        this.loadingLoadBalance = false;
      }
    },
    closeLoadBalanceModal() {
      this.showLoadBalanceModal = false;
      this.selectedPortChannel = null;
      this.loadBalanceStats = null;
    },
    formatLoadBalanceStats(stats) {
      if (!stats || typeof stats !== 'object') {
        return 'No statistics available';
      }
      
      // Try to format common structures
      let formatted = '';
      
      // If stats has a specific structure, format it nicely
      if (stats.output) {
        // If it's text output, show it as-is
        formatted = stats.output;
      } else if (stats.portChannels) {
        // If it's structured data, format it
        formatted = JSON.stringify(stats, null, 2);
      } else {
        // Default: show as formatted JSON
        formatted = JSON.stringify(stats, null, 2);
      }
      
      return formatted;
    },
    async saveVlanConfiguration() {
      this.validationErrors = [];
      
      if (!this.csrfToken || !this.vlanConfigPortChannel) {
        this.validationErrors.push('Configuration error');
        return;
      }
      
      // Convert selected VLANs array to comma-separated string
      const trunkVlansString = this.selectedTrunkVlans.length > 0 
        ? this.selectedTrunkVlans.sort((a, b) => a - b).join(',')
        : '';
      
      // Validate based on mode
      if (this.vlanConfiguration.mode === 'access') {
        if (!this.vlanConfiguration.vlan_id) {
          this.validationErrors.push('Access mode requires a VLAN ID');
          return;
        }
      } else if (this.vlanConfiguration.mode === 'trunk') {
        if (!this.vlanConfiguration.native_vlan_id && this.selectedTrunkVlans.length === 0) {
          this.validationErrors.push('Trunk mode requires at least a native VLAN or tagged VLANs');
          return;
        }
      }
      
      this.saving = true;
      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/port-channels/configure.php?switch_id=${this.switchId}&port_channel_id=${this.vlanConfigPortChannel.id}`,
          {
            csrf_token: this.csrfToken,
            mode: this.vlanConfiguration.mode,
            vlan_id: this.vlanConfiguration.vlan_id || null,
            native_vlan_id: this.vlanConfiguration.native_vlan_id || null,
            trunk_vlans: trunkVlansString || null
          },
          {
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );
        
        if (response.data.success) {
          this.$emit('show-message', 'VLAN configuration updated successfully', 'success');
          this.$emit('config-changed');
          this.closeVlanModal();
          await this.loadPortChannels();
        } else {
          this.validationErrors = response.data.errors || [response.data.error || 'Failed to update VLAN configuration'];
        }
      } catch (error) {
        this.validationErrors = error.response?.data?.errors || [error.response?.data?.error || error.message || 'Failed to update VLAN configuration'];
      } finally {
        this.saving = false;
      }
    },
    // Sorting helpers (same as InterfaceManagement)
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
      const aName = a.interface_name || a.name || '';
      const bName = b.interface_name || b.name || '';
      const aRank = this.interfaceTypeRank(aName);
      const bRank = this.interfaceTypeRank(bName);
      if (aRank !== bRank) return aRank - bRank;
      // Same type: compare numeric components
      const aNums = this.extractNumbers(aName);
      const bNums = this.extractNumbers(bName);
      const len = Math.max(aNums.length, bNums.length);
      for (let i = 0; i < len; i++) {
        const av = aNums[i] ?? -1;
        const bv = bNums[i] ?? -1;
        if (av !== bv) return av - bv;
      }
      // Fallback to string compare
      return aName.localeCompare(bName);
    },
    initializeTooltips() {
      // Initialize Bootstrap tooltips
      try {
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
          this.$nextTick(() => {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach(function (tooltipTriggerEl) {
              try {
                // Destroy existing tooltip if it exists
                const existingTooltip = bootstrap.Tooltip.getInstance(tooltipTriggerEl);
                if (existingTooltip) {
                  existingTooltip.dispose();
                }
                // Create new tooltip
                new bootstrap.Tooltip(tooltipTriggerEl);
              } catch (e) {
                // Ignore individual tooltip initialization errors
              }
            });
          });
        }
      } catch (e) {
        // Ignore tooltip initialization errors - tooltips are optional
      }
    },
    getSortedMembers(portChannel) {
      // Helper method to get sorted members for a port channel
      if (!portChannel || !portChannel.members || !Array.isArray(portChannel.members)) {
        return [];
      }
      // Create a copy and sort
      return [...portChannel.members].sort((a, b) => {
        return this.compareInterfaces(
          { interface_name: a.interface_name || a.name },
          { interface_name: b.interface_name || b.name }
        );
      });
    }
  },
  template: `
    <div class="port-channel-management">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5><i class="fas fa-link me-2"></i>Port Channels (LACP)</h5>
        <div>
          <input
            type="text"
            class="form-control form-control-sm d-inline-block"
            style="width: 200px;"
            v-model="searchTerm"
            placeholder="Search port channels..."
          />
          <button
            v-if="canManagePortChannels"
            class="btn btn-primary btn-sm ms-2"
            @click="openCreateModal"
          >
            <i class="fas fa-plus me-1"></i>
            Create Port Channel
          </button>
        </div>
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

      <div v-else-if="filteredPortChannels.length === 0" class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        No port channels found.
      </div>

      <div v-else class="table-responsive">
        <table class="table table-striped table-hover">
          <thead>
            <tr>
              <th>Port Channel</th>
              <th>Mode</th>
              <th>LACP Mode</th>
              <th>VLAN Config</th>
              <th>Members</th>
              <th>Status</th>
              <th>Description</th>
              <th v-if="canManagePortChannels">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="pc in filteredPortChannels" :key="pc.id">
              <td><strong>{{ pc.port_channel_name }}</strong></td>
              <td>
                <span class="badge bg-secondary">{{ pc.mode || 'unknown' }}</span>
              </td>
              <td>
                <span class="badge bg-info">{{ pc.lacp_mode || 'active' }}</span>
              </td>
              <td>
                <span v-if="pc.mode === 'access' && pc.vlan_id">VLAN {{ pc.vlan_id }}</span>
                <span v-else-if="pc.mode === 'trunk'">
                  <span v-if="pc.native_vlan_id">Native: {{ pc.native_vlan_id }}</span>
                  <span v-if="pc.trunk_vlans"> | Allowed: {{ pc.trunk_vlans }}</span>
                  <span v-if="!pc.native_vlan_id && !pc.trunk_vlans">-</span>
                </span>
                <span v-else>-</span>
              </td>
              <td>
                <small>
                  <template v-if="pc.members && pc.members.length > 0">
                    <template v-for="(member, idx) in getSortedMembers(pc)" :key="member.id || member.interface_name || idx">
                      {{ member.interface_name }}<span v-if="idx < getSortedMembers(pc).length - 1">, </span>
                    </template>
                  </template>
                  <span v-else class="text-muted">No members</span>
                </small>
              </td>
              <td>
                <span :class="['badge', pc.oper_status === 'up' ? 'bg-success' : (pc.oper_status === 'down' ? 'bg-danger' : 'bg-secondary')]">
                  {{ pc.oper_status || 'unknown' }}
                </span>
              </td>
              <td>{{ pc.description || '-' }}</td>
              <td v-if="canManagePortChannels">
                <button class="btn btn-sm btn-outline-primary me-1" @click="openConfigureModal(pc)" title="Configure">
                  <i class="fas fa-cog"></i>
                </button>
                <button class="btn btn-sm btn-outline-info me-1" @click="openVlanModal(pc)" title="Configure VLANs">
                  <i class="fas fa-network-wired"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary me-1" @click="openLoadBalanceModal(pc)" title="Load Balance Stats">
                  <i class="fas fa-balance-scale"></i>
                </button>
                <button class="btn btn-sm btn-outline-success me-1" @click="openMemberModal(pc, 'add')" title="Add Member">
                  <i class="fas fa-plus"></i>
                </button>
                <button class="btn btn-sm btn-outline-warning me-1" @click="openMemberModal(pc, 'remove')" title="Remove Member">
                  <i class="fas fa-minus"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" @click="confirmDelete(pc)" title="Delete">
                  <i class="fas fa-trash"></i>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Create Port Channel Modal -->
      <div v-if="showCreateModal" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Create Port Channel</h5>
              <button type="button" class="btn-close" @click="showCreateModal = false"></button>
            </div>
            <div class="modal-body">
              <div v-if="validationErrors.length > 0" class="alert alert-danger">
                <ul class="mb-0">
                  <li v-for="error in validationErrors" :key="error">{{ error }}</li>
                </ul>
              </div>

              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">Port Channel Number *</label>
                  <input
                    type="number"
                    class="form-control"
                    v-model="newPortChannel.port_channel_number"
                    placeholder="1-4096"
                    min="1"
                    max="4096"
                    :disabled="saving"
                  />
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">
                    LACP Mode *
                    <i class="fas fa-info-circle text-info ms-1" 
                       data-bs-toggle="tooltip" 
                       data-bs-placement="right"
                       title="Active: Initiates LACP negotiations. Passive: Responds to LACP (use with Active). On: Static aggregation without LACP protocol."
                    ></i>
                  </label>
                  <select class="form-select" v-model="newPortChannel.lacp_mode" :disabled="saving" title="Active: Initiates LACP negotiations. Passive: Responds to LACP. On: Static aggregation without LACP.">
                    <option value="active" title="Initiates LACP negotiations - recommended for most cases">Active</option>
                    <option value="passive" title="Responds to LACP negotiations - pair with Active mode">Passive</option>
                    <option value="on" title="Static aggregation without LACP protocol - no negotiation">On (Static)</option>
                  </select>
                  <small class="form-text text-muted">
                    <strong>Active:</strong> Initiates LACP negotiations (recommended). 
                    <strong>Passive:</strong> Responds to LACP. 
                    <strong>On:</strong> Static aggregation (no LACP).
                  </small>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label">
                  Mode *
                  <i class="fas fa-info-circle text-info ms-1" 
                     data-bs-toggle="tooltip" 
                     data-bs-placement="right"
                     title="Trunk: Carries multiple VLANs (tagged). Access: Single VLAN (untagged). Routed: Layer 3 interface (no switchport)."
                  ></i>
                </label>
                <select class="form-select" v-model="newPortChannel.mode" :disabled="saving" title="Trunk: Multiple VLANs (tagged). Access: Single VLAN (untagged). Routed: Layer 3 interface.">
                  <option value="trunk" title="Carries multiple VLANs with 802.1Q tags - for inter-switch links">Trunk</option>
                  <option value="access" title="Single VLAN mode - untagged frames for end devices">Access</option>
                  <option value="routed" title="Layer 3 interface - no VLANs, uses IP addresses">Routed</option>
                </select>
                <small class="form-text text-muted">
                  <strong>Trunk:</strong> Carries multiple VLANs with 802.1Q tags (for inter-switch links). 
                  <strong>Access:</strong> Single VLAN, untagged frames (for end devices). 
                  <strong>Routed:</strong> Layer 3 interface, uses IP addresses.
                </small>
              </div>

              <div v-if="newPortChannel.mode === 'access'" class="mb-3">
                <label class="form-label">Access VLAN *</label>
                <select class="form-select" v-model="newPortChannel.vlan_id" :disabled="saving">
                  <option :value="null">Select VLAN</option>
                  <option v-for="vlan in vlans" :key="vlan.vlan_id" :value="vlan.vlan_id">
                    VLAN {{ vlan.vlan_id }} - {{ vlan.name || 'Unnamed' }}
                  </option>
                </select>
              </div>

              <div v-if="newPortChannel.mode === 'trunk'">
                <div class="mb-3">
                  <label class="form-label">
                    Native VLAN
                    <i class="fas fa-info-circle text-info ms-1" 
                       data-bs-toggle="tooltip" 
                       data-bs-placement="right"
                       title="The native VLAN carries untagged traffic on a trunk port. Frames in the native VLAN are not tagged with 802.1Q."
                    ></i>
                  </label>
                  <select class="form-select" v-model="newPortChannel.native_vlan_id" :disabled="saving" title="Untagged VLAN for this trunk - frames in this VLAN are sent without 802.1Q tags">
                    <option :value="null">None</option>
                    <option v-for="vlan in vlans" :key="vlan.vlan_id" :value="vlan.vlan_id">
                      VLAN {{ vlan.vlan_id }} - {{ vlan.name || 'Unnamed' }}
                    </option>
                  </select>
                  <small class="form-text text-muted">The native VLAN carries untagged traffic on trunk ports (optional).</small>
                </div>
                <div class="mb-3">
                  <label class="form-label">
                    Trunk VLANs (comma-separated)
                    <i class="fas fa-info-circle text-info ms-1" 
                       data-bs-toggle="tooltip" 
                       data-bs-placement="right"
                       title="List of VLAN IDs that are allowed on this trunk port. Frames in these VLANs will be tagged with 802.1Q."
                    ></i>
                  </label>
                  <input
                    type="text"
                    class="form-control"
                    v-model="newPortChannel.trunk_vlans"
                    placeholder="e.g., 10,20,30"
                    :disabled="saving"
                    title="Comma-separated list of VLAN IDs allowed on this trunk (e.g., 10,20,30)"
                  />
                  <small class="form-text text-muted">Comma-separated VLAN IDs that are allowed on this trunk (tagged traffic).</small>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label">Description</label>
                <input
                  type="text"
                  class="form-control"
                  v-model="newPortChannel.description"
                  placeholder="Optional description"
                  :disabled="saving"
                />
              </div>

              <div class="mb-3">
                <label class="form-label">Member Interfaces</label>
                <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                  <div v-if="availableInterfaces.length === 0" class="text-muted small">No available interfaces</div>
                  <div v-else v-for="iface in availableInterfaces" :key="iface.interface_name || iface.name" class="form-check">
                    <input
                      class="form-check-input"
                      type="checkbox"
                      :id="'member-' + (iface.interface_name || iface.name)"
                      :checked="isMemberSelected(iface.interface_name || iface.name)"
                      @change="toggleMemberSelection(iface.interface_name || iface.name)"
                      :disabled="saving"
                    />
                    <label class="form-check-label" :for="'member-' + (iface.interface_name || iface.name)">
                      {{ iface.interface_name || iface.name }}
                    </label>
                  </div>
                </div>
                <small class="text-muted">Selected: {{ newPortChannel.members.length }} interface(s)</small>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" @click="showCreateModal = false" :disabled="saving">Cancel</button>
              <button type="button" class="btn btn-primary" @click="createPortChannel" :disabled="saving">
                <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ saving ? 'Creating...' : 'Create' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Configure Port Channel Modal -->
      <div v-if="showConfigureModal && selectedPortChannel" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Configure {{ selectedPortChannel.port_channel_name }}</h5>
              <button type="button" class="btn-close" @click="showConfigureModal = false"></button>
            </div>
            <div class="modal-body">
              <div v-if="validationErrors.length > 0" class="alert alert-danger">
                <ul class="mb-0">
                  <li v-for="error in validationErrors" :key="error">{{ error }}</li>
                </ul>
              </div>

              <div class="mb-3">
                <label class="form-label">
                  Mode
                  <i class="fas fa-info-circle text-info ms-1" 
                     data-bs-toggle="tooltip" 
                     data-bs-placement="right"
                     title="Trunk: Carries multiple VLANs (tagged). Access: Single VLAN (untagged). Routed: Layer 3 interface (no switchport)."
                  ></i>
                </label>
                <select class="form-select" v-model="configurePortChannel.mode" :disabled="saving" title="Trunk: Multiple VLANs (tagged). Access: Single VLAN (untagged). Routed: Layer 3 interface.">
                  <option value="trunk" title="Carries multiple VLANs with 802.1Q tags - for inter-switch links">Trunk</option>
                  <option value="access" title="Single VLAN mode - untagged frames for end devices">Access</option>
                  <option value="routed" title="Layer 3 interface - no VLANs, uses IP addresses">Routed</option>
                </select>
                <small class="form-text text-muted">
                  <strong>Trunk:</strong> Multiple VLANs with 802.1Q tags. 
                  <strong>Access:</strong> Single VLAN, untagged. 
                  <strong>Routed:</strong> Layer 3 interface.
                </small>
              </div>

              <div v-if="configurePortChannel.mode === 'access'" class="mb-3">
                <label class="form-label">Access VLAN</label>
                <select class="form-select" v-model="configurePortChannel.vlan_id" :disabled="saving">
                  <option :value="null">Select VLAN</option>
                  <option v-for="vlan in vlans" :key="vlan.vlan_id" :value="vlan.vlan_id">
                    VLAN {{ vlan.vlan_id }} - {{ vlan.name || 'Unnamed' }}
                  </option>
                </select>
              </div>

              <div v-if="configurePortChannel.mode === 'trunk'">
                <div class="mb-3">
                  <label class="form-label">
                    Native VLAN
                    <i class="fas fa-info-circle text-info ms-1" 
                       data-bs-toggle="tooltip" 
                       data-bs-placement="right"
                       title="The native VLAN carries untagged traffic on a trunk port. Frames in the native VLAN are not tagged with 802.1Q."
                    ></i>
                  </label>
                  <select class="form-select" v-model="configurePortChannel.native_vlan_id" :disabled="saving" title="Untagged VLAN for this trunk - frames in this VLAN are sent without 802.1Q tags">
                    <option :value="null">None</option>
                    <option v-for="vlan in vlans" :key="vlan.vlan_id" :value="vlan.vlan_id">
                      VLAN {{ vlan.vlan_id }} - {{ vlan.name || 'Unnamed' }}
                    </option>
                  </select>
                  <small class="form-text text-muted">Untagged VLAN for this trunk port (optional).</small>
                </div>
                <div class="mb-3">
                  <label class="form-label">
                    Trunk VLANs (comma-separated)
                    <i class="fas fa-info-circle text-info ms-1" 
                       data-bs-toggle="tooltip" 
                       data-bs-placement="right"
                       title="List of VLAN IDs that are allowed on this trunk port. Frames in these VLANs will be tagged with 802.1Q."
                    ></i>
                  </label>
                  <input
                    type="text"
                    class="form-control"
                    v-model="configurePortChannel.trunk_vlans"
                    placeholder="e.g., 10,20,30"
                    :disabled="saving"
                    title="Comma-separated list of VLAN IDs allowed on this trunk (e.g., 10,20,30)"
                  />
                  <small class="form-text text-muted">Comma-separated VLAN IDs allowed on this trunk (tagged traffic).</small>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label">Description</label>
                <input
                  type="text"
                  class="form-control"
                  v-model="configurePortChannel.description"
                  placeholder="Optional description"
                  :disabled="saving"
                />
              </div>

              <div class="mb-3">
                <label class="form-label">Admin State</label>
                <select class="form-select" v-model="configurePortChannel.admin_state" :disabled="saving">
                  <option :value="null">No change</option>
                  <option value="up">Up</option>
                  <option value="down">Down</option>
                </select>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" @click="showConfigureModal = false" :disabled="saving">Cancel</button>
              <button type="button" class="btn btn-primary" @click="configurePortChannelSubmit" :disabled="saving">
                <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ saving ? 'Saving...' : 'Save' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Add/Remove Member Modal -->
      <div v-if="showMemberModal && selectedPortChannel" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">{{ memberAction === 'add' ? 'Add' : 'Remove' }} Member to {{ selectedPortChannel.port_channel_name }}</h5>
              <button type="button" class="btn-close" @click="showMemberModal = false"></button>
            </div>
            <div class="modal-body">
              <div v-if="validationErrors.length > 0" class="alert alert-danger">
                <ul class="mb-0">
                  <li v-for="error in validationErrors" :key="error">{{ error }}</li>
                </ul>
              </div>

              <div v-if="memberAction === 'add'" class="mb-3">
                <label class="form-label">Interface *</label>
                <select class="form-select" v-model="memberInterface" :disabled="saving">
                  <option value="">Select interface</option>
                  <option v-for="iface in availableInterfaces" :key="iface.interface_name || iface.name" :value="iface.interface_name || iface.name">
                    {{ iface.interface_name || iface.name }}
                  </option>
                </select>
              </div>

              <div v-else class="mb-3">
                <label class="form-label">Interface *</label>
                <select class="form-select" v-model="memberInterface" :disabled="saving">
                  <option value="">Select interface</option>
                  <option v-for="member in getSortedMembers(selectedPortChannel)" :key="member.id || member.interface_name" :value="member.interface_name">
                    {{ member.interface_name }}
                  </option>
                </select>
              </div>

              <div v-if="memberAction === 'add'" class="mb-3">
                <label class="form-label">
                  LACP Mode
                  <i class="fas fa-info-circle text-info ms-1" 
                     data-bs-toggle="tooltip" 
                     data-bs-placement="right"
                     title="Active: Initiates LACP negotiations. Passive: Responds to LACP (use with Active). On: Static aggregation without LACP protocol."
                  ></i>
                </label>
                <select class="form-select" v-model="memberLacpMode" :disabled="saving" title="Active: Initiates LACP. Passive: Responds to LACP. On: Static aggregation.">
                  <option value="active" title="Initiates LACP negotiations - recommended for most cases">Active</option>
                  <option value="passive" title="Responds to LACP negotiations - pair with Active mode">Passive</option>
                  <option value="on" title="Static aggregation without LACP protocol - no negotiation">On (Static)</option>
                </select>
                <small class="form-text text-muted">
                  <strong>Active:</strong> Initiates LACP (recommended). 
                  <strong>Passive:</strong> Responds to LACP. 
                  <strong>On:</strong> Static (no LACP).
                </small>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" @click="showMemberModal = false" :disabled="saving">Cancel</button>
              <button type="button" class="btn btn-primary" @click="manageMember" :disabled="saving || !memberInterface">
                <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ saving ? (memberAction === 'add' ? 'Adding...' : 'Removing...') : (memberAction === 'add' ? 'Add' : 'Remove') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Configure VLANs Modal -->
      <div v-if="showVlanModal && vlanConfigPortChannel" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">
                <i class="fas fa-network-wired me-2"></i>
                Configure VLANs: {{ vlanConfigPortChannel.port_channel_name }}
              </h5>
              <button type="button" class="btn-close" @click="closeVlanModal"></button>
            </div>
            <div class="modal-body">
              <div v-if="validationErrors.length > 0" class="alert alert-danger">
                <ul class="mb-0">
                  <li v-for="error in validationErrors" :key="error">{{ error }}</li>
                </ul>
              </div>

              <div class="mb-3">
                <label class="form-label">
                  Current VLAN Configuration:
                  <span class="badge bg-info ms-2">{{ vlanDisplayText }}</span>
                </label>
              </div>

              <div class="row">
                <div class="col-md-12 mb-3">
                  <label class="form-label">Mode</label>
                  <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" id="mode_access" v-model="vlanConfiguration.mode" value="access" :disabled="saving" />
                    <label class="btn btn-outline-primary" for="mode_access">Access</label>
                    
                    <input type="radio" class="btn-check" id="mode_trunk" v-model="vlanConfiguration.mode" value="trunk" :disabled="saving" />
                    <label class="btn btn-outline-primary" for="mode_trunk">Trunk</label>
                    
                    <input type="radio" class="btn-check" id="mode_routed" v-model="vlanConfiguration.mode" value="routed" :disabled="saving" />
                    <label class="btn btn-outline-primary" for="mode_routed">Routed</label>
                  </div>
                </div>
              </div>

              <!-- Access Mode -->
              <div v-if="vlanConfiguration.mode === 'access'" class="row">
                <div class="col-md-12 mb-3">
                  <label class="form-label">VLAN *</label>
                  <select class="form-select" v-model="vlanConfiguration.vlan_id" :disabled="saving">
                    <option value="">-- Select VLAN --</option>
                    <option v-for="vlan in vlans" :key="vlan.vlan_id" :value="vlan.vlan_id">
                      VLAN {{ vlan.vlan_id }} ({{ vlan.name || 'no name' }})
                    </option>
                  </select>
                </div>
              </div>

              <!-- Trunk Mode -->
              <div v-else-if="vlanConfiguration.mode === 'trunk'">
                <!-- Native VLAN -->
                <div class="row mb-3">
                  <div class="col-md-12">
                    <label class="form-label">Native VLAN (Untagged)</label>
                    <select class="form-select" v-model="vlanConfiguration.native_vlan_id" :disabled="saving">
                      <option :value="null">-- No native VLAN --</option>
                      <option v-for="vlan in vlans" :key="vlan.vlan_id" :value="vlan.vlan_id">
                        VLAN {{ vlan.vlan_id }} ({{ vlan.name || 'no name' }})
                      </option>
                    </select>
                    <small class="form-text text-muted">VLAN for untagged traffic on this trunk</small>
                  </div>
                </div>

                <!-- Tagged VLANs - Dual List Selector -->
                <div class="row">
                  <div class="col-md-12 mb-3">
                    <label class="form-label">Tagged VLANs</label>
                    <div class="row" style="gap: 10px;">
                      <!-- Available VLANs -->
                      <div class="col-md-5">
                        <div class="card">
                          <div class="card-header bg-light py-2">
                            <small class="fw-bold">Available VLANs</small>
                          </div>
                          <div class="card-body p-2" style="max-height: 300px; overflow-y: auto;">
                            <div v-if="vlans.length === 0" class="text-muted small">No VLANs available</div>
                            <div v-for="vlan in vlans" :key="vlan.vlan_id" class="form-check mb-2">
                              <input
                                type="checkbox"
                                class="form-check-input"
                                :id="'available_vlan_' + vlan.vlan_id"
                                :value="vlan.vlan_id"
                                v-model="selectedTrunkVlans"
                                :disabled="saving"
                              />
                              <label class="form-check-label" :for="'available_vlan_' + vlan.vlan_id">
                                <span class="badge bg-secondary me-1">{{ vlan.vlan_id }}</span>
                                {{ vlan.name || '(no name)' }}
                              </label>
                            </div>
                          </div>
                        </div>
                      </div>

                      <!-- Selected VLANs -->
                      <div class="col-md-5">
                        <div class="card">
                          <div class="card-header bg-light py-2">
                            <small class="fw-bold">
                              Selected Tagged VLANs 
                              <span v-if="selectedTrunkVlans.length > 0" class="badge bg-primary ms-1">
                                {{ selectedTrunkVlans.length }}
                              </span>
                            </small>
                          </div>
                          <div class="card-body p-2" style="max-height: 300px; overflow-y: auto;">
                            <div v-if="selectedTrunkVlans.length === 0" class="text-muted small py-3 text-center">
                              Select VLANs from the left
                            </div>
                            <div v-for="vlanId in selectedTrunkVlans.sort((a, b) => a - b)" :key="vlanId" class="d-flex align-items-center justify-content-between mb-2 p-2 bg-light rounded">
                              <div>
                                <span class="badge bg-primary me-1">{{ vlanId }}</span>
                                <small>{{ vlans.find(v => v.vlan_id === vlanId)?.name || '(no name)' }}</small>
                              </div>
                              <button
                                type="button"
                                class="btn btn-sm btn-close"
                                @click="selectedTrunkVlans = selectedTrunkVlans.filter(v => v !== vlanId)"
                                :disabled="saving"
                                title="Remove VLAN"
                              ></button>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Routed Mode -->
              <div v-else-if="vlanConfiguration.mode === 'routed'" class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Routed mode does not use VLAN configuration. This interface will operate as a routed port.
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" @click="closeVlanModal" :disabled="saving">Cancel</button>
              <button type="button" class="btn btn-primary" @click="saveVlanConfiguration" :disabled="saving">
                <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ saving ? 'Saving...' : 'Apply' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Delete Confirmation Modal -->
      <div v-if="showDeleteConfirm && portChannelToDelete" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-sm">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Delete Port Channel?</h5>
              <button type="button" class="btn-close" @click="showDeleteConfirm = false"></button>
            </div>
            <div class="modal-body">
              <p>Are you sure you want to delete <strong>{{ portChannelToDelete.port_channel_name }}</strong>?</p>
              <p class="text-muted small">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" @click="showDeleteConfirm = false" :disabled="deleting">Cancel</button>
              <button type="button" class="btn btn-danger" @click="deletePortChannel" :disabled="deleting">
                <span v-if="deleting" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ deleting ? 'Deleting...' : 'Delete' }}
              </button>
            </div>
          </div>
        </div>
        </div>
      </div>

      <!-- Load Balance Stats Modal -->
      <div v-if="showLoadBalanceModal" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">
                <i class="fas fa-balance-scale me-2"></i>
                Load Balance Stats - {{ selectedPortChannel?.port_channel_name }}
              </h5>
              <button type="button" class="btn-close" @click="closeLoadBalanceModal"></button>
            </div>
            <div class="modal-body">
              <div v-if="loadingLoadBalance" class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                  <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading load balance statistics...</p>
              </div>
              <div v-else-if="loadBalanceStats">
                <!-- Display load balance stats -->
                <div v-if="Object.keys(loadBalanceStats).length > 0">
                  
                  <!-- Case 1: Port Channel Traffic / Load Balance structure -->
                  <div v-if="loadBalanceStats.portChannels">
                    <div v-for="(pcData, pcName) in loadBalanceStats.portChannels" :key="pcName" class="mb-4">
                      <h6 class="border-bottom pb-2">{{ pcName }} Statistics</h6>
                      
                      <!-- Traffic Stats (inputs/outputs) -->
                      <div v-if="pcData.inputs || pcData.outputs" class="table-responsive mt-2">
                         <table class="table table-sm table-bordered table-striped">
                           <thead class="table-light">
                             <tr>
                               <th>Interface</th>
                               <th v-if="pcData.inputs">Input Packets</th>
                               <th v-if="pcData.outputs">Output Packets</th>
                               <th v-if="pcData.inputBytes">Input Bytes</th>
                               <th v-if="pcData.outputBytes">Output Bytes</th>
                             </tr>
                           </thead>
                           <tbody>
                             <!-- Combine keys from inputs and outputs to get all members -->
                             <tr v-for="iface in [...new Set([...Object.keys(pcData.inputs || {}), ...Object.keys(pcData.outputs || {})])].sort(compareInterfaces)" :key="iface">
                               <td>{{ iface }}</td>
                               <td v-if="pcData.inputs">{{ (pcData.inputs[iface] || 0).toLocaleString() }}</td>
                               <td v-if="pcData.outputs">{{ (pcData.outputs[iface] || 0).toLocaleString() }}</td>
                               <td v-if="pcData.inputBytes">{{ (pcData.inputBytes[iface] || 0).toLocaleString() }}</td>
                               <td v-if="pcData.outputBytes">{{ (pcData.outputBytes[iface] || 0).toLocaleString() }}</td>
                             </tr>
                           </tbody>
                         </table>
                      </div>
                      
                      <!-- Generic Nested Object Tables (e.g. 'ports' or other stats) -->
                      <div v-for="(val, key) in pcData" :key="key">
                         <div v-if="val && typeof val === 'object' && !Array.isArray(val) && key !== 'inputs' && key !== 'outputs' && key !== 'inputBytes' && key !== 'outputBytes'" class="mt-3">
                            <strong>{{ key.charAt(0).toUpperCase() + key.slice(1) }}:</strong>
                            
                            <!-- Specific Traffic Stats Table (Mapped Headers) -->
                            <div v-if="Object.values(val).length > 0 && typeof Object.values(val)[0] === 'object' && Object.values(val)[0] !== null && ('inUcastPkts' in Object.values(val)[0] || 'outUcastPkts' in Object.values(val)[0])" class="table-responsive mt-1">
                               <table class="table table-sm table-bordered table-striped text-center">
                                 <thead class="table-light">
                                   <tr>
                                     <th class="text-start">Port</th>
                                     <th>Rx-Ucst</th>
                                     <th>Tx-Ucst</th>
                                     <th>Rx-Mcst</th>
                                     <th>Tx-Mcst</th>
                                     <th>Rx-Bcst</th>
                                     <th>Tx-Bcst</th>
                                   </tr>
                                 </thead>
                                 <tbody>
                                   <tr v-for="(rowVal, rowKey) in val" :key="rowKey">
                                     <td class="text-start"><strong>{{ rowKey }}</strong></td>
                                     <td>{{ rowVal['inUcastPkts'] || rowVal['Rx-Ucst'] || '-' }}</td>
                                     <td>{{ rowVal['outUcastPkts'] || rowVal['Tx-Ucst'] || '-' }}</td>
                                     <td>{{ rowVal['inMulticastPkts'] || rowVal['Rx-Mcst'] || '-' }}</td>
                                     <td>{{ rowVal['outMulticastPkts'] || rowVal['Tx-Mcst'] || '-' }}</td>
                                     <td>{{ rowVal['inBroadcastPkts'] || rowVal['Rx-Bcst'] || '-' }}</td>
                                     <td>{{ rowVal['outBroadcastPkts'] || rowVal['Tx-Bcst'] || '-' }}</td>
                                   </tr>
                                 </tbody>
                               </table>
                            </div>

                            <!-- Specific Traffic Stats Table (Direct Headers - if CLI keys are used directly) -->
                            <div v-else-if="Object.values(val).length > 0 && typeof Object.values(val)[0] === 'object' && Object.values(val)[0] !== null && ('Rx-Ucst' in Object.values(val)[0] || 'Tx-Ucst' in Object.values(val)[0])" class="table-responsive mt-1">
                               <table class="table table-sm table-bordered table-striped text-center">
                                 <thead class="table-light">
                                   <tr>
                                     <th class="text-start">Port</th>
                                     <th>Rx-Ucst</th>
                                     <th>Tx-Ucst</th>
                                     <th>Rx-Mcst</th>
                                     <th>Tx-Mcst</th>
                                     <th>Rx-Bcst</th>
                                     <th>Tx-Bcst</th>
                                   </tr>
                                 </thead>
                                 <tbody>
                                   <tr v-for="(rowVal, rowKey) in val" :key="rowKey">
                                     <td class="text-start"><strong>{{ rowKey }}</strong></td>
                                     <td>{{ rowVal['Rx-Ucst'] || '-' }}</td>
                                     <td>{{ rowVal['Tx-Ucst'] || '-' }}</td>
                                     <td>{{ rowVal['Rx-Mcst'] || '-' }}</td>
                                     <td>{{ rowVal['Tx-Mcst'] || '-' }}</td>
                                     <td>{{ rowVal['Rx-Bcst'] || '-' }}</td>
                                     <td>{{ rowVal['Tx-Bcst'] || '-' }}</td>
                                   </tr>
                                 </tbody>
                               </table>
                            </div>

                            <!-- Smart Matrix Table for other objects of objects -->
                            <div v-else-if="Object.values(val).length > 0 && typeof Object.values(val)[0] === 'object' && Object.values(val)[0] !== null" class="table-responsive mt-1">
                               <table class="table table-sm table-bordered table-striped text-center">
                                 <thead class="table-light">
                                   <tr>
                                     <th class="text-start">Port</th>
                                     <th v-for="header in Object.keys(Object.values(val)[0])" :key="header">{{ header }}</th>
                                   </tr>
                                 </thead>
                                 <tbody>
                                   <tr v-for="(rowVal, rowKey) in val" :key="rowKey">
                                     <td class="text-start"><strong>{{ rowKey }}</strong></td>
                                     <td v-for="header in Object.keys(Object.values(val)[0])" :key="header">
                                       {{ rowVal[header] }}
                                     </td>
                                   </tr>
                                 </tbody>
                               </table>
                            </div>

                            <!-- Fallback Simple Table for flat key-value objects -->
                            <div v-else class="table-responsive mt-1">
                               <table class="table table-sm table-bordered table-striped">
                                 <thead class="table-light">
                                   <tr>
                                     <th>Item</th>
                                     <th>Value</th>
                                   </tr>
                                 </thead>
                                 <tbody>
                                   <tr v-for="(subVal, subKey) in val" :key="subKey">
                                     <td>{{ subKey }}</td>
                                     <td>
                                        <span v-if="typeof subVal !== 'object'">{{ subVal }}</span>
                                        <pre v-else class="mb-0 p-1" style="font-size:0.8em">{{ JSON.stringify(subVal, null, 1) }}</pre>
                                     </td>
                                   </tr>
                                 </tbody>
                               </table>
                            </div>
                         </div>
                      </div>

                      <!-- Other keys in pcData that aren't traffic dicts -->
                      <div class="row mt-2">
                        <div class="col-md-6" v-for="(val, key) in pcData" :key="key">
                           <div v-if="typeof val !== 'object' && val !== null" class="d-flex justify-content-between border-bottom py-1">
                             <span class="fw-bold">{{ key }}:</span>
                             <span>{{ val }}</span>
                           </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Case 2: Flat Key-Value pairs (e.g. Global load balance config) -->
                  <div v-else class="card card-body bg-light">
                    <div class="row">
                       <div class="col-md-6" v-for="(val, key) in loadBalanceStats" :key="key">
                          <div v-if="typeof val !== 'object'" class="d-flex justify-content-between border-bottom py-1">
                             <span class="fw-bold">{{ key }}:</span>
                             <span>{{ val }}</span>
                          </div>
                          <div v-else class="mt-2">
                             <span class="fw-bold">{{ key }}:</span>
                             <pre class="mt-1 p-2 bg-white border rounded">{{ JSON.stringify(val, null, 2) }}</pre>
                          </div>
                       </div>
                    </div>
                  </div>

                  <div class="mt-3">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#rawJsonCollapse" aria-expanded="false" aria-controls="rawJsonCollapse">
                      <i class="fas fa-code me-1"></i> Show Raw JSON
                    </button>
                    <div class="collapse mt-2" id="rawJsonCollapse">
                      <div class="card card-body">
                         <pre class="mb-0" style="max-height: 300px; overflow-y: auto; font-size: 0.85em;">{{ formatLoadBalanceStats(loadBalanceStats) }}</pre>
                      </div>
                    </div>
                  </div>
                </div>
                <div v-else class="alert alert-info">
                  <i class="fas fa-info-circle me-2"></i>
                  No load balance statistics available for this port channel.
                </div>
              </div>
              <div v-else class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Failed to load load balance statistics.
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" @click="closeLoadBalanceModal">Close</button>
            </div>
          </div>
        </div>
      </div>
  `
};

