// VLAN Management Component
const API_BASE_URL = '/arista/api';

export default {
  name: 'VLANManagement',
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
    },
    isPolling: {
      type: Boolean,
      default: false
    }
  },
  data() {
    return {
      vlans: [],
      switchInfo: null,
      loading: false,
      saving: false,
      deleting: false,
      syncing: false,
      error: null,
      showCreateModal: false,
      showEditModal: false,
      showDeleteConfirm: false,
      selectedVlan: null,
      searchTerm: '',
      newVlan: {
        vlan_id: '',
        name: '',
        description: ''
      },
      editVlan: {
        vlan_id: '',
        name: '',
        description: ''
      },
      vlanToDelete: null,
      validationErrors: []
    }
  },
  computed: {
    canManageVLANs() {
      return this.user.role === 'admin' || this.user.role === 'operator';
    },
    filteredVlans() {
      if (!this.searchTerm) return this.vlans;
      const term = this.searchTerm.toLowerCase();
      return this.vlans.filter(vlan =>
        vlan.vlan_id.toString().includes(term) ||
        (vlan.name && vlan.name.toLowerCase().includes(term)) ||
        (vlan.description && vlan.description.toLowerCase().includes(term))
      );
    }
  },
  mounted() {
    this.loadSwitchInfo();
    this.autoRefreshOnLoad();
  },
  methods: {
    async autoRefreshOnLoad() {
      // Always try to sync from switch on first load to avoid stale cache
      try {
        // Silent sync (no confirm, no toast); requires operator/admin
        await axios.post(
          `${API_BASE_URL}/switches/vlans/sync.php?switch_id=${this.switchId}`,
          {},
          { withCredentials: true, headers: { 'Content-Type': 'application/json' } }
        );
      } catch (e) {
        // Non-fatal; we'll still load what we have
      } finally {
        await this.loadVLANs();
      }
    },
    async loadSwitchInfo() {
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/get.php?id=${this.switchId}`, {
          withCredentials: true
        });
        if (response.data.success) {
          this.switchInfo = response.data.switch;
        }
      } catch (error) {
        console.error('Failed to load switch info:', error);
      }
    },

    async loadVLANs() {
      this.loading = true;
      this.error = null;
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/vlans/list.php`, {
          params: { switch_id: this.switchId, _ts: Date.now() }, // cache-buster
          withCredentials: true
        });
        if (response.data.success) {
          this.vlans = response.data.vlans;
        } else {
          this.error = response.data.error || 'Failed to load VLANs';
        }
      } catch (error) {
        this.error = 'Failed to load VLANs: ' + (error.response?.data?.error || error.message);
      } finally {
        this.loading = false;
      }
    },

    async syncVLANsFromSwitch() {
      if (!confirm('This will fetch VLANs directly from the switch and update the database. Continue?')) {
        return;
      }

      this.syncing = true;
      try {
        // Use sync endpoint to persist VLANs in DB
        const response = await axios.post(
          `${API_BASE_URL}/switches/vlans/sync.php?switch_id=${this.switchId}`,
          {}, // no body needed
          { withCredentials: true, headers: { 'Content-Type': 'application/json' } }
        );

        if (response.data?.success) {
          const count = response.data.synced_count ?? 0;
          this.$emit('show-message', `Synced ${count} VLAN${count === 1 ? '' : 's'} from switch`, 'success');
        } else {
          this.$emit('show-message', response.data?.error || 'Failed to sync VLANs', 'error');
        }
        await this.loadVLANs();
      } catch (error) {
        this.$emit('show-message', 'Failed to sync VLANs: ' + (error.response?.data?.error || error.message), 'error');
      } finally {
        this.syncing = false;
      }
    },

    openCreateModal() {
      this.validationErrors = [];
      this.newVlan = { vlan_id: '', name: '', description: '' };
      this.showCreateModal = true;
    },

    async createVlan() {
      this.validationErrors = [];

      if (!this.newVlan.vlan_id || !Number.isInteger(Number(this.newVlan.vlan_id))) {
        this.validationErrors.push('VLAN ID is required and must be a number');
      } else if (Number(this.newVlan.vlan_id) < 1 || Number(this.newVlan.vlan_id) > 4094) {
        this.validationErrors.push('VLAN ID must be between 1 and 4094');
      }

      if (this.validationErrors.length > 0) return;

      if (!this.csrfToken) {
        this.$emit('show-message', 'CSRF token not available', 'error');
        return;
      }

      this.saving = true;
      try {
        const response = await axios.post(`${API_BASE_URL}/switches/vlans/create.php?switch_id=${this.switchId}`, {
          vlan_id: Number(this.newVlan.vlan_id),
          name: this.newVlan.name || null,
          description: this.newVlan.description || null,
          csrf_token: this.csrfToken
        }, {
          withCredentials: true,
          headers: { 'Content-Type': 'application/json' }
        });

        if (response.data.success) {
          this.$emit('show-message', `VLAN ${this.newVlan.vlan_id} created successfully`, 'success');
          this.showCreateModal = false;
          await this.loadVLANs();
          this.$emit('config-changed');
        } else {
          this.$emit('show-message', response.data.error || 'Failed to create VLAN', 'error');
        }
      } catch (error) {
        const errMsg = error.response?.data?.errors?.join(', ') || error.response?.data?.error || error.message;
        this.$emit('show-message', 'Failed to create VLAN: ' + errMsg, 'error');
      } finally {
        this.saving = false;
      }
    },

    openEditModal(vlan) {
      this.validationErrors = [];
      this.selectedVlan = vlan;
      this.editVlan = {
        vlan_id: vlan.vlan_id,
        name: vlan.name || '',
        description: vlan.description || ''
      };
      this.showEditModal = true;
    },

    async updateVlan() {
      this.validationErrors = [];

      if (!this.csrfToken) {
        this.$emit('show-message', 'CSRF token not available', 'error');
        return;
      }

      this.saving = true;
      try {
        const response = await axios.put(
          `${API_BASE_URL}/switches/vlans/update.php?switch_id=${this.switchId}&vlan_id=${this.selectedVlan.vlan_id}`,
          {
            name: this.editVlan.name || null,
            description: this.editVlan.description || null,
            csrf_token: this.csrfToken
          },
          {
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );

        if (response.data.success) {
          this.$emit('show-message', `VLAN ${this.selectedVlan.vlan_id} updated successfully`, 'success');
          this.showEditModal = false;
          await this.loadVLANs();
          this.$emit('config-changed');
        } else {
          this.$emit('show-message', response.data.error || 'Failed to update VLAN', 'error');
        }
      } catch (error) {
        const errMsg = error.response?.data?.error || error.message;
        this.$emit('show-message', 'Failed to update VLAN: ' + errMsg, 'error');
      } finally {
        this.saving = false;
      }
    },

    openDeleteConfirm(vlan) {
      this.vlanToDelete = vlan;
      this.showDeleteConfirm = true;
    },

    async deleteVlan() {
      if (!this.vlanToDelete || !this.csrfToken) return;

      this.deleting = true;
      try {
        const response = await axios.delete(
          `${API_BASE_URL}/switches/vlans/delete.php?switch_id=${this.switchId}&vlan_id=${this.vlanToDelete.vlan_id}`,
          {
            data: { csrf_token: this.csrfToken },
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );

        if (response.data.success) {
          this.$emit('show-message', `VLAN ${this.vlanToDelete.vlan_id} deleted successfully`, 'success');
          this.showDeleteConfirm = false;
          this.vlanToDelete = null;
          await this.loadVLANs();
          this.$emit('config-changed');
        } else {
          this.$emit('show-message', response.data.error || 'Failed to delete VLAN', 'error');
        }
      } catch (error) {
        this.$emit('show-message', 'Failed to delete VLAN: ' + (error.response?.data?.error || error.message), 'error');
      } finally {
        this.deleting = false;
      }
    },

    cancelDelete() {
      this.showDeleteConfirm = false;
      this.vlanToDelete = null;
    }
  },
  template: `
    <div class="vlan-management">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="fas fa-layer-group me-2"></i>
            VLANs - {{ switchInfo?.hostname || 'Switch' }}
          </h5>
          <div>
            <button
              class="btn btn-secondary btn-sm me-2"
              @click="syncVLANsFromSwitch"
              :disabled="!canManageVLANs || syncing"
            >
              <span v-if="syncing" class="spinner-border spinner-border-sm me-1" role="status"></span>
              <i class="fas fa-download me-1"></i>
              {{ syncing ? 'Syncing...' : 'Sync from Switch' }}
            </button>
            <button
              class="btn btn-primary btn-sm"
              @click="openCreateModal"
              :disabled="!canManageVLANs || isPolling"
              :title="isPolling ? 'Disabled during polling' : ''"
            >
              <i class="fas fa-plus me-1"></i>
              Add VLAN
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
                placeholder="Search VLANs by ID, name, or description..."
                v-model="searchTerm"
              />
            </div>

            <div v-if="filteredVlans.length === 0" class="alert alert-info">
              <i class="fas fa-info-circle me-2"></i>
              No VLANs found.
            </div>

            <div v-else class="table-responsive">
              <table class="table table-hover">
                <thead>
                  <tr>
                    <th>VLAN ID</th>
                    <th>Name</th>
                    <th>Description</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="vlan in filteredVlans" :key="vlan.id">
                    <td>
                      <strong>{{ vlan.vlan_id }}</strong>
                    </td>
                    <td>{{ vlan.name || '-' }}</td>
                    <td>
                      <small class="text-muted">{{ vlan.description || '-' }}</small>
                    </td>
                    <td class="text-end">
                      <button
                        v-if="canManageVLANs"
                        class="btn btn-sm btn-outline-primary me-1"
                        @click="openEditModal(vlan)"
                        :disabled="isPolling"
                        :title="isPolling ? 'Disabled during polling' : 'Edit VLAN'"
                      >
                        <i class="fas fa-edit"></i>
                      </button>
                      <button
                        v-if="canManageVLANs"
                        class="btn btn-sm btn-outline-danger"
                        @click="openDeleteConfirm(vlan)"
                        :disabled="isPolling"
                        :title="isPolling ? 'Disabled during polling' : 'Delete VLAN'"
                      >
                        <i class="fas fa-trash"></i>
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Create VLAN Modal -->
      <div v-if="showCreateModal" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Create VLAN</h5>
              <button type="button" class="btn-close" @click="showCreateModal = false"></button>
            </div>
            <div class="modal-body">
              <div v-if="validationErrors.length > 0" class="alert alert-danger">
                <ul class="mb-0">
                  <li v-for="error in validationErrors" :key="error">{{ error }}</li>
                </ul>
              </div>

              <div class="mb-3">
                <label class="form-label">VLAN ID *</label>
                <input
                  type="number"
                  class="form-control"
                  v-model="newVlan.vlan_id"
                  placeholder="1-4094"
                  min="1"
                  max="4094"
                  :disabled="saving"
                />
              </div>

              <div class="mb-3">
                <label class="form-label">Name</label>
                <input
                  type="text"
                  class="form-control"
                  v-model="newVlan.name"
                  placeholder="Enter VLAN name"
                  :disabled="saving"
                />
              </div>

              <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea
                  class="form-control"
                  v-model="newVlan.description"
                  placeholder="Enter description"
                  rows="2"
                  :disabled="saving"
                ></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" @click="showCreateModal = false" :disabled="saving">Cancel</button>
              <button type="button" class="btn btn-primary" @click="createVlan" :disabled="saving">
                <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ saving ? 'Creating...' : 'Create VLAN' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Edit VLAN Modal -->
      <div v-if="showEditModal" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Edit VLAN {{ selectedVlan?.vlan_id }}</h5>
              <button type="button" class="btn-close" @click="showEditModal = false"></button>
            </div>
            <div class="modal-body">
              <div v-if="validationErrors.length > 0" class="alert alert-danger">
                <ul class="mb-0">
                  <li v-for="error in validationErrors" :key="error">{{ error }}</li>
                </ul>
              </div>

              <div class="mb-3">
                <label class="form-label">Name</label>
                <input
                  type="text"
                  class="form-control"
                  v-model="editVlan.name"
                  placeholder="Enter VLAN name"
                  :disabled="saving"
                />
              </div>

              <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea
                  class="form-control"
                  v-model="editVlan.description"
                  placeholder="Enter description"
                  rows="2"
                  :disabled="saving"
                ></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" @click="showEditModal = false" :disabled="saving">Cancel</button>
              <button type="button" class="btn btn-primary" @click="updateVlan" :disabled="saving">
                <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ saving ? 'Updating...' : 'Update VLAN' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Delete Confirmation Modal -->
      <div v-if="showDeleteConfirm" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-sm">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Delete VLAN?</h5>
              <button type="button" class="btn-close" @click="cancelDelete"></button>
            </div>
            <div class="modal-body">
              <p>
                Are you sure you want to delete VLAN <strong>{{ vlanToDelete?.vlan_id }}</strong>?
                <span v-if="vlanToDelete?.name"> ({{ vlanToDelete.name }})</span>
              </p>
              <p class="text-muted small">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" @click="cancelDelete" :disabled="deleting">Cancel</button>
              <button type="button" class="btn btn-danger" @click="deleteVlan" :disabled="deleting">
                <span v-if="deleting" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ deleting ? 'Deleting...' : 'Delete' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
};

