<script setup>
import { defineAsyncComponent, onBeforeUnmount, ref, watch } from 'vue'
const NodeEditor = defineAsyncComponent(() => import('./NodeQuickEditor.vue'))
const editingNode = ref(null)
import { ElMessage } from 'element-plus'
import { buildDashboardApiUrl, requestDashboardMutation, requestDashboardReadPost } from '../../services/api'
const props = defineProps({ modelValue: Boolean, machine: Object })
const emit = defineEmits(['update:modelValue'])
const host = ref(''), rows = ref([]), token = ref(''), loading = ref(false), applying = ref(false), error = ref('')
let generation = 0
let disposed = false
let previewController = null
watch(() => [props.modelValue, props.machine?.id], () => {
  generation++
  previewController?.abort(); previewController = null
  loading.value = false; applying.value = false
  editingNode.value = null; host.value = ''; rows.value = []; token.value = ''; error.value = ''
  if (props.modelValue && props.machine?.id) preview(false)
}, { immediate:true, flush:'sync' })
watch(host, () => {
  generation++
  previewController?.abort(); previewController = null
  if (!applying.value) loading.value = false
  token.value = ''; rows.value = rows.value.map(row=>({...row,after:null}))
}, { flush:'sync' })
onBeforeUnmount(() => { disposed = true; generation++; previewController?.abort() })
async function preview(useNewHost = true) {
  if (disposed || !props.modelValue || !props.machine?.id || applying.value) return
  if (useNewHost && !host.value.trim()) { error.value = '请填写新的 IPv4 或 IPv6'; return }
  const id = ++generation
  previewController?.abort()
  const controller = new AbortController()
  previewController = controller
  loading.value = true; error.value = ''; token.value = ''
  try {
    const result = await requestDashboardReadPost(buildDashboardApiUrl('subscription-entry/preview'), { machine_id: props.machine.id, ...(useNewHost ? { host: host.value.trim() } : {}) }, { signal: controller.signal })
    if (id !== generation) return
    if (!Array.isArray(result?.data?.rows)) throw new Error('订阅入口预览返回格式异常，请重试')
    rows.value = result.data.rows; token.value = result.data.token
  } catch (e) { if (id === generation) error.value = e.message } finally {
    if (previewController === controller) previewController = null
    if (id === generation) loading.value = false
  }
}
async function apply() {
  if (disposed || !props.modelValue || loading.value || applying.value || !token.value || !rows.value.length || editingNode.value) return
  const id = ++generation
  const previewToken = token.value
  loading.value = true; applying.value = true; error.value = ''; token.value = ''
  try {
    const result = await requestDashboardMutation(buildDashboardApiUrl('subscription-entry/apply'), { token: previewToken })
    if (id !== generation) return
    ElMessage.success(`已更新 ${result.data.updated} 个订阅入口`); emit('update:modelValue', false)
  } catch (e) { if (id === generation) error.value = e.message } finally {
    if (id === generation) { loading.value = false; applying.value = false }
  }
}
</script>
<template>
  <el-dialog class="subscription-entry-dialog" :model-value="modelValue" :title="`${machine?.name || ''} · 更换订阅入口 IP`" width="min(820px, calc(100vw - 24px))" :close-on-click-modal="!applying" :close-on-press-escape="!applying" :show-close="!applying" @update:model-value="emit('update:modelValue', $event)">
    <p>仅修改已绑定节点的客户端入口 IP，端口和机器实际配置保持不变。</p>
    <div class="entry-toolbar"><el-input v-model="host" placeholder="新的 IPv4 或 IPv6" :disabled="loading" /><el-button :loading="loading" @click="preview">预览</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table v-loading="loading" :data="rows" max-height="360" empty-text="暂无已绑定的订阅节点" row-key="key">
      <el-table-column prop="name" label="节点" min-width="160" show-overflow-tooltip />
      <el-table-column prop="before" label="当前入口" min-width="170" show-overflow-tooltip />
      <el-table-column label="新入口" min-width="170" show-overflow-tooltip><template #default="{ row }">{{ row.after || '—' }}</template></el-table-column>
      <el-table-column prop="port" label="端口" width="90" />
      <el-table-column label="操作" width="76" fixed="right"><template #default="{ row }"><el-button link type="primary" :disabled="!!editingNode || loading" @click="editingNode = row.key">编辑</el-button></template></el-table-column>
    </el-table>
    <template #footer><el-button :disabled="applying" @click="emit('update:modelValue', false)">取消</el-button><el-button type="primary" :disabled="loading || !token || !rows.length || !!editingNode" :loading="applying" @click="apply">确认更新 {{ rows.length }} 个入口</el-button></template>
  </el-dialog>
  <NodeEditor v-if="modelValue && editingNode" :key="editingNode" :node-key="editingNode" @close="editingNode = null" @saved="preview(false)" />
</template>
<style scoped>
.entry-toolbar { display:flex;gap:12px;margin:16px 0; }
:global(.el-dialog.subscription-entry-dialog) { width:min(820px, calc(100vw - 24px)) !important;max-width:none; }
</style>
