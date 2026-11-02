<script setup>
import { onMounted, onUnmounted, reactive, ref } from 'vue'
import { createSequence } from '../utils/sequence'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Pencil, Trash2, Search } from 'lucide-vue-next'
import SectionCard from '../components/common/SectionCard.vue'
import CopyButton from '../components/common/CopyButton.vue'
import {
  fetchPayments,
  fetchPaymentMethods,
  fetchPaymentForm,
  fetchPaymentDetail,
  savePayment,
  deletePayment,
  togglePaymentShow,
  sortPayments,
} from '../services/payment'
import SortDialog from '../components/common/SortDialog.vue'

const payments = ref([])
const paymentMethods = ref([])
const methodsLoading = ref(false)
const methodsLoaded = ref(false)
const methodsError = ref('')
const loading = ref(false)
const togglingIds = ref(new Set())
const deletingIds = ref(new Set())
const error = ref('')
const searchKeyword = ref('')

const dialogVisible = ref(false)
const dialogMode = ref('create')
const formKey = ref(0)
const sortDialogVisible = ref(false)
const sortSaving = ref(false)
const formFields = ref([])
const formLoading = ref(false)
const formError = ref('')
const formSequence = createSequence()
const listSequence = createSequence()
const methodsSequence = createSequence()
let formController = null
let listController = null
let methodsController = null
let disposed = false
let paymentsRefreshAfterWrite = false
function cancelFormRead() {
  formSequence.invalidate()
  formController?.abort()
  formController = null
  formLoading.value = false
  form.config = {}
  formFields.value = []
}
onUnmounted(() => {
  disposed = true
  cancelFormRead()
  listSequence.invalidate()
  methodsSequence.invalidate()
  listController?.abort()
  methodsController?.abort()
})
const form = reactive({
  id: null,
  name: '',
  icon: '',
  payment: '',
  config: {},
  enable: true,
  notifyDomain: '',
  handlingFeePercent: null,
  handlingFeeFixed: null,
})

function getFilteredPayments() {
  if (!searchKeyword.value) return payments.value
  const kw = searchKeyword.value.toLowerCase()
  return payments.value.filter(p =>
    (p.name || '').toLowerCase().includes(kw) ||
    (p.payment || '').toLowerCase().includes(kw)
  )
}

async function loadPayments({ afterWrite = false } = {}) {
  if (disposed) return
  if (afterWrite) paymentsRefreshAfterWrite = true
  listController?.abort()
  const controller = new AbortController()
  listController = controller
  const revision = listSequence.next()
  loading.value = true
  error.value = ''
  if (paymentsRefreshAfterWrite) payments.value = []
  try {
    const list = await fetchPayments({ signal: controller.signal })
    if (!listSequence.isCurrent(revision)) return
    payments.value = list
    paymentsRefreshAfterWrite = false
  } catch (err) {
    if (!listSequence.isCurrent(revision) || controller.signal.aborted) return
    error.value = paymentsRefreshAfterWrite ? `操作已成功，但列表刷新失败：${err.message}` : err.message
  } finally {
    if (listSequence.isCurrent(revision)) loading.value = false
    if (listController === controller) listController = null
  }
}

async function loadMethods() {
  if (disposed || methodsLoading.value || methodsLoaded.value) return
  const controller = new AbortController()
  methodsController = controller
  const revision = methodsSequence.next()
  methodsLoading.value = true
  methodsError.value = ''
  try {
    const methods = await fetchPaymentMethods({ signal: controller.signal })
    if (!methodsSequence.isCurrent(revision)) return
    paymentMethods.value = methods
    methodsLoaded.value = true
  } catch (err) {
    if (methodsSequence.isCurrent(revision) && !controller.signal.aborted) methodsError.value = err.message || '加载支付接口失败'
  } finally {
    if (methodsSequence.isCurrent(revision)) methodsLoading.value = false
    if (methodsController === controller) methodsController = null
  }
}

function openCreateDialog() {
  if (disposed || formSaving.value) return
  loadMethods()
  cancelFormRead()
  formLoading.value = false
  formError.value = ''
  dialogMode.value = 'create'
  Object.assign(form, {
    id: null, name: '', icon: '', payment: '', config: {},
    enable: true, notifyDomain: '', handlingFeePercent: null, handlingFeeFixed: null,
  })
  formFields.value = []
  formKey.value += 1
  dialogVisible.value = true
}

async function openEditDialog(row) {
  if (disposed || formSaving.value || deletingIds.value.has(row.id) || togglingIds.value.has(row.id)) return
  dialogMode.value = 'edit'
  Object.assign(form, {
    id: row.id,
    name: row.name,
    icon: row.icon || '',
    payment: row.payment,
    config: {},
    enable: row.enable,
    notifyDomain: row.notifyDomain || '',
    handlingFeePercent: row.handlingFeePercent,
    handlingFeeFixed: row.handlingFeeFixed,
  })
  formKey.value += 1
  dialogVisible.value = true
  await loadPaymentFields(row.payment, row.id)
}

async function loadPaymentFields(payment, id) {
  if (disposed || formSaving.value) return
  formController?.abort()
  const controller = new AbortController()
  formController = controller
  const revision = formSequence.next()
  formLoading.value = true
  formError.value = ''
  formFields.value = []
  try {
    const detail = id ? await fetchPaymentDetail(id, { signal: controller.signal }) : null
    if (!formSequence.isCurrent(revision)) return
    const fields = await fetchPaymentForm(detail?.payment || payment, id, { signal: controller.signal })
    if (!formSequence.isCurrent(revision)) return
    const config = detail ? { ...detail.config } : {}
    for (const field of fields) {
      if (Object.hasOwn(field, 'value') && !Object.hasOwn(config, field.field)) config[field.field] = field.value
    }
    if (detail) Object.assign(form, detail)
    form.config = config
    formFields.value = fields
  } catch (err) {
    if (formSequence.isCurrent(revision) && !controller.signal.aborted) formError.value = err.message || '加载支付配置表单失败'
  } finally {
    if (formSequence.isCurrent(revision)) formLoading.value = false
    if (formController === controller) formController = null
  }
}

async function handlePaymentChange(payment) {
  if (disposed || formSaving.value) return
  form.payment = payment
  form.config = {}
  await loadPaymentFields(payment)
}

const formRef = ref(null)
function validateFixedFee(_rule, value, callback) {
  if (value === null || value === undefined || value === '') return callback()
  const number = Number(value)
  callback(Number.isInteger(number) && number >= 0 && number <= 2147483647
    ? undefined : new Error('固定手续费须为0-2147483647之间的整数'))
}
function validatePercentFee(_rule, value, callback) {
  if (value === null || value === undefined || value === '') return callback()
  const number = Number(value)
  callback(Number.isFinite(number) && number >= 0 && number <= 100
    ? undefined : new Error('百分比手续费须在0-100之间'))
}
const formRules = {
  name: [{ required: true, message: '请输入显示名称', trigger: 'blur' }],
  payment: [{ required: true, message: '请选择支付接口', trigger: 'change' }],
  handlingFeeFixed: [{ validator: validateFixedFee, trigger: 'blur' }],
  handlingFeePercent: [{ validator: validatePercentFee, trigger: 'blur' }],
}
const formSaving = ref(false)

async function handleSave() {
  if (disposed || formSaving.value || formLoading.value || formError.value || !dialogVisible.value) return
  const revision = formSequence.current()
  const mode = dialogMode.value
  const payload = { ...form, config: JSON.parse(JSON.stringify(form.config)) }
  formSaving.value = true
  try {
    if (formRef.value) {
      try { await formRef.value.validate() } catch { return }
    }
    if (disposed || !formSequence.isCurrent(revision) || formLoading.value || formError.value || !dialogVisible.value) return
    await savePayment(payload)
    if (disposed) return
    if (!formSequence.isCurrent(revision)) return
    ElMessage.success(mode === 'create' ? '支付方式已添加' : '支付方式已更新')
    dialogVisible.value = false
    await loadPayments({ afterWrite: true })
  } catch (err) {
    if (!disposed) ElMessage.error(err.message)
  } finally {
    formSaving.value = false
  }
}

async function handleDelete(row) {
  const id = row.id
  if (disposed || deletingIds.value.has(id) || togglingIds.value.has(id)) return
  deletingIds.value.add(id)
  try {
    await ElMessageBox.confirm(`确认删除「${row.name}」？`, '删除支付方式', {
      type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消',
    })
    if (disposed) return
    await deletePayment(id)
    if (disposed) return
    ElMessage.success('已删除')
    await loadPayments({ afterWrite: true })
  } catch (err) {
    if (!disposed && err !== 'cancel' && err !== 'close') ElMessage.error(err.message || '删除失败')
  } finally {
    deletingIds.value.delete(id)
  }
}

async function handleToggleEnable(row) {
  if (disposed || togglingIds.value.has(row.id) || deletingIds.value.has(row.id)) return
  togglingIds.value.add(row.id)
  try {
    await togglePaymentShow(row.id)
    if (disposed) return
    ElMessage.success('状态已更新')
    await loadPayments({ afterWrite: true })
  } catch (err) {
    if (!disposed) ElMessage.error(err.message)
  } finally {
    togglingIds.value.delete(row.id)
  }
}

async function handleSortSave(ids) {
  if (disposed || sortSaving.value) return
  sortSaving.value = true
  try {
    await sortPayments(ids)
    if (disposed) return
    ElMessage.success('排序已保存')
    sortDialogVisible.value = false
    await loadPayments({ afterWrite: true })
  } catch (err) {
    if (!disposed) ElMessage.error(err.message || '排序保存失败')
  } finally {
    sortSaving.value = false
  }
}

onMounted(loadPayments)
</script>

<template>
  <section class="page-stack">
    <SectionCard>
      <template #filters>
          <el-input
            v-model="searchKeyword"
            aria-label="搜索支付方式名称或接口"
            placeholder="搜索支付方式名称/接口"
            clearable
            style="width: 240px"
          >
            <template #prefix>
              <el-icon><Search /></el-icon>
            </template>
          </el-input>
      </template>
      <template #actions>
        <el-space wrap>
          <el-button @click="sortDialogVisible = true" :disabled="sortSaving || payments.length < 2">排序</el-button>
          <el-button type="primary" :icon="Plus" :disabled="formSaving" @click="openCreateDialog">
            添加支付方式
          </el-button>
        </el-space>
      </template>

      <el-alert v-if="error" type="error" :closable="false" :title="error" class="dashboard-alert">
        <el-button
          link
          type="primary"
          :loading="loading"
          :disabled="loading"
          @click="loadPayments"
        >
          重试
        </el-button>
      </el-alert>

      <el-table :data="getFilteredPayments()" v-loading="loading" class="dashboard-table">
        <el-table-column label="ID" prop="id" sortable width="70" />
        <el-table-column label="启用" width="70">
          <template #default="{ row }">
            <el-switch :model-value="row.enable" :aria-label="`启用支付方式 ${row.name}`" :loading="togglingIds.has(row.id)" :disabled="deletingIds.has(row.id)" size="small" @change="handleToggleEnable(row)" />
          </template>
        </el-table-column>
        <el-table-column label="显示名称" min-width="120">
          <template #default="{ row }">
            <div style="display:flex;align-items:center;gap:6px;">
              <img v-if="row.icon" :src="row.icon" alt="" width="18" height="18" style="object-fit:contain;border-radius:3px;" />
              <span>{{ row.name }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="支付接口" min-width="100">
          <template #default="{ row }">
            <el-tag size="small">{{ row.payment }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="通知地址" min-width="260">
          <template #default="{ row }">
            <div v-if="row.notifyUrl" style="display:flex;align-items:center;gap:6px;">
              <span style="font-size:12px;color:var(--el-text-color-regular);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0;">
                {{ row.notifyUrl }}
              </span>
              <CopyButton
                :value="row.notifyUrl"
                label="复制"
                success-message="通知地址已复制"
                style="flex-shrink:0;"
              />
            </div>
            <span v-else style="color:var(--el-text-color-placeholder);">--</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="100" fixed="right">
          <template #default="{ row }">
            <el-dropdown trigger="click">
              <el-button link size="small" type="primary" :aria-label="`操作支付方式 ${row.name}`">操作</el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item @click="openEditDialog(row)">编辑</el-dropdown-item>
                  <el-dropdown-item divided :disabled="deletingIds.has(row.id) || togglingIds.has(row.id)" @click="handleDelete(row)" style="color:var(--el-color-danger)">删除</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
      </el-table>

      <div style="margin-top: 12px; font-size: 12px; color: var(--el-text-color-secondary);">
        共 {{ getFilteredPayments().length }} 项
      </div>
    </SectionCard>

    <!-- Add / Edit Dialog -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogMode === 'create' ? '添加支付方式' : '编辑支付方式'"
      width="min(540px, calc(100vw - 32px))"
      class="payment-dialog"
      append-to-body
      :close-on-click-modal="!formSaving"
      :close-on-press-escape="!formSaving"
      :show-close="!formSaving"
      @close="cancelFormRead"
    >
      <el-alert v-if="formError" :title="formError" type="error" :closable="false">
        <el-button text type="primary" @click="loadPaymentFields(form.payment, form.id)">重新加载</el-button>
      </el-alert>
      <el-form
        :key="formKey"
        ref="formRef"
        :model="form"
        :rules="formRules"
        label-position="top"
        v-loading="formLoading"
        :disabled="formSaving || formLoading || !!formError"
      >
        <el-form-item label="显示名称" prop="name">
          <el-input v-model="form.name" placeholder="支付宝" />
          <div class="form-help-text">用于前端显示</div>
        </el-form-item>

        <el-form-item label="图标URL">
          <el-input v-model="form.icon" placeholder="https://..." />
          <div class="form-help-text">用于前端显示的图标地址</div>
        </el-form-item>

        <el-form-item label="通知域名">
          <el-input v-model="form.notifyDomain" placeholder="https://www.example.com" />
          <div class="form-help-text">网关通知将发送到该域名，包括协议（http或https）</div>
        </el-form-item>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <el-form-item label="百分比手续费(%)" prop="handlingFeePercent">
            <el-input v-model.number="form.handlingFeePercent" placeholder="0.00" />
          </el-form-item>
          <el-form-item label="固定手续费（分）" prop="handlingFeeFixed">
            <el-input v-model.number="form.handlingFeeFixed" placeholder="0" />
          </el-form-item>
        </div>

        <el-form-item label="支付接口" prop="payment">
          <el-select
            v-model="form.payment"
            style="width:100%;"
            :disabled="dialogMode === 'edit'"
            :loading="methodsLoading"
            placeholder="选择要使用的支付接口"
            filterable
            @change="handlePaymentChange"
          >
            <el-option v-for="m in paymentMethods" :key="m" :value="m" :label="m" />
          </el-select>
          <div v-if="dialogMode === 'create' && methodsError" role="alert">
            {{ methodsError }} <el-button link type="primary" @click="loadMethods">重试</el-button>
          </div>
          <div class="form-help-text">选择要使用的支付接口</div>
        </el-form-item>

        <template v-if="formFields.length > 0">
          <el-divider content-position="left">支付配置</el-divider>
          <el-form-item
            v-for="field in formFields"
            :key="field.field || field.label"
            :label="field.label || field.field"
          >
            <el-input
              :model-value="form.config[field.field] ?? ''"
              :placeholder="field.placeholder || field.tips || ''"
              @update:model-value="form.config[field.field] = $event"
            />
            <div v-if="field.tips" class="form-help-text" style="color:var(--el-color-primary);">
              {{ field.tips }}
            </div>
          </el-form-item>
        </template>

        <el-form-item label="启用">
          <el-switch v-model="form.enable" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="formSaving" @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="formSaving" :disabled="formLoading || !!formError" @click="handleSave">提交</el-button>
      </template>
    </el-dialog>

    <SortDialog
      v-model:visible="sortDialogVisible"
      :saving="sortSaving"
      :items="payments"
      title="排序支付方式"
      @save="handleSortSave"
    />
  </section>
</template>

<style scoped>
:global(.el-dialog.payment-dialog) {
  display: flex;
  flex-direction: column;
  max-height: calc(100dvh - 32px);
  margin: 16px auto;
}
:global(.el-dialog.payment-dialog .el-dialog__body) {
  min-height: 0;
  overflow: auto;
}
:global(.el-dialog.payment-dialog .el-dialog__header),
:global(.el-dialog.payment-dialog .el-dialog__footer) {
  flex-shrink: 0;
}
.form-help-text {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin-top: 4px;
  line-height: 1.4;
}
</style>
