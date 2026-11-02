<script setup>
import { ref, watch, onBeforeUnmount, useId } from 'vue'
import { fetchPaymentReceipts, downloadPaymentReceipt } from '../../services/paymentReceipts'

const props = defineProps({
  tradeNo: { type: String, required: true },
  contextSignal: { type: Object, default: undefined },
})
const panelId = useId()
const expanded = ref(false)
const loading = ref(false)
const loaded = ref(false)
const error = ref('')
const downloadError = ref('')
const rows = ref([])
const page = ref(1)
const pageSize = ref(10)
const total = ref(0)
const downloadingId = ref(null)
let disposed = false
let listRevision = 0
let downloadRevision = 0
let listController, downloadController

function cancelDownload() {
  downloadRevision++
  downloadController?.abort()
  downloadController = null
  downloadingId.value = null
}

function cancelReads() {
  listRevision++
  listController?.abort()
  listController = null
  loading.value = false
  cancelDownload()
}

watch(() => props.tradeNo, () => {
  cancelReads()
  expanded.value = false
  loaded.value = false
  rows.value = []
  total.value = 0
  page.value = 1
  error.value = ''
  downloadError.value = ''
}, { flush: 'sync' })
// The dialog aborts synchronously, before Vue removes this panel on its next render.
watch(() => props.contextSignal, (signal, _previous, onCleanup) => {
  if (!signal) return
  const cancel = () => { expanded.value = false; cancelReads() }
  signal.addEventListener('abort', cancel, { once: true })
  onCleanup(() => signal.removeEventListener('abort', cancel))
  if (signal.aborted) cancel()
}, { immediate: true, flush: 'sync' })
onBeforeUnmount(() => { disposed = true; cancelReads() })

async function loadReceipts(current = page.value) {
  if (disposed || props.contextSignal?.aborted || !expanded.value) return
  listController?.abort()
  cancelDownload()
  const controller = new AbortController()
  listController = controller
  const revision = ++listRevision
  const tradeNo = props.tradeNo
  page.value = current
  loading.value = true
  loaded.value = false
  error.value = ''
  downloadError.value = ''
  try {
    const result = await fetchPaymentReceipts(tradeNo, { page: current, pageSize: pageSize.value, signal: controller.signal })
    if (disposed || controller.signal.aborted || revision !== listRevision || tradeNo !== props.tradeNo || !expanded.value) return
    rows.value = result.list
    page.value = result.page
    pageSize.value = result.pageSize
    total.value = result.total
    loaded.value = true
  } catch (err) {
    if (!disposed && !controller.signal.aborted && revision === listRevision && tradeNo === props.tradeNo && expanded.value) {
      error.value = err?.name === 'AbortError' ? '管理会话已变更，请重新打开订单详情' : (err?.message || '支付回调原件读取失败，请重试')
    }
  } finally {
    if (revision === listRevision && !disposed) loading.value = false
    if (listController === controller) listController = null
  }
}

function toggleReceipts() {
  if (disposed || props.contextSignal?.aborted) return
  expanded.value = !expanded.value
  if (expanded.value) loadReceipts()
  else cancelReads()
}

async function downloadReceipt(row) {
  if (disposed || props.contextSignal?.aborted || !expanded.value || downloadingId.value !== null) return
  const controller = new AbortController()
  downloadController = controller
  const revision = ++downloadRevision
  const tradeNo = props.tradeNo
  downloadingId.value = row.id
  downloadError.value = ''
  try {
    await downloadPaymentReceipt(row.id, tradeNo, { signal: controller.signal })
  } catch (err) {
    if (!disposed && !controller.signal.aborted && revision === downloadRevision && tradeNo === props.tradeNo && expanded.value) {
      downloadError.value = err?.name === 'AbortError' ? '管理会话已变更，请重新打开订单详情' : (err?.message || '原件下载失败，请重试')
    }
  } finally {
    if (revision === downloadRevision && !disposed) downloadingId.value = null
    if (downloadController === controller) downloadController = null
  }
}

function formatTime(timestamp) {
  const date = new Date(timestamp * 1000)
  return Number.isNaN(date.getTime()) ? '--' : new Intl.DateTimeFormat('zh-CN', {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
  }).format(date)
}
</script>

<template>
  <section class="payment-receipts" aria-label="支付回调原件">
    <header class="payment-receipts__header">
      <h3>支付回调原件</h3>
      <div class="payment-receipts__actions">
        <el-button v-if="downloadingId !== null" size="small" @click="cancelDownload">取消下载</el-button>
        <el-button size="small" plain :aria-expanded="expanded" :aria-controls="panelId" @click="toggleReceipts">
          {{ expanded ? '收起' : '查看原件' }}
        </el-button>
      </div>
    </header>
    <div v-if="expanded" :id="panelId" :aria-busy="loading">
      <div v-if="loading" class="payment-receipts__status" role="status">正在读取支付回调原件…</div>
      <div v-else-if="error" class="payment-receipts__error" role="alert">
        <span>{{ error }}</span>
        <el-button size="small" @click="loadReceipts()">重新加载</el-button>
      </div>
      <template v-else-if="loaded">
        <el-alert v-if="downloadError" :title="downloadError" type="error" :closable="false" show-icon />
        <el-table :data="rows" size="small" empty-text="暂无支付回调原件" class="payment-receipts__table">
          <el-table-column prop="id" label="ID" min-width="65" />
          <el-table-column label="回调号" min-width="150">
            <template #default="{ row }"><span class="payment-receipts__number">{{ row.callbackNo ?? '--' }}</span></template>
          </el-table-column>
          <el-table-column label="首次收到" min-width="165">
            <template #default="{ row }">{{ formatTime(row.firstSeenAt) }}</template>
          </el-table-column>
          <el-table-column label="最近收到" min-width="165">
            <template #default="{ row }">{{ formatTime(row.lastSeenAt) }}</template>
          </el-table-column>
          <el-table-column prop="seenCount" label="次数" min-width="65" />
          <el-table-column label="字节" min-width="85">
            <template #default="{ row }">{{ row.bodyBytes.toLocaleString('zh-CN') }}</template>
          </el-table-column>
          <el-table-column label="操作" width="100" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" :aria-label="`下载原件 ${row.id}`"
                :loading="downloadingId === row.id" :disabled="downloadingId !== null" @click="downloadReceipt(row)">下载原件</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination v-if="total > 0" size="small" :current-page="page" :page-size="pageSize" :total="total"
          :pager-count="5" layout="total, prev, pager, next" class="payment-receipts__pagination" @current-change="loadReceipts" />
      </template>
    </div>
  </section>
</template>

<style scoped>
.payment-receipts { margin-top: 20px; border-top: 1px solid var(--el-border-color-lighter); padding-top: 16px; }
.payment-receipts__header, .payment-receipts__actions, .payment-receipts__error { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.payment-receipts__header { justify-content: space-between; margin-bottom: 12px; }
.payment-receipts__header h3 { margin: 0; font-size: 14px; color: var(--el-text-color-primary); }
.payment-receipts__status { color: var(--el-text-color-secondary); font-size: 13px; padding: 12px 0; }
.payment-receipts__error { color: var(--el-color-danger); font-size: 13px; }
.payment-receipts__table { width: 100%; }
.payment-receipts__number { overflow-wrap: anywhere; }
.payment-receipts__pagination { justify-content: flex-end; flex-wrap: wrap; margin-top: 12px; }
</style>
