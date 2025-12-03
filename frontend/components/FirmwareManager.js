const API_BASE_URL = '/arista/api';

export default {
  name: 'FirmwareManager',
  props: {
    csrfToken: {
      type: String,
      default: null
    },
    user: {
      type: Object,
      required: true
    }
  },
  data() {
    return {
      firmware: [],
      loading: false,
      uploading: false,
      error: null,
      uploadError: null,
      selectedFile: null,
      form: {
        version: '',
        model: '',
        notes: ''
      },
      allowedExtensions: ['swix','swi','swp','tar','gz','tgz','rpm','bin','img']
    };
  },
  computed: {
    canUpload() {
      return this.user.role === 'admin' || this.user.role === 'operator';
    },
    canDelete() {
      return this.user.role === 'admin';
    }
  },
  mounted() {
    this.loadFirmware();
  },
  methods: {
    async loadFirmware() {
      this.loading = true;
      this.error = null;
      try {
        const response = await axios.get(`${API_BASE_URL}/firmware/list.php`, {
          withCredentials: true
        });
        if (response.data?.success) {
          this.firmware = response.data.firmware || [];
        } else {
          this.error = response.data?.error || 'Failed to load firmware list';
        }
      } catch (e) {
        this.error = e.response?.data?.error || e.message;
      } finally {
        this.loading = false;
      }
    },

    onFileChange(event) {
      this.selectedFile = event.target.files[0] || null;
      this.uploadError = null;
    },

    resetForm() {
      this.selectedFile = null;
      this.form = { version: '', model: '', notes: '' };
      this.uploadError = null;
      this.$refs.fileInput.value = '';
    },

    formatSize(bytes) {
      if (!bytes && bytes !== 0) return '-';
      const units = ['B', 'KB', 'MB', 'GB'];
      let size = bytes;
      let unit = 0;
      while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
      }
      return `${size.toFixed(unit === 0 ? 0 : 2)} ${units[unit]}`;
    },

    formatDate(value) {
      if (!value) return '-';
      try {
        return new Date(value).toLocaleString();
      } catch (e) {
        return value;
      }
    },

    async uploadFirmware() {
      if (!this.canUpload) {
        this.uploadError = 'You do not have permission to upload firmware';
        return;
      }
      if (!this.csrfToken) {
        this.uploadError = 'CSRF token not available';
        return;
      }
      if (!this.selectedFile) {
        this.uploadError = 'Please select a firmware file';
        return;
      }

      const formData = new FormData();
      formData.append('csrf_token', this.csrfToken);
      formData.append('firmware', this.selectedFile);
      formData.append('version', this.form.version);
      formData.append('model', this.form.model);
      formData.append('notes', this.form.notes);

      this.uploading = true;
      this.uploadError = null;
      try {
        const response = await axios.post(
          `${API_BASE_URL}/firmware/upload.php`,
          formData,
          {
            withCredentials: true,
            headers: { 'Content-Type': 'multipart/form-data' }
          }
        );
        if (response.data?.success) {
          this.$emit('show-message', 'Firmware uploaded successfully', 'success');
          this.firmware.unshift(response.data.firmware);
          this.resetForm();
        } else {
          this.uploadError = response.data?.error || 'Failed to upload firmware';
        }
      } catch (e) {
        this.uploadError = e.response?.data?.error || e.message;
      } finally {
        this.uploading = false;
      }
    },

    async deleteFirmware(item) {
      if (!this.canDelete) return;
      if (!confirm(`Delete firmware "${item.original_filename}"? This cannot be undone.`)) {
        return;
      }
      if (!this.csrfToken) {
        this.$emit('show-message', 'CSRF token not available', 'error');
        return;
      }
      try {
        const response = await axios.delete(
          `${API_BASE_URL}/firmware/delete.php?id=${item.id}`,
          {
            data: { csrf_token: this.csrfToken },
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );
        if (response.data?.success) {
          this.$emit('show-message', 'Firmware deleted', 'success');
          this.firmware = this.firmware.filter(f => f.id !== item.id);
        } else {
          this.$emit('show-message', response.data?.error || 'Failed to delete firmware', 'error');
        }
      } catch (e) {
        this.$emit('show-message', e.response?.data?.error || e.message, 'error');
      }
    },

    downloadFirmware(item) {
      window.open(item.download_url, '_blank');
    }
  },
  template: `
    <div class="firmware-manager">
      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title mb-3">
            <i class="fas fa-hdd me-2"></i> Firmware Archive
          </h5>

          <div v-if="canUpload" class="mb-4 border rounded p-3 bg-light">
            <h6 class="mb-3">Upload Firmware</h6>
            <div v-if="uploadError" class="alert alert-danger small">{{ uploadError }}</div>
            <div class="row g-3 align-items-end">
              <div class="col-md-4">
                <label class="form-label">Firmware File</label>
                <input type="file" class="form-control" ref="fileInput" @change="onFileChange">
                <small class="text-muted">Allowed: {{ allowedExtensions.join(', ') }}</small>
              </div>
              <div class="col-md-2">
                <label class="form-label">Version</label>
                <input type="text" class="form-control" v-model="form.version" placeholder="e.g., 4.29.3F">
              </div>
              <div class="col-md-3">
                <label class="form-label">Model</label>
                <input type="text" class="form-control" v-model="form.model" placeholder="e.g., 7280SE">
              </div>
              <div class="col-md-3">
                <label class="form-label">Notes</label>
                <input type="text" class="form-control" v-model="form.notes" placeholder="Optional notes">
              </div>
            </div>
            <div class="mt-3">
              <button class="btn btn-primary" :disabled="uploading" @click="uploadFirmware">
                <span v-if="uploading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ uploading ? 'Uploading...' : 'Upload Firmware' }}
              </button>
              <button class="btn btn-outline-secondary ms-2" type="button" @click="resetForm" :disabled="uploading">
                Clear
              </button>
              <div v-if="selectedFile" class="mt-2 text-muted small">
                Selected: {{ selectedFile.name }} ({{ formatSize(selectedFile.size) }})
              </div>
            </div>
          </div>

          <div v-if="error" class="alert alert-danger">
            {{ error }}
          </div>

          <div v-if="loading" class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
          </div>

          <div v-if="!loading && firmware.length === 0" class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            No firmware files uploaded yet.
          </div>

          <div v-if="firmware.length > 0" class="table-responsive">
            <table class="table table-striped align-middle">
              <thead>
                <tr>
                  <th>Filename</th>
                  <th>Version</th>
                  <th>Model</th>
                  <th>Size</th>
                  <th>Uploaded</th>
                  <th>Uploaded By</th>
                  <th>Checksum</th>
                  <th>Notes</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in firmware" :key="item.id">
                  <td>{{ item.original_filename }}</td>
                  <td>{{ item.version || '-' }}</td>
                  <td>{{ item.model || '-' }}</td>
                  <td>{{ formatSize(item.size) }}</td>
                  <td>{{ formatDate(item.uploaded_at) }}</td>
                  <td>{{ item.uploaded_by_username || '—' }}</td>
                  <td>
                    <code class="small">{{ item.checksum_sha256 || '-' }}</code>
                  </td>
                  <td>{{ item.notes || '-' }}</td>
                  <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary me-1" @click="downloadFirmware(item)">
                      <i class="fas fa-download"></i>
                    </button>
                    <button
                      v-if="canDelete"
                      class="btn btn-sm btn-outline-danger"
                      @click="deleteFirmware(item)"
                    >
                      <i class="fas fa-trash"></i>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  `
};

