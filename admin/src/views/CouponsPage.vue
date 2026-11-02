<script setup>
import { onMounted, onBeforeUnmount, reactive, ref } from 'vue'
import { createSequence } from '../utils/sequence'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Trash2, Pencil, Search } from 'lucide-vue-next'
import SectionCard from '../components/common/SectionCard.vue'
import {
  fetchManagedCoupons,
  couponToForm,
  generateCoupons,
  deleteCoupon,
  toggleCouponShow,
  normalizeCouponValueForApi,
} from '../services/coupons'
import { fetchManagedPlanOptions, PERIOD_LABELS } from '../services/plans'
import { useRoute } from 'vue-router'

const route = useRoute()
const coupons = ref([])
const plans = ref([])
const plansLoading = ref(false)
const plansLoaded = ref(false)
const plansError = ref('')
const loading = ref(false)
const error = ref('')
const pagination = reactive({ page: 1, pageSize: 15, total: 0 })
const filters = reactive({ keyword: '' })
const appliedFilters = reactive({ keyword: '' })
const dialogVisible = ref(false)
const dialogMode = ref('create')
const listSequence = createSequence()
const togglingIds = ref(new Set())
const deletingIds = ref(new Set())
let disposed = false
let couponsRefreshAfterWrite = false
let listController = null
const pageController = new AbortController()
onBeforeUnmount(() => {
  disposed = true
  listSequence.next()
  listController?.abort()
  pageController.abort()
})

const PERIOD_OPTIONS = Object.entries(PERIOD_LABELS).map(([value, label]) => ({ value, label }))

const couponTypeOptions = [
  { value: 1, label: '按金额' },
  { value: 2, label: '按比例' },
]

const couponTypeLabels = {
  1: '按金额',
  2: '按比例',
  3: '重置流量',
}

const supportedCouponTypes = couponTypeOptions.map(option => option.value)

const defaultForm = () => ({
  id: null,
  name: '',
  code: '',
  type: 1,
  value: 0,
  generateCount: 1,
  limitUse: null,
  initialLimitUse: null,
  limitUseWithUser: null,
  limitPlanIds: [],
  limitPeriod: [],
  dateRange: null,
  show: true,
})

const form = reactive(defaultForm())

function valueSuffix() {
  if (form.type === 1) return '¥'
  if (form.type === 2) return '%'
  return ''
}

function valuePlaceholder() {
  if (form.type === 1) return '请输入金额，如 8.88'
  if (form.type === 2) return '请输入整数比例，如 10'
  return '0'
}

function valueStep() {
  return form.type === 1 ? '0.01' : '1'
}

async function loadPlans() {
  if (disposed || plansLoading.value || plansLoaded.value) return
  plansLoading.value = true
  plansError.value = ''
  try {
    const list = await fetchManagedPlanOptions({ signal: pageController.signal })
    if (disposed) return
    plans.value = list
    plansLoaded.value = true
  } catch (err) {
    if (!disposed && !pageController.signal.aborted) plansError.value = err.message || '套餐选项加载失败'
  } finally {
    plansLoading.value = false
  }
}

async function loadCoupons({ afterWrite = false } = {}) {
  if (disposed) return
  if (afterWrite) couponsRefreshAfterWrite = true
  const token = listSequence.next()
  listController?.abort()
  const controller = new AbortController()
  listController = controller
  loading.value = true
  error.value = ''
  coupons.value = []
  try {
    const result = await fetchManagedCoupons({ page: pagination.page, pageSize: pagination.pageSize, filters: { ...appliedFilters }, signal: controller.signal })
    if (!listSequence.isCurrent(token)) return
    Object.assign(pagination, result.pagination)
    const lastPage = Math.max(1, Math.ceil(pagination.total / pagination.pageSize))
    if (pagination.page > lastPage) {
      pagination.page = lastPage
      await loadCoupons({ afterWrite })
      return
    }
    coupons.value = result.list
    couponsRefreshAfterWrite = false
  } catch (err) {
    if (!listSequence.isCurrent(token) || controller.signal.aborted) return
    error.value = couponsRefreshAfterWrite ? `操作已成功，但列表刷新失败：${err.message}` : err.message
  } finally {
    if (listController === controller) listController = null
    if (listSequence.isCurrent(token)) loading.value = false
  }
}

function openCreateDialog() {
  if (disposed || saving.value) return
  dialogMode.value = 'create'
  Object.assign(form, defaultForm())
  dialogVisible.value = true
  loadPlans()
}

function openEditDialog(coupon) {
  if (disposed || saving.value || deletingIds.value.has(coupon.id) || togglingIds.value.has(coupon.id)) return
  if (!supportedCouponTypes.includes(Number(coupon.type))) {
    ElMessage.warning('当前后端不支持编辑该优惠券类型')
    return
  }

  dialogMode.value = 'edit'
  Object.assign(form, couponToForm(coupon))
  dialogVisible.value = true
  loadPlans()
}

function validateCouponForm() {
  const name = String(form.name || '').trim()
  const value = Number(form.value)
  const generateCount = Number(form.generateCount || 1)
  const limitUse = form.limitUse === null || form.limitUse === '' ? null : Number(form.limitUse)
  const limitUseWithUser = form.limitUseWithUser === null || form.limitUseWithUser === '' ? null : Number(form.limitUseWithUser)

  if (!name) {
    ElMessage.warning('请输入优惠券名称')
    return false
  }

  if (!supportedCouponTypes.includes(Number(form.type))) {
    ElMessage.warning('请选择后端支持的优惠券类型')
    return false
  }

  if (!Number.isFinite(value) || value <= 0) {
    ElMessage.warning('请输入有效的优惠金额或比例')
    return false
  }

  if (form.type === 2 && !Number.isInteger(value)) {
    ElMessage.warning('比例优惠必须填写整数')
    return false
  }

  try { normalizeCouponValueForApi(form.type, form.value) } catch (err) {
    ElMessage.warning(err.message)
    return false
  }

  if (!Array.isArray(form.dateRange) || !form.dateRange[0] || !form.dateRange[1]) {
    ElMessage.warning('请选择优惠券有效期')
    return false
  }

  const startedAt = new Date(form.dateRange[0]).getTime()
  const endedAt = new Date(form.dateRange[1]).getTime()
  if (!Number.isFinite(startedAt) || !Number.isFinite(endedAt) || endedAt <= startedAt) {
    ElMessage.warning('优惠券结束时间必须晚于开始时间')
    return false
  }

  if (dialogMode.value === 'create') {
    if (!Number.isInteger(generateCount) || generateCount < 1 || generateCount > 500) {
      ElMessage.warning('批量生成数量必须是 1 到 500 的整数')
      return false
    }

  }

  if (limitUse !== null && (!Number.isInteger(limitUse) || limitUse < 0)) {
    ElMessage.warning('最大使用次数必须是非负整数')
    return false
  }

  if (limitUseWithUser !== null && (!Number.isInteger(limitUseWithUser) || limitUseWithUser < 0)) {
    ElMessage.warning('每个用户可使用次数必须是非负整数')
    return false
  }

  return true
}

const saving = ref(false)

async function handleSave() {
  if (disposed || saving.value) return
  if (!validateCouponForm()) {
    return
  }

  const payload = {
    id: form.id || undefined,
    name: form.name.trim(),
    type: form.type,
    value: form.value,
    generateCount: (!form.id && form.generateCount > 1) ? form.generateCount : undefined,
    code: dialogMode.value === 'create' && form.generateCount > 1 ? undefined : form.code.trim() || undefined,
    limitUse: form.limitUse == null || form.limitUse === '' ? null : Number(form.limitUse),
    limitUseWithUser: form.limitUseWithUser == null || form.limitUseWithUser === '' ? null : Number(form.limitUseWithUser),
    limitPlanIds: Array.isArray(form.limitPlanIds) ? form.limitPlanIds : [],
    limitPeriod: Array.isArray(form.limitPeriod) ? form.limitPeriod : [],
    startedAt: form.dateRange?.[0] ? Math.floor(new Date(form.dateRange[0]).getTime() / 1000) : null,
    endedAt: form.dateRange?.[1] ? Math.floor(new Date(form.dateRange[1]).getTime() / 1000) : null,
    // Retain the original visibility when an older backend accepts it here.
    show: form.id ? (form.show === false ? 0 : 1) : undefined,
  }
  if (form.id && payload.limitUse === (form.initialLimitUse == null ? null : Number(form.initialLimitUse))) {
    // Usage can change while this editor is open; only an explicit edit replaces the live count.
    delete payload.limitUse
  }
  saving.value = true
  try {
    await generateCoupons(payload)
    if (disposed) return
    ElMessage.success(form.id ? '优惠券已保存' : '优惠券已生成')
    dialogVisible.value = false
    await loadCoupons({ afterWrite: true })
  } catch (err) {
    if (!disposed) ElMessage.error(err.message)
  } finally {
    saving.value = false
  }
}

async function handleDelete(coupon) {
  if (disposed || deletingIds.value.has(coupon.id) || togglingIds.value.has(coupon.id)) return
  deletingIds.value.add(coupon.id)
  try {
    await ElMessageBox.confirm(`确认删除优惠券「${coupon.name}」吗？`, '删除优惠券', {
      type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消',
    })
    if (disposed) return
    await deleteCoupon(coupon.id)
    if (disposed) return
    ElMessage.success('优惠券已删除')
    await loadCoupons({ afterWrite: true })
  } catch (err) {
    if (!disposed && err !== 'cancel' && err !== 'close') ElMessage.error(err.message || '删除失败')
  } finally {
    deletingIds.value.delete(coupon.id)
  }
}

async function handleToggleShow(coupon) {
  if (disposed || togglingIds.value.has(coupon.id) || deletingIds.value.has(coupon.id)) return
  togglingIds.value.add(coupon.id)
  try {
    await toggleCouponShow(coupon.id)
    if (disposed) return
    ElMessage.success('状态已更新')
    await loadCoupons({ afterWrite: true })
  } catch (err) {
    if (!disposed) ElMessage.error(err.message)
  } finally {
    togglingIds.value.delete(coupon.id)
  }
}

function handlePageChange(page) {
  pagination.page = page
  return loadCoupons()
}

function handleSearch() {
  appliedFilters.keyword = filters.keyword.trim()
  pagination.page = 1
  return loadCoupons()
}

function formatCouponType(type) {
  return couponTypeLabels[type] || '未知'
}

function formatCouponValue(coupon) {
  if (coupon.type === 1) return `¥${(coupon.value / 100).toFixed(2)}`
  if (coupon.type === 2) return `${coupon.value}%`
  return '--'
}

function formatTime(ts) {
  if (!ts) return '--'
  return new Date(ts * 1000).toLocaleString('zh-CN')
}

onMounted(() => {
  // Order-detail links can supply either the name or the coupon code.
  if (route.query.coupon_name) filters.keyword = String(route.query.coupon_name)
  else if (route.query.coupon_code) filters.keyword = String(route.query.coupon_code)
  handleSearch()
})
</script>

<template>
  <section class="page-stack">
    <SectionCard>
      <template #filters>
        <el-input
          v-model="filters.keyword"
          clearable
          placeholder="搜索名称或优惠码"
          maxlength="255"
          style="width: 240px;"
          @keyup.enter="handleSearch"
          @clear="handleSearch"
        >
          <template #prefix><el-icon><Search /></el-icon></template>
        </el-input>
        <el-button :loading="loading" @click="handleSearch">搜索</el-button>
      </template>
      <template #actions>
        <el-button type="primary" :disabled="saving" @click="openCreateDialog">
          <el-icon><Plus /></el-icon>
          添加优惠券
        </el-button>
      </template>

      <el-alert v-if="error" type="error" :closable="false" :title="error" class="dashboard-alert">
        <el-button link type="primary" :loading="loading" :disabled="loading" @click="loadCoupons">重试</el-button>
      </el-alert>

      <el-table :data="coupons" v-loading="loading" class="dashboard-table">
        <el-table-column label="ID" prop="id" width="70" />
        <el-table-column label="名称" prop="name" min-width="120" />
        <el-table-column label="代码" min-width="140">
          <template #default="{ row }">
            <code style="font-size: 12px; background: rgba(0,0,0,.04); padding: 2px 6px; border-radius: 4px;">{{ row.code }}</code>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="100">
          <template #default="{ row }">
            <el-tag size="small" :type="row.type === 1 ? 'primary' : row.type === 2 ? 'success' : 'info'">
              {{ formatCouponType(row.type) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="面值" width="100">
          <template #default="{ row }">{{ formatCouponValue(row) }}</template>
        </el-table-column>
        <el-table-column label="使用限制" width="80">
          <template #default="{ row }">{{ row.limitUse ?? '不限' }}</template>
        </el-table-column>
        <el-table-column label="显示" width="70">
          <template #default="{ row }">
            <el-switch :model-value="row.show" :aria-label="`启用优惠券 ${row.name}`" :loading="togglingIds.has(row.id)" :disabled="deletingIds.has(row.id)" size="small" @change="handleToggleShow(row)" />
          </template>
        </el-table-column>
        <el-table-column label="有效期" min-width="150">
          <template #default="{ row }">
            <span v-if="row.startedAt && row.endedAt" style="font-size: 12px;">
              {{ formatTime(row.startedAt) }}<br/>至 {{ formatTime(row.endedAt) }}
            </span>
            <span v-else style="color:var(--el-text-color-secondary);">永久</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="100" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link size="small" title="编辑" :aria-label="`编辑优惠券 ${row.name}`" :disabled="deletingIds.has(row.id) || togglingIds.has(row.id) || saving" @click="openEditDialog(row)">
              <el-icon><Pencil /></el-icon>
            </el-button>
            <el-button type="danger" link size="small" title="删除" :aria-label="`删除优惠券 ${row.name}`" :loading="deletingIds.has(row.id)" :disabled="togglingIds.has(row.id)" @click="handleDelete(row)">
              <el-icon><Trash2 /></el-icon>
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
        <el-pagination
          :current-page="pagination.page"
          :page-size="pagination.pageSize"
          :total="pagination.total"
          layout="total, prev, pager, next"
          @current-change="handlePageChange"
        />
      </div>
    </SectionCard>

    <!-- Add / Edit Coupon Dialog -->
    <el-dialog v-model="dialogVisible" :title="dialogMode === 'create' ? '添加优惠券' : '编辑优惠券'" width="min(540px, calc(100vw - 32px))" class="coupon-dialog" top="4vh" append-to-body :close-on-click-modal="!saving" :close-on-press-escape="!saving" :show-close="!saving">
      <el-form label-position="top" :disabled="saving">
        <el-form-item label="优惠券名称">
          <el-input v-model="form.name" placeholder="请输入优惠券名称" />
        </el-form-item>

        <el-form-item v-if="dialogMode === 'create'" label="批量生成数量">
          <el-input-number v-model="form.generateCount" :min="1" :max="500" :precision="0" />
        </el-form-item>

        <el-form-item label="自定义优惠码">
          <el-input
            v-model="form.code"
            :disabled="dialogMode === 'create' && form.generateCount > 1"
            placeholder="自定义优惠码，留空则自动生成"
          />
          <div v-if="dialogMode === 'create' && form.generateCount > 1" class="form-help-text">批量生成时自动分配优惠码</div>
        </el-form-item>

        <el-form-item label="优惠券类型和值">
          <div style="display: flex; gap: 8px; width: 100%;">
            <el-select v-model="form.type" aria-label="优惠券类型" style="width: 120px; flex-shrink: 0;">
              <el-option v-for="opt in couponTypeOptions" :key="opt.value" :value="opt.value" :label="opt.label" />
            </el-select>
            <el-input
              v-model.number="form.value"
              :aria-label="form.type === 1 ? '优惠金额（元）' : '优惠比例（百分比）'"
              type="number"
              :placeholder="valuePlaceholder()"
              :step="valueStep()"
              style="flex: 1; min-width: 0;"
            >
              <template #suffix>{{ valueSuffix() }}</template>
            </el-input>
          </div>
        </el-form-item>

        <el-form-item label="优惠券有效期">
          <el-date-picker
            v-model="form.dateRange"
            type="datetimerange"
            range-separator="至"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            style="width: 100%;"
          />
        </el-form-item>

        <el-form-item label="最大使用次数">
          <el-input v-model.number="form.limitUse" type="number" placeholder="留空不限制" />
        </el-form-item>

        <el-form-item label="每人可用次数">
          <el-input v-model.number="form.limitUseWithUser" type="number" placeholder="留空不限制" />
        </el-form-item>

        <el-form-item label="指定周期">
          <el-select
            v-model="form.limitPeriod"
            multiple
            clearable
            collapse-tags
            collapse-tags-tooltip
            placeholder="全部周期"
            style="width: 100%;"
          >
            <el-option
              v-for="opt in PERIOD_OPTIONS"
              :key="opt.value"
              :value="opt.value"
              :label="opt.label"
            />
          </el-select>
        </el-form-item>

        <el-form-item label="指定订阅">
          <el-select
            v-model="form.limitPlanIds"
            multiple
            filterable
            clearable
            collapse-tags
            collapse-tags-tooltip
            placeholder="全部套餐"
            :loading="plansLoading"
            :disabled="plansLoading || !!plansError"
            style="width: 100%;"
          >
            <el-option
              v-for="plan in plans"
              :key="plan.id"
              :value="plan.id"
              :label="plan.name"
            />
          </el-select>
          <div v-if="plansError" role="alert">
            {{ plansError }} <el-button link type="primary" :disabled="saving" @click="loadPlans">重试</el-button>
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="saving" @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
:global(.el-dialog.coupon-dialog) {
  display: flex;
  flex-direction: column;
  max-height: calc(100dvh - max(4vh, 16px) - 16px);
}
:global(.el-dialog.coupon-dialog .el-dialog__body) {
  flex: 1;
  min-height: 0;
  overflow: auto;
}
:global(.el-dialog.coupon-dialog .el-dialog__header),
:global(.el-dialog.coupon-dialog .el-dialog__footer) {
  flex-shrink: 0;
}
.form-help-text {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin-top: 4px;
  line-height: 1.4;
}
</style>
