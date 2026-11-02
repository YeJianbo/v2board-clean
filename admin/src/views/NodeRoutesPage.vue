<script setup>
import { ref, onMounted, onUnmounted, computed, watch, nextTick } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, RefreshCw } from 'lucide-vue-next'
import SectionCard from '../components/common/SectionCard.vue'
import { createSequence } from '../utils/sequence'
import { ensureChinaBlockRoutes } from '../services/nodeRoutePresets'
import {
  fetchManagedNodeRoutes,
  saveManagedNodeRoute,
  deleteManagedNodeRoute,
} from '../services/nodes'

const routes = ref([])
const loading = ref(false)
const error = ref('')
const searchWord = ref('')
const pagination = ref({
  page: 1,
  pageSize: 20,
})

const dialogVisible = ref(false)
const dialogMode = ref('create')
const form = ref(createEmptyForm())
const saving = ref(false)
const presetSaving = ref(false)
const deletingId = ref(null)
const mutationBusy = computed(() => saving.value || presetSaving.value || deletingId.value !== null)
const routesSequence = createSequence()
let disposed = false
let routesController = null
function disposeRouteReads() {
  disposed = true
  routesSequence.invalidate()
  routesController?.abort()
  routesController = null
}
onUnmounted(disposeRouteReads)
async function addChinaPreset() {
  if (disposed || mutationBusy.value) return
  presetSaving.value = true
  const revision = routesSequence.next()
  routesController?.abort()
  routesController = null
  loading.value = false
  try {
    const result = await ensureChinaBlockRoutes()
    if (disposed) return
    if (routesSequence.isCurrent(revision)) {
      routes.value = result
      error.value = ''
    } else {
      await loadRoutes()
      if (disposed) return
    }
    ElMessage.success('禁止回国规则已就绪，可在节点编辑中启用')
  } catch (err) { if (!disposed) ElMessage.error(err.message || '创建规则失败') }
  finally { if (!disposed) presetSaving.value = false }
}

function createEmptyForm() {
  return {
    id: null,
    remarks: '',
    matchText: '',
    action: 'block',
    action_value: '',
  }
}

const actionOptions = [
  { label: '禁止访问(域名目标)', value: 'block' },
  { label: '禁止访问(IP目标)', value: 'block_ip' },
  { label: '禁止访问(端口目标)', value: 'block_port' },
  { label: '禁止访问(协议)', value: 'protocol' },
  { label: '指定DNS服务器进行解析', value: 'dns' },
  { label: '指定出站服务器(域名目标)', value: 'route' },
  { label: '指定出站服务器(IP目标)', value: 'route_ip' },
  { label: '自定义默认出站', value: 'default_out' },
]

const matchTextPlaceholder = computed(() => {
  if (['block_ip', 'route_ip'].includes(form.value.action)) {
    return '127.0.0.1(单一匹配)\n10.0.0.0/8(范围匹配)\ngeoip:cn(预定义列表匹配)'
  }
  if (form.value.action === 'block_port') {
    return '53\n443\n1000-2000'
  }
  if (form.value.action === 'protocol') {
    return 'http\ntls\nquic\nbittorrent'
  }
  return 'example.com(关键字匹配)\ndomain:example.com(子域名匹配)\ngeosite:netflix(预定义域名列表)'
})

function fillDefaultOutboundConfig() {
  // Placeholder values are rejected by the server until the example is completed.
  form.value.action_value = `{
  "tag": "<请填写出站标签>",
  "sendThrough": "0.0.0.0",
  "protocol": "shadowsocks",
  "settings": {
    "email": "<请填写邮箱>",
    "address": "<请填写出站节点地址>",
    "port": 0,
    "method": "2022-blake3-aes-128-gcm",
    "password": "<请填写出站节点密码>"
  }
}`
}

// Actions that need no action_value at all
const NO_VALUE_ACTIONS = ['block', 'block_ip', 'block_port', 'protocol']

let watchIgnore = false

// Clear action_value when switching action type to prevent cross-contamination
watch(() => form.value.action, (newAction, oldAction) => {
  if (watchIgnore) return
  if (newAction === oldAction) return
  // Determine category of old and new to decide if we should reset
  const getCategory = (a) => {
    if (NO_VALUE_ACTIONS.includes(a)) return 'none'
    if (a === 'dns') return 'dns'
    if (['route', 'route_ip', 'default_out'].includes(a)) return 'outbound'
    return 'other'
  }
  if (getCategory(newAction) !== getCategory(oldAction)) {
    form.value.action_value = ''
  }
})

async function loadRoutes() {
  if (disposed) return
  routesController?.abort()
  const controller = new AbortController()
  routesController = controller
  const revision = routesSequence.next()
  loading.value = true
  error.value = ''
  try {
    const result = await fetchManagedNodeRoutes({ signal: controller.signal })
    if (disposed || !routesSequence.isCurrent(revision)) return
    routes.value = result
  } catch (err) {
    if (disposed || controller.signal.aborted || !routesSequence.isCurrent(revision)) return
    error.value = err.message || '加载路由失败'
  } finally {
    if (!disposed && routesSequence.isCurrent(revision)) loading.value = false
    if (routesController === controller) routesController = null
  }
}

const displayRoutes = computed(() => {
  const start = (pagination.value.page - 1) * pagination.value.pageSize
  const end = start + pagination.value.pageSize

  return filteredRoutes.value.slice(start, end)
})

const filteredRoutes = computed(() => {
  if (!searchWord.value.trim()) return routes.value
  const kw = searchWord.value.trim().toLowerCase()
  return routes.value.filter(r => (
    r.remarks.toLowerCase().includes(kw) ||
    String(r.id).includes(kw) ||
    r.actionLabel.toLowerCase().includes(kw)
  ))
})

const totalRoutes = computed(() => filteredRoutes.value.length)

watch(searchWord, () => {
  pagination.value.page = 1
})

watch(totalRoutes, (total) => {
  const maxPage = Math.max(Math.ceil(total / pagination.value.pageSize), 1)
  if (pagination.value.page > maxPage) {
    pagination.value.page = maxPage
  }
})

function openCreateDialog() {
  if (disposed || mutationBusy.value) return
  dialogMode.value = 'create'
  watchIgnore = true
  form.value = createEmptyForm()
  dialogVisible.value = true
  nextTick(() => { watchIgnore = false })
}

function openEditDialog(route) {
  if (disposed || mutationBusy.value) return
  dialogMode.value = 'edit'
  watchIgnore = true
  form.value = {
    id: route.id,
    remarks: route.remarks,
    matchText: Array.isArray(route.match) ? route.match.join('\n') : '',
    action: route.action || 'block',
    action_value: route.actionValue || '',
  }
  dialogVisible.value = true
  nextTick(() => { watchIgnore = false })
}

async function handleSave() {
  if (disposed || mutationBusy.value || !dialogVisible.value) return
  if (!form.value.remarks?.trim()) {
    ElMessage.warning('请输入备注')
    return
  }
  const matchArr = form.value.matchText
    .split('\n')
    .map(s => s.trim())
    .filter(s => s.length > 0)
  if (form.value.action !== 'default_out' && matchArr.length === 0) {
    ElMessage.warning('请输入至少一条匹配规则')
    return
  }
  // 当 action 是 JSON 类型时（出站配置 / DNS），校验 JSON 合法性，
  // 避免把脏字符串推给后端造成 500。
  const JSON_VALUE_ACTIONS = ['route', 'route_ip', 'default_out']
  if (JSON_VALUE_ACTIONS.includes(form.value.action) && form.value.action_value?.trim()) {
    try {
      JSON.parse(form.value.action_value)
    } catch (e) {
      ElMessage.error(`动作参数不是合法 JSON：${e?.message || ''}`)
      return
    }
  }
  saving.value = true
  const draft = { ...form.value }
  const mode = dialogMode.value
  try {
    await saveManagedNodeRoute({
      id: draft.id || undefined,
      remarks: draft.remarks.trim(),
      match: matchArr,
      action: draft.action,
      action_value: draft.action_value || '',
    })
    if (disposed) return
    ElMessage.success(mode === 'create' ? '路由已创建' : '路由已更新')
    dialogVisible.value = false
    await loadRoutes()
  } catch (err) {
    if (!disposed) ElMessage.error(err.message || '保存失败')
  } finally {
    if (!disposed) saving.value = false
  }
}

async function handleDelete(route) {
  if (disposed || mutationBusy.value) return
  const id = route.id
  const remarks = route.remarks
  deletingId.value = id
  try {
    await ElMessageBox.confirm(`确定要删除路由「${remarks}」吗？`, '删除确认', { type: 'warning' })
    if (disposed) return
    await deleteManagedNodeRoute(id)
    if (disposed) return
    ElMessage.success('路由已删除')
    await loadRoutes()
  } catch (err) {
    if (!disposed && err !== 'cancel' && err !== 'close') ElMessage.error(err.message || '删除失败')
  } finally {
    if (!disposed) deletingId.value = null
  }
}

function handleDialogBeforeClose(done) {
  if (!saving.value) done()
}

function actionTagType(action) {
  if (['block', 'block_ip', 'block_port', 'protocol'].includes(action)) return 'danger'
  if (action === 'dns') return 'primary'
  if (['route', 'route_ip'].includes(action)) return 'warning'
  if (action === 'default_out') return 'success'
  return 'info'
}

function handlePageChange(page) {
  pagination.value.page = page
}

function handlePageSizeChange(size) {
  pagination.value.pageSize = size
  pagination.value.page = 1
}

onMounted(loadRoutes)
</script>

<template>
  <section class="page-stack">
    <SectionCard>
      <template #actions>
        <el-button :loading="presetSaving" :disabled="mutationBusy && !presetSaving" @click="addChinaPreset">禁止回国规则</el-button>
        <el-button :icon="Plus" type="primary" :disabled="mutationBusy" @click="openCreateDialog">添加路由</el-button>
        <el-button :icon="RefreshCw" plain type="info" title="刷新" aria-label="刷新" :loading="loading" @click="loadRoutes" />
      </template>
      <template #filters>
        <el-input v-model="searchWord" placeholder="搜索路由" aria-label="搜索路由" clearable style="width: 180px" />
      </template>

      <el-alert v-if="error" type="error" :closable="false" :title="error" class="dashboard-alert" />

      <el-table :data="displayRoutes" v-loading="loading" class="dashboard-table">
        <el-table-column label="ID" prop="id" width="70" sortable />
        <el-table-column label="备注" prop="remarks" min-width="140" />
        <el-table-column label="动作值" min-width="200">
          <template #default="{ row }">
            <div>
              <div class="route-value" style="font-size:13px; color:var(--el-text-color-primary)">{{ row.actionDisplayValue }}</div>
              <div style="font-size:11px; color:var(--el-text-color-secondary); margin-top:2px">
                匹配{{ row.matchCount }}条规则
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="动作" width="200">
          <template #default="{ row }">
            <el-tag :type="actionTagType(row.action)" size="small">
              {{ row.actionLabel }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link size="small" type="primary" :aria-label="`编辑路由 ${row.remarks}`" :disabled="mutationBusy" @click="openEditDialog(row)">编辑</el-button>
            <el-button link size="small" type="danger" :aria-label="`删除路由 ${row.remarks}`" :disabled="mutationBusy" :loading="deletingId === row.id" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="totalRoutes"
        :current-page="pagination.page"
        :page-size="pagination.pageSize"
        :page-sizes="[10, 20, 50, 100]"
        :total="totalRoutes"
        :pager-count="5"
        background
        layout="total, sizes, prev, pager, next, jumper"
        class="route-pagination"
        @current-change="handlePageChange"
        @size-change="handlePageSizeChange"
      />
    </SectionCard>

    <!-- 创建/编辑路由对话框 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogMode === 'create' ? '创建路由' : '编辑路由'"
      width="min(520px, calc(100vw - 32px))"
      class="node-route-dialog"
      top="4vh"
      destroy-on-close
      :show-close="!saving"
      :close-on-click-modal="!saving"
      :close-on-press-escape="!saving"
      :before-close="handleDialogBeforeClose"
    >
      <div class="route-form">
        <div class="route-form__field">
          <label for="node-route-remarks">备注</label>
          <el-input id="node-route-remarks" v-model="form.remarks" :disabled="saving" maxlength="255" placeholder="请输入备注" />
        </div>

        <div v-if="form.action !== 'default_out'" class="route-form__field">
          <label for="node-route-match" style="display: flex; align-items: center; gap: 12px;">
            匹配值
            <el-link type="primary" underline="never" href="https://xtls.github.io/config/routing.html" target="_blank" style="font-size: 13px; font-weight: 600;">填写参考</el-link>
          </label>
          <el-input
            v-model="form.matchText"
            id="node-route-match"
            :spellcheck="false"
            :disabled="saving"
            type="textarea"
            :autosize="{ minRows: 4, maxRows: 10 }"
            :placeholder="matchTextPlaceholder"
          />
        </div>

        <div class="route-form__field">
          <label for="node-route-action">动作</label>
          <el-select id="node-route-action" v-model="form.action" :disabled="saving" style="width: 100%">
            <el-option
              v-for="opt in actionOptions"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </div>

        <div v-if="form.action === 'dns'" class="route-form__field">
          <label for="node-route-dns">DNS服务器</label>
          <el-input id="node-route-dns" v-model="form.action_value" :disabled="saving" :spellcheck="false" placeholder="请输入用于解析的DNS服务器地址" />
        </div>

        <div v-if="['route', 'route_ip', 'default_out'].includes(form.action)" class="route-form__field">
          <label for="node-route-outbound" style="display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
              Xray出站配置
              <el-link type="primary" underline="never" href="https://xtls.github.io/config/outbound.html" target="_blank" style="font-size: 13px; font-weight: 600;">填写参考</el-link>
            </div>
            <el-button link type="primary" size="small" :disabled="saving" @click="fillDefaultOutboundConfig">填入示例</el-button>
          </label>
          <el-input
            v-model="form.action_value"
            id="node-route-outbound"
            :spellcheck="false"
            :disabled="saving"
            type="textarea"
            :autosize="{ minRows: 8, maxRows: 15 }"
            placeholder="{&#10;  &#x22;tag&#x22;: &#x22;ss_out&#x22;,&#10;  &#x22;sendThrough&#x22;: &#x22;0.0.0.0&#x22;,&#10;  ..."
          />
        </div>
      </div>
      <template #footer>
        <el-button :disabled="saving" @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.route-value {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  overflow-wrap: anywhere;
}

.route-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.route-form__field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.route-form__field label {
  font-size: 14px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.route-form__hint {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.route-pagination {
  justify-content: flex-end;
  margin-top: 16px;
}

:global(.el-dialog.node-route-dialog) {
  display: flex;
  flex-direction: column;
  max-height: calc(100dvh - 8vh);
}

:global(.node-route-dialog .el-dialog__body) {
  min-height: 0;
  overflow: auto;
}

:global(.node-route-dialog .el-dialog__footer) {
  flex-shrink: 0;
}

@media (max-width: 640px) {
  .route-pagination {
    justify-content: center;
    flex-wrap: wrap;
    gap: 8px;
  }

  .route-pagination :deep(.el-pagination__sizes),
  .route-pagination :deep(.el-pagination__jump) {
    display: none;
  }

  .route-pagination :deep(.el-pagination__total) {
    flex-basis: 100%;
  }
}
</style>
