// Config Editor Component - Edit and Apply Configuration
const API_BASE_URL = '/arista/api';

export default {
  name: 'ConfigEditor',
  props: {
    switchId: {
      type: [String, Number],
      required: true
    },
    configId: {
      type: [String, Number],
      default: null
    },
    initialConfig: {
      type: String,
      default: ''
    },
    csrfToken: {
      type: String,
      default: null
    }
  },
  data() {
    return {
      configText: '',
      loading: false,
      validating: false,
      applying: false,
      mode: 'view', // view, edit, validate, apply
      validation: null,
      changes: null,
      error: null,
      success: null,
      autoBackup: true,
      reloadOnComplete: false,
      showDiffView: false
    }
  },
  computed: {
    hasChanges() {
      return this.configText !== this.initialConfig;
    },
    lineCount() {
      return this.configText.split('\n').length;
    },
    fileSize() {
      return (this.configText.length / 1024).toFixed(2) + ' KB';
    }
  },
  mounted() {
    this.configText = this.initialConfig;
  },
  methods: {
    async validateConfig() {
      if (!this.configText.trim()) {
        this.error = 'Configuration cannot be empty';
        return;
      }
      
      this.validating = true;
      this.error = null;
      this.validation = null;
      
      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/config/edit.php?id=${this.switchId}`,
          {
            csrf_token: this.csrfToken,
            config_text: this.configText,
            validate_only: true
          },
          { withCredentials: true, headers: { 'Content-Type': 'application/json' } }
        );
        
        if (response.data.success) {
          this.validation = response.data.validation;
          this.changes = response.data.changes;
          this.mode = 'validate';
          this.$emit('validation-complete', response.data);
        } else {
          this.error = response.data.error;
          if (response.data.validation_errors) {
            this.validation = {
              valid: false,
              errors: response.data.validation_errors,
              warnings: response.data.warnings || []
            };
          }
        }
      } catch (error) {
        const errMsg = error.response?.data?.error || error.message;
        this.error = 'Validation failed: ' + errMsg;
      } finally {
        this.validating = false;
      }
    },
    
    async applyConfig() {
      if (!this.validation?.valid) {
        this.error = 'Cannot apply invalid configuration. Validate first.';
        return;
      }
      
      if (!confirm('Are you sure you want to apply this configuration to the switch?\n\nAuto-backup: ' + (this.autoBackup ? 'Enabled' : 'Disabled'))) {
        return;
      }
      
      this.applying = true;
      this.error = null;
      
      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/config/edit.php?id=${this.switchId}`,
          {
            csrf_token: this.csrfToken,
            config_text: this.configText,
            apply: true,
            auto_backup: this.autoBackup,
            validate_only: false
          },
          { withCredentials: true, headers: { 'Content-Type': 'application/json' } }
        );
        
        if (response.data.success) {
          this.$emit('config-applied', response.data);
          this.$emit('show-message', 'Configuration applied successfully', 'success');
          this.mode = 'view';
          // Reset to new config as baseline
          this.$emit('config-updated', this.configText);
        } else {
          this.error = response.data.error || 'Failed to apply configuration';
        }
      } catch (error) {
        const errMsg = error.response?.data?.error || error.message;
        this.error = 'Failed to apply configuration: ' + errMsg;
        this.$emit('show-message', this.error, 'error');
      } finally {
        this.applying = false;
      }
    },
    
    resetChanges() {
      if (!confirm('Discard all changes?')) return;
      this.configText = this.initialConfig;
      this.validation = null;
      this.changes = null;
      this.mode = 'view';
    },
    
    downloadConfig() {
      const blob = new Blob([this.configText], { type: 'text/plain' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `switch_config_edited_${new Date().toISOString().split('T')[0]}.cfg`;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);
    },
    
    copyToClipboard() {
      navigator.clipboard.writeText(this.configText).then(() => {
        this.$emit('show-message', 'Configuration copied to clipboard', 'success');
      }).catch(() => {
        this.$emit('show-message', 'Failed to copy to clipboard', 'error');
      });
    }
  },
  template: `
    <div class="config-editor">
      <!-- Header -->
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <h6>Configuration Editor</h6>
          <small class="text-muted">
            Lines: {{ lineCount }} | Size: {{ fileSize }}
            <span v-if="hasChanges" class="ms-2 badge bg-warning">Modified</span>
          </small>
        </div>
        <div class="d-flex gap-2">
          <button 
            class="btn btn-sm btn-outline-secondary"
            @click="downloadConfig"
            title="Download edited config"
          >
            <i class="fas fa-download me-1"></i>Download
          </button>
          <button 
            class="btn btn-sm btn-outline-secondary"
            @click="copyToClipboard"
            title="Copy to clipboard"
          >
            <i class="fas fa-copy me-1"></i>Copy
          </button>
        </div>
      </div>

      <!-- Error Alert -->
      <div v-if="error" class="alert alert-danger alert-dismissible">
        <i class="fas fa-exclamation-circle me-2"></i>
        {{ error }}
        <button type="button" class="btn-close" @click="error = null"></button>
      </div>

      <!-- Success Alert -->
      <div v-if="success" class="alert alert-success alert-dismissible">
        <i class="fas fa-check-circle me-2"></i>
        {{ success }}
        <button type="button" class="btn-close" @click="success = null"></button>
      </div>

      <!-- Editor -->
      <div class="card border-0 mb-3">
        <div class="card-body p-0">
          <textarea
            v-model="configText"
            class="form-control border-0"
            style="font-family: monospace; font-size: 12px; height: 400px; resize: vertical;"
            placeholder="Enter configuration..."
          ></textarea>
        </div>
      </div>

      <!-- Validation Results -->
      <div v-if="validation" class="card mb-3" :class="validation.valid ? 'border-success' : 'border-danger'">
        <div class="card-header" :class="validation.valid ? 'bg-success' : 'bg-danger'" style="color: white;">
          <i :class="validation.valid ? 'fas fa-check-circle' : 'fas fa-times-circle'" class="me-2"></i>
          {{ validation.valid ? 'Validation Passed' : 'Validation Failed' }}
        </div>
        <div class="card-body">
          <div v-if="validation.errors.length > 0" class="mb-3">
            <h6 class="text-danger">Errors:</h6>
            <ul class="mb-0">
              <li v-for="(error, i) in validation.errors" :key="'error-' + i" class="small">
                {{ error }}
              </li>
            </ul>
          </div>
          <div v-if="validation.warnings.length > 0" class="mb-3">
            <h6 class="text-warning">Warnings:</h6>
            <ul class="mb-0">
              <li v-for="(warning, i) in validation.warnings" :key="'warning-' + i" class="small">
                {{ warning }}
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Changes Summary -->
      <div v-if="changes" class="card mb-3 bg-light">
        <div class="card-body">
          <h6 class="card-title">Changes Summary</h6>
          <div class="row">
            <div class="col-4">
              <small class="text-muted">Lines Added</small>
              <div class="text-success font-weight-bold">+{{ changes.lines_added }}</div>
            </div>
            <div class="col-4">
              <small class="text-muted">Lines Removed</small>
              <div class="text-danger font-weight-bold">-{{ changes.lines_removed }}</div>
            </div>
            <div class="col-4">
              <small class="text-muted">Total Lines</small>
              <div class="font-weight-bold">{{ changes.total_lines_after }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="d-flex gap-2">
        <button
          class="btn btn-outline-primary btn-sm"
          @click="validateConfig"
          :disabled="validating || !hasChanges"
        >
          <i class="fas fa-check me-2"></i>
          {{ validating ? 'Validating...' : 'Validate' }}
        </button>

        <button
          v-if="validation?.valid"
          class="btn btn-success btn-sm"
          @click="applyConfig"
          :disabled="applying"
        >
          <i class="fas fa-rocket me-2"></i>
          {{ applying ? 'Applying...' : 'Apply Configuration' }}
        </button>

        <button
          class="btn btn-outline-secondary btn-sm"
          @click="resetChanges"
          :disabled="!hasChanges"
        >
          <i class="fas fa-undo me-2"></i>
          Reset
        </button>
      </div>

      <!-- Auto-backup and Reload Options -->
      <div v-if="validation?.valid" class="mt-3 p-3 bg-light rounded">
        <div class="form-check mb-2">
          <input 
            type="checkbox" 
            v-model="autoBackup" 
            id="autoBackup" 
            class="form-check-input"
          >
          <label class="form-check-label" for="autoBackup">
            Auto-backup current configuration before apply
          </label>
        </div>
        <div class="form-check">
          <input 
            type="checkbox" 
            v-model="reloadOnComplete" 
            id="reloadOnComplete" 
            class="form-check-input"
          >
          <label class="form-check-label" for="reloadOnComplete">
            Reload switch after configuration apply
          </label>
          <small class="text-muted d-block ms-4">May interrupt network connectivity</small>
        </div>
      </div>
    </div>
  `
}

