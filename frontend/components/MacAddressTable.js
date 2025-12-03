// MAC Address Table Component
const API_BASE_URL = '/arista/api';

export default {
  name: 'MacAddressTable',
  props: {
    switchId: {
      type: [String, Number],
      required: true
    }
  },
  data() {
    return {
      macTable: [],
      loading: false,
      error: null,
      searchTerm: '',
      filterVlan: '',
      filterInterface: '',
      filterType: ''
    };
  },
  computed: {
    filteredMacTable() {
      let filtered = this.macTable || [];
      
      // Filter by search term (MAC address or interface)
      if (this.searchTerm) {
        const search = this.searchTerm.toLowerCase();
        filtered = filtered.filter(entry => {
          const mac = (entry.macAddress || entry.mac || '').toLowerCase();
          const iface = (entry.interface || entry.port || '').toLowerCase();
          const vlan = String(entry.vlan || entry.vlanId || '');
          return mac.includes(search) || iface.includes(search) || vlan.includes(search);
        });
      }
      
      // Filter by VLAN
      if (this.filterVlan) {
        filtered = filtered.filter(entry => {
          return String(entry.vlan || entry.vlanId || '') === this.filterVlan;
        });
      }
      
      // Filter by interface
      if (this.filterInterface) {
        filtered = filtered.filter(entry => {
          const iface = entry.interface || entry.port || '';
          return iface.toLowerCase().includes(this.filterInterface.toLowerCase());
        });
      }
      
      // Filter by type
      if (this.filterType) {
        filtered = filtered.filter(entry => {
          const type = (entry.type || entry.entryType || 'dynamic').toLowerCase();
          return type === this.filterType.toLowerCase();
        });
      }
      
      return filtered;
    },
    uniqueVlans() {
      const vlans = new Set();
      (this.macTable || []).forEach(entry => {
        const vlan = entry.vlan || entry.vlanId;
        if (vlan) vlans.add(vlan);
      });
      return Array.from(vlans).sort((a, b) => a - b);
    },
    uniqueInterfaces() {
      const interfaces = new Set();
      (this.macTable || []).forEach(entry => {
        const iface = entry.interface || entry.port;
        if (iface) interfaces.add(iface);
      });
      return Array.from(interfaces).sort();
    }
  },
  mounted() {
    this.loadMacTable();
  },
  methods: {
    async loadMacTable() {
      this.loading = true;
      this.error = null;
      try {
        const params = { switch_id: this.switchId };
        if (this.filterVlan) params.vlan = this.filterVlan;
        if (this.filterInterface) params.interface = this.filterInterface;
        
        const response = await axios.get(`${API_BASE_URL}/switches/mac-address-table/get.php`, {
          params,
          withCredentials: true
        });
        
        if (response.data.success) {
          // Normalize MAC table structure
          const raw = response.data.mac_address_table;
          this.macTable = this.normalizeMacTable(raw);
        } else {
          this.error = response.data.error || 'Failed to load MAC address table';
        }
      } catch (error) {
        this.error = 'Failed to load MAC address table: ' + (error.response?.data?.error || error.message);
      } finally {
        this.loading = false;
      }
    },
    normalizeMacTable(raw) {
      const normalized = [];
      
      // Handle various eAPI response structures
      if (Array.isArray(raw)) {
        normalized.push(...raw);
      } else if (raw && typeof raw === 'object') {
        // Check for common keys
        if (raw.macTable || raw.macAddressTable) {
          const table = raw.macTable || raw.macAddressTable;
          if (Array.isArray(table)) {
            normalized.push(...table);
          } else if (typeof table === 'object') {
            Object.values(table).forEach(entry => {
              if (Array.isArray(entry)) {
                normalized.push(...entry);
              } else {
                normalized.push(entry);
              }
            });
          }
        } else {
          // Try to extract entries directly
          Object.values(raw).forEach(entry => {
            if (Array.isArray(entry)) {
              normalized.push(...entry);
            } else if (entry && typeof entry === 'object') {
              normalized.push(entry);
            }
          });
        }
      }
      
      return normalized;
    },
    formatMacAddress(mac) {
      if (!mac) return '-';
      // Format MAC address with colons
      const cleaned = mac.replace(/[^0-9a-fA-F]/g, '');
      if (cleaned.length === 12) {
        return cleaned.match(/.{2}/g).join(':').toUpperCase();
      }
      return mac.toUpperCase();
    },
    clearFilters() {
      this.searchTerm = '';
      this.filterVlan = '';
      this.filterInterface = '';
      this.filterType = '';
      this.loadMacTable();
    }
  },
  template: `
    <div class="mac-address-table">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="fas fa-list me-2"></i>
            MAC Address Table
          </h5>
          <button
            class="btn btn-sm btn-outline-secondary"
            @click="loadMacTable"
            :disabled="loading"
            title="Refresh MAC table"
          >
            <i class="fas fa-sync-alt" :class="{ 'fa-spin': loading }"></i>
          </button>
        </div>
        <div class="card-body">
          <div v-if="error" class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            {{ error }}
          </div>
          
          <!-- Filters -->
          <div class="row mb-3">
            <div class="col-md-3">
              <input
                type="text"
                class="form-control form-control-sm"
                placeholder="Search MAC, Interface, VLAN..."
                v-model="searchTerm"
              />
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" v-model="filterVlan">
                <option value="">All VLANs</option>
                <option v-for="vlan in uniqueVlans" :key="vlan" :value="vlan">VLAN {{ vlan }}</option>
              </select>
            </div>
            <div class="col-md-2">
              <input
                type="text"
                class="form-control form-control-sm"
                placeholder="Filter Interface..."
                v-model="filterInterface"
              />
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" v-model="filterType">
                <option value="">All Types</option>
                <option value="dynamic">Dynamic</option>
                <option value="static">Static</option>
              </select>
            </div>
            <div class="col-md-3 text-end">
              <button
                class="btn btn-sm btn-outline-secondary"
                @click="clearFilters"
                v-if="searchTerm || filterVlan || filterInterface || filterType"
              >
                <i class="fas fa-times me-1"></i> Clear
              </button>
            </div>
          </div>
          
          <div v-if="loading" class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
          </div>
          
          <div v-else-if="filteredMacTable.length === 0" class="text-center py-4 text-muted">
            <i class="fas fa-inbox fa-3x mb-3"></i>
            <p>No MAC addresses found</p>
          </div>
          
          <div v-else class="table-responsive">
            <table class="table table-sm table-hover">
              <thead>
                <tr>
                  <th>MAC Address</th>
                  <th>Type</th>
                  <th>VLAN</th>
                  <th>Interface</th>
                  <th>Age</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(entry, index) in filteredMacTable" :key="index">
                  <td><code>{{ formatMacAddress(entry.macAddress || entry.mac) }}</code></td>
                  <td>
                    <span class="badge" :class="(entry.type || entry.entryType || 'dynamic').toLowerCase() === 'static' ? 'bg-primary' : 'bg-secondary'">
                      {{ (entry.type || entry.entryType || 'dynamic').toUpperCase() }}
                    </span>
                  </td>
                  <td>{{ entry.vlan || entry.vlanId || '-' }}</td>
                  <td>{{ entry.interface || entry.port || '-' }}</td>
                  <td>{{ entry.age || entry.ageSeconds || '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  `
};

