// Permission Management Component
// API Base URL - adjust if needed
const API_BASE_URL = '/arista/api';

export default {
  name: 'PermissionManagement',
  props: {
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
      loading: false,
      saving: false,
      users: [],
      permissions: [],
      selectedUser: null,
      userPermissions: null,
      permissionChanges: {},
      searchTerm: '',
      filterCategory: ''
    }
  },
  computed: {
    filteredUsers() {
      if (!this.searchTerm) return this.users;
      const term = this.searchTerm.toLowerCase();
      return this.users.filter(user =>
        user.username.toLowerCase().includes(term) ||
        user.role.toLowerCase().includes(term)
      );
    },
    canManagePermissions() {
      return this.user.role === 'admin';
    },
    permissionsByCategory() {
      const categories = {};
      this.permissions.forEach(perm => {
        if (!categories[perm.category]) {
          categories[perm.category] = [];
        }
        categories[perm.category].push(perm);
      });
      return categories;
    },
    hasChanges() {
      return Object.keys(this.permissionChanges).length > 0;
    },
    directPermissionSet() {
      if (!this.userPermissions) return new Set();
      return new Set(this.userPermissions.direct_permissions.map(p => p.name));
    },
    rolePermissionSet() {
      if (!this.userPermissions) return new Set();
      return new Set(this.userPermissions.role_permissions.map(p => p.name));
    }
  },
  mounted() {
    if (this.canManagePermissions) {
      this.loadUsers();
      this.loadPermissions();
    }
  },
  methods: {
    async loadUsers() {
      try {
        const response = await axios.get(`${API_BASE_URL}/permissions/users.php`, {
          withCredentials: true
        });

        if (response.data.success) {
          this.users = response.data.users;
        } else {
          this.showError('Failed to load users');
        }
      } catch (error) {
        this.showError('Failed to load users: ' + (error.response?.data?.error || error.message));
      }
    },

    async loadPermissions() {
      try {
        const response = await axios.get(`${API_BASE_URL}/permissions/list.php`, {
          withCredentials: true
        });

        if (response.data.success) {
          this.permissions = response.data.permissions;
        }
      } catch (error) {
        this.showError('Failed to load permissions: ' + (error.response?.data?.error || error.message));
      }
    },

    async selectUser(user) {
      this.selectedUser = user;
      this.loading = true;
      this.permissionChanges = {};

      try {
        const response = await axios.get(`${API_BASE_URL}/permissions/user_permissions.php?user_id=${user.id}`, {
          withCredentials: true
        });

        if (response.data.success) {
          this.userPermissions = response.data;
        }
      } catch (error) {
        this.showError('Failed to load user permissions: ' + (error.response?.data?.error || error.message));
      } finally {
        this.loading = false;
      }
    },

    togglePermission(permissionName) {
      if (this.rolePermissionSet.has(permissionName)) {
        this.showError('Cannot modify role-based permissions');
        return;
      }

      const isDirect = this.directPermissionSet.has(permissionName);
      if (this.permissionChanges[permissionName] === undefined) {
        this.permissionChanges[permissionName] = !isDirect;
      } else {
        const desiredState = !isDirect;
        if (this.permissionChanges[permissionName] === desiredState) {
          delete this.permissionChanges[permissionName];
        } else {
          this.permissionChanges[permissionName] = desiredState;
        }
      }
    },

    async savePermissions() {
      if (!this.selectedUser || !this.hasChanges || this.saving) {
        return;
      }

      if (!this.csrfToken) {
        this.showError('CSRF token not available. Please refresh and try again.');
        return;
      }

      this.saving = true;
      const changes = { ...this.permissionChanges };

      try {
        for (const [permName, shouldGrant] of Object.entries(changes)) {
          if (shouldGrant) {
            await axios.post(`${API_BASE_URL}/permissions/grant.php`, {
              user_id: this.selectedUser.id,
              permission_name: permName,
              csrf_token: this.csrfToken
            }, {
              withCredentials: true,
              headers: { 'Content-Type': 'application/json' }
            });
          } else {
            await axios.post(`${API_BASE_URL}/permissions/revoke.php`, {
              user_id: this.selectedUser.id,
              permission_name: permName,
              csrf_token: this.csrfToken
            }, {
              withCredentials: true,
              headers: { 'Content-Type': 'application/json' }
            });
          }
        }

        this.showSuccess('Permissions saved successfully');
        this.permissionChanges = {};
        await this.selectUser(this.selectedUser);
        await this.loadUsers();
      } catch (error) {
        this.showError('Failed to save permissions: ' + (error.response?.data?.error || error.message));
      } finally {
        this.saving = false;
      }
    },

    resetChanges() {
      this.permissionChanges = {};
    },

    showError(message) {
      this.$emit('show-message', message, 'error');
    },

    showSuccess(message) {
      this.$emit('show-message', message, 'success');
    },

    isPermissionGranted(permissionName) {
      const isDirect = this.directPermissionSet.has(permissionName);
      const isRole = this.rolePermissionSet.has(permissionName);
      
      if (this.permissionChanges[permissionName] !== undefined) {
        return this.permissionChanges[permissionName];
      }
      
      return isDirect || isRole;
    },

    getPermissionBadge(permissionName) {
      const isDirect = this.directPermissionSet.has(permissionName);
      const isRole = this.rolePermissionSet.has(permissionName);
      const hasChange = this.permissionChanges[permissionName] !== undefined;

      if (hasChange) {
        return this.permissionChanges[permissionName] ? 'Pending Grant' : 'Pending Revoke';
      }

      if (isRole) return 'Role-Based';
      if (isDirect) return 'Direct';
      return 'Not Granted';
    },

    getPermissionBadgeClass(permissionName) {
      const isDirect = this.directPermissionSet.has(permissionName);
      const isRole = this.rolePermissionSet.has(permissionName);
      const hasChange = this.permissionChanges[permissionName] !== undefined;

      if (hasChange) {
        return this.permissionChanges[permissionName] ? 'bg-warning' : 'bg-danger';
      }

      if (isRole) return 'bg-info';
      if (isDirect) return 'bg-primary';
      return 'bg-secondary';
    }
  },
  template: `
    <div class="permission-management">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="fas fa-shield-alt me-2"></i>
            Permission Management
          </h5>
          <div v-if="selectedUser">
            <button
              class="btn btn-success btn-sm me-2"
              @click="savePermissions"
              :disabled="!hasChanges || saving"
            >
              <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
              {{ saving ? 'Saving...' : 'Save Changes' }}
            </button>
            <button
              class="btn btn-secondary btn-sm"
              @click="resetChanges"
              :disabled="!hasChanges"
            >
              <i class="fas fa-undo me-1"></i>
              Reset
            </button>
          </div>
        </div>

        <div class="card-body">
          <div v-if="!canManagePermissions" class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            You don't have permission to manage permissions.
          </div>

          <div v-else>
            <div class="row">
              <!-- Users List -->
              <div class="col-md-4">
                <div class="card">
                  <div class="card-header">
                    <h6 class="mb-0">Users</h6>
                    <input
                      type="text"
                      class="form-control form-control-sm mt-2"
                      placeholder="Search users..."
                      v-model="searchTerm"
                    />
                  </div>
                  <div class="card-body p-0" style="max-height: 600px; overflow-y: auto;">
                    <div class="list-group list-group-flush">
                      <button
                        v-for="user in filteredUsers"
                        :key="user.id"
                        class="list-group-item list-group-item-action text-start"
                        :class="{ active: selectedUser && selectedUser.id === user.id }"
                        @click="selectUser(user)"
                      >
                        <div class="d-flex justify-content-between align-items-start w-100">
                          <div>
                            <strong>{{ user.username }}</strong>
                            <br>
                            <small class="text-muted">{{ user.role }}</small>
                          </div>
                          <div class="text-end">
                            <small class="text-muted">
                              <i class="fas fa-shield-alt"></i> {{ user.direct_permissions }}
                            </small>
                          </div>
                        </div>
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Permissions Editor -->
              <div class="col-md-8">
                <div v-if="!selectedUser" class="alert alert-info">
                  <i class="fas fa-info-circle me-2"></i>
                  Select a user to manage their permissions.
                </div>

                <div v-else>
                  <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                      <span class="visually-hidden">Loading...</span>
                    </div>
                  </div>

                  <div v-else-if="userPermissions">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                      <h6 class="mb-0">
                        Permissions for <strong>{{ selectedUser.username }}</strong>
                        <span class="badge bg-secondary ms-2">{{ selectedUser.role }}</span>
                      </h6>
                      <div>
                        <small v-if="hasChanges" class="text-warning">
                          <i class="fas fa-exclamation-circle me-1"></i>
                          {{ Object.keys(permissionChanges).length }} pending change(s)
                        </small>
                      </div>
                    </div>

                    <div v-for="(perms, category) in permissionsByCategory" :key="category" class="mb-4">
                      <h6 class="text-capitalize text-muted">
                        <i class="fas fa-folder-open me-1"></i>
                        {{ category }}
                      </h6>

                      <div class="row">
                        <div
                          v-for="perm in perms"
                          :key="perm.name"
                          class="col-md-6 mb-2"
                        >
                          <div class="form-check">
                            <input
                              class="form-check-input"
                              type="checkbox"
                              :id="'perm-' + selectedUser.id + '-' + perm.name"
                              :checked="isPermissionGranted(perm.name)"
                              :disabled="rolePermissionSet.has(perm.name) || saving"
                              @change="togglePermission(perm.name)"
                            />
                            <label class="form-check-label" :for="'perm-' + selectedUser.id + '-' + perm.name">
                              <strong>{{ perm.display_name }}</strong>
                              <br>
                              <small class="text-muted">{{ perm.name }}</small>
                            </label>
                            <div class="ms-4">
                              <span :class="['badge', getPermissionBadgeClass(perm.name)]">
                                {{ getPermissionBadge(perm.name) }}
                              </span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>

                    <hr />
                    <small class="text-muted">
                      <i class="fas fa-info-circle me-1"></i>
                      <strong>Role-Based</strong> permissions (from user's role) are locked. Modify direct permissions as needed and click "Save Changes".
                    </small>
                  </div>

                  <div v-else class="text-center text-muted py-4">
                    Unable to load permissions.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
};
