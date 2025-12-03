// Maintenance Tab Component - Integrate Restart and Config Management
// Import these components:
// import RestartScheduler from './RestartScheduler.js';
// import ConfigEditor from './ConfigEditor.js';
// import ConfigViewer from './ConfigViewer.js';

export default {
  name: 'MaintenanceTab',
  components: {
    // Register components here:
    // RestartScheduler,
    // ConfigEditor,
    // ConfigViewer
  },
  props: {
    switchId: {
      type: [String, Number],
      required: true
    },
    switchHostname: {
      type: String,
      default: 'Unknown'
    },
    csrfToken: {
      type: String,
      default: null
    }
  },
  data() {
    return {
      activeTab: 'restart', // restart, config-viewer, config-editor
      messages: [],
      showUploadModal: false,
      uploadedFile: null,
      uploadError: null,
      uploadSuccess: null
    }
  },
  methods: {
    showMessage(message, type = 'info') {
      this.messages.push({ message, type });
      setTimeout(() => {
        this.messages.shift();
      }, 5000);
    },

    handleFileSelect(event) {
      const file = event.target.files[0];
      if (file) {
        if (file.size > 1048576) { // 1MB
          this.uploadError = 'File too large (max 1MB)';
          return;
        }
        this.uploadedFile = file;
        this.uploadError = null;
      }
    },

    async uploadConfig() {
      if (!this.uploadedFile) {
        this.uploadError = 'Please select a file';
        return;
      }

      const formData = new FormData();
      formData.append('file', this.uploadedFile);
      formData.append('csrf_token', this.csrfToken);

      try {
        const response = await axios.post(
          `/arista/api/switches/config/upload.php?id=${this.switchId}`,
          formData,
          {
            withCredentials: true,
            headers: { 'Content-Type': 'multipart/form-data' }
          }
        );

        if (response.data.success) {
          this.uploadSuccess = 'Configuration uploaded successfully';
          this.showMessage(this.uploadSuccess, 'success');
          this.showUploadModal = false;
          this.uploadedFile = null;
          // Refresh config viewer if active
          this.$nextTick(() => {
            this.$refs.configViewer?.loadCurrentConfig();
          });
        } else {
          this.uploadError = response.data.error || 'Upload failed';
        }
      } catch (error) {
        this.uploadError = 'Failed to upload configuration: ' + (error.response?.data?.error || error.message);
      }
    }
  },
  template: `
    <div class="maintenance-tab">
      <!-- Messages -->
      <div v-for="msg in messages" :key="msg.message" :class="['alert', 'alert-' + msg.type, 'alert-dismissible']">
        {{ msg.message }}
        <button type="button" class="btn-close" @click="messages = messages.filter(m => m !== msg)"></button>
      </div>

      <!-- Tab Navigation -->
      <div class="d-flex gap-2 mb-3 flex-wrap">
        <button 
          :class="['btn btn-sm', activeTab === 'restart' ? 'btn-primary' : 'btn-outline-secondary']"
          @click="activeTab = 'restart'"
        >
          <i class="fas fa-power-off me-2"></i>Restart
        </button>
        <button 
          :class="['btn btn-sm', activeTab === 'config-viewer' ? 'btn-primary' : 'btn-outline-secondary']"
          @click="activeTab = 'config-viewer'"
        >
          <i class="fas fa-eye me-2"></i>View Config
        </button>
        <button 
          :class="['btn btn-sm', activeTab === 'config-editor' ? 'btn-primary' : 'btn-outline-secondary']"
          @click="activeTab = 'config-editor'"
        >
          <i class="fas fa-edit me-2"></i>Edit Config
        </button>
        <button 
          class="btn btn-sm btn-outline-success ms-auto"
          @click="showUploadModal = true"
          title="Upload configuration"
        >
          <i class="fas fa-cloud-upload-alt me-2"></i>Upload Config
        </button>
      </div>

      <!-- Restart Tab -->
      <div v-show="activeTab === 'restart'">
        <div class="card">
          <div class="card-body">
            <restart-scheduler
              :switch-id="switchId"
              :switch-hostname="switchHostname"
              :csrf-token="csrfToken"
              @show-message="showMessage"
              @restart-initiated="() => showMessage('Restart initiated', 'success')"
              @restart-scheduled="() => showMessage('Restart scheduled', 'success')"
            ></restart-scheduler>
          </div>
        </div>
      </div>

      <!-- Config Viewer Tab -->
      <div v-show="activeTab === 'config-viewer'">
        <div class="card">
          <div class="card-body">
            <config-viewer
              ref="configViewer"
              :switch-id="switchId"
              :switch-hostname="switchHostname"
              :csrf-token="csrfToken"
              @show-message="showMessage"
            ></config-viewer>
          </div>
        </div>
      </div>

      <!-- Config Editor Tab -->
      <div v-show="activeTab === 'config-editor'">
        <div class="card">
          <div class="card-body">
            <config-editor
              :switch-id="switchId"
              :csrf-token="csrfToken"
              :initial-config="''"
              @show-message="showMessage"
              @config-applied="() => showMessage('Configuration applied', 'success')"
              @config-updated="() => activeTab = 'config-viewer'"
            ></config-editor>
          </div>
        </div>
      </div>

      <!-- Upload Modal -->
      <div v-if="showUploadModal" class="modal-backdrop show d-block"></div>
      <div v-if="showUploadModal" class="modal show d-block">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header bg-light">
              <h5 class="modal-title">Upload Configuration</h5>
              <button 
                type="button" 
                class="btn-close"
                @click="showUploadModal = false"
              ></button>
            </div>

            <div class="modal-body">
              <div v-if="uploadError" class="alert alert-danger alert-dismissible small">
                {{ uploadError }}
                <button type="button" class="btn-close btn-sm" @click="uploadError = null"></button>
              </div>

              <div class="mb-3">
                <label class="form-label">Select Configuration File</label>
                <input 
                  type="file"
                  class="form-control"
                  accept=".cfg,.conf,.txt"
                  @change="handleFileSelect"
                >
                <small class="text-muted">Max size: 1MB. Supported formats: .cfg, .conf, .txt</small>
              </div>

              <div v-if="uploadedFile" class="alert alert-info">
                <i class="fas fa-file me-2"></i>
                {{ uploadedFile.name }} ({{ (uploadedFile.size / 1024).toFixed(2) }} KB)
              </div>
            </div>

            <div class="modal-footer">
              <button 
                type="button" 
                class="btn btn-secondary"
                @click="showUploadModal = false"
              >
                Cancel
              </button>
              <button 
                type="button" 
                class="btn btn-primary"
                @click="uploadConfig"
                :disabled="!uploadedFile"
              >
                Upload
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
}

