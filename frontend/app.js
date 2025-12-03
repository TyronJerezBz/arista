// Main Vue Application
const { createApp } = Vue;

// API Base URL - adjust if needed
const API_BASE_URL = '/arista/api';

// Import components
import LoginForm from './components/LoginForm.js';
import SwitchList from './components/SwitchList.js';
import AddSwitch from './components/AddSwitch.js';
import EditSwitch from './components/EditSwitch.js';
import SwitchDetails from './components/SwitchDetails.js';
import PermissionManagement from './components/PermissionManagement.js';
import UserManagement from './components/UserManagement.js';
import VLANManagement from './components/VLANManagement.js';
import Help from './components/Help.js';

// Main Vue app
createApp({
  components: {
    LoginForm,
    SwitchList,
    AddSwitch,
    EditSwitch,
    SwitchDetails,
    PermissionManagement,
    UserManagement,
    VLANManagement,
    Help
  },
  data() {
    return {
      user: null,
      currentView: 'dashboard',
      loading: false,
      polling: false,
      csrfToken: null,
      message: null,
      messageType: 'info',
      editingSwitchId: null,
      viewingSwitchId: null,
      autoPollSwitchId: null
    }
  },
  mounted() {
    this.checkSession();
  },
  methods: {
    async checkSession() {
      this.loading = true;
      try {
        const response = await axios.get(`${API_BASE_URL}/auth/session.php`, { 
          withCredentials: true 
        });
        
        if (response.data.authenticated && response.data.user) {
          this.user = response.data.user;
          // Always update CSRF token from session response
          if (response.data.csrf_token) {
            this.csrfToken = response.data.csrf_token;
          }
          this.currentView = 'dashboard';
        } else {
          this.user = null;
          this.csrfToken = null;
          this.currentView = 'login';
        }
      } catch (error) {
        console.error('Session check failed:', error);
        this.user = null;
        this.csrfToken = null;
        this.currentView = 'login';
      } finally {
        this.loading = false;
      }
    },
    
    handleLogin(user) {
      this.user = user;
      this.currentView = 'dashboard';
      // Refresh session to get CSRF token
      this.checkSession();
    },
    
    async handleLogout() {
      this.loading = true;
      try {
        await axios.post(`${API_BASE_URL}/auth/logout.php`, {}, { 
          withCredentials: true 
        });
        this.user = null;
        this.currentView = 'login';
        this.csrfToken = null;
      } catch (error) {
        console.error('Logout failed:', error);
      } finally {
        this.loading = false;
      }
    },
    
    viewSwitch(switchId) {
      this.viewingSwitchId = switchId;
      this.currentView = 'switch-details';
    },
    
    showMessage(message, type = 'info') {
      this.message = message;
      this.messageType = type;
      setTimeout(() => {
        this.message = null;
      }, 5000);
    },
    
    handleSwitchAdded(switchData) {
      this.showMessage('Switch added successfully!', 'success');
      // If switchData has an id, we'll auto-poll it after redirect
      this.autoPollSwitchId = switchData?.id || null;
      this.currentView = 'switches';
      // Refresh switch list will happen automatically when view changes
    },

    handleEditSwitch(switchId) {
      this.editingSwitchId = switchId;
      this.currentView = 'edit-switch';
    },

  },
  template: `
    <div class="app-container">
      <div v-if="loading && !user" class="d-flex justify-content-center align-items-center" style="min-height: 100vh;">
        <div class="spinner-border text-primary" role="status">
          <span class="visually-hidden">Loading...</span>
        </div>
      </div>
      
      <LoginForm v-if="!user && !loading" @login="handleLogin" />
      
      <div v-if="user" class="main-content">
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
          <div class="container-fluid">
            <a class="navbar-brand" href="#" @click.prevent="currentView = 'dashboard'">
              <i class="fas fa-network-wired me-2"></i>
              Arista Switch Manager
            </a>
            <div class="navbar-nav">
              <button 
                class="nav-link text-white" 
                :class="{ active: currentView === 'dashboard' }"
                @click="currentView = 'dashboard'"
              >
                <i class="fas fa-home me-1"></i> Dashboard
              </button>
              <button
                class="nav-link text-white"
                :class="{ active: currentView === 'switches' }"
                @click="currentView = 'switches'"
              >
                <i class="fas fa-network-wired me-1"></i> Switches
              </button>
              <button
                v-if="user.role === 'admin'"
                class="nav-link text-white"
                :class="{ active: currentView === 'users' }"
                @click="currentView = 'users'"
              >
                <i class="fas fa-users me-1"></i> Users
              </button>
              <button
                v-if="user.role === 'admin'"
                class="nav-link text-white"
                :class="{ active: currentView === 'permissions' }"
                @click="currentView = 'permissions'"
              >
                <i class="fas fa-shield-alt me-1"></i> Permissions
              </button>
              <button
                class="nav-link text-white"
                :class="{ active: currentView === 'help' }"
                @click="currentView = 'help'"
              >
                <i class="fas fa-question-circle me-1"></i> Help
              </button>
            </div>
            <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
              <span class="navbar-text me-3">
                <i class="fas fa-user me-2"></i>
                {{ user.username }} <span class="badge bg-secondary ms-2">{{ user.role }}</span>
              </span>
              <button class="btn btn-outline-light btn-sm" @click="handleLogout">
                <i class="fas fa-sign-out-alt me-2"></i>
                Logout
              </button>
            </div>
          </div>
        </nav>
        
        <!-- Message Alert -->
        <div v-if="message" class="container-fluid mt-3">
          <div :class="['alert', 'alert-' + (messageType === 'error' ? 'danger' : messageType === 'success' ? 'success' : 'info'), 'alert-dismissible']" role="alert">
            {{ message }}
            <button type="button" class="btn-close" @click="message = null"></button>
          </div>
        </div>
        
        <div class="container-fluid mt-3">
          <div v-if="currentView === 'dashboard'" class="text-center py-5">
            <h2>Welcome, {{ user.username }}!</h2>
            <p class="text-muted">Dashboard coming soon...</p>
            <button class="btn btn-primary" @click="currentView = 'switches'">
              <i class="fas fa-network-wired me-2"></i>
              View Switches
            </button>
          </div>
          
          <SwitchList
            v-if="currentView === 'switches'"
            :user="user"
            :auto-poll-switch-id="autoPollSwitchId"
            @add-switch="currentView = 'add-switch'"
            @view-switch="viewSwitch"
            @show-message="showMessage"
            @edit-switch="handleEditSwitch"
            @poll-completed="autoPollSwitchId = null"
          />
          
          <AddSwitch
            v-if="currentView === 'add-switch'"
            :user="user"
            :csrf-token="csrfToken"
            @switch-added="handleSwitchAdded"
            @cancel="currentView = 'switches'"
          />

          <EditSwitch
            v-if="currentView === 'edit-switch' && editingSwitchId"
            :switch-id="editingSwitchId"
            :user="user"
            :csrf-token="csrfToken"
            @switch-updated="handleSwitchAdded"
            @cancel="currentView = 'switches'"
          />

          <SwitchDetails
            v-if="currentView === 'switch-details' && viewingSwitchId"
            :switch-id="viewingSwitchId"
            :user="user"
            :csrf-token="csrfToken"
            @back="currentView = 'switches'"
            @edit-switch="handleEditSwitch"
            @switch-deleted="currentView = 'switches'"
            @show-message="showMessage"
            @manage-vlans="() => {}"
          />

          <UserManagement
            v-if="currentView === 'users' && user.role === 'admin'"
            :user="user"
            :csrf-token="csrfToken"
            @show-message="showMessage"
          />

          <PermissionManagement
            v-if="currentView === 'permissions' && user.role === 'admin'"
            :user="user"
            :csrf-token="csrfToken"
            @show-message="showMessage"
          />

          <Help
            v-if="currentView === 'help'"
          />
        </div>
      </div>
    </div>
  `
}).mount('#app');

