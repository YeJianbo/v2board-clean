<script setup>
import { computed, defineAsyncComponent, onMounted, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { buildDashboardApiUrl, requestDashboardApi, requestDashboardMutation } from '../../services/api'
import { ElMessage } from 'element-plus'
import { formatBytes } from '../../utils/format'
import { trafficNodeColor } from '../../utils/userTrafficChart'
const MultiTrafficChart = defineAsyncComponent(() => import('../common/MultiTrafficChart.vue'))
const period = ref('today'), customRange = ref([]), activeRange = ref(null)
const resolution = ref('hour'), search = ref(''), rows = shallowRef([]), total = ref(0), loading = ref(false), error = ref('')
const nodeId = ref(null), activeNodeId = ref(null), nodes = shallowRef([]), actualRange = ref(null)
const retention = ref({ domain_hour: 2, domain_daily: 31 }), lifetimeTotal = ref(0)
let disposed = false
function selectedRange() {
  if (period.value === 'custom') {
    if (!Array.isArray(customRange.value) || customRange.value.length !== 2) return null
    return { start_at: Math.floor(Number(customRange.value[0]) / 1000), end_at: Math.min(Math.floor(Number(customRange.value[1]) / 1000), Math.floor(Date.now() / 1000)) }
  }
  const now = new Date()
  const start = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  if (period.value === '7') start.setDate(start.getDate() - 6)
  if (period.value === '30') start.setDate(start.getDate() - 29)
  const earliest = Date.now() - retention.value.domain_daily * 86400000
  if (start.getTime() < earliest) start.setTime(earliest)
  return { start_at: Math.floor(start.getTime() / 1000), end_at: Math.floor(Date.now() / 1000) }
}
function timeParams(range) { return [['start_at', range.start_at], ['end_at', range.end_at]] }
function changePeriod() {
  analysis.value = null
  if (selectedRange()) load()
  else { ++serial; loadController?.abort(); rows.value = []; total.value = 0; activeRange.value = null; actualRange.value = null; loading.value = false }
}
const rangeLabel = computed(() => {
  if (!actualRange.value) return ''
  const start = new Date(actualRange.value.since * 1000).toLocaleString()
  const end = new Date(actualRange.value.until * 1000).toLocaleString()
  return `${start} 至 ${end}`
})
const collectionOpen = ref(false), collection = ref([]), standaloneNodes = ref([]), masterEnabled = ref(true), savingTargets = ref(new Set())
const collectionLoading = ref(false), collectionError = ref('')
let collectionSerial = 0, collectionController
function cancelCollectionRead() {
  ++collectionSerial; collectionController?.abort(); collectionController = null; collectionLoading.value = false
}
watch(collectionOpen, open => { if (!open) cancelCollectionRead() }, { flush: 'sync' })
async function openCollection() {
  if (disposed || savingTargets.value.size) return
  const id = ++collectionSerial
  collectionController?.abort()
  collectionController = new AbortController()
  const signal = collectionController.signal
  const current = () => !disposed && !signal.aborted && collectionOpen.value && id === collectionSerial
  collectionOpen.value = true; collectionLoading.value = true; collectionError.value = ''
  collection.value = []; standaloneNodes.value = []
  try {
    const result = await requestDashboardApi(buildDashboardApiUrl('stat/domainTraffic/collection'), { signal })
    if (!current()) return
    collection.value = result.data.machines || []
    standaloneNodes.value = result.data.standalone_nodes || []
    masterEnabled.value = result.data.master_enabled !== false
  } catch (e) { if (current()) collectionError.value = e.message || '采集配置加载失败' }
  finally { if (id === collectionSerial) { collectionLoading.value = false; collectionController = null } }
}
async function setCollection(scope, target, value) {
  if (disposed || !collectionOpen.value || collectionLoading.value || collectionError.value) return
  const key = `${scope}:${target.id}`
  if (savingTargets.value.has(key)) return
  savingTargets.value.add(key)
  try {
    await requestDashboardMutation(buildDashboardApiUrl('stat/domainTraffic/collection'), { scope, id: target.id, enabled: value })
    if (disposed || !collectionOpen.value) return
    target.enabled = value
    ElMessage.success('已保存，节点下次拉取配置后生效')
  } catch (e) { if (!disposed && collectionOpen.value) ElMessage.error(e.message || '设置失败') }
  finally { savingTargets.value.delete(key) }
}
const historyOpen = ref(false), historyLoading = ref(false), history = shallowRef(null), historyError = ref('')
let historySerial = 0
let historyController, loadController
watch(historyOpen, open => { if (!open) { ++historySerial; historyController?.abort(); historyLoading.value = false } }, { flush: 'sync' })
function releaseClosedHistory() {
  if (historyOpen.value) return
  history.value = null
  historyController = null
}
onBeforeUnmount(() => { disposed = true; ++serial; ++historySerial; historyController?.abort(); loadController?.abort(); cancelCollectionRead(); cancelBlockRead() })
const historyRangeLabel = computed(() => history.value?.since && history.value?.until
  ? `${new Date(history.value.since * 1000).toLocaleString()} 至 ${new Date(history.value.until * 1000).toLocaleString()}` : '')
const historyNodeFocus = ref('ALL'), historyNodesExpanded = ref(false)
const historyNodeTotal = computed(() => (history.value?.nodes || []).reduce((sum, node) => sum + Number(node.total), 0))
const visibleHistoryNodes = computed(() => {
  const nodes = history.value?.nodes || []
  return historyNodesExpanded.value ? nodes : nodes.slice(0, 8)
})
function historyBuckets(data) {
  if (!data?.since || !data?.until) return []
  const step = data.resolution === 'hour' ? 3600 : 86400
  const count = Math.ceil((Number(data.until) - Number(data.since)) / step)
  return Array.from({ length: Math.max(0, count) }, (_, index) => Number(data.since) + index * step)
}
function historyPointLabel(bucket, hourly) {
  const date = new Date(bucket * 1000)
  const day = `${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
  return hourly ? `${day} ${String(date.getHours()).padStart(2, '0')}:00` : day
}
const historyChartSeries = computed(() => {
  const data = history.value
  const buckets = historyBuckets(data)
  if (!buckets.length) return []
  const hourly = data.resolution === 'hour'
  const focused = historyNodeFocus.value
  const nodes = (data.nodes || []).filter(node => node.points?.length && (focused === 'ALL' || String(node.id) === String(focused)))
  if (nodes.length) {
    return nodes.map(node => {
      const byBucket = new Map(node.points.map(point => [Number(point.bucket), point]))
      return {
        key: String(node.id), name: node.name, color: trafficNodeColor(node.id),
        points: buckets.map(bucket => {
          const point = byBucket.get(bucket)
          const upload = Number(point?.upload || 0), download = Number(point?.download || 0)
          return { timestamp: bucket, label: historyPointLabel(bucket, hourly), total: upload + download, upload, download }
        }),
      }
    }).filter(series => series.points.some(point => point.total > 0))
  }
  const byBucket = new Map((data.points || []).map(point => [Number(point.bucket), point]))
  return [{
    key: 'total', name: '合计', color: '#399f94',
    points: buckets.map(bucket => {
      const point = byBucket.get(bucket)
      const upload = Number(point?.upload || 0), download = Number(point?.download || 0)
      return { timestamp: bucket, label: historyPointLabel(bucket, hourly), total: upload + download, upload, download }
    }),
  }].filter(series => series.points.some(point => point.total > 0))
})
const historyChartLabels = computed(() => (historyChartSeries.value[0]?.points || []).map(point => ({ timestamp: point.timestamp, label: point.label })))
function formatHistoryTooltip(series, point) {
  return { title: series?.name || '--', lines: [point?.label || '--', `合计 ${formatBytes(point?.total)}`, `上传 ${formatBytes(point?.upload)} · 下载 ${formatBytes(point?.download)}`] }
}
function nodeShare(node) {
  if (!historyNodeTotal.value) return '0%'
  const share = Number(node.total) / historyNodeTotal.value * 100
  return `${share < 0.1 ? '<0.1' : share.toFixed(1)}%`
}
function focusHistoryNode(id) { historyNodeFocus.value = historyNodeFocus.value === id ? 'ALL' : id }
async function openHistory(domain) {
  if (disposed || !activeRange.value || loading.value || error.value) return
  const id = ++historySerial
  historyController?.abort()
  historyController = new AbortController()
  const signal = historyController.signal
  historyOpen.value = true; historyLoading.value = true; historyError.value = ''; history.value = { domain, points: [], nodes: [] }
  historyNodeFocus.value = 'ALL'; historyNodesExpanded.value = false
  try {
    const result = await requestDashboardApi(buildDashboardApiUrl('stat/domainTraffic/history', [['domain', domain], ...timeParams(activeRange.value), ['node_id', activeNodeId.value || null]]), { signal })
    if (id === historySerial) history.value = result.data
  } catch (e) { if (id === historySerial) historyError.value = e.message || '曲线加载失败' }
  finally { if (id === historySerial) historyLoading.value = false }
}
const blockOpen = ref(false), blockDomain = ref(''), blockMachines = ref([]), selectedMachines = ref([]), blocking = ref(false)
const blockOptionsLoading = ref(false), blockError = ref(''), blockWriteError = ref('')
let blockSerial = 0, blockController
function cancelBlockRead() {
  ++blockSerial; blockController?.abort(); blockController = null; blockOptionsLoading.value = false
}
watch(blockOpen, open => { if (!open) cancelBlockRead() }, { flush: 'sync' })
async function openBlock(domain) {
  if (disposed || blocking.value) return
  const id = ++blockSerial
  blockController?.abort()
  blockController = new AbortController()
  const signal = blockController.signal
  const current = () => !disposed && !signal.aborted && blockOpen.value && id === blockSerial
  blockDomain.value = domain; selectedMachines.value = []; blockMachines.value = []; blockError.value = ''; blockWriteError.value = ''
  blockOpen.value = true; blockOptionsLoading.value = true
  try {
    const result = await requestDashboardApi(buildDashboardApiUrl('stat/domainTraffic/block-options'), { signal })
    if (!current()) return
    blockMachines.value = result.data || []
  } catch (e) { if (current()) blockError.value = e.message || '机器列表加载失败' }
  finally { if (id === blockSerial) { blockOptionsLoading.value = false; blockController = null } }
}
async function blockDomainOnMachines() {
  if (disposed || !blockOpen.value || blockOptionsLoading.value || blockError.value || !selectedMachines.value.length || blocking.value) return
  const domain = blockDomain.value, machineIds = [...selectedMachines.value]
  blocking.value = true; blockWriteError.value = ''
  try {
    const result = await requestDashboardMutation(buildDashboardApiUrl('stat/domainTraffic/block'), { domain, machine_ids: machineIds })
    if (disposed || !blockOpen.value || blockDomain.value !== domain) return
    ElMessage.success(`已保存 ${result.data.machine_ids.length} 台机器的屏蔽规则；节点下次拉取配置后生效`)
    blockOpen.value = false
  } catch (e) { if (!disposed) blockWriteError.value = e.message || '暂时无法确认保存结果，请核对路由规则后再重试' }
  finally { if (!disposed) blocking.value = false }
}
let serial = 0
const analysing = ref(false), applying = ref(false), analysis = ref(null), selected = ref([])
async function analyse() {
  if (disposed || analysing.value || applying.value || loading.value || error.value || !activeRange.value) return
  const requestSerial = serial
  analysing.value = true; error.value = ''; analysis.value = null; selected.value = []
  try {
    const result = await requestDashboardMutation(buildDashboardApiUrl('stat/domainTraffic/analyse'), { ...activeRange.value, node_id: activeNodeId.value || null })
    if (requestSerial === serial) analysis.value = result.data
  } catch (e) { if (requestSerial === serial) error.value = e.message || '分析失败' }
  finally { analysing.value = false }
}
async function apply() {
  if (disposed || applying.value || analysing.value || !analysis.value?.token || !selected.value.length) return
  const targetAnalysis = analysis.value
  const domains = selected.value.map(row => row.domain)
  applying.value = true; error.value = ''
  try {
    const result = await requestDashboardMutation(buildDashboardApiUrl('stat/domainTraffic/apply'), { token: targetAnalysis.token, domains })
    if (disposed) return
    ElMessage.success(`已补充 ${result.data.added} 个域名到 direct.yaml`)
    const added = new Set(domains)
    targetAnalysis.rows.forEach(row => { if (added.has(row.domain)) row.status = 'covered' })
    targetAnalysis.token = null
    if (analysis.value === targetAnalysis) selected.value = []
  } catch (e) { if (!disposed && analysis.value === targetAnalysis) error.value = e.message || '保存失败' }
  finally { applying.value = false }
}
async function load() {
  if (disposed || applying.value) return
  const range = selectedRange()
  if (!range || range.start_at >= range.end_at) return
  const id = ++serial
  loadController?.abort()
  loadController = new AbortController()
  const signal = loadController.signal
  const requestedNodeId = nodeId.value
  analysis.value = null; selected.value = []
  loading.value = true; error.value = ''
  try {
    const result = await requestDashboardApi(buildDashboardApiUrl('stat/domainTraffic', [...timeParams(range), ['search', search.value.trim()], ['node_id', requestedNodeId || null]]), { signal })
    if (id !== serial) return
    rows.value = result.data.rows; total.value = result.data.total; nodes.value = result.data.nodes || []
    activeRange.value = range; activeNodeId.value = requestedNodeId; resolution.value = result.data.resolution || 'day'
    actualRange.value = { since: result.data.since, until: result.data.until }
    retention.value = result.data.retention || retention.value
    lifetimeTotal.value = Number(result.data.lifetime_total || 0)
  } catch (e) { if (id === serial) error.value = e.message || '加载失败' }
  finally { if (id === serial) loading.value = false }
}
onMounted(load)
</script>

<template>
  <el-card class="section-card domain-traffic-card" shadow="never">
    <div class="section-head domain-head">
      <div><h3>网站流量排行</h3><span class="domain-summary">当前范围 {{ formatBytes(total) }} · 累计采集 {{ formatBytes(lifetimeTotal) }}</span></div>
      <div class="domain-actions">
        <el-button :loading="collectionLoading" :disabled="savingTargets.size > 0" @click="openCollection">采集设置</el-button>
        <el-segmented v-model="period" :disabled="analysing || applying" :options="[{label:'今天',value:'today'},{label:'7天',value:'7'},{label:'30天',value:'30'},{label:'自定义',value:'custom'}]" @change="changePeriod" />
        <el-date-picker v-if="period === 'custom'" v-model="customRange" type="datetimerange" value-format="x" start-placeholder="开始时间" end-placeholder="结束时间" range-separator="至" :disabled="analysing || applying" :disabled-date="date => date.getTime() > Date.now() || date.getTime() < Date.now() - (retention.domain_daily + 1) * 86400000" @change="changePeriod" />
        <el-select v-model="nodeId" clearable filterable placeholder="全部节点" aria-label="筛选节点" :disabled="analysing || applying" @change="analysis = null; load()"><el-option v-for="node in nodes" :key="node.id" :value="node.id" :label="`${node.name} (#${node.id})`" /></el-select>
        <el-input v-model="search" placeholder="搜索域名" aria-label="搜索网站域名" :disabled="applying" clearable @keyup.enter="load" @clear="load" />
        <el-button :loading="loading" :disabled="applying" @click="load">刷新</el-button>
        <el-button type="primary" :loading="analysing" :disabled="applying || loading || !!error || !activeRange || (period === 'custom' && !selectedRange())" @click="analyse">分析国内域名</el-button>
      </div>
    </div>
    <p v-if="actualRange" class="domain-summary domain-period">统计范围：{{ rangeLabel }}（结束时间不含） · {{ resolution === 'hour' ? '按完整小时汇总' : '按完整日汇总，边界可能超出所选时间' }} · 小时明细 {{ retention.domain_hour }} 天 / 日明细 {{ retention.domain_daily }} 天</p>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table v-loading="loading" :data="rows" max-height="380" empty-text="暂无域名流量，请确认节点已升级并产生代理流量">
      <template #empty><p class="domain-empty">暂无域名流量<br>节点升级并产生代理流量后显示</p></template>
      <el-table-column type="index" label="#" width="45" />
      <el-table-column prop="domain" label="主域名" min-width="200" show-overflow-tooltip><template #default="{ row }"><el-button link type="primary" :disabled="loading || !!error" @click="openHistory(row.domain)">{{ row.domain }}</el-button></template></el-table-column>
      <el-table-column label="上传" min-width="95"><template #default="{ row }">{{ formatBytes(row.upload) }}</template></el-table-column>
      <el-table-column label="下载" min-width="95"><template #default="{ row }">{{ formatBytes(row.download) }}</template></el-table-column>
      <el-table-column label="合计" min-width="130"><template #default="{ row }"><div>{{ formatBytes(row.total) }}</div><div class="domain-bar"><i :style="{ width: `${total ? Math.min(100, row.total / total * 100) : 0}%` }" /></div></template></el-table-column>
      <el-table-column label="操作" width="90"><template #default="{ row }"><el-button link type="danger" :disabled="loading || !!error || blocking" @click="openBlock(row.domain)">屏蔽</el-button></template></el-table-column>
    </el-table>
    <section v-if="analysis" class="domain-analysis">
      <div class="section-head domain-head">
        <div><h3>国内域名分析</h3><span class="domain-summary">{{ analysis.node_id ? (nodes.find(node => node.id === analysis.node_id)?.name || `节点 #${analysis.node_id}`) : '全部节点' }} · {{ rangeLabel }} · 已分析 {{ analysis.scanned }} 个域名 · {{ analysis.matched }} 个候选</span></div>
        <el-button type="primary" :loading="applying" :disabled="!analysis.token || !selected.length || analysing" @click="apply">补入直连（{{ selected.length }}）</el-button>
      </div>
      <p class="domain-summary">选择需直连的域名后补入 direct.yaml；已有规则保留，仅添加完整域名。{{ analysis.matched > 500 ? '按流量显示前 500 个匹配结果。' : '' }}</p>
      <el-table :data="analysis.rows" max-height="440" empty-text="未发现需要检查的国内域名" @selection-change="selected = $event">
        <el-table-column type="selection" width="45" :selectable="row => row.status === 'candidate' && !!analysis.token && !applying" />
        <el-table-column prop="domain" label="域名" min-width="190" show-overflow-tooltip />
        <el-table-column label="代理流量" min-width="100"><template #default="{row}">{{ formatBytes(row.total) }}</template></el-table-column>
        <el-table-column prop="reason" label="判断依据" min-width="220" />
        <el-table-column label="状态" min-width="120"><template #default="{row}"><el-tag :type="row.status === 'covered' ? 'success' : row.status === 'conflict' ? 'danger' : 'warning'">{{ {covered:'已有直连',conflict:'规则冲突',candidate:'待确认'}[row.status] }}</el-tag></template></el-table-column>
      </el-table>
    </section>
    <el-dialog v-model="historyOpen" :title="`${history?.domain || ''} · 流量详情`" width="min(880px, 94vw)" append-to-body destroy-on-close @closed="releaseClosedHistory">
      <div v-loading="historyLoading" class="domain-history">
        <el-alert v-if="historyError" :title="historyError" type="error" :closable="false"><el-button size="small" @click="openHistory(history.domain)">重试</el-button></el-alert>
        <template v-if="!historyLoading && !historyError && history">
          <p class="domain-summary">统计范围：{{ historyRangeLabel }}（结束时间不含） · {{ history.resolution === 'hour' ? '按完整小时汇总' : '按完整日汇总' }}</p>
          <div class="domain-chart">
            <MultiTrafficChart :series="historyChartSeries" :labels="historyChartLabels" :height="220" fit-container zoomable chart-type="bar" empty-text="该时段暂无细分记录；小时历史从新版采集开始累计" :tooltip-formatter="formatHistoryTooltip" />
          </div>
          <div v-if="history.nodes?.length" class="domain-node-list">
            <button type="button" class="domain-node-all" :class="{ 'is-selected': historyNodeFocus === 'ALL' }" @click="historyNodeFocus = 'ALL'">全部节点</button>
            <button v-for="node in visibleHistoryNodes" :key="node.id" type="button" class="domain-node-item" :class="{ 'is-selected': historyNodeFocus === String(node.id) }" :aria-pressed="historyNodeFocus === String(node.id)" @click="focusHistoryNode(String(node.id))">
              <i :style="{ background: trafficNodeColor(node.id) }" />
              <strong>{{ node.name }}</strong>
              <span class="domain-node-share">{{ formatBytes(node.total) }} · {{ nodeShare(node) }}</span>
            </button>
            <el-button v-if="history.nodes.length > 8" text size="small" @click="historyNodesExpanded = !historyNodesExpanded">{{ historyNodesExpanded ? '收起' : `+${history.nodes.length - 8}` }}</el-button>
          </div>
        </template>
      </div>
    </el-dialog>
    <el-dialog v-model="collectionOpen" title="域名流量采集" width="min(720px, 94vw)" append-to-body :close-on-click-modal="!savingTargets.size" :close-on-press-escape="!savingTargets.size" :show-close="!savingTargets.size">
      <p class="domain-summary">机器关闭后，其节点全部停止采集；单独关闭节点不影响同机其他节点。历史数据不会删除。</p>
      <el-alert v-if="collectionError" :title="collectionError" type="error" :closable="false"><el-button size="small" :disabled="savingTargets.size > 0" @click="openCollection">重试</el-button></el-alert>
      <el-alert v-if="!collectionLoading && !collectionError && !masterEnabled" title="旧版全局采集开关仍处于关闭状态，以下开关不会生效" type="warning" :closable="false" />
      <div v-loading="collectionLoading" class="collection-list"><section v-for="machine in collection" :key="machine.id" class="collection-machine">
        <div class="collection-row"><strong>{{ machine.name }} (#{{ machine.id }})</strong><el-switch :model-value="machine.enabled" :loading="savingTargets.has(`machine:${machine.id}`)" aria-label="机器采集" @change="setCollection('machine', machine, $event)" /></div>
        <div v-for="node in machine.nodes" :key="node.id" class="collection-row collection-node"><span>{{ node.name }} (#{{ node.id }})</span><el-switch :model-value="node.enabled" :loading="savingTargets.has(`node:${node.id}`)" :disabled="!machine.enabled || savingTargets.has(`machine:${machine.id}`)" aria-label="节点采集" @change="setCollection('node', node, $event)" /></div>
      </section><section v-if="standaloneNodes.length" class="collection-machine"><strong>未绑定或绑定失效</strong>
        <div v-for="node in standaloneNodes" :key="node.id" class="collection-row collection-node"><span>{{ node.name }} (#{{ node.id }})<small v-if="node.binding_missing" class="collection-binding-note">绑定机器 #{{ node.machine_id }} 已不存在</small></span><el-switch :model-value="node.enabled" :loading="savingTargets.has(`node:${node.id}`)" aria-label="节点采集" @change="setCollection('node', node, $event)" /></div>
      </section></div>
    </el-dialog>
    <el-dialog v-model="blockOpen" :title="`屏蔽 ${blockDomain}`" :close-on-click-modal="!blocking" :close-on-press-escape="!blocking" :show-close="!blocking" width="min(560px, 94vw)" append-to-body>
      <p>请选择部署机器。规则会屏蔽 {{ blockDomain }} 及其全部子域名。</p>
      <el-alert v-if="blockError" :title="blockError" type="error" :closable="false"><el-button size="small" :disabled="blocking" @click="openBlock(blockDomain)">重新加载机器</el-button></el-alert>
      <el-alert v-if="blockWriteError" :title="blockWriteError" type="error" :closable="false" />
      <div v-loading="blockOptionsLoading" class="block-machine-list">
        <p v-if="!blockOptionsLoading && !blockError && !blockMachines.length" class="domain-summary">暂无可部署的机器</p>
        <el-checkbox-group v-model="selectedMachines" :disabled="blocking || blockOptionsLoading || !!blockError"><el-checkbox v-for="machine in blockMachines" :key="machine.id" :value="machine.id" :label="`${machine.name} · ${machine.node_count} 个节点`" /></el-checkbox-group>
      </div>
      <template #footer><el-button :disabled="blocking" @click="blockOpen = false">取消</el-button><el-button type="danger" :loading="blocking" :disabled="blockOptionsLoading || !!blockError || !selectedMachines.length" @click="blockDomainOnMachines">确认屏蔽所选机器</el-button></template>
    </el-dialog>
  </el-card>
</template>

<style scoped>
.domain-head { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap }
.domain-head h3 { margin:0 0 4px }
.domain-summary { color:var(--el-text-color-secondary); font-size:12px }
.domain-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap }
.domain-actions .el-input { width:180px }
.domain-actions .el-select { width:220px }
.domain-actions .el-date-editor { width:380px }
.domain-period { margin:8px 0 12px }
.domain-bar { height:3px; margin-top:5px; background:var(--el-fill-color); border-radius:3px }
.domain-bar i { display:block; height:100%; background:var(--el-color-primary); border-radius:3px }
.domain-empty { line-height:1.6; margin:20px 0 }
.domain-analysis { margin-top:24px; padding-top:20px; border-top:1px solid var(--el-border-color-light) }
.domain-history { min-height: 180px; max-height: calc(100vh - 160px); overflow: auto }
.domain-chart { margin-top: 4px }
.domain-node-list { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-top:12px }
.domain-node-list > .el-button { margin:0 }
.domain-node-all, .domain-node-item { display:inline-flex; align-items:center; gap:6px; max-width:100%; padding:5px 10px; border:1px solid var(--el-border-color-lighter); border-radius:8px; background:var(--el-fill-color-blank); color:var(--el-text-color-primary); font:inherit; cursor:pointer }
.domain-node-all { color:var(--el-text-color-regular) }
.domain-node-all:hover, .domain-node-item:hover, .domain-node-all.is-selected, .domain-node-item.is-selected { border-color:var(--el-color-primary); background:var(--el-fill-color-light) }
.domain-node-item i { width:8px; height:8px; border-radius:2px; flex:0 0 auto }
.domain-node-item strong { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:12px; font-weight:600 }
.domain-node-share { color:var(--el-text-color-secondary); font-size:12px; white-space:nowrap }
.collection-list { min-height:80px; max-height:55vh; overflow:auto; margin-top:16px }
.collection-machine { padding:12px 0; border-top:1px solid var(--el-border-color-light) }
.collection-row { display:flex; justify-content:space-between; align-items:center; gap:16px; min-height:36px }
.collection-node { padding-left:18px; color:var(--el-text-color-secondary) }
.collection-binding-note { display:block; margin-top:3px; font-size:12px; color:var(--el-color-warning) }
.block-machine-list { min-height:80px; max-height:42vh; overflow:auto }
.block-machine-list :deep(.el-checkbox-group) { display:flex; flex-direction:column }
@media(max-width:640px) { .domain-actions { width:100% } .domain-actions .el-segmented { flex-basis:100% } .domain-actions .el-date-editor { width:100%; max-width:100% } .domain-actions .el-input { flex:1; min-width:120px } }
</style>
