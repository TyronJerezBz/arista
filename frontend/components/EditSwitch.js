// Edit Switch Component
// API Base URL - adjust if needed
const API_BASE_URL = '/arista/api';

export default {
  name: 'EditSwitch',
  props: {
    user: {
      type: Object,
      required: true
    },
    switchId: {
      type: Number,
      required: true
    },
    csrfToken: {
      type: String,
      default: null
    }
  },
  data() {
    return {
      formData: {
        hostname: '',
        ip_address: '',
        model: '',
        role: '',
        tags: '',
        username: '',
        password: '',
        port: 443,
        use_https: true,
        timeout: 10
      },
      originalData: {},
      loading: true,
      saving: false,
      error: null,
      success: false,
      passwordRequired: false
    }
  },
  computed: {
    canEdit() {
      return this.user.role === 'admin' || this.user.role === 'operator';
    },
    hasChanges() {
      return JSON.stringify(this.formData) !== JSON.stringify(this.originalData);
    }
  },
  mounted() {
    this.loadSwitch();
  },
  methods: {
    async loadSwitch() {
      this.loading = true;
      this.error = null;
      
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/get.php?id=${this.switchId}`, {
          withCredentials: true
        });
        
        if (response.data.success && response.data.switch) {
          const sw = response.data.switch;
          // Load last known connection settings even if switch is offline
          this.formData = {
            hostname: sw.hostname || '',
            ip_address: sw.ip_address || '',
            model: sw.model || '',
            role: sw.role || '',
            tags: sw.tags || '',
            username: sw.credentials?.username || '',
            password: '', // Always leave password blank - user must re-enter if changing
            port: sw.credentials?.port || 443,
            use_https: sw.credentials?.use_https !== false,
            timeout: sw.credentials?.timeout || 10
          };
          this.originalData = JSON.parse(JSON.stringify(this.formData));
        } else {
          this.error = response.data.error || 'Failed to load switch';
        }
      } catch (error) {
        if (error.response && error.response.data) {
          this.error = error.response.data.error || 'Failed to load switch';
        } else {
          this.error = 'Network error. Please try again.';
        }
      } finally {
        this.loading = false;
      }
    },
    
    async handleSubmit() {
      this.error = null;
      this.success = false;
      
      if (!this.canEdit) {
        this.error = 'You do not have permission to edit switches';
        return;
      }
      
      // Validate required fields
      if (!this.formData.hostname) {
        this.error = 'Hostname is required';
        return;
      }
      
      if (!this.formData.ip_address) {
        this.error = 'IP address is required';
        return;
      }
      
      // Validate IP address format
      const ipRegex = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
      if (!ipRegex.test(this.formData.ip_address.trim())) {
        this.error = 'Invalid IP address format';
        return;
      }
      
      this.saving = true;
      
      try {
        // Get CSRF token if not provided
        let csrf = this.csrfToken;
        if (!csrf) {
          try {
            const sessionRes = await axios.get(`${API_BASE_URL}/auth/session.php`, { withCredentials: true });
            if (sessionRes.data.csrf_token) {
              csrf = sessionRes.data.csrf_token;
            }
          } catch (sessionError) {
            console.error('Failed to fetch CSRF token:', sessionError);
          }
        }
        
        if (!csrf) {
          this.error = 'CSRF token not available. Please refresh the page.';
          this.saving = false;
          return;
        }
        
        // Prepare request data
        const requestData = {
          ...this.formData,
          csrf_token: csrf
        };
        
        // Handle credential updates separately
        // If password is empty, don't update it (but still allow updating other credential fields)
        if (!requestData.password || requestData.password.trim() === '') {
          delete requestData.password;
        }
        // Allow updating username, port, use_https, and timeout independently
        // These will be processed by the backend even if password is not provided
        
        // Update switch - explicitly set JSON content type
        const response = await axios.put(`${API_BASE_URL}/switches/update.php?id=${this.switchId}`, requestData, {
          withCredentials: true,
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          }
        });
        
        if (response.data.success) {
          this.success = true;
          this.originalData = JSON.parse(JSON.stringify(this.formData));
          this.$emit('switch-updated', response.data.switch);
          
          setTimeout(() => {
            this.$emit('cancel');
          }, 2000);
        } else {
          this.error = response.data.error || 'Failed to update switch';
        }
      } catch (error) {
        if (error.response && error.response.data) {
          this.error = error.response.data.error || 'Failed to update switch';
          if (error.response.data.errors) {
            this.error += ': ' + error.response.data.errors.join(', ');
          }
        } else {
          this.error = 'Network error. Please try again.';
        }
      } finally {
        this.saving = false;
      }
    },
    
    handleCancel() {
      this.$emit('cancel');
    }
  },
  template: `
    <div class="edit-switch">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="fas fa-edit me-2"></i>
            Edit Switch
          </h5>
          <button class="btn btn-sm btn-outline-secondary" @click="handleCancel">
            <i class="fas fa-times me-1"></i>
            Cancel
          </button>
        </div>
        <div class="card-body">
          <div v-if="loading" class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
          </div>
          
          <div v-else>
            <div v-if="success" class="alert alert-success" role="alert">
              <i class="fas fa-check-circle me-2"></i>
              Switch updated successfully! Redirecting...
            </div>
            
            <div v-if="error" class="alert alert-danger" role="alert">
              <i class="fas fa-exclamation-circle me-2"></i>
              {{ error }}
            </div>
            
            <form @submit.prevent="handleSubmit">
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label for="hostname" class="form-label">Hostname <span class="text-danger">*</span></label>
                  <input
                    type="text"
                    class="form-control"
                    id="hostname"
                    v-model="formData.hostname"
                    placeholder="switch01"
                    required
                  />
                </div>
                
                <div class="col-md-6 mb-3">
                  <label for="ip_address" class="form-label">IP Address <span class="text-danger">*</span></label>
                  <input
                    type="text"
                    class="form-control"
                    id="ip_address"
                    v-model="formData.ip_address"
                    placeholder="192.168.1.100"
                    required
                  />
                  <small class="form-text text-muted">IP address used to connect to this switch</small>
                </div>
              </div>
              
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label for="model" class="form-label">Model</label>
                  <input
                    type="text"
                    class="form-control"
                    id="model"
                    v-model="formData.model"
                    placeholder="DCS-7050SX"
                  />
                </div>
                <div class="col-md-6 mb-3">
                  <label for="role" class="form-label">Role</label>
                  <select class="form-select" id="role" v-model="formData.role">
                    <option value="">Select role...</option>
                    <option value="core">Core</option>
                    <option value="distribution">Distribution</option>
                    <option value="access">Access</option>
                    <option value="edge">Edge</option>
                    <option value="spine">Spine</option>
                    <option value="leaf">Leaf</option>
                  </select>
                </div>
                
                <div class="col-md-6 mb-3">
                  <label for="tags" class="form-label">Tags</label>
                  <input
                    type="text"
                    class="form-control"
                    id="tags"
                    v-model="formData.tags"
                    placeholder="datacenter, production"
                  />
                  <small class="form-text text-muted">Comma-separated tags</small>
                </div>
              </div>
              
              <hr />
              
              <h6 class="mb-3">Switch Connection Settings</h6>
              <div class="alert alert-info mb-3">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Note:</strong> You can update connection settings even when the switch is offline. 
                Changes will be saved to the database and used for future connection attempts.
              </div>
              <p class="text-muted small mb-3">Leave password blank to keep existing password. Other fields will be updated if changed.</p>
              
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label for="username" class="form-label">Username</label>
                  <input
                    type="text"
                    class="form-control"
                    id="username"
                    v-model="formData.username"
                    placeholder="admin"
                  />
                </div>
                
                <div class="col-md-6 mb-3">
                  <label for="password" class="form-label">Password</label>
                  <input
                    type="password"
                    class="form-control"
                    id="password"
                    v-model="formData.password"
                    placeholder="Leave blank to keep existing"
                  />
                </div>
              </div>
              
              <div class="row">
                <div class="col-md-4 mb-3">
                  <label for="port" class="form-label">Port</label>
                  <input
                    type="number"
                    class="form-control"
                    id="port"
                    v-model.number="formData.port"
                    min="1"
                    max="65535"
                  />
                </div>
                
                <div class="col-md-4 mb-3">
                  <label class="form-label">HTTPS</label>
                  <div class="form-check form-switch mt-2">
                    <input
                      class="form-check-input"
                      type="checkbox"
                      id="use_https"
                      v-model="formData.use_https"
                    />
                    <label class="form-check-label" for="use_https">
                      Use HTTPS
                    </label>
                  </div>
                </div>
                
                <div class="col-md-4 mb-3">
                  <label for="timeout" class="form-label">Timeout (seconds)</label>
                  <input
                    type="number"
                    class="form-control"
                    id="timeout"
                    v-model.number="formData.timeout"
                    min="1"
                    max="300"
                  />
                </div>
              </div>
              
              <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" @click="handleCancel" :disabled="saving">
                  Cancel
                </button>
                <button type="submit" class="btn btn-primary" :disabled="saving || !hasChanges">
                  <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                  <i v-else class="fas fa-save me-2"></i>
                  {{ saving ? 'Saving...' : 'Save Changes' }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  `
}
