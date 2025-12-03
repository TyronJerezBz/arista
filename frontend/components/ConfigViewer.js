// Config Viewer Component - View and Compare Configurations
const API_BASE_URL = '/arista/api';

export default {
  name: 'ConfigViewer',
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
      loading: false,
      comparing: false,
      currentConfig: '',
      configHistory: [],
      selectedCompareId: null,
      comparisonResult: null,
      viewMode: 'current', // current, history, compare
      error: null,
      downloadingRunning: false,
      searchText: '',
      filteredConfig: ''
    }
  },
  computed: {
    displayConfig() {
      if (this.searchText && this.viewMode === 'current') {
        return this.filterConfig(this.currentConfig);
      }
      return this.currentConfig;
    },
    configStats() {
      if (!this.currentConfig) return { lines: 0, size: 0 };
      const lines = this.currentConfig.split('\n').length;
      const size = (this.currentConfig.length / 1024).toFixed(2);
      return { lines, size };
    }
  },
  mounted() {
    this.autoSyncOnLoad();
  },
  methods: {
    async autoSyncOnLoad() {
      // Always try to fetch fresh running-config on load (no cache)
      try {
        if (this.csrfToken) {
          await axios.post(
            `${API_BASE_URL}/switches/config/sync.php?id=${this.switchId}`,
            { csrf_token: this.csrfToken },
            { withCredentials: true, headers: { 'Content-Type': 'application/json' } }
          );
        }
      } catch (e) {
        // Non-fatal; we'll still load latest known config
      } finally {
        // Load latest from DB with cache-busting and show Current tab
        await this.loadCurrentConfig();
        await this.loadConfigHistory();
        this.viewMode = 'current';
      }
    },
    async loadCurrentConfig() {
      this.loading = true;
      try {
        const response = await axios.get(
          `${API_BASE_URL}/switches/config/download.php?id=${this.switchId}&current=true&_ts=${Date.now()}`,
          { withCredentials: true, headers: { 'Cache-Control': 'no-cache' } }
        );
        
        if (response.data && response.data.config) {
          this.currentConfig = response.data.config;
        } else {
          this.error = 'Failed to load current configuration';
        }
      } catch (error) {
        this.error = 'Failed to load current configuration: ' + (error.response?.data?.error || error.message);
      } finally {
        this.loading = false;
      }
    },

    async loadConfigHistory() {
      try {
        const response = await axios.get(
          `${API_BASE_URL}/switches/config/download.php?id=${this.switchId}&history=true&_ts=${Date.now()}`,
          { withCredentials: true, headers: { 'Cache-Control': 'no-cache' } }
        );
        
        if (response.data && response.data.history) {
          this.configHistory = response.data.history;
        }
      } catch (error) {
        // History may not be available, don't show error
      }
    },

    async applyConfigFromHistory(configId) {
      if (!configId) return;
      if (!this.csrfToken) {
        this.$emit('show-message', 'CSRF token not available', 'error');
        return;
      }
      const confirmed = confirm('Apply this configuration to the switch?\n\nAn auto-backup will be created before applying.');
      if (!confirmed) return;

      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/config/apply.php?id=${this.switchId}&config_id=${configId}`,
          {
            csrf_token: this.csrfToken,
            auto_backup: true,
            reload_on_complete: false
          },
          { withCredentials: true, headers: { 'Content-Type': 'application/json' } }
        );

        if (response.data && response.data.success) {
          this.$emit('show-message', 'Configuration applied successfully', 'success');
          // Optional: refresh current config/history
          await this.loadCurrentConfig();
          await this.loadConfigHistory();
          this.viewMode = 'current';
        } else {
          const err = response.data?.error || 'Failed to apply configuration';
          this.$emit('show-message', err, 'error');
        }
      } catch (error) {
        const errMsg = error.response?.data?.error || error.message;
        this.$emit('show-message', 'Failed to apply configuration: ' + errMsg, 'error');
      }
    },

    async downloadRunning() {
      this.downloadingRunning = true;
      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/config/sync.php?id=${this.switchId}`,
          {
            csrf_token: this.csrfToken
          },
          { withCredentials: true, headers: { 'Content-Type': 'application/json' } }
        );
        
        if (response.data.success) {
          const message = response.data.changed 
            ? 'Configuration synced successfully'
            : 'Configuration is already synced';
          this.$emit('show-message', message, 'success');
          
          // Reload current config and history
          await this.loadCurrentConfig();
          await this.loadConfigHistory();
          
          // Switch to current view to show the synced config
          this.viewMode = 'current';
        } else {
          this.$emit('show-message', response.data.error || 'Failed to sync configuration', 'error');
        }
      } catch (error) {
        const errMsg = error.response?.data?.error || error.message;
        this.$emit('show-message', 'Failed to sync configuration: ' + errMsg, 'error');
      } finally {
        this.downloadingRunning = false;
      }
    },

    async compareConfigs() {
      if (!this.selectedCompareId) {
        this.error = 'Please select a configuration to compare with';
        return;
      }

      this.comparing = true;
      try {
        // Find selected config
        const selectedConfig = this.configHistory.find(c => c.id == this.selectedCompareId);
        if (!selectedConfig) {
          this.error = 'Configuration not found';
          return;
        }

        // Calculate diff
        this.comparisonResult = this.calculateDiff(selectedConfig.config_text, this.currentConfig);
        this.viewMode = 'compare';
      } catch (error) {
        this.error = 'Comparison failed: ' + error.message;
      } finally {
        this.comparing = false;
      }
    },

    calculateDiff(oldText, newText) {
      const oldLines = oldText.split('\n');
      const newLines = newText.split('\n');
      const diffs = [];

      let oldIdx = 0, newIdx = 0;

      // Simple line-by-line diff (can be enhanced with proper diff algorithm)
      const maxLen = Math.max(oldLines.length, newLines.length);
      
      for (let i = 0; i < maxLen; i++) {
        const oldLine = oldLines[i] || '';
        const newLine = newLines[i] || '';

        if (oldLine === newLine) {
          diffs.push({
            type: 'context',
            lineNumber: i + 1,
            content: oldLine
          });
        } else if (!newLine) {
          diffs.push({
            type: 'removed',
            lineNumber: i + 1,
            content: oldLine
          });
        } else if (!oldLine) {
          diffs.push({
            type: 'added',
            lineNumber: i + 1,
            content: newLine
          });
        } else {
          diffs.push({
            type: 'removed',
            lineNumber: i + 1,
            content: oldLine
          });
          diffs.push({
            type: 'added',
            lineNumber: i + 1,
            content: newLine
          });
        }
      }

      return {
        oldConfig: oldText,
        newConfig: newText,
        diffs: diffs,
        stats: {
          added: diffs.filter(d => d.type === 'added').length,
          removed: diffs.filter(d => d.type === 'removed').length
        }
      };
    },

    filterConfig(text) {
      if (!this.searchText) return text;
      
      return text.split('\n')
        .filter(line => line.toLowerCase().includes(this.searchText.toLowerCase()))
        .join('\n');
    },

    downloadConfig(filename) {
      const blob = new Blob([this.currentConfig], { type: 'text/plain' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename || `${this.switchHostname}_config_${new Date().toISOString().split('T')[0]}.cfg`;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);
    },

    copyToClipboard() {
      navigator.clipboard.writeText(this.currentConfig).then(() => {
        this.$emit('show-message', 'Configuration copied to clipboard', 'success');
      }).catch(() => {
        this.$emit('show-message', 'Failed to copy to clipboard', 'error');
      });
    },

    getDiffLineStyle(diff) {
      let bgColor = 'white';
      let borderColor = '#dee2e6';
      
      if (diff.type === 'added') {
        bgColor = '#e6ffed';
        borderColor = '#28a745';
      } else if (diff.type === 'removed') {
        bgColor = '#ffeaea';
        borderColor = '#dc3545';
      }
      
      return {
        flex: 1,
        padding: '2px 8px',
        backgroundColor: bgColor,
        borderLeft: '3px solid ' + borderColor
      };
    }
  },
  template: `
    <div class="config-viewer">
      <!-- Alerts -->
      <div v-if="error" class="alert alert-danger alert-dismissible small mb-2">
        <i class="fas fa-exclamation-circle me-2"></i>
        {{ error }}
        <button type="button" class="btn-close btn-sm" @click="error = null"></button>
      </div>

      <!-- Tabs -->
      <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
          <button 
            :class="['nav-link', { active: viewMode === 'current' }]"
            @click="viewMode = 'current'"
          >
            <i class="fas fa-eye me-2"></i>Current Configuration
          </button>
        </li>
        <li class="nav-item">
          <button 
            :class="['nav-link', { active: viewMode === 'history' }]"
            @click="viewMode = 'history'"
          >
            <i class="fas fa-history me-2"></i>History
          </button>
        </li>
        <li class="nav-item">
          <button 
            :class="['nav-link', { active: viewMode === 'compare' }]"
            @click="viewMode = 'compare'"
            :disabled="!comparisonResult"
          >
            <i class="fas fa-code-branch me-2"></i>Compare
          </button>
        </li>
      </ul>

      <!-- Current Config View -->
      <div v-show="viewMode === 'current'">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <small class="text-muted">Lines: {{ configStats.lines }} | Size: {{ configStats.size }} KB</small>
          </div>
          <div class="d-flex gap-2">
            <input 
              v-model="searchText"
              type="text"
              class="form-control form-control-sm"
              placeholder="Search..."
              style="max-width: 150px;"
            >
            <button 
              class="btn btn-sm btn-outline-secondary"
              @click="copyToClipboard"
              title="Copy to clipboard"
            >
              <i class="fas fa-copy"></i>
            </button>
            <button 
              class="btn btn-sm btn-outline-secondary"
              @click="downloadConfig()"
              title="Download as file"
            >
              <i class="fas fa-download"></i>
            </button>
            <button 
              class="btn btn-sm btn-outline-primary"
              @click="downloadRunning"
              :disabled="downloadingRunning"
              title="Sync with running config"
            >
              <i class="fas fa-sync-alt" :class="{ 'fa-spin': downloadingRunning }"></i>
            </button>
          </div>
        </div>

        <div class="card border-0">
          <div class="card-body p-0">
            <pre v-if="currentConfig" class="mb-0" style="font-size: 12px; max-height: 500px; overflow: auto; background: #f5f5f5; padding: 15px;">{{ displayConfig }}</pre>
            <div v-else class="text-muted p-3">
              <i class="fas fa-database me-2"></i>No configuration loaded
            </div>
          </div>
        </div>
      </div>

      <!-- History View -->
      <div v-show="viewMode === 'history'" class="card">
        <div class="card-body">
          <h6 class="mb-3">Configuration History</h6>
          <div v-if="configHistory.length > 0">
            <div class="table-responsive">
              <table class="table table-sm">
                <thead>
                  <tr>
                    <th>Saved</th>
                    <th>Size</th>
                    <th>Type</th>
                    <th>Notes</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="config in configHistory" :key="config.id">
                    <td class="small">{{ new Date(config.created_at).toLocaleString() }}</td>
                    <td class="small">{{ (config.config_text.length / 1024).toFixed(2) }} KB</td>
                    <td><span class="badge bg-secondary">{{ config.backup_type }}</span></td>
                    <td class="small text-muted">{{ config.notes }}</td>
                    <td>
                      <button 
                        class="btn btn-xs btn-outline-secondary"
                        @click="selectedCompareId = config.id; compareConfigs()"
                        title="Compare with current"
                      >
                        Compare
                      </button>
                      <button
                        class="btn btn-xs btn-outline-primary ms-2"
                        @click="applyConfigFromHistory(config.id)"
                        title="Apply this configuration to the switch"
                      >
                        Apply
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <div v-else class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>No configuration history available
          </div>
        </div>
      </div>

      <!-- Compare View -->
      <div v-show="viewMode === 'compare'" v-if="comparisonResult">
        <div class="card">
          <div class="card-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h6 class="mb-0">Configuration Diff</h6>
              </div>
              <div>
                <span class="badge bg-success me-2">+{{ comparisonResult.stats.added }} Added</span>
                <span class="badge bg-danger">-{{ comparisonResult.stats.removed }} Removed</span>
              </div>
            </div>
          </div>
          <div class="card-body p-0">
            <div style="font-size: 12px; max-height: 500px; overflow: auto;">
              <div v-for="(diff, i) in comparisonResult.diffs" :key="'diff-' + i" class="d-flex">
                <div style="width: 50px; text-align: right; background: #f5f5f5; padding: 2px 8px; border-right: 1px solid #ddd;">
                  {{ diff.lineNumber }}
                </div>
                <div 
                  :style="getDiffLineStyle(diff)"
                >
                  <span v-if="diff.type === 'added'" class="text-success">+</span>
                  <span v-else-if="diff.type === 'removed'" class="text-danger">-</span>
                  <span v-else class="text-muted"> </span>
                  <code style="font-family: monospace;">{{ diff.content }}</code>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
}

