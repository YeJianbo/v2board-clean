<script setup>
import { ref, shallowRef, computed, defineAsyncComponent, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { toPlan } from '../utils/crossLink'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search, RefreshCw, Download, Plus, SlidersHorizontal, Mail, PlusCircle, X, HelpCircle } from 'lucide-vue-next'
import SectionCard from '../components/common/SectionCard.vue'
import UserActionsMenu from '../components/users/UserActionsMenu.vue'
const MultiTrafficChart = defineAsyncComponent(() => import('../components/common/MultiTrafficChart.vue'))
import { userTrafficChartSeries, trafficNodeColor } from '../utils/userTrafficChart'
import {
  fetchManagedUsers,
  generateManagedUserLoginUrl,
  updateManagedUser,
  generateManagedUser,
  fetchUserGenerationStatus,
  downloadGeneratedUsers,
  banManagedUsers,
  resetManagedUserSecret,
  destroyManagedUser,
  dumpUsersCSV,
  sendMailToUsers,
  createEmptyManagedUsersPagination,
  fetchUserNodeTrafficStats,
  fetchUserNodeTrafficSeriesStats,
  fetchUserTrafficStats,
} from '../services/users'
import { fetchManagedPlanOptions, PERIOD_LABELS } from '../services/plans'
import { assignOrder } from '../services/orders'
import { createSequence } from '../utils/sequence'
import { copyText } from '../utils/clipboard'
import { userEditPatch, userTrafficBytes } from '../utils/userEditPatch'
import { assignmentAmountCents } from '../utils/orderAmount'
import { captureUserFilterQuery, buildUserFilters, userFilterLabel } from '../utils/userFilterQuery'

const router = useRouter()
const props = defineProps({ editorUserId: { type: [Number, String], default: 0 } })
const emit = defineEmits(['editor-close', 'editor-saved'])
let editorDisposed = false
let exportReadController = null, generationDownloadController = null
onBeforeUnmount(() => {
  editorDisposed = true
  exportReadController?.abort()
  generationDownloadController?.abort()
})
let editOriginal = {}
const route = useRoute()
const USER_ACTIONS_COLUMN_WIDTH = 104

const users = ref([])
const pagination = ref(createEmptyManagedUsersPagination())
const loading = ref(false)
const loginUrlLoading = ref(false)
const errorMsg = ref('')
const searchKeyword = ref('')
const showFilters = ref(false)
const appliedFilters = ref([])
const showUserSecondaryColumns = ref(false)
const appliedFilterLabels = computed(() => appliedFilters.value.map(item => userFilterLabel(item, filterFieldOptions.value, formatTrafficBytes)))
const filterConditions = ref([])
const filterQuery = ref(captureUserFilterQuery())
const filtersDirty = computed(() => JSON.stringify(buildFilterArray()) !== JSON.stringify(buildUserFilters(filterQuery.value, filterFieldOptions.value)))
const usersLoaded = ref(false)
const canMailFiltered = computed(() => usersLoaded.value && appliedFilters.value.length > 0 && pagination.value.total > 0 && !loading.value && !errorMsg.value)

const sendMailDialogVisible = ref(false)
const sendMailForm = ref({ subject: '', content: '' })
const sendMailSending = ref(false)
const sendMailScope = ref('all') // 'all' or 'filter'
const sendMailFilterSnapshot = ref([])
const sendMailMatchCount = ref(0)
const sendMailFilterLabels = computed(() => sendMailFilterSnapshot.value.map(item => userFilterLabel(item, filterFieldOptions.value, formatTrafficBytes)).join('；'))

const assignDialogVisible = ref(false)
const assignSaving = ref(false)
const assignForm = ref({
  email: '',
  planId: null,
  period: '',
  totalAmount: 0,
})

function formatResetCountdown(timestamp) {
  if (!timestamp) return ''
  const diffMs = timestamp * 1000 - Date.now()
  if (diffMs <= 0) return '待重置'
  const totalMinutes = Math.floor(diffMs / 60000)
  const days = Math.floor(totalMinutes / (60 * 24))
  if (days >= 1) return `${days} 天后`
  const hours = Math.floor(totalMinutes / 60)
  if (hours >= 1) return `${hours} 小时后`
  return `${totalMinutes} 分钟后`
}

const trafficDialogVisible = ref(false)
const trafficLoading = ref(false)
const nodeTrafficLoading = ref(false)
const nodeTrafficSeriesLoading = ref(false)
const trafficActiveTab = ref('daily')
const trafficData = shallowRef([])
const nodeTrafficData = shallowRef([])
const nodeTrafficSeries = shallowRef([])
const nodeTrafficLabels = shallowRef([])
const nodeTrafficGranularity = ref('day')
const nodeTrafficChartType = ref('bar')
const nodeTrafficError = ref('')
const nodeTrafficMeta = shallowRef(null)
const nodeTrafficActualRange = computed(() => {
  const meta = nodeTrafficMeta.value
  if (!meta?.startAt || !meta?.endAt) return ''
  const format = stamp => new Date(Number(stamp) * 1000).toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false })
  return `${format(meta.startAt)} 至 ${format(meta.endAt)}`
})
const expandedTrafficNodes = ref(false)
const displayedTrafficNodes = computed(() => expandedTrafficNodes.value ? nodeTrafficSummary.value :
  nodeTrafficSummary.value.filter((item, index) => index < 6 || selectedNodeTrafficKeys.value.includes(item.key)))
const nodeTrafficSummary = computed(() => {
  const rows = nodeTrafficSeries.value.filter(item => item.key !== 'total').map(item => ({ ...item, bytes: Number(item.cost ?? item.total ?? 0), color: trafficNodeColor(item.key) }))
    .filter(item => item.bytes > 0).sort((a, b) => b.bytes - a.bytes)
  const total = rows.reduce((sum, item) => sum + item.bytes, 0)
  return rows.map(item => ({ ...item, share: total ? item.bytes / total * 100 : 0 }))
})
const currentTrafficUserLabel = ref('')
let dailyRequest = 0, nodeRequest = 0, seriesRequest = 0
let dailyReadController, nodeReadController, seriesReadController
const dailyTrafficLoaded = ref(false), nodeTrafficLoaded = ref(false)
function cancelTrafficReads() {
  dailyRequest++; nodeRequest++; seriesRequest++
  dailyReadController?.abort(); nodeReadController?.abort(); seriesReadController?.abort()
  dailyReadController = nodeReadController = seriesReadController = null
  trafficLoading.value = nodeTrafficLoading.value = nodeTrafficSeriesLoading.value = false
}
onBeforeUnmount(cancelTrafficReads)
watch(trafficDialogVisible, visible => { if (!visible) cancelTrafficReads() }, { flush: 'sync' })
function releaseClosedTraffic() {
  if (trafficDialogVisible.value) return
  trafficData.value = []; nodeTrafficData.value = []; nodeTrafficSeries.value = []; nodeTrafficLabels.value = []
  nodeTrafficMeta.value = null
  dailyTrafficLoaded.value = nodeTrafficLoaded.value = false
}
watch(trafficActiveTab, activeTab => {
  if (!trafficDialogVisible.value) return
  if (activeTab === 'daily' && !dailyTrafficLoaded.value && !trafficLoading.value) loadTrafficData()
  if (activeTab === 'nodes' && !nodeTrafficLoaded.value && !nodeTrafficLoading.value) loadNodeTrafficData()
})
function selectTrafficPeriod(days) {
  const end = new Date()
  const start = new Date(end)
  start.setDate(start.getDate() - days + 1)
  start.setHours(0, 0, 0, 0)
  trafficTimeRange.value = [start, end]
  handleTrafficTimeRangeChange()
}
const selectedNodeTrafficKeys = ref(['ALL'])
const nodeTrafficMinimumValue = ref(undefined)
const nodeTrafficMinimumUnit = ref('KB')
const nodeTrafficTablePagination = ref({ page: 1, pageSize: 10, total: 0 })
const trafficPagination = ref({ page: 1, pageSize: 10, total: 0 })
const currentTrafficUserId = ref(null)
const trafficTimeRange = ref([])

function formatTrafficBytes(value) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  let size = Number(value || 0)
  let unitIndex = 0

  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024
    unitIndex += 1
  }

  return `${size.toFixed(2)} ${units[unitIndex]}`
}

const nodeTrafficMinimumBytes = computed(function getNodeTrafficMinimumBytes() {
  const value = Number(nodeTrafficMinimumValue.value)
  if (!Number.isFinite(value) || value <= 0) {
    return 0
  }

  const multipliers = {
    B: 1,
    KB: 1024,
    MB: 1024 ** 2,
    GB: 1024 ** 3,
  }

  return value * (multipliers[nodeTrafficMinimumUnit.value] || 1)
})

function normalizeTrafficTimestamp(value, endOfDay = false) {
  if (!value) return ''

  let date
  if (value instanceof Date) {
    date = new Date(value.getTime())
  } else if (typeof value === 'number') {
    date = new Date(value > 9999999999 ? value : value * 1000)
  } else {
    date = new Date(value)
  }

  if (Number.isNaN(date.getTime())) return ''

  if (
    endOfDay
    && date.getHours() === 0
    && date.getMinutes() === 0
    && date.getSeconds() === 0
    && date.getMilliseconds() === 0
  ) {
    date.setHours(23, 59, 59, 999)
  }

  return Math.floor(date.getTime() / 1000)
}

function getTrafficTimeParams() {
  const range = Array.isArray(trafficTimeRange.value) ? trafficTimeRange.value : []
  return {
    startTime: normalizeTrafficTimestamp(range[0]),
    endTime: range[1] ? Math.min(normalizeTrafficTimestamp(range[1], true), Math.floor(Date.now() / 1000)) : '',
  }
}

function getNodeTrafficSeriesTimeParams() {
  const params = getTrafficTimeParams()
  if (params.startTime || params.endTime) {
    return params
  }

  if (nodeTrafficGranularity.value !== 'day') {
    return params
  }

  const now = new Date()
  const start = new Date(now.getTime() - 6 * 24 * 60 * 60 * 1000)

  return {
    startTime: normalizeTrafficTimestamp(start),
    endTime: normalizeTrafficTimestamp(now),
  }
}

const nodeTrafficSeriesOptions = computed(function buildNodeTrafficSeriesOptions() {
  return [
    { label: '全部节点', value: 'ALL' },
    ...nodeTrafficData.value.map(function mapNode(item) {
      return {
        label: `${item.name} · ${item.type}`,
        value: item.key,
      }
    }),
  ]
})

const visibleNodeTrafficSeries = computed(function buildVisibleNodeTrafficSeries() {
  const selected = Array.isArray(selectedNodeTrafficKeys.value) && selectedNodeTrafficKeys.value.length > 0
    ? selectedNodeTrafficKeys.value
    : ['ALL']
  return userTrafficChartSeries(nodeTrafficSeries.value, selected)
})

const nodeTrafficRateMap = computed(function buildNodeTrafficRateMap() {
  return new Map(nodeTrafficData.value.map(function mapNode(item) {
    return [item.key, item.rateText || item.rate || '--']
  }))
})

const nodeTrafficNodeMap = computed(function buildNodeTrafficNodeMap() {
  return new Map(nodeTrafficData.value.map(function mapNode(item) {
    return [item.key, item]
  }))
})

const nodeTrafficTableRows = computed(function buildNodeTrafficTableRows() {
  const selected = Array.isArray(selectedNodeTrafficKeys.value) && selectedNodeTrafficKeys.value.length > 0
    ? selectedNodeTrafficKeys.value
    : ['ALL']
  const selectedSet = new Set(selected.filter(function filterNodeKey(value) {
    return value !== 'ALL'
  }))
  const seriesList = (Array.isArray(nodeTrafficSeries.value) ? nodeTrafficSeries.value : []).filter(function filterSeries(item) {
    if (item.key === 'total') {
      return false
    }

    if (selected.includes('ALL')) {
      return true
    }

    return selectedSet.has(item.key)
  })
  if (!seriesList.length) {
    return []
  }

  return seriesList.flatMap(function mapSeries(seriesItem) {
    const summaryNode = nodeTrafficNodeMap.value.get(seriesItem.key)
    const rateText = nodeTrafficRateMap.value.get(seriesItem.key) || summaryNode?.rateText || '--'
    const nodeName = seriesItem.name || summaryNode?.name || '--'
    const nodeType = seriesItem.type || summaryNode?.type || '--'

    return (Array.isArray(seriesItem.points) ? seriesItem.points : []).filter(function filterPoint(point) {
      const totalBytes = Number(point?.cost ?? point?.total ?? 0)
      return totalBytes > 0 && totalBytes >= nodeTrafficMinimumBytes.value
    }).map(function mapPoint(point) {
      const totalBytes = Number(point?.cost ?? point?.total ?? 0)
      return {
        key: `${seriesItem.key}:${point.timestamp}`,
        timestamp: Number(point.timestamp || 0),
        time: point.label || '--',
        name: nodeName,
        type: nodeType,
        rateText,
        uText: point.uText || '--',
        dText: point.dText || '--',
        totalText: point.totalText || '--',
        totalBytes,
        actualTotalText: point.actualTotalText || '--',
      }
    })
  }).sort(function sortRows(a, b) {
    if (a.timestamp !== b.timestamp) {
      return b.timestamp - a.timestamp
    }
    return String(a.name || '').localeCompare(String(b.name || ''), 'zh-CN')
  })
})

const pagedNodeTrafficTableRows = computed(function buildPagedNodeTrafficTableRows() {
  const page = Math.max(Number(nodeTrafficTablePagination.value.page || 1), 1)
  const pageSize = Math.max(Number(nodeTrafficTablePagination.value.pageSize || 10), 1)
  const start = (page - 1) * pageSize
  return nodeTrafficTableRows.value.slice(start, start + pageSize)
})

watch(nodeTrafficTableRows, function syncNodeTrafficTablePagination(rows) {
  const total = Array.isArray(rows) ? rows.length : 0
  nodeTrafficTablePagination.value.total = total
  const pageSize = Math.max(Number(nodeTrafficTablePagination.value.pageSize || 10), 1)
  const maxPage = Math.max(Math.ceil(total / pageSize), 1)
  if (nodeTrafficTablePagination.value.page > maxPage) {
    nodeTrafficTablePagination.value.page = maxPage
  }
}, { immediate: true })

watch([nodeTrafficMinimumValue, nodeTrafficMinimumUnit], function resetNodeTrafficTablePage() {
  nodeTrafficTablePagination.value.page = 1
})

async function loadTrafficData() {
  if (!currentTrafficUserId.value || !trafficDialogVisible.value) return
  const requestId = ++dailyRequest
  dailyReadController?.abort()
  dailyReadController = new AbortController()
  const signal = dailyReadController.signal
  trafficLoading.value = true
  try {
    const result = await fetchUserTrafficStats(currentTrafficUserId.value, {
      page: trafficPagination.value.page,
      pageSize: trafficPagination.value.pageSize,
      ...getTrafficTimeParams(),
      signal,
    })
    if (requestId !== dailyRequest) return
    trafficData.value = result.list
    trafficPagination.value = result.pagination
    dailyTrafficLoaded.value = true
  } catch (err) {
    if (requestId === dailyRequest && !signal.aborted) ElMessage.error(err.message || '加载流量详情失败')
  } finally {
    if (requestId === dailyRequest) trafficLoading.value = false
  }
}

async function loadNodeTrafficData() {
  if (!currentTrafficUserId.value || !trafficDialogVisible.value) return
  const requestId = ++nodeRequest
  ++seriesRequest
  nodeReadController?.abort(); seriesReadController?.abort()
  nodeReadController = new AbortController()
  const signal = nodeReadController.signal
  nodeTrafficSeriesLoading.value = false
  nodeTrafficLoading.value = true
  nodeTrafficError.value = ''
  nodeTrafficSeries.value = []; nodeTrafficLabels.value = []; nodeTrafficMeta.value = null
  try {
    const result = await fetchUserNodeTrafficStats(currentTrafficUserId.value, { ...getTrafficTimeParams(), signal })
    if (requestId !== nodeRequest) return
    nodeTrafficData.value = result.list
    const validKeys = new Set(['ALL', ...result.list.map(function mapNode(item) { return item.key })])
    const nextSelection = selectedNodeTrafficKeys.value.filter(function filterSelected(key) {
      return validKeys.has(key)
    })
    selectedNodeTrafficKeys.value = nextSelection.length ? nextSelection : ['ALL']
    nodeTrafficLoaded.value = true
    await loadUserNodeTrafficSeries()
  } catch (err) {
    if (requestId === nodeRequest && !signal.aborted) {
      nodeTrafficData.value = []
      nodeTrafficError.value = err.message || '加载节点流量失败'
    }
  } finally {
    if (requestId === nodeRequest) nodeTrafficLoading.value = false
  }
}

async function loadUserNodeTrafficSeries() {
  if (!currentTrafficUserId.value || !trafficDialogVisible.value) return
  const requestId = ++seriesRequest
  seriesReadController?.abort()
  seriesReadController = new AbortController()
  const signal = seriesReadController.signal
  nodeTrafficSeriesLoading.value = true
  nodeTrafficError.value = ''
  nodeTrafficSeries.value = []; nodeTrafficLabels.value = []; nodeTrafficMeta.value = null
  try {
    const result = await fetchUserNodeTrafficSeriesStats(currentTrafficUserId.value, {
      granularity: nodeTrafficGranularity.value,
      nodeKeys: [],
      includeTotal: false,
      ...getNodeTrafficSeriesTimeParams(),
      signal,
    })
    if (requestId !== seriesRequest || signal.aborted) return
    nodeTrafficLabels.value = result.labels
    nodeTrafficSeries.value = result.series
    nodeTrafficMeta.value = result.meta
  } catch (err) {
    if (requestId !== seriesRequest) return
    nodeTrafficLabels.value = []
    nodeTrafficSeries.value = []
    nodeTrafficError.value = err.message || '加载节点流量曲线失败'
  } finally {
    if (requestId === seriesRequest) nodeTrafficSeriesLoading.value = false
  }
}

function handleViewTraffic(row) {
  currentTrafficUserId.value = row.id
  currentTrafficUserLabel.value = row.email || `用户 #${row.id}`
  trafficData.value = []; nodeTrafficData.value = []; nodeTrafficSeries.value = []; nodeTrafficLabels.value = []
  trafficActiveTab.value = 'nodes'
  nodeTrafficChartType.value = 'bar'
  expandedTrafficNodes.value = false
  trafficPagination.value.page = 1
  nodeTrafficTablePagination.value.page = 1
  nodeTrafficGranularity.value = 'day'
  selectedNodeTrafficKeys.value = ['ALL']
  nodeTrafficMinimumValue.value = undefined
  nodeTrafficMinimumUnit.value = 'KB'
  trafficDialogVisible.value = true
  selectTrafficPeriod(7)
}

function handleTrafficPageChange(page) {
  trafficPagination.value.page = page
  loadTrafficData()
}

function handleNodeTrafficSelectionChange(value) {
  nodeTrafficTablePagination.value.page = 1
  const nextValue = Array.isArray(value) ? value.filter(Boolean) : []
  if (!nextValue.length) {
    selectedNodeTrafficKeys.value = ['ALL']
  } else if (nextValue.includes('ALL') && nextValue.length > 1) {
    selectedNodeTrafficKeys.value = nextValue[nextValue.length - 1] === 'ALL'
      ? ['ALL']
      : nextValue.filter(function filterNodeKey(item) { return item !== 'ALL' })
  } else {
    selectedNodeTrafficKeys.value = nextValue
  }
}

function handleNodeTrafficGranularityChange() {
  nodeTrafficTablePagination.value.page = 1
  loadUserNodeTrafficSeries()
}

function handleTrafficTimeRangeChange() {
  cancelTrafficReads()
  dailyTrafficLoaded.value = nodeTrafficLoaded.value = false
  trafficData.value = []; nodeTrafficData.value = []
  trafficPagination.value.page = 1
  nodeTrafficTablePagination.value.page = 1
  if (trafficActiveTab.value === 'daily') loadTrafficData()
  else loadNodeTrafficData()
}

function handleNodeTrafficTablePageChange(page) {
  nodeTrafficTablePagination.value.page = page
}

function formatNodeTrafficTooltip(seriesItem, point) {
  return {
    title: seriesItem?.name || '--',
    lines: [
      `时间：${point?.label || '--'}`,
      `计费：${point?.totalText || '--'}`,
      `上行：${point?.uText || '--'}`,
      `下行：${point?.dText || '--'}`,
    ],
  }
}

function getAssignPeriodOptions(planId) {
  if (!planId) return []
  const plan = plans.value.find(p => p.id === planId)
  if (!plan) return []
  const result = []
  Object.keys(PERIOD_LABELS).forEach(function mapKey(key) {
    if (plan.prices[key] !== null && plan.prices[key] !== undefined) {
      result.push({ value: key, label: PERIOD_LABELS[key], price: plan.prices[key] })
    }
  })
  return result
}
const periodOptions = computed(() => getAssignPeriodOptions(assignForm.value.planId))

// 静态部分提到模块作用域，避免每次 reactivity tick 都重建 16 个 option 对象 +
// 给 plans 做一次 .map。只有 plan_id 这一项依赖响应式 plans，单独合并。
const FILTER_FIELD_STATIC = Object.freeze([
  { id: 'email', label: '邮箱', type: 'text', operators: ['模糊', '精确'] },
  { id: 'id', label: '用户ID', type: 'number', operators: ['等于', '大于', '小于'] },
  // plan_id 见下方
  { id: 'transfer_enable', label: '流量', type: 'number', operators: ['大于', '小于', '等于'] },
  { id: 'd', label: '已用流量', type: 'number', operators: ['大于', '小于', '等于'] },
  { id: 'online_count', label: '在线设备', type: 'number', operators: ['大于', '小于', '等于'] },
  { id: 'expired_at', label: '到期时间', type: 'date', operators: ['早于', '晚于'] },
  { id: 'next_reset_at', label: '下次重置', type: 'date', operators: ['早于', '晚于'] },
  { id: 'last_reset_at', label: '上次重置', type: 'date', operators: ['早于', '晚于'] },
  { id: 'uuid', label: 'UUID', type: 'text', operators: ['精确'] },
  { id: 'token', label: 'Token', type: 'text', operators: ['精确'] },
  { id: 'banned', label: '账号状态', type: 'select', operators: ['等于'], selectOptions: [{ label: '正常', value: '0' }, { label: '已封禁', value: '1' }] },
  { id: 'remarks', label: '备注', type: 'text', operators: ['模糊'] },
  { id: 'invite_user.email', label: '邀请人邮箱', type: 'text', operators: ['模糊', '精确'] },
  { id: 'invite_user_id', label: '邀请人ID', type: 'number', operators: ['等于'] },
  { id: 'is_admin', label: '管理员', type: 'select', operators: ['等于'], selectOptions: [{ label: '是', value: '1' }, { label: '否', value: '0' }] },
  { id: 'is_staff', label: '员工', type: 'select', operators: ['等于'], selectOptions: [{ label: '是', value: '1' }, { label: '否', value: '0' }] },
])

const filterFieldOptions = computed(() => {
  const planEntry = {
    id: 'plan_id',
    label: '订阅',
    type: 'select',
    operators: ['等于'],
    selectOptions: plans.value.map(p => ({ label: p.name, value: String(p.id) })),
  }
  // 插在 'id' 之后（保留原先位置）
  return [FILTER_FIELD_STATIC[0], FILTER_FIELD_STATIC[1], planEntry, ...FILTER_FIELD_STATIC.slice(2)]
})

function getFieldDef(fieldId) {
  return filterFieldOptions.value.find(f => f.id === fieldId)
}

function onFilterFieldChange(cond) {
  cond.value = ''
  const def = getFieldDef(cond.field)
  if (def?.operators?.length === 1) {
    cond.operator = def.operators[0]
  } else {
    cond.operator = ''
  }
}

// 用 perf-now 单调时间戳 + 随机数生成稳定唯一 id —— 避免 crypto.randomUUID
// 在非 secure context 不可用。v-for :key 用稳定 id 而不是 index，
// 防止删除中间项时 v-model 串到下一行。
let nextFilterCondId = 1
function makeFilterCondId() {
  nextFilterCondId += 1
  return `fc-${nextFilterCondId}-${Math.random().toString(36).slice(2, 6)}`
}

function addFilterCondition() {
  filterConditions.value.push({ id: makeFilterCondId(), field: '', operator: '', value: '' })
}

function removeFilterCondition(index) {
  filterConditions.value.splice(index, 1)
}

function buildFilterArray() {
  return buildUserFilters(captureUserFilterQuery(searchKeyword.value, filterConditions.value), filterFieldOptions.value)
}

function captureFilterQuery() {
  filterQuery.value = captureUserFilterQuery(searchKeyword.value, filterConditions.value)
}

function discardFilterDraft() {
  const query = captureUserFilterQuery(filterQuery.value.keyword, filterQuery.value.conditions)
  searchKeyword.value = query.keyword
  filterConditions.value = query.conditions
}

const editDialogVisible = ref(false)
const editForm = ref({})
const editSaving = ref(false)
const editFormRef = ref(null)
const editRules = {
  email: [
    { required: true, message: '请输入邮箱', trigger: 'blur' },
    { type: 'email', message: '邮箱格式不合法', trigger: 'blur' },
  ],
}
const plans = ref([])

const generateDialogVisible = ref(false)
const generateForm = ref({
  emailPrefix: '',
  emailSuffix: 'gmail.com',
  password: '',
  planId: null,
  expiredAt: null,
  generateCount: null,
})
const generateSaving = ref(false)
const generationTask = ref(null)
const generationStatusError = ref('')
const generationDownloading = ref(false)
const generationSubmitUncertain = ref(false)
const generationActive = computed(() => ['queued', 'running'].includes(generationTask.value?.state))
const generationBusy = computed(() => generateSaving.value || generationActive.value || generationSubmitUncertain.value)
let generationTimer, generationReadController, generationReadVersion = 0
let generationSubmission = null
let generationLastCompleted = ''

function stopGenerationRead() {
  clearTimeout(generationTimer)
  generationReadVersion++
  generationReadController?.abort()
  generationReadController = null
}

function acceptGenerationTask(task) {
  generationTask.value = task
  generationStatusError.value = ''
  if (task?.state === 'completed' && generationLastCompleted !== task.batch_id) {
    generationLastCompleted = task.batch_id
    loadUsers()
  }
}

async function readGenerationStatus() {
  if (editorDisposed || props.editorUserId || document.hidden || generateSaving.value || generationReadController) return
  const version = generationReadVersion
  const controller = new AbortController()
  generationReadController = controller
  try {
    const task = await fetchUserGenerationStatus({ signal: controller.signal })
    if (editorDisposed || version !== generationReadVersion) return
    acceptGenerationTask(task)
    if (task && generationSubmitUncertain.value && task.request_id === generationSubmission?.request_id) {
      generationSubmission = null
      generationSubmitUncertain.value = false
      generateDialogVisible.value = false
    }
  } catch (error) {
    if (!controller.signal.aborted && version === generationReadVersion) generationStatusError.value = error.message || '任务状态暂时无法读取'
  } finally {
    if (generationReadController === controller) generationReadController = null
    if (!editorDisposed && version === generationReadVersion && !document.hidden && (generationActive.value || generationStatusError.value || generationSubmitUncertain.value)) {
      generationTimer = setTimeout(readGenerationStatus, generationStatusError.value ? 10000 : 3000)
    }
  }
}

function generationVisibilityChanged() {
  stopGenerationRead()
  if (!document.hidden) readGenerationStatus()
}
onMounted(() => {
  if (!props.editorUserId) {
    document.addEventListener('visibilitychange', generationVisibilityChanged)
    readGenerationStatus()
  }
})
onBeforeUnmount(() => {
  stopGenerationRead()
  document.removeEventListener('visibilitychange', generationVisibilityChanged)
})

async function downloadGenerationResult() {
  if (editorDisposed || generationDownloading.value || generationTask.value?.state !== 'completed') return
  generationDownloading.value = true
  const controller = new AbortController()
  generationDownloadController = controller
  try { await downloadGeneratedUsers(generationTask.value.batch_id, { signal: controller.signal }) }
  catch (error) { if (!editorDisposed && !controller.signal.aborted) ElMessage.error(error.message || '下载失败') }
  finally { generationDownloadController = null; generationDownloading.value = false }
}
const generateFormRef = ref(null)
const generateRules = {
  emailSuffix: [{ required: true, message: '请填写邮箱后缀（如 gmail.com）', trigger: 'blur' }],
}

const sendMailFormRef = ref(null)
const sendMailRules = {
  subject: [{ required: true, message: '请输入邮件主题', trigger: 'blur' }],
  content: [{ required: true, message: '请输入邮件内容', trigger: 'blur' }],
}

const assignFormRef = ref(null)
const assignRules = {
  planId: [{ required: true, message: '请选择订阅计划', trigger: 'change' }],
  period: [{ required: true, message: '请选择购买时长', trigger: 'change' }],
  totalAmount: [
    {
      validator: (_r, v, cb) => {
        try { assignmentAmountCents(v); cb() } catch (error) { cb(error) }
      },
      trigger: 'blur',
    },
  ],
}

const sortField = ref('')
const sortOrder = ref('desc')
const sortOptions = [
  { label: '默认', value: '' },
  { label: '已用流量', value: 'd' },
  { label: '到期时间', value: 'expired_at' },
  { label: '下次重置', value: 'next_reset_at' },
  { label: '余额', value: 'balance' },
  { label: '佣金', value: 'commission_balance' },
  { label: '在线设备', value: 'online_count' },
]

const userSortProps = { d: 'totalUsedRaw', expired_at: 'expiredAtRaw', next_reset_at: 'nextResetAtRaw', balance: 'balance', commission_balance: 'commissionBalance', online_count: 'onlineCount' }
const tableSort = computed(() => ({ prop: userSortProps[sortField.value] || '', order: sortField.value ? (sortOrder.value === 'asc' ? 'ascending' : 'descending') : null }))
function handleUserAction(action, row) {
  const actions = { edit: openEditDialog, traffic: handleViewTraffic, orders: navigateToUserOrders, tickets: navigateToUserTickets, invites: navigateToUserInvites, assign: handleAssignOrder, subscribe: copySubscribeUrl, login: copyLoginUrl, 'reset-traffic': handleResetTraffic, 'reset-secret': handleResetSecret, immediate: user => handleResetSecret(user, 'immediate'), revoke: user => handleResetSecret(user, 'revoke'), delete: handleDelete }
  actions[action]?.(row)
}
const USERS_PAGE_STATE_KEY = 'xboard-admin:users-page-state'

function saveUsersPageState() {
  try {
    if (filterQuery.value.conditions.some(item => ['uuid', 'token'].includes(item.field))) {
      sessionStorage.removeItem(USERS_PAGE_STATE_KEY)
      return
    }
    sessionStorage.setItem(USERS_PAGE_STATE_KEY, JSON.stringify({
      searchKeyword: filterQuery.value.keyword,
      filterConditions: filterQuery.value.conditions,
      sortField: sortField.value,
      sortOrder: sortOrder.value,
      page: pagination.value.page,
      pageSize: pagination.value.pageSize,
    }))
  } catch (_) {
    // sessionStorage 不可用时静默忽略
  }
}

function restoreUsersPageState() {
  try {
    const raw = sessionStorage.getItem(USERS_PAGE_STATE_KEY)
    if (!raw) return
    const data = JSON.parse(raw)
    if (typeof data.searchKeyword === 'string') searchKeyword.value = data.searchKeyword
    if (Array.isArray(data.filterConditions)) {
      // 兼容旧版 sessionStorage 没 id 的项 —— 补一个稳定 id
      filterConditions.value = data.filterConditions.map(c => c.id ? c : { ...c, id: makeFilterCondId() })
    }
    if (typeof data.sortField === 'string') sortField.value = data.sortField
    if (typeof data.sortOrder === 'string') sortOrder.value = data.sortOrder
    if (Number.isFinite(data.page)) pagination.value.page = data.page
    if (Number.isFinite(data.pageSize)) pagination.value.pageSize = data.pageSize
  } catch (_) {
    // 反序列化失败时忽略，使用默认状态
  }
}

// Sequence guard：快速切筛选/翻页时，旧响应若回得比新响应晚，
// 之前会覆盖 users / pagination / sessionStorage —— 现在丢弃所有非最新响应。
const usersSeq = createSequence()
let usersReadController
let lastUsersPagination, failedUsersPage
onBeforeUnmount(() => {
  usersSeq.invalidate()
  usersReadController?.abort()
})

async function loadUsers() {
  if (editorDisposed) return
  const requestedPage = { page: pagination.value.page, pageSize: pagination.value.pageSize }
  const my = usersSeq.next()
  usersReadController?.abort()
  usersReadController = new AbortController()
  const signal = usersReadController.signal
  loading.value = true
  errorMsg.value = ''
  try {
    const filter = buildUserFilters(filterQuery.value, filterFieldOptions.value)
    const sort = []
    if (sortField.value) {
      sort.push({ id: sortField.value, desc: sortOrder.value === 'desc' })
    }
    const result = await fetchManagedUsers({
      signal,
      page: pagination.value.page,
      pageSize: pagination.value.pageSize,
      filter,
      sort,
    })
    if (!usersSeq.isCurrent(my)) return
    users.value = result.list
    usersLoaded.value = true
    appliedFilters.value = filter.map(item => ({ ...item }))
    pagination.value = result.pagination
    lastUsersPagination = { ...result.pagination }
    failedUsersPage = null
    // 兜底 clamp：若服务器返回 page > totalPages（往往是 sessionStorage 恢复
    // 到了一个被筛掉的页码），自动回到第 1 页再拉一次，避免"空白页"卡死。
    const totalPages = Math.max(Math.ceil((result.pagination.total ?? 0) / (result.pagination.pageSize ?? 1)), 1)
    if (
      result.pagination.page > totalPages
      && result.pagination.page > 1
      && result.list.length === 0
    ) {
      pagination.value.page = 1
      // 触发下一帧重新拉，避免同一帧内递归
      Promise.resolve().then(() => loadUsers())
      return
    }
    saveUsersPageState()
  } catch (err) {
    if (!usersSeq.isCurrent(my)) return
    errorMsg.value = err.message || '加载用户列表失败'
    failedUsersPage = requestedPage
    if (lastUsersPagination) pagination.value = { ...lastUsersPagination }
  } finally {
    if (usersSeq.isCurrent(my)) loading.value = false
  }
}

function retryUsers() {
  if (failedUsersPage) Object.assign(pagination.value, failedUsersPage)
  loadUsers()
}

function handlePageChange(page) {
  pagination.value.page = page
  loadUsers()
}

function handlePageSizeChange(size) {
  pagination.value.pageSize = size
  pagination.value.page = 1
  loadUsers()
}

function handleSearch() {
  captureFilterQuery()
  handleQuerySort()
}

function handleQuerySort() {
  pagination.value.page = 1
  loadUsers()
}

function handleSortChange({ prop, order }) {
  const fieldMap = {
    totalUsedRaw: 'd',
    expiredAtRaw: 'expired_at',
    nextResetAtRaw: 'next_reset_at',
    balance: 'balance',
    commissionBalance: 'commission_balance',
    onlineCount: 'online_count',
  }
  if (prop && order && fieldMap[prop]) {
    sortField.value = fieldMap[prop]
    sortOrder.value = order === 'ascending' ? 'asc' : 'desc'
  } else {
    sortField.value = ''
    sortOrder.value = 'desc'
  }
  pagination.value.page = 1
  loadUsers()
}

function openEditDialog(user) {
  if (editorDisposed || editSaving.value) return
  editForm.value = {
    id: user.id,
    email: user.email,
    password: '',
    balance: user.balance,
    commission_balance: user.commissionBalance,
    u: user.uploadRaw ? Number((user.uploadRaw / 1073741824).toFixed(2)) : 0,
    d: user.downloadRaw ? Number((user.downloadRaw / 1073741824).toFixed(2)) : 0,
    transfer_enable: user.transferEnableRaw ? user.transferEnableRaw / 1073741824 : 0,
    speed_limit: user.speedLimit,
    device_limit: user.deviceLimit,
    expired_at: user.expiredAtRaw == null ? null : String(user.expiredAtRaw),
    next_reset_at_raw: user.nextResetAtRaw,
    next_reset_at_display: user.nextResetAt,
    last_reset_at_display: user.lastResetAt,
    plan_id: user.planId,
    banned: user.banned,
    is_admin: user.isAdmin ? 1 : 0,
    is_staff: user.isStaff ? 1 : 0,
    commission_type: user.commissionType ?? 0,
    commission_rate: user.commissionRate,
    discount: user.discount,
    remarks: user.remarks,
    invite_user_email: user.inviteUserEmail !== '--' ? user.inviteUserEmail : '',
  }
  editDialogVisible.value = true
  editOriginal = JSON.parse(JSON.stringify(editForm.value))
}
watch(editDialogVisible, (open, previous) => { if (props.editorUserId && previous && !open) emit('editor-close') })

async function saveEditForm() {
  if (editorDisposed || editSaving.value || !editDialogVisible.value) return
  editSaving.value = true
  const draft = { ...editForm.value }, original = { ...editOriginal }
  try {
    if (editFormRef.value) {
      try { await editFormRef.value.validate() } catch { return }
    }
    if (editorDisposed || !editDialogVisible.value) return
    const payload = { ...draft }
    if (!payload.password) {
      delete payload.password
    }
    // 余额/佣金：界面与后端 update 均以「元」交互（后端 UserController::update 会自行 ×100 存「分」）。
    // 前端按原值（元）发送即可，切勿在此再 ×100，否则与后端叠加成 100 倍。
    for (const [key, label] of [['balance', '余额'], ['commission_balance', '佣金余额']]) {
      if (JSON.stringify(draft[key]) === JSON.stringify(original[key])) continue
      const value = draft[key]
      if (value == null || String(value).trim() === '' || !Number.isFinite(Number(value))) throw new Error(`${label}请输入有效金额`)
      payload[key] = Number(value)
    }
    for (const [key, label] of [['transfer_enable', '流量额度'], ['u', '已用上行'], ['d', '已用下行']]) {
      if (JSON.stringify(draft[key]) !== JSON.stringify(original[key])) {
        payload[key] = userTrafficBytes(payload[key], label)
      }
    }
    // Ensure integer types for boolean-like fields
    payload.banned = Number(payload.banned) || 0
    payload.is_admin = Number(payload.is_admin) || 0
    payload.is_staff = Number(payload.is_staff) || 0
    payload.commission_type = Number(payload.commission_type) || 0
    if (payload.commission_rate !== null && payload.commission_rate !== undefined) {
      payload.commission_rate = Number(payload.commission_rate)
    }
    if (payload.discount !== null && payload.discount !== undefined) {
      payload.discount = Number(payload.discount)
    }
    const changed = userEditPatch(payload, draft, original)
    const hasChanges = Object.keys(changed).length > 1
    if (hasChanges) await updateManagedUser(changed)
    if (editorDisposed) return
    if (hasChanges) {
      ElMessage.success('用户信息已更新')
      if (props.editorUserId) emit('editor-saved')
    } else ElMessage.info('用户信息未修改')
    editDialogVisible.value = false
    if (hasChanges && !props.editorUserId) loadUsers()
  } catch (err) {
    if (!editorDisposed) ElMessage.error(err.message || '保存失败')
  } finally {
    editSaving.value = false
  }
}

async function handleBan(user) {
  try {
    await ElMessageBox.confirm(`确定要封禁用户 ${user.email} 吗？`, '封禁用户', {
      type: 'warning',
    })
    await banManagedUsers({ scope: 'selected', user_ids: [user.id] })
    ElMessage.success('用户已封禁')
    loadUsers()
  } catch (err) {
    if (err !== 'cancel' && err !== 'close') {
      ElMessage.error(err.message || '封禁失败')
    }
  }
}

const secretResetIds = ref(new Set())
async function handleResetSecret(user, mode = 'grace') {
  if (editorDisposed || !user?.id || secretResetIds.value.has(user.id)) return
  const target = { id: user.id, email: user.email }
  secretResetIds.value.add(target.id)
  try {
    await ElMessageBox.confirm(
      mode === 'revoke' ? `立即撤销 ${target.email} 的旧订阅链接和旧 UUID？当前的新凭据保持不变。`
        : mode === 'immediate' ? `紧急重置 ${target.email} 的订阅链接和 UUID？旧链接立即失效，旧 UUID 在节点同步后撤销，不保留过渡期。`
          : `重置 ${target.email} 的订阅链接和 UUID？仅支持已升级节点的纯 Ravel 订阅。旧链接只返回旧密钥，保留最多 15 分钟；新旧密钥共享同一用户的限速、设备数和流量额度。重复重置不延长原截止时间，请在期限内导入新链接。`,
      mode === 'revoke' ? '撤销旧凭据' : mode === 'immediate' ? '泄露紧急重置' : '重置订阅（15 分钟过渡）',
      { type: 'warning' },
    )
    if (editorDisposed) return
    const result = await resetManagedUserSecret(target.id, mode)
    if (editorDisposed) return
    const expiry = result?.data?.grace_expires_at
    ElMessage.success(expiry ? `订阅链接和 UUID 已更新，旧凭据截止 ${new Date(expiry * 1000).toLocaleString('zh-CN', { hour12: false })}`
      : mode === 'revoke' ? '旧凭据已撤销，新凭据不变' : '已紧急重置订阅链接和 UUID')
    await loadUsers()
  } catch (err) {
    if (!editorDisposed && err !== 'cancel' && err !== 'close') {
      ElMessage.error(err.message || '重置失败')
    }
  } finally {
    secretResetIds.value.delete(target.id)
  }
}

async function handleDelete(user) {
  if (editorDisposed) return
  try {
    await ElMessageBox.confirm(
      `确定要删除用户 ${user.email} 吗？此操作将同时删除其所有订单、工单等关联数据且不可恢复！`,
      '删除用户',
      { type: 'error' },
    )
    if (editorDisposed) return
    await destroyManagedUser(user.id)
    if (editorDisposed) return
    ElMessage.success('用户已删除')
    loadUsers()
  } catch (err) {
    if (!editorDisposed && err !== 'cancel' && err !== 'close') {
      ElMessage.error(err.message || '删除失败')
    }
  }
}

function handleAssignOrder(user) {
  if (editorDisposed || assignSaving.value) return
  assignForm.value = {
    email: user.email,
    planId: null,
    period: 'month_price',
    totalAmount: 0,
  }
  assignDialogVisible.value = true
}

function onAssignPlanChange() {
  const option = periodOptions.value[0]
  assignForm.value.period = option?.value ?? ''
  assignForm.value.totalAmount = option?.price ?? 0
}

function onAssignPeriodChange(period) {
  const opt = periodOptions.value.find(o => o.value === period)
  if (opt) {
    assignForm.value.totalAmount = opt.price
  }
}

async function submitAssignOrder() {
  if (editorDisposed || assignSaving.value || !assignDialogVisible.value) return
  assignSaving.value = true
  const draft = { ...assignForm.value }
  try {
    if (assignFormRef.value) {
      try { await assignFormRef.value.validate() } catch { return }
    }
    if (editorDisposed || !assignDialogVisible.value) return
    if (!getAssignPeriodOptions(draft.planId).some(option => option.value === draft.period)) {
      throw new Error('请选择当前订阅计划提供的购买时长')
    }
    await assignOrder({
      ...draft,
      totalAmount: assignmentAmountCents(draft.totalAmount),
    })
    if (editorDisposed) return
    ElMessage.success('订单已分配')
    assignDialogVisible.value = false
  } catch (err) {
    if (!editorDisposed) ElMessage.error(err.message || '分配订单失败')
  } finally {
    assignSaving.value = false
  }
}

async function copySubscribeUrl(user) {
  if (!user?.subscribeUrl) {
    ElMessage.warning('该用户没有订阅 URL')
    return
  }
  try {
    await copyText(user.subscribeUrl)
    ElMessage.success('订阅 URL 已复制到剪贴板')
  } catch (e) {
    ElMessage.error('复制失败：' + (e?.message || ''))
  }
}

async function copyLoginUrl(user) {
  if (editorDisposed || loginUrlLoading.value) return
  if (!user?.id) {
    ElMessage.warning('用户不存在')
    return
  }
  loginUrlLoading.value = true
  try {
    const loginUrl = await generateManagedUserLoginUrl(user.id)
    if (editorDisposed) return
    await copyText(loginUrl)
    if (!editorDisposed) ElMessage.success('登录链接已复制，60 秒内有效')
  } catch (e) {
    if (!editorDisposed) ElMessage.error('生成或复制失败：' + (e?.message || ''))
  } finally {
    loginUrlLoading.value = false
  }
}

function navigateToUserOrders(user) {
  // 用具名路由，自动带上 frontendSecurePath 前缀（相对 path 会在改安全路径后失效）
  router.push({ name: 'orders', query: { user_id: user.id, user_email: user.email } })
}

function navigateToUserInvites(user) {
  // Clear keyword search to avoid it being combined with the new invite filter
  searchKeyword.value = ''
  filterConditions.value = [{
    field: 'invite_user_id',
    operator: '等于',
    value: String(user.id)
  }]
  // Show the filter panel so the user can see the active condition
  showFilters.value = true
  handleSearch()
}

function navigateToUserTickets(user) {
  router.push({ name: 'tickets', query: { user_email: user.email } })
}

async function handleResetTraffic(user) {
  if (editorDisposed) return
  try {
    await ElMessageBox.confirm(
      `确定要重置用户 ${user.email} 的已用流量吗？上行和下行将清零。`,
      '重置流量',
      { type: 'warning' },
    )
    if (editorDisposed) return
    await updateManagedUser({ id: user.id, u: 0, d: 0, reset_traffic: true })
    if (editorDisposed) return
    ElMessage.success('流量已重置')
    loadUsers()
  } catch (err) {
    if (!editorDisposed && err !== 'cancel' && err !== 'close') {
      ElMessage.error(err.message || '重置失败')
    }
  }
}

const exportSaving = ref(false)
async function handleExportCSV() {
  if (editorDisposed || exportSaving.value) return
  exportSaving.value = true
  const controller = new AbortController()
  exportReadController = controller
  try {
    await dumpUsersCSV({ scope: 'all' }, { signal: controller.signal })
    if (!editorDisposed && !controller.signal.aborted) ElMessage.success('导出成功')
  } catch (err) {
    if (!editorDisposed && !controller.signal.aborted) ElMessage.error(err.message || '导出失败')
  } finally {
    exportReadController = null
    exportSaving.value = false
  }
}

function openGenerateDialog() {
  if (generationActive.value || generateSaving.value) return
  if (generationSubmitUncertain.value) { generateDialogVisible.value = true; return }
  generationSubmission = null
  generateForm.value = {
    emailPrefix: '',
    emailSuffix: 'gmail.com',
    password: '',
    planId: null,
    expiredAt: null,
    generateCount: null,
  }
  generateDialogVisible.value = true
}

async function submitGenerate() {
  if (generateSaving.value || generationActive.value) return
  generateSaving.value = true
  if (generateFormRef.value) {
    try { await generateFormRef.value.validate() } catch { generateSaving.value = false; return }
  }
  // 至少要填一个：邮箱前缀 or 批量数量
  const hasPrefix = !!generateForm.value.emailPrefix?.trim()
  const hasCount = Number(generateForm.value.generateCount) > 0
  if (!hasPrefix && !hasCount) {
    ElMessage.warning('请填写邮箱前缀或批量数量')
    generateSaving.value = false
    return
  }
  try {
    const data = {
      email_suffix: generateForm.value.emailSuffix,
      password: generateForm.value.password || null,
      plan_id: generateForm.value.planId || null,
      expired_at: generateForm.value.expiredAt || null,
    }

    if (hasPrefix) {
      data.email_prefix = generateForm.value.emailPrefix.trim()
    }

    const generateCount = Number(generateForm.value.generateCount)
    if (hasCount && (!hasPrefix || generateCount > 1)) {
      data.generate_count = generateCount
    }

    // Keep the same request identifier and payload after an ambiguous network failure.
    generationSubmission ||= { ...data, async: 1, request_id: crypto.randomUUID() }
    stopGenerationRead()
    const payload = await generateManagedUser(generationSubmission)
    const task = payload?.data
    if (!task?.batch_id) throw new Error('服务器未返回任务信息，请重试查询')
    acceptGenerationTask(task)
    generationSubmission = null
    generationSubmitUncertain.value = false
    if (task.state === 'failed') ElMessage.error(task.error || '生成失败')
    else ElMessage.success(task.state === 'completed' ? '用户生成完成' : '生成任务已提交')
    generateDialogVisible.value = false
  } catch (err) {
    generationSubmitUncertain.value = ![401, 403, 409, 422].includes(err.httpStatus)
    if (!generationSubmitUncertain.value) generationSubmission = null
    ElMessage.error(err.message || '生成失败')
  } finally {
    generateSaving.value = false
    readGenerationStatus()
  }
}

function openSendMailDialog(scope) {
  if (editorDisposed || sendMailSending.value) return
  if (scope === 'filter' && !canMailFiltered.value) {
    ElMessage.warning('请先查询到匹配用户，再发送筛选邮件')
    return
  }
  sendMailScope.value = scope
  sendMailFilterSnapshot.value = scope === 'filter' ? appliedFilters.value.map(item => ({ ...item })) : []
  sendMailMatchCount.value = scope === 'filter' ? pagination.value.total : 0
  sendMailForm.value = { subject: '', content: '' }
  sendMailDialogVisible.value = true
}

async function submitSendMail() {
  if (editorDisposed || sendMailSending.value) return
  if (sendMailScope.value === 'filter' && !sendMailFilterSnapshot.value.length) {
    ElMessage.warning('筛选范围为空，请重新查询后打开邮件窗口')
    return
  }
  sendMailSending.value = true
  if (sendMailFormRef.value) {
    try { await sendMailFormRef.value.validate() } catch { sendMailSending.value = false; return }
  }
  if (editorDisposed) { sendMailSending.value = false; return }
  try {
    const payload = {
      subject: sendMailForm.value.subject,
      content: sendMailForm.value.content,
    }
    if (sendMailScope.value === 'filter') {
      payload.filter = sendMailFilterSnapshot.value.map(item => ({ ...item }))
    }
    await sendMailToUsers(payload)
    if (editorDisposed) return
    ElMessage.success('邮件发送任务已提交')
    sendMailDialogVisible.value = false
  } catch (err) {
    if (!editorDisposed) ElMessage.error(err.message || '发送失败')
  } finally {
    sendMailSending.value = false
  }
}

// 消费跨页跳转的 query：从订单/工单/邀请等处点过来时自动定位用户。
// 必须在 restoreUsersPageState() 之后执行 —— 否则恢复的 sessionStorage
// 旧筛选会把这里注入的条件覆盖掉。
function applyQueryFilter() {
  const q = route.query
  if (q.user_id) {
    // 按用户 id 精确筛选（清空关键词，避免和旧的高级筛选 AND 串味）
    searchKeyword.value = ''
    filterConditions.value = [{ id: makeFilterCondId(), field: 'id', operator: '等于', value: String(q.user_id) }]
    showFilters.value = true
    pagination.value.page = 1
    return true
  }
  const email = q.email || q.user_email
  if (q.plan_id && /^\d+$/.test(String(q.plan_id))) {
    searchKeyword.value = ''
    filterConditions.value = [{ id: makeFilterCondId(), field: 'plan_id', operator: '等于', value: String(q.plan_id) }]
    showFilters.value = true
    pagination.value.page = 1
    return true
  }
  if (email) {
    // 邮箱 LIKE：直接填进主搜索框
    searchKeyword.value = String(email)
    filterConditions.value = []
    pagination.value.page = 1
    return true
  }
  return false
}

watch(() => [route.query.user_id, route.query.plan_id, route.query.email, route.query.user_email], () => {
  if (props.editorUserId || editorDisposed) return
  if (applyQueryFilter()) { captureFilterQuery(); loadUsers() }
})

onMounted(function onMount() {
  if (props.editorUserId) {
    fetchManagedUsers({ page:1, pageSize:1, filter:[{ id:'id', value:`eq:${props.editorUserId}` }] })
      .then(result => {
        if (editorDisposed) return
        const user = result.list.find(item => String(item.id) === String(props.editorUserId))
        if (!user) throw new Error('用户不存在或无权查看')
        openEditDialog(user)
      }).catch(err => { if (!editorDisposed) { ElMessage.error(err.message); emit('editor-close') } })
    fetchManagedPlanOptions().then(list => { if (!editorDisposed) plans.value = list }).catch(err => { if (!editorDisposed) ElMessage.error(err.message) })
    return
  }
  restoreUsersPageState()
  applyQueryFilter()
  captureFilterQuery()
  loadUsers()
  fetchManagedPlanOptions()
    .then(list => { plans.value = list })
    .catch(err => { console.warn('[UsersPage] 加载订阅计划失败', err) })
})
</script>

<template>
  <section class="page-stack">
    <SectionCard v-if="!editorUserId">
      <template #actions>
        <div class="users-toolbar">
          <el-input
            class="users-search-input"
            v-model="searchKeyword"
            :prefix-icon="Search"
            clearable
            placeholder="搜索邮箱…"
            aria-label="搜索用户邮箱" name="user-email-search" autocomplete="off" :spellcheck="false"
            style="width: 200px"
            @keyup.enter="handleSearch"
            @clear="handleSearch"
          />
          <el-button :icon="SlidersHorizontal" class="ghost-btn small users-filter-toggle" plain type="info" :aria-expanded="showFilters" aria-controls="user-filter-panel" @click="showFilters = !showFilters">
            筛选<span v-if="appliedFilters.length"> · {{ appliedFilters.length }}</span>
          </el-button>
          <el-select class="users-sort-field" v-model="sortField" placeholder="排序" aria-label="用户排序字段" style="width:120px" size="default" @change="handleQuerySort">
            <el-option v-for="opt in sortOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
          <el-button v-if="sortField" class="users-sort-direction" size="default" plain @click="sortOrder = sortOrder === 'desc' ? 'asc' : 'desc'; handleQuerySort()">
            {{ sortOrder === 'desc' ? '↓ 降序' : '↑ 升序' }}
          </el-button>
          <el-button :icon="Search" class="ghost-btn small users-search-button" plain type="info" @click="handleSearch">
            搜索
          </el-button>
          <el-dropdown trigger="click" class="users-bulk-actions">
            <el-button :icon="Mail" class="ghost-btn small" plain type="info">
              发送邮件
            </el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item @click="openSendMailDialog('all')">发送给全部用户</el-dropdown-item>
                <el-dropdown-item :disabled="!canMailFiltered" @click="openSendMailDialog('filter')">发送给筛选用户</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
          <el-button :icon="Download" :loading="exportSaving" class="ghost-btn small users-export" plain type="info" @click="handleExportCSV">
            导出全部 CSV
          </el-button>
          <el-button :icon="Plus" :disabled="generateSaving || generationActive" class="primary-btn small users-generate" type="primary" @click="openGenerateDialog">
            生成用户
          </el-button>
          <el-checkbox class="users-columns" v-model="showUserSecondaryColumns">更多列</el-checkbox>
          <el-dropdown class="users-more-actions" trigger="click" @command="command => command === 'export' ? handleExportCSV() : openSendMailDialog(command)">
            <el-button plain :disabled="exportSaving">更多</el-button>
            <template #dropdown><el-dropdown-menu><el-dropdown-item command="all">发送给全部用户</el-dropdown-item><el-dropdown-item command="filter" :disabled="!canMailFiltered">发送给筛选用户</el-dropdown-item><el-dropdown-item command="export">导出全部 CSV</el-dropdown-item></el-dropdown-menu></template>
          </el-dropdown>
        </div>
      </template>

      <!-- 高级筛选条件 -->
      <div v-if="generationTask || generationStatusError" class="generation-status" role="status" aria-live="polite">
        <div>
          <strong v-if="generationTask?.state === 'queued'">生成任务排队中 · {{ generationTask.total }} 个用户</strong>
          <strong v-else-if="generationTask?.state === 'running'">正在生成 · {{ generationTask.prepared }} / {{ generationTask.total }}</strong>
          <strong v-else-if="generationTask?.state === 'completed'">已生成 {{ generationTask.created }} 个用户</strong>
          <strong v-else-if="generationTask?.state === 'failed'">{{ generationTask.error }}</strong>
          <span v-if="generationStatusError">{{ generationStatusError }}</span>
          <span v-else-if="generationActive">可以离开此页面，任务会继续处理</span>
          <span v-else-if="generationTask?.state === 'completed'">账户 CSV 下载结果保留 1 小时</span>
        </div>
        <el-button v-if="generationTask?.state === 'completed'" :icon="Download" :loading="generationDownloading" plain @click="downloadGenerationResult">下载账户 CSV</el-button>
        <el-button v-if="generationStatusError" plain @click="readGenerationStatus">查询状态</el-button>
      </div>
      <div v-if="appliedFilters.length" class="users-applied-filters" role="status" aria-live="polite">
        <div class="users-filter-tags"><span class="users-filter-caption">已应用</span><span v-for="(label, index) in appliedFilterLabels" :key="index" class="users-filter-tag">{{ label }}</span></div>
        <el-button text @click="searchKeyword = ''; filterConditions = []; handleSearch()">清除筛选</el-button>
      </div>
      <div v-if="filtersDirty" class="users-filter-draft" role="status"><span>筛选已修改，搜索后生效</span><el-button text size="small" @click="discardFilterDraft">撤销修改</el-button></div>
      <div v-if="showFilters" id="user-filter-panel" class="user-filter-panel">
        <div class="user-filter-panel__header">
          <span>筛选条件</span>
          <el-button :icon="PlusCircle" size="small" text type="primary" @click="addFilterCondition">添加条件</el-button>
        </div>
        <div v-for="(cond, idx) in filterConditions" :key="cond.id || idx" class="user-filter-row">
          <span class="user-filter-row__label">条件 {{ idx + 1 }}</span>
          <el-select v-model="cond.field" placeholder="选择字段" :aria-label="`条件 ${idx + 1} 字段`" style="width: 140px" @change="onFilterFieldChange(cond)">
            <el-option v-for="f in filterFieldOptions" :key="f.id" :label="f.label" :value="f.id" />
          </el-select>
          <el-select v-model="cond.operator" placeholder="条件" :aria-label="`条件 ${idx + 1} 运算符`" style="width: 100px" :disabled="!cond.field">
            <el-option v-for="op in (getFieldDef(cond.field)?.operators || [])" :key="op" :label="op" :value="op" />
          </el-select>
          <template v-if="getFieldDef(cond.field)?.type === 'select'">
            <el-select v-model="cond.value" placeholder="选择" :aria-label="`条件 ${idx + 1} 值`" style="width: 140px">
              <el-option v-for="opt in (getFieldDef(cond.field)?.selectOptions || [])" :key="opt.value" :label="opt.label" :value="opt.value" />
            </el-select>
          </template>
          <template v-else-if="getFieldDef(cond.field)?.type === 'date'">
            <el-date-picker v-model="cond.value" type="datetime" placeholder="选择日期" :aria-label="`条件 ${idx + 1} 值`" value-format="X" style="width: 200px" />
          </template>
          <template v-else>
            <el-input v-model="cond.value" placeholder="输入值" :aria-label="`条件 ${idx + 1} 值`" style="width: 180px" :type="getFieldDef(cond.field)?.type === 'number' ? 'number' : 'text'" />
          </template>
          <el-button :icon="X" size="small" text type="danger" :aria-label="`删除条件 ${idx + 1}`" @click="removeFilterCondition(idx)" />
        </div>
        <div v-if="filterConditions.length > 0" style="text-align: right; margin-top: 8px">
          <el-button size="small" type="primary" @click="handleSearch">应用筛选</el-button>
          <el-button size="small" @click="filterConditions = []; handleSearch()">清空</el-button>
        </div>
      </div>

      <el-alert v-if="errorMsg" :title="errorMsg" :closable="false" show-icon type="error" class="users-read-error">
        <p v-if="usersLoaded">当前保留上次查询结果，筛选邮件暂不可用。</p>
        <el-button size="small" :loading="loading" @click="retryUsers">重试查询</el-button>
      </el-alert>

      <div v-loading="loading" class="users-mobile-list" :aria-busy="loading">
        <article v-for="row in users" :key="row.id" class="user-mobile-row">
          <header><button type="button" class="user-mobile-email" @click="openEditDialog(row)">{{ row.email }}</button><UserActionsMenu :user="row" :login-pending="loginUrlLoading" :secret-pending="secretResetIds.has(row.id)" @action="action => handleUserAction(action, row)" /></header>
          <div class="user-mobile-meta"><span>#{{ row.id }}</span><el-tag size="small" :type="row.banned ? 'danger' : 'success'">{{ row.banned ? '已封禁' : '正常' }}</el-tag><span>{{ row.planName || '无订阅' }}</span></div>
          <dl><div><dt>已用 / 总量</dt><dd>{{ row.totalUsed }} / {{ row.transferEnable }}</dd></div><div><dt>到期</dt><dd>{{ row.expiredAt }}</dd></div><div><dt>余额</dt><dd>¥{{ row.balance }}</dd></div><div><dt>在线设备</dt><dd>{{ row.onlineCount }}{{ row.deviceLimit ? ` / ${row.deviceLimit}` : '' }}</dd></div></dl>
        </article>
        <el-empty v-if="!users.length && !loading" description="暂无匹配用户" />
      </div>
      <el-table
        :key="`${tableSort.prop}:${tableSort.order}`"
        :default-sort="tableSort"
        v-loading="loading"
        :data="users"
        stripe
        class="dashboard-table user-table"
        style="width: 100%"
        @sort-change="handleSortChange"
      >
        <el-table-column label="ID" prop="id" width="56" />
        <el-table-column label="邮箱" prop="email" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            <div class="user-email-cell">
              <span
                :class="['user-online-dot', { 'is-online': row.onlineCount > 0 }]"
                :title="row.onlineCount > 0 ? `在线设备 ${row.onlineCount}` : '离线'"
              />
              <span class="user-email-cell__text">{{ row.email }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.statusType" effect="dark" size="small">{{ row.statusText }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="订阅" min-width="100" show-overflow-tooltip>
          <template #default="{ row }">
            <button v-if="toPlan(row.planId)" class="x-link x-link--button" type="button" @click="router.push(toPlan(row.planId))">{{ row.planName }}</button>
            <span v-else>{{ row.planName }}</span>
          </template>
        </el-table-column>
        <el-table-column label="已用/总量" min-width="190" class-name="user-numeric-cell" prop="totalUsedRaw" sortable="custom">
          <template #default="{ row }">
            <span :style="{ color: row.totalUsedRaw > row.transferEnableRaw ? 'var(--el-color-danger)' : '', fontWeight: row.totalUsedRaw > row.transferEnableRaw ? '600' : '' }">
              {{ row.totalUsed }} / {{ row.transferEnable }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="到期时间" prop="expiredAtRaw" width="140" sortable="custom">
          <template #default="{ row }">
            <span :style="{ color: row.expiredAtRaw !== null && row.expiredAtRaw * 1000 < Date.now() ? 'var(--el-color-danger)' : '', fontWeight: row.expiredAtRaw !== null && row.expiredAtRaw * 1000 < Date.now() ? '600' : '' }">
              {{ row.expiredAt }}
            </span>
          </template>
        </el-table-column>
        <el-table-column v-if="showUserSecondaryColumns || sortField === 'next_reset_at'" label="下次重置" prop="nextResetAtRaw" width="160" sortable="custom">
          <template #default="{ row }">
            <template v-if="row.nextResetAtRaw">
              <div :style="{ color: row.nextResetAtRaw * 1000 < Date.now() ? 'var(--el-color-danger)' : '', fontWeight: row.nextResetAtRaw * 1000 < Date.now() ? '600' : '' }">
                {{ row.nextResetAt }}
              </div>
              <div style="font-size: 12px; color: var(--el-text-color-secondary)">
                {{ formatResetCountdown(row.nextResetAtRaw) }}
              </div>
            </template>
            <span v-else style="color: var(--el-text-color-secondary)">不重置</span>
          </template>
        </el-table-column>
        <el-table-column label="余额" width="110" align="right" class-name="user-numeric-cell" prop="balance" sortable="custom">
          <template #default="{ row }">¥{{ row.balance }}</template>
        </el-table-column>
        <el-table-column v-if="showUserSecondaryColumns || sortField === 'commission_balance'" label="佣金" width="110" align="right" class-name="user-numeric-cell" prop="commissionBalance" sortable="custom">
          <template #default="{ row }">¥{{ row.commissionBalance }}</template>
        </el-table-column>
        <el-table-column label="在线设备" width="110" prop="onlineCount" sortable="custom">
          <template #default="{ row }">
            <span :style="{ color: row.deviceLimit && row.onlineCount > row.deviceLimit ? 'var(--el-color-danger)' : '' }">
              {{ row.onlineCount }}<span v-if="row.deviceLimit" style="color:var(--el-text-color-secondary)"> / {{ row.deviceLimit }}</span>
            </span>
          </template>
        </el-table-column>
        <el-table-column v-if="showUserSecondaryColumns" label="注册时间" prop="createdAt" width="140" />
        <el-table-column fixed="right" label="操作" :width="USER_ACTIONS_COLUMN_WIDTH" align="center" class-name="user-actions-column">
          <template #default="{ row }">
            <UserActionsMenu :user="row" :login-pending="loginUrlLoading" :secret-pending="secretResetIds.has(row.id)" @action="action => handleUserAction(action, row)" />
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        :current-page="pagination.page"
        :page-size="pagination.pageSize"
        :page-sizes="[10, 20, 50, 100]"
        :total="pagination.total"
        background
        layout="total, sizes, prev, pager, next, jumper"
        style="margin-top: 16px; justify-content: flex-end"
        @current-change="handlePageChange"
        @size-change="handlePageSizeChange"
      />
    </SectionCard>

    <!-- 用户管理对话框 -->
    <el-dialog v-model="editDialogVisible" class="user-edit-dialog" title="用户管理" width="min(480px, calc(100vw - 32px))" destroy-on-close append-to-body
      :show-close="!editSaving" :close-on-click-modal="!editSaving" :close-on-press-escape="!editSaving">
      <el-form
        ref="editFormRef"
        autocomplete="off"
        :model="editForm"
        :rules="editRules"
        :disabled="editSaving"
        label-position="top"
        style="padding: 0 4px"
      >
        <el-form-item label="邮箱" prop="email">
          <el-input v-model="editForm.email" autocomplete="off" name="managed-user-email" />
        </el-form-item>
        <el-form-item label="邀请人邮箱">
          <el-input v-model="editForm.invite_user_email" autocomplete="off" name="managed-user-inviter" placeholder="留空则清除邀请人" />
        </el-form-item>
        <el-form-item label="密码">
          <el-input v-model="editForm.password" autocomplete="new-password" name="managed-user-new-password" placeholder="如需修改密码请输入" show-password />
        </el-form-item>

        <div style="display:flex;gap:12px">
          <el-form-item label="余额" style="flex:1">
            <el-input v-model.number="editForm.balance" placeholder="请输入余额" type="number">
              <template #suffix>¥</template>
            </el-input>
          </el-form-item>
          <el-form-item label="佣金余额" style="flex:1">
            <el-input v-model.number="editForm.commission_balance" placeholder="请输入佣金余额" type="number">
              <template #suffix>¥</template>
            </el-input>
          </el-form-item>
        </div>

        <div style="display:flex;gap:12px">
          <el-form-item label="已用上行" style="flex:1">
            <el-input v-model.number="editForm.u" placeholder="已用上行" type="number">
              <template #suffix>GB</template>
            </el-input>
          </el-form-item>
          <el-form-item label="已用下行" style="flex:1">
            <el-input v-model.number="editForm.d" placeholder="已用下行" type="number">
              <template #suffix>GB</template>
            </el-input>
          </el-form-item>
        </div>
        <div style="margin-top: -10px; margin-bottom: 12px; text-align: right;">
          <el-button size="small" type="primary" link icon="DataLine" @click="handleViewTraffic({ id: editForm.id })">
            查看流量明细
          </el-button>
        </div>

        <el-form-item label="流量额度（GB）">
          <el-input v-model.number="editForm.transfer_enable" placeholder="请输入流量" type="number">
            <template #suffix>GB</template>
          </el-input>
        </el-form-item>

        <el-form-item label="到期时间">
          <el-date-picker
            v-model="editForm.expired_at"
            type="datetime"
            placeholder="请选择用户到期日期，留空为长期有效"
            value-format="X"
            style="width:100%"
            clearable
          />
        </el-form-item>

        <el-form-item label="流量重置">
          <div style="width:100%; font-size: 13px; color: var(--el-text-color-regular); line-height: 1.7">
            <div>
              <span style="color: var(--el-text-color-secondary)">下次重置：</span>
              <span v-if="editForm.next_reset_at_display && editForm.next_reset_at_display !== '--'">
                {{ editForm.next_reset_at_display }}
                <span style="color: var(--el-text-color-secondary); margin-left: 6px">
                  ({{ formatResetCountdown(editForm.next_reset_at_raw) }})
                </span>
              </span>
              <span v-else style="color: var(--el-text-color-secondary)">不重置（一次性套餐或未设置）</span>
            </div>
            <div>
              <span style="color: var(--el-text-color-secondary)">上次重置：</span>
              <span>{{ editForm.last_reset_at_display || '--' }}</span>
            </div>
          </div>
        </el-form-item>

        <el-form-item label="订阅计划">
          <el-select v-model="editForm.plan_id" placeholder="无" clearable style="width:100%">
            <el-option :label="'无'" :value="null" />
            <el-option v-for="p in plans" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
        </el-form-item>

        <el-form-item label="账户状态">
          <el-select v-model="editForm.banned" style="width:100%">
            <el-option label="正常" :value="0" />
            <el-option label="封禁" :value="1" />
          </el-select>
        </el-form-item>

        <el-form-item>
          <template #label>
            <el-tooltip placement="top" effect="dark" :trigger="['hover', 'focus']">
              <template #content>
                <div style="max-width: 260px; line-height: 1.6">
                  <b>跟随系统设置（0）</b>：由后台「仅首次支付返佣」开关决定，开启则仅首单返佣，关闭则每单返佣。<br />
                  <b>每单均返佣（1）</b>：无论首单还是续费，每次付款均产生佣金（循环佣金）。<br />
                  <b>仅首单返佣（2）</b>：该用户名下无任何有效历史订单时才触发佣金，之后续费不再返。
                </div>
              </template>
              <span class="user-commission-help" tabindex="0" aria-label="佣金类型说明">
                佣金类型 <HelpCircle :size="14" aria-hidden="true" />
              </span>
            </el-tooltip>
          </template>
          <el-select v-model="editForm.commission_type" style="width:100%">
            <el-option label="跟随系统设置（由全局开关决定）" :value="0" />
            <el-option label="每单均返佣（循环佣金）" :value="1" />
            <el-option label="仅首单返佣（一次性佣金）" :value="2" />
          </el-select>
        </el-form-item>

        <el-form-item label="推荐返利比例">
          <el-input v-model.number="editForm.commission_rate" placeholder="为空则跟随站点设置返利比例" type="number">
            <template #suffix>%</template>
          </el-input>
        </el-form-item>

        <el-form-item label="专享折扣比例">
          <el-input v-model.number="editForm.discount" placeholder="为空则不享受专享折扣" type="number">
            <template #suffix>%</template>
          </el-input>
        </el-form-item>

        <el-form-item label="限速">
          <el-input v-model.number="editForm.speed_limit" placeholder="留空则不限速" type="number">
            <template #suffix>Mbps</template>
          </el-input>
        </el-form-item>

        <el-form-item label="设备限制">
          <el-input v-model.number="editForm.device_limit" placeholder="留空则不限制" type="number">
            <template #suffix>台</template>
          </el-input>
        </el-form-item>

        <el-form-item label="是否管理员">
          <el-switch v-model="editForm.is_admin" :active-value="1" :inactive-value="0" />
        </el-form-item>

        <el-form-item label="是否员工">
          <el-switch v-model="editForm.is_staff" :active-value="1" :inactive-value="0" />
        </el-form-item>

        <el-form-item label="备注">
          <el-input v-model="editForm.remarks" type="textarea" :rows="3" placeholder="请在这里记录" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="editSaving" @click="editDialogVisible = false">取消</el-button>
        <el-button :loading="editSaving" type="primary" @click="saveEditForm">提交</el-button>
      </template>
    </el-dialog>

    <!-- 生成用户对话框 -->
    <el-dialog v-model="generateDialogVisible" title="生成用户" width="min(500px, calc(100vw - 32px))" destroy-on-close>
      <el-form
        ref="generateFormRef"
        autocomplete="off"
        :model="generateForm"
        :rules="generateRules"
        :disabled="generationBusy"
        label-width="120px"
      >
        <el-form-item label="邮箱前缀">
          <el-input v-model="generateForm.emailPrefix" placeholder="指定前缀或留空批量生成" />
        </el-form-item>
        <el-form-item label="邮箱后缀" prop="emailSuffix">
          <el-input v-model="generateForm.emailSuffix" />
        </el-form-item>
        <el-form-item label="批量数量">
          <el-input-number v-model="generateForm.generateCount" :min="1" :max="500" placeholder="留空为单个生成" />
        </el-form-item>
        <el-form-item label="密码">
          <el-input v-model="generateForm.password" autocomplete="new-password" name="generated-user-new-password" placeholder="留空则使用邮箱作为密码" show-password />
        </el-form-item>
      </el-form>
      <el-alert v-if="generationSubmitUncertain" title="提交结果尚未确认。重试会使用同一个任务编号，不会重复创建；也可先查询任务状态。" type="warning" :closable="false" />
      <template #footer>
        <el-button @click="generateDialogVisible = false">取消</el-button>
        <el-button :loading="generateSaving" :disabled="generationActive" type="primary" @click="submitGenerate">{{ generationSubmitUncertain ? '重试提交' : '提交生成' }}</el-button>
      </template>
    </el-dialog>

    <!-- 发送邮件对话框 -->
    <el-dialog v-model="sendMailDialogVisible" :title="sendMailScope === 'all' ? '发送邮件给全部用户' : '发送邮件给筛选用户'" width="min(600px, calc(100vw - 32px))" destroy-on-close
      :show-close="!sendMailSending" :close-on-click-modal="!sendMailSending" :close-on-press-escape="!sendMailSending">
      <el-alert v-if="sendMailScope === 'all'" type="warning" title="此邮件将发送给全部用户，不受列表筛选条件限制。" :closable="false" show-icon class="users-mail-scope" />
      <div v-else class="users-mail-scope users-mail-filter-scope"><strong>筛选范围 · 查询时匹配 {{ sendMailMatchCount }} 位用户</strong><p>{{ sendMailFilterLabels }}</p></div>
      <el-form
        ref="sendMailFormRef"
        :model="sendMailForm"
        :rules="sendMailRules"
        :disabled="sendMailSending"
        label-position="top"
      >
        <el-form-item label="邮件主题" prop="subject">
          <el-input v-model="sendMailForm.subject" placeholder="输入邮件主题" />
        </el-form-item>
        <el-form-item label="邮件内容" prop="content">
          <el-input v-model="sendMailForm.content" type="textarea" :rows="8" placeholder="支持 {{user.email}} 等用户变量，按纯文本发送" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="sendMailSending" @click="sendMailDialogVisible = false">取消</el-button>
        <el-button :loading="sendMailSending" type="primary" @click="submitSendMail">{{ sendMailScope === 'all' ? '发送给全部用户' : '发送给筛选用户' }}</el-button>
      </template>
    </el-dialog>

    <!-- 分配订单对话框 -->
    <el-dialog v-model="assignDialogVisible" title="分配订单" width="min(480px, calc(100vw - 32px))" destroy-on-close
      :show-close="!assignSaving" :close-on-click-modal="!assignSaving" :close-on-press-escape="!assignSaving">
      <el-form
        ref="assignFormRef"
        :model="assignForm"
        :rules="assignRules"
        :disabled="assignSaving"
        label-width="90px"
      >
        <el-form-item label="用户邮箱">
          <el-input :model-value="assignForm.email" disabled />
        </el-form-item>
        <el-form-item label="订阅计划" prop="planId">
          <el-select v-model="assignForm.planId" placeholder="请选择订阅计划" style="width:100%" @change="onAssignPlanChange">
            <el-option v-for="plan in plans" :key="plan.id" :label="plan.name" :value="plan.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="订阅周期" prop="period">
          <el-select v-model="assignForm.period" placeholder="请先选择订阅计划" style="width:100%" :disabled="!assignForm.planId" @change="onAssignPeriodChange">
            <el-option v-for="opt in periodOptions" :key="opt.value" :label="`${opt.label} - ¥${opt.price}`" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="支付金额" prop="totalAmount">
          <el-input-number v-model="assignForm.totalAmount" :min="0" :precision="2" :step="1" style="width:100%" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="assignSaving" @click="assignDialogVisible = false">取消</el-button>
        <el-button :loading="assignSaving" type="primary" @click="submitAssignOrder">确认分配</el-button>
      </template>
    </el-dialog>
    <!-- 流量详情对话框 -->
    <el-dialog
      v-model="trafficDialogVisible"
      append-to-body
      :title="`流量分析 · ${currentTrafficUserLabel}`"
      width="min(1080px, calc(100vw - 24px))"
      top="5vh"
      destroy-on-close
      class="user-traffic-dialog"
      @closed="releaseClosedTraffic"
    >
      <div class="traffic-detail-filterbar">
        <el-button-group class="traffic-period-shortcuts capsule-choice-group" aria-label="流量时间范围">
          <el-button @click="selectTrafficPeriod(1)">今天</el-button>
          <el-button @click="selectTrafficPeriod(7)">近7天</el-button>
          <el-button @click="selectTrafficPeriod(30)">近30天</el-button>
        </el-button-group>
        <el-date-picker
          v-model="trafficTimeRange"
          type="daterange"
          unlink-panels
          range-separator="至"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          format="YYYY-MM-DD"
          :disabled-date="date => date.getTime() > Date.now()"
          class="traffic-detail-date-range"
          @change="handleTrafficTimeRangeChange"
        />
      </div>
      <el-tabs v-model="trafficActiveTab" class="user-traffic-tabs">
        <el-tab-pane label="按天明细" name="daily">
          <el-table v-loading="trafficLoading" :data="trafficData" stripe style="width: 100%">
            <el-table-column prop="date" label="统计日期" width="120" />
            <el-table-column prop="uText" label="上行流量" />
            <el-table-column prop="dText" label="下行流量" />
            <el-table-column prop="totalText" label="合计流量" />
            <el-table-column label="倍率" width="96" align="center">
              <template #default="{ row }">
                <span class="traffic-detail-chip traffic-detail-chip--rate">
                  {{ row.serverRateText || row.serverRate || '1x' }}
                </span>
              </template>
            </el-table-column>
          </el-table>
          <el-pagination
            :current-page="trafficPagination.page"
            :page-size="trafficPagination.pageSize"
            :total="trafficPagination.total"
            layout="total, prev, pager, next"
            class="traffic-detail-pagination"
            @current-change="handleTrafficPageChange"
          />
        </el-tab-pane>
        <el-tab-pane label="流量趋势与节点" name="nodes" lazy>
          <div v-if="nodeTrafficError" class="traffic-analysis-error" role="alert">
            <span>{{ nodeTrafficError }}</span><el-button size="small" @click="loadNodeTrafficData">重试</el-button>
          </div>
          <div class="traffic-detail-tools">
            <el-radio-group v-model="nodeTrafficChartType" size="small" aria-label="图表类型">
              <el-radio-button value="bar">柱状</el-radio-button><el-radio-button value="line">折线</el-radio-button>
            </el-radio-group>
            <el-radio-group
              v-model="nodeTrafficGranularity"
              size="small"
              class="traffic-detail-granularity"
              @change="handleNodeTrafficGranularityChange"
            >
              <el-radio-button value="two_minutes">按 2 分钟</el-radio-button>
              <el-radio-button value="hour">按小时</el-radio-button>
              <el-radio-button value="day">按天</el-radio-button>
            </el-radio-group>
            <el-select
              v-model="selectedNodeTrafficKeys"
              multiple
              collapse-tags
              collapse-tags-tooltip
              filterable
              class="traffic-detail-node-select"
              placeholder="选择节点曲线"
              @change="handleNodeTrafficSelectionChange"
            >
              <el-option
                v-for="item in nodeTrafficSeriesOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </el-select>
          </div>
          <p v-if="nodeTrafficActualRange" class="traffic-actual-range">数据范围：{{ nodeTrafficActualRange }} · 计费流量</p>
          <div v-loading="nodeTrafficSeriesLoading" class="traffic-detail-chart-wrap">
            <MultiTrafficChart
              :series="visibleNodeTrafficSeries"
              :labels="nodeTrafficLabels"
              :height="240"
              fit-container
              zoomable
              :chart-type="nodeTrafficChartType"
              empty-text="暂无节点曲线数据"
              :tooltip-formatter="formatNodeTrafficTooltip"
            />
          </div>
          <div
            v-if="nodeTrafficSummary.length"
            class="traffic-detail-series"
          >
            <button type="button" class="traffic-detail-series__all" :class="{ 'is-selected': selectedNodeTrafficKeys.includes('ALL') }" @click="handleNodeTrafficSelectionChange(['ALL'])">全部节点</button>
            <button
              v-for="series in displayedTrafficNodes"
              :key="series.key"
              class="traffic-detail-series__item"
              type="button"
              :class="{ 'is-selected': selectedNodeTrafficKeys.includes(series.key) }"
              :aria-pressed="selectedNodeTrafficKeys.includes(series.key)"
              @click="handleNodeTrafficSelectionChange([series.key])"
            >
              <i class="traffic-node-color" :style="{ background: series.color }" />
              <strong>{{ series.name }}</strong>
              <span class="traffic-detail-series__total">{{ formatTrafficBytes(series.bytes) }} · {{ series.share < 0.1 ? '<0.1' : series.share.toFixed(1) }}%</span>
            </button>
            <el-button v-if="nodeTrafficSummary.length > 6" size="small" text @click="expandedTrafficNodes = !expandedTrafficNodes">{{ expandedTrafficNodes ? '收起' : `+${nodeTrafficSummary.length - 6}` }}</el-button>
          </div>
          <div class="traffic-detail-table-tools">
            <span class="traffic-detail-table-tools__label">计费流量下限</span>
            <el-input-number
              v-model="nodeTrafficMinimumValue"
              :min="0"
              :precision="2"
              :controls="false"
              placeholder="不限"
              class="traffic-detail-minimum-input"
            />
            <el-select
              v-model="nodeTrafficMinimumUnit"
              class="traffic-detail-minimum-unit"
            >
              <el-option label="B" value="B" />
              <el-option label="KB" value="KB" />
              <el-option label="MB" value="MB" />
              <el-option label="GB" value="GB" />
            </el-select>
          </div>
          <el-table
            v-loading="nodeTrafficLoading"
            :data="pagedNodeTrafficTableRows"
            stripe
            table-layout="fixed"
            style="width: 100%"
          >
            <el-table-column prop="time" label="时间" width="104" />
            <el-table-column prop="name" label="节点名称" min-width="132" show-overflow-tooltip />
            <el-table-column label="协议" width="118" align="center">
              <template #default="{ row }">
                <span class="traffic-detail-chip traffic-detail-chip--protocol">
                  {{ row.type }}
                </span>
              </template>
            </el-table-column>
            <el-table-column label="倍率" width="92" align="center">
              <template #default="{ row }">
                <span class="traffic-detail-chip traffic-detail-chip--rate">
                  {{ row.rateText || '--' }}
                </span>
              </template>
            </el-table-column>
            <el-table-column prop="uText" label="上行流量" min-width="88" />
            <el-table-column prop="dText" label="下行流量" min-width="88" />
            <el-table-column prop="totalText" label="计费流量" min-width="88" />
          </el-table>
          <el-pagination
            v-if="nodeTrafficTablePagination.total > nodeTrafficTablePagination.pageSize"
            :current-page="nodeTrafficTablePagination.page"
            :page-size="nodeTrafficTablePagination.pageSize"
            :total="nodeTrafficTablePagination.total"
            layout="total, prev, pager, next"
            class="traffic-detail-pagination"
            @current-change="handleNodeTrafficTablePageChange"
          />
        </el-tab-pane>
      </el-tabs>
    </el-dialog>
  </section>
</template>

<style scoped>
.users-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.users-more-actions, .users-mobile-list { display: none; }
.users-applied-filters { display: flex; align-items: center; gap: 12px; justify-content: space-between; margin-bottom: 12px; font-size: 13px; }
.users-filter-tags { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; min-width: 0; }
.users-filter-caption { color: var(--muted); margin-right: 2px; }
.users-filter-tag { padding: 4px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg-soft); overflow-wrap: anywhere; }
.users-applied-filters > .el-button { flex-shrink: 0; }
.users-filter-draft { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; color: var(--muted); font-size: 12px; }
.users-read-error, .users-mail-scope { margin-bottom: 16px; }
.users-read-error p { margin: 4px 0 8px; }
.users-mail-filter-scope { padding: 12px 14px; border: 1px solid var(--line); border-radius: 10px; background: var(--bg-soft); font-size: 13px; }
.users-mail-filter-scope p { margin: 6px 0 0; color: var(--muted); overflow-wrap: anywhere; }
.user-table :deep(.user-numeric-cell .cell) { white-space: nowrap; font-variant-numeric: tabular-nums; }
@media (max-width: 640px) {
  .users-toolbar { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; width: 100%; }
  .users-toolbar > .users-search-input { grid-area: 1 / 1 / 2 / 3; width: 100% !important; }
  .users-search-button { grid-area: 1 / 3; }
  .users-filter-toggle { grid-area: 2 / 1; }
  .users-sort-field { grid-area: 2 / 2; }
  .users-sort-direction { grid-area: 2 / 3; }
  .users-generate { grid-area: 3 / 1 / 4 / 3; }
  .users-more-actions { grid-area: 3 / 3; }
  .users-toolbar .el-button { min-height: 40px; margin-left: 0; }
  .users-toolbar > .el-select { width: 100% !important; }
  .users-toolbar .users-bulk-actions, .users-toolbar .users-export, .users-columns, .user-table { display: none; }
  .users-more-actions { display: inline-flex; }
  .users-mobile-list { display: grid; }
  .user-mobile-row { padding: 16px 0; border-bottom: 1px solid var(--line); }
  .user-mobile-row header { display: flex; gap: 12px; align-items: flex-start; justify-content: space-between; }
  .user-mobile-email { border: 0; padding: 0; background: none; text-align: left; color: var(--text); font: inherit; font-weight: 600; min-width: 0; overflow-wrap: anywhere; cursor: pointer; }
  .user-mobile-email:focus-visible { outline: 2px solid var(--primary); outline-offset: 3px; }
  .user-mobile-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; font-size: 12px; margin-top: 8px; }
  .user-mobile-row dl { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; margin: 12px 0 0; font-size: 12px; }
  .user-mobile-row dt { color: var(--muted); margin-bottom: 3px; }
  .user-mobile-row dd { margin: 0; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
  .user-mobile-row :deep(.user-action-trigger) { height: 40px; }
}
.user-commission-help { display: inline-flex; align-items: center; gap: 4px; cursor: help; }
.user-commission-help:focus-visible { outline: 2px solid var(--el-color-primary); outline-offset: 3px; border-radius: 3px; }
.generation-status {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 12px 16px;
  margin-bottom: 16px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 12px;
  background: var(--el-fill-color-light);
}
.generation-status > div { display: grid; gap: 4px; min-width: 0; }
.generation-status span { font-size: 13px; color: var(--el-text-color-secondary); overflow-wrap: anywhere; }
.user-filter-panel {
  margin-bottom: 16px;
  padding: 14px 16px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
  background: var(--el-fill-color-lighter);
}
.user-filter-panel__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
  font-size: 14px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}
.user-filter-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}
.user-filter-row__label {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  min-width: 50px;
}

.user-table :deep(.el-table__cell) {
  vertical-align: middle;
}

.user-table :deep(.user-actions-column) {
  padding: 0;
}

.user-table :deep(.el-table__fixed-right) {
  z-index: 4;
  box-shadow: -6px 0 16px rgba(42, 37, 32, 0.05);
}

.user-table :deep(.el-table__fixed-right-patch) {
}

.user-table :deep(.user-actions-column .cell) {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100%;
  padding: 0 10px;
  background: transparent;
}

.user-action-trigger {
  --el-button-text-color: #c94f2e;
  --el-button-bg-color: rgba(255, 255, 255, 0.96);
  --el-button-border-color: rgba(232, 88, 45, 0.35);
  --el-button-hover-text-color: #c94f2e;
  --el-button-hover-bg-color: #fff7f4;
  --el-button-hover-border-color: rgba(232, 88, 45, 0.72);
  --el-button-active-text-color: #a83d20;
  --el-button-active-bg-color: #fff2ec;
  --el-button-active-border-color: rgba(232, 88, 45, 0.86);
  min-width: 58px;
  height: 28px;
  padding: 0 12px;
  border-radius: 999px;
  font-weight: 600;
  color: #c94f2e !important;
  background: rgba(255, 255, 255, 0.96) !important;
  border-color: rgba(232, 88, 45, 0.35) !important;
  box-shadow: none !important;
}

.user-action-trigger:hover,
.user-action-trigger:focus-visible {
  color: #c94f2e !important;
  background: #fff7f4 !important;
  border-color: rgba(232, 88, 45, 0.72) !important;
}

.user-action-trigger:active {
  color: #a83d20 !important;
  background: #fff2ec !important;
  border-color: rgba(232, 88, 45, 0.86) !important;
}

.user-email-cell {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  max-width: 100%;
  min-width: 0;
}

.user-email-cell__text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-online-dot {
  position: relative;
  flex: 0 0 auto;
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: #cbd5e1;
  box-shadow: 0 0 0 2px rgba(203, 213, 225, 0.25);
}

.user-online-dot.is-online {
  background: #22c55e;
  box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.18);
}

:global(.user-traffic-dialog .el-dialog__body) {
  padding-top: 8px;
}

:global(.user-traffic-dialog .el-tabs__item) {
  color: var(--muted);
  transition:
    color 0.15s ease,
    background-color 0.15s ease;
}

:global(.user-traffic-dialog .el-tabs__item.is-active) {
  color: var(--primary);
}

:global(.user-traffic-dialog .el-tabs__item:hover),
:global(.user-traffic-dialog .el-tabs__item:focus-visible) {
  color: var(--primary);
}

:global(.user-traffic-dialog .el-tabs__active-bar) {
  background-color: var(--primary);
}

:global(.traffic-detail-filterbar) {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  padding: 0;
}

:global(.traffic-detail-filterbar__label) {
  flex: 0 0 auto;
  color: var(--text);
  font-size: 12px;
  font-weight: 700;
}

:global(.traffic-detail-date-range) {
  flex: 1 1 280px;
  min-width: 0;
}

:global(.traffic-detail-date-range.el-date-editor) {
  --el-datepicker-active-color: var(--primary);
  --el-input-focus-border-color: var(--primary);
  --el-input-hover-border-color: var(--line-strong);
}

:global(.user-traffic-dialog .el-radio-button__inner) {
  color: var(--muted);
  border-color: var(--line);
  background: var(--panel-raised);
  transition:
    color 0.15s ease,
    background-color 0.15s ease,
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

:global(.user-traffic-dialog .el-radio-button__inner:hover),
:global(.user-traffic-dialog .el-radio-button__inner:focus-visible) {
  color: var(--primary);
  border-color: var(--line-strong);
  background: var(--bg-soft);
  box-shadow: none;
}

:global(.user-traffic-dialog .el-radio-button__original-radio:checked + .el-radio-button__inner) {
  border-color: var(--primary);
  background: var(--primary);
  color: #fff;
  box-shadow: -1px 0 0 0 var(--primary);
}

:global(.traffic-detail-tools) {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  margin-bottom: 12px;
  padding: 0;
}

:global(.traffic-detail-node-select) {
  min-width: 0;
  flex: 1 1 320px;
}

:global(.traffic-detail-scale-select) {
  width: 92px;
  flex: 0 0 auto;
}

:global(.traffic-detail-node-select .el-select__wrapper:hover),
:global(.traffic-detail-node-select .el-select__wrapper.is-focused),
:global(.traffic-detail-scale-select .el-select__wrapper:hover),
:global(.traffic-detail-scale-select .el-select__wrapper.is-focused) {
  box-shadow: 0 0 0 1px var(--line-strong) inset;
}

:global(.traffic-detail-chart-wrap) {
  margin-bottom: 14px;
  padding: 8px 4px;
  border: 1px solid var(--line);
  border-radius: 16px;
  background: var(--panel-raised);
}
:global(.traffic-analysis-error) { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 12px; margin-bottom:12px; border-radius:8px; background:var(--el-color-danger-light-9); color:var(--el-color-danger); }
:global(.traffic-actual-range) { margin:0 0 10px; font-size:12px; color:var(--muted); }

:global(.user-edit-dialog) { max-height: calc(100dvh - 32px); margin: 16px auto; display: flex; flex-direction: column; }
:global(.user-edit-dialog .el-dialog__header),
:global(.user-edit-dialog .el-dialog__footer) { flex: 0 0 auto; }
:global(.user-edit-dialog .el-dialog__body) { min-height: 0; overflow-y: auto; }

:global(.user-traffic-dialog) { max-height: 90dvh; display: flex; flex-direction: column; margin-bottom: 0; }
:global(.user-traffic-dialog .el-dialog__header) { flex: 0 0 auto; padding-right: 28px; }
:global(.user-traffic-dialog .el-dialog__title) { overflow-wrap: anywhere; }
:global(.user-traffic-dialog .el-dialog__body) { min-height: 0; overflow-y: auto; }
@media (max-width: 640px) {
  :global(.traffic-detail-date-range) { flex-basis: 100%; width: 100%; }
  :global(.traffic-detail-node-select) { flex-basis: 100%; width: 100%; }
  :global(.user-traffic-dialog .el-dialog__title) { font-size: 16px; }
  :global(.traffic-detail-table-tools) { flex-wrap: wrap; }
}

:global(.traffic-detail-time-table) {
  margin-bottom: 14px;
}

:global(.traffic-detail-time-table__header) {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
  color: var(--text);
  font-size: 13px;
}

:global(.traffic-detail-time-table__header span) {
  color: var(--muted);
  font-size: 12px;
}

:global(.traffic-detail-pagination) {
  margin-top: 16px;
  justify-content: flex-end;
}

:global(.traffic-detail-pagination .el-pager) {
  column-gap: 8px;
}

:global(.traffic-detail-pagination .el-pager li),
:global(.traffic-detail-pagination button) {
  box-sizing: border-box;
  height: 32px;
  min-width: 38px;
  margin: 0 !important;
  border: 1px solid var(--line);
  border-radius: 8px !important;
  background: var(--panel-raised);
  box-shadow: none !important;
  transform: none !important;
  transition:
    color 0.15s ease,
    border-color 0.15s ease;
}

:global(.traffic-detail-pagination .el-pager li) {
  flex: 0 0 38px;
  width: 38px;
  padding: 0;
}

:global(.traffic-detail-pagination .btn-prev),
:global(.traffic-detail-pagination .btn-next) {
  flex: 0 0 auto;
  min-width: 72px;
  padding: 0 12px;
}

:global(.traffic-detail-pagination .el-pager li:hover),
:global(.traffic-detail-pagination .el-pager li:focus-visible),
:global(.traffic-detail-pagination button:hover),
:global(.traffic-detail-pagination button:focus-visible) {
  color: var(--primary);
  border-color: var(--line-strong);
  background: var(--panel-raised);
  box-shadow: none !important;
  transform: none !important;
}

:global(.traffic-detail-pagination .el-pager li.is-active) {
  border-color: var(--primary);
  background: var(--primary) !important;
  color: #fff !important;
}

:global(.traffic-detail-pagination .el-pager li.is-active:hover),
:global(.traffic-detail-pagination .el-pager li.is-active:focus-visible) {
  border-color: var(--primary);
  background: var(--primary) !important;
  color: #fff !important;
}

:global(.traffic-detail-pagination button.is-disabled),
:global(.traffic-detail-pagination button:disabled) {
  border-color: var(--line);
  background: var(--panel-raised) !important;
  box-shadow: none !important;
  transform: none !important;
}

:global(.traffic-detail-series) {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
}
:global(.traffic-detail-series > .el-button) { margin: 0; }
:global(.traffic-node-color) { width: 8px; height: 8px; border-radius: 2px; flex: 0 0 auto; }
:global(.traffic-detail-series__all),
:global(.traffic-detail-series__item) {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 5px 10px;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--panel-raised);
  color: var(--text);
  font: inherit;
  cursor: pointer;
}
:global(.traffic-detail-series__all) { color: var(--muted); }
:global(.traffic-detail-series__all:hover),
:global(.traffic-detail-series__item:hover),
:global(.traffic-detail-series__all.is-selected),
:global(.traffic-detail-series__item.is-selected) { border-color: var(--primary); background: var(--bg-soft); }
:global(.traffic-detail-series__item strong) {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12px;
  font-weight: 600;
}
:global(.traffic-detail-series__total) {
  font-size: 12px;
  color: var(--muted);
  white-space: nowrap;
}

:global(.traffic-detail-table-tools) {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  margin: 2px 0 10px;
}

:global(.traffic-detail-table-tools__label) {
  color: var(--muted);
  font-size: 12px;
  font-weight: 600;
}

:global(.traffic-detail-minimum-input) {
  width: 112px;
}

:global(.traffic-detail-minimum-unit) {
  width: 76px;
}

:global(.traffic-detail-minimum-input .el-input__wrapper:hover),
:global(.traffic-detail-minimum-input .el-input__wrapper.is-focus),
:global(.traffic-detail-minimum-unit .el-select__wrapper:hover),
:global(.traffic-detail-minimum-unit .el-select__wrapper.is-focused) {
  box-shadow: 0 0 0 1px var(--line-strong) inset;
}

:global(.traffic-detail-chip) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 24px;
  max-width: 100%;
  padding: 2px 10px;
  border-radius: 6px;
  border: 1px solid transparent;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.25;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  box-sizing: border-box;
  font-variant-numeric: tabular-nums;
}

:global(.traffic-detail-chip--protocol) {
  border-color: rgba(201, 79, 46, 0.22);
  background: var(--primary-soft);
  color: var(--primary-strong);
}

:global(.traffic-detail-chip--rate) {
  border-color: rgba(201, 79, 46, 0.2);
  background: rgba(201, 79, 46, 0.06);
  color: var(--primary);
}
</style>
