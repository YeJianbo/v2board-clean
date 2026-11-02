<script setup>
import { computed, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { ChevronDown, ShieldCheck } from 'lucide-vue-next'
import AgentChangeList from './AgentChangeList.vue'
import { agentApprovalSummary } from '../../utils/agentConversation'
import { buildDashboardApiUrl, requestDashboardMutation } from '../../services/api'

const props = defineProps({ actions: { type: Array, default: () => [] }, nowSeconds: { type: Number, required: true } })
const emit = defineEmits(['approved', 'rejected', 'history'])
const selected = ref([]), busy = ref(false), error = ref(''), expanded = ref(false)
const titles = { provision: '创建节点与入口转发（隐藏待验证）', visibility: '修改订阅显示状态', sort: '调整节点顺序', rename: '修改节点名称', ip: '修改入口 IP', entry_ip: '修改入口 IP' }
const items = computed(() => props.actions.flatMap(action => action.approvals.map(approval => ({
    token: approval.token, done: approval.done, cancelled: approval.cancelled, undone: approval.undone,
  expired: action.expires_at && action.expires_at <= props.nowSeconds,
  title: titles[action.operation] || '修改节点',
  rows: approval.key ? action.rows.filter(row => row.key === approval.key) : action.rows,
  isSort: action.operation === 'sort',
}))))
const pending = computed(() => items.value.filter(item => !item.done && !item.cancelled && !item.expired))
watch(() => pending.value.length > 0, value => { expanded.value = value }, { immediate: true })
const selectedPending = computed(() => pending.value.filter(item => selected.value.includes(item.token)))
const resultSummary = computed(() => agentApprovalSummary(items.value))
watch(() => props.actions, () => { selected.value = []; error.value = '' })
function toggle(token, value) {
  selected.value = value ? [...new Set([...selected.value, token])] : selected.value.filter(id => id !== token)
}
async function approve(tokens) {
  if (!tokens.length || busy.value) return
  busy.value = true; error.value = ''
  try {
    const result = (await requestDashboardMutation(buildDashboardApiUrl('agent/confirm-batch'), { tokens })).data
    emit('approved', result.operations)
    selected.value = selected.value.filter(token => !tokens.includes(token))
    ElMessage.success(result.updated ? `已批准执行，更新 ${result.updated} 个节点变更` : '操作已处理，未重复执行')
    if (!pending.value.length) expanded.value = false
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
async function reject(token) {
  busy.value = true; error.value = ''
  try { await requestDashboardMutation(buildDashboardApiUrl('agent/cancel'), { token }); emit('rejected', token) }
  catch (e) { error.value = e.message } finally { busy.value = false }
}
</script>
<template>
  <section class="agent-approval-card" aria-label="更改批准">
    <button type="button" class="approval-heading" :aria-expanded="expanded" @click="expanded = !expanded"><span><ShieldCheck :size="16" />{{ pending.length ? '要确认这些更改吗？' : '更改处理结果' }}</span><span>{{ pending.length ? `${pending.length} 项待批准` : `${items.length} 项` }}<ChevronDown :size="14" :class="{ expanded }" /></span></button>
    <div v-if="expanded" class="approval-content">
    <div class="approval-summary"><span>只执行你批准的项目</span><el-checkbox :model-value="pending.length > 0 && selectedPending.length === pending.length" :indeterminate="selectedPending.length > 0 && selectedPending.length < pending.length" :disabled="busy || !pending.length" @change="selected = $event ? pending.map(item => item.token) : []">全选</el-checkbox></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <div class="approval-items">
      <article v-for="item in items" :key="item.token" class="approval-item" :class="{ settled: item.done || item.cancelled || item.expired }">
        <header>
          <el-checkbox :model-value="selected.includes(item.token)" :disabled="busy || item.done || item.cancelled || item.expired" @change="toggle(item.token, $event)">{{ item.title }}<small v-if="item.isSort"> · {{ item.rows.length }} 个节点，整体批准</small></el-checkbox>
          <el-tag v-if="item.done || item.cancelled || item.expired" size="small" :type="item.done && !item.undone ? 'success' : 'info'">{{ item.undone ? '已撤销' : item.done ? '已批准' : item.cancelled ? '已拒绝' : '已过期' }}</el-tag>
          <div v-else class="approval-item-actions"><el-button link :disabled="busy" @click="reject(item.token)">拒绝</el-button><el-button link type="primary" :disabled="busy" @click="approve([item.token])">批准此项</el-button></div>
        </header>
        <AgentChangeList :rows="item.rows" />
      </article>
    </div>
    </div>
    <div class="approval-footer"><el-button v-if="items.some(item => item.done)" text @click="$emit('history')">操作记录</el-button><div v-if="pending.length"><el-button size="small" :disabled="busy || !selectedPending.length" @click="approve(selectedPending.map(item => item.token))">批准所选{{ selectedPending.length ? ` (${selectedPending.length})` : '' }}</el-button><el-button size="small" type="primary" :loading="busy" @click="approve(pending.map(item => item.token))">批准全部</el-button></div><span v-else>{{ resultSummary }}</span></div>
  </section>
</template>
<style scoped>
.agent-approval-card { margin-top:12px;border:1px solid var(--line-strong);border-radius:var(--radius-sm);overflow:hidden;background:var(--panel-raised); }
.approval-heading { display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;padding:12px;border:0;background:var(--primary-soft);color:var(--text);cursor:pointer;text-align:left; }
.approval-heading span { display:flex;align-items:center;gap:6px;font-size:12px; }.approval-heading span + span { font-size:11px;color:var(--muted);white-space:nowrap; }.approval-heading .expanded { transform:rotate(180deg); }
.approval-content { padding:0 12px; }
.approval-summary { display:flex;justify-content:space-between;align-items:center;color:var(--muted);font-size:11px;padding:6px 0; }
.approval-items { display:flex;flex-direction:column;max-height:340px;overflow:auto;overscroll-behavior:contain; }
.approval-item { padding:10px 0;border-bottom:1px solid var(--line); }.approval-item:last-child { border-bottom:0; }
.approval-item.settled { opacity:.65; }.approval-item header { display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap; }
.approval-item small { color:var(--muted);font-weight:400;font-size:11px; }.approval-item-actions { display:flex;align-items:center;gap:10px; }
.approval-footer { display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:8px;padding:10px 12px;border-top:1px solid var(--line); }.approval-footer > span { text-align:left;color:var(--muted);font-size:11px; }.approval-footer > div { display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap; }
.approval-footer .el-button + .el-button { margin-left:0; }
.el-alert { margin-bottom:12px; }
.approval-item :deep(.el-checkbox__label) { font-size:12px; }.approval-item :deep(.el-checkbox) { margin:0;height:auto;line-height:1.6; }
.approval-item header { align-items:flex-start; }.approval-item-actions :deep(.el-button) { font-size:12px; }
</style>
