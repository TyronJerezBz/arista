// Restart Scheduler Component - Schedule and Manage Restarts
const API_BASE_URL = '/arista/api';

export default {
  name: 'RestartScheduler',
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
      restarting: false,
      scheduling: false,
      scheduledTasks: [],
      showScheduleModal: false,
      scheduleDate: '',
      scheduleTime: '00:00',
      forceReboot: false,
      error: null,
      success: null,
      minDateTime: this.getMinDateTime()
    }
  },
  computed: {
    scheduledDateTime() {
      if (!this.scheduleDate || !this.scheduleTime) return null;
      return `${this.scheduleDate} ${this.scheduleTime}`;
    },
    hasScheduledTasks() {
      return this.scheduledTasks && this.scheduledTasks.length > 0;
    }
  },
  mounted() {
    this.loadScheduledTasks();
  },
  methods: {
    getMinDateTime() {
      const now = new Date();
      now.setMinutes(now.getMinutes() + 5); // Minimum 5 minutes in future
      return now.toISOString().split('T')[0] + 'T' + 
             String(now.getHours()).padStart(2, '0') + ':' + 
             String(now.getMinutes()).padStart(2, '0');
    },

    async loadScheduledTasks() {
      this.loading = true;
      try {
        const response = await axios.get(
          `${API_BASE_URL}/switches/restart.php?id=${this.switchId}&action=list`,
          { withCredentials: true }
        );
        
        if (response.data.success) {
          this.scheduledTasks = response.data.tasks || [];
        }
      } catch (error) {
        this.error = 'Failed to load scheduled tasks: ' + (error.response?.data?.error || error.message);
      } finally {
        this.loading = false;
      }
    },

    async restartNow() {
      if (!confirm(`Restart ${this.switchHostname} immediately?\n\nThis will interrupt network connectivity.`)) {
        return;
      }

      this.restarting = true;
      this.error = null;
      this.success = null;

      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/restart.php?id=${this.switchId}`,
          {
            csrf_token: this.csrfToken,
            action: 'restart_now'
          },
          { withCredentials: true, headers: { 'Content-Type': 'application/json' } }
        );

        if (response.data.success) {
          this.success = `Switch restarted successfully. It may take a few minutes to come back online.`;
          this.$emit('show-message', this.success, 'success');
          this.$emit('restart-initiated', response.data);
          
          // Reload tasks list
          setTimeout(() => this.loadScheduledTasks(), 2000);
        } else {
          this.error = response.data.error || 'Restart failed';
        }
      } catch (error) {
        const errMsg = error.response?.data?.error || error.message;
        this.error = 'Failed to restart switch: ' + errMsg;
        this.$emit('show-message', this.error, 'error');
      } finally {
        this.restarting = false;
      }
    },

    async scheduleRestart() {
      if (!this.scheduledDateTime) {
        this.error = 'Please select both date and time';
        return;
      }

      if (!confirm(`Schedule ${this.switchHostname} to restart on ${this.scheduledDateTime}?`)) {
        return;
      }

      this.scheduling = true;
      this.error = null;
      this.success = null;

      try {
        const response = await axios.post(
          `${API_BASE_URL}/switches/restart.php?id=${this.switchId}`,
          {
            csrf_token: this.csrfToken,
            action: 'schedule',
            schedule_time: this.scheduledDateTime,
            force_reboot: this.forceReboot
          },
          { withCredentials: true, headers: { 'Content-Type': 'application/json' } }
        );

        if (response.data.success) {
          this.success = `Restart scheduled for ${this.scheduledDateTime}`;
          this.$emit('show-message', this.success, 'success');
          this.$emit('restart-scheduled', response.data);
          
          // Reset form and close modal
          this.resetForm();
          this.showScheduleModal = false;
          
          // Reload tasks list
          this.loadScheduledTasks();
        } else {
          this.error = response.data.error || 'Scheduling failed';
        }
      } catch (error) {
        const errMsg = error.response?.data?.error || error.message;
        this.error = 'Failed to schedule restart: ' + errMsg;
        this.$emit('show-message', this.error, 'error');
      } finally {
        this.scheduling = false;
      }
    },

    async cancelScheduledRestart(taskId) {
      if (!confirm('Cancel this scheduled restart?')) {
        return;
      }

      try {
        const response = await axios.delete(
          `${API_BASE_URL}/switches/restart.php?id=${this.switchId}&task_id=${taskId}`,
          {
            data: { csrf_token: this.csrfToken },
            withCredentials: true,
            headers: { 'Content-Type': 'application/json' }
          }
        );

        if (response.data.success) {
          this.success = 'Restart cancelled';
          this.$emit('show-message', this.success, 'success');
          this.loadScheduledTasks();
        } else {
          this.error = response.data.error || 'Cancellation failed';
        }
      } catch (error) {
        const errMsg = error.response?.data?.error || error.message;
        this.error = 'Failed to cancel restart: ' + errMsg;
        this.$emit('show-message', this.error, 'error');
      }
    },

    resetForm() {
      this.scheduleDate = '';
      this.scheduleTime = '00:00';
      this.forceReboot = false;
      this.error = null;
    },

    getStatusBadge(status) {
      const badges = {
        pending: 'warning',
        in_progress: 'info',
        completed: 'success',
        failed: 'danger',
        cancelled: 'secondary'
      };
      return badges[status] || 'secondary';
    },

    formatDateTime(dt) {
      return new Date(dt).toLocaleString();
    }
  },
  template: `
    <div class="restart-scheduler">
      <!-- Alerts -->
      <div v-if="error" class="alert alert-danger alert-dismissible small mb-2">
        <i class="fas fa-exclamation-circle me-2"></i>
        {{ error }}
        <button type="button" class="btn-close btn-sm" @click="error = null"></button>
      </div>

      <div v-if="success" class="alert alert-success alert-dismissible small mb-2">
        <i class="fas fa-check-circle me-2"></i>
        {{ success }}
        <button type="button" class="btn-close btn-sm" @click="success = null"></button>
      </div>

      <!-- Quick Actions -->
      <div class="d-flex gap-2 mb-3">
        <button 
          class="btn btn-danger btn-sm"
          @click="restartNow"
          :disabled="restarting || loading"
          title="Restart immediately"
        >
          <i class="fas fa-power-off me-2"></i>
          {{ restarting ? 'Restarting...' : 'Restart Now' }}
        </button>

        <button 
          class="btn btn-warning btn-sm"
          @click="showScheduleModal = true"
          :disabled="scheduling || loading"
          title="Schedule restart"
        >
          <i class="fas fa-calendar-plus me-2"></i>
          Schedule Restart
        </button>

        <button 
          class="btn btn-outline-secondary btn-sm"
          @click="loadScheduledTasks"
          :disabled="loading"
        >
          <i class="fas fa-sync-alt me-2" :class="{ 'fa-spin': loading }"></i>
          Refresh
        </button>
      </div>

      <!-- Scheduled Tasks List -->
      <div v-if="loading" class="alert alert-info">
        <i class="fas fa-spinner fa-spin me-2"></i>
        Loading scheduled tasks...
      </div>

      <div v-else-if="hasScheduledTasks" class="card">
        <div class="card-header bg-light">
          <h6 class="mb-0">Scheduled Restarts ({{ scheduledTasks.length }})</h6>
        </div>
        <div class="card-body p-0">
          <div v-for="task in scheduledTasks" :key="task.id" class="border-bottom p-3 last:border-0">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <small class="text-muted">Scheduled for:</small>
                <div class="font-monospace small">{{ formatDateTime(task.schedule_time) }}</div>
              </div>
              <span :class="'badge bg-' + getStatusBadge(task.status)">{{ task.status }}</span>
            </div>
            
            <div v-if="task.details" class="small text-muted mb-2">
              <div>Force Reboot: {{ task.details.force_reboot ? 'Yes' : 'No' }}</div>
            </div>

            <button 
              v-if="task.status === 'pending'"
              class="btn btn-sm btn-outline-danger"
              @click="cancelScheduledRestart(task.id)"
              title="Cancel this restart"
            >
              <i class="fas fa-times me-1"></i>
              Cancel
            </button>
          </div>
        </div>
      </div>

      <div v-else class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        No scheduled restarts
      </div>

      <!-- Schedule Modal -->
      <div v-if="showScheduleModal" class="modal-backdrop show d-block"></div>
      <div v-if="showScheduleModal" class="modal show d-block">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header bg-light">
              <h5 class="modal-title">Schedule Restart</h5>
              <button 
                type="button" 
                class="btn-close" 
                @click="showScheduleModal = false"
              ></button>
            </div>

            <div class="modal-body">
              <div v-if="error" class="alert alert-danger alert-dismissible small">
                {{ error }}
                <button type="button" class="btn-close btn-sm" @click="error = null"></button>
              </div>

              <div class="mb-3">
                <label class="form-label">Date</label>
                <input 
                  v-model="scheduleDate"
                  type="date"
                  class="form-control"
                  :min="minDateTime.split('T')[0]"
                >
              </div>

              <div class="mb-3">
                <label class="form-label">Time</label>
                <input 
                  v-model="scheduleTime"
                  type="time"
                  class="form-control"
                >
              </div>

              <div class="form-check mb-3">
                <input 
                  v-model="forceReboot"
                  type="checkbox"
                  class="form-check-input"
                  id="forceReboot"
                >
                <label class="form-check-label" for="forceReboot">
                  Force reboot (don't wait for graceful shutdown)
                </label>
              </div>

              <div class="alert alert-warning small">
                <i class="fas fa-exclamation-triangle me-2"></i>
                The switch will restart at the scheduled time and will be temporarily unavailable.
              </div>
            </div>

            <div class="modal-footer">
              <button 
                type="button" 
                class="btn btn-secondary"
                @click="showScheduleModal = false"
              >
                Cancel
              </button>
              <button 
                type="button" 
                class="btn btn-primary"
                @click="scheduleRestart"
                :disabled="scheduling || !scheduleDate || !scheduleTime"
              >
                {{ scheduling ? 'Scheduling...' : 'Schedule' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `
}

