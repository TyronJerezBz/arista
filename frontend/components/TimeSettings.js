const API_BASE_URL = '/arista/api';

export default {
  name: 'TimeSettings',
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
      saving: false,
      error: null,
      clockText: '',
      timezoneLine: '',
      form: {
        timezone: '',
        offset: '',
        datetime: ''
      }
    };
  },
  mounted() {
    this.loadClock();
  },
  methods: {
    async loadClock() {
      this.loading = true;
      this.error = null;
      try {
        const response = await axios.get(
          `${API_BASE_URL}/switches/time/get.php`,
          {
            params: { switch_id: this.switchId },
            withCredentials: true
          }
        );
        if (response.data?.success) {
          this.clockText = response.data.clock || '';
          this.timezoneLine = response.data.timezone || '';
          this.parseTimezoneLine();
        } else {
          this.error = response.data?.error || 'Failed to load clock';
        }
      } catch (e) {
        this.error = e.response?.data?.error || e.message;
      } finally {
        this.loading = false;
      }
    },

    parseTimezoneLine() {
      if (!this.timezoneLine) return;
      const parts = this.timezoneLine.trim().split(/\s+/);
      if (parts.length >= 3 && parts[0] === 'clock' && parts[1] === 'timezone') {
        this.form.timezone = parts[2];
        this.form.offset = parts.slice(3).join(' ');
      }
    },

    setDatetimeToBrowser() {
      const now = new Date();
      const offsetMs = now.getTimezoneOffset() * 60000;
      const localISO = new Date(now.getTime() - offsetMs).toISOString().slice(0,16);
      this.form.datetime = localISO;
    },

    async applySettings() {
      if (!this.csrfToken) {
        this.$emit('show-message', 'CSRF token not available', 'error');
        return;
      }

      if ((!this.form.timezone || this.form.timezone.trim() === '') &&
          (!this.form.datetime || this.form.datetime.trim() === '')) {
        this.$emit('show-message', 'Enter a timezone or time to update', 'warning');
        return;
      }

      const payload = { csrf_token: this.csrfToken };
      if (this.form.timezone && this.form.timezone.trim() !== '') {
        payload.timezone = this.form.timezone.trim();
        payload.offset = this.form.offset ? this.form.offset.trim() : '';
      }
      if (this.form.datetime && this.form.datetime.trim() !== '') {
        // Convert local datetime to ISO string
        const local = new Date(this.form.datetime);
        if (isNaN(local.getTime())) {
          this.$emit('show-message', 'Invalid date/time', 'error');
          return;
        }
        const iso = new Date(local.getTime() - local.getTimezoneOffset() * 60000).toISOString();
        payload.datetime = iso;
      }

      this.saving = true;
      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/time/set.php?switch_id=${this.switchId}`,
          payload,
          {
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );
        if (response.data?.success) {
          this.$emit('show-message', response.data.message || 'Clock updated', 'success');
          if (payload.timezone) {
            this.$emit('config-changed');
          }
          await this.loadClock();
        } else {
          this.$emit('show-message', response.data?.error || 'Failed to update clock', 'error');
        }
      } catch (e) {
        this.$emit('show-message', e.response?.data?.error || e.message, 'error');
      } finally {
        this.saving = false;
      }
    }
  },
  template: `
    <div class=\"time-settings\">
      <div class=\"card mb-3\">
        <div class=\"card-body\">
          <h5 class=\"card-title mb-3\">
            <i class=\"fas fa-clock me-2\"></i> Switch Time & Timezone
          </h5>

          <div v-if=\"error\" class=\"alert alert-danger\">{{ error }}</div>

          <div v-if=\"loading\" class=\"text-center py-4\">
            <div class=\"spinner-border text-primary\" role=\"status\">
              <span class=\"visually-hidden\">Loading...</span>
            </div>
          </div>

          <div v-else>
            <div class=\"mb-3\">
              <label class=\"form-label fw-bold\">Current Clock Output</label>
              <pre class=\"bg-light p-2 rounded small\">{{ clockText || 'Unavailable' }}</pre>
            </div>

            <div class=\"mb-4\">
              <label class=\"form-label fw-bold\">Configured Timezone</label>
              <pre class=\"bg-light p-2 rounded small\">{{ timezoneLine || 'No timezone configured' }}</pre>
            </div>

            <div class=\"row g-3 align-items-end\">
              <div class=\"col-md-4\">
                <label class=\"form-label\">Timezone Name</label>
                <input type=\"text\" class=\"form-control\" v-model=\"form.timezone\" placeholder=\"e.g., UTC or US/Eastern\">
              </div>
              <div class=\"col-md-3\">
                <label class=\"form-label\">Offset (optional)</label>
                <input type=\"text\" class=\"form-control\" v-model=\"form.offset\" placeholder=\"e.g., -5 or 8\">
              </div>
              <div class=\"col-md-5\">
                <label class=\"form-label\">Set Time</label>
                <div class=\"input-group\">
                  <input type=\"datetime-local\" class=\"form-control\" v-model=\"form.datetime\">
                  <button class=\"btn btn-outline-secondary\" type=\"button\" @click=\"setDatetimeToBrowser\">Use Browser Time</button>
                </div>
              </div>
            </div>

            <div class=\"mt-4\">
              <button class=\"btn btn-primary\" :disabled=\"saving\" @click=\"applySettings\">
                <span v-if=\"saving\" class=\"spinner-border spinner-border-sm me-2\" role=\"status\"></span>
                {{ saving ? 'Applying...' : 'Apply Settings' }}
              </button>
              <button class=\"btn btn-outline-secondary ms-2\" type=\"button\" @click=\"loadClock\" :disabled=\"loading || saving\">
                Refresh
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
};

