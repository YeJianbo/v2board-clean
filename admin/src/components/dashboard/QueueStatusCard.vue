<script setup>
import { RefreshCw } from 'lucide-vue-next'
import { useI18n } from 'vue-i18n'
const props = defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
  queueStatus: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['refresh', 'view-jobs'])
const { t } = useI18n()

function statusTagType(tone) {
  const typeMap = {
    danger: 'danger',
    success: 'success',
  }

  return typeMap[tone] || 'info'
}
</script>

<template>
  <section v-loading="loading" class="queue-card" aria-labelledby="queue-status-title">
    <div class="section-head queue-card__head">
      <div>
        <h4 id="queue-status-title">{{ t('queue.statusTitle') }}</h4>
      </div>

      <div class="queue-card__actions">
        <el-tag :type="statusTagType(queueStatus.statusTone)" effect="dark">
          {{ queueStatus.statusText }}
        </el-tag>
        <el-button :icon="RefreshCw" :disabled="loading" size="small" plain @click="emit('refresh')">刷新</el-button>
      </div>
    </div>

    <div class="queue-card__metrics">
      <article>
        <span>{{ t('queue.jobsPerMinute') }}</span>
        <strong>{{ queueStatus.jobsPerMinute }}</strong>
      </article>
      <button class="queue-card__metric-link" type="button" aria-label="查看近期任务" @click="emit('view-jobs', 'recent')">
        <span>{{ t('queue.recentJobs') }}</span>
        <strong>{{ queueStatus.recentJobs }}</strong>
        <small>查看任务</small>
      </button>
      <button class="queue-card__metric-link" type="button" aria-label="查看失败任务" @click="emit('view-jobs', 'failed')">
        <span>{{ t('queue.failedJobs') }}</span>
        <strong>{{ queueStatus.failedJobs }}</strong>
        <small>查看详情</small>
      </button>
      <article>
        <span>{{ t('queue.failedPeriodJobs') }}</span>
        <strong>{{ queueStatus.failedPeriodJobs }}</strong>
      </article>
      <article>
        <span>{{ t('queue.pausedMaster') }}</span>
        <strong>{{ queueStatus.pausedMasters }}</strong>
      </article>
      <article>
        <span>{{ t('queue.activeProcesses') }}</span>
        <strong>{{ queueStatus.processes }}</strong>
      </article>
    </div>
  </section>
</template>

<style scoped>
.queue-card__metric-link {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 4px;
  padding: 10px;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: var(--panel-strong);
  text-align: left;
  font: inherit;
  color: inherit;
  cursor: pointer;
}
.queue-card__metric-link:hover { border-color: var(--el-color-primary); background: var(--el-color-primary-light-9); }
.queue-card__metric-link:focus-visible { outline: 2px solid var(--el-color-primary); outline-offset: 3px; }
.queue-card__metric-link small { grid-column: 2; grid-row: 1; font-size: 11px; color: var(--el-color-primary); white-space: nowrap; }
.queue-card__metric-link strong { grid-column: 1 / -1; margin-top: 0; }
</style>
