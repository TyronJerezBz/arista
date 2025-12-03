// Port Activity Component - Visual Switch Port Display
const API_BASE_URL = '/arista/api';

export default {
  name: 'PortActivity',
  props: {
    switchId: {
      type: [String, Number],
      required: true
    }
  },
  data() {
    return {
      interfaces: [],
      loading: false,
      error: null,
      refreshInterval: null,
      autoRefresh: false,
      refreshIntervalSeconds: 5
    };
  },
  computed: {
    sortedInterfaces() {
      // Sort interfaces naturally (Ethernet1, Ethernet2, ... Ethernet10, etc.)
      return [...this.interfaces].sort((a, b) => {
        return this.compareInterfaces(a.interface_name, b.interface_name);
      });
    },
    portsPerRow() {
      // Default to 24 ports per row (standard switch layout)
      return 24;
    },
    portRows() {
      const ports = this.sortedInterfaces.filter(iface => 
        /^Ethernet\d+/i.test(iface.interface_name)
      );
      const rows = [];
      for (let i = 0; i < ports.length; i += this.portsPerRow) {
        rows.push(ports.slice(i, i + this.portsPerRow));
      }
      return rows;
    }
  },
  mounted() {
    this.loadInterfaces();
  },
  beforeUnmount() {
    if (this.refreshInterval) {
      clearInterval(this.refreshInterval);
    }
  },
  methods: {
    async loadInterfaces() {
      this.loading = true;
      this.error = null;
      try {
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
      } finally {
        this.loading = false;
      }
    },
    compareInterfaces(a, b) {
      // Natural sort for interface names (Ethernet1, Ethernet2, Ethernet10)
      const extractNumbers = (name) => {
        const match = name.match(/(\d+)/g);
        return match ? match.map(Number) : [];
      };
      
      const aParts = extractNumbers(a);
      const bParts = extractNumbers(b);
      
      for (let i = 0; i < Math.max(aParts.length, bParts.length); i++) {
        const aNum = aParts[i] || 0;
        const bNum = bParts[i] || 0;
        if (aNum !== bNum) {
          return aNum - bNum;
        }
      }
      
      return a.localeCompare(b);
    },
    getPortStatusClass(iface) {
      const admin = (iface.admin_status || '').toLowerCase();
      const oper = (iface.oper_status || '').toLowerCase();
      
      if (admin === 'down') {
        return 'port-disabled';
      }
      
      if (oper === 'up' || oper === 'connected') {
        return 'port-up';
      }
      
      if (oper === 'down' || oper === 'notconnect' || oper === 'not connected') {
        return 'port-down';
      }
      
      return 'port-unknown';
    },
    getPortStatusTitle(iface) {
      const admin = iface.admin_status || 'unknown';
      const oper = iface.oper_status || 'unknown';
      const mode = iface.mode || 'unknown';
      const vlan = iface.vlan_id || iface.native_vlan_id || '-';
      const speed = this.formatSpeed(iface.speed);
      const description = iface.description || '-';
      
      return `${iface.interface_name}\nAdmin: ${admin}\nOper: ${oper}\nMode: ${mode}\nVLAN: ${vlan}\nSpeed: ${speed}\nDesc: ${description}`;
    },
    formatSpeed(speed) {
      if (!speed || speed === null || speed === undefined) return '-';
      const numSpeed = parseFloat(speed);
      if (isNaN(numSpeed)) return speed;
      if (numSpeed >= 10000000000) return (numSpeed / 1000000000).toFixed(0) + 'Gb/s';
      if (numSpeed >= 1000000000) return (numSpeed / 1000000000).toFixed(1) + 'Gb/s';
      if (numSpeed >= 1000000) return (numSpeed / 1000000).toFixed(0) + 'Mb/s';
      return numSpeed + ' b/s';
    },
    toggleAutoRefresh() {
      if (this.autoRefresh) {
        this.startAutoRefresh();
      } else {
        this.stopAutoRefresh();
      }
    },
    handleRefreshIntervalChange() {
      if (this.autoRefresh) {
        this.startAutoRefresh();
      }
    },
    getPortNumber(interfaceName) {
      return interfaceName.replace(/^Ethernet/i, '');
    },
    getEmptyCellsCount(row) {
      return this.portsPerRow - row.length > 0 ? this.portsPerRow - row.length : 0;
    },
    startAutoRefresh() {
      if (this.refreshInterval) {
        clearInterval(this.refreshInterval);
      }
      this.refreshInterval = setInterval(() => {
        this.loadInterfaces();
      }, this.refreshIntervalSeconds * 1000);
    },
    stopAutoRefresh() {
      if (this.refreshInterval) {
        clearInterval(this.refreshInterval);
        this.refreshInterval = null;
      }
    }
  },
  watch: {
    autoRefresh(newVal) {
      if (newVal) {
        this.startAutoRefresh();
      } else {
        this.stopAutoRefresh();
      }
    }
  },
  template: `
    <div class="port-activity">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="fas fa-network-wired me-2"></i>
            Port Activity
          </h5>
          <div class="d-flex gap-2 align-items-center">
            <div class="form-check form-switch">
              <input
                class="form-check-input"
                type="checkbox"
                id="autoRefresh"
                v-model="autoRefresh"
                @change="toggleAutoRefresh"
              />
              <label class="form-check-label" for="autoRefresh">
                Auto Refresh
              </label>
            </div>
            <select
              v-model="refreshIntervalSeconds"
              class="form-select form-select-sm"
              style="width: auto;"
              @change="handleRefreshIntervalChange"
            >
              <option :value="5">5s</option>
              <option :value="10">10s</option>
              <option :value="30">30s</option>
              <option :value="60">60s</option>
            </select>
            <button
              class="btn btn-sm btn-outline-secondary"
              @click="loadInterfaces"
              :disabled="loading"
              title="Refresh port status"
            >
              <i class="fas fa-sync-alt" :class="{ 'fa-spin': loading }"></i>
            </button>
          </div>
        </div>
        <div class="card-body">
          <div v-if="error" class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            {{ error }}
          </div>
          
          <!-- Legend -->
          <div class="mb-3">
            <div class="d-flex flex-wrap gap-3 align-items-center">
              <span class="badge bg-success port-legend-item">Up / Connected</span>
              <span class="badge bg-secondary port-legend-item">Down / Not Connected</span>
              <span class="badge bg-dark port-legend-item">Disabled (Shutdown)</span>
              <span class="badge bg-warning text-dark port-legend-item">Unknown</span>
            </div>
          </div>
          
          <div v-if="loading && interfaces.length === 0" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
          </div>
          
          <div v-else-if="portRows.length === 0" class="text-center py-4 text-muted">
            <i class="fas fa-inbox fa-3x mb-3"></i>
            <p>No Ethernet ports found</p>
          </div>
          
          <div v-else class="switch-visualization">
            <!-- Switch Header -->
            <div class="switch-header text-center mb-3">
              <h6 class="mb-0">Front Panel View</h6>
            </div>
            
            <!-- Port Grid -->
            <div class="port-grid-container">
              <div v-for="(row, rowIndex) in portRows" :key="'row-' + rowIndex" class="port-row">
                <template v-for="(iface, colIndex) in row" :key="iface.interface_name">
                  <div
                    :class="['port-cell', getPortStatusClass(iface)]"
                    :title="getPortStatusTitle(iface)"
                    :style="{ cursor: 'pointer' }"
                  >
                    <div class="port-number">{{ getPortNumber(iface.interface_name) }}</div>
                    <div class="port-status-indicator"></div>
                  </div>
                </template>
                <!-- Empty cells to fill the row -->
                <template v-for="n in getEmptyCellsCount(row)" :key="'empty-' + rowIndex + '-' + n">
                  <div class="port-cell port-empty"></div>
                </template>
              </div>
            </div>
            
            <!-- Port Statistics -->
            <div class="mt-4 row text-center">
              <div class="col-md-3">
                <div class="stat-box">
                  <div class="stat-value text-success">{{ sortedInterfaces.filter(i => (i.oper_status || '').toLowerCase() === 'up' || (i.oper_status || '').toLowerCase() === 'connected').length }}</div>
                  <div class="stat-label">Up</div>
                </div>
              </div>
              <div class="col-md-3">
                <div class="stat-box">
                  <div class="stat-value text-secondary">{{ sortedInterfaces.filter(i => (i.oper_status || '').toLowerCase() === 'down' || (i.oper_status || '').toLowerCase() === 'notconnect').length }}</div>
                  <div class="stat-label">Down</div>
                </div>
              </div>
              <div class="col-md-3">
                <div class="stat-box">
                  <div class="stat-value text-dark">{{ sortedInterfaces.filter(i => (i.admin_status || '').toLowerCase() === 'down').length }}</div>
                  <div class="stat-label">Disabled</div>
                </div>
              </div>
              <div class="col-md-3">
                <div class="stat-box">
                  <div class="stat-value">{{ sortedInterfaces.length }}</div>
                  <div class="stat-label">Total Ports</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
};

