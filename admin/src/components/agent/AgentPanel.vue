<script setup>
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue'
import { ArrowUp, Settings2, X, ArrowLeft, MessageSquare, History, Square, Plus, ChevronDown } from 'lucide-vue-next'
import { ElMessage } from 'element-plus'
import { buildDashboardApiUrl, requestDashboardApi, requestDashboardMutation } from '../../services/api'
import { activeAgentStatus, agentMessages, mergeAgentRun } from '../../utils/agentConversation'
import AgentAnswer from './AgentAnswer.vue'
import { shouldSendAgentMessage } from '../../utils/agentComposer'
import { createPollGate } from '../../utils/pollGate'
const AgentOperationHistory = defineAsyncComponent(() => import('./AgentOperationHistory.vue'))
const AgentApprovalCard = defineAsyncComponent(() => import('./AgentApprovalCard.vue'))
defineEmits(['close'])
const props = defineProps({ active: { type: Boolean, default: true } })
const settingsOpen = ref(false)
const operationsOpen = ref(false), selectedOperation = ref(null)
function showOperations(id = null) { selectedOperation.value = id; settingsOpen.value = false; operationsOpen.value = true }
function markUndone(id) {
  for (const item of history.value) for (const action of item.actions || []) for (const approval of action.approvals || []) if (approval.operation_id === id) approval.undone = true
}
function recordApprovals(results) {
  for (const message of history.value) for (const action of message.actions || []) for (const item of action.approvals) {
    const result = results.find(result => result.token === item.token)
    if (result) { item.done = true; item.operation_id = result.operation_id; item.undone = result.status === 'undone' }
  }
}
function recordRejection(token) {
  for (const message of history.value) for (const action of message.actions || []) for (const item of action.approvals) if (item.token === token) item.cancelled = true
}
const conversation = ref(null)
const builtinModels = [
  { key: 'grok', name: 'Grok 4.7', model: 'grok-4.7', icon: 'grok', period: '优先调用' },
  { key: 'luna', name: 'GPT-6 Luna', model: 'gpt-6-luna', icon: 'openai', period: '备用模型' },
]
const protocols = [{ value: 'openai', label: 'OpenAI Compatible', hint: '基础地址包含 /v1，自动追加 /chat/completions' }, { value: 'responses', label: 'OpenAI Responses', hint: '基础地址包含 /v1，自动追加 /responses' }, { value: 'anthropic', label: 'Anthropic Messages', hint: '基础地址包含 /v1，自动追加 /messages' }, { value: 'gemini', label: 'Gemini 原生', hint: '基础地址包含 /v1beta，自动追加模型与 streamGenerateContent' }]
const emptyProfiles = () => Object.fromEntries(builtinModels.map(m => [m.key, { enabled: true, protocol: 'openai', base_url: '', api_key: '', has_api_key: false }]))
const config = ref({ enabled: false, tools: [], profiles: emptyProfiles() })
const expandedProfiles = ref([])
const models = computed(() => [...builtinModels, ...Object.entries(config.value.profiles).filter(([key]) => key.startsWith('custom_')).map(([key, p]) => ({ key, name: p.label || '自定义模型', model: p.model, custom: true, period: '手动选择' }))])
function addModel() {
  if (disposed || saving.value || !settingsReady.value || settingsLoading.value) return
  const key = `custom_${crypto.randomUUID()}`
  config.value.profiles[key] = { label: '', model: '', protocol: 'openai', enabled: true, base_url: '', api_key: '', has_api_key: false }
  expandedProfiles.value = [...expandedProfiles.value, key]
}
function removeModel(key) {
  if (disposed || saving.value || !settingsReady.value || settingsLoading.value) return
  delete config.value.profiles[key]
  if (modelChoice.value === key) modelChoice.value = 'auto'
}
const modelChoice = ref('auto'), now = ref(Date.now()), clockOffset = ref(0)
let clockTimer = null, tailFrame = null
const selectedModel = computed(() => models.value.find(m => m.key === modelChoice.value))
const iconUrl = icon => `${import.meta.env.BASE_URL}assets/model-icons/${icon}.svg`
const replyModel = id => models.value.find(m => m.model === id)
function updateConfig(value) {
  config.value = { ...value, profiles: Object.fromEntries(Object.entries({ ...emptyProfiles(), ...value.profiles }).map(([key, p]) => [key, { protocol: 'openai', ...p, api_key: '' }])) }
  if (modelChoice.value !== 'auto' && !config.value.profiles[modelChoice.value]) modelChoice.value = 'auto'
  if (value.schedule?.server_time) clockOffset.value = value.schedule.server_time * 1000 - Date.now()
}
const tools = ref({}), busy = ref(false), saving = ref(false), error = ref(''), message = ref(''), history = ref([])
const settingsLoading = ref(false), settingsReady = ref(false), settingsError = ref('')
const toolLabels = { preview_machine_provision: '机器上线配置', nodes_select: '精确筛选节点', preview_node_batch: '按目标集合批量编辑', node_health: '节点健康检查', node_onboarding_check: '节点对接预检', preview_node_visibility: '显示/隐藏节点', dashboard_summary: '面板汇总', machine_status: '机器状态', nodes_list: '节点查询', preview_entry_ip: '按入口机器改 IP', preview_node_sort: '节点排序', preview_node_rename: '批量重命名', preview_node_ip: '按节点改入口 IP' }
const conversationId = ref(null), activeRun = ref(null), cancelling = ref(false), followTail = ref(true), restoring = ref(false)
const contextInfo = ref({ turns: 0, max_turns: 8, characters: 0, omitted: 0 })
let pollTimer = null, pollController = null, disposed = false, pollFailures = 0
let submitting = false
const readController = new AbortController()
const runPollGate = createPollGate()
const sessionKey = 'panelcore-agent-conversation'
const missingRun = error => /404|已过期|不属于/.test(error.message)
function remember() {
  try { sessionStorage.setItem(sessionKey, JSON.stringify({ conversationId: conversationId.value, runId: activeRun.value })) } catch {}
}
function applyRun(run) {
  conversationId.value = run.conversation_id
  mergeAgentRun(history.value, run)
  busy.value = activeAgentStatus(run.status)
  activeRun.value = busy.value ? run.id : null
  contextInfo.value = run.context || contextInfo.value
  remember()
  if (!busy.value && run.status === 'failed' && !message.value) message.value = run.prompt
}
async function loadConversation(id) {
  const data = (await requestDashboardApi(buildDashboardApiUrl('agent/conversation', [['id', id]]), { signal: readController.signal })).data
  if (disposed) return
  history.value = data.turns.flatMap(agentMessages); contextInfo.value = data.context
  conversationId.value = data.id; activeRun.value = data.active_run
  if (data.active_run) await pollRun(data.active_run)
}
async function restoreConversation() {
  if (disposed || restoring.value) return
  restoring.value = true; error.value = ''
  try {
    const saved = JSON.parse(sessionStorage.getItem(sessionKey) || 'null')
    conversationId.value = saved?.conversationId || null; activeRun.value = saved?.runId || null
    if (saved?.conversationId) await loadConversation(saved.conversationId)
    else if (saved?.runId) await pollRun(saved.runId)
  } catch (e) {
    if (disposed || readController.signal.aborted) return
    if (missingRun(e) || e instanceof SyntaxError) { conversationId.value = null; activeRun.value = null; remember() }
    else error.value = '暂时无法恢复会话，请重新连接后恢复，不会自动重复提交。'
  } finally { restoring.value = false; syncActivity() }
}
async function pollRun(id) {
  clearTimeout(pollTimer); pollTimer = null
  if (disposed || !props.active || document.hidden) return
  const poll = runPollGate.begin()
  if (!poll) return
  const controller = new AbortController()
  pollController = controller
  try {
    const run = (await requestDashboardApi(buildDashboardApiUrl('agent/run', [['id', id]]), { signal: controller.signal })).data
    if (disposed || controller.signal.aborted || !props.active || document.hidden || !runPollGate.isCurrent(poll)) return
    pollFailures = 0; error.value = ''; applyRun(run)
    if (busy.value) pollTimer = setTimeout(() => pollRun(id), 1000)
    else if (conversationId.value) {
      try {
        const data = (await requestDashboardApi(buildDashboardApiUrl('agent/conversation', [['id', conversationId.value]]), { signal: controller.signal })).data
        if (!disposed && runPollGate.isCurrent(poll)) contextInfo.value = data.context
      } catch { /* A failed context refresh must not revive a finished run. */ }
    }
  } catch (e) {
    if (disposed || controller.signal.aborted || !props.active || document.hidden || !runPollGate.isCurrent(poll)) return
    if (missingRun(e)) { busy.value = false; activeRun.value = null; conversationId.value = null; remember(); error.value = '会话已过期，可以开始新对话。'; return }
    pollFailures++
    error.value = `进度连接暂时中断，正在恢复。${e.message}`
    if (pollFailures <= 4) pollTimer = setTimeout(() => pollRun(id), Math.min(8000, pollFailures * 1500))
    else { busy.value = false; activeRun.value = id; remember(); error.value = '暂时无法读取执行结果，任务可能仍在后台运行。请恢复进度，不要重复提交。' }
  } finally {
    runPollGate.finish(poll)
    if (pollController === controller) pollController = null
  }
}
function newConversation() {
  if (busy.value || activeRun.value || restoring.value || cancelling.value) return
  pauseActivity()
  history.value = []; conversationId.value = null; message.value = ''; error.value = ''
  contextInfo.value = { turns: 0, max_turns: 8, characters: 0, omitted: 0 }; remember()
  syncActivity()
}
async function stop() {
  if (!activeRun.value || cancelling.value) return
  cancelling.value = true
  try {
    const run = (await requestDashboardMutation(buildDashboardApiUrl('agent/run-cancel'), { id: activeRun.value })).data
    if (disposed) return
    clearTimeout(pollTimer); pollTimer = null; runPollGate.invalidate()
    pollController?.abort(); pollController = null; applyRun(run)
    if (busy.value) await pollRun(run.id)
  }
  catch (e) { if (!disposed) error.value = e.message }
  finally { cancelling.value = false; syncActivity() }
}
function elapsed(item) { return Math.max(0, Math.floor(((item.running ? now.value + clockOffset.value : (item.updatedAt || item.createdAt) * 1000) - item.createdAt * 1000) / 1000)) }
async function load() {
  if (disposed || saving.value || settingsLoading.value) return
  settingsLoading.value = true; settingsError.value = ''
  try {
    const r = await requestDashboardApi(buildDashboardApiUrl('agent/settings'), { signal: readController.signal })
    if (disposed) return
    if (!r.data?.config || typeof r.data.config !== 'object' || Array.isArray(r.data.config) || !r.data.tools || typeof r.data.tools !== 'object') {
      throw new Error('助手设置返回格式异常，请重新加载')
    }
    updateConfig(r.data.config); tools.value = r.data.tools
    settingsReady.value = true
  } catch (e) { if (!disposed && !readController.signal.aborted) settingsError.value = e.message }
  finally { settingsLoading.value = false }
}
async function save() {
  if (disposed || saving.value || settingsLoading.value || !settingsReady.value) return
  saving.value = true; settingsError.value = ''
  try {
    const snapshot = JSON.parse(JSON.stringify({ enabled: config.value.enabled, tools: config.value.tools, profiles: config.value.profiles }))
    const r = await requestDashboardMutation(buildDashboardApiUrl('agent/settings'), snapshot)
    if (disposed) return
    if (!r.data || typeof r.data !== 'object' || Array.isArray(r.data)) throw new Error('提交已返回，但配置内容未确认，请重新加载核对')
    updateConfig(r.data); ElMessage.success('Agent 配置已保存')
  } catch (e) { if (!disposed) settingsError.value = e.message }
  finally { saving.value = false }
}
async function send() {
  if (!config.value.enabled || !message.value.trim() || busy.value || activeRun.value || restoring.value) return
  submitting = true
  const prompt = message.value.trim(); message.value = ''; busy.value = true; error.value = ''
  const id = crypto.randomUUID(); activeRun.value = id; remember(); followTail.value = true
  mergeAgentRun(history.value, { id, prompt, status: 'queued', created_at: Math.floor((Date.now() + clockOffset.value) / 1000), steps: [{ label: '提交请求', type: 'queued' }] })
  try {
    const r = await requestDashboardMutation(buildDashboardApiUrl('agent/start'), { message: prompt, model_choice: modelChoice.value, client_id: id, conversation_id: conversationId.value })
    if (disposed || activeRun.value !== id) return
    applyRun(r.data)
    if (busy.value) await pollRun(id)
  }
  catch (e) {
    if (disposed || activeRun.value !== id) return
    if (!props.active || document.hidden) {
      busy.value = false
      error.value = '暂时无法确认提交结果，请恢复执行进度，不要重复提交。'
      return
    }
    try {
      const run = (await requestDashboardApi(buildDashboardApiUrl('agent/run', [['id', id]]), { signal: readController.signal })).data
      if (disposed || activeRun.value !== id) return
      applyRun(run); if (busy.value) await pollRun(id)
    }
    catch (lookupError) {
      if (disposed || activeRun.value !== id) return
      if (missingRun(lookupError)) applyRun({ id, conversation_id: conversationId.value, prompt, status: 'failed', error: e.message, created_at: Math.floor(Date.now() / 1000), updated_at: Math.floor(Date.now() / 1000) })
      else { busy.value = false; activeRun.value = id; remember(); error.value = '暂时无法确认提交结果，请恢复执行进度，不要重复提交。' }
    }
  }
  finally { submitting = false; syncActivity() }
}
function composerKeydown(event) {
  if (!shouldSendAgentMessage(event)) return
  event.preventDefault()
  send()
}
function followConversationTail() {
  cancelAnimationFrame(tailFrame)
  tailFrame = null
  if (disposed || !props.active || document.hidden || !followTail.value || settingsOpen.value || operationsOpen.value) return
  tailFrame = requestAnimationFrame(() => {
    tailFrame = null
    const element = conversation.value
    if (!disposed && props.active && !document.hidden && followTail.value && !settingsOpen.value && !operationsOpen.value && element?.clientHeight) {
      element.scrollTo({ top: element.scrollHeight, behavior: 'auto' })
    }
  })
}
function handleConversationScroll(event) {
  if (disposed || !props.active || document.hidden || settingsOpen.value || operationsOpen.value) return
  const element = event.currentTarget
  followTail.value = element.scrollHeight - element.scrollTop - element.clientHeight < 90
}
function pauseActivity() {
  clearTimeout(pollTimer); pollTimer = null
  runPollGate.invalidate()
  pollController?.abort(); pollController = null
  clearInterval(clockTimer); clockTimer = null
  cancelAnimationFrame(tailFrame); tailFrame = null
}
function syncActivity() {
  if (disposed || !props.active || document.hidden) { pauseActivity(); return }
  now.value = Date.now()
  if (clockTimer === null) clockTimer = setInterval(() => { if (busy.value || Date.now() - now.value >= 10000) now.value = Date.now() }, 1000)
  if (activeRun.value && !restoring.value && !cancelling.value && !submitting && pollTimer === null && pollController === null) { pollFailures = 0; pollRun(activeRun.value) }
  followConversationTail()
}
watch(() => props.active, syncActivity)
watch(() => [props.active, settingsOpen.value, operationsOpen.value, history.value.length, history.value.at(-1)?.text?.length, history.value.at(-1)?.steps?.length], followConversationTail, { flush: 'post' })
onMounted(() => { document.addEventListener('visibilitychange', syncActivity); load(); restoreConversation(); syncActivity() })
onUnmounted(() => { disposed = true; pauseActivity(); readController.abort(); document.removeEventListener('visibilitychange', syncActivity) })
</script>
<template>
  <section class="agent-panel">
    <header class="agent-header">
      <div><MessageSquare :size="18" /><strong>{{ settingsOpen ? '助手设置' : operationsOpen ? '操作记录' : 'PanelCore 助手' }}</strong></div>
      <div>
        <el-button v-if="!settingsOpen && !operationsOpen" class="agent-new-chat" text :disabled="busy || !!activeRun || restoring || cancelling" aria-label="新对话" title="开始不携带旧上下文的新对话" @click="newConversation"><Plus :size="15" />新对话</el-button>
        <el-button v-if="!settingsOpen && !operationsOpen" text circle aria-label="操作记录" title="操作记录与撤销" @click="showOperations()"><History :size="17" /></el-button>
        <el-button text circle :aria-label="settingsOpen || operationsOpen ? '返回对话' : '助手设置'" :title="settingsOpen || operationsOpen ? '返回对话' : '模型与权限设置'" @click="settingsOpen || operationsOpen ? (settingsOpen = false, operationsOpen = false) : settingsOpen = true"><ArrowLeft v-if="settingsOpen || operationsOpen" :size="17" /><Settings2 v-else :size="17" /></el-button>
        <el-button text circle aria-label="收起助手" title="收起助手" @click="$emit('close')"><X :size="18" /></el-button>
      </div>
    </header>
    <el-alert v-if="error && !operationsOpen" :title="error" type="error" :closable="false" />
    <el-button v-if="activeRun && !busy" text @click="busy = true; pollFailures = 0; pollRun(activeRun)">恢复执行进度</el-button>
    <el-button v-else-if="conversationId && error && !busy" text :loading="restoring" @click="restoreConversation">恢复会话</el-button>
      <AgentOperationHistory v-if="operationsOpen" :active="props.active && !settingsOpen" :selected-id="selectedOperation" @undone="markUndone" />
      <div v-show="settingsOpen" class="agent-settings">
        <el-form class="agent-settings-form" label-position="top" :disabled="saving || settingsLoading || !settingsReady" v-loading="settingsLoading" @submit.prevent="save">
          <div class="agent-settings-fields">
          <el-alert v-if="settingsError" :title="settingsError" type="error" :closable="false"><el-button text :disabled="saving" :loading="settingsLoading" @click="load">重新加载</el-button></el-alert>
          <el-form-item label="启用"><el-switch v-model="config.enabled" /></el-form-item>
          <p class="agent-note">自动选择时 Grok 4.7 优先，GPT-6 Luna 备用；手动选择时只调用指定模型。</p>
          <el-button text type="primary" @click="addModel"><Plus :size="15" />添加自定义模型</el-button>
          <el-collapse v-model="expandedProfiles" class="agent-profiles">
            <el-collapse-item v-for="model in models" :key="model.key" :name="model.key">
              <template #title><span class="agent-model-title"><img v-if="model.icon" :src="iconUrl(model.icon)" alt="" /><MessageSquare v-else :size="18" /><strong>{{ model.name }}</strong><small>{{ model.period }}</small></span></template>
              <template v-if="model.custom">
                <el-form-item label="显示名称"><el-input v-model="config.profiles[model.key].label" maxlength="80" placeholder="自定义模型名称" /></el-form-item>
                <el-form-item label="模型 ID"><el-input v-model="config.profiles[model.key].model" maxlength="150" placeholder="API 提供的模型 ID" /></el-form-item>
                <el-button text type="danger" @click="removeModel(model.key)">移除此模型（保存后生效）</el-button>
              </template>
              <p class="agent-note">{{ model.model }} · {{ config.profiles[model.key].has_api_key ? 'Key 已保存' : '尚未配置 Key' }}</p>
              <el-form-item label="参与调用"><el-switch v-model="config.profiles[model.key].enabled" /></el-form-item>
              <el-form-item label="API 协议"><el-select v-model="config.profiles[model.key].protocol"><el-option v-for="protocol in protocols" :key="protocol.value" :value="protocol.value" :label="protocol.label" /></el-select></el-form-item>
              <p class="agent-note">{{ protocols.find(p => p.value === config.profiles[model.key].protocol)?.hint }}。模型需支持工具调用。</p>
              <el-form-item :label="`${model.name} API 地址`"><el-input v-model="config.profiles[model.key].base_url" placeholder="https://api.example.com/v1" /></el-form-item>
              <el-form-item :label="`${model.name} API Key`"><el-input v-model="config.profiles[model.key].api_key" type="password" show-password autocomplete="new-password" :placeholder="config.profiles[model.key].has_api_key ? '留空保持不变' : '填写独立 API Key'" /></el-form-item>
            </el-collapse-item>
          </el-collapse>
          <el-form-item label="允许调用的功能"><el-checkbox-group v-model="config.tools"><el-checkbox v-for="(label, name) in tools" :key="name" :value="name" :title="label">{{ toolLabels[name] || label }}</el-checkbox></el-checkbox-group></el-form-item>
          <p class="agent-note">只允许调用你勾选的功能。提问及已授权的查询结果会发送到所选模型 API；修改还需要你逐次批准。</p>
          </div>
          <div class="agent-settings-actions"><el-button type="primary" native-type="submit" :disabled="settingsLoading || !settingsReady" :loading="saving">保存配置</el-button></div>
        </el-form>
      </div>
      <div v-show="!settingsOpen && !operationsOpen" ref="conversation" class="agent-history" aria-live="polite" :aria-busy="busy" @scroll="handleConversationScroll">
        <div v-if="!history.length" class="agent-welcome">
          <span class="agent-kicker">PANELCORE</span>
          <h3>有什么需要处理？</h3>
          <p>查看状态，整理节点，批量调整入口。</p>
          <button v-for="example in ['查看离线机器和异常节点', '按国家整理节点顺序', '帮我批量修改节点名称']" :key="example" type="button" @click="message = example">{{ example }}<ArrowUp :size="14" /></button>
          <el-button v-if="!config.enabled" text type="primary" @click="settingsOpen = true">配置模型后开始使用</el-button>
        </div>
        <article v-for="item in history" :key="item.id" class="agent-message" :class="`agent-message--${item.role}`">
          <strong class="agent-model-title"><img v-if="replyModel(item.model)?.icon" :src="iconUrl(replyModel(item.model).icon)" alt="" />{{ item.role === 'user' ? '你' : (replyModel(item.model)?.name || 'Agent') }}</strong>
          <details v-if="item.role === 'assistant' && item.steps?.length" class="agent-trace" :open="item.running">
            <summary><span :class="['agent-trace-dot', { running: item.running }]" />{{ item.running ? item.steps.at(-1)?.label : '执行记录' }}<span>{{ elapsed(item) }}s</span><ChevronDown :size="13" /></summary>
            <ol><li v-for="(step, i) in item.steps" :key="i"><span>{{ step.tool ? `${step.type === 'tool_start' ? '开始' : '完成'}：${toolLabels[step.tool] || step.tool}` : step.label }}<template v-if="step.count != null"> · {{ step.count }} 项</template></span><p v-if="step.summary">{{ step.summary }}</p></li></ol>
          </details>
          <AgentAnswer v-if="item.role === 'assistant' && item.text" :text="item.text" />
          <p v-else-if="item.text">{{ item.text }}</p>
          <div v-if="item.failed" class="agent-failure"><span>{{ item.error || '本轮失败' }}</span><el-button link type="primary" :disabled="busy || !!activeRun" @click="message = item.prompt; send()">重试本轮</el-button></div>
          <span v-if="item.cancelled" class="agent-note">已停止生成，未执行新的修改</span>
          <AgentApprovalCard v-if="item.actions?.some(action => action.approvals.length)" :actions="item.actions" :now-seconds="Math.floor((now + clockOffset) / 1000)" @approved="recordApprovals" @rejected="recordRejection" @history="showOperations()" />
        </article>
      </div>
      <footer v-show="!settingsOpen && !operationsOpen" class="agent-footer">
        <div class="agent-model-picker">
          <el-select v-model="modelChoice" aria-label="选择助手模型" :disabled="busy" size="small">
            <template #label><span class="agent-model-title"><img v-if="selectedModel?.icon" :src="iconUrl(selectedModel.icon)" alt="" /><Settings2 v-else :size="14" />{{ selectedModel?.name || '自动选择' }}</span></template>
            <el-option value="auto" label="自动选择"><span class="agent-model-title"><Settings2 :size="15" />自动选择</span></el-option>
            <el-option v-for="model in models" :key="model.key" :value="model.key" :label="model.name" :disabled="!config.profiles[model.key].enabled || !config.profiles[model.key].has_api_key || !config.profiles[model.key].base_url"><span class="agent-model-title"><img v-if="model.icon" :src="iconUrl(model.icon)" alt="" /><MessageSquare v-else :size="15" />{{ model.name }}</span></el-option>
          </el-select>
          <span class="agent-note">{{ modelChoice === 'auto' ? 'Grok 优先' : '手动选择' }}</span>
        </div>
        <form class="agent-composer" @submit.prevent="send"><el-input v-model="message" type="textarea" :autosize="{ minRows: 2, maxRows: 5 }" maxlength="6000" :placeholder="busy ? '可继续输入下一条，右侧停止当前任务' : 'Enter 发送，Shift+Enter 换行'" @keydown="composerKeydown" /><button class="agent-send" :class="{ stopping: busy }" :type="busy ? 'button' : 'submit'" :aria-label="busy ? '停止生成' : '发送'" :disabled="cancelling || (!busy && (!config.enabled || !message.trim() || !!activeRun))" @click="busy && stop()"><Square v-if="busy" :size="14" /><ArrowUp v-else :size="18" /></button></form>
        <p class="agent-note" :title="`历史上下文 ${contextInfo.characters} 字符；较早 ${contextInfo.omitted} 轮未发送给模型。会话保留 24 小时。`">历史 {{ contextInfo.turns }}/8 轮 · 修改需确认<template v-if="contextInfo.omitted"> · 较早对话已移出上下文</template></p>
      </footer>
  </section>
</template>
<style scoped>
.agent-note { color:var(--muted);font-size:12px;line-height:1.7; }
.agent-model-title { display:inline-flex;align-items:center;gap:7px;min-width:0;font-size:12px; }
.agent-model-title img { width:18px;height:18px;object-fit:contain;flex:none; }
.agent-model-title strong { font-weight:500; }.agent-model-title small { color:var(--muted);font-size:11px; }
.agent-profiles { margin-bottom:20px; }
.agent-model-picker { display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:9px; }
.agent-model-picker .el-select { width:190px; }
.agent-panel { display:flex;flex-direction:column;height:100%;min-height:0;color:var(--text);background:var(--panel-raised); }
.agent-header { display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--line); }
.agent-header > div { display:flex;align-items:center;gap:8px; }
.agent-header > div:last-child { gap:2px; }.agent-header .el-button { margin:0; }.agent-header .is-circle { width:32px;height:32px; }.agent-header .agent-new-chat { padding-inline:8px;gap:4px;font-size:12px; }
.agent-header > div:first-child { min-width:0; }.agent-header strong { white-space:nowrap;font-size:14px; }
.agent-history, .agent-settings { flex:1;min-height:0;overflow:auto;padding:20px; }
.agent-settings { overflow:hidden;padding:0; }
.agent-settings-form { display:flex;flex-direction:column;height:100%; }
.agent-settings-fields { flex:1;min-height:0;overflow:auto;padding:20px; }
.agent-settings-actions { flex:none;border-top:1px solid var(--line);padding:12px 20px; }
.agent-welcome { padding:16px 0; }
.agent-kicker { font-size:11px;letter-spacing:.16em;color:var(--primary); }
.agent-welcome h3 { font-size:23px;font-weight:500;margin:12px 0; }
.agent-welcome p { color:var(--muted);font-size:13px;margin-bottom:24px; }
.agent-welcome > button:not(.el-button) { display:flex;align-items:center;justify-content:space-between;width:100%;padding:13px 14px;margin-top:8px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--bg-soft);color:var(--text);text-align:left;cursor:pointer;font:inherit;font-size:13px; }
.agent-welcome > button:hover { border-color:var(--primary); }
.agent-message { padding:14px;border-radius:var(--radius-sm);margin-bottom:12px;background:var(--bg-soft);font-size:13px; }
.agent-message--user { margin-left:30px;background:var(--primary-soft); }
.agent-message--assistant { padding:10px 0 16px;background:transparent;border-radius:0; }
.agent-message p { white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.7; }
.agent-footer { padding:12px 16px;border-top:1px solid var(--line); }
.agent-footer p { margin:8px 0 0;text-align:center; }
.agent-composer { position:relative;display:flex;align-items:flex-end;min-width:0; }
.agent-composer :deep(.el-textarea__inner) { box-shadow:none;background:var(--bg-soft);resize:none;padding-right:54px;min-height:72px; }
.agent-send { position:absolute;right:9px;bottom:9px;display:grid;place-items:center;width:36px;height:36px;min-width:36px;padding:0;margin:0;border:0;border-radius:50%;background:var(--primary);color:white;cursor:pointer; }
.agent-send:disabled { opacity:.45;cursor:not-allowed; }.agent-send.stopping { background:var(--primary-soft);color:var(--primary);border:1px solid var(--line-strong); }
.agent-send:focus-visible { outline:2px solid var(--primary);outline-offset:3px; }
.agent-trace { margin-top:12px;color:var(--muted);font-size:12px; }.agent-trace summary { display:flex;align-items:center;gap:6px;cursor:pointer;list-style:none;line-height:1.6; }.agent-trace summary > span:last-of-type { margin-left:auto;white-space:nowrap;font-variant-numeric:tabular-nums; }.agent-trace summary::-webkit-details-marker { display:none; }
.agent-trace[open] { padding:10px 12px;background:var(--bg-soft);border-radius:var(--radius-sm); }
.agent-trace ol { margin:10px 0 0;padding-left:18px;border-left:1px solid var(--line);list-style:none; }.agent-trace li { padding:5px 0;line-height:1.6; }.agent-trace li p { margin:4px 0; }.agent-trace-dot { width:6px;height:6px;flex:none;border-radius:50%;background:var(--muted); }.agent-trace-dot.running { background:var(--primary);animation:agent-pulse 1.4s ease-in-out infinite; }
.agent-failure { display:flex;flex-direction:column;align-items:flex-start;gap:8px;margin-top:12px;padding-top:10px;border-top:1px solid var(--line);color:var(--danger, #b33c32);font-size:12px;line-height:1.7; }
@keyframes agent-pulse { 50% { opacity:.35; } }
@media(prefers-reduced-motion:reduce) { .agent-trace-dot.running { animation:none; } }
.el-checkbox-group { display:grid;grid-template-columns:1fr 1fr;gap:4px; }
@media(max-width:380px) { .agent-header { padding:10px 12px; }.agent-header > div:first-child > svg { display:none; }.agent-header strong { font-size:13px; } }
@media(max-height:500px) { .agent-header { padding-block:8px; }.agent-history { padding:12px 16px; }.agent-footer { padding:8px 16px; }.agent-footer p { margin-top:4px; }.agent-model-picker { margin-bottom:6px; } }
</style>
