// VLAN Matrix Component (bulk VLAN assignment)
const API_BASE_URL = '/arista/api';

export default {
  name: 'VlanMatrix',
  props: {
    switchId: { type: [String, Number], required: true },
    user: { type: Object, required: true },
    csrfToken: { type: String, default: null }
  },
  data() {
    return {
      loading: false,
      saving: false,
      vlans: [], // [{vlan_id,name}]
      rows: [],  // [{interface, mode, assignments: { [vlanId]: 'none'|'tagged'|'untagged' }}]
      error: null,
      search: '',
      refreshTrigger: 0 // Incremented to trigger reload
    };
  },
  computed: {
    canEdit() {
      return this.user.role === 'admin' || this.user.role === 'operator';
    },
    filteredRows() {
      const t = (this.search || '').toLowerCase();
      const list = t ? this.rows.filter(r => r.interface.toLowerCase().includes(t)) : [...this.rows];
      // Sort by interface number (natural order)
      list.sort(this.compareInterfaces);
      return list;
    }
  },
  mounted() {
    this.loadMatrix();
  },
  watch: {
    // Watch for external changes and reload matrix
    switchId() {
      this.loadMatrix();
    },
    refreshTrigger() {
      this.loadMatrix();
    }
  },
  emits: ['show-message'],
  methods: {
    async loadMatrix() {
      this.loading = true;
      this.error = null;
      try {
        const resp = await axios.get(`${API_BASE_URL}/switches/vlan-matrix/get.php`, {
          params: { switch_id: this.switchId },
          withCredentials: true
        });
        if (resp.data?.success) {
          this.vlans = resp.data.vlans || [];
          this.rows = (resp.data.interfaces || []).map(r => ({
            interface: r.interface,
            mode: r.mode === 'trunk' ? 'trunk' : 'access',
            assignments: { ...r.assignments },
            isPortChannelMember: r.is_port_channel_member || false,
            portChannelName: r.port_channel_name || null
          }));
          // Ensure access ports have exactly one untagged (default to first VLAN if missing)
          if (this.vlans && this.vlans.length > 0) {
            const firstVlan = String(this.vlans[0].vlan_id);
            this.rows.forEach(row => {
              if (row.mode === 'access' && !row.isPortChannelMember) {
                const hasUntagged = Object.values(row.assignments).some(v => v === 'untagged');
                if (!hasUntagged) {
                  Object.keys(row.assignments).forEach(vid => {
                    row.assignments[vid] = (vid === firstVlan) ? 'untagged' : 'none';
                  });
                }
              }
            });
          }
        } else {
          this.error = resp.data?.error || 'Failed to load VLAN matrix';
        }
      } catch (e) {
        this.error = e.response?.data?.error || e.message;
      } finally {
        this.loading = false;
      }
    },
    onModeChange(row) {
      if (row.mode === 'access') {
        // Clear any tagged selections
        Object.keys(row.assignments).forEach(vid => {
          if (row.assignments[vid] === 'tagged') {
            row.assignments[vid] = 'none';
          }
        });
        // Ensure exactly one untagged exists; if none, pick first VLAN
        const hasUntagged = Object.values(row.assignments).some(v => v === 'untagged');
        if (!hasUntagged && this.vlans && this.vlans.length > 0) {
          const firstVlan = String(this.vlans[0].vlan_id);
          Object.keys(row.assignments).forEach(vid => {
            row.assignments[vid] = (vid === firstVlan) ? 'untagged' : 'none';
          });
        } else if (hasUntagged) {
          // If multiple untagged exist, keep the first and clear others
          let kept = false;
          Object.keys(row.assignments).forEach(vid => {
            if (row.assignments[vid] === 'untagged') {
              if (!kept) {
                kept = true;
              } else {
                row.assignments[vid] = 'none';
              }
            }
          });
        }
      }
      // For trunk, user can set tagged/untagged per VLAN; no automatic changes needed
    },
    // Sorting helpers (same strategy as InterfaceManagement)
    interfaceTypeRank(name) {
      const n = (name || '').toLowerCase();
      if (n.startsWith('ethernet') || n.startsWith('et')) return 1;
      if (n.startsWith('management') || n.startsWith('ma')) return 2;
      if (n.startsWith('port-channel') || n.startsWith('portchannel') || n.startsWith('po')) return 3;
      if (n.startsWith('vlan')) return 4;
      if (n.startsWith('loopback') || n.startsWith('lo')) return 5;
      if (n.startsWith('tunnel') || n.startsWith('tu')) return 6;
      return 99;
    },
    extractNumbers(name) {
      const nums = [];
      const re = /(\d+)/g;
      let m;
      while ((m = re.exec(name || '')) !== null) {
        nums.push(parseInt(m[1], 10));
      }
      return nums.length ? nums : [Number.MAX_SAFE_INTEGER];
    },
    compareInterfaces(a, b) {
      const aRank = this.interfaceTypeRank(a.interface);
      const bRank = this.interfaceTypeRank(b.interface);
      if (aRank !== bRank) return aRank - bRank;
      const aNums = this.extractNumbers(a.interface);
      const bNums = this.extractNumbers(b.interface);
      const len = Math.max(aNums.length, bNums.length);
      for (let i = 0; i < len; i++) {
        const av = aNums[i] ?? -1;
        const bv = bNums[i] ?? -1;
        if (av !== bv) return av - bv;
      }
      return (a.interface || '').localeCompare(b.interface || '');
    },
    isPortChannel(interfaceName) {
      // Check if interface is a port channel
      if (!interfaceName) return false;
      const name = interfaceName.toLowerCase();
      return name.startsWith('port-channel') || name.startsWith('portchannel') || name.startsWith('po');
    },
    rowClass(r) {
      if (this.isPortChannel(r.interface)) {
        return 'table-info'; // Light blue for port channels (read-only)
      }
      return r.mode === 'trunk' ? 'table-warning' : '';
    },
    setCell(row, vlanId, value) {
      // Enforce single untagged per port
      if (value === 'untagged') {
        Object.keys(row.assignments).forEach(vid => {
          if (vid !== String(vlanId) && row.assignments[vid] === 'untagged') {
            row.assignments[vid] = 'none';
          }
        });
      }
      row.assignments[String(vlanId)] = value;
    },
    validateMatrix() {
      const errors = [];
      for (const row of this.rows) {
        const states = Object.values(row.assignments);
        const untaggedCount = states.filter(s => s === 'untagged').length;
        const taggedCount = states.filter(s => s === 'tagged').length;
        if (row.mode === 'access') {
          if (untaggedCount !== 1 || taggedCount > 0) {
            errors.push(`${row.interface}: access mode requires exactly one untagged VLAN and no tagged VLANs`);
          }
        } else if (row.mode === 'trunk') {
          if (untaggedCount > 1) {
            errors.push(`${row.interface}: trunk mode allows at most one untagged (native) VLAN`);
          }
          if (untaggedCount === 0 && taggedCount === 0) {
            errors.push(`${row.interface}: trunk mode requires at least one VLAN (tagged or untagged)`);
          }
        }
      }
      return errors;
    },
    async applyChanges() {
      if (!this.canEdit) return;
      if (!this.csrfToken) {
        this.$emit('show-message', 'CSRF token not available', 'error');
        return;
      }
      const errors = this.validateMatrix();
      if (errors.length) {
        this.$emit('show-message', errors[0], 'error');
        return;
      }
      const payload = {
        csrf_token: this.csrfToken,
        changes: this.rows.map(r => ({
          interface: r.interface,
          mode: r.mode,
          assignments: r.assignments
        }))
      };
      this.saving = true;
      try {
        const resp = await axios.post(
          `${API_BASE_URL}/switches/vlan-matrix/apply.php?switch_id=${this.switchId}`,
          payload,
          { withCredentials: true, headers: { 'Content-Type': 'application/json' } }
        );
        if (resp.data?.success) {
          this.$emit('show-message', `Applied changes to ${resp.data.applied} interface(s)`, 'success');
          this.$emit('config-changed');
          await this.loadMatrix();
        } else {
          const first = (resp.data?.errors || [])[0] || 'Failed to apply VLAN matrix';
          this.$emit('show-message', first, 'error');
        }
      } catch (e) {
        this.$emit('show-message', e.response?.data?.error || e.message, 'error');
      } finally {
        this.saving = false;
      }
    }
  },
  template: `
    <div class="vlan-matrix">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fas fa-table me-2"></i> VLAN Matrix</h5>
          <div>
            <input class="form-control form-control-sm d-inline-block me-2" style="width: 220px"
                   placeholder="Search interfaces..." v-model="search" />
            <button class="btn btn-primary btn-sm" :disabled="!canEdit || saving" @click="applyChanges">
              <span v-if="saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
              Apply Changes
            </button>
          </div>
        </div>
        <div class="card-body p-0">
          <div v-if="error" class="alert alert-danger m-3">{{ error }}</div>
          <div v-else-if="loading" class="text-center py-4">
            <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
          </div>
          <div v-else class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th style="position: sticky; left: 0; background: #f8f9fa; z-index: 1;">Interface</th>
                  <th style="position: sticky; left: 140px; background: #f8f9fa; z-index: 1;">Mode</th>
                  <th v-for="v in vlans" :key="v.vlan_id">VLAN {{ v.vlan_id }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in filteredRows" :key="r.interface" :class="rowClass(r)">
                  <td style="position: sticky; left: 0; background: #fff; z-index: 1;">
                    <strong>{{ r.interface }}</strong>
                    <span v-if="isPortChannel(r.interface)" class="badge bg-info ms-2" title="Port Channel (read-only)">
                      <i class="fas fa-link me-1"></i>Port Channel
                    </span>
                    <span v-else-if="r.isPortChannelMember" class="badge bg-warning ms-2" title="Member of port channel">
                      <i class="fas fa-link me-1"></i>{{ r.portChannelName }}
                    </span>
                  </td>
                  <td style="position: sticky; left: 140px; background: #fff; z-index: 1;">
                    <span v-if="isPortChannel(r.interface) || r.isPortChannelMember" class="badge" :class="r.mode === 'trunk' ? 'bg-warning text-dark' : 'bg-secondary'">
                      {{ r.mode }}
                    </span>
                    <select v-else class="form-select form-select-sm" v-model="r.mode" :disabled="!canEdit" @change="onModeChange(r)">
                      <option value="access">Access</option>
                      <option value="trunk">Trunk</option>
                    </select>
                  </td>
                  <td v-for="v in vlans" :key="v.vlan_id" class="text-center">
                    <span v-if="isPortChannel(r.interface)" class="badge" :class="
                      r.assignments[String(v.vlan_id)] === 'tagged' ? 'bg-success' :
                      r.assignments[String(v.vlan_id)] === 'untagged' ? 'bg-primary' :
                      'bg-light text-muted'
                    ">
                      {{ r.assignments[String(v.vlan_id)] || 'none' }}
                    </span>
                    <span v-else-if="r.isPortChannelMember" class="text-muted">—</span>
                    <select v-else class="form-select form-select-sm" :disabled="!canEdit"
                            :value="r.assignments[String(v.vlan_id)] || 'none'"
                            @change="setCell(r, v.vlan_id, $event.target.value)">
                      <option value="none">None</option>
                      <option value="tagged" :disabled="r.mode==='access'">Tagged</option>
                      <option value="untagged">Untagged</option>
                    </select>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  `
};


