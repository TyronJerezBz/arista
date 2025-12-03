// Switch List Component
// API Base URL - adjust if needed
const API_BASE_URL = '/arista/api';

export default {
  name: 'SwitchList',
  props: {
    user: {
      type: Object,
      required: true
    },
    autoPollSwitchId: {
      type: Number,
      default: null
    }
  },
  data() {
    return {
      switches: [],
      loading: false,
      error: null,
      filterStatus: '',
      searchTerm: '',
      deleting: false,
      csrfToken: null,
      hasAutoPolled: false
    }
  },
  computed: {
    canAddSwitch() {
      return this.user.role === 'admin' || this.user.role === 'operator';
    },
    canDeleteSwitch() {
      return this.user.role === 'admin';
    },
    canEditSwitch() {
      return this.user.role === 'admin';
    },
    filteredSwitches() {
      let filtered = this.switches;
      
      if (this.filterStatus) {
        filtered = filtered.filter(s => s.status === this.filterStatus);
      }
      
      if (this.searchTerm) {
        const term = this.searchTerm.toLowerCase();
        filtered = filtered.filter(s => 
          s.hostname.toLowerCase().includes(term) ||
          s.ip_address.toLowerCase().includes(term) ||
          (s.model && s.model.toLowerCase().includes(term))
        );
      }
      
      return filtered;
    }
  },
  mounted() {
    this.loadSwitches();
    // Get CSRF token from parent if available
    this.csrfToken = this.$parent?.csrfToken || null;
  },
  watch: {
    // Reload switches when component becomes visible
    '$parent.currentView'(newView) {
      if (newView === 'switches') {
        this.loadSwitches();
      }
    },
    // Reset auto-poll flag when switch ID changes
    autoPollSwitchId(newId, oldId) {
      if (newId !== oldId) {
        this.hasAutoPolled = false;
      }
    }
  },
  methods: {
    async loadSwitches() {
      this.loading = true;
      this.error = null;
      
      try {
        const params = {};
        if (this.filterStatus) {
          params.status = this.filterStatus;
        }
        
        const response = await axios.get(`${API_BASE_URL}/switches/list.php`, {
          params: params,
          withCredentials: true
        });
        
        if (response.data.success) {
          this.switches = response.data.switches || [];
        } else {
          this.error = response.data.error || 'Failed to load switches';
        }
      } catch (error) {
        if (error.response && error.response.data) {
          this.error = error.response.data.error || 'Failed to load switches';
        } else {
          this.error = 'Network error. Please try again.';
        }
      } finally {
        this.loading = false;
        
        // Auto-poll switch if specified (e.g., after connection settings update)
        // Only do this once per switch ID to avoid infinite loops
        if (this.autoPollSwitchId && !this.hasAutoPolled) {
          this.hasAutoPolled = true;
          // Small delay to ensure UI is updated before polling
          setTimeout(async () => {
            await this.pollSwitch(this.autoPollSwitchId);
          }, 500);
        }
      }
    },
    
    async pollSwitch(switchId) {
      try {
        const response = await axios.post(`${API_BASE_URL}/switches/poll.php?id=${switchId}`, {}, {
          withCredentials: true,
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          }
        });
        
        if (response.data.success) {
          // Reload switches
          await this.loadSwitches();
          this.$emit('show-message', 'Switch polled successfully', 'success');
          
          // If this was an auto-poll after update, notify parent that polling is complete
          if (switchId === this.autoPollSwitchId) {
            this.$emit('poll-completed');
          }
        } else {
          // Even on failure, reload switches to get updated status (down)
          this.loadSwitches();
          
          const errorMsg = response.data.error || response.data.reason || 'Failed to poll switch';
          this.$emit('show-message', errorMsg, 'error');
        }
      } catch (error) {
        // Even on error, reload switches to get updated status
        await this.loadSwitches();
        
        // Try to get detailed error from response
        let errorMessage = 'Failed to poll switch';
        if (error.response?.data?.error) {
          errorMessage = error.response.data.error;
        } else if (error.response?.data?.reason) {
          errorMessage = error.response.data.reason;
        } else if (error.message) {
          errorMessage = error.message;
        }
        
        this.$emit('show-message', errorMessage, 'error');
        
        // If this was an auto-poll after update, notify parent that polling is complete (even on error)
        if (switchId === this.autoPollSwitchId) {
          this.$emit('poll-completed');
        }
      }
    },
    
    async handleDelete(switchId) {
      const switchItem = this.switches.find(s => s.id === switchId);
      if (!switchItem) return;

      if (!confirm(`Are you sure you want to delete switch "${switchItem.hostname}"?\n\nThis action cannot be undone and will delete all related data (credentials, VLANs, interfaces, configurations, and alerts).`)) {
        return;
      }

      if (!this.csrfToken) {
        this.$emit('show-message', 'CSRF token not available. Please refresh and try again.', 'error');
        return;
      }

      this.deleting = true;
      try {
        const response = await axios.delete(`${API_BASE_URL}/switches/delete.php?id=${switchId}`, {
          data: { csrf_token: this.csrfToken },
          withCredentials: true,
          headers: { 'Content-Type': 'application/json' }
        });

        if (response.data.success) {
          this.$emit('show-message', `Switch "${switchItem.hostname}" deleted successfully`, 'success');
          await this.loadSwitches();
        } else {
          this.$emit('show-message', response.data.error || 'Failed to delete switch', 'error');
        }
      } catch (error) {
        const errorMsg = error.response?.data?.error || error.message || 'Failed to delete switch';
        this.$emit('show-message', errorMsg, 'error');
      } finally {
        this.deleting = false;
      }
    },
    
    getStatusClass(status) {
      const classes = {
        'up': 'status-up',
        'down': 'status-down',
        'unknown': 'status-unknown'
      };
      return classes[status] || 'status-unknown';
    },
    
    formatDate(dateString) {
      if (!dateString) return 'Never';
      const date = new Date(dateString);
      return date.toLocaleString();
    },
    
    openDiagnosticTool() {
      window.open('/arista/diagnostic.php', '_blank');
    }
  },
  template: `
    <div class="switch-list">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="fas fa-network-wired me-2"></i>
            Switches
          </h5>
          <div class="d-flex gap-2">
            <button class="btn btn-outline-info btn-sm" @click="openDiagnosticTool" title="Run eAPI diagnostic tests">
              <i class="fas fa-stethoscope me-2"></i>
              Diagnostic
            </button>
            <button v-if="canAddSwitch" class="btn btn-primary btn-sm" @click="$emit('add-switch')">
              <i class="fas fa-plus me-2"></i>
              Add Switch
            </button>
          </div>
        </div>
        <div class="card-body">
          <div v-if="error" class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            {{ error }}
          </div>
          
          <div v-if="loading" class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
          </div>
          
          <div v-else>
            <!-- Filters -->
            <div class="row mb-3">
              <div class="col-md-4">
                <input
                  type="text"
                  class="form-control"
                  placeholder="Search switches..."
                  v-model="searchTerm"
                />
              </div>
              <div class="col-md-3">
                <select class="form-select" v-model="filterStatus">
                  <option value="">All Status</option>
                  <option value="up">Up</option>
                  <option value="down">Down</option>
                  <option value="unknown">Unknown</option>
                </select>
              </div>
              <div class="col-md-5 text-end">
                <button class="btn btn-outline-secondary btn-sm" @click="loadSwitches">
                  <i class="fas fa-sync-alt me-2"></i>
                  Refresh
                </button>
              </div>
            </div>
            
            <!-- Switches Table -->
            <div v-if="filteredSwitches.length === 0" class="text-center py-4 text-muted">
              <i class="fas fa-inbox fa-3x mb-3"></i>
              <p>No switches found</p>
            </div>
            
            <div v-else class="table-responsive">
              <table class="table table-hover">
                <thead>
                  <tr>
                    <th>Hostname</th>
                    <th>IP Address</th>
                    <th>Model</th>
                    <th>Status</th>
                    <th>Last Seen</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="switchItem in filteredSwitches" :key="switchItem.id">
                    <td>
                      <button 
                        class="btn btn-link text-start p-0"
                        @click="$emit('view-switch', switchItem.id)"
                        style="text-decoration: none;"
                      >
                        <strong>{{ switchItem.hostname }}</strong>
                      </button>
                      <span v-if="switchItem.role" class="badge bg-secondary ms-2">{{ switchItem.role }}</span>
                    </td>
                    <td>{{ switchItem.ip_address }}</td>
                    <td>{{ switchItem.model || 'N/A' }}</td>
                    <td>
                      <span :class="['status-badge', getStatusClass(switchItem.status)]">
                        {{ switchItem.status }}
                      </span>
                      <span v-if="switchItem.environment_alert == 1" class="ms-2 text-danger" title="Environmental Alert (Power/Fan/Temp)">
                        <i class="fas fa-exclamation-triangle"></i>
                      </span>
                    </td>
                    <td>{{ formatDate(switchItem.last_seen) }}</td>
                    <td>
                      <button
                        class="btn btn-sm btn-outline-secondary me-2"
                        @click="pollSwitch(switchItem.id)"
                        :disabled="polling"
                        title="Poll switch status"
                      >
                        <i class="fas fa-sync-alt"></i>
                      </button>
                      <button
                        class="btn btn-sm btn-primary"
                        @click="$emit('view-switch', switchItem.id)"
                        title="View switch details and manage"
                      >
                        <i class="fas fa-arrow-right me-1"></i>
                        View
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
}

