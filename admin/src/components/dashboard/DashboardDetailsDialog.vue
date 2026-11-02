<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, ref, watch } from 'vue'
const UserEditor = defineAsyncComponent(() => import('../../views/UsersPage.vue'))
const NodeEditor = defineAsyncComponent(() => import('../nodes/NodeQuickEditor.vue'))
const editingUser = ref(null), editingNode = ref(null)
import { dashboardDetailTitles, fetchDashboardDetails } from '../../services/dashboardDetails'
import { formatBytes as bytes, formatTimestamp as dateTime } from '../../utils/format'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  metric: { type: String, default: 'online-users' },
})
const emit = defineEmits(['update:modelValue'])
const visible = computed({
  get: () => props.modelValue,
  set: value => emit('update:modelValue', value),
})
const isNodes = computed(() => props.metric === 'online-nodes')
const loading = ref(false)
const error = ref('')
const rows = ref([])
const page = ref(1)
const pageSize = ref(10)
const total = ref(0)
const search = ref('')
const appliedSearch = ref('')
const meta = ref({})
let requestId = 0
let readController
let disposed = false
function cancelRead() { readController?.abort(); readController = null }

async function load() {
  if (disposed || !props.modelValue) return
  const id = ++requestId
  cancelRead()
  const controller = new AbortController()
  readController = controller
  loading.value = true
  error.value = ''
  rows.value = []
  try {
    const result = await fetchDashboardDetails({
      metric: props.metric, page: page.value, pageSize: pageSize.value, search: appliedSearch.value,
      signal: controller.signal,
    })
    // Closing or switching the dialog invalidates responses that are still in flight.
    if (id !== requestId || controller.signal.aborted) return
    rows.value = result.rows
    total.value = result.total
    page.value = result.page
    meta.value = result.meta
  } catch (err) {
    if (id !== requestId || controller.signal.aborted) return
    error.value = err?.message || '加载失败，请重试'
    total.value = 0
    meta.value = {}
  } finally {
    if (id === requestId) loading.value = false
    if (readController === controller) readController = null
  }
}

watch(() => [props.modelValue, props.metric], ([open]) => {
  requestId++
  cancelRead()
  loading.value = false
  editingUser.value = null; editingNode.value = null
  if (!open) return
  page.value = 1
  pageSize.value = 10
  total.value = 0
  search.value = ''
  appliedSearch.value = ''
  meta.value = {}
  load()
}, { immediate: true })
onBeforeUnmount(() => { disposed = true; requestId++; cancelRead(); loading.value = false })

function searchRows() {
  appliedSearch.value = search.value.trim()
  page.value = 1
  load()
}
function changePage(value) {
  page.value = value
  load()
}
function changeSize(value) {
  pageSize.value = value
  page.value = 1
  load()
}
function protocol(value) {
  const key = String(value || '').toLowerCase()
  return { anytls: 'AnyTLS', hysteria: 'Hysteria', hysteria2: 'Hysteria2', shadowsocks: 'Shadowsocks', trojan: 'Trojan', vless: 'VLESS', vmess: 'VMess', tuic: 'TUIC' }[key] || value || '--'
}
function address(row) {
  const host = row.host || '--'
  return `${host.includes(':') && !host.startsWith('[') ? `[${host}]` : host}:${row.port || '--'}`
}
</script>

<template>
  <el-dialog v-model="visible" :title="dashboardDetailTitles[metric]" width="min(880px, calc(100vw - 32px))"
    class="dashboard-details-dialog" align-center destroy-on-close append-to-body>
    <div class="dashboard-details-toolbar">
      <form class="dashboard-details-search" @submit.prevent="searchRows">
        <el-input v-model="search" :placeholder="isNodes ? '搜索节点、协议或入口' : '搜索邮箱或用户 ID'"
          :aria-label="isNodes ? '搜索节点' : '搜索用户'" clearable maxlength="100" @clear="searchRows" />
        <el-button native-type="submit">搜索</el-button>
      </form>
      <el-button :loading="loading" @click="load">刷新</el-button>
    </div>
    <div class="dashboard-details-caption">
      <span v-if="isNodes">按节点心跳统计，子节点继承父节点状态</span>
      <span v-else-if="metric === 'users'">用户列表</span>
      <span v-else>最近 10 分钟有流量上报的用户</span>
      <span v-if="meta.as_of">截至 {{ dateTime(meta.as_of) }}</span>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" class="dashboard-details-error" />
    <el-table v-loading="loading" :data="rows" row-key="key" class="dashboard-details-table"
      max-height="min(440px, 48vh, max(100px, calc(100dvh - 300px)))" :empty-text="loading ? ' ' : (error ? '加载失败，请点击刷新重试' : '暂无符合条件的数据')">
      <template v-if="isNodes">
        <el-table-column prop="name" label="节点" min-width="180" show-overflow-tooltip />
        <el-table-column label="协议" width="105"><template #default="{ row }">{{ protocol(row.protocol) }}</template></el-table-column>
        <el-table-column label="入口" min-width="180" show-overflow-tooltip><template #default="{ row }">{{ address(row) }}</template></el-table-column>
        <el-table-column label="订阅展示" width="100"><template #default="{ row }"><el-tag :type="row.show ? 'success' : 'info'" size="small">{{ row.show ? '显示' : '隐藏' }}</el-tag></template></el-table-column>
        <el-table-column label="最后心跳" min-width="170"><template #default="{ row }">{{ dateTime(row.last_active_at) }}</template></el-table-column>
      </template>
      <template v-else>
        <el-table-column prop="email" label="用户" min-width="180" show-overflow-tooltip />
        <el-table-column prop="device_count" label="设备数" width="80" />
        <el-table-column label="套餐" min-width="120" show-overflow-tooltip><template #default="{ row }">{{ row.plan_name || '未订阅' }}</template></el-table-column>
        <el-table-column v-if="metric === 'users'" label="状态" width="85"><template #default="{ row }"><el-tag :type="row.banned ? 'danger' : (row.is_online ? 'success' : 'info')" size="small">{{ row.banned ? '已封禁' : (row.is_online ? '在线' : '离线') }}</el-tag></template></el-table-column>
        <el-table-column v-if="metric !== 'online-devices'" label="已用流量" min-width="110"><template #default="{ row }">{{ bytes(Number(row.upload) + Number(row.download)) }}</template></el-table-column>
        <el-table-column label="最后流量上报" min-width="175"><template #default="{ row }">{{ dateTime(row.last_active_at) }}</template></el-table-column>
      </template>
      <el-table-column label="操作" width="76" fixed="right"><template #default="{ row }">
        <el-button link type="primary" :disabled="!!editingUser || !!editingNode" @click="isNodes ? editingNode = row.key : editingUser = row.id">编辑</el-button>
      </template></el-table-column>
    </el-table>
    <p v-if="metric === 'online-devices'" class="dashboard-details-device-note">按用户汇总节点上报的设备数；未上报设备数的在线用户按至少 1 台统计。</p>
    <template #footer>
      <div class="dashboard-details-footer">
        <span class="dashboard-details-total">{{ loading ? '加载中' : error ? '未获取数据' : metric === 'online-devices' ? `${total} 位用户 · ${meta.online_devices ?? 0} 台设备` : `${total} ${isNodes ? '个节点' : '位用户'}` }}</span>
        <el-pagination :current-page="page" :page-size="pageSize" :page-sizes="[10, 20, 50]" :total="total"
          :disabled="loading || !!error" :pager-count="5" size="small" layout="sizes, prev, pager, next"
          @current-change="changePage" @size-change="changeSize" />
      </div>
    </template>
  </el-dialog>
  <UserEditor v-if="editingUser" :key="`user-${editingUser}`" :editor-user-id="editingUser" @editor-close="editingUser = null" @editor-saved="load" />
  <NodeEditor v-if="editingNode" :key="editingNode" :node-key="editingNode" @close="editingNode = null" @saved="load" />
</template>

<style scoped>
:global(.el-dialog.dashboard-details-dialog) {
  width: min(880px, calc(100vw - 24px)) !important;
  max-width: none;
  display: flex;
  flex-direction: column;
  align-self: center;
  max-height: calc(100dvh - 24px);
  overflow: hidden;
}
:global(.dashboard-details-dialog > .el-dialog__header),
:global(.dashboard-details-dialog > .el-dialog__footer) {
  flex-shrink: 0;
}
:global(.dashboard-details-dialog > .el-dialog__body) {
  min-height: 0;
  max-height: none;
  overflow-y: auto;
}
.dashboard-details-toolbar, .dashboard-details-footer, .dashboard-details-caption {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.dashboard-details-search { display: flex; gap: 8px; flex: 1; max-width: 420px; min-width: 0; }
.dashboard-details-search .el-input { min-width: 0; }
.dashboard-details-caption { flex-wrap: wrap; margin: 12px 0; }
.dashboard-details-caption, .dashboard-details-total, .dashboard-details-device-note { color: var(--muted); font-size: 12px; }
.dashboard-details-error { margin-bottom: 12px; }
.dashboard-details-table { min-height: 100px; width: 100%; }
.dashboard-details-device-note { margin: 12px 0 0; line-height: 1.6; }
.dashboard-details-footer { flex-wrap: wrap; }
@media (max-width: 600px) {
  .dashboard-details-footer { justify-content: flex-end; gap: 8px; }
  .dashboard-details-total { width: 100%; text-align: left; }
  .dashboard-details-toolbar { gap: 8px; }
}
</style>
