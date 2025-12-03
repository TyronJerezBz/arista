const API_BASE_URL = '/arista/api';

function loadAsset(id, tag, attrs) {
  return new Promise((resolve, reject) => {
    if (document.getElementById(id)) {
      resolve();
      return;
    }
    const el = document.createElement(tag);
    el.id = id;
    Object.entries(attrs).forEach(([k, v]) => el[k] = v);
    el.onload = () => resolve();
    el.onerror = () => reject(new Error(`Failed to load ${attrs.src || attrs.href}`));
    document.head.appendChild(el);
  });
}

function ensureDataTables() {
  const cssPromise = loadAsset(
    'datatables-css',
    'link',
    { rel: 'stylesheet', href: 'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css' }
  );

  const jsPromise = new Promise((resolve, reject) => {
    if (!window.jQuery) {
      reject(new Error('jQuery is required for DataTables'));
      return;
    }
    if (window.jQuery.fn && window.jQuery.fn.dataTable) {
      resolve();
      return;
    }
    loadAsset(
      'datatables-js',
      'script',
      { src: 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js' }
    ).then(resolve).catch(reject);
  });

  return Promise.all([cssPromise, jsPromise]);
}

export default {
  name: 'LogsViewer',
  props: {
    switchId: {
      type: [String, Number],
      required: true
    },
    csrfToken: {
      type: String,
      default: null
    }
  },
  data() {
    return {
      loading: false,
      error: null,
      entries: [],
      availableSeverities: [],
      selectedSeverities: [],
      lines: 200,
      textFilter: '',
      warnings: [],
      dataTable: null,
      dataTablesFilterFn: null
    };
  },
  mounted() {
    ensureDataTables()
      .then(() => this.fetchLogs())
      .catch(err => {
        console.error(err);
        this.error = err.message;
      });
  },
  beforeUnmount() {
    if (this.dataTable) {
      this.dataTable.destroy();
      this.dataTable = null;
    }
    if (this.dataTablesFilterFn && window.jQuery && window.jQuery.fn && window.jQuery.fn.dataTable) {
      const idx = window.jQuery.fn.dataTable.ext.search.indexOf(this.dataTablesFilterFn);
      if (idx > -1) {
        window.jQuery.fn.dataTable.ext.search.splice(idx, 1);
      }
    }
  },
  methods: {
    async fetchLogs() {
      this.loading = true;
      this.error = null;
      this.warnings = [];
      try {
        const response = await axios.get(`${API_BASE_URL}/switches/logs/get.php`, {
          params: {
            switch_id: this.switchId,
            lines: this.lines > 0 ? this.lines : 200,
            filter: this.textFilter || undefined
          },
          withCredentials: true
        });
        if (response.data?.success) {
          this.entries = response.data.entries || [];
          this.availableSeverities = response.data.severities || [];
          this.selectedSeverities = [...this.availableSeverities];
          this.warnings = response.data.warnings || [];
          this.$nextTick(() => this.renderTable());
        } else {
          this.error = response.data?.error || 'Failed to load logs';
        }
      } catch (e) {
        this.error = e.response?.data?.error || e.message;
      } finally {
        this.loading = false;
      }
    },

    renderTable() {
      const $ = window.jQuery;
      if (!($ && $.fn && $.fn.dataTable)) {
        this.error = 'DataTables not available';
        return;
      }

      if (this.dataTable) {
        this.dataTable.clear();
        this.dataTable.rows.add(this.entries);
        this.dataTable.draw();
        return;
      }

      const self = this;
      this.dataTablesFilterFn = function(settings, data, dataIndex, rowData) {
        if (!self.dataTable || settings !== self.dataTable.settings()[0]) {
          return true;
        }
        if (!self.selectedSeverities.length) {
          return false;
        }
        return self.selectedSeverities.includes(rowData.severity);
      };
      $.fn.dataTable.ext.search.push(this.dataTablesFilterFn);

      this.dataTable = $(this.$refs.table).DataTable({
        data: this.entries,
        deferRender: true,
        columns: [
          { title: 'Timestamp', data: 'timestamp', defaultContent: '-' },
          { title: 'Severity', data: 'severity', defaultContent: '-' },
          { title: 'Facility', data: 'facility', defaultContent: '-' },
          { title: 'Code', data: 'code', defaultContent: '-' },
          { title: 'Message', data: 'message', defaultContent: '-' }
        ],
        order: [[0, 'desc']],
        pageLength: 25,
        lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'All']],
        responsive: true
      });
    },

    toggleSeverity(sev) {
      const index = this.selectedSeverities.indexOf(sev);
      if (index > -1) {
        this.selectedSeverities.splice(index, 1);
      } else {
        this.selectedSeverities.push(sev);
      }
      if (this.dataTable) {
        this.dataTable.draw();
      }
    },

    isSeverityActive(sev) {
      return this.selectedSeverities.includes(sev);
    },

    applySearch() {
      if (this.dataTable) {
        this.dataTable.search(this.textFilter).draw();
      }
    },

    resetFilters() {
      this.textFilter = '';
      this.selectedSeverities = [...this.availableSeverities];
      if (this.dataTable) {
        this.dataTable.search('').draw();
        this.dataTable.draw();
      }
    }
  },
  template: `
    <div class="logs-viewer">
      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title mb-3">
            <i class="fas fa-clipboard-list me-2"></i> Switch Logs
          </h5>

          <div class="row g-3 align-items-end mb-3">
            <div class="col-md-2">
              <label class="form-label">Tail (lines)</label>
              <input type="number" class="form-control" v-model.number="lines" min="1" max="5000">
            </div>
            <div class="col-md-4">
              <label class="form-label">Search</label>
              <div class="input-group">
                <input type="text" class="form-control" v-model="textFilter" @keyup.enter="applySearch">
                <button class="btn btn-outline-secondary" type="button" @click="applySearch">Apply</button>
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Actions</label><br>
              <button class="btn btn-primary me-2" :disabled="loading" @click="fetchLogs">
                <span v-if="loading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ loading ? 'Loading...' : 'Refresh' }}
              </button>
              <button class="btn btn-outline-secondary" type="button" @click="resetFilters">Reset</button>
            </div>
          </div>

          <div v-if="availableSeverities.length" class="mb-3">
            <label class="form-label me-2">Severity:</label>
            <div class="btn-group flex-wrap">
              <button
                v-for="sev in availableSeverities"
                :key="sev"
                type="button"
                class="btn btn-sm"
                :class="isSeverityActive(sev) ? 'btn-success' : 'btn-outline-secondary'"
                @click="toggleSeverity(sev)"
              >
                {{ sev }}
              </button>
            </div>
          </div>

          <div v-if="warnings.length" class="alert alert-warning small">
            <ul class="mb-0 ps-3">
              <li v-for="w in warnings" :key="w">{{ w }}</li>
            </ul>
          </div>

          <div v-if="error" class="alert alert-danger">
            {{ error }}
          </div>

          <div v-if="!error">
            <table ref="table" class="display nowrap" style="width: 100%;"></table>
          </div>
        </div>
      </div>
    </div>
  `
};

