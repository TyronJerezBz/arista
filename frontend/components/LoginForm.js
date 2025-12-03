// Login Form Component
// API Base URL - adjust if needed
const API_BASE_URL = '/arista/api';

export default {
  name: 'LoginForm',
  data() {
    return {
      username: '',
      password: '',
      error: null,
      loading: false
    }
  },
  methods: {
    async handleLogin() {
      // Reset error
      this.error = null;
      
      // Validate input
      if (!this.username || !this.password) {
        this.error = 'Please enter both username and password';
        return;
      }
      
      // Set loading state
      this.loading = true;
      
      try {
        // Make login request
        const response = await axios.post(`${API_BASE_URL}/auth/login.php`, {
          username: this.username,
          password: this.password
        }, {
          withCredentials: true
        });
        
        if (response.data.success && response.data.user) {
          // Login successful - emit event even if CSRF token generation failed
          // The session is already created on the server
          this.$emit('login', response.data.user);
          // Clear any previous errors
          this.error = null;
        } else {
          this.error = response.data.error || 'Login failed';
        }
      } catch (error) {
        // Only show error if login actually failed
        if (error.response) {
          if (error.response.status === 401 || error.response.status === 400) {
            // Authentication failed
            this.error = error.response.data?.error || 'Invalid username or password';
          } else {
            // Other error
            this.error = error.response.data?.error || 'Login failed. Please try again.';
          }
        } else {
          // Network error
          this.error = 'Network error. Please check your connection and try again.';
        }
      } finally {
        this.loading = false;
      }
    },
    
    handleKeyPress(event) {
      if (event.key === 'Enter') {
        this.handleLogin();
      }
    }
  },
  template: `
    <div class="login-container">
      <div class="card login-card">
        <div class="card-header">
          <h4 class="mb-0">
            <i class="fas fa-network-wired me-2"></i>
            Arista Switch Manager
          </h4>
        </div>
        <div class="card-body">
          <form @submit.prevent="handleLogin">
            <div v-if="error" class="alert alert-danger" role="alert">
              <i class="fas fa-exclamation-circle me-2"></i>
              {{ error }}
            </div>
            
            <div class="mb-3">
              <label for="username" class="form-label">Username</label>
              <input
                type="text"
                class="form-control"
                id="username"
                v-model="username"
                placeholder="Enter username"
                required
                autofocus
                :disabled="loading"
                @keypress="handleKeyPress"
              />
            </div>
            
            <div class="mb-3">
              <label for="password" class="form-label">Password</label>
              <input
                type="password"
                class="form-control"
                id="password"
                v-model="password"
                placeholder="Enter password"
                required
                :disabled="loading"
                @keypress="handleKeyPress"
              />
            </div>
            
            <button
              type="submit"
              class="btn btn-primary w-100"
              :disabled="loading"
            >
              <span v-if="loading" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
              <span v-else><i class="fas fa-sign-in-alt me-2"></i></span>
              {{ loading ? 'Logging in...' : 'Login' }}
            </button>
          </form>
        </div>
      </div>
    </div>
  `
}

