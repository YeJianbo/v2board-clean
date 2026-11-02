<script setup>
import { nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { createSequence } from '../utils/sequence'
import { ElMessage } from 'element-plus'
import { Plus, Download } from 'lucide-vue-next'
import SectionCard from '../components/common/SectionCard.vue'
import {
  fetchGiftCardTemplates,
  createGiftCardTemplate,
  generateGiftCardCodes,
  fetchGiftCardCodes,
  exportGiftCardCodes,
  giftCardBalanceCents,
} from '../services/giftcards'
import { useRouter } from 'vue-router'
import { toPlan } from '../utils/crossLink'
import { fetchManagedPlanOptions } from '../services/plans'

const router = useRouter()
const templates = ref([])
const pagination = reactive({ page: 1, pageSize: 15, total: 0 })
const plans = ref([])
const plansLoading = ref(false)
const plansError = ref('')
const loading = ref(false)
const error = ref('')
const templateDialogVisible = ref(false)
const createEmptyTemplate = () => ({ name: '', kind: 'plan', planId: null, period: 'month_price', price: 0 })
const templateForm = reactive(createEmptyTemplate())
const periodOptions = [
  { value: 'month_price', label: '30 天' },
  { value: 'quarter_price', label: '90 天' },
  { value: 'half_year_price', label: '180 天' },
  { value: 'year_price', label: '365 天' },
  { value: 'two_year_price', label: '730 天' },
  { value: 'three_year_price', label: '1095 天' },
]
const templateFormRef = ref(null)
const templateRules = {
  name: [{ required: true, whitespace: true, message: '请输入模板名称', trigger: 'blur' }],
  planId: [{ required: true, message: '请选择套餐', trigger: 'change' }],
  period: [{ required: true, message: '请选择周期', trigger: 'change' }],
  price: [
    { required: true, message: '请输入充值金额', trigger: 'blur' },
    {
      validator: (_r, v, cb) => {
        try { giftCardBalanceCents(v); cb() } catch (err) { cb(err) }
      },
      trigger: 'blur',
    },
  ],
}
const templateSaving = ref(false)

const codesDialogVisible = ref(false)
const activeTemplate = ref(null)
const codes = ref([])
const codesLoading = ref(false)
const codesPagination = reactive({ page: 1, pageSize: 15, total: 0 })

const generateDialogVisible = ref(false)
const generateCount = ref(1)
const generateTemplateId = ref(null)
const generateSaving = ref(false)
const exportingIds = ref(new Set())
const exportControllers = new Map()
const codesError = ref('')
const templatesSequence = createSequence()
const codesSequence = createSequence()
const plansSequence = createSequence()
let disposed = false
let templatesRefreshAfterWrite = false
let templatesController = null
let codesController = null
let plansController = null
let templatesLoadedPage = 1
let codesLoadedPage = 1
onBeforeUnmount(() => {
  disposed = true
  templatesSequence.next()
  codesSequence.next()
  plansSequence.next()
  templatesController?.abort()
  codesController?.abort()
  plansController?.abort()
  for (const controller of exportControllers.values()) controller.abort()
  exportControllers.clear()
})

async function loadTemplates(options = {}) {
  if (disposed) return
  if (options.afterWrite) templatesRefreshAfterWrite = true
  templatesController?.abort()
  const controller = new AbortController()
  templatesController = controller
  const token = templatesSequence.next()
  loading.value = true
  error.value = ''
  try {
    const result = await fetchGiftCardTemplates({ ...pagination, signal: controller.signal })
    if (!templatesSequence.isCurrent(token)) return
    templates.value = result.list
    Object.assign(pagination, result.pagination)
    templatesLoadedPage = pagination.page
    templatesRefreshAfterWrite = false
  } catch (err) {
    if (templatesSequence.isCurrent(token) && !controller.signal.aborted) {
      if (templatesRefreshAfterWrite) {
        templates.value = []
        pagination.page = 1
        pagination.total = 0
        templatesLoadedPage = 1
      } else {
        pagination.page = templatesLoadedPage
      }
      error.value = templatesRefreshAfterWrite
        ? `操作已成功，刷新模板列表失败：${err.message || '请重试'}`
        : err.message || '模板加载失败'
    }
  } finally {
    if (templatesSequence.isCurrent(token)) loading.value = false
    if (templatesController === controller) templatesController = null
  }
}

async function openTemplateDialog() {
  if (templateSaving.value) return
  Object.assign(templateForm, createEmptyTemplate())
  templateDialogVisible.value = true
  await nextTick()
  templateFormRef.value?.clearValidate()
  await loadPlans()
}

async function loadPlans() {
  if (disposed) return
  plansController?.abort()
  const controller = new AbortController()
  plansController = controller
  const token = plansSequence.next()
  plansLoading.value = true
  plansError.value = ''
  try {
    const list = await fetchManagedPlanOptions({ signal: controller.signal })
    if (plansSequence.isCurrent(token)) plans.value = list
  } catch (err) {
    if (plansSequence.isCurrent(token) && !controller.signal.aborted) plansError.value = err.message || '套餐加载失败'
  } finally {
    if (plansSequence.isCurrent(token)) plansLoading.value = false
    if (plansController === controller) plansController = null
  }
}

async function handleCreateTemplate() {
  if (disposed || templateSaving.value) return
  templateSaving.value = true
  try {
    if (templateFormRef.value) {
      try { await templateFormRef.value.validate() } catch { return }
    }
    if (disposed) return
    await createGiftCardTemplate({ ...templateForm })
    if (disposed) return
    ElMessage.success('模板已创建')
    templateDialogVisible.value = false
    Object.assign(templateForm, createEmptyTemplate())
    pagination.page = 1
    await loadTemplates({ afterWrite: true })
  } catch (err) {
    if (!disposed) ElMessage.error(err.message)
  } finally {
    templateSaving.value = false
  }
}

function openCodesDialog(template) {
  codes.value = []
  codesError.value = ''
  activeTemplate.value = template
  codesPagination.page = 1
  codesPagination.total = 0
  codesLoadedPage = 1
  codesDialogVisible.value = true
  loadCodes()
}

async function loadCodes() {
  if (disposed || !activeTemplate.value || !codesDialogVisible.value) return
  codesController?.abort()
  const controller = new AbortController()
  codesController = controller
  const token = codesSequence.next()
  codesLoading.value = true
  codesError.value = ''
  try {
    const result = await fetchGiftCardCodes(activeTemplate.value.id, {
      page: codesPagination.page,
      pageSize: codesPagination.pageSize,
      signal: controller.signal,
    })
    if (!codesSequence.isCurrent(token)) return
    codes.value = result.list
    Object.assign(codesPagination, result.pagination)
    codesLoadedPage = codesPagination.page
  } catch (err) {
    if (codesSequence.isCurrent(token) && !controller.signal.aborted) {
      codesPagination.page = codesLoadedPage
      codesError.value = err.message || '兑换码加载失败'
    }
  } finally {
    if (codesSequence.isCurrent(token)) codesLoading.value = false
    if (codesController === controller) codesController = null
  }
}

function closeCodesDialog() {
  codesSequence.next()
  codesController?.abort()
  codesController = null
  codesLoading.value = false
  codesError.value = ''
  codes.value = []
  activeTemplate.value = null
  codesPagination.page = 1
  codesPagination.total = 0
  codesLoadedPage = 1
}

function openGenerateDialog(template) {
  if (generateSaving.value) return
  generateTemplateId.value = template.id
  generateCount.value = 1
  generateDialogVisible.value = true
}

async function handleGenerate() {
  if (disposed || generateSaving.value) return
  const n = Number(generateCount.value)
  if (!Number.isInteger(n) || n < 1 || n > 500) {
    ElMessage.warning('生成数量必须是 1 到 500 的整数')
    return
  }
  generateSaving.value = true
  try {
    await generateGiftCardCodes(generateTemplateId.value, n)
    if (disposed) return
    ElMessage.success(`已生成 ${n} 个兑换码`)
    generateDialogVisible.value = false
    await loadTemplates({ afterWrite: true })
  } catch (err) {
    if (!disposed) ElMessage.error(err.message)
  } finally {
    generateSaving.value = false
  }
}

async function handleExport(template) {
  const templateId = template.id
  if (disposed || exportingIds.value.has(templateId)) return
  const controller = new AbortController()
  exportControllers.set(templateId, controller)
  exportingIds.value.add(templateId)
  try {
    const blob = await exportGiftCardCodes(templateId, { signal: controller.signal })
    if (disposed || controller.signal.aborted) return
    const a = document.createElement('a')
    const url = URL.createObjectURL(blob)
    try {
      a.href = url
      a.download = `giftcards_${templateId}.txt`
      document.body.appendChild(a)
      a.click()
    } finally {
      try { a.remove() } finally { URL.revokeObjectURL(url) }
    }
    ElMessage.success('已开始下载')
  } catch (err) {
    if (!disposed && !controller.signal.aborted) ElMessage.error(err.message)
  } finally {
    exportControllers.delete(templateId)
    exportingIds.value.delete(templateId)
  }
}

function formatTime(ts) {
  if (!ts) return '--'
  return new Date(ts * 1000).toLocaleString('zh-CN')
}

function formatPeriod(period) {
  return periodOptions.find(option => option.value === period)?.label || period || '--'
}

onMounted(loadTemplates)
</script>

<template>
  <section class="page-stack">
    <SectionCard header-actions>
      <template #actions>
        <el-button type="primary" :disabled="templateSaving" @click="openTemplateDialog">
          <el-icon><Plus /></el-icon>
          创建模板
        </el-button>
      </template>

      <el-alert v-if="error" type="error" :closable="false" :title="error" class="dashboard-alert">
        <el-button
          link
          type="primary"
          :loading="loading"
          :disabled="loading"
          @click="loadTemplates"
        >
          重试
        </el-button>
      </el-alert>

      <el-table :data="templates" v-loading="loading" class="dashboard-table">
        <el-table-column label="ID" prop="id" width="70" />
        <el-table-column label="模板名称" prop="name" min-width="140" />
        <el-table-column label="绑定套餐" min-width="100">
          <template #default="{ row }">
            <button v-if="toPlan(row.planId)" class="x-link x-link--button" type="button" title="在套餐管理中查看" @click="router.push(toPlan(row.planId))">{{ row.planName || `ID: ${row.planId}` }}</button>
            <span v-else>{{ row.planName || (row.planId ? `ID: ${row.planId}` : '余额充值') }}</span>
          </template>
        </el-table-column>
        <el-table-column label="有效天数" width="100">
          <template #default="{ row }">{{ row.planId ? formatPeriod(row.period) : '--' }}</template>
        </el-table-column>
        <el-table-column label="充值金额" width="110">
          <template #default="{ row }">{{ row.planId ? '--' : `¥${(row.price / 100).toFixed(2)}` }}</template>
        </el-table-column>
        <el-table-column label="码数/已用" width="100">
          <template #default="{ row }">{{ row.codesCount }} / {{ row.usedCount }}</template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button link size="small" type="primary" @click="openCodesDialog(row)">查看码</el-button>
            <el-button link size="small" type="primary" :disabled="generateSaving" @click="openGenerateDialog(row)">生成码</el-button>
            <el-button link size="small" type="warning" :loading="exportingIds.has(row.id)" @click="handleExport(row)">
              <el-icon><Download /></el-icon> 导出
            </el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        :current-page="pagination.page"
        :page-size="pagination.pageSize"
        :total="pagination.total"
        :pager-count="5"
        layout="total, prev, pager, next"
        @current-change="(page) => { pagination.page = page; loadTemplates() }"
      />
    </SectionCard>

    <!-- Create Template Dialog -->
    <el-dialog v-model="templateDialogVisible" title="创建礼品卡模板" width="min(480px, calc(100vw - 32px))" class="gift-card-dialog" top="4vh" append-to-body :close-on-click-modal="!templateSaving" :close-on-press-escape="!templateSaving" :show-close="!templateSaving">
      <el-form
        ref="templateFormRef"
        :model="templateForm"
        :rules="templateRules"
        label-position="top"
        :disabled="templateSaving"
      >
        <el-form-item label="模板名称" prop="name">
          <el-input v-model="templateForm.name" maxlength="255" placeholder="请输入模板名称" />
        </el-form-item>
        <el-form-item label="礼品卡类型">
          <el-radio-group v-model="templateForm.kind" @change="templateFormRef?.clearValidate()">
            <el-radio-button value="plan">套餐卡</el-radio-button>
            <el-radio-button value="balance">余额卡</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-alert v-if="plansError && templateForm.kind === 'plan'" :title="plansError" type="error" :closable="false">
          <el-button link type="primary" @click="loadPlans">重试</el-button>
        </el-alert>
        <el-form-item v-if="templateForm.kind === 'plan'" label="绑定套餐" prop="planId">
          <el-select v-model="templateForm.planId" filterable :loading="plansLoading" placeholder="搜索套餐名称" style="width: 100%;">
            <el-option v-for="plan in plans" :key="plan.id" :value="plan.id" :label="plan.name" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="templateForm.kind === 'plan'" label="有效天数" prop="period">
          <el-select v-model="templateForm.period" style="width: 100%;">
            <el-option v-for="option in periodOptions" :key="option.value" :value="option.value" :label="option.label" />
          </el-select>
        </el-form-item>
        <el-form-item v-else label="充值金额（元）" prop="price">
          <el-input-number v-model="templateForm.price" :min="0.01" :precision="2" style="width: 100%;" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="templateSaving" @click="templateDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="templateSaving" @click="handleCreateTemplate">创建</el-button>
      </template>
    </el-dialog>

    <!-- Generate Codes Dialog -->
    <el-dialog v-model="generateDialogVisible" title="生成兑换码" width="min(360px, calc(100vw - 32px))" class="gift-card-dialog" top="4vh" append-to-body :close-on-click-modal="!generateSaving" :close-on-press-escape="!generateSaving" :show-close="!generateSaving">
      <el-form label-position="top" :disabled="generateSaving">
        <el-form-item label="生成数量">
          <el-input-number v-model="generateCount" :min="1" :max="500" style="width: 100%;" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="generateSaving" @click="generateDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="generateSaving" @click="handleGenerate">生成</el-button>
      </template>
    </el-dialog>

    <!-- View Codes Dialog -->
    <el-dialog v-model="codesDialogVisible" :title="`兑换码列表 - ${activeTemplate?.name || ''}`" width="min(680px, calc(100vw - 32px))" class="gift-card-dialog" top="4vh" append-to-body @close="closeCodesDialog">
      <el-alert v-if="codesError" :title="codesError" type="error" :closable="false" class="dashboard-alert">
        <el-button link type="primary" @click="loadCodes">重试</el-button>
      </el-alert>
      <el-table :data="codes" v-loading="codesLoading" class="dashboard-table">
        <el-table-column label="兑换码" prop="code" min-width="200">
          <template #default="{ row }">
            <code style="font-size: 12px;">{{ row.code }}</code>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.statusType" size="small">
              {{ row.statusText }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="使用时间" width="170">
          <template #default="{ row }">{{ formatTime(row.usedAt) }}</template>
        </el-table-column>
      </el-table>
      <div style="display: flex; justify-content: flex-end; margin-top: 12px;">
        <el-pagination
          :current-page="codesPagination.page"
          :page-size="codesPagination.pageSize"
          :total="codesPagination.total"
          layout="total, prev, pager, next"
          @current-change="(p) => { codesPagination.page = p; loadCodes() }"
        />
      </div>
    </el-dialog>
  </section>
</template>

<style scoped>
:global(.el-dialog.gift-card-dialog) {
  display: flex;
  flex-direction: column;
  max-height: calc(100dvh - 32px);
}
:global(.el-dialog.gift-card-dialog .el-dialog__body) {
  flex: 1;
  min-height: 0;
  overflow: auto;
}
:global(.el-dialog.gift-card-dialog .el-dialog__header),
:global(.el-dialog.gift-card-dialog .el-dialog__footer) {
  flex-shrink: 0;
}
</style>
