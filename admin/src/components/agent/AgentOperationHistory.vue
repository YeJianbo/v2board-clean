<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { ArrowLeft, RefreshCw, Undo2 } from 'lucide-vue-next'
import { ElMessage } from 'element-plus'
import AgentChangeList from './AgentChangeList.vue'
import { buildDashboardApiUrl, requestDashboardApi, requestDashboardMutation } from '../../services/api'

const props = defineProps({ selectedId: { type: Number, default: null }, active: { type: Boolean, default: true } })
const emit = defineEmits(['undone'])
const list = ref([]), total = ref(0), page = ref(1), loading = ref(false), error = ref('')
const detail = ref(null), undo = ref(null), working = ref(false)
const requestedId = ref(props.selectedId), listLoaded = ref(false)
let listPage = null
let readController = null, disposed = false
const labels = { preview_machine_provision: '机器上线配置', nodes_select: '精确筛选节点', preview_node_batch: '按目标集合批量编辑', node_health: '节点健康检查', node_onboarding_check: '节点对接预检', preview_node_visibility: '显示/隐藏节点', preview_node_sort: '节点排序', preview_node_rename: '批量重命名', preview_node_ip: '修改入口 IP', preview_entry_ip: '按机器改入口 IP' }
const date = timestamp => timestamp ? new Date(timestamp * 1000).toLocaleString('zh-CN', { hour12: false }) : ''
function cancelRead() {
  readController?.abort()
  readController = null
  loading.value = false
}
async function readCurrent() {
  cancelRead()
  if (disposed || !props.active || document.hidden) return false
  const controller = new AbortController()
  readController = controller
  const id = requestedId.value, requestedPage = page.value
  const current = () => !disposed && props.active && !document.hidden && !controller.signal.aborted && readController === controller
  loading.value = true; error.value = ''
  try {
    const url = new URL(buildDashboardApiUrl(id === null ? 'agent/operations' : 'agent/operation'), window.location.origin)
    url.searchParams.set(id === null ? 'page' : 'id', id === null ? requestedPage : id)
    const result = (await requestDashboardApi(url.toString(), { signal: controller.signal })).data
    if (!current()) return false
    if (id === null) { list.value = result.data; total.value = result.total; listLoaded.value = true; listPage = requestedPage }
    else detail.value = result
    return true
  } catch (e) {
    if (current()) error.value = e.message || '操作记录加载失败，请重试'
    return false
  } finally {
    if (readController === controller) { readController = null; loading.value = false }
  }
}
function load() {
  if (working.value || disposed) return
  requestedId.value = null; detail.value = null; undo.value = null
  if (listPage !== page.value) { list.value = []; listLoaded.value = false }
  return readCurrent()
}
function open(id) {
  if (working.value || disposed) return
  if (requestedId.value !== id) detail.value = null
  requestedId.value = id; undo.value = null
  return readCurrent()
}
function refresh() {
  if (working.value || disposed) return
  undo.value = null
  return readCurrent()
}
async function previewUndo() {
  if (working.value || loading.value || disposed || !detail.value || detail.value.status === 'undone') return
  const id = detail.value.operation_id
  cancelRead()
  working.value = true; error.value = ''
  try {
    const result = (await requestDashboardMutation(buildDashboardApiUrl('agent/undo-preview'), { id })).data
    if (!disposed && requestedId.value === id) undo.value = result
  } catch (e) { if (!disposed && requestedId.value === id) error.value = e.message }
  finally { if (!disposed) working.value = false }
}
async function confirmUndo() {
  if (working.value || loading.value || disposed || !detail.value || !undo.value || undo.value.operation_id !== detail.value.operation_id) return
  const id = detail.value.operation_id, token = undo.value.token
  cancelRead()
  working.value = true; error.value = ''
  let result
  try {
    result = (await requestDashboardMutation(buildDashboardApiUrl('agent/undo'), { token })).data
  } catch (e) {
    if (!disposed && requestedId.value === id) error.value = e.message
    return
  } finally { if (!disposed) working.value = false }
  emit('undone', id)
  ElMessage.success('已撤销本次操作')
  if (disposed || requestedId.value !== id) return
  undo.value = null
  detail.value = { ...detail.value, ...result }
  if (!await readCurrent() && !disposed && requestedId.value === id && error.value) {
    ElMessage.warning('撤销已完成，但详情刷新失败，请稍后刷新')
  }
}
function syncActivity() {
  if (!props.active || document.hidden) cancelRead()
  else readCurrent()
}
watch(() => props.active, syncActivity)
watch(() => props.selectedId, id => {
  requestedId.value = id; detail.value = null; undo.value = null
  syncActivity()
})
onMounted(() => { document.addEventListener('visibilitychange', syncActivity); syncActivity() })
onUnmounted(() => { disposed = true; cancelRead(); document.removeEventListener('visibilitychange', syncActivity) })
</script>
<template>
  <section class="agent-operations" :aria-busy="loading || working">
    <div class="operation-toolbar">
      <el-button v-if="requestedId !== null" text :disabled="working" @click="load"><ArrowLeft :size="15" />操作列表</el-button>
      <span v-else>我的操作 · {{ total }} 条</span>
      <el-button text circle aria-label="刷新操作记录" :loading="loading" :disabled="working" @click="refresh"><RefreshCw v-if="!loading" :size="15" /></el-button>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <div v-loading="loading" class="operation-content">
      <template v-if="detail">
        <div class="operation-heading"><strong>{{ labels[detail.tool] || detail.tool }}</strong><el-tag size="small" :type="detail.status === 'undone' ? 'info' : 'success'">{{ detail.status === 'undone' ? '已撤销' : '已执行' }}</el-tag></div>
        <p class="operation-meta">#{{ detail.operation_id }} · {{ date(detail.created_at) }} · {{ detail.updated }} 个节点</p>
        <template v-if="undo">
          <p class="operation-note">以下字段将恢复为操作前的值。确认时会再次检查冲突。</p>
          <AgentChangeList :rows="undo.rows" />
          <div class="operation-actions"><el-button :disabled="working" @click="undo = null">取消</el-button><el-button type="primary" :loading="working" :disabled="loading" @click="confirmUndo">确认撤销</el-button></div>
        </template>
        <template v-else>
          <AgentChangeList :rows="detail.rows" />
          <p v-if="detail.status === 'undone' && detail.undone_at" class="operation-meta">撤销时间 {{ date(detail.undone_at) }}</p>
          <div v-else-if="detail.status !== 'undone' && detail.tool !== 'preview_machine_provision'" class="operation-actions"><el-button :loading="working" :disabled="loading" @click="previewUndo"><Undo2 :size="14" />撤销此操作</el-button></div>
        </template>
      </template>
      <template v-else-if="requestedId === null">
        <p v-if="!list.length && !loading && !error && listLoaded" class="operation-empty">暂无操作记录</p>
        <button v-for="record in list" :key="record.id" type="button" class="operation-row" :disabled="working" @click="open(record.id)">
          <span><strong>{{ labels[record.tool] || record.tool }}</strong><small>{{ date(record.created_at) }}</small></span>
          <span><span>{{ record.node_count }} 个节点</span><small :class="{ undone: record.status === 'undone' }">{{ record.status === 'undone' ? '已撤销' : '已执行' }}</small></span>
        </button>
      </template>
    </div>
    <el-pagination v-if="requestedId === null && total > 15" v-model:current-page="page" :disabled="working" :page-size="15" :total="total" layout="prev, pager, next" :pager-count="5" size="small" @current-change="load" />
  </section>
</template>
<style scoped>
.agent-operations { flex:1;min-height:0;display:flex;flex-direction:column;padding:12px 16px; }
.operation-toolbar { display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;color:var(--muted);font-size:12px; }
.operation-content { flex:1;min-height:0;overflow:auto; }
.operation-row { width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;text-align:left;padding:15px 0;border:0;border-bottom:1px solid var(--line);color:var(--text);background:none;cursor:pointer; }
.operation-row:hover { color:var(--primary); }.operation-row strong { font-size:13px;font-weight:500; }.operation-row span + span { font-size:12px;text-align:right; }.operation-row small { display:block;margin-top:6px;color:var(--muted);font-size:11px; }.operation-row .undone { color:var(--primary); }
.operation-row:focus-visible { outline:2px solid var(--primary);outline-offset:-2px;border-radius:var(--radius-md); }
.operation-empty { padding:40px 0;text-align:center;font-size:13px;color:var(--muted); }
.operation-heading { display:flex;align-items:center;justify-content:space-between;padding:12px 0 0; }.operation-heading strong { font-size:14px;font-weight:500; }
.operation-meta,.operation-note { font-size:12px;line-height:1.7;color:var(--muted); }.operation-note { color:var(--primary); }
.operation-actions { display:flex;justify-content:flex-end;gap:8px;padding:16px 0; }.operation-actions :deep(.el-button) { gap:6px; }
.el-pagination { flex:none;justify-content:center;padding:12px 0 0; }
.agent-operations :deep(.el-alert) { flex:none;margin-bottom:10px; }
</style>
