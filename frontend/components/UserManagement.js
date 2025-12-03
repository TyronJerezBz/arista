// User Management Component
const API_BASE_URL = '/arista/api';

export default {
  name: 'UserManagement',
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
      users: [],
      loading: false,
      showCreateModal: false,
      showEditModal: false,
      showDeleteConfirm: false,
      selectedUser: null,
      searchTerm: '',
      newUser: {
        username: '',
        password: '',
        role: 'viewer'
      },
      editUser: {
        username: '',
        password: '',
        role: 'viewer'
      },
      userToDelete: null,
      saving: false,
      validationErrors: [],
      roles: ['admin', 'operator', 'viewer']
    }
  },
  computed: {
    canManageUsers() {
      return this.user.role === 'admin';
    },
    filteredUsers() {
      if (!this.searchTerm) return this.users;
      const term = this.searchTerm.toLowerCase();
      return this.users.filter(u =>
        u.username.toLowerCase().includes(term) ||
        u.role.toLowerCase().includes(term)
      );
    }
  },
  mounted() {
    if (this.canManageUsers) {
      this.loadUsers();
    }
  },
  methods: {
    async loadUsers() {
      this.loading = true;
      try {
        const response = await axios.get(`${API_BASE_URL}/users/list.php`, {
          withCredentials: true
        });
        if (response.data.success) {
          this.users = response.data.users;
        } else {
          this.showError('Failed to load users');
        }
      } catch (error) {
        this.showError('Failed to load users: ' + (error.response?.data?.error || error.message));
      } finally {
        this.loading = false;
      }
    },

    openCreateModal() {
      this.validationErrors = [];
      this.newUser = { username: '', password: '', role: 'viewer' };
      this.showCreateModal = true;
    },

    async createUser() {
      this.validationErrors = [];
      
      if (!this.newUser.username.trim()) {
        this.validationErrors.push('Username is required');
      }
      if (!this.newUser.password.trim()) {
        this.validationErrors.push('Password is required');
      }
      if (this.validationErrors.length > 0) {
        return;
      }

      if (!this.csrfToken) {
        this.showError('CSRF token not available');
        return;
      }

      this.saving = true;
      try {
        const response = await axios.post(`${API_BASE_URL}/users/create.php`, {
          username: this.newUser.username,
          password: this.newUser.password,
          role: this.newUser.role,
          csrf_token: this.csrfToken
        }, {
          withCredentials: true,
          headers: { 'Content-Type': 'application/json' }
        });

        if (response.data.success) {
          this.showSuccess('User created successfully');
          this.showCreateModal = false;
          await this.loadUsers();
        } else {
          this.showError(response.data.error || 'Failed to create user');
        }
      } catch (error) {
        const errMsg = error.response?.data?.errors?.join(', ') || error.response?.data?.error || error.message;
        this.showError('Failed to create user: ' + errMsg);
      } finally {
        this.saving = false;
      }
    },

    openEditModal(user) {
      this.validationErrors = [];
      this.selectedUser = user;
      this.editUser = {
        username: user.username,
        password: '',
        role: user.role
      };
      this.showEditModal = true;
    },

    async updateUser() {
      this.validationErrors = [];
      
      if (!this.editUser.username.trim()) {
        this.validationErrors.push('Username is required');
      }
      if (this.validationErrors.length > 0) {
        return;
      }

      if (!this.csrfToken) {
        this.showError('CSRF token not available');
        return;
      }

      this.saving = true;
      try {
        const payload = {
          username: this.editUser.username,
          role: this.editUser.role,
          csrf_token: this.csrfToken
        };
        if (this.editUser.password.trim()) {
          payload.password = this.editUser.password;
        }

        const response = await axios.put(`${API_BASE_URL}/users/update.php?id=${this.selectedUser.id}`, payload, {
          withCredentials: true,
          headers: { 'Content-Type': 'application/json' }
        });

        if (response.data.success) {
          this.showSuccess('User updated successfully');
          this.showEditModal = false;
          await this.loadUsers();
        } else {
          this.showError(response.data.error || 'Failed to update user');
        }
      } catch (error) {
        const errMsg = error.response?.data?.errors?.join(', ') || error.response?.data?.error || error.message;
        this.showError('Failed to update user: ' + errMsg);
      } finally {
        this.saving = false;
      }
    },

    openDeleteConfirm(user) {
      if (user.id === this.user.id) {
        this.showError('You cannot delete your own account');
        return;
      }
      this.userToDelete = user;
      this.showDeleteConfirm = true;
    },

    async deleteUser() {
      if (!this.userToDelete || !this.csrfToken) {
        return;
      }

      this.saving = true;
      try {
        const response = await axios.delete(`${API_BASE_URL}/users/delete.php?id=${this.userToDelete.id}`, {
          data: { csrf_token: this.csrfToken },
          withCredentials: true,
          headers: { 'Content-Type': 'application/json' }
        });

        if (response.data.success) {
          this.showSuccess('User deleted successfully');
          this.showDeleteConfirm = false;
          this.userToDelete = null;
          await this.loadUsers();
        } else {
          this.showError(response.data.error || 'Failed to delete user');
        }
      } catch (error) {
        this.showError('Failed to delete user: ' + (error.response?.data?.error || error.message));
      } finally {
        this.saving = false;
      }
    },

    cancelDelete() {
      this.showDeleteConfirm = false;
      this.userToDelete = null;
    },

    showError(message) {
      this.$emit('show-message', message, 'error');
    },

    showSuccess(message) {
      this.$emit('show-message', message, 'success');
    }
  },
  template: `
    <div class="user-management">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="fas fa-users me-2"></i>
            User Management
          </h5>
          <button
            class="btn btn-primary btn-sm"
            @click="openCreateModal"
            :disabled="!canManageUsers"
          >
            <i class="fas fa-plus me-1"></i>
            Add User
          </button>
        </div>

        <div class="card-body">
          <div v-if="!canManageUsers" class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            You don't have permission to manage users.
          </div>

          <div v-else>
            <div class="mb-3">
              <input
                type="text"
                class="form-control"
                placeholder="Search users..."
                v-model="searchTerm"
              />
            </div>

            <div v-if="loading" class="text-center py-4">
              <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
              </div>
            </div>

            <div v-else-if="filteredUsers.length > 0" class="table-responsive">
              <table class="table table-hover">
                <thead>
                  <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Created</th>
                    <th>Last Login</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="u in filteredUsers" :key="u.id">
                    <td>
                      <strong>{{ u.username }}</strong>
                      <span v-if="u.id === user.id" class="badge bg-secondary ms-2">You</span>
                    </td>
                    <td>
                      <span class="badge" :class="u.role === 'admin' ? 'bg-danger' : u.role === 'operator' ? 'bg-warning' : 'bg-secondary'">
                        {{ u.role }}
                      </span>
                    </td>
                    <td>
                      <small class="text-muted">-</small>
                    </td>
                    <td>
                      <small class="text-muted">-</small>
                    </td>
                    <td class="text-end">
                      <button
                        class="btn btn-sm btn-outline-primary"
                        @click="openEditModal(u)"
                        title="Edit user"
                      >
                        <i class="fas fa-edit"></i>
                      </button>
                      <button
                        class="btn btn-sm btn-outline-danger"
                        @click="openDeleteConfirm(u)"
                        :disabled="u.id === user.id"
                        title="Delete user"
                      >
                        <i class="fas fa-trash"></i>
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-else class="alert alert-info">
              <i class="fas fa-info-circle me-2"></i>
              No users found.
            </div>
          </div>
        </div>
      </div>

      <!-- Create User Modal -->
      <div v-if="showCreateModal" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Add New User</h5>
              <button type="button" class="btn-close" @click="showCreateModal = false"></button>
            </div>
            <div class="modal-body">
              <div v-if="validationErrors.length > 0" class="alert alert-danger">
                <ul class="mb-0">
                  <li v-for="error in validationErrors" :key="error">{{ error }}</li>
                </ul>
              </div>

              <div class="mb-3">
                <label class="form-label">Username:</label>
                <input
                  type="text"
                  class="form-control"
                  v-model="newUser.username"
                  placeholder="Enter username"
                  :disabled="saving"
                />
              </div>

              <div class="mb-3">
                <label class="form-label">Password:</label>
                <input
                  type="password"
                  class="form-control"
                  v-model="newUser.password"
                  placeholder="Enter password"
                  :disabled="saving"
                />
                <small class="text-muted">
                  Password should be at least 8 characters with uppercase, lowercase, and numbers.
                </small>
              </div>

              <div class="mb-3">
                <label class="form-label">Role:</label>
                <select class="form-select" v-model="newUser.role" :disabled="saving">
                  <option v-for="role in roles" :key="role" :value="role">
                    {{ role.charAt(0).toUpperCase() + role.slice(1) }}
                  </option>
                </select>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" @click="showCreateModal = false" :disabled="saving">Cancel</button>
              <button type="button" class="btn btn-primary" @click="createUser" :disabled="saving">
                <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ saving ? 'Creating...' : 'Create User' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Edit User Modal -->
      <div v-if="showEditModal" class="modal fade show d-block" style="background-color: rgba(0,0,0,0.5);">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Edit User: {{ selectedUser.username }}</h5>
              <button type="button" class="btn-close" @click="showEditModal = false"></button>
            </div>
            <div class="modal-body">
              <div v-if="validationErrors.length > 0" class="alert alert-danger">
                <ul class="mb-0">
                  <li v-for="error in validationErrors" :key="error">{{ error }}</li>
                </ul>
              </div>

              <div class="mb-3">
                <label class="form-label">Username:</label>
                <input
                  type="text"
                  class="form-control"
                  v-model="editUser.username"
                  placeholder="Enter username"
                  :disabled="saving"
                />
              </div>

              <div class="mb-3">
                <label class="form-label">Password (leave empty to keep current):</label>
                <input
                  type="password"
                  class="form-control"
                  v-model="editUser.password"
                  placeholder="Enter new password"
                  :disabled="saving"
                />
                <small class="text-muted">
                  Leave blank to keep the current password.
                </small>
              </div>

              <div class="mb-3">
                <label class="form-label">Role:</label>
                <select class="form-select" v-model="editUser.role" :disabled="saving">
                  <option v-for="role in roles" :key="role" :value="role">
                    {{ role.charAt(0).toUpperCase() + role.slice(1) }}
                  </option>
                </select>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" @click="showEditModal = false" :disabled="saving">Cancel</button>
              <button type="button" class="btn btn-primary" @click="updateUser" :disabled="saving">
                <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ saving ? 'Updating...' : 'Update User' }}
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
              <h5 class="modal-title">Delete User?</h5>
              <button type="button" class="btn-close" @click="cancelDelete"></button>
            </div>
            <div class="modal-body">
              <p>
                Are you sure you want to delete the user <strong>{{ userToDelete.username }}</strong>?
                This action cannot be undone.
              </p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" @click="cancelDelete" :disabled="saving">Cancel</button>
              <button type="button" class="btn btn-danger" @click="deleteUser" :disabled="saving">
                <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ saving ? 'Deleting...' : 'Delete' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
};

