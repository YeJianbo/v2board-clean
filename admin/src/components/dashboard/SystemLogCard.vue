<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { RefreshCw, Eye } from 'lucide-vue-next'

const props = defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
  runtimeStatus: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['refresh'])
const { t } = useI18n()
const router = useRouter()
const traffic = computed(() => props.runtimeStatus.trafficAccounting)
const trafficLabel = computed(() => {
  if (traffic.value?.available !== true) return '不可用'
  const count = `${traffic.value.pending_capped ? '>' : ''}${traffic.value.pending}`
  return traffic.value.delayed ? `积压 ${count} 条` : traffic.value.pending ? `待处理 ${count} 条` : '无待处理'
})
const trafficTone = computed(() => traffic.value?.available !== true ? 'danger' : traffic.value.delayed ? 'warning' : traffic.value.pending ? 'info' : 'success')

function goToLogs() {
  router.push({ name: 'logs' })
}

function statusTagType(tone) {
  const typeMap = {
    danger: 'danger',
    success: 'success',
  }

  return typeMap[tone] || 'info'
}
</script>

<template>
  <section v-loading="loading" class="runtime-status-bar" aria-labelledby="runtime-status-title">
    <div class="section-head runtime-status-head">
      <div>
        <h3 id="runtime-status-title">运行状态</h3>
        <p class="runtime-last-schedule">{{ t('system.lastSchedule') }} <strong>{{ runtimeStatus.scheduleLastRuntime }}</strong></p>
      </div>

      <div class="system-log-actions">
        <el-tag :type="statusTagType(runtimeStatus.scheduleStatusTone)" effect="dark" size="small">
          {{ t('system.schedule', { status: runtimeStatus.scheduleStatusText }) }}
        </el-tag>
        <el-tag :type="statusTagType(runtimeStatus.horizonStatusTone)" effect="dark" size="small">
          {{ t('system.horizon', { status: runtimeStatus.horizonStatusText }) }}
        </el-tag>
        <el-tooltip v-if="traffic" :content="traffic.available ? `最早待处理上报：${runtimeStatus.trafficOldestReport}` : '无法读取待处理流量，请检查数据库和流量更新任务'" :disabled="traffic.available && !traffic.pending">
          <el-tag :type="trafficTone" size="small">流量入账 {{ trafficLabel }}</el-tag>
        </el-tooltip>
        <el-button :icon="RefreshCw" :disabled="loading" size="small" plain @click="emit('refresh')">刷新</el-button>
        <el-button :icon="Eye" size="small" plain type="primary" @click="goToLogs">查看审计日志</el-button>
      </div>
    </div>

  </section>
</template>

<style scoped>
.system-log-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  justify-content: flex-end;
}
</style>
