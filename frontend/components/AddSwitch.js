// Add Switch Component
// API Base URL - adjust if needed
const API_BASE_URL = '/arista/api';

export default {
  name: 'AddSwitch',
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
      loading: false,
      error: null,
      success: false
    }
  },
  methods: {
    async handleSubmit() {
      // Reset errors
      this.error = null;
      this.success = false;
      
      // Validate required fields
      if (!this.formData.hostname || !this.formData.ip_address || !this.formData.username || !this.formData.password) {
        this.error = 'Please fill in all required fields (Hostname, IP Address, Username, Password)';
        return;
      }
      
      // Validate IP address format (basic check)
      const ipRegex = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
      if (!ipRegex.test(this.formData.ip_address)) {
        this.error = 'Invalid IP address format';
        return;
      }
      
      this.loading = true;
      
      try {
        // Get CSRF token - always fetch fresh from session if not already provided
        let csrf = null;
        
        // Try to use provided token first
        if (this.csrfToken) {
          csrf = this.csrfToken;
        } else {
          // If no token provided, fetch from session.php
          try {
            const sessionRes = await axios.get(`${API_BASE_URL}/auth/session.php`, { withCredentials: true });
            if (sessionRes.data.csrf_token) {
              csrf = sessionRes.data.csrf_token;
            }
          } catch (sessionError) {
            // Failed to fetch CSRF token
          }
        }
        
        // Check if we have a CSRF token
        if (!csrf) {
          this.error = 'CSRF token not available. Please refresh the page and try again.';
          this.loading = false;
          return;
        }
        
        // Prepare request data
        const requestData = {
          ...this.formData,
          csrf_token: csrf
        };
        
        
        // Add switch - explicitly set JSON content type
        const response = await axios.post(`${API_BASE_URL}/switches/add.php`, requestData, {
          withCredentials: true,
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          }
        });
        
        
        if (response.data.success) {
          this.success = true;
          this.$emit('switch-added', response.data.switch);
          // Reset form after 2 seconds
          setTimeout(() => {
            this.$emit('cancel');
          }, 2000);
        } else {
          this.error = response.data.error || 'Failed to add switch';
        }
      } catch (error) {
        if (error.response && error.response.data) {
          this.error = error.response.data.error || 'Failed to add switch';
          if (error.response.data.errors) {
            this.error += ': ' + error.response.data.errors.join(', ');
          }
        } else {
          this.error = 'Network error. Please try again.';
        }
      } finally {
        this.loading = false;
      }
    },
    
    handleCancel() {
      this.$emit('cancel');
    }
  },
  template: `
    <div class="add-switch">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="fas fa-plus-circle me-2"></i>
            Add New Switch
          </h5>
          <button class="btn btn-sm btn-outline-secondary" @click="handleCancel">
            <i class="fas fa-times me-1"></i>
            Cancel
          </button>
        </div>
        <div class="card-body">
          <div v-if="success" class="alert alert-success" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            Switch added successfully! Redirecting...
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
            </div>
            
            <div class="mb-3">
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
            
            <hr />
            
            <h6 class="mb-3">Switch Credentials</h6>
            
            <div class="row">
              <div class="col-md-6 mb-3">
                <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                <input
                  type="text"
                  class="form-control"
                  id="username"
                  v-model="formData.username"
                  placeholder="admin"
                  required
                />
              </div>
              
              <div class="col-md-6 mb-3">
                <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                <input
                  type="password"
                  class="form-control"
                  id="password"
                  v-model="formData.password"
                  placeholder="Enter switch password"
                  required
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
                <small class="form-text text-muted">Default: 443</small>
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
                <small class="form-text text-muted">Default: 10</small>
              </div>
            </div>
            
            <div class="d-flex justify-content-end gap-2">
              <button type="button" class="btn btn-secondary" @click="handleCancel" :disabled="loading">
                Cancel
              </button>
              <button type="submit" class="btn btn-primary" :disabled="loading">
                <span v-if="loading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                <i v-else class="fas fa-plus me-2"></i>
                {{ loading ? 'Adding...' : 'Add Switch' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  `
}

