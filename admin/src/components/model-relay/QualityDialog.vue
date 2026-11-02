<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { buildDashboardApiUrl, requestDashboardApi, requestDashboardMutation } from '../../services/api'
import { relayProtocols, qualityStates, qualityAlert, relayCost, relayCostStates } from '../../utils/modelRelay'

const props = defineProps({ modelValue: Boolean, upstreamId: { type: [Number, String], default: null }, upstreams: { type: Array, default: () => [] } })
const emit = defineEmits(['update:modelValue', 'changed'])
const selected = ref(null), loading = ref(false), saving = ref(false), error = ref(''), page = ref(1)
const data = ref({ runs: { data: [], total: 0 } }), settings = ref(null), expanded = ref([])
const expandedRuns = ref([]), details = ref({})
let timer = null, generation = 0, session = 0, readController = null, disposed = false
let detailGeneration = 0
const detailControllers = new Map()
const upstream = computed(() => props.upstreams.find(u => String(u.id) === String(selected.value)))
const protocols = computed(() => (upstream.value?.protocols || ['openai']).filter(p => p !== 'responses_ws'))
const pending = computed(() => data.value.runs.data.some(r => ['queued', 'running'].includes(r.status)))
const statuses = { queued: '待执行', running: '检测中', complete: '完成', error: '未完成' }
const errors = { upstream_network: '连接中断或超时', upstream_rejected: '上游拒绝', invalid_response: '响应格式错误', gateway_unavailable: '中转进程未连接', gateway_or_protocol: '上游或协议不可用' }

function stopPolling() { clearTimeout(timer); timer = null }
function cancelRead() {
  generation++
  readController?.abort()
  readController = null
  loading.value = false
}
function currentSession(value) { return !disposed && props.modelValue && value === session }
function cancelDetails(clear = false) {
  detailGeneration++
  for (const controller of detailControllers.values()) controller.abort()
  detailControllers.clear()
  if (clear) { details.value = {}; expandedRuns.value = [] }
  else for (const [id, detail] of Object.entries(details.value)) {
    if (detail.state === 'loading') delete details.value[id]
  }
}
function runDetail(run) {
  if (typeof run.has_results !== 'boolean') return { state: 'loaded', results: Array.isArray(run.results) ? run.results : [] }
  if (!run.has_results) return { state: 'loaded', results: [] }
  return details.value[String(run.id)] || { state: 'idle', results: [] }
}
async function loadDetail(run, retry = false) {
  const id = String(run.id), previous = runDetail(run)
  if (disposed || !props.modelValue || document.hidden || saving.value || !expandedRuns.value.includes(id)) return
  if (!data.value.runs.data.some(item => String(item.id) === id) || !run.has_results) return
  if (previous.state !== 'idle' && !(retry && previous.state === 'error')) return
  const controller = new AbortController(), current = detailGeneration, upstreamId = selected.value
  detailControllers.set(id, controller)
  details.value[id] = { state: 'loading', results: [] }
  const isCurrent = () => current === detailGeneration && detailControllers.get(id) === controller && !controller.signal.aborted && !disposed && props.modelValue && !document.hidden
  try {
    const url = new URL(buildDashboardApiUrl('model-relay/quality-detail'), window.location.origin)
    url.searchParams.set('upstream_id', upstreamId); url.searchParams.set('run_id', id)
    const result = (await requestDashboardApi(url.toString(), { signal: controller.signal })).data
    if (!isCurrent()) return
    if (String(result?.id) !== id || String(result?.upstream_id) !== String(upstreamId) || !Array.isArray(result?.results) || !Object.hasOwn(statuses, result?.status)) {
      throw new Error('检测详情返回格式异常，请重试')
    }
    details.value[id] = { state: 'loaded', results: result.results, status: result.status }
  } catch (e) {
    if (isCurrent()) details.value[id] = { state: 'error', results: [], error: e.message || '详情加载失败，请重试' }
  } finally {
    if (detailControllers.get(id) === controller) detailControllers.delete(id)
  }
}
function loadExpandedDetails() {
  for (const run of data.value.runs.data) {
    if (expandedRuns.value.includes(String(run.id))) loadDetail(run)
  }
}
function reconcileDetails() {
  const ids = new Set(data.value.runs.data.map(run => String(run.id)))
  expandedRuns.value = expandedRuns.value.filter(id => ids.has(id))
  for (const [id, detail] of Object.entries(details.value)) {
    if (!ids.has(id) || (detail.state === 'loaded' && !['complete', 'error'].includes(detail.status))) {
      detailControllers.get(id)?.abort()
      detailControllers.delete(id)
      delete details.value[id]
    }
  }
  loadExpandedDetails()
}
function changePage() {
  cancelDetails(true)
  data.value.runs.data = []
  load(true)
}
function selectUpstream() {
  session++
  cancelDetails(true)
  settings.value = null
  data.value = { runs: { data: [], total: 0 } }
  expanded.value = []
  page.value = 1
  load()
}
async function load(keepForm = false) {
  stopPolling()
  cancelRead()
  if (disposed || !props.modelValue || !selected.value || saving.value || document.hidden) return
  const controller = new AbortController()
  readController = controller
  const current = ++generation
  loading.value = true; error.value = ''
  try {
    const url = new URL(buildDashboardApiUrl('model-relay/quality'), window.location.origin)
    url.searchParams.set('upstream_id', selected.value); url.searchParams.set('page', page.value); url.searchParams.set('summary', '1')
    const result = (await requestDashboardApi(url.toString(), { signal: controller.signal })).data
    if (current !== generation || disposed || !props.modelValue) return
    if (!Array.isArray(result?.runs?.data) || !result?.settings || typeof result.settings !== 'object' || Array.isArray(result.settings)) {
      throw new Error('检测记录返回格式异常，请重试')
    }
    data.value = result
    reconcileDetails()
    if (!keepForm || !settings.value) settings.value = structuredClone(result.settings)
  } catch (e) { if (current === generation && !controller.signal.aborted) error.value = e.message }
  finally {
    if (current === generation) {
      loading.value = false; readController = null
      if (!disposed && props.modelValue && !saving.value && pending.value && !document.hidden) timer = setTimeout(() => load(true), 4000)
    }
  }
}
async function mutate(path, body) { return requestDashboardMutation(buildDashboardApiUrl(`model-relay/${path}`), body) }
async function save(run = false) {
  if (saving.value || loading.value || !settings.value || !selected.value || disposed || !props.modelValue) return
  if (run && (pending.value || !upstream.value?.enabled)) return
  const origin = session, upstreamId = selected.value
  const snapshot = JSON.parse(JSON.stringify(settings.value))
  stopPolling(); cancelRead()
  saving.value = true
  let settingsSaved = false
  try {
    await mutate('quality-settings', { upstream_id: upstreamId, settings: snapshot })
    settingsSaved = true
    if (run) await mutate('quality-run', { upstream_id: upstreamId })
    if (!currentSession(origin)) return
    if (run && page.value !== 1) { cancelDetails(true); data.value.runs.data = []; page.value = 1 }
    emit('changed')
    ElMessage.success(run ? '已排队，通常一分钟内开始' : '检测设置已保存')
  } catch (e) {
    if (currentSession(origin)) ElMessage.error(settingsSaved && run ? `检测设置已保存；测试提交未确认，请先刷新记录。${e.message}` : e.message)
  } finally {
    saving.value = false
    if (currentSession(origin)) load(true)
  }
}
async function baseline(run) {
  if (saving.value || loading.value || disposed || !props.modelValue) return
  const origin = session, runId = run.id
  stopPolling(); cancelRead()
  saving.value = true
  try {
    await mutate('quality-baseline', { run_id: runId })
    if (currentSession(origin)) { emit('changed'); ElMessage.success('基线已更新') }
  } catch (e) { if (currentSession(origin)) ElMessage.error(e.message) }
  finally { saving.value = false; if (currentSession(origin)) load(true) }
}
watch(() => props.modelValue, open => {
  session++
  if (open) {
    const requested = props.upstreams.find(u => String(u.id) === String(props.upstreamId))
    if (requested) selected.value = requested.id
    else if (!props.upstreams.some(u => String(u.id) === String(selected.value))) selected.value = props.upstreams[0]?.id
    selectUpstream()
  }
  else { stopPolling(); cancelRead(); cancelDetails(true) }
}, { immediate: true })
function handleVisibilityChange() {
  if (document.hidden) { stopPolling(); cancelRead(); cancelDetails() }
  else if (props.modelValue && !saving.value) load(true)
}
onMounted(() => document.addEventListener('visibilitychange', handleVisibilityChange))
onBeforeUnmount(() => { disposed = true; session++; stopPolling(); cancelRead(); cancelDetails(true); document.removeEventListener('visibilitychange', handleVisibilityChange) })
</script>

<template>
  <el-dialog :model-value="modelValue" title="能力测试" width="880px" class="quality-dialog relay-dialog" append-to-body :close-on-click-modal="!saving" :close-on-press-escape="!saving" :show-close="!saving" @update:model-value="emit('update:modelValue', $event)">
    <div class="quality-toolbar"><el-select v-model="selected" :disabled="saving" filterable placeholder="选择上游" @change="selectUpstream"><el-option v-for="u in upstreams" :key="u.id" :value="u.id" :label="u.name" /></el-select><el-button text :disabled="saving || !selected" :loading="loading" @click="load(true)">刷新</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-skeleton v-if="loading && !settings" :rows="4" animated />
    <el-empty v-else-if="!selected" description="暂无可用上游" :image-size="54" />
    <template v-if="settings">
      <div class="quality-metrics">
        <div><span>检测状态</span><el-tag :type="qualityAlert(data.state) ? 'warning' : data.state === 'healthy' ? 'success' : 'info'">{{ qualityStates[data.state] || data.state }}</el-tag></div>
        <div><span>最近通过率</span><strong>{{ data.last_score == null ? '--' : `${data.last_score}%` }}</strong></div>
        <div><span>基线通过率</span><strong>{{ data.baseline_score == null ? '--' : `${data.baseline_score}%` }}</strong></div>
      </div>
      <el-collapse v-model="expanded" class="quality-settings"><el-collapse-item title="检测设置与题库" name="settings">
        <el-form label-position="top" :disabled="saving">
          <div class="quality-grid"><el-form-item label="检测模型"><el-select v-model="settings.model"><el-option v-for="m in upstream?.models || []" :key="m" :value="m" :label="m" /></el-select></el-form-item><el-form-item label="请求协议"><el-select v-model="settings.protocol"><el-option v-for="p in protocols" :key="p" :value="p" :label="relayProtocols[p]" /></el-select></el-form-item></div>
          <div class="quality-grid"><el-form-item label="定时检测"><el-switch v-model="settings.enabled" /><span v-if="data.next_run_at" class="quality-hint">下次 {{ data.next_run_at }} UTC</span></el-form-item><el-form-item label="间隔（分钟）"><el-input-number v-model="settings.interval_minutes" :min="15" :max="10080" :disabled="!settings.enabled" :controls="false" /></el-form-item></div>
          <div class="quality-grid quality-grid-three"><el-form-item label="最低通过率（%）"><el-input-number v-model="settings.threshold" :min="0" :max="100" :controls="false" /></el-form-item><el-form-item label="较基线下降（百分点）"><el-input-number v-model="settings.drop_threshold" :min="1" :max="100" :controls="false" /></el-form-item><el-form-item label="连续异常次数"><el-input-number v-model="settings.consecutive" :min="1" :max="10" :controls="false" /></el-form-item></div>
          <div class="quality-grid"><el-form-item label="单题最大输出 Token"><el-input-number v-model="settings.max_output" :min="16" :max="1024" :controls="false" /></el-form-item><el-form-item label="Telegram 提醒"><el-switch v-model="settings.notify" /></el-form-item></div>
          <div class="quality-toolbar"><strong>题库</strong><el-button text :disabled="settings.cases.length >= 8" @click="settings.cases.push({ name: '', prompt: '', expected: '' })">添加题目</el-button></div>
          <p v-if="!settings.cases.length" class="quality-note">默认使用 3 道随机数字的糖果收支、逆推与多轮条件题。可添加自己的题目替换默认题库。</p>
          <div v-for="(item, index) in settings.cases" :key="index" class="quality-case-editor"><div class="quality-toolbar"><el-input v-model="item.name" placeholder="题目名称" :aria-label="`题目 ${index + 1} 名称`" maxlength="60" /><el-button text type="danger" @click="settings.cases.splice(index, 1)">移除</el-button></div><el-input v-model="item.prompt" type="textarea" :rows="2" placeholder="题干" :aria-label="`题目 ${index + 1} 题干`" maxlength="2000" /><el-input v-model="item.expected" placeholder="标准答案（精确匹配）" :aria-label="`题目 ${index + 1} 标准答案`" maxlength="200" /></div>
        </el-form>
      </el-collapse-item></el-collapse>
      <div class="quality-history-title"><strong>测试记录</strong><span v-if="pending">排队或执行中，将自动刷新</span></div>
      <el-collapse v-model="expandedRuns" class="quality-runs" @change="loadExpandedDetails">
        <el-collapse-item v-for="run in data.runs.data" :key="run.id" :name="String(run.id)">
          <template #title><div class="quality-run-title"><span>#{{ run.id }} · {{ run.created_at }} UTC</span><el-tag size="small" :type="run.status === 'complete' ? 'success' : run.status === 'error' ? 'warning' : 'info'">{{ statuses[run.status] }}</el-tag><strong>{{ run.score == null ? '--' : `${run.score}%` }}</strong></div></template>
          <div class="quality-run-meta"><span>{{ run.model }} · {{ relayProtocols[run.protocol] }} · {{ run.source === 'manual' ? '手动' : '定时' }} · {{ run.tokens == null ? '用量未完整确认' : `${run.tokens.toLocaleString()} Token` }} · {{ relayCost(run.cost) }}（{{ relayCostStates[run.cost_status] || '未配置单价' }}）</span><el-button v-if="run.status === 'complete'" text :disabled="saving" @click="baseline(run)">设为基线</el-button></div>
          <p v-if="runDetail(run).state === 'loading'" class="quality-note" role="status">正在加载检测详情…</p>
          <div v-else-if="runDetail(run).state === 'error'" class="quality-detail-error" role="alert"><span>{{ runDetail(run).error }}</span><el-button text :disabled="saving" @click="loadDetail(run, true)">重试</el-button></div>
          <article v-for="(item, index) in runDetail(run).results" :key="index" class="quality-result"><header><strong>{{ item.name }}</strong><el-tag size="small" :type="item.error_code ? 'warning' : item.passed ? 'success' : 'danger'">{{ item.error_code ? '请求异常' : item.passed ? '通过' : '未通过' }}</el-tag></header><p>{{ item.prompt }}</p><div class="quality-answer"><span>标准答案 <strong>{{ item.expected }}</strong></span><span>实际答案 <strong>{{ item.answer ?? (item.error_code ? '未返回' : '格式不符合') }}</strong></span></div><div class="quality-result-stats"><span>{{ item.duration_ms == null ? '耗时未知' : `${(item.duration_ms / 1000).toFixed(1)}s` }}</span><span>{{ item.tokens == null ? 'Token 未确认' : `${item.tokens} Token` }}</span><span>缓存读 {{ item.cache_read_tokens ?? '?' }}</span><span>缓存写 {{ item.cache_write_tokens ?? '?' }}</span><span>{{ relayCost(item.cost) }}</span></div><p v-if="item.error_code" class="quality-error">{{ errors[item.error_code] || item.error_code }}</p><details v-if="item.actual" class="quality-raw"><summary>原始响应</summary><pre>{{ item.actual }}</pre></details></article>
          <p v-if="runDetail(run).state === 'loaded' && !runDetail(run).results.length" class="quality-note">{{ run.status === 'queued' ? '等待调度器执行' : run.status === 'running' ? '正在请求上游' : '本次检测暂无结果' }}</p>
        </el-collapse-item>
      </el-collapse>
      <p v-if="!data.runs.data.length" class="quality-empty">暂无测试记录</p>
      <el-pagination v-if="data.runs.total > 10" v-model:current-page="page" :disabled="saving" :page-size="10" :total="data.runs.total" layout="prev, pager, next" @current-change="changePage" />
      <p class="quality-note">每轮 {{ settings.cases.length || 3 }} 次独立 API 请求，产生供应商用量，单独记录、不扣机器额度。少量测试题只反映本次表现；超时不计为答错，也不会自动停用上游。</p>
    </template>
    <template #footer><el-button :disabled="saving" @click="emit('update:modelValue', false)">关闭</el-button><el-button :disabled="!settings || loading" :loading="saving" @click="save(false)">保存设置</el-button><el-button type="primary" :disabled="!settings || loading || pending || !upstream?.enabled" :loading="saving" @click="save(true)">开始测试</el-button></template>
  </el-dialog>
</template>

<style scoped>
.quality-toolbar { display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px; }
.quality-toolbar > .el-select { width:300px;max-width:100%; }
.quality-metrics { display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;padding:8px 0 24px; }
.quality-metrics > div { display:flex;flex-direction:column;align-items:flex-start;gap:10px; }.quality-metrics span,.quality-hint,.quality-history-title > span { font-size:12px;color:var(--muted); }.quality-metrics strong { font-size:25px;font-weight:500; }
.quality-grid { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 20px; }.quality-grid-three { grid-template-columns:repeat(3,minmax(0,1fr)); }.quality-grid .el-input-number,.quality-grid .el-select { width:100%; }
.quality-hint { margin-left:10px; }.quality-case-editor { display:grid;gap:10px;padding:16px 0;border-top:1px solid var(--line); }.quality-case-editor .quality-toolbar { margin:0; }
.quality-history-title { display:flex;justify-content:space-between;align-items:center;gap:12px;padding:24px 0 12px; }
.quality-run-title { display:flex;align-items:center;gap:12px;width:100%;min-width:0; }.quality-run-title > span:first-child { flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }.quality-run-title strong { min-width:42px;font-weight:500; }
.quality-run-meta { display:flex;align-items:center;justify-content:space-between;gap:8px;color:var(--muted);font-size:12px; }.quality-result { padding:14px 0;border-top:1px solid var(--line); }.quality-result header,.quality-answer { display:flex;align-items:center;justify-content:space-between;gap:12px; }.quality-result p { margin:10px 0;line-height:1.7; }.quality-answer { justify-content:flex-start;color:var(--muted);font-size:12px;flex-wrap:wrap; }.quality-result pre { white-space:pre-wrap;overflow-wrap:anywhere;background:var(--panel-soft);padding:10px;border-radius:var(--radius-sm); }
.quality-note,.quality-empty { color:var(--muted);font-size:12px;line-height:1.7; }.quality-empty { text-align:center;padding:26px 0; }
.quality-detail-error { display:flex;align-items:center;justify-content:space-between;gap:12px;color:var(--primary);font-size:12px; }
.quality-answer { display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px; }.quality-answer > span { padding:10px 12px;border-radius:var(--radius-sm);background:var(--bg-soft); }.quality-answer strong { display:block;color:var(--text);font-weight:500;font-size:15px;margin-top:4px;overflow-wrap:anywhere; }
.quality-result-stats { display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12px;color:var(--muted);line-height:1.7; }.quality-raw { margin-top:10px;color:var(--muted);font-size:12px; }.quality-raw summary { cursor:pointer;width:fit-content; }.quality-error { color:var(--primary);font-size:12px; }
@media(max-width:600px) { .quality-grid-three { grid-template-columns:repeat(2,minmax(0,1fr)); }.quality-metrics { gap:12px; }.quality-run-title { gap:6px; }.quality-run-meta { align-items:flex-start;flex-direction:column; } }
@media(max-width:420px) { .quality-grid { grid-template-columns:1fr; }.quality-run-title > span:first-child { font-size:11px; } }
</style>
<style>
.quality-dialog.el-dialog { margin: min(6vh, 48px) auto; max-height: calc(100dvh - min(12vh, 96px)); display:flex;flex-direction:column; }
.quality-dialog > .el-dialog__body { min-height:0;overflow-y:auto;overscroll-behavior:contain; }
.quality-dialog > .el-dialog__header,.quality-dialog > .el-dialog__footer { flex-shrink:0; }
.quality-dialog > .el-dialog__footer { border-top:1px solid var(--line);padding-top:16px;display:flex;justify-content:flex-end;gap:8px; }
.quality-dialog > .el-dialog__footer .el-button { margin:0; }
@media(max-width:600px) { .quality-dialog > .el-dialog__footer .el-button { padding-inline:12px; } }
</style>
