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
    <div class="modern-login-wrapper">
      <div class="modern-login-background">
        <div class="bg-shape bg-shape-1"></div>
        <div class="bg-shape bg-shape-2"></div>
        <div class="bg-shape bg-shape-3"></div>
      </div>
      
      <div class="modern-login-container">
        <div class="modern-login-card">
          <div class="modern-login-header">
            <div class="modern-logo-wrapper">
              <i class="fas fa-network-wired"></i>
            </div>
            <h1 class="modern-login-title">Arista Switch Manager</h1>
            <p class="modern-login-subtitle">Sign in to access your network management platform</p>
        </div>
          
          <form @submit.prevent="handleLogin" class="modern-login-form">
            <div v-if="error" class="modern-error-alert">
              <i class="fas fa-exclamation-triangle"></i>
              <span>{{ error }}</span>
            </div>
            
            <div class="modern-form-group">
              <label for="username" class="modern-form-label">
                <i class="fas fa-user"></i>
                Username
              </label>
              <div class="modern-input-group">
                <i class="fas fa-user modern-input-icon"></i>
              <input
                type="text"
                  class="modern-input"
                id="username"
                v-model="username"
                  placeholder="Enter your username"
                required
                autofocus
                :disabled="loading"
                @keypress="handleKeyPress"
              />
              </div>
            </div>
            
            <div class="modern-form-group">
              <label for="password" class="modern-form-label">
                <i class="fas fa-lock"></i>
                Password
              </label>
              <div class="modern-input-group">
                <i class="fas fa-lock modern-input-icon"></i>
              <input
                type="password"
                  class="modern-input"
                id="password"
                v-model="password"
                  placeholder="Enter your password"
                required
                :disabled="loading"
                @keypress="handleKeyPress"
              />
              </div>
            </div>
            
            <button
              type="submit"
              class="modern-login-btn"
              :disabled="loading"
              :class="{ 'is-loading': loading }"
            >
              <span v-if="loading" class="modern-spinner-wrapper">
                <span class="modern-spinner"></span>
              </span>
              <span v-else class="modern-btn-content">
                <i class="fas fa-sign-in-alt"></i>
                Sign In
              </span>
            </button>
          </form>
          
          <div class="modern-login-footer">
            <div class="modern-security-badge">
              <i class="fas fa-shield-alt"></i>
              <span>Secure Authentication</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
}

