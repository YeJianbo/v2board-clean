<script setup>
import { onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { RefreshCw, Eye } from 'lucide-vue-next'
import SectionCard from '../components/common/SectionCard.vue'
import CopyButton from '../components/common/CopyButton.vue'
import { buildDashboardApiUrl, requestDashboardApi } from '../services/api'
import { normalizeSystemLog } from '../services/logs'
import { createSequence } from '../utils/sequence'
import { createLogDetailState } from '../utils/logDetailState'

const route = useRoute()
const router = useRouter()

// --- Tab ---
const activeTab = ref('audit')
const queueTabs = [
  { name: 'failed', label: '失败任务' },
  { name: 'recent', label: '近期任务' },
  { name: 'pending', label: '等待任务' },
]
const queueTotals = ref({})

// --- Audit Log State ---
const auditLogs = ref([])
const auditLoading = ref(false)
const auditError = ref('')
const activeAction = ref('all')
const searchKeyword = ref('')
const appliedSearchKeyword = ref('')
const auditPage = ref(1)
const auditPageSize = ref(20)
const auditTotal = ref(0)
const auditDetails = createLogDetailState(async (id, signal) => {
  const payload = await requestDashboardApi(buildDashboardApiUrl('system/getAuditLog', [['id', id]]), { signal })
  if (!payload?.data || Array.isArray(payload.data)) throw new Error('日志详情返回格式异常')
  return normalizeSystemLog(payload.data)
})
const auditDetailEntries = auditDetails.state
let activeAuditDetailId = null
function openAuditDetail(id) {
  if (disposed || activeTab.value !== 'audit') return false
  const key = String(id)
  if (activeAuditDetailId !== key && auditDetailEntries.entries[activeAuditDetailId]?.loading) auditDetails.clear()
  activeAuditDetailId = key
  return auditDetails.load(id)
}
function closeAuditDetail(id) {
  const key = String(id)
  if (activeAuditDetailId !== key) return
  activeAuditDetailId = null
  if (auditDetailEntries.entries[key]?.loading) auditDetails.clear()
}
function getAuditDetail(row) { return auditDetailEntries.entries[row.id]?.data || row }
const logPopoverOptions = {
  strategy: 'fixed',
  modifiers: [{ name: 'preventOverflow', options: { altAxis: true, tether: false, padding: 16, rootBoundary: 'viewport' } }],
}

const actionOptions = [
  { label: '全部', value: 'all' },
  { label: '信息', value: 'info' },
  { label: '警告', value: 'warning' },
  { label: '错误', value: 'error' },
]

// --- Failed Jobs State ---
const failedJobs = ref([])
const failedLoading = ref(false)
const failedError = ref('')
const failedPage = ref(1)
const failedPageSize = ref(10)
const failedTotal = ref(0)
const failedDetailVisible = ref(false)
const failedDetailJob = ref(null)
const failedDetailLoading = ref(false)
const failedDetailError = ref('')
let queueController
let detailController
let auditController
let disposed = false
const detailSequence = createSequence()

// ===================== Audit Log =====================
const auditSequence = createSequence()
const failedSequence = createSequence()
onUnmounted(() => {
  disposed = true
  auditSequence.invalidate(); failedSequence.invalidate(); auditDetails.dispose()
  detailSequence.invalidate(); queueController?.abort(); detailController?.abort()
  auditController?.abort()
})

async function loadAuditLogs() {
  if (disposed || activeTab.value !== 'audit') return
  const revision = auditSequence.next()
  auditController?.abort()
  auditController = new AbortController()
  auditLoading.value = true
  auditError.value = ''
  auditDetails.clear()
  try {
    const queryEntries = [
      ['current', auditPage.value],
      ['page_size', auditPageSize.value],
      ['summary', 1],
    ]
    if (activeAction.value !== 'all') {
      queryEntries.push(['level', activeAction.value])
    }
    if (appliedSearchKeyword.value) {
      queryEntries.push(['keyword', appliedSearchKeyword.value])
    }
    const apiUrl = buildDashboardApiUrl('system/getAuditLog', queryEntries)
    const payload = await requestDashboardApi(apiUrl, { signal: auditController.signal })
    if (!auditSequence.isCurrent(revision)) return
    const raw = payload?.data
    if (Array.isArray(raw)) {
      auditLogs.value = raw.map(normalizeSystemLog)
      auditTotal.value = Number(payload?.total ?? raw.length)
    } else {
      throw new Error('日志接口返回格式异常')
    }
  } catch (err) {
    if (!auditSequence.isCurrent(revision)) return
    auditLogs.value = []
    auditError.value = err.message
  } finally {
    if (auditSequence.isCurrent(revision)) auditLoading.value = false
  }
}

// ===================== Failed Jobs =====================

async function loadFailedJobs() {
  if (disposed || activeTab.value === 'audit') return
  const revision = failedSequence.next()
  const type = activeTab.value
  queueController?.abort()
  queueController = new AbortController()
  failedLoading.value = true
  failedError.value = ''
  try {
    const queryEntries = [
      ['current', failedPage.value],
      ['page_size', failedPageSize.value],
      ['type', type],
    ]
    const apiUrl = buildDashboardApiUrl('system/getHorizonJobs', queryEntries)
    const payload = await requestDashboardApi(apiUrl, { signal: queueController.signal })
    if (!failedSequence.isCurrent(revision)) return
    const raw = payload?.data
    if (Array.isArray(raw)) {
      failedJobs.value = raw
      failedTotal.value = Number(payload?.total ?? raw.length)
      queueTotals.value[type] = failedTotal.value
    } else {
      throw new Error('队列任务接口返回格式异常')
    }
  } catch (err) {
    if (!failedSequence.isCurrent(revision)) return
    failedJobs.value = []
    failedError.value = err.message
  } finally {
    if (failedSequence.isCurrent(revision)) failedLoading.value = false
  }
}

function getJobDisplayName(job) {
  if (job.name) return job.name
  if (job.payload) {
    try {
      const p = typeof job.payload === 'string' ? JSON.parse(job.payload) : job.payload
      return p.displayName || p.job || '--'
    } catch { /* ignore */ }
  }
  return '--'
}

function getExceptionSummary(job) {
  const ex = job.exception || ''
  if (!ex) return '--'
  const firstLine = ex.split('\n')[0] || ''
  return firstLine.length > 80 ? firstLine.substring(0, 80) + '...' : firstLine
}

function showJobDetail(job) {
  if (disposed) return
  failedDetailJob.value = job
  failedDetailVisible.value = true
  loadJobDetail()
}

async function loadJobDetail() {
  if (disposed || !failedDetailVisible.value) return
  const id = String(failedDetailJob.value?.id || '')
  if (!id) return
  const revision = detailSequence.next()
  detailController?.abort()
  detailController = new AbortController()
  failedDetailLoading.value = true
  failedDetailError.value = ''
  try {
    const payload = await requestDashboardApi(
      buildDashboardApiUrl('system/getHorizonJobs', [['id', id]]),
      { signal: detailController.signal },
    )
    if (!detailSequence.isCurrent(revision)) return
    if (!payload?.data || String(payload.data.id) !== id) throw new Error('任务详情返回格式异常')
    failedDetailJob.value = payload.data
  } catch (error) {
    if (detailSequence.isCurrent(revision)) failedDetailError.value = error.message || '任务详情加载失败'
  } finally {
    if (detailSequence.isCurrent(revision)) failedDetailLoading.value = false
  }
}

function formatJson(value) { return value == null ? '无记录' : JSON.stringify(value, null, 2) }
function jobStatus(job) {
  return ({ failed: '失败', pending: '等待', reserved: '执行中', completed: '已完成', silenced: '已完成（静默）' })[job.status] || job.status || '--'
}
function jobTime(job) { return job.failed_at || job.completed_at || job.reserved_at }

function buildJobDetailText(job) {
  if (!job) return ''
  const lines = [
    `任务 ID: ${job.id || '--'}`,
    `任务名称: ${getJobDisplayName(job)}`,
    `队列: ${job.queue || '--'}`,
    `连接: ${job.connection || '--'}`,
    `状态: ${jobStatus(job)}`,
    `失败时间: ${formatTime(job.failed_at)}`,
    '',
    '异常堆栈:',
    job.exception || '无异常信息',
    '', '任务数据（已脱敏）:', formatJson(job.payload),
    '', '异常上下文（已脱敏）:', formatJson(job.context),
    '', '重试记录:', formatJson(job.retried_by),
  ]
  return lines.join('\n')
}

// ===================== Helpers =====================

function formatTime(ts) {
  if (ts === null || ts === undefined || ts === '') return '--'
  if (typeof ts === 'string') {
    if (ts.includes('/') || ts.includes('-')) return ts
    const num = Number(ts)
    if (!Number.isNaN(num)) ts = num
  }
  if (typeof ts === 'number') {
    const ms = ts < 1e12 ? ts * 1000 : ts
    const d = new Date(ms)
    if (Number.isNaN(d.getTime())) return String(ts)
    return d.toLocaleString('zh-CN', { hour12: false })
  }
  const d = new Date(ts)
  if (Number.isNaN(d.getTime())) return String(ts)
  return d.toLocaleString('zh-CN', { hour12: false })
}

function levelTagType(level) {
  const map = { info: 'info', warning: 'warning', error: 'danger', critical: 'danger', alert: 'danger', emergency: 'danger' }
  return map[level] || 'info'
}

function searchLogs() {
  appliedSearchKeyword.value = searchKeyword.value.trim()
  if (auditPage.value === 1) loadAuditLogs()
  else auditPage.value = 1
}

function handleRefresh() {
  if (activeTab.value === 'audit') loadAuditLogs()
  else loadFailedJobs()
}

watch(activeAction, () => { auditPage.value = 1 })

watch(() => route.query.tab, (tab) => {
  activeTab.value = queueTabs.some((item) => item.name === tab) ? tab : 'audit'
}, { immediate: true })

watch(activeTab, (tab) => {
  if (tab !== (route.query.tab || 'audit')) router.replace({ query: { ...route.query, tab } })
  failedSequence.invalidate(); queueController?.abort()
  auditSequence.invalidate(); auditController?.abort(); auditLoading.value = false
  auditDetails.clear()
  failedDetailVisible.value = false
  failedJobs.value = []; failedTotal.value = 0; failedPage.value = 1
  failedLoading.value = false
}, { immediate: true })

// Batch page-size, page-reset and tab changes into one list request.
watch([activeTab, auditPage, auditPageSize, activeAction], () => {
  if (activeTab.value === 'audit') loadAuditLogs()
}, { immediate: true })

watch([activeTab, failedPage, failedPageSize], () => {
  if (activeTab.value !== 'audit') loadFailedJobs()
}, { immediate: true })

watch(failedDetailVisible, (visible) => {
  if (!visible) {
    detailSequence.invalidate(); detailController?.abort()
    failedDetailLoading.value = false; failedDetailError.value = ''
  }
})

</script>

<template>
  <section class="page-stack">
    <SectionCard>
      <div class="log-tabs-container">
      <el-button class="log-refresh" :icon="RefreshCw" :loading="auditLoading || failedLoading" @click="handleRefresh">刷新</el-button>
      <el-tabs v-model="activeTab" class="log-tabs">
        <!-- ========== 审计日志 Tab ========== -->
        <el-tab-pane label="运行日志" name="audit">
          <template v-if="activeTab === 'audit'">
          <el-alert v-if="auditError" type="error" :closable="false" :title="auditError" style="margin-bottom:12px;" />

          <div class="log-filter-bar">
            <el-radio-group v-model="activeAction" size="small">
              <el-radio-button v-for="opt in actionOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </el-radio-button>
            </el-radio-group>
            <el-input
              v-model="searchKeyword" clearable placeholder="搜索日志 / 请求地址"
              size="small" style="max-width: 240px;"
              @keyup.enter="searchLogs" @clear="searchLogs"
            />
            <el-button size="small" :loading="auditLoading" @click="searchLogs">搜索</el-button>
          </div>

          <el-table :data="auditLogs" v-loading="auditLoading" class="dashboard-table" max-height="580">
            <el-table-column label="ID" prop="id" width="70" sortable />
            <el-table-column label="级别" width="90">
              <template #default="{ row }">
                <el-tag size="small" :type="levelTagType(row.level)">{{ row.level }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="内容" prop="title" min-width="220" show-overflow-tooltip />
            <el-table-column label="时间" width="170">
              <template #default="{ row }">{{ formatTime(row.createdAt) }}</template>
            </el-table-column>
            <el-table-column label="请求地址" min-width="240" show-overflow-tooltip>
              <template #default="{ row }">
                {{ row.uri || '--' }}
              </template>
            </el-table-column>
            <el-table-column label="详情" width="80">
              <template #default="{ row }">
                <el-popover placement="left" width="min(480px, calc(100vw - 32px))" :popper-options="logPopoverOptions" trigger="click" @show="openAuditDetail(row.id)" @before-leave="closeAuditDetail(row.id)">
                  <template #reference>
                    <el-button link size="small" type="primary">详情</el-button>
                  </template>
                  <div v-loading="auditDetailEntries.entries[row.id]?.loading" style="min-height:80px;max-height: min(400px, 65vh); overflow: auto;overflow-wrap:anywhere;">
                    <el-alert v-if="auditDetailEntries.entries[row.id]?.error" type="error" :closable="false" :title="auditDetailEntries.entries[row.id].error" />
                    <el-button v-if="auditDetailEntries.entries[row.id]?.error" size="small" @click="openAuditDetail(row.id)">重试</el-button>
                    <h4 style="margin:0 0 8px;">{{ getAuditDetail(row).title || '日志详情' }}</h4>
                    <table style="font-size:12px;width:100%;border-collapse:collapse;">
                      <tbody>
                      <tr><td class="detail-label">请求方式</td><td class="detail-value">{{ getAuditDetail(row).method }}</td></tr>
                      <tr><td class="detail-label">URI</td><td class="detail-value" style="word-break:break-all;">{{ getAuditDetail(row).uri }}</td></tr>
                      <tr><td class="detail-label">来源 IP</td><td class="detail-value">{{ getAuditDetail(row).ip }}</td></tr>
                      </tbody>
                    </table>
                    <template v-if="getAuditDetail(row).requestData">
                      <div class="stack-header" style="margin-top:12px;">
                        <h4 style="margin:0;">请求数据</h4>
                        <CopyButton
                          :value="typeof getAuditDetail(row).requestData === 'string' ? getAuditDetail(row).requestData : JSON.stringify(getAuditDetail(row).requestData, null, 2)"
                          label="复制"
                          success-message="已复制请求数据"
                        />
                      </div>
                      <pre class="log-stack-trace">{{ typeof getAuditDetail(row).requestData === 'string' ? getAuditDetail(row).requestData : JSON.stringify(getAuditDetail(row).requestData, null, 2) }}</pre>
                    </template>
                    <template v-if="getAuditDetail(row).context">
                      <h4>上下文</h4>
                      <pre class="log-stack-trace">{{ typeof getAuditDetail(row).context === 'string' ? getAuditDetail(row).context : JSON.stringify(getAuditDetail(row).context, null, 2) }}</pre>
                    </template>
                  </div>
                </el-popover>
              </template>
            </el-table-column>
          </el-table>

          <div class="log-pagination">
            <el-pagination
              v-model:current-page="auditPage" v-model:page-size="auditPageSize"
              :page-sizes="[10, 20, 50, 100]" :total="auditTotal"
              layout="total, sizes, prev, pager, next, jumper"
              @size-change="auditPage = 1"
            />
          </div>
          </template>
        </el-tab-pane>

        <!-- ========== 失败任务 Tab ========== -->
        <el-tab-pane v-for="tab in queueTabs" :key="tab.name" :name="tab.name">
          <template #label>
            {{ tab.label }}
            <el-badge v-if="tab.name === 'failed' && queueTotals.failed > 0" :value="queueTotals.failed" :max="99" style="margin-left:6px;" />
          </template>
        </el-tab-pane>
      </el-tabs>
      <template v-if="activeTab !== 'audit'">

          <el-alert v-if="failedError" type="error" :closable="false" :title="failedError" style="margin-bottom:12px;" />

          <el-table :data="failedJobs" v-loading="failedLoading" class="dashboard-table" max-height="580">
            <el-table-column label="时间" width="170">
              <template #default="{ row }">
                <span style="font-size:12px;">{{ formatTime(jobTime(row)) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="队列" width="140">
              <template #default="{ row }">
                <el-tag size="small" type="info">{{ row.queue || '--' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="任务名称" min-width="200">
              <template #default="{ row }">
                <span style="font-size:12px;font-family:monospace;">{{ getJobDisplayName(row) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="100">
              <template #default="{ row }"><el-tag size="small" :type="row.status === 'failed' ? 'danger' : 'info'">{{ jobStatus(row) }}</el-tag></template>
            </el-table-column>
            <el-table-column v-if="activeTab === 'failed'" label="异常信息" min-width="260">
              <template #default="{ row }">
                <span style="font-size:12px;color:var(--el-color-danger);">{{ getExceptionSummary(row) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="90" align="center" fixed="right">
              <template #default="{ row }">
                <el-button :icon="Eye" link type="primary" @click="showJobDetail(row)">详情</el-button>
              </template>
            </el-table-column>
          </el-table>

          <div class="log-pagination">
            <el-pagination
              v-model:current-page="failedPage" v-model:page-size="failedPageSize"
              :page-sizes="[10, 20, 50]" :total="failedTotal"
              layout="total, sizes, prev, pager, next, jumper"
              @size-change="failedPage = 1"
            />
          </div>
      </template>
      </div>
    </SectionCard>

    <!-- ========== 失败任务详情对话框 ========== -->
    <el-dialog
      v-model="failedDetailVisible"
      :title="failedDetailJob?.status === 'failed' ? '失败任务详情' : '队列任务详情'"
      width="min(720px, calc(100vw - 32px))"
      class="queue-job-dialog"
      destroy-on-close
    >
      <template v-if="failedDetailJob">
        <el-alert v-if="failedDetailError" :title="failedDetailError" type="error" :closable="false" style="margin-bottom:12px" />
        <div v-loading="failedDetailLoading">
        <el-descriptions :column="1" border size="small" style="margin-bottom:16px;">
          <el-descriptions-item label="任务 ID">
            <span style="font-family:monospace;font-size:12px;">{{ failedDetailJob.id }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="任务名称">
            <span style="font-family:monospace;font-size:12px;">{{ getJobDisplayName(failedDetailJob) }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="队列">
            <el-tag size="small" type="info">{{ failedDetailJob.queue || '--' }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="状态">{{ jobStatus(failedDetailJob) }}</el-descriptions-item>
          <el-descriptions-item label="失败时间" v-if="failedDetailJob.failed_at">
            {{ formatTime(failedDetailJob.failed_at) }}
          </el-descriptions-item>
          <el-descriptions-item label="开始时间" v-if="failedDetailJob.reserved_at">{{ formatTime(failedDetailJob.reserved_at) }}</el-descriptions-item>
          <el-descriptions-item label="完成时间" v-if="failedDetailJob.completed_at">{{ formatTime(failedDetailJob.completed_at) }}</el-descriptions-item>
          <el-descriptions-item label="连接">
            {{ failedDetailJob.connection || '--' }}
          </el-descriptions-item>
        </el-descriptions>

        <template v-if="failedDetailJob.exception">
        <div class="stack-header">
          <h4 style="margin:0;font-size:14px;">异常堆栈</h4>
          <CopyButton
            :value="failedDetailJob.exception"
            label="复制堆栈"
            success-message="已复制异常堆栈"
          />
        </div>
        <pre class="log-stack-trace">{{ failedDetailJob.exception || '无异常信息' }}</pre>
        </template>
        <template v-if="!failedDetailLoading && !failedDetailError">
          <div class="stack-header queue-job-data-header"><h4>任务数据（已脱敏）</h4><CopyButton :value="formatJson(failedDetailJob.payload)" label="复制数据" /></div>
          <pre class="log-stack-trace">{{ formatJson(failedDetailJob.payload) }}</pre>
          <p class="queue-job-note">密码、令牌及序列化任务对象不展示；记录超过队列保留时间后会自动清理。</p>
          <template v-if="failedDetailJob.context">
            <div class="stack-header queue-job-data-header"><h4>异常上下文（已脱敏）</h4></div>
            <pre class="log-stack-trace">{{ formatJson(failedDetailJob.context) }}</pre>
          </template>
          <template v-if="failedDetailJob.retried_by?.length">
            <div class="stack-header queue-job-data-header"><h4>重试记录</h4></div>
            <pre class="log-stack-trace">{{ formatJson(failedDetailJob.retried_by) }}</pre>
          </template>
        </template>
        </div>
      </template>

      <template #footer>
        <CopyButton
          v-if="!failedDetailLoading && !failedDetailError"
          :value="buildJobDetailText(failedDetailJob)"
          label="复制全部"
          variant="solid"
          type="default"
          success-message="已复制完整详情"
        />
        <el-button :icon="RefreshCw" :loading="failedDetailLoading" @click="loadJobDetail">{{ failedDetailError ? '重试加载' : '刷新详情' }}</el-button>
        <el-button type="primary" @click="failedDetailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.queue-job-dialog :deep(.el-dialog__body) { max-height: 65vh; overflow-y: auto; overflow-wrap: anywhere; }
.queue-job-dialog :deep(.el-dialog__footer) { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
.queue-job-data-header { margin-top: 16px; }
.queue-job-data-header h4 { margin: 0; font-size: 14px; }
.queue-job-note { color: var(--el-text-color-secondary); font-size: 12px; line-height: 1.6; }
@media (max-width: 600px) {
  .log-pagination { overflow-x: auto; justify-content: flex-start; }
  .log-tabs :deep(.el-tabs__item) { padding-right: 12px; padding-left: 12px; }
}
.log-tabs-container {
  position: relative;
}

.log-refresh {
  position: absolute;
  top: 0;
  right: 0;
  z-index: 1;
}

.log-tabs :deep(.el-tabs__header) {
  margin-right: 92px;
  margin-bottom: 16px;
}

.log-filter-bar {
  margin-bottom: 12px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}

.log-pagination {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}

.detail-label {
  padding: 4px 8px;
  font-weight: 600;
  white-space: nowrap;
}

.detail-value {
  padding: 4px 8px;
}

.stack-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 0 0 8px;
}

.log-stack-trace {
  font-size: 11px;
  font-family: 'Menlo', 'Monaco', 'Consolas', monospace;
  background: #1e1e2e;
  color: #a6adc8;
  padding: 12px;
  border-radius: 8px;
  overflow-x: auto;
  margin: 0;
  white-space: pre-wrap;
  word-break: break-all;
  max-height: 400px;
  overflow-y: auto;
  line-height: 1.5;
}
</style>
