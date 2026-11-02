<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { fetchPanelBackupStatus } from '../../services/settings'

const props = defineProps({ request: { type: Object, default: null } })
const emit = defineEmits(['busy'])
const state = ref(null)
const loading = ref(false)
const error = ref('')
let pending = null, timer = null, disposed = false, suspended = false
const phase = computed(() => state.value?.phase || 'unknown')
const labels = { idle: '暂无记录', queued: '排队中', running: '执行中', success: '备份成功', failed: '备份失败', unknown: '待确认' }
const descriptions = {
  idle: '尚无备份执行记录', queued: '任务已入队，等待备份工作进程执行',
  running: '正在生成或上传备份，请勿重复提交', success: '备份任务已完成',
  failed: '备份未完成，请查看队列失败记录或服务器日志', unknown: '暂时无法确认执行结果，请刷新核对',
}
const busy = computed(() => ['queued', 'running'].includes(phase.value))
const tagType = computed(() => ({ success: 'success', failed: 'danger', unknown: 'warning', idle: 'info' }[phase.value] || 'primary'))
const typeLabel = computed(() => ({ database: '数据库', migration: '完整迁移包' }[state.value?.type || props.request?.type] || ''))
function formatTime(value) {
  if (!value) return ''
  const number = Number(value)
  const date = new Date(Number.isFinite(number) ? number * 1000 : value)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('zh-CN', { hour12: false })
}
const times = computed(() => [
  ['提交', state.value?.queued_at], ['开始', state.value?.started_at], ['结束', state.value?.completed_at],
].map(([label, value]) => ({ label, value: formatTime(value) })).filter(item => item.value))
function cancelRead() {
  clearTimeout(timer); timer = null
  const request = pending; pending = null
  request?.abort()
  loading.value = false
}
function schedule() {
  clearTimeout(timer); timer = null
  if (disposed || suspended || document.hidden) return
  if (busy.value || error.value || (phase.value === 'unknown' && props.request)) {
    timer = setTimeout(load, busy.value ? 10000 : 30000)
  }
}
async function load() {
  if (disposed || suspended || document.hidden || pending) return
  clearTimeout(timer); timer = null
  const request = new AbortController(); pending = request
  loading.value = true
  try {
    const result = await fetchPanelBackupStatus(String(props.request?.job_id || ''), { signal: request.signal })
    if (disposed || pending !== request || request.signal.aborted) return
    state.value = { ...result, phase: Object.hasOwn(labels, result.phase) ? result.phase : 'unknown' }
    error.value = ''
    emit('busy', busy.value)
  } catch (failure) {
    if (disposed || pending !== request || request.signal.aborted) return
    error.value = failure?.message || '状态更新失败，请刷新重试'
  } finally {
    if (pending === request) { pending = null; loading.value = false; schedule() }
  }
}
function visibility() { if (document.hidden) cancelRead(); else load() }
function pagehide() { suspended = true; cancelRead() }
function pageshow(event) { if (event.persisted && !disposed) { suspended = false; load() } }
watch(() => props.request, () => {
  cancelRead()
  state.value = props.request?.job_id ? { phase: 'queued', type: props.request.type, queued_at: props.request.queued_at } : null
  error.value = ''
  emit('busy', busy.value)
  load()
})
onMounted(() => {
  document.addEventListener('visibilitychange', visibility)
  window.addEventListener('pagehide', pagehide)
  window.addEventListener('pageshow', pageshow)
  load()
})
onBeforeUnmount(() => {
  disposed = true; cancelRead()
  document.removeEventListener('visibilitychange', visibility)
  window.removeEventListener('pagehide', pagehide)
  window.removeEventListener('pageshow', pageshow)
})
</script>

<template>
  <section class="backup-status" aria-label="备份任务状态" aria-live="polite">
    <div class="backup-status__heading">
      <h3>备份状态</h3>
      <el-tag size="small" :type="tagType" round>{{ labels[phase] }}</el-tag>
      <el-button size="small" :loading="loading" @click="load">刷新状态</el-button>
    </div>
    <p>{{ state?.message || descriptions[phase] }}</p>
    <dl v-if="typeLabel || times.length">
      <div v-if="typeLabel"><dt>类型</dt><dd>{{ typeLabel }}</dd></div>
      <div v-for="item in times" :key="item.label"><dt>{{ item.label }}</dt><dd>{{ item.value }}</dd></div>
    </dl>
    <p v-if="error" class="backup-status__error" role="alert">{{ error }}；{{ state ? '当前显示上次读取的状态' : '尚未取得有效状态' }}。</p>
  </section>
</template>

<style scoped>
.backup-status { border-top: 1px solid var(--el-border-color-light); padding-top: 20px; }
.backup-status__heading { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.backup-status__heading h3 { margin: 0; font-size: 15px; }
.backup-status__heading .el-button { margin-left: auto; }
.backup-status p { margin: 12px 0 0; color: var(--el-text-color-secondary); line-height: 1.6; overflow-wrap: anywhere; }
.backup-status dl { display: flex; flex-wrap: wrap; gap: 8px 24px; margin: 12px 0 0; font-size: 13px; }
.backup-status dl div { display: flex; gap: 8px; }
.backup-status dt { color: var(--el-text-color-secondary); }
.backup-status dd { margin: 0; overflow-wrap: anywhere; }
.backup-status .backup-status__error { color: var(--el-color-danger); }
@media (max-width: 600px) { .backup-status dl { flex-direction: column; gap: 8px; } }
</style>
