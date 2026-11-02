<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowUpRight, Copy, Plus, RefreshCw, Search, Settings2 } from 'lucide-vue-next'
import SectionCard from '../components/common/SectionCard.vue'
import { relayProtocols, qualityStates, qualityAlert, relayCost, relayCostStates, relayNeedsAttention, relayPeriodCost, filterRelayKeys } from '../utils/modelRelay'
import { buildDashboardApiUrl, requestDashboardApi, requestDashboardMutation, requestDashboardReadPost } from '../services/api'
import { createSequence } from '../utils/sequence'
import { copyText } from '../utils/clipboard'
const data = ref({ keys: [], upstreams: [], machines: [], api_path: '/api/model/v1' })
const loading = ref(true), saving = ref(false), error = ref(''), search = ref('')
const statusFilter = ref('all')
const serviceReady = ref(false)
const upstreamOpen = ref(false), keyOpen = ref(false), secret = ref(''), upstream = ref({}), machineKey = ref({})
const logsOpen = ref(false), logsLoading = ref(false), logs = ref({ data: [], total: 0 }), logMachine = ref(null), logPage = ref(1)
const logsError = ref('')
const mutationError = ref('')
const statusSequence = createSequence(), logsSequence = createSequence()
const upstreamFormSequence = createSequence(), keyFormSequence = createSequence(), testSequence = createSequence()
let statusController = null, logsController = null, disposed = false, logsScope = ''
let testController = null
const testing = ref(false), testResult = ref(null)
const QualityDialog = defineAsyncComponent(() => import('../components/model-relay/QualityDialog.vue'))
const qualityOpen = ref(false), qualityReady = ref(false), qualityUpstreamId = ref(null), endpointProtocol = ref('openai')
function openQuality(upstreamId = null) {
  if (disposed || saving.value) return
  qualityUpstreamId.value = upstreamId
  qualityReady.value = true
  qualityOpen.value = true
}
const protocolOptions = Object.entries(relayProtocols)
const priceFields = [['input', '普通输入'], ['cache_read', '缓存读取'], ['cache_write', '缓存写入'], ['output', '输出']]
const protocolEntries = { openai: '/v1', responses: '/v1', anthropic: '/v1', gemini: '/v1beta', responses_ws: '/v1/responses' }
const errorLabels = { cloudflare_blocked: '上游 Cloudflare 拦截，请联系供应商放行', upstream_network: '上游网络连接失败或超时', upstream_rejected: '上游拒绝请求，请核对权限和额度', invalid_response: '上游响应格式不正确', stream_interrupted: '上游流式响应中断' }
const statusLabels = { pending: '处理中', complete: '完成', rejected: '上游拒绝', interrupted: '已中断', estimated: '估算扣额', unknown: '待核实' }
const periods = { day: '每日', month: '每月', lifetime: '累计' }
const endpoint = computed(() => `${endpointProtocol.value === 'responses_ws' ? window.location.origin.replace(/^http/, 'ws') : window.location.origin}${data.value.api_path.replace(/\/v1$/, '')}${protocolEntries[endpointProtocol.value]}`)
const rows = computed(() => filterRelayKeys(data.value.keys, search.value, statusFilter.value))
const metrics = computed(() => [
  { label: '已启用机器', value: data.value.keys.filter(k => k.enabled).length, action: () => { statusFilter.value = 'enabled' } },
  { label: '当前周期请求', value: data.value.keys.reduce((sum, k) => sum + Number(k.requests || 0), 0), action: () => viewLogs() },
  { label: '需要关注', value: data.value.keys.filter(relayNeedsAttention).length, action: () => { statusFilter.value = 'attention' } },
])
const modelOptions = computed(() => data.value.upstreams.find(u => u.id === machineKey.value.upstream_id)?.models || [])
const number = value => Number(value || 0).toLocaleString('zh-CN')
const upstreamName = id => data.value.upstreams.find(u => u.id === id)?.name || '已移除'
const machineName = id => data.value.machines.find(m => m.id === id)?.name || `#${id}`
const percentage = row => Math.min(100, Math.round((Number(row.used) + Number(row.reserved)) / Number(row.quota) * 100))
async function load() {
  if (disposed || saving.value) return
  statusController?.abort()
  const controller = new AbortController()
  statusController = controller
  const revision = statusSequence.next()
  loading.value = true; error.value = ''
  try {
    const result = (await requestDashboardApi(buildDashboardApiUrl('model-relay/status'), { signal: controller.signal })).data
    if (disposed || !statusSequence.isCurrent(revision)) return
    if (!Array.isArray(result?.keys) || !Array.isArray(result?.upstreams) || !Array.isArray(result?.machines)) {
      throw new Error('模型分发数据返回格式异常，请重试')
    }
    serviceReady.value = true
    data.value = result
  } catch (e) {
    if (!disposed && !controller.signal.aborted && statusSequence.isCurrent(revision)) error.value = e.message
  } finally {
    if (!disposed && statusSequence.isCurrent(revision)) loading.value = false
    if (statusController === controller) statusController = null
  }
}
async function mutate(path, body) {
  return (await requestDashboardMutation(buildDashboardApiUrl(`model-relay/${path}`), body)).data
}
function cancelTest() {
  testSequence.invalidate()
  testController?.abort()
  testController = null
  testing.value = false
}
function beginWrite() {
  if (disposed || saving.value || secret.value) return false
  saving.value = true
  mutationError.value = ''
  statusSequence.invalidate()
  statusController?.abort()
  statusController = null
  loading.value = false
  cancelTest()
  testResult.value = null
  return true
}
function reportWriteError(e) {
  if (disposed) return
  if (e.httpStatus >= 400 && e.httpStatus < 500) { ElMessage.error(e.message); return }
  mutationError.value = `提交结果未确认，请先刷新列表核对，不要直接重复提交。${e.message || ''}`
  ElMessage.warning(mutationError.value)
}
function editUpstream(row) {
  if (disposed || saving.value || secret.value) return
  upstreamFormSequence.next()
  cancelTest()
  testResult.value = null
  upstream.value = row ? { ...row, api_key: '', models: [...row.models], enabled: !!row.enabled, protocols: [...(row.protocols || ['openai'])], protocol_urls: { ...row.protocol_urls }, pricing: Object.fromEntries(Object.entries(row.pricing || {}).map(([model, rates]) => [model, Object.fromEntries(Object.entries(rates).map(([field, rate]) => [field, Number(rate)]))])) } : { name: '', base_url: '', api_key: '', models: [], enabled: true, protocols: [], protocol_urls: {}, pricing: {} }
}
function openUpstream() {
  if (disposed || saving.value || secret.value) return
  editUpstream()
  upstreamOpen.value = true
}
function togglePrice(model, enabled) { if (saving.value) return; if (enabled) upstream.value.pricing[model] = { input: 0, output: 0, cache_read: 0, cache_write: 0 }; else delete upstream.value.pricing[model] }
async function testUpstream() {
  if (disposed || saving.value || testing.value || !upstreamOpen.value || !upstream.value.id) return
  const id = upstream.value.id, revision = testSequence.next()
  const controller = new AbortController()
  testController = controller
  testing.value = true; testResult.value = null
  try {
    const result = (await requestDashboardReadPost(buildDashboardApiUrl('model-relay/test'), { id }, { signal: controller.signal })).data
    if (disposed || !upstreamOpen.value || !testSequence.isCurrent(revision)) return
    if (typeof result?.ok !== 'boolean') throw new Error('连接测试返回格式异常，请重试')
    testResult.value = result
  } catch (e) {
    if (!disposed && !controller.signal.aborted && testSequence.isCurrent(revision)) ElMessage.error(e.message)
  } finally {
    if (!disposed && testSequence.isCurrent(revision)) testing.value = false
    if (testController === controller) testController = null
  }
}
async function saveUpstream() {
  if (!upstreamOpen.value || !beginWrite()) return
  const revision = upstreamFormSequence.current()
  let changed = false
  try {
    const snapshot = JSON.parse(JSON.stringify(upstream.value))
    const result = await mutate('upstream', snapshot)
    changed = true
    if (disposed) return
    if (!Number.isInteger(Number(result?.id)) || Number(result.id) <= 0) {
      mutationError.value = '上游提交已返回，但未收到记录编号，请先刷新列表核对，不要重复新建。'
      ElMessage.warning(mutationError.value)
      return
    }
    if (upstreamOpen.value && upstreamFormSequence.isCurrent(revision)) upstream.value = { ...snapshot, id: Number(result.id), api_key: '' }
    ElMessage.success('上游已保存')
  } catch (e) { reportWriteError(e) }
  finally { saving.value = false; if (changed && !disposed) load() }
}
function editKey(row) {
  if (disposed || saving.value || secret.value) return
  keyFormSequence.next()
  machineKey.value = row ? { ...row, enabled: !!row.enabled, models: [...row.models] } : { machine_id: null, upstream_id: data.value.upstreams[0]?.id, models: [], quota: 1000000, period: 'month', max_output: 8192, enabled: true }
  keyOpen.value = true
}
async function saveKey() {
  if (!keyOpen.value || !beginWrite()) return
  const revision = keyFormSequence.current()
  let changed = false
  try {
    const snapshot = JSON.parse(JSON.stringify(machineKey.value))
    const result = await mutate('key', snapshot)
    changed = true
    if (disposed) return
    if (!Number.isInteger(Number(result?.id)) || Number(result.id) <= 0) {
      mutationError.value = '机器配置提交已返回，但未收到记录编号，请先刷新列表核对。'
      ElMessage.warning(mutationError.value)
      return
    }
    if (keyOpen.value && keyFormSequence.isCurrent(revision)) machineKey.value = { ...snapshot, id: Number(result.id) }
    if (typeof result?.key === 'string' && result.key) secret.value = result.key
    else if (!snapshot.id) {
      mutationError.value = '机器接入已返回，但未收到新 Key，请先刷新列表核对，不要重复接入。'
      ElMessage.warning(mutationError.value)
      return
    } else ElMessage.success('机器额度已保存')
    if (keyOpen.value && keyFormSequence.isCurrent(revision)) keyOpen.value = false
  } catch (e) { reportWriteError(e) }
  finally { saving.value = false; if (changed && !disposed) load() }
}
async function keyAction(row, action) {
  if (!beginWrite()) return
  const id = row.id
  let changed = false
  try {
    await ElMessageBox.confirm(action === 'rotate' ? '旧 Key 将立即失效，机器需换用新 Key。已用额度不会清零。' : '停用后此机器的新模型请求会被拒绝。', action === 'rotate' ? '重置机器 Key' : '停用机器 Key', { type: 'warning', confirmButtonText: '确认', cancelButtonText: '取消' })
    if (disposed) return
    const result = await mutate('key-action', { id, action })
    changed = true
    if (disposed) return
    if (!result || typeof result !== 'object' || !Object.hasOwn(result, 'key')) {
      mutationError.value = 'Key 操作提交已返回，但返回格式异常，请先核对状态，不要直接重复操作。'
      ElMessage.warning(mutationError.value)
      return
    }
    if (typeof result?.key === 'string' && result.key) secret.value = result.key
    else if (action === 'rotate') {
      mutationError.value = '重置操作已返回，但未收到新 Key，请核对服务器记录，不要直接再次重置。'
      ElMessage.warning(mutationError.value)
    } else ElMessage.success('机器 Key 已停用')
  } catch (e) { if (e !== 'cancel' && e !== 'close') reportWriteError(e) }
  finally { saving.value = false; if (changed && !disposed) load() }
}
async function copy(value) {
  if (disposed) return
  try { await copyText(value); if (!disposed) ElMessage.success('已复制') }
  catch { if (!disposed) ElMessage.error('复制失败，请手动选择文本') }
}
async function loadLogs() {
  if (disposed || !logsOpen.value) return
  logsController?.abort()
  const controller = new AbortController()
  logsController = controller
  const revision = logsSequence.next()
  const scope = `${logMachine.value || 'all'}:${logPage.value}`
  if (scope !== logsScope) logs.value = { data: [], total: logs.value.total }
  logsScope = scope
  logsLoading.value = true
  logsError.value = ''
  try {
    const url = new URL(buildDashboardApiUrl('model-relay/usage'), window.location.origin)
    url.searchParams.set('summary', '1')
    url.searchParams.set('page', logPage.value)
    if (logMachine.value) url.searchParams.set('machine_id', logMachine.value)
    const result = (await requestDashboardApi(url.toString(), { signal: controller.signal })).data
    if (disposed || !logsOpen.value || !logsSequence.isCurrent(revision)) return
    if (!Array.isArray(result?.data) || !Number.isFinite(Number(result.total)) || Number(result.total) < 0) {
      throw new Error('请求记录返回格式异常，请重试')
    }
    logs.value = { ...result, total: Number(result.total) }
  } catch (e) {
    if (!disposed && !controller.signal.aborted && logsSequence.isCurrent(revision)) logsError.value = e.message
  } finally {
    if (!disposed && logsSequence.isCurrent(revision)) logsLoading.value = false
    if (logsController === controller) logsController = null
  }
}
function viewLogs(row) { logMachine.value = row?.machine_id || null; logPage.value = 1; logsOpen.value = true; loadLogs() }
watch(logsOpen, open => {
  if (!open) { logsSequence.invalidate(); logsController?.abort(); logsController = null; logsLoading.value = false }
})
watch(upstreamOpen, open => { if (!open) { upstreamFormSequence.invalidate(); cancelTest(); testResult.value = null } })
watch(keyOpen, open => { if (!open) keyFormSequence.invalidate() })
onMounted(load)
onBeforeUnmount(() => {
  disposed = true
  statusSequence.invalidate(); logsSequence.invalidate()
  upstreamFormSequence.invalidate(); keyFormSequence.invalidate(); cancelTest()
  statusController?.abort(); logsController?.abort()
})
</script>
<template>
  <section class="page-stack relay-page">
    <SectionCard v-if="loading && !serviceReady"><el-skeleton :rows="4" animated /></SectionCard>
    <el-alert v-if="error" :title="error" type="error" :closable="false"><el-button text :disabled="saving" :loading="loading" @click="load">重试</el-button></el-alert>
    <el-alert v-if="mutationError" :title="mutationError" type="warning" :closable="false"><el-button text :disabled="saving" :loading="loading" @click="load">刷新核对</el-button></el-alert>
    <el-alert v-if="!loading && !error && !serviceReady" title="模型分发服务尚未部署" type="info" :closable="false" />
    <SectionCard v-if="serviceReady">
      <div class="relay-health"><span :class="['relay-status', { offline: !data.gateway?.online }]"><i />{{ data.gateway?.online ? '中转进程在线' : '中转进程未连接' }}</span></div>
      <div class="relay-summary"><button v-for="metric in metrics" :key="metric.label" type="button" :aria-label="`查看${metric.label}`" @click="metric.action"><span>{{ metric.label }}<ArrowUpRight :size="14" /></span><strong>{{ number(metric.value) }}</strong></button></div>
      <div class="relay-endpoint"><el-select v-model="endpointProtocol" aria-label="查看协议入口" class="relay-endpoint-select"><el-option v-for="[value, label] in protocolOptions" :key="value" :value="value" :label="label" /></el-select><div class="relay-endpoint-address"><code>{{ endpoint }}</code><el-button text circle aria-label="复制 API 地址" title="复制 API 地址" @click="copy(endpoint)"><Copy :size="15" /></el-button></div></div>
      <div v-if="data.upstreams.some(u => qualityAlert(u.quality_state))" class="relay-quality-notices"><el-button v-for="u in data.upstreams.filter(u => qualityAlert(u.quality_state))" :key="u.id" text type="warning" :disabled="saving" @click="openQuality(u.id)">{{ u.name }} · {{ qualityStates[u.quality_state] }}</el-button></div>
    </SectionCard>
    <SectionCard v-if="serviceReady" title="机器额度">
      <template #actions><el-button :disabled="saving" @click="openUpstream"><Settings2 :size="15" />上游设置</el-button><el-button type="primary" :disabled="saving || !data.upstreams.length" @click="editKey()"><Plus :size="15" />接入机器</el-button></template>
      <div class="relay-toolbar"><div class="relay-filters"><el-input v-model="search" clearable placeholder="搜索机器名称 / 编号" aria-label="搜索接入机器"><template #prefix><Search :size="15" /></template></el-input><el-select v-model="statusFilter" aria-label="筛选机器状态"><el-option label="全部状态" value="all" /><el-option label="已启用" value="enabled" /><el-option label="已停用" value="disabled" /><el-option label="需要关注" value="attention" /></el-select></div><div class="relay-toolbar-actions"><el-button text :disabled="saving || !data.upstreams.length" @click="openQuality()">能力测试</el-button><el-button text @click="viewLogs()">请求记录</el-button><el-button text circle :disabled="saving" :loading="loading" aria-label="刷新模型分发" title="刷新" @click="load"><RefreshCw v-if="!loading" :size="16" /></el-button></div></div>
      <el-table v-loading="loading" :data="rows" class="relay-desktop-table" row-key="id" :empty-text="data.keys.length ? '没有匹配的机器' : '暂无接入机器'">
        <el-table-column label="机器" min-width="155"><template #default="{ row }"><div class="relay-machine"><span :class="['relay-dot', { 'is-enabled': row.enabled }]" :title="row.enabled ? '已启用' : '已停用'" /><strong>{{ row.machine_name || '机器已删除' }}</strong></div><span class="relay-secondary">#{{ row.machine_id }} · {{ row.enabled ? '已启用' : '已停用' }}</span></template></el-table-column>
        <el-table-column label="上游 / 模型" min-width="165"><template #default="{ row }">{{ upstreamName(row.upstream_id) }}<div class="relay-secondary" :title="row.models.join('、')">{{ row.models.join('、') }}</div></template></el-table-column>
        <el-table-column label="周期额度 · Token" min-width="220"><template #default="{ row }"><div class="relay-quota"><span>{{ number(row.used) }}</span><span>{{ number(row.quota) }} · {{ periods[row.period] }}</span></div><el-progress :percentage="percentage(row)" :show-text="false" :stroke-width="5" :status="percentage(row) >= 90 ? 'warning' : undefined" /><div class="relay-secondary">剩余 {{ number(row.remaining) }}<template v-if="row.reserved"> · 预留 {{ number(row.reserved) }}</template></div></template></el-table-column>
        <el-table-column label="请求" width="95"><template #default="{ row }"><el-button link @click="viewLogs(row)">{{ number(row.requests) }}</el-button><div v-if="row.uncertain" class="relay-warning">{{ row.uncertain }} 笔估算</div></template></el-table-column>
        <el-table-column label="周期费用 · USD" width="160"><template #default="{ row }">{{ relayPeriodCost(row) }}<div v-if="row.cost_unconfirmed" class="relay-warning">{{ row.cost_unconfirmed }} 笔费用待确认</div></template></el-table-column>
        <el-table-column label="操作" width="172" fixed="right"><template #default="{ row }"><div class="relay-row-actions"><el-button link type="primary" :disabled="saving" @click="editKey(row)">编辑</el-button><el-button link :disabled="saving" @click="keyAction(row, 'rotate')">重置 Key</el-button><el-button v-if="row.enabled" link type="danger" :disabled="saving" @click="keyAction(row, 'revoke')">停用</el-button></div></template></el-table-column>
      </el-table>
      <div v-loading="loading" class="relay-mobile-list">
        <article v-for="row in rows" :key="row.id" class="relay-mobile-card">
          <header><div><strong>{{ row.machine_name || '机器已删除' }}</strong><span class="relay-secondary">#{{ row.machine_id }}</span></div><span :class="['relay-status', { offline: !row.enabled }]"><i />{{ row.enabled ? '已启用' : '已停用' }}</span></header>
          <div class="relay-mobile-model"><span>{{ upstreamName(row.upstream_id) }}</span><span class="relay-secondary">{{ row.models.join('、') }}</span></div>
          <div class="relay-quota"><span>已用 {{ number(row.used) }}</span><span>{{ number(row.quota) }} · {{ periods[row.period] }}</span></div>
          <el-progress :percentage="percentage(row)" :show-text="false" :stroke-width="5" :status="percentage(row) >= 90 ? 'warning' : undefined" />
          <div class="relay-secondary">剩余 {{ number(row.remaining) }}<template v-if="row.reserved"> · 预留 {{ number(row.reserved) }}</template></div>
          <div class="relay-mobile-totals"><button type="button" @click="viewLogs(row)"><span>请求记录<ArrowUpRight :size="12" /></span><strong>{{ number(row.requests) }}</strong><small v-if="row.uncertain" class="relay-warning">{{ row.uncertain }} 笔估算</small></button><div><span>周期费用 · USD</span><strong>{{ relayPeriodCost(row) }}</strong><small v-if="row.cost_unconfirmed" class="relay-warning">{{ row.cost_unconfirmed }} 笔待确认</small></div></div>
          <footer><el-button :disabled="saving" @click="editKey(row)">编辑额度</el-button><el-button text :disabled="saving" @click="keyAction(row, 'rotate')">重置 Key</el-button><el-button v-if="row.enabled" text type="danger" :disabled="saving" @click="keyAction(row, 'revoke')">停用</el-button></footer>
        </article>
        <el-empty v-if="!rows.length && !loading" :description="data.keys.length ? '没有匹配的机器' : '暂无接入机器'" :image-size="54" />
      </div>
      <details class="relay-billing-note"><summary>额度与计费说明</summary><p class="relay-footnote">Token 限额按真实输入与输出合计；费用按模型单价及缓存明细单独统计，不从账户余额扣款。缺少 usage 时按预留量扣 Token，费用标记未确认。</p></details>
    </SectionCard>
    <el-dialog v-model="upstreamOpen" title="上游设置" width="680px" class="relay-dialog" append-to-body :close-on-click-modal="!saving" :close-on-press-escape="!saving" :show-close="!saving">
      <div class="relay-upstreams"><button v-for="row in data.upstreams" :key="row.id" type="button" :disabled="saving" :class="{ active: upstream.id === row.id }" @click="editUpstream(row)">{{ row.name }}<span>{{ row.enabled ? '启用' : '停用' }}</span></button><el-button text :disabled="saving" @click="editUpstream()"><Plus :size="15" />新建上游</el-button></div>
      <el-form id="relay-upstream-form" label-position="top" :disabled="saving" @submit.prevent="saveUpstream">
        <div class="relay-form-row"><el-form-item label="名称"><el-input v-model="upstream.name" maxlength="100" /></el-form-item><el-form-item label="启用"><el-switch v-model="upstream.enabled" /></el-form-item></div>
        <el-form-item label="上游支持的协议" required><el-checkbox-group v-model="upstream.protocols"><el-checkbox v-for="[value, label] in protocolOptions" :key="value" :value="value" :label="value">{{ label }}</el-checkbox></el-checkbox-group></el-form-item>
        <el-form-item label="API 基础地址"><el-input v-model="upstream.base_url" placeholder="https://example.com/v1（Gemini 使用 /v1beta）" /></el-form-item>
        <el-collapse class="relay-protocol-settings"><el-collapse-item title="按协议单独设置地址" name="urls"><el-form-item v-for="p in upstream.protocols.filter(p => p !== 'responses_ws')" :key="p" :label="relayProtocols[p]"><el-input v-model="upstream.protocol_urls[p]" placeholder="留空使用上面的基础地址" /></el-form-item></el-collapse-item></el-collapse>
        <el-form-item label="上游 API Key"><el-input v-model="upstream.api_key" type="password" show-password autocomplete="new-password" :placeholder="upstream.id ? '已保存，留空保持不变' : '填写 API Key'" /></el-form-item>
        <el-form-item label="允许分发的模型"><el-select v-model="upstream.models" multiple filterable allow-create default-first-option placeholder="输入实际模型 ID 后回车" /></el-form-item>
        <el-collapse class="relay-protocol-settings"><el-collapse-item title="模型单价（USD / 百万 Token）" name="prices">
          <el-table :data="upstream.models.map(name => ({ name }))" max-height="250" empty-text="先添加模型">
            <el-table-column prop="name" label="模型" min-width="170" /><el-table-column label="定价" width="65"><template #default="{ row }"><el-switch :model-value="!!upstream.pricing[row.name]" :aria-label="`${row.name} 启用定价`" @change="togglePrice(row.name, $event)" /></template></el-table-column>
            <el-table-column v-for="[field, label] in priceFields" :key="field" :label="label" width="130"><template #default="{ row }"><el-input-number v-if="upstream.pricing[row.name]" v-model="upstream.pricing[row.name][field]" :min="0" :max="10000" :precision="6" :controls="false" :aria-label="`${row.name} ${label}单价`" /><span v-else>--</span></template></el-table-column>
          </el-table><p class="relay-footnote">缓存读取不会再重复按普通输入收费。单价随每次请求保存，后续调价不改历史费用；未定价与缓存明细缺失分别标记。</p>
        </el-collapse-item></el-collapse>
        <p class="relay-footnote">只勾选供应商实际支持的协议，不做跨协议转换。WebSocket 使用 Responses 地址；模型列表连通不代表其他协议可用。</p>
        <el-alert v-if="testResult" :type="testResult.ok ? 'success' : 'error'" :closable="false" :title="testResult.ok ? `连接成功，返回 ${testResult.model_count} 个模型` : `${errorLabels[testResult.error_code] || '连接失败'}（HTTP ${testResult.http_status || '无响应'}）`" />
      </el-form>
      <template #footer><el-button v-if="upstream.id" :disabled="saving" :loading="testing" @click="testUpstream">测试已保存配置</el-button><el-button :disabled="saving" @click="upstreamOpen = false">关闭</el-button><el-button type="primary" native-type="submit" form="relay-upstream-form" :loading="saving">保存上游</el-button></template>
    </el-dialog>
    <el-dialog v-model="keyOpen" :title="machineKey.id ? '编辑机器额度' : '接入机器'" width="560px" class="relay-dialog" append-to-body :close-on-click-modal="!saving" :close-on-press-escape="!saving" :show-close="!saving">
      <el-form id="relay-key-form" label-position="top" :disabled="saving" @submit.prevent="saveKey">
        <el-form-item label="机器"><el-select v-model="machineKey.machine_id" filterable :disabled="!!machineKey.id" placeholder="选择机器"><el-option v-for="m in data.machines" :key="m.id" :value="m.id" :label="`#${m.id} ${m.name}`" :disabled="!machineKey.id && data.keys.some(k => k.machine_id === m.id)" /></el-select></el-form-item>
        <el-form-item label="上游"><el-select v-model="machineKey.upstream_id" @change="machineKey.models = []"><el-option v-for="u in data.upstreams" :key="u.id" :value="u.id" :label="u.name" /></el-select></el-form-item>
        <el-form-item label="模型白名单"><el-select v-model="machineKey.models" multiple filterable><el-option v-for="name in modelOptions" :key="name" :value="name" :label="name" /></el-select></el-form-item>
        <div class="relay-form-row"><el-form-item label="额度（输入 + 输出 Token）"><el-input-number v-model="machineKey.quota" :min="1" :max="1e12" :precision="0" :controls="false" /></el-form-item><el-form-item label="重置周期"><el-select v-model="machineKey.period" :disabled="!!machineKey.id"><el-option v-for="(label, value) in periods" :key="value" :value="value" :label="label" /></el-select></el-form-item></div>
        <div class="relay-form-row"><el-form-item label="单次最大输出 Token"><el-input-number v-model="machineKey.max_output" :min="1" :max="131072" :precision="0" :controls="false" /></el-form-item><el-form-item label="启用"><el-switch v-model="machineKey.enabled" /></el-form-item></div>
      </el-form>
      <template #footer><el-button :disabled="saving" @click="keyOpen = false">取消</el-button><el-button type="primary" native-type="submit" form="relay-key-form" :loading="saving">{{ machineKey.id ? '保存' : '生成机器 Key' }}</el-button></template>
    </el-dialog>
    <el-dialog :model-value="!!secret" title="机器 API Key" width="520px" class="relay-dialog" append-to-body :close-on-click-modal="false" @update:model-value="secret = ''">
      <el-alert title="仅展示一次，关闭后无法再次查看。重置 Key 不会重置已用额度。" type="warning" :closable="false" />
      <div class="relay-secret"><code>{{ secret }}</code><el-button @click="copy(secret)">复制 Key</el-button></div><div class="relay-endpoint"><code>{{ endpoint }}</code></div>
    </el-dialog>
    <el-dialog v-model="logsOpen" title="请求记录" width="1000px" class="relay-dialog" append-to-body>
      <div class="relay-toolbar"><el-select v-model="logMachine" clearable filterable placeholder="全部机器" @change="logPage = 1; loadLogs()"><el-option v-for="m in data.machines" :key="m.id" :value="m.id" :label="m.name" /></el-select><el-button text :loading="logsLoading" @click="loadLogs">刷新</el-button></div>
      <el-alert v-if="logsError" :title="logsError" type="error" :closable="false"><el-button text :loading="logsLoading" @click="loadLogs">重试</el-button></el-alert>
      <el-table v-loading="logsLoading" :data="logs.data" row-key="request_id" max-height="430" :empty-text="logsError ? '请求记录未能加载' : logsLoading ? '加载中' : '暂无请求记录'">
        <el-table-column prop="created_at" label="时间（UTC）" width="175" /><el-table-column label="机器" min-width="130" show-overflow-tooltip><template #default="{ row }">{{ machineName(row.machine_id) }}</template></el-table-column><el-table-column prop="model" label="模型" min-width="130" show-overflow-tooltip /><el-table-column label="协议" width="150"><template #default="{ row }">{{ relayProtocols[row.protocol || 'openai'] }}</template></el-table-column>
        <el-table-column label="输入 / 输出" width="140"><template #default="{ row }">{{ row.input_tokens == null ? '未知' : number(row.input_tokens) }} / {{ row.output_tokens == null ? '未知' : number(row.output_tokens) }}</template></el-table-column><el-table-column label="扣额" width="95"><template #default="{ row }">{{ number(row.charged) }}</template></el-table-column>
        <el-table-column label="缓存读 / 写" width="140"><template #default="{ row }">{{ row.cache_read_tokens == null ? '未知' : number(row.cache_read_tokens) }} / {{ row.cache_write_tokens == null ? '未知' : number(row.cache_write_tokens) }}</template></el-table-column>
        <el-table-column label="费用 · USD" min-width="170"><template #default="{ row }">{{ relayCost(row.cost) }}<div class="relay-secondary">{{ relayCostStates[row.cost_status] || '未配置单价' }}</div></template></el-table-column>
        <el-table-column label="状态" width="110"><template #default="{ row }"><el-tag size="small" :type="row.status === 'complete' ? 'success' : 'warning'">{{ statusLabels[row.status] || row.status }}</el-tag></template></el-table-column><el-table-column label="HTTP / 耗时" width="130"><template #default="{ row }">{{ row.http_status || '—' }} · {{ row.duration_ms == null ? '—' : `${(row.duration_ms / 1000).toFixed(1)}s` }}</template></el-table-column>
        <el-table-column label="诊断" min-width="190" show-overflow-tooltip><template #default="{ row }">{{ errorLabels[row.error_code] || (row.status === 'estimated' ? '未返回 usage，暂按预留量扣额' : '—') }}</template></el-table-column>
      </el-table><el-pagination v-model:current-page="logPage" :page-size="25" :total="logs.total" layout="prev, pager, next, total" @current-change="loadLogs" />
    </el-dialog>
    <QualityDialog v-if="qualityReady" v-model="qualityOpen" :upstream-id="qualityUpstreamId" :upstreams="data.upstreams" @changed="load" />
  </section>
</template>
<style scoped>
.relay-page { container-type:inline-size; }
.relay-summary { display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px;padding:2px 0 20px; }
.relay-health { margin-bottom:20px; }
.relay-status { display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--success);white-space:nowrap; }
.relay-status i { width:6px;height:6px;border-radius:50%;background:currentColor;flex:none; }.relay-status.offline { color:var(--muted); }
.relay-summary > button { display:flex;flex-direction:column;align-items:flex-start;justify-content:center;min-width:0;padding:0;border:0;background:none;color:var(--text);text-align:left;cursor:pointer; }
.relay-summary > button + button { border-left:1px solid var(--line);padding-left:24px; }
.relay-summary > button:hover strong { color:var(--primary); }.relay-summary > button:focus-visible { outline:2px solid var(--primary);outline-offset:5px;border-radius:4px; }
.relay-summary span { display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted); }
.relay-summary strong { display:block;font-size:30px;font-weight:500;margin-top:8px;font-variant-numeric:tabular-nums; }
.relay-endpoint { display:flex;align-items:center;gap:12px;border-top:1px solid var(--line);padding-top:12px;font-size:12px;color:var(--muted); }
.relay-endpoint code { overflow-wrap:anywhere;color:var(--text);font-size:12px; }
.relay-endpoint-address { flex:1;min-width:180px;display:flex;align-items:center;justify-content:space-between;gap:8px;background:var(--bg-soft);border-radius:var(--radius-sm);padding:4px 8px 4px 12px; }
.relay-endpoint-address .el-button { flex:none; }
.relay-endpoint { flex-wrap:wrap; }.relay-endpoint-select { width:185px; }.relay-protocol-settings { margin-bottom:18px; }.relay-quality-notices { display:flex;flex-wrap:wrap;gap:8px;margin-top:12px; }
.relay-quality-notices .el-button { padding:6px 0;height:auto;white-space:normal;text-align:left; }
.relay-toolbar { display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap; }
.relay-toolbar > .el-input, .relay-toolbar > .el-select { max-width:270px; }
.relay-filters { display:flex;gap:8px;flex:1;min-width:260px;max-width:440px; }.relay-filters > .el-input { flex:1;min-width:0; }.relay-filters > .el-select { width:126px;flex:none; }
.relay-toolbar-actions { display:flex;align-items:center;gap:4px;margin-left:auto; }.relay-toolbar-actions .el-button { margin:0;padding-inline:10px; }.relay-toolbar-actions .is-circle { width:32px;padding:0; }
.relay-machine { display:flex;align-items:center;gap:7px; }.relay-machine strong { font-weight:500; }
.relay-row-actions { display:flex;align-items:center;gap:12px;white-space:nowrap; }.relay-row-actions .el-button { margin:0;padding:0; }
.relay-health .relay-status.offline { color:var(--danger, #b33c32); }
.relay-dot { width:6px;height:6px;flex:none;border-radius:50%;background:var(--muted); }.relay-dot.is-enabled { background:var(--success); }
.relay-secondary { color:var(--muted);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:5px; }
.relay-quota { display:flex;justify-content:space-between;gap:8px;font-size:12px;margin-bottom:7px;font-variant-numeric:tabular-nums; }.relay-quota span + span { color:var(--muted); }
.relay-warning { color:var(--primary);font-size:12px; }
.relay-footnote { font-size:12px;color:var(--muted);line-height:1.6;margin:16px 0 0; }
.relay-billing-note { margin-top:18px;color:var(--muted);font-size:12px; }.relay-billing-note summary { cursor:pointer;width:fit-content; }.relay-billing-note .relay-footnote { margin-top:8px; }
.relay-mobile-list { display:none; }
.relay-mobile-card { min-width:0;padding:18px;border:1px solid var(--line);border-radius:var(--radius-md);background:var(--panel-raised); }
.relay-mobile-card header { display:flex;align-items:flex-start;justify-content:space-between;gap:12px; }.relay-mobile-card header > div { min-width:0; }.relay-mobile-card header strong { display:block;font-size:15px;overflow-wrap:anywhere;font-weight:600; }
.relay-mobile-card header .relay-secondary { display:block; }.relay-mobile-model { margin:16px 0;font-size:13px; }.relay-mobile-model > span { display:block; }.relay-mobile-model .relay-secondary { white-space:normal;overflow-wrap:anywhere; }
.relay-mobile-totals { display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:18px; }.relay-mobile-totals > * { border:0;padding:0;background:none;text-align:left;min-width:0; }.relay-mobile-totals > button { display:flex;flex-direction:column;align-items:flex-start;justify-content:flex-start;gap:0;cursor:pointer; }.relay-mobile-totals > button:hover strong { color:var(--primary); }
.relay-mobile-totals span { display:flex;align-items:center;gap:5px;font-size:12px;color:var(--muted); }.relay-mobile-totals strong { display:block;font-size:17px;font-weight:500;color:var(--text);margin-top:6px;overflow-wrap:anywhere; }.relay-mobile-totals small { display:block;margin-top:5px; }
.relay-mobile-card footer { display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:18px;padding-top:12px;border-top:1px solid var(--line); }.relay-mobile-card footer .el-button { margin:0;padding-inline:12px; }
.relay-upstreams { display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px; }.relay-upstreams > button:not(.el-button) { border:1px solid var(--line);border-radius:var(--radius-sm);padding:10px 12px;background:var(--panel);color:var(--text);cursor:pointer; }.relay-upstreams > button.active { border-color:var(--primary);background:var(--primary-soft); }.relay-upstreams span { margin-left:8px;font-size:11px;color:var(--muted); }
.relay-upstreams > button:disabled { cursor:default;opacity:.55; }
.relay-form-row { display:grid;grid-template-columns:1fr 1fr;gap:20px; }
.relay-secret { display:flex;align-items:center;gap:16px;margin:24px 0; }.relay-secret code { overflow-wrap:anywhere; }
.relay-page :deep(.el-button) { gap:6px; }.relay-page :deep(.el-pagination) { margin-top:16px;justify-content:flex-end; }
@container(max-width:900px) { .relay-desktop-table { display:none; }.relay-mobile-list { display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:12px; }.relay-mobile-list > .el-empty { grid-column:1/-1; } }
@container(max-width:500px) { .relay-summary { gap:12px; }.relay-summary > button + button { padding-left:12px; }.relay-summary strong { font-size:25px; }.relay-summary span { font-size:12px;gap:3px; }.relay-summary span svg { display:none; }.relay-endpoint { gap:8px; }.relay-endpoint-select { width:100%; }.relay-endpoint-address { min-width:0;width:100%;flex:auto; }.relay-filters { max-width:none;flex-basis:100%;min-width:0; }.relay-toolbar-actions { width:100%;justify-content:flex-end; }.relay-mobile-card { padding:14px; } }
@media(max-width:600px) { .relay-form-row { grid-template-columns:1fr;gap:0; }.relay-secret { flex-direction:column;align-items:stretch; } }
</style>
<style>
.relay-dialog.el-dialog { max-width:calc(100vw - 24px);display:flex;flex-direction:column;max-height:calc(100dvh - 32px);margin:min(4vh,32px) auto; }
.relay-dialog > .el-dialog__body { flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain; }
.relay-dialog > .el-dialog__header,.relay-dialog > .el-dialog__footer { flex:none; }
.relay-dialog > .el-dialog__footer { display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;border-top:1px solid var(--line);padding-top:14px; }
.relay-dialog > .el-dialog__footer .el-button { margin:0; }
.relay-dialog .el-select,.relay-dialog .el-input-number { width:100%; }
@media(max-width:420px) { .relay-dialog > .el-dialog__footer .el-button { padding-inline:12px; } }
</style>
