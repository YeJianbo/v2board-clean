<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { createSequence } from '../utils/sequence'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, RefreshCw } from 'lucide-vue-next'
import SectionCard from '../components/common/SectionCard.vue'
import {
  fetchManagedPlans,
  saveManagedPlan,
  deleteManagedPlan,
  updateManagedPlan,
  sortManagedPlans,
  createEmptyManagedPlans,
  PERIOD_LABELS,
} from '../services/plans'
import {
  fetchManagedNodeGroupOptions,
} from '../services/nodes'
import SortDialog from '../components/common/SortDialog.vue'
import { useRoute, useRouter } from 'vue-router'
import { toNodeGroup, toUsersByPlan } from '../utils/crossLink'

const route = useRoute()
const router = useRouter()
// 套餐页无搜索框：从订单/用户/工单等处带 ?plan_id= 跳进来时高亮对应行
const highlightPlanId = computed(() => route.query.plan_id ? String(route.query.plan_id) : null)
function planRowClass({ row }) {
  return highlightPlanId.value && String(row.id) === highlightPlanId.value ? 'plan-row--highlight' : ''
}

const plans = ref(createEmptyManagedPlans())
const loading = ref(false)
const errorMsg = ref('')
const groups = ref([])
const groupsLoading = ref(false)
const groupsError = ref('')
const groupsLoaded = ref(false)
const sortDialogVisible = ref(false)
const sortSaving = ref(false)

const editDialogVisible = ref(false)
const editForm = ref(createEmptyPlanForm())
const editSaving = ref(false)
const isEditing = ref(false)
const deletingPlanId = ref(null)
const updatingPlanId = ref(null)
const mutationBusy = computed(() => editSaving.value || sortSaving.value || deletingPlanId.value !== null || updatingPlanId.value !== null)
const pageBusy = computed(() => mutationBusy.value || loading.value)
let disposed = false
let plansRefreshAfterWrite = false
let plansController = null
let groupsController = null

function createEmptyPlanForm() {
  return {
    id: null,
    name: '',
    groupId: null,
    transferEnableGB: 0,
    speedLimit: null,
    deviceLimit: null,
    show: true,
    renew: true,
    content: '',
    resetTrafficMethod: -1,
    capacityLimit: null,
    forceUpdate: false,
    prices: {
      month_price: null,
      quarter_price: null,
      half_year_price: null,
      year_price: null,
      two_year_price: null,
      three_year_price: null,
      onetime_price: null,
      reset_price: null,
    },
  }
}

const plansSequence = createSequence()
onUnmounted(() => {
  disposed = true
  plansSequence.invalidate()
  plansController?.abort()
  groupsController?.abort()
})
async function loadPlans(options = {}) {
  if (disposed) return
  const { afterWrite = false, ...readOptions } = options
  if (afterWrite) plansRefreshAfterWrite = true
  if (plansRefreshAfterWrite) readOptions.force = true
  plansController?.abort()
  const controller = new AbortController()
  plansController = controller
  const revision = plansSequence.next()
  loading.value = true
  errorMsg.value = ''
  try {
    const result = await fetchManagedPlans({ ...readOptions, signal: controller.signal })
    if (!disposed && plansSequence.isCurrent(revision)) {
      plans.value = result
      plansRefreshAfterWrite = false
    }
  } catch (err) {
    if (disposed || !plansSequence.isCurrent(revision) || controller.signal.aborted) return
    if (plansRefreshAfterWrite) plans.value = []
    errorMsg.value = plansRefreshAfterWrite
      ? `操作已成功，刷新套餐列表失败：${err.message || '请重试'}`
      : err.message || '加载套餐列表失败'
  } finally {
    if (!disposed && plansSequence.isCurrent(revision)) {
      loading.value = false
      plansController = null
    }
  }
}

async function loadGroups() {
  if (disposed || groupsLoading.value || groupsLoaded.value) return
  const controller = new AbortController()
  groupsController = controller
  groupsLoading.value = true
  groupsError.value = ''
  try {
    const result = await fetchManagedNodeGroupOptions({ signal: controller.signal })
    if (disposed) return
    groups.value = Array.isArray(result) ? result : []
    groupsLoaded.value = true
  } catch (err) {
    if (!disposed && !controller.signal.aborted) groupsError.value = err.message || '加载权限组失败'
  } finally {
    if (!disposed) { groupsLoading.value = false; groupsController = null }
  }
}

function openCreateDialog() {
  if (disposed || pageBusy.value || editDialogVisible.value) return
  basePrice.value = 0
  editForm.value = createEmptyPlanForm()
  isEditing.value = false
  editDialogVisible.value = true
  void loadGroups()
}

function openEditDialog(plan) {
  if (disposed || pageBusy.value || editDialogVisible.value) return
  basePrice.value = 0
  editForm.value = {
    id: plan.id,
    name: plan.name,
    groupId: plan.groupId,
    transferEnableGB: plan.transferEnableGB,
    speedLimit: plan.speedLimit,
    deviceLimit: plan.deviceLimit,
    show: plan.show,
    renew: plan.renew,
    content: plan.content,
    resetTrafficMethod: plan.resetTrafficMethod == null ? -1 : Number(plan.resetTrafficMethod),
    capacityLimit: plan.capacityLimit,
    forceUpdate: false,
    prices: { ...plan.prices },
  }
  isEditing.value = true
  editDialogVisible.value = true
  void loadGroups()
}

async function saveForm() {
  if (disposed || pageBusy.value) return
  if (!editForm.value.name?.trim()) {
    ElMessage.warning('请输入套餐名称')
    return
  }
  for (const [key, label] of [['transferEnableGB', '流量'], ['speedLimit', '速度限制'], ['deviceLimit', '设备限制']]) {
    const value = editForm.value[key]
    if (value == null || value === '') continue
    const number = Number(value)
    if (!Number.isFinite(number) || number < 0 || (key === 'deviceLimit' && !Number.isSafeInteger(number))) {
      ElMessage.warning(`${label}须为非负${key === 'deviceLimit' ? '整数' : '有效数值'}`)
      return
    }
  }

  editSaving.value = true
  const submitted = { ...editForm.value, prices: { ...editForm.value.prices } }
  const editing = isEditing.value
  try {
    await saveManagedPlan(submitted)
    if (disposed) return

    ElMessage.success(editing ? '套餐已更新' : '套餐已创建')
    editDialogVisible.value = false
    await loadPlans({ force: true, afterWrite: true })
  } catch (err) {
    if (!disposed) ElMessage.error(err.message || '保存失败')
  } finally {
    editSaving.value = false
  }
}

async function handleDelete(plan) {
  if (disposed || pageBusy.value || !plan?.id) return
  deletingPlanId.value = plan.id
  const id = plan.id
  try {
    await ElMessageBox.confirm(`确定要删除套餐 "${plan.name}" 吗？`, '删除套餐', {
      type: 'error',
    })
    if (disposed) return
    await deleteManagedPlan(id)
    if (disposed) return
    ElMessage.success('套餐已删除')
    await loadPlans({ force: true, afterWrite: true })
  } catch (err) {
    if (!disposed && err !== 'cancel' && err !== 'close') {
      ElMessage.error(err.message || '删除失败')
    }
  } finally {
    deletingPlanId.value = null
  }
}

async function handleToggle(plan, field) {
  if (disposed || pageBusy.value || !['show', 'renew'].includes(field)) return
  const lockKey = `${field}Updating`
  if (plan[lockKey]) return
  const previous = plan[field]
  const newVal = !previous
  plan[lockKey] = true
  updatingPlanId.value = plan.id
  // 乐观更新：UI 先翻，失败再回滚
  plan[field] = newVal
  try {
    await updateManagedPlan(plan.id, { [field]: newVal })
    if (disposed) return
    ElMessage.success('已更新')
  } catch (err) {
    if (!disposed) {
      plan[field] = previous
      ElMessage.error(err.message || '更新失败')
    }
  } finally {
    plan[lockKey] = false
    updatingPlanId.value = null
  }
}

const periodKeys = Object.keys(PERIOD_LABELS)
const periodLabelEntries = Object.entries(PERIOD_LABELS)

const resetTrafficOptions = [
  { label: '跟随系统设置', value: -1 },
  { label: '每月1日', value: 0 },
  { label: '按月重置（从订阅日起算）', value: 1 },
  { label: '不重置（流量叠加）', value: 2 },
  { label: '每年1月1日', value: 3 },
  { label: '按年重置（从订阅日起算）', value: 4 },
]

const basePrice = ref(0)

const mainPeriods = [
  { key: 'month_price', label: '月付', sub: '(每月)' },
  { key: 'quarter_price', label: '季付', sub: '(3个月)' },
  { key: 'half_year_price', label: '半年付', sub: '(6个月)' },
  { key: 'year_price', label: '年付', sub: '(12个月)' },
  { key: 'two_year_price', label: '两年付', sub: '(24个月)' },
  { key: 'three_year_price', label: '三年付', sub: '(36个月)' },
]

const periodMultipliers = {
  month_price: 1,
  quarter_price: 2.85,
  half_year_price: 5.4,
  year_price: 10.2,
  two_year_price: 0,
  three_year_price: 0,
}

function fillPricesFromBase() {
  if (editSaving.value) return
  if (!basePrice.value || basePrice.value <= 0) {
    ElMessage.warning('请先输入基础价格')
    return
  }
  mainPeriods.forEach(p => {
    const m = periodMultipliers[p.key]
    if (m > 0) {
      editForm.value.prices[p.key] = Number((basePrice.value * m).toFixed(2))
    }
  })
}

function clearAllPrices() {
  if (editSaving.value) return
  Object.keys(editForm.value.prices).forEach(k => {
    editForm.value.prices[k] = null
  })
  basePrice.value = 0
}

async function handleSortSave(ids) {
  if (disposed || pageBusy.value) return
  sortSaving.value = true
  try {
    await sortManagedPlans(ids)
    if (disposed) return
    ElMessage.success('排序已保存')
    sortDialogVisible.value = false
    await loadPlans({ force: true, afterWrite: true })
  } catch (err) {
    if (!disposed) ElMessage.error(err.message || '排序保存失败')
  } finally {
    sortSaving.value = false
  }
}

onMounted(function onMount() {
  loadPlans()
})
</script>

<template>
  <section class="page-stack">
    <SectionCard header-actions>
      <template #actions>
        <el-space wrap>
          <el-button @click="sortDialogVisible = true" :disabled="pageBusy || plans.length < 2">排序</el-button>
          <el-button :icon="Plus" class="primary-btn small" type="primary" :disabled="pageBusy" @click="openCreateDialog">
            创建套餐
          </el-button>
          <el-button :icon="RefreshCw" class="ghost-btn small" plain type="info" :loading="loading" :disabled="mutationBusy" @click="loadPlans({ force: true })">
            刷新
          </el-button>
        </el-space>
      </template>

      <el-alert v-if="errorMsg" :title="errorMsg" closable show-icon type="error" style="margin-bottom: 16px" @close="errorMsg = ''" />

      <el-table v-loading="loading" :data="plans" :row-class-name="planRowClass" class="plans-table" stripe style="width: 100%" table-layout="auto">
        <el-table-column label="ID" prop="id" width="70" align="center" />
        <el-table-column label="名称" min-width="180" prop="name" show-overflow-tooltip />
        <el-table-column label="权限组" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">
            <button v-if="toNodeGroup(row.groupId)" class="x-link x-link--button" type="button" @click="router.push(toNodeGroup(row.groupId))">{{ row.groupName }}</button>
            <span v-else>{{ row.groupName }}</span>
          </template>
        </el-table-column>
        <el-table-column label="流量" width="110">
          <template #default="{ row }">
            <span style="white-space:nowrap">{{ row.transferEnableText }}</span>
          </template>
        </el-table-column>
        <el-table-column label="速率限制" width="110">
          <template #default="{ row }">
            <span style="white-space:nowrap">{{ row.speedLimit ? row.speedLimit + ' Mbps' : '--' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="用户数" width="130">
          <template #default="{ row }">
            <div style="display:flex;flex-direction:column;align-items:flex-start;gap:3px;white-space:nowrap">
              <el-button link type="primary" @click="router.push(toUsersByPlan(row.id))">全部 {{ row.usersCount }} 人</el-button>
              <span style="color:var(--el-text-color-secondary);font-size:12px">未到期 {{ row.activeUsersCount }} 人</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="定价" min-width="300">
          <template #default="{ row }">
            <div v-if="row.priceTexts.length > 0" style="display:flex;flex-wrap:wrap;gap:4px;">
              <el-tag
                v-for="(text, idx) in row.priceTexts"
                :key="idx"
                size="small"
                type="info"
                style="white-space:nowrap;"
              >
                {{ text }}
              </el-tag>
            </div>
            <span v-else>--</span>
          </template>
        </el-table-column>
        <el-table-column label="显示" width="82" align="center">
          <template #default="{ row }">
            <el-switch :model-value="row.show" :loading="row.showUpdating" :disabled="pageBusy" :aria-label="`显示 ${row.name}`" size="small" @change="handleToggle(row, 'show')" />
          </template>
        </el-table-column>
        <el-table-column label="续费" width="82" align="center">
          <template #default="{ row }">
            <el-switch :model-value="row.renew" :loading="row.renewUpdating" :disabled="pageBusy" :aria-label="`续费 ${row.name}`" size="small" @change="handleToggle(row, 'renew')" />
          </template>
        </el-table-column>
        <el-table-column fixed="right" label="操作" width="130" align="center" class-name="plans-actions-column">
          <template #default="{ row }">
            <el-button link size="small" type="primary" :disabled="pageBusy" :aria-label="`编辑 ${row.name}`" @click="openEditDialog(row)">编辑</el-button>
            <el-button link size="small" type="danger" :disabled="pageBusy" :loading="deletingPlanId === row.id" :aria-label="`删除 ${row.name}`" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </SectionCard>

    <!-- 创建/编辑套餐对话框 -->
    <el-dialog
      v-model="editDialogVisible"
      :title="isEditing ? '编辑套餐' : '创建套餐'"
      width="min(640px, calc(100vw - 32px))"
      top="4vh"
      class="plan-editor-dialog"
      append-to-body
      :show-close="!editSaving"
      :close-on-click-modal="!editSaving"
      :close-on-press-escape="!editSaving"
      destroy-on-close
    >
      <el-form :model="editForm" :disabled="editSaving" label-position="top" style="padding: 0 8px">
        <el-form-item label="套餐名称" required>
          <el-input v-model="editForm.name" placeholder="输入套餐名称" />
        </el-form-item>

        <el-form-item label="权限组">
          <el-select v-model="editForm.groupId" :loading="groupsLoading" :disabled="groupsLoading || Boolean(groupsError)" clearable placeholder="选择权限组" style="width: 100%">
            <el-option
              v-for="group in groups"
              :key="group.id"
              :label="group.name"
              :value="group.id"
            />
          </el-select>
          <div v-if="groupsError" class="plan-group-error" role="status">
            <span>{{ groupsError }}</span>
            <el-button link type="primary" :disabled="editSaving" @click="loadGroups">重试</el-button>
          </div>
        </el-form-item>

        <el-form-item label="流量">
          <el-input v-model.number="editForm.transferEnableGB" placeholder="0" type="number">
            <template #suffix><span style="color:var(--el-text-color-secondary)">GB</span></template>
          </el-input>
        </el-form-item>

        <el-form-item label="速度限制">
          <el-input v-model.number="editForm.speedLimit" placeholder="不限制" type="number">
            <template #suffix><span style="color:var(--el-text-color-secondary)">Mbps</span></template>
          </el-input>
        </el-form-item>

        <!-- 价格设置 -->
        <el-divider content-position="left">价格设置</el-divider>

        <div class="plan-base-prices">
          <span style="font-size: 13px; color: var(--el-text-color-regular)">¥ 基础价格</span>
          <el-input-number v-model="basePrice" aria-label="基础价格" :min="0" :precision="2" :step="10" size="small" style="width: 140px" />
          <el-button size="small" type="info" plain @click="fillPricesFromBase">应用</el-button>
          <el-button size="small" @click="clearAllPrices">清空</el-button>
        </div>

        <div class="plan-price-grid">
          <div class="plan-price-card" v-for="item in mainPeriods" :key="item.key">
            <div class="plan-price-card__label">{{ item.label }} <span>{{ item.sub }}</span></div>
            <el-input v-model.number="editForm.prices[item.key]" :aria-label="`${item.label}价格`" placeholder="0.00" type="number">
              <template #prefix>¥</template>
            </el-input>
          </div>
        </div>

        <div class="plan-form-pair" style="margin-top: 12px">
          <div class="plan-price-card plan-price-card--special" style="flex:1">
            <div class="plan-price-card__label">流量包 <span>一次性流量包，无时间限制</span></div>
            <el-input v-model.number="editForm.prices.onetime_price" aria-label="流量包价格" placeholder="0" type="number">
              <template #prefix>¥</template>
            </el-input>
          </div>
          <div class="plan-price-card plan-price-card--special" style="flex:1">
            <div class="plan-price-card__label">重置包 <span>重置流量包，可多次使用</span></div>
            <el-input v-model.number="editForm.prices.reset_price" aria-label="重置包价格" placeholder="0" type="number">
              <template #prefix>¥</template>
            </el-input>
          </div>
        </div>

        <!-- 设备限制 / 容量限制 -->
        <div class="plan-form-pair" style="margin-top: 20px">
          <el-form-item label="设备限制" style="flex: 1">
            <el-input v-model.number="editForm.deviceLimit" placeholder="不限制" type="number">
              <template #suffix><span style="color:var(--el-text-color-secondary)">台</span></template>
            </el-input>
          </el-form-item>
          <el-form-item label="容量限制" style="flex: 1">
            <el-input v-model.number="editForm.capacityLimit" placeholder="不限制" type="number">
              <template #suffix><span style="color:var(--el-text-color-secondary)">人</span></template>
            </el-input>
          </el-form-item>
        </div>

        <el-form-item label="流量重置方式">
          <el-select v-model="editForm.resetTrafficMethod" clearable style="width: 100%">
            <el-option
              v-for="opt in resetTrafficOptions"
              :key="String(opt.value)"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
          <div style="font-size: 12px; color: var(--el-text-color-secondary); margin-top: 4px">
            流量重置方式将决定如何重置流量
          </div>
        </el-form-item>

        <el-divider content-position="left">显示设置</el-divider>

        <div style="display: flex; gap: 32px; align-items: center;">
          <el-form-item label="显示" style="margin-bottom: 0;">
            <el-switch v-model="editForm.show" />
          </el-form-item>
          <el-form-item label="续费" style="margin-bottom: 0;">
            <el-switch v-model="editForm.renew" />
          </el-form-item>
        </div>

        <el-form-item v-if="isEditing" label="强制更新用户">
          <el-switch v-model="editForm.forceUpdate" />
          <span style="margin-left: 8px; color: var(--el-text-color-secondary); font-size: 12px">
            将当前套餐下所有用户的权限组、流量、速率限制同步更新
          </span>
        </el-form-item>

        <el-form-item label="套餐说明">
          <el-input v-model="editForm.content" type="textarea" :rows="4" placeholder="套餐描述（支持 Markdown）" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="editSaving" @click="editDialogVisible = false">取消</el-button>
        <el-button :loading="editSaving" type="primary" @click="saveForm">保存</el-button>
      </template>
    </el-dialog>

    <SortDialog
      v-model:visible="sortDialogVisible"
      :saving="sortSaving"
      :items="plans"
      title="排序套餐"
      @save="handleSortSave"
    />
  </section>
</template>

<style scoped>
:global(.el-dialog.plan-editor-dialog) {
  display: flex;
  flex-direction: column;
  max-height: 92vh;
  max-height: 92dvh;
  overflow: hidden;
}
:global(.plan-editor-dialog .el-dialog__body) {
  min-height: 0;
  overflow-y: auto;
}
:global(.plan-editor-dialog .el-dialog__header),
:global(.plan-editor-dialog .el-dialog__footer) {
  flex-shrink: 0;
}
.plan-base-prices {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}
.plan-form-pair {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}
.plan-group-error {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--danger);
  font-size: 12px;
}
/* 从别处带 ?plan_id= 跳进来时高亮该套餐行 */
:deep(.plan-row--highlight > td.el-table__cell) {
  background: var(--primary-soft) !important;
}
:deep(.plan-row--highlight > td.el-table__cell:first-child) {
  box-shadow: inset 3px 0 0 0 var(--el-color-primary);
}

.plans-table :deep(.el-switch) {
  vertical-align: middle;
}

.plans-table :deep(.plans-actions-column),
.plans-table :deep(.plans-actions-column .cell) {
  background: var(--bc-table-body-bg, var(--panel-raised, #fff)) !important;
}

.plans-table :deep(.plans-actions-column .cell) {
  display: flex;
  justify-content: center;
  gap: 8px;
  padding-left: 12px;
  padding-right: 12px;
  white-space: nowrap;
}

.plan-price-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}
.plan-price-card {
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  padding: 10px;
  background: var(--el-fill-color-lighter);
}
.plan-price-card--special {
  border: 1px dashed var(--el-border-color);
  border-radius: 8px;
  padding: 10px;
  background: var(--el-fill-color-lighter);
}
.plan-price-card__label {
  font-size: 13px;
  font-weight: 600;
  color: var(--el-text-color-regular);
  margin-bottom: 6px;
}
.plan-price-card__label span {
  font-weight: 400;
  font-size: 11px;
  color: var(--el-text-color-secondary);
}
@media (max-width: 600px) {
  .plan-price-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 420px) {
  .plan-price-grid, .plan-form-pair { grid-template-columns: minmax(0, 1fr); }
}
</style>
