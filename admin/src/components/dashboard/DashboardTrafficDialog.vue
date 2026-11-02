<script setup>
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { ElSelect, ElOption } from 'element-plus'
import MultiTrafficChart from '../common/MultiTrafficChart.vue'
import { buildDashboardApiUrl, requestDashboardApi } from '../../services/api'
import { trafficPage, trafficGroupItems, trafficTotalsInRange, filterTrafficNodes, filterTrafficUsers } from '../../utils/dashboardTrafficView'
import { trafficNodeColor } from '../../utils/userTrafficChart'
const props = defineProps({ modelValue: Boolean, period: { type: String, default: 'month' } })
const emit = defineEmits(['update:modelValue'])
const interval = ref('day'), chartType = ref('bar'), loading = ref(false), error = ref('')
const tab = ref('trend'), group = ref('node'), selection = ref(''), options = shallowRef([]), dates = ref([]), page = ref(1), search = ref('')
const detailNode = ref('')
// Responses are replaced as a whole; chart points do not need deep reactive proxies.
const data = shallowRef({series: [], nodes: [], users: [], total: 0})
let request = 0
let readController
function cancelRead() { readController?.abort(); readController = null }
function releaseClosedHistory() {
  if (props.modelValue) return
  data.value = {series: [], nodes: [], users: [], total: 0}
  options.value = []
}
onBeforeUnmount(() => { request++; cancelRead() })
const title = computed(() => ({today:'今日',month:'本月',all:'累计'})[props.period] + '流量分析')
function bytes(value) {
  const n = Math.max(0, Number(value) || 0), unit = Math.min(4, Math.floor(Math.log(n || 1) / Math.log(1024)))
  return `${(n / 1024 ** unit).toFixed(unit ? 2 : 0)} ${['B','KB','MB','GB','TB'][unit]}`
}
const userNodeDetails = computed(() => group.value === 'user' && Boolean(selection.value))
const detailNodeOptions = computed(() => filterTrafficNodes(data.value.nodes, '', true))
const rows = computed(() => {
  const source = data.value.series.filter(row => !dates.value?.length || (row.date.slice(0,10) >= dates.value[0] && row.date.slice(0,10) <= dates.value[1]))
  if (!userNodeDetails.value || !detailNode.value) return source
  const node = data.value.nodes.find(item => item.key === detailNode.value)
  const points = new Map((node?.points || []).map(point => [point.date, point]))
  return source.map(row => ({date:row.date, upload:Number(points.get(row.date)?.upload || 0), download:Number(points.get(row.date)?.download || 0)}))
})
const items = computed(() => trafficGroupItems(data.value, group.value))
const chartItems = computed(() => userNodeDetails.value
  ? data.value.nodes.filter(item => !detailNode.value || item.key === detailNode.value)
  : items.value)
const series = computed(() => chartItems.value.filter(item => (item.id || userNodeDetails.value) && item.points?.length).map(item => {
  const points = new Map(item.points.map(point => [point.date, point]))
  return {key:item.key, name:item.name, color:trafficNodeColor(item.key), points:rows.value.map((row,index) => {
    const point = points.get(row.date)
    const upload = Number(point?.upload || 0), download = Number(point?.download || 0)
    return {timestamp:index + 1,label:row.date,upload,download,total:upload+download}
  })}
}).filter(item => item.points.some(point => point.total > 0)))
const labels = computed(() => rows.value.map((row,index) => ({timestamp:index+1,label:interval.value === 'hour' ? row.date.slice(11) : row.date})))
const expanded = ref(false)
const legend = computed(() => expanded.value ? series.value : series.value.slice(0,6))
function changeSelection() {detailNode.value = ''; search.value = ''; if (group.value === 'user' && selection.value) tab.value = 'distribution'; load()}
function focusItem(key) {
  if (userNodeDetails.value) {detailNode.value = detailNode.value === key ? '' : key; return}
  selection.value = selection.value === key ? '' : key; changeSelection()
}
function clearChartSelection() {
  if (userNodeDetails.value) {detailNode.value = ''; return}
  selection.value = ''; changeSelection()
}
const distributionRows = computed(() => {
  const source = userNodeDetails.value ? chartItems.value : items.value
  const totals = trafficTotalsInRange(source, dates.value)
  return group.value === 'user' && !userNodeDetails.value
    ? filterTrafficUsers(totals, search.value)
    : filterTrafficNodes(totals, search.value, userNodeDetails.value)
})
const latestRows = computed(() => [...rows.value].sort((a, b) => b.date.localeCompare(a.date)))
const tableRows = computed(() => trafficPage(tab.value === 'trend' ? latestRows.value : distributionRows.value, page.value, 20).rows)
const tableCount = computed(() => tab.value === 'trend' ? rows.value.length : distributionRows.value.length)
const displayTotal = computed(() => dates.value?.length || (userNodeDetails.value && detailNode.value)
  ? rows.value.reduce((sum,row)=>sum+Number(row.upload || 0)+Number(row.download || 0),0) : data.value.total)
watch([tab, search, dates, detailNode], () => {page.value = 1})
function tooltip(item, point) {return {title:item.name, lines:[point.label, `合计 ${bytes(point.total)}`, `上传 ${bytes(point.upload)} · 下载 ${bytes(point.download)}`]}}
async function load() {
  const id = ++request
  cancelRead()
  readController = new AbortController()
  const signal = readController.signal
  loading.value = true; error.value = ''; data.value = {series:[],nodes:[],users:[],total:0}; page.value = 1
  try {
    const params = [['period',props.period],['interval',interval.value],['group_by',group.value]]
    if (selection.value) params.push(group.value === 'user' ? ['user_id',selection.value.slice(5)] : ['node',selection.value])
    const response = await requestDashboardApi(buildDashboardApiUrl('stat/getTrafficHistory', params), { signal })
    if (id !== request) return
    if (!Array.isArray(response?.data?.series) || !Array.isArray(response?.data?.[group.value === 'user' ? 'users' : 'nodes'])
      || (group.value === 'user' && selection.value && !Array.isArray(response?.data?.nodes))) throw new Error('统计返回格式错误')
    data.value = response.data
    if (!selection.value) {
      const choices = group.value === 'user' ? filterTrafficUsers(data.value.users) : filterTrafficNodes(data.value.nodes)
      options.value = choices.map(({key, name}) => ({key, name}))
    }
  } catch(e) {if (id === request) error.value = e.message || '加载失败'}
  finally {if (id === request) loading.value = false}
}
watch(() => [props.modelValue,props.period], ([open]) => {
  if (!open) {request++; cancelRead(); loading.value = false; return}
  interval.value = props.period === 'today' ? 'hour' : props.period === 'all' ? 'month' : 'day'
  group.value = 'node'; selection.value = ''; detailNode.value = ''; options.value = []; dates.value = []; tab.value = 'trend'; chartType.value = 'bar'; load()
}, { flush: 'sync', immediate: true })
function changeInterval() {dates.value = []; load()}
function changeGroup() {selection.value = ''; detailNode.value = ''; options.value = []; search.value = ''; dates.value = []; tab.value = 'trend'; expanded.value = false; load()}
</script>
<template>
  <el-dialog :model-value="modelValue" :title="title" width="min(960px, 94vw)" append-to-body destroy-on-close class="dashboard-traffic-dialog" @closed="releaseClosedHistory" @update:model-value="emit('update:modelValue',$event)">
    <div class="traffic-analysis" v-loading="loading">
      <el-alert v-if="error" :title="error" type="error" :closable="false"><el-button size="small" @click="load">重试</el-button></el-alert>
      <div class="analysis-tools">
        <el-radio-group v-model="group" size="small" aria-label="流量分组" @change="changeGroup"><el-radio-button value="node">按节点</el-radio-button><el-radio-button value="user">按用户</el-radio-button></el-radio-group>
        <el-radio-group v-model="chartType" size="small"><el-radio-button value="bar">柱状</el-radio-button><el-radio-button value="line">折线</el-radio-button></el-radio-group>
        <el-radio-group v-model="interval" size="small" @change="changeInterval"><el-radio-button v-if="period === 'today'" value="hour">按小时</el-radio-button><el-radio-button v-else value="day">按天</el-radio-button><el-radio-button v-if="period !== 'today'" value="month">按月</el-radio-button></el-radio-group>
        <ElSelect v-model="selection" filterable class="node-select" :placeholder="group === 'user' ? '全部用户' : '全部节点'" :aria-label="group === 'user' ? '筛选流量用户' : '筛选流量节点'" @change="changeSelection">
          <ElOption value="" :label="group === 'user' ? '全部用户' : '全部节点'" />
          <ElOption v-for="item in options" :key="item.key" :value="item.key" :label="item.name" />
        </ElSelect>
        <ElSelect v-if="userNodeDetails" v-model="detailNode" filterable class="node-select" placeholder="该用户全部节点" aria-label="筛选该用户的流量节点">
          <ElOption value="" label="该用户全部节点" />
          <ElOption v-for="item in detailNodeOptions" :key="item.key" :value="item.key" :label="item.name" />
        </ElSelect>
        <el-date-picker v-if="interval === 'day'" v-model="dates" type="daterange" value-format="YYYY-MM-DD" start-placeholder="开始日期" end-placeholder="结束日期" class="range-select" />
      </div>
      <template v-if="!loading && !error">
        <div class="analysis-summary"><strong>{{bytes(displayTotal)}}</strong><span v-if="rows.length">{{rows[0].date}} 至 {{rows.at(-1).date}}</span></div>
        <p v-if="group === 'user' && period === 'all'" class="analysis-note">用户图表仅包含仍保留日期明细的流量；已归档但无日期的流量不参与柱状图。</p>
        <div class="analysis-chart"><MultiTrafficChart :series="series" :labels="labels" :height="240" fit-container zoomable :chart-type="chartType" :tooltip-formatter="tooltip" empty-text="所选范围暂无统计" /></div>
        <div v-if="series.length" class="node-legend">
          <el-button size="small" :type="(userNodeDetails ? !detailNode : !selection) ? 'primary' : 'default'" plain @click="clearChartSelection">{{userNodeDetails ? '全部节点' : group === 'user' ? '全部用户' : '全部节点'}}</el-button>
          <button v-for="item in legend" :key="item.key" type="button" @click="focusItem(item.key)"><i :style="{background:item.color}"/><strong>{{item.name}}</strong><span>{{bytes(item.points.reduce((sum,p)=>sum+p.total,0))}}</span></button>
          <el-button v-if="series.length > 6" text size="small" @click="expanded = !expanded">{{expanded ? '收起' : `+${series.length-6}`}}</el-button>
        </div>
        <el-tabs v-model="tab">
          <el-tab-pane label="流量明细" name="trend"/>
          <el-tab-pane :label="userNodeDetails ? '节点明细' : group === 'user' ? '用户分布' : '节点分布'" name="distribution"/>
        </el-tabs>
        <el-input v-if="tab === 'distribution'" v-model="search" :placeholder="group === 'user' && !userNodeDetails ? '搜索用户邮箱或编号' : '搜索节点名称或编号'" clearable class="node-search"/>
        <el-table :data="tableRows" max-height="300" stripe empty-text="暂无统计">
          <el-table-column v-if="tab === 'trend'" prop="date" label="时间" min-width="155"/>
          <el-table-column v-else prop="name" :label="group === 'user' && !userNodeDetails ? '用户' : '节点名称'" min-width="190" show-overflow-tooltip/>
          <el-table-column label="上传" min-width="100" align="right"><template #default="{row}">{{bytes(row.upload)}}</template></el-table-column>
          <el-table-column label="下载" min-width="100" align="right"><template #default="{row}">{{bytes(row.download)}}</template></el-table-column>
          <el-table-column label="总流量" min-width="110" align="right"><template #default="{row}"><strong>{{bytes(row.upload+row.download)}}</strong></template></el-table-column>
        </el-table>
        <el-pagination v-if="tableCount > 20" v-model:current-page="page" :page-size="20" :total="tableCount" :pager-count="5" layout="total, prev, pager, next" size="small"/>
      </template>
    </div>
  </el-dialog>
</template>
<style scoped>
.node-legend{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}.node-legend>button:not(.el-button){display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid var(--line);border-radius:9px;background:transparent;cursor:pointer;color:var(--text);font:inherit;font-size:12px;max-width:100%}.node-legend i{width:9px;height:9px;border-radius:2px;flex-shrink:0}.node-legend strong{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.node-legend span{color:var(--muted);white-space:nowrap}
.analysis-tools{display:flex;align-items:center;flex-wrap:wrap;gap:10px}.node-select{flex:1;min-width:180px}.range-select{max-width:100%;flex:1 1 280px!important}.analysis-summary{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin:18px 0 12px}.analysis-summary strong{font-size:24px;color:var(--primary)}.analysis-summary span{font-size:12px;color:var(--muted)}.analysis-chart{padding:8px;border:1px solid var(--line);border-radius:16px;background:var(--el-bg-color)}.node-search{max-width:320px;margin-bottom:12px}.el-pagination{margin-top:12px;justify-content:flex-end}.traffic-analysis{min-height:220px}
.analysis-note{margin:-4px 0 12px;color:var(--muted);font-size:12px}
@media(max-width:600px){.node-select{flex-basis:100%}.analysis-chart{padding:0}.analysis-summary{gap:8px}.analysis-summary span{flex-basis:100%}}
</style>
