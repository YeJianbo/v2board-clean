<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { RefreshCw } from 'lucide-vue-next'
import SectionCard from '../common/SectionCard.vue'
import { fetchManagedMachineStatuses, summarizeMachineStatuses } from '../../services/machineStatus'

const router = useRouter()
function viewMachines(status) { router.push({ name: 'nodeMachines', query: { status } }) }
const summary = ref(null)
const error = ref('')
const loading = ref(false)
const updatedAt = ref('')
let timer
let stopped = false
const readsController = new AbortController()

function rateText(value) {
  if (value === null || value === undefined) return '--'
  const units = ['B/s', 'KB/s', 'MB/s', 'GB/s']
  let index = 0
  while (value >= 1024 && index < units.length - 1) { value /= 1024; index += 1 }
  return `${value.toFixed(index ? 1 : 0)} ${units[index]}`
}

async function refresh() {
  clearTimeout(timer)
  if (stopped || loading.value || document.hidden) return
  loading.value = true
  try {
    const machines = await fetchManagedMachineStatuses({ signal: readsController.signal })
    if (stopped) return
    summary.value = summarizeMachineStatuses(machines)
    updatedAt.value = new Date().toLocaleTimeString('zh-CN', { hour12: false })
    error.value = ''
  } catch (err) {
    if (!stopped) error.value = err.message || '机器状态加载失败'
  } finally {
    loading.value = false
    if (!stopped && !document.hidden) timer = setTimeout(refresh, 15000)
  }
}

function onVisibilityChange() {
  if (document.hidden) clearTimeout(timer)
  else refresh()
}

onMounted(() => {
  refresh()
  document.addEventListener('visibilitychange', onVisibilityChange)
})
onBeforeUnmount(() => {
  stopped = true
  readsController.abort()
  clearTimeout(timer)
  document.removeEventListener('visibilitychange', onVisibilityChange)
})
</script>

<template>
  <SectionCard title="机器概览" class="machine-overview-card">
    <template #actions>
      <el-button link type="primary" @click="router.push({ name: 'nodeMachines' })">机器管理</el-button>
      <el-button :icon="RefreshCw" :loading="loading" title="刷新机器状态" aria-label="刷新机器状态" @click="refresh" />
    </template>
    <el-alert v-if="error" type="warning" :closable="false" :title="error" />
    <div v-loading="loading && !summary" class="machine-overview-grid">
      <article>
        <span>在线机器</span>
        <strong>{{ summary?.online ?? '--' }} <small v-if="summary">/ {{ summary.total }}</small></strong>
        <button v-if="summary" class="machine-summary-link" :class="{ 'has-offline': summary.offline }" type="button" @click="viewMachines('offline')">{{ summary.offline }} 台离线</button>
      </article>
      <article><span>实时上行</span><strong>{{ rateText(summary?.upload) }}</strong></article>
      <article><span>实时下行</span><strong>{{ rateText(summary?.download) }}</strong></article>
      <article>
        <span>服务异常主机</span>
        <el-tooltip v-if="summary?.failedServices" :content="summary.failedMachines.map(machine => `${machine.name}: ${machine.message}`).join('；')" :popper-style="{ maxWidth: 'min(360px, calc(100vw - 32px))', overflowWrap: 'anywhere' }" placement="top" :show-after="150">
          <button class="machine-summary-link machine-summary-link--value has-offline" type="button" @click="viewMachines('failed')">{{ summary.failedServices }}</button>
        </el-tooltip>
        <strong v-else>{{ summary?.failedServices ?? '--' }}</strong>
      </article>
    </div>
    <div v-if="summary" class="machine-resource-overview">
      <div class="machine-resource-overview__item">
        <div><span>在线比例</span><strong>{{ summary.total ? (summary.online / summary.total * 100).toFixed(1) : '0.0' }}%</strong></div>
        <el-progress :percentage="summary.total ? summary.online / summary.total * 100 : 0" :show-text="false" :stroke-width="6" />
      </div>
      <div v-for="metric in [{ key: 'cpu', label: '平均 CPU' }, { key: 'memory', label: '平均内存' }]" :key="metric.key" class="machine-resource-overview__item">
        <div><span>{{ metric.label }}</span><strong>{{ summary[metric.key] === null ? '--' : `${summary[metric.key].toFixed(1)}%` }}</strong></div>
        <el-progress :percentage="summary[metric.key] ?? 0" :show-text="false" :stroke-width="6" />
      </div>
    </div>
    <footer v-if="summary" class="machine-overview-footer">
      <button type="button" class="machine-summary-link" :class="{ 'has-offline': summary.trafficAlerts }" @click="viewMachines('traffic')">流量告警 {{ summary.trafficAlerts }} 台</button>
      <span>更新于 {{ updatedAt }}</span>
    </footer>
  </SectionCard>
</template>

<style scoped>
.machine-summary-link { width: fit-content; border: 0; background: none; padding: 3px 0; font: inherit; font-size: 12px; color: var(--muted); cursor: pointer; text-decoration: underline; text-underline-offset: 3px; }
.machine-summary-link--value { font-size: 26px; font-weight: 600; text-decoration: none; }
.machine-summary-link:focus-visible { outline: 2px solid var(--primary); outline-offset: 3px; }
.machine-overview-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 20px;
  min-height: 80px;
}
.machine-overview-grid article { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.machine-overview-grid span, .machine-overview-grid small { color: var(--muted); font-size: 12px; }
.machine-overview-grid strong { font-size: 26px; font-weight: 600; line-height: 1.2; white-space: nowrap; font-variant-numeric: tabular-nums; }
.has-offline { color: var(--el-color-danger) !important; }
.machine-resource-overview { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 28px; padding-top: 18px; margin-top: 12px; border-top: 1px solid var(--line); }
.machine-resource-overview__item > div:first-child { display: flex; justify-content: space-between; margin-bottom: 10px; gap: 8px; font-size: 12px; color: var(--muted); }
.machine-resource-overview__item strong { color: var(--text); font-variant-numeric: tabular-nums; }
.machine-overview-footer { display: flex; justify-content: space-between; margin-top: 16px; gap: 12px; font-size: 12px; color: var(--muted); }
@media (max-width: 720px) {
  .machine-overview-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
  .machine-overview-grid strong { font-size: 20px; }
  .machine-resource-overview { grid-template-columns: 1fr; gap: 16px; }
}
</style>
