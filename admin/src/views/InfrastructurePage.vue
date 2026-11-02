<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, RefreshCw } from 'lucide-vue-next'
import SectionCard from '../components/common/SectionCard.vue'
import InfrastructureRulesEditor from '../components/common/InfrastructureRulesEditor.vue'
import { fetchCloudflareCredentials } from '../services/cloudflare'
import { downloadMigrationPackage, readInfrastructure, mutateInfrastructure } from '../services/infrastructure'
import { fetchManagedMachines } from '../services/machines'
import { copyText } from '../utils/clipboard'
import { createSequence } from '../utils/sequence'

const tab = ref('hosts')
const data = ref({ hosts: [], clusters: [], certificates: [], mesh_groups: [], features: [], services: [] })
const credentials = ref([])
const machines = ref([])
const backups = ref([])
const tasks = ref([])
const events = ref([])
const hostFilter = ref(null)
const loading = ref({})
const errors = ref({})
const busy = ref(false)
const dialog = ref('')
const form = ref({})
const formError = ref('')
const detail = ref('')
const detailTitle = ref('')
const taskDetailTarget = ref(null)
const migrationOutput = ref({ command: '', token: '', expires_at: null, password: '' })
const downloadingMigration = ref(false)
const featureLabels = { services: '服务与定时任务', backups: '创建备份', restores: '恢复备份', deploy: '备份部署', certificates: '证书管理', replication: '主备与共享状态', mesh: 'Mesh 内网' }
const serviceLabels = { nginx: 'Nginx', 'php-fpm': 'PHP-FPM', database: '数据库', redis: 'Redis', horizon: '队列任务', scheduler: '应用定时任务', certbot: 'Certbot', mesh: 'Cloudflare Mesh' }
const stateLabels = { queued: '排队中', dispatched: '执行中', success: '已完成', failed: '失败', unknown: '结果不明', cancelled: '已取消', expired: '已过期', ready: '可用', uploading: '传输中' }
const newKinds = { hosts: 'host', clusters: 'cluster', mesh: 'mesh', certificates: 'certificate' }
const createLabel = computed(() => ({ hosts: '添加主机', clusters: '添加集群', mesh: '添加 Mesh 组', certificates: '添加证书' }[tab.value]))
const createBlockedReason = computed(() => {
  if (loading.value.configuration) return '正在读取主机配置'
  if (errors.value.configuration) return '请先重新读取主机配置'
  if (tab.value === 'clusters' && data.value.hosts.length < 2) return '先添加至少两台原生主机，再配置主备'
  if (['mesh', 'certificates'].includes(tab.value) && !data.value.hosts.length) return '请先添加原生主机'
  if (['mesh', 'certificates'].includes(tab.value) && !credentials.value.length) return '请先添加 Cloudflare 凭据'
  return ''
})
const hostOptions = computed(() => data.value.hosts.map(host => ({ label: host.name, value: host.id })))
const credentialOptions = computed(() => credentials.value.map(item => ({ label: item.name + (item.enabled ? '' : '（未启用）'), value: item.id })))
const selectedHost = computed(() => data.value.hosts.find(host => host.id === form.value.host_id))
const selectedBackup = computed(() => backups.value.find(item => item.id === form.value.backup_id))
const availableTargets = computed(() => data.value.hosts.filter(host => host.id !== selectedBackup.value?.host_id))
let disposed = false
let pollTimer
const requests = {}
const activitySequence = createSequence()
const mutationSequence = createSequence()

// 切换操作上下文后，尚未确认的操作不能继续提交；已发出的请求不取消。
watch([tab, dialog, hostFilter], () => mutationSequence.invalidate(), { flush: 'sync' })
watch(dialog, cancelTaskDetail, { flush: 'sync' })
watch([tab, hostFilter], () => {
  if (!taskDetailTarget.value) return
  cancelTaskDetail()
  if (dialog.value === 'detail') dialog.value = ''
}, { flush: 'sync' })

function hostName(id) { return data.value.hosts.find(host => host.id === id)?.name || `主机 ${id}` }
function credentialName(id) { return credentials.value.find(item => item.id === id)?.name || '未绑定' }
function date(value) {
  if (!value) return '尚未上报'
  const parsed = new Date(typeof value === 'number' || /^\d+$/.test(value) ? Number(value) * 1000 : value)
  return Number.isNaN(parsed.getTime()) ? '未知' : parsed.toLocaleString()
}
function bytes(value) { return Number(value) < 1048576 ? `${(Number(value) / 1024).toFixed(1)} KB` : `${(Number(value) / 1048576).toFixed(1)} MB` }
function stateType(state) { return state === 'success' || state === 'ready' ? 'success' : ['failed', 'unknown'].includes(state) ? 'danger' : 'info' }
function allows(host, feature) { return Boolean(host?.enabled && host?.features?.[feature]) }
function parseArray(value, label) {
  const parsed = JSON.parse(value || '[]')
  if (!Array.isArray(parsed)) throw new Error(`${label}必须是 JSON 数组`)
  return parsed
}

async function read(key, operation, apply) {
  if (disposed) return
  requests[key]?.abort()
  const controller = new AbortController()
  requests[key] = controller
  loading.value[key] = true
  errors.value[key] = ''
  try {
    const result = await operation(controller.signal)
    if (!disposed && requests[key] === controller && !controller.signal.aborted) apply(result)
  } catch (error) {
    if (!disposed && requests[key] === controller && !controller.signal.aborted) errors.value[key] = error.message
  } finally {
    if (!disposed && requests[key] === controller) {
      loading.value[key] = false
      delete requests[key]
    }
  }
}

async function loadConfiguration() {
  await read('configuration', signal => readInfrastructure('fetch', {}, { signal }), result => { data.value = result })
}
async function loadCredentials() {
  await read('credentials', signal => fetchCloudflareCredentials({ signal }), result => { credentials.value = result })
}
async function loadBackups() {
  await read('backups', signal => readInfrastructure('backups', {}, { signal }), result => { backups.value = result })
}
async function loadActivity() {
  if (disposed || tab.value !== 'activity') return
  const token = activitySequence.next()
  clearTimeout(pollTimer)
  await Promise.all([
    read('tasks', signal => readInfrastructure('tasks', { host_id: hostFilter.value, summary: 1 }, { signal }), result => { tasks.value = result }),
    read('events', signal => readInfrastructure('events', { summary: 1 }, { signal }), result => { events.value = result }),
  ])
  if (disposed || !activitySequence.isCurrent(token)) return
  schedulePoll()
}
function schedulePoll() {
  clearTimeout(pollTimer)
  if (disposed || tab.value !== 'activity' || !tasks.value.some(task => ['queued', 'dispatched'].includes(task.state))) return
  pollTimer = setTimeout(() => {
    if (document.visibilityState === 'visible') loadActivity()
    else schedulePoll()
  }, 5000)
}
async function refresh() {
  await Promise.all([
    loadConfiguration(),
    tab.value === 'backups' ? loadBackups() : null,
    tab.value === 'activity' ? loadActivity() : null,
  ])
}
watch(tab, value => {
  clearTimeout(pollTimer)
  if (value !== 'activity') {
    activitySequence.invalidate()
    requests.tasks?.abort()
    requests.events?.abort()
  }
  if (value === 'backups') loadBackups()
  if (value === 'activity') loadActivity()
})
watch(hostFilter, () => { if (tab.value === 'activity') loadActivity() })

async function mutate(operation, message = '配置已保存', after = refresh) {
  if (disposed || busy.value) return
  const token = mutationSequence.next()
  const isCurrent = () => !disposed && mutationSequence.isCurrent(token)
  busy.value = true
  formError.value = ''
  try {
    const result = await operation(isCurrent)
    if (!isCurrent()) return
    if (message) ElMessage.success(message)
    await after(result)
  } catch (error) {
    if (isCurrent() && error !== 'cancel' && error !== 'close') {
      formError.value = error.message || String(error)
      ElMessage.error(formError.value)
    }
  } finally { if (!disposed) busy.value = false }
}

function open(kind, item) {
  if (disposed || busy.value) return
  if (!item && kind !== 'host' && createBlockedReason.value) { ElMessage.info(createBlockedReason.value); return }
  formError.value = ''
  const common = { id: item?.id || null, revision: item?.revision, enabled: Boolean(item?.enabled) }
  if (kind === 'host') {
    form.value = { ...common, machine_id: item?.machine_id || null, name: item?.name || '',
      features: Object.fromEntries(Object.keys(featureLabels).map(key => [key, Boolean(item?.features?.[key])])),
      settings: { site_root: item?.settings?.site_root || '/srv/panelcore', domain: item?.settings?.domain || '' } }
    read('machines', signal => fetchManagedMachines({ signal, optionsOnly: true }), result => { machines.value = result })
  } else if (kind === 'certificate') {
    form.value = { ...common, host_id: item?.host_id || null, credential_id: item?.credential_id || null,
      zone_id: item?.zone_id || '', domain: item?.domain || '', email: item?.email || '', staging: item?.staging ?? true }
  } else if (kind === 'mesh') {
    form.value = { ...common, name: item?.name || '', credential_id: item?.credential_id || null,
      host_ids: [...(item?.settings?.host_ids || [])], pairs_json: JSON.stringify(item?.settings?.allowed_pairs || [], null, 2) }
  } else if (kind === 'cluster') {
    form.value = { ...common, name: item?.name || '', primary_host_id: item?.primary_host_id || null, standby_host_id: item?.standby_host_id || null,
      credential_id: item?.credential_id || null, sync_enabled: Boolean(item?.sync_enabled), failover_enabled: Boolean(item?.failover_enabled), split_enabled: Boolean(item?.split_enabled),
      settings: { zone_id: '', arbiter_url: '', primary_origin: '', standby_origin: '', primary_address: '', standby_address: '', primary_mesh_ip: '', standby_mesh_ip: '',
        max_replica_lag: 5, failure_threshold: 3, max_file_age: 300, check_interval: 30, ...item?.settings },
      dns_json: JSON.stringify(item?.settings?.dns_records || [], null, 2),
      secrets: { primary_node_token: '', standby_node_token: '', replication_password: '', notify_url: '' },
      has_primary_token: item?.has_primary_token, has_standby_token: item?.has_standby_token }
  }
  dialog.value = kind
}

async function save() {
  let payload
  const kind = dialog.value
  const draft = JSON.parse(JSON.stringify(form.value))
  try {
    const common = { id: draft.id, revision: draft.revision, enabled: draft.enabled }
    if (kind === 'host') payload = { ...common, machine_id: draft.machine_id, name: draft.name.trim(), features: draft.features, settings: draft.settings }
    if (kind === 'certificate') payload = { ...common, host_id: draft.host_id, credential_id: draft.credential_id, zone_id: draft.zone_id.trim(), domain: draft.domain.trim(), email: draft.email.trim(), staging: draft.staging }
    if (kind === 'mesh') payload = { ...common, name: draft.name.trim(), credential_id: draft.credential_id, settings: { host_ids: draft.host_ids, allowed_pairs: parseArray(draft.pairs_json, '访问规则') } }
    if (kind === 'cluster') {
      payload = { ...common, name: draft.name.trim(), primary_host_id: draft.primary_host_id, standby_host_id: draft.standby_host_id, credential_id: draft.credential_id,
        sync_enabled: draft.sync_enabled, failover_enabled: draft.failover_enabled, split_enabled: draft.split_enabled,
        settings: { ...draft.settings, dns_records: parseArray(draft.dns_json, 'DNS 记录') },
        secrets: Object.fromEntries(Object.entries(draft.secrets).filter(([, value]) => value.trim()).map(([key, value]) => [key, value.trim()])) }
    }
  } catch (error) { formError.value = error.message; return }
  await mutate(async isCurrent => {
    if (payload.enabled) await ElMessageBox.confirm('保存将允许对应主机执行所选功能；主机本地 root 配置也必须单独授权。是否保存？', '启用管理配置', { type: 'warning', confirmButtonText: '保存' })
    if (!isCurrent()) return
    const action = { host: 'saveHost', certificate: 'saveCertificate', mesh: 'saveMesh', cluster: 'saveCluster' }[kind]
    return mutateInfrastructure(action, payload)
  }, '配置已保存', async () => {
    dialog.value = ''
    form.value = {}
    await refresh()
  })
}

async function confirmName(name, title, explanation) {
  const result = await ElMessageBox.prompt(explanation + `\n请输入“${name}”确认。`, title, { type: 'warning', confirmButtonText: '执行', inputValidator: value => value === name || '名称不匹配' })
  return result.value
}

function openServices(host) {
  if (disposed || busy.value) return
  form.value = { host_id: host.id, service: 'nginx', lines: 100, action: 'status', kind: 'migration' }
  formError.value = ''
  dialog.value = 'services'
}
async function serviceAction(action) {
  const host = selectedHost.value
  if (!host) return
  const name = host.name
  const payload = { host_id: host.id, action: `service.${action}`, service: form.value.service, lines: form.value.lines }
  await mutate(async isCurrent => {
    if (['stop', 'restart'].includes(action)) payload.confirm_name = await confirmName(name, '修改服务状态', `${serviceLabels[payload.service]} 将${action === 'stop' ? '停止' : '重启'}，可能中断此主机的服务。`)
    if (!isCurrent()) return
    await mutateInfrastructure('createTask', payload)
  }, '任务已排队', async () => { dialog.value = ''; tab.value = 'activity'; await loadActivity() })
}
async function scheduleAction(enabled) {
  const host = selectedHost.value
  if (!host) return
  const name = host.name
  const payload = { host_id: host.id, action: 'schedule.save', schedule_enabled: enabled }
  await mutate(async isCurrent => {
    await ElMessageBox.confirm(`将${enabled ? '启用' : '停用'} ${name} 的应用定时任务。主备模式只应由当前写入主机执行。`, '定时任务', { type: 'warning' })
    if (!isCurrent()) return
    await mutateInfrastructure('createTask', payload)
  }, '任务已排队', async () => { dialog.value = ''; tab.value = 'activity'; await loadActivity() })
}
function openBackup(host) {
  if (disposed || busy.value) return
  form.value = { host_id: host?.id || null, kind: 'migration' }
  formError.value = ''
  dialog.value = 'backup'
}
async function createBackup() {
  await mutate(() => mutateInfrastructure('createTask', { host_id: form.value.host_id, action: 'backup.create', kind: form.value.kind }), '备份任务已排队', async () => { dialog.value = ''; tab.value = 'activity'; await loadActivity() })
}
function openRestore(backup, deploy) {
  if (disposed || busy.value) return
  form.value = { backup_id: backup.id, host_id: null, overwrite: false, deploy }
  formError.value = ''
  dialog.value = 'restore'
}
function openMigration(backup) {
  if (disposed || busy.value) return
  form.value = { backup_id: backup.id, mode: 'package',
    domain: data.value.hosts.find(host => host.id === backup.host_id)?.settings?.domain || '',
    site_root: '/srv/panelcore', database_name: 'panelcore', database_user: 'panelcore' }
  migrationOutput.value = { command: '', token: '', expires_at: null, password: '' }
  formError.value = ''
  dialog.value = 'migration'
}
function migrationPayload() {
  return Object.fromEntries(['backup_id', 'site_root', 'domain', 'database_name', 'database_user'].map(key => [key, form.value[key].trim()]))
}
async function migrate() {
  const mode = form.value.mode
  await mutate(async () => {
    const payload = migrationPayload()
    if (mode === 'package') {
      const controller = new AbortController()
      requests.migrationDownload = controller
      downloadingMigration.value = true
      try {
        await downloadMigrationPackage(payload, { signal: controller.signal })
        return null
      } finally {
        if (requests.migrationDownload === controller) delete requests.migrationDownload
        downloadingMigration.value = false
      }
    }
    return mutateInfrastructure('migrationCommand', payload)
  }, mode === 'package' ? '迁移数据包已下载' : '', async result => {
    if (mode === 'command') migrationOutput.value = { ...migrationOutput.value, ...result }
  })
}
async function revealMigrationPassword() {
  await mutate(() => mutateInfrastructure('migrationPassword', { backup_id: form.value.backup_id }), '', result => { migrationOutput.value.password = result.password })
}
async function copyMigrationCommand() {
  try { await copyText(migrationOutput.value.command); ElMessage.success('安装命令已复制') }
  catch (error) { ElMessage.error(error.message || '复制失败') }
}
watch(() => [form.value.mode, form.value.site_root, form.value.domain, form.value.database_name, form.value.database_user], () => {
  migrationOutput.value.command = ''
  migrationOutput.value.token = ''
  migrationOutput.value.expires_at = null
})
async function restoreBackup() {
  const host = selectedHost.value
  if (!host) { formError.value = '请选择目标主机'; return }
  const { name, settings: { site_root } } = host
  const deploy = form.value.deploy
  const payload = { host_id: host.id, action: deploy ? 'backup.deploy' : 'backup.restore', backup_id: form.value.backup_id, overwrite: form.value.overwrite }
  await mutate(async isCurrent => {
    payload.confirm_name = await confirmName(name, deploy ? '备份部署' : '恢复备份', `完整备份将恢复到 ${site_root}。恢复后保持备用模式，不启动业务队列和定时任务。${payload.overwrite ? '现有站点与目标数据库可能被覆盖。' : ''}`)
    if (!isCurrent()) return
    await mutateInfrastructure('createTask', payload)
  }, '恢复任务已排队', async () => { dialog.value = ''; tab.value = 'activity'; await loadActivity() })
}
async function certificateAction(certificate, action) {
  const payload = { host_id: certificate.host_id, action: `certificate.${action}`, certificate_id: certificate.id }
  const explanation = `在 ${hostName(payload.host_id)} ${action === 'issue' ? '申请并部署' : '检查续期'} ${certificate.domain} 的${certificate.staging ? '测试' : '正式'}证书。`
  await mutate(async isCurrent => {
    if (action !== 'check') await ElMessageBox.confirm(explanation, '证书管理', { type: 'warning' })
    if (!isCurrent()) return
    await mutateInfrastructure('createTask', payload)
  }, '证书任务已排队', async () => { tab.value = 'activity'; await loadActivity() })
}
async function clusterAction(cluster, action, role = 'primary') {
  const hostId = role === 'standby' ? cluster.standby_host_id : cluster.primary_host_id
  const name = hostName(hostId)
  const replicaName = hostName(cluster.standby_host_id)
  const payload = { host_id: hostId, action: `cluster.${action}`, cluster_id: cluster.id }
  await mutate(async isCurrent => {
    payload.confirm_name = await confirmName(name, '主备操作', { configure: '写入主备配置；主机初始化还会同步应用文件并配置数据库与 Redis 复制。', sync: '同步当前应用文件及共享配置。', split: '两台应用将共用主机数据库和 Redis，并按所选 DNS 记录分流。', failover: '确认旧主机停写并取得仲裁授权后，提升备用数据库、启动业务任务并更新 DNS。' }[action])
    if (!isCurrent()) return
    if (action === 'configure' && role === 'primary') {
      payload.confirm_replica_name = await confirmName(replicaName, '覆盖备用数据库', '初始化复制会用当前主机快照覆盖此备用主机的数据库，并同步站点文件。')
      if (!isCurrent()) return
    }
    await mutateInfrastructure('createTask', payload)
  }, '主备任务已排队', async () => { tab.value = 'activity'; await loadActivity() })
}
async function inspectMesh(group) {
  await mutate(() => readInfrastructure('meshInspect', { mesh_id: group.id }), '', result => { showDetail('Mesh 账户检查', result) })
}
function openEnrollment(group) {
  if (disposed || busy.value) return
  form.value = { mesh_id: group.id, host_id: null, host_ids: group.settings.host_ids }
  formError.value = ''
  dialog.value = 'enroll'
}
async function enrollMesh() {
  const payload = { mesh_id: form.value.mesh_id, host_id: form.value.host_id }
  await mutate(async isCurrent => {
    await ElMessageBox.confirm('将创建 Cloudflare Mesh 节点并让目标主机接入。已有 WARP 注册不会被覆盖，接入前默认拒绝 Mesh 流量。', '接入 Mesh', { type: 'warning' })
    if (!isCurrent()) return
    return mutateInfrastructure('meshEnroll', payload)
  }, '接入任务已排队', async () => { dialog.value = ''; tab.value = 'activity'; await loadActivity() })
}
async function applyMesh(group) {
  const name = group.name
  const payload = { mesh_id: group.id }
  await mutate(async isCurrent => {
    payload.confirm_name = await confirmName(name, '应用访问规则', '先收紧本机规则，再发布 Cloudflare Gateway 规则。收紧任务完成后需再次应用，才会放行所选机器对与端口。')
    if (!isCurrent()) return
    return mutateInfrastructure('meshApply', payload)
  }, '', async result => { ElMessage.success(result.message || 'Cloudflare 规则已发布，本机放行任务已排队'); await loadConfiguration() })
}
function cancelTaskDetail() {
  requests.taskDetail?.abort()
  delete requests.taskDetail
  loading.value.taskDetail = false
  errors.value.taskDetail = ''
  taskDetailTarget.value = null
}
async function openTaskDetail(task) {
  if (disposed || busy.value || tab.value !== 'activity') return
  showDetail('任务结果', '', { task_id: task.id, host_id: hostFilter.value })
  await loadTaskDetail()
}
async function loadTaskDetail() {
  if (disposed || dialog.value !== 'detail' || !taskDetailTarget.value) return
  const payload = { ...taskDetailTarget.value }
  await read('taskDetail', signal => readInfrastructure('taskDetail', payload, { signal }), result => { showDetail('任务结果', result, payload) })
}
function taskResultMessage(task) { return task.result_message ?? task.result?.message ?? '等待 Agent 上报' }
function showDetail(title, value, taskTarget = null) {
  if (disposed) return
  cancelTaskDetail()
  detailTitle.value = title
  detail.value = typeof value === 'string' ? value : JSON.stringify(value, null, 2)
  dialog.value = 'detail'
  taskDetailTarget.value = taskTarget
}
async function bootstrap(host) {
  const payload = { host_id: host.id }
  await mutate(async isCurrent => {
    const result = await ElMessageBox.prompt('生成包含此机器 API Token 的 root 配置。请输入面板 HTTPS 根地址。', '生成 Agent 配置', { inputValue: window.location.origin.startsWith('https://') ? window.location.origin : '', inputValidator: value => /^https:\/\/[^/?#]+\/?$/.test(value || '') || '请输入 HTTPS 根地址' })
    if (!isCurrent()) return
    return mutateInfrastructure('bootstrap', { ...payload, panel_url: result.value })
  }, '', result => { showDetail('Agent 配置（包含密钥）', result) })
}
async function resolveTask(task) {
  const payload = { task_id: task.id, confirmed_stopped: true }
  await mutate(async isCurrent => {
    const result = await ElMessageBox.prompt('确认目标主机不再执行该任务后，填写处理依据。此操作不会撤销已发生的主机变更。', '处理结果不明任务', { type: 'warning', inputValidator: value => (value || '').trim().length >= 5 || '至少填写 5 个字符' })
    if (!isCurrent()) return
    await mutateInfrastructure('resolveTask', { ...payload, reason: result.value.trim() })
  }, '任务已标记为取消', loadActivity)
}
async function drop(kind, item) {
  const name = item.name || item.domain || item.filename
  const payload = { kind, id: item.id, revision: item.revision }
  await mutate(async isCurrent => {
    await ElMessageBox.confirm(`删除${name}？${kind === 'backup' ? '加密备份文件也会删除。' : '已被引用或已有远程资源的配置不能删除。'}`, '删除配置', { type: 'warning', confirmButtonText: '删除' })
    if (!isCurrent()) return
    await mutateInfrastructure('drop', payload)
  }, '已删除')
}
onMounted(() => Promise.all([loadConfiguration(), loadCredentials()]))
onUnmounted(() => { disposed = true; mutationSequence.invalidate(); cancelTaskDetail(); clearTimeout(pollTimer); Object.values(requests).forEach(controller => controller.abort()); form.value = {}; detail.value = ''; migrationOutput.value = {} })
</script>

<template>
  <div class="infrastructure-page">
    <SectionCard header-actions>
      <template #actions>
        <router-link :to="{ name: 'cloudflare' }" class="infra-row-actions">Cloudflare 凭据</router-link>
        <el-button :disabled="busy" @click="refresh"><RefreshCw :size="15" />刷新</el-button>
        <el-button v-if="createLabel" type="primary" :disabled="busy || Boolean(createBlockedReason)" :title="createBlockedReason" @click="open(newKinds[tab])"><Plus :size="15" />{{ createLabel }}</el-button>
        <el-button v-if="tab === 'backups'" type="primary" :disabled="busy" @click="openBackup()"><Plus :size="15" />创建备份</el-button>
      </template>
      <div v-if="!loading.configuration && !errors.configuration && (!data.hosts.length || createBlockedReason)" class="infra-setup">
        <strong>{{ createBlockedReason || '先连接原生主机，再启用运维功能' }}</strong>
        <p>添加绑定机器，由独立可信安装器在主机上接入 Agent；确认上报后，再配置凭据、备份或主备。面板不持有 root SSH 密钥。</p>
        <div class="infra-row-actions"><el-button :disabled="busy" @click="tab = 'hosts'; open('host')">添加原生主机</el-button><router-link :to="{ name: 'cloudflare' }">配置 Cloudflare 凭据</router-link></div>
      </div>
      <el-tabs v-model="tab">
        <el-tab-pane label="原生主机" name="hosts" />
        <el-tab-pane label="备份与部署" name="backups" />
        <el-tab-pane label="主备与分流" name="clusters" />
        <el-tab-pane label="Mesh 内网" name="mesh" />
        <el-tab-pane label="TLS 证书" name="certificates" />
        <el-tab-pane label="任务与事件" name="activity" />
      </el-tabs>
      <el-alert v-if="errors.configuration" :title="errors.configuration" type="error" :closable="false" class="infra-alert" />
      <el-alert v-if="errors.credentials" :title="errors.credentials" type="error" :closable="false" class="infra-alert" />
      <template v-if="tab === 'hosts'">
        <el-table v-loading="loading.configuration" :data="data.hosts" row-key="id" empty-text="添加原生主机后，可管理服务、备份与主备配置">
          <el-table-column prop="name" label="主机" min-width="140" />
          <el-table-column label="站点" min-width="230"><template #default="{ row }">{{ row.settings.domain }}<small>{{ row.settings.site_root }}</small></template></el-table-column>
          <el-table-column label="管理状态" width="110"><template #default="{ row }"><el-tag :type="row.enabled ? 'success' : 'info'">{{ row.enabled ? '已启用' : '未启用' }}</el-tag></template></el-table-column>
          <el-table-column label="上报" min-width="190"><template #default="{ row }">{{ date(row.reported_at) }}<small v-if="row.status?.agent_version">Agent {{ row.status.agent_version }} / {{ row.status.cpu_count || '?' }} vCPU</small></template></el-table-column>
          <el-table-column label="操作" min-width="285"><template #default="{ row }"><div class="infra-row-actions"><el-button link :disabled="busy || !allows(row, 'services')" @click="openServices(row)">服务</el-button><el-button link :disabled="busy || !allows(row, 'backups')" @click="openBackup(row)">备份</el-button><el-button link :disabled="busy" @click="showDetail(row.name, row.status || {})">状态</el-button><el-button link :disabled="busy" @click="bootstrap(row)">Agent 配置</el-button><el-button link :disabled="busy" @click="open('host', row)">编辑</el-button><el-button link type="danger" :disabled="busy || row.enabled" @click="drop('host', row)">删除</el-button></div></template></el-table-column>
        </el-table>
      </template>
      <template v-else-if="tab === 'backups'">
        <el-alert v-if="errors.backups" :title="errors.backups" type="error" :closable="false" class="infra-alert" />
        <el-table v-loading="loading.backups" :data="backups" row-key="id" empty-text="暂无加密备份">
          <el-table-column prop="filename" label="备份" min-width="320" show-overflow-tooltip />
          <el-table-column label="来源" min-width="130"><template #default="{ row }">{{ hostName(row.host_id) }}</template></el-table-column>
          <el-table-column label="类型" width="110"><template #default="{ row }">{{ row.kind === 'migration' ? '完整迁移' : '数据库' }}</template></el-table-column>
          <el-table-column label="状态" width="110"><template #default="{ row }"><el-tag :type="stateType(row.state)">{{ stateLabels[row.state] || row.state }}</el-tag></template></el-table-column>
          <el-table-column label="大小" width="110"><template #default="{ row }">{{ bytes(row.bytes) }}</template></el-table-column>
          <el-table-column label="时间" min-width="175"><template #default="{ row }">{{ date(row.created_at) }}</template></el-table-column>
          <el-table-column label="操作" min-width="245"><template #default="{ row }"><div class="infra-row-actions"><el-button link :disabled="busy || row.state !== 'ready'" @click="openRestore(row, false)">恢复</el-button><el-button link :disabled="busy || row.state !== 'ready' || row.kind !== 'migration'" @click="openRestore(row, true)">已有主机部署</el-button><el-button link :disabled="busy || row.state !== 'ready' || row.kind !== 'migration'" @click="openMigration(row)">迁移到新机</el-button><el-button link type="danger" :disabled="busy || row.state === 'uploading'" @click="drop('backup', row)">删除</el-button></div></template></el-table-column>
        </el-table>
      </template>
      <template v-else-if="tab === 'clusters'">
        <el-table v-loading="loading.configuration" :data="data.clusters" row-key="id" empty-text="配置主备主机、复制与 DNS 分流">
          <el-table-column prop="name" label="集群" min-width="140" />
          <el-table-column label="主备主机" min-width="185"><template #default="{ row }">{{ hostName(row.primary_host_id) }}<small>备用：{{ hostName(row.standby_host_id) }}</small></template></el-table-column>
          <el-table-column label="功能" min-width="230"><template #default="{ row }"><div class="infra-tags"><el-tag :type="row.enabled ? 'success' : 'info'">{{ row.enabled ? '已启用' : '未启用' }}</el-tag><el-tag v-if="row.sync_enabled">同步</el-tag><el-tag v-if="row.failover_enabled">故障转移</el-tag><el-tag v-if="row.split_enabled">共享分流</el-tag></div></template></el-table-column>
          <el-table-column label="操作" min-width="420"><template #default="{ row }"><div class="infra-row-actions"><el-button link :disabled="busy || !row.enabled" @click="clusterAction(row, 'configure', 'standby')">配置备用机</el-button><el-button link :disabled="busy || !row.enabled || !row.sync_enabled" @click="clusterAction(row, 'configure')">初始化复制</el-button><el-button link :disabled="busy || !row.enabled || !row.sync_enabled" @click="clusterAction(row, 'sync')">同步</el-button><el-button link :disabled="busy || !row.enabled || !row.split_enabled" @click="clusterAction(row, 'split')">分流</el-button><el-button link type="danger" :disabled="busy || !row.enabled || !row.failover_enabled" @click="clusterAction(row, 'failover', 'standby')">接管</el-button><el-button link :disabled="busy" @click="showDetail(row.name, row.status || {})">状态</el-button><el-button link :disabled="busy" @click="open('cluster', row)">编辑</el-button><el-button link type="danger" :disabled="busy || row.enabled" @click="drop('cluster', row)">删除</el-button></div></template></el-table-column>
        </el-table>
      </template>
      <template v-else-if="tab === 'mesh'">
        <el-table v-loading="loading.configuration" :data="data.mesh_groups" row-key="id" empty-text="创建机器访问组，按源机器、目标机器与端口放行">
          <el-table-column prop="name" label="访问组" min-width="140" />
          <el-table-column label="机器" min-width="200"><template #default="{ row }">{{ row.settings.host_ids.map(hostName).join('、') }}</template></el-table-column>
          <el-table-column label="管理状态" width="110"><template #default="{ row }"><el-tag :type="row.enabled ? 'success' : 'info'">{{ row.enabled ? '已启用' : '未启用' }}</el-tag></template></el-table-column>
          <el-table-column label="规则状态" min-width="180"><template #default="{ row }">{{ row.status?.policy ? (row.status.policy.revision === row.revision ? '已发布，查看本机任务' : '配置已变更，待重新应用') : '未发布' }}</template></el-table-column>
          <el-table-column label="操作" min-width="330"><template #default="{ row }"><el-button link :disabled="busy" @click="inspectMesh(row)">账户检查</el-button><el-button link :disabled="busy || !row.enabled" @click="openEnrollment(row)">接入机器</el-button><el-button link :disabled="busy || !row.enabled" @click="applyMesh(row)">应用规则</el-button><el-button link :disabled="busy" @click="showDetail(row.name, row.status || {})">状态</el-button><el-button link :disabled="busy" @click="open('mesh', row)">编辑</el-button><el-button link type="danger" :disabled="busy || row.enabled || row.status?.nodes || row.status?.policy" @click="drop('mesh', row)">删除</el-button></template></el-table-column>
        </el-table>
      </template>
      <template v-else-if="tab === 'certificates'">
        <el-table v-loading="loading.configuration" :data="data.certificates" row-key="id" empty-text="添加主机证书并绑定共享 Cloudflare 凭据">
          <el-table-column prop="domain" label="域名" min-width="190" />
          <el-table-column label="主机" min-width="140"><template #default="{ row }">{{ hostName(row.host_id) }}</template></el-table-column>
          <el-table-column label="凭据" min-width="150"><template #default="{ row }">{{ credentialName(row.credential_id) }}</template></el-table-column>
          <el-table-column label="状态" min-width="170"><template #default="{ row }"><el-tag :type="row.enabled ? 'success' : 'info'">{{ row.enabled ? '已启用' : '未启用' }}</el-tag><small>{{ row.staging ? '测试证书' : '正式证书' }}</small></template></el-table-column>
          <el-table-column label="有效期" min-width="180"><template #default="{ row }">{{ row.status?.expires_at ? date(row.status.expires_at) : '尚未检查' }}</template></el-table-column>
          <el-table-column label="操作" min-width="240"><template #default="{ row }"><el-button link :disabled="busy || !row.enabled" @click="certificateAction(row, 'check')">检查</el-button><el-button link :disabled="busy || !row.enabled" @click="certificateAction(row, 'issue')">申请</el-button><el-button link :disabled="busy || !row.enabled" @click="certificateAction(row, 'renew')">续期</el-button><el-button link :disabled="busy" @click="open('certificate', row)">编辑</el-button><el-button link type="danger" :disabled="busy || row.enabled" @click="drop('certificate', row)">删除</el-button></template></el-table-column>
        </el-table>
      </template>
      <template v-else>
        <div class="infra-filter"><el-select v-model="hostFilter" clearable placeholder="所有主机" aria-label="任务主机"><el-option v-for="host in hostOptions" :key="host.value" :label="host.label" :value="host.value" /></el-select></div>
        <el-alert v-if="errors.tasks" :title="errors.tasks" type="error" :closable="false" class="infra-alert" />
        <el-table v-loading="loading.tasks" :data="tasks" row-key="id" empty-text="暂无任务">
          <el-table-column label="主机" min-width="140"><template #default="{ row }">{{ hostName(row.host_id) }}</template></el-table-column>
          <el-table-column prop="action" label="任务" min-width="180" />
          <el-table-column label="状态" width="110"><template #default="{ row }"><el-tag :type="stateType(row.state)">{{ stateLabels[row.state] || row.state }}</el-tag></template></el-table-column>
          <el-table-column label="结果" min-width="270"><template #default="{ row }">{{ taskResultMessage(row) }}</template></el-table-column>
          <el-table-column label="时间" min-width="175"><template #default="{ row }">{{ date(row.created_at) }}</template></el-table-column>
          <el-table-column label="操作" width="150"><template #default="{ row }"><el-button link :disabled="busy" @click="openTaskDetail(row)">详情</el-button><el-button v-if="['queued', 'unknown'].includes(row.state)" link type="danger" :disabled="busy" @click="resolveTask(row)">处理</el-button></template></el-table-column>
        </el-table>
        <h3 class="infra-subtitle">事件</h3>
        <el-alert v-if="errors.events" :title="errors.events" type="error" :closable="false" class="infra-alert" />
        <el-table v-loading="loading.events" :data="events" row-key="id" empty-text="暂无事件">
          <el-table-column label="时间" min-width="175"><template #default="{ row }">{{ date(row.created_at) }}</template></el-table-column>
          <el-table-column prop="kind" label="类型" min-width="180" />
          <el-table-column label="主机" min-width="130"><template #default="{ row }">{{ row.host_id ? hostName(row.host_id) : '系统' }}</template></el-table-column>
          <el-table-column prop="message" label="内容" min-width="340" />
        </el-table>
      </template>
    </SectionCard>

    <el-dialog :model-value="Boolean(dialog)" :title="{ host: form.id ? '编辑主机' : '添加主机', cluster: form.id ? '编辑主备集群' : '添加主备集群', mesh: form.id ? '编辑 Mesh 访问组' : '添加 Mesh 访问组', certificate: form.id ? '编辑证书' : '添加证书', services: '原生服务', backup: '创建加密备份', restore: form.deploy ? '从备份部署' : '恢复备份', migration: '迁移到新机', enroll: '接入 Mesh', detail: detailTitle }[dialog]"
      width="740px" class="infra-dialog" :close-on-click-modal="!busy" :close-on-press-escape="!busy" :show-close="!busy" destroy-on-close @update:model-value="value => { if (!value && !busy) dialog = '' }" @closed="() => { if (!dialog) { form = {}; detail = ''; migrationOutput = { command: '', token: '', expires_at: null, password: '' } } }">
      <el-form v-if="['host', 'cluster', 'mesh', 'certificate'].includes(dialog)" label-position="top" :disabled="busy" @submit.prevent="save">
        <template v-if="dialog === 'host'">
          <div class="infra-grid"><el-form-item label="主机名称" required><el-input v-model="form.name" maxlength="100" /></el-form-item><el-form-item label="绑定机器" required><el-select v-model="form.machine_id" :disabled="Boolean(form.id)" :loading="loading.machines" filterable><el-option v-for="machine in machines" :key="machine.id" :label="machine.name || `机器 ${machine.id}`" :value="machine.id" /></el-select></el-form-item></div>
          <el-alert v-if="errors.machines" :title="errors.machines" type="error" :closable="false" class="infra-alert" />
          <div class="infra-grid"><el-form-item label="站点根目录" required><el-input v-model="form.settings.site_root" /></el-form-item><el-form-item label="主机域名" required><el-input v-model="form.settings.domain" /></el-form-item></div>
          <el-form-item label="功能授权"><div class="infra-feature-list"><label v-for="(label, key) in featureLabels" :key="key"><span>{{ label }}</span><el-switch v-model="form.features[key]" /></label></div></el-form-item>
          <el-form-item label="启用主机管理"><el-switch v-model="form.enabled" /></el-form-item>
        </template>
        <template v-else-if="dialog === 'certificate'">
          <div class="infra-grid"><el-form-item label="主机" required><el-select v-model="form.host_id"><el-option v-for="host in hostOptions" :key="host.value" :label="host.label" :value="host.value" /></el-select></el-form-item><el-form-item label="Cloudflare 凭据" required><el-select v-model="form.credential_id"><el-option v-for="item in credentialOptions" :key="item.value" :label="item.label" :value="item.value" /></el-select></el-form-item></div>
          <el-form-item label="Zone ID" required><el-input v-model="form.zone_id" maxlength="32" /></el-form-item>
          <div class="infra-grid"><el-form-item label="证书域名" required><el-input v-model="form.domain" /></el-form-item><el-form-item label="续期联系邮箱" required><el-input v-model="form.email" type="email" /></el-form-item></div>
          <div class="infra-switches"><el-form-item label="使用 ACME 测试环境"><el-switch v-model="form.staging" /></el-form-item><el-form-item label="启用证书管理"><el-switch v-model="form.enabled" /></el-form-item></div>
        </template>
        <template v-else-if="dialog === 'mesh'">
          <div class="infra-grid"><el-form-item label="访问组名称" required><el-input v-model="form.name" /></el-form-item><el-form-item label="账户级 Cloudflare 凭据" required><el-select v-model="form.credential_id"><el-option v-for="item in credentialOptions" :key="item.value" :label="item.label" :value="item.value" /></el-select></el-form-item></div>
          <el-form-item label="组内主机" required><el-select v-model="form.host_ids" multiple filterable><el-option v-for="host in hostOptions" :key="host.value" :label="`${host.label}（ID ${host.value}）`" :value="host.value" /></el-select></el-form-item>
          <el-form-item label="允许的机器对与端口"><InfrastructureRulesEditor v-model="form.pairs_json" kind="mesh" :hosts="hostOptions.filter(host => form.host_ids.includes(host.value))" /></el-form-item>
          <el-form-item label="启用 Mesh 管理"><el-switch v-model="form.enabled" /></el-form-item>
        </template>
        <template v-else>
          <el-form-item label="集群名称" required><el-input v-model="form.name" /></el-form-item>
          <div class="infra-grid"><el-form-item label="主机" required><el-select v-model="form.primary_host_id"><el-option v-for="host in hostOptions" :key="host.value" :label="host.label" :value="host.value" /></el-select></el-form-item><el-form-item label="备用机" required><el-select v-model="form.standby_host_id"><el-option v-for="host in hostOptions" :key="host.value" :disabled="host.value === form.primary_host_id" :label="host.label" :value="host.value" /></el-select></el-form-item></div>
          <div class="infra-grid"><el-form-item label="主机 Mesh IP"><el-input v-model="form.settings.primary_mesh_ip" /></el-form-item><el-form-item label="备用机 Mesh IP"><el-input v-model="form.settings.standby_mesh_ip" /></el-form-item></div>
          <div class="infra-switches"><el-form-item label="启用集群管理"><el-switch v-model="form.enabled" /></el-form-item><el-form-item label="配置与文件同步"><el-switch v-model="form.sync_enabled" /></el-form-item><el-form-item label="独立故障检测与接管"><el-switch v-model="form.failover_enabled" /></el-form-item><el-form-item label="双机共享状态分流"><el-switch v-model="form.split_enabled" /></el-form-item></div>
          <el-collapse>
            <el-collapse-item title="故障检测与仲裁" name="health">
              <el-form-item label="独立仲裁 HTTPS 地址"><el-input v-model="form.settings.arbiter_url" placeholder="https://panelcore-failover-arbiter.example.workers.dev" /></el-form-item>
              <div class="infra-grid"><el-form-item label="主机 HTTPS 检查地址"><el-input v-model="form.settings.primary_origin" /></el-form-item><el-form-item label="备用机 HTTPS 检查地址"><el-input v-model="form.settings.standby_origin" /></el-form-item><el-form-item label="主机公网 IP"><el-input v-model="form.settings.primary_address" /></el-form-item><el-form-item label="备用机公网 IP"><el-input v-model="form.settings.standby_address" /></el-form-item></div>
              <div class="infra-grid"><el-form-item label="主机仲裁 Token（留空保留）"><el-input v-model="form.secrets.primary_node_token" type="password" autocomplete="new-password" :placeholder="form.has_primary_token ? '已配置' : ''" /></el-form-item><el-form-item label="备用机仲裁 Token（留空保留）"><el-input v-model="form.secrets.standby_node_token" type="password" autocomplete="new-password" :placeholder="form.has_standby_token ? '已配置' : ''" /></el-form-item></div>
              <div class="infra-grid"><el-form-item label="检查间隔（秒）"><el-input-number v-model="form.settings.check_interval" :min="10" :max="30" /></el-form-item><el-form-item label="连续失败次数"><el-input-number v-model="form.settings.failure_threshold" :min="3" :max="30" /></el-form-item><el-form-item label="最大复制延迟（秒）"><el-input-number v-model="form.settings.max_replica_lag" :min="0" :max="300" /></el-form-item><el-form-item label="文件同步最大年龄（秒）"><el-input-number v-model="form.settings.max_file_age" :min="60" :max="86400" /></el-form-item></div>
            </el-collapse-item>
            <el-collapse-item title="Cloudflare DNS 分流与切换" name="dns">
              <el-form-item label="Cloudflare 凭据"><el-select v-model="form.credential_id" clearable><el-option v-for="item in credentialOptions" :key="item.value" :label="item.label" :value="item.value" /></el-select><router-link :to="{ name: 'cloudflare' }">管理 CF Token</router-link></el-form-item>
              <el-form-item label="Zone ID"><el-input v-model="form.settings.zone_id" maxlength="32" /></el-form-item>
              <el-form-item label="现有 DNS 记录"><InfrastructureRulesEditor v-model="form.dns_json" kind="dns" /></el-form-item>
            </el-collapse-item>
            <el-collapse-item title="复制与独立通知" name="secrets">
              <el-form-item label="复制密码（留空保留或自动生成）"><el-input v-model="form.secrets.replication_password" type="password" autocomplete="new-password" /></el-form-item>
              <el-form-item label="独立通知 HTTPS Webhook（留空保留）"><el-input v-model="form.secrets.notify_url" type="password" autocomplete="new-password" /></el-form-item>
            </el-collapse-item>
          </el-collapse>
        </template>
      </el-form>
      <el-form v-else-if="dialog === 'services'" label-position="top" :disabled="busy">
        <el-alert title="默认只读。启停服务和修改调度需要目标机 root 单独设置限时授权，面板不能创建此授权。" type="info" :closable="false" class="infra-alert" />
        <el-button class="infra-alert" @click="serviceAction('inspect')">读取主机诊断（资源、磁盘、Supervisor、调度）</el-button>
        <el-form-item label="服务"><el-select v-model="form.service"><el-option v-for="(label, key) in serviceLabels" :key="key" :label="label" :value="key" /></el-select></el-form-item>
        <el-form-item label="日志行数"><el-input-number v-model="form.lines" :min="20" :max="300" /></el-form-item>
        <div class="infra-service-actions"><el-button @click="serviceAction('status')">读取状态</el-button><el-button @click="serviceAction('logs')">读取日志</el-button><el-button @click="serviceAction('start')">启动</el-button><el-button @click="serviceAction('restart')">重启</el-button><el-button type="danger" @click="serviceAction('stop')">停止</el-button></div>
        <el-divider />
        <el-form-item label="应用定时任务"><el-button @click="scheduleAction(true)">启用</el-button><el-button @click="scheduleAction(false)">停用</el-button></el-form-item>
      </el-form>
      <el-form v-else-if="dialog === 'backup'" label-position="top" :disabled="busy">
        <el-form-item label="来源主机" required><el-select v-model="form.host_id"><el-option v-for="host in data.hosts" :key="host.id" :disabled="!allows(host, 'backups')" :label="host.name" :value="host.id" /></el-select></el-form-item>
        <el-form-item label="备份范围"><el-radio-group v-model="form.kind"><el-radio value="migration">完整迁移（站点、数据库、配置）</el-radio><el-radio value="database">仅数据库</el-radio></el-radio-group></el-form-item>
      </el-form>
      <el-form v-else-if="dialog === 'restore'" label-position="top" :disabled="busy">
        <el-form-item label="备份"><el-input :model-value="selectedBackup?.filename" readonly /></el-form-item>
        <el-form-item label="目标主机" required><el-select v-model="form.host_id"><el-option v-for="host in availableTargets" :key="host.id" :disabled="!allows(host, form.deploy ? 'deploy' : 'restores')" :label="host.name" :value="host.id" /></el-select></el-form-item>
        <el-form-item :label="selectedBackup?.kind === 'database' ? '允许覆盖目标数据库' : '允许覆盖现有站点'"><el-switch v-model="form.overwrite" /></el-form-item>
      </el-form>
      <el-form v-else-if="dialog === 'migration'" label-position="top" :disabled="busy" @submit.prevent="migrate">
        <el-form-item label="安装方式"><el-radio-group v-model="form.mode"><el-radio value="package">导出迁移数据</el-radio><el-radio value="command">可信安装器下载</el-radio></el-radio-group></el-form-item>
        <el-alert title="安装为待切换站点，不改 DNS、不启动业务队列和调度。目标已有站点或同名数据库时停止。" type="info" :closable="false" class="infra-alert" />
        <el-collapse><el-collapse-item title="目标站点配置" name="destination">
          <div class="infra-grid"><el-form-item label="域名" required><el-input v-model="form.domain" /></el-form-item><el-form-item label="网站目录" required><el-input v-model="form.site_root" /></el-form-item><el-form-item label="数据库名称" required><el-input v-model="form.database_name" /></el-form-item><el-form-item label="数据库用户" required><el-input v-model="form.database_user" /></el-form-item></div>
        </el-collapse-item></el-collapse>
        <template v-if="form.mode === 'package'">
          <p>数据包不含可执行安装脚本。新机需先从独立可信来源安装 <code>panelcore-install</code>，再运行 <code>panelcore-install --package /root/migration-data.tar.gz</code>。</p>
          <p class="infra-download-note">面板一次仅处理一个迁移下载。大备份建议选择“可信安装器下载”，避免浏览器缓存整个文件。</p>
        </template>
        <p v-if="downloadingMigration" class="infra-download-note" role="status">正在打包并下载；离开此页面会取消本次下载。</p>
        <template v-if="form.mode === 'command' && migrationOutput.command">
          <p>在已安装可信安装器的新机运行以下命令，按提示输入下载授权和解密密码。下载授权有效期至 {{ date(migrationOutput.expires_at) }}。</p>
          <el-input :model-value="migrationOutput.command" type="textarea" :rows="5" readonly aria-label="可信安装器命令" />
          <el-button class="infra-alert" @click="copyMigrationCommand">复制安装命令</el-button>
          <el-input :model-value="migrationOutput.token" type="password" show-password readonly aria-label="临时下载授权" class="infra-alert" />
        </template>
        <el-button :loading="busy" @click="revealMigrationPassword">获取解密密码</el-button>
        <el-input v-if="migrationOutput.password" :model-value="migrationOutput.password" type="password" show-password readonly aria-label="迁移包解密密码" class="infra-alert" />
      </el-form>
      <el-form v-else-if="dialog === 'enroll'" label-position="top" :disabled="busy">
        <el-form-item label="目标主机" required><el-select v-model="form.host_id"><el-option v-for="id in form.host_ids" :key="id" :label="hostName(id)" :value="id" /></el-select></el-form-item>
      </el-form>
      <template v-else-if="dialog === 'detail'">
        <p v-if="loading.taskDetail" role="status">正在读取任务详情…</p>
        <el-alert v-if="errors.taskDetail" :title="errors.taskDetail" type="error" :closable="false" class="infra-alert" />
        <el-button v-if="errors.taskDetail" :loading="loading.taskDetail" @click="loadTaskDetail">重试</el-button>
        <pre v-if="detail" class="infra-detail">{{ detail }}</pre>
      </template>
      <el-alert v-if="formError" :title="formError" type="error" :closable="false" class="infra-alert" />
      <template #footer>
        <el-button :disabled="busy" @click="dialog = ''">{{ dialog === 'detail' || dialog === 'services' ? '关闭' : '取消' }}</el-button>
        <el-button v-if="['host', 'cluster', 'mesh', 'certificate'].includes(dialog)" type="primary" :loading="busy" @click="save">保存</el-button>
        <el-button v-if="dialog === 'backup'" type="primary" :loading="busy" :disabled="!allows(selectedHost, 'backups')" @click="createBackup">创建备份</el-button>
        <el-button v-if="dialog === 'restore'" type="primary" :loading="busy" :disabled="!selectedHost" @click="restoreBackup">{{ form.deploy ? '部署到目标主机' : '恢复到目标主机' }}</el-button>
        <el-button v-if="dialog === 'migration'" type="primary" :loading="busy" @click="migrate">{{ { package: '下载迁移数据', command: '生成下载授权' }[form.mode] }}</el-button>
        <el-button v-if="dialog === 'enroll'" type="primary" :loading="busy" :disabled="!selectedHost || !allows(selectedHost, 'mesh')" @click="enrollMesh">接入</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.infra-setup { padding: 16px; margin-bottom: 18px; background: var(--el-fill-color-light); border: 1px solid var(--line); border-radius: 12px; }
.infra-setup p { color: var(--muted); font-size: 13px; line-height: 1.7; margin: 8px 0 12px; }

.infrastructure-page { min-width: 0; }
.infra-alert { margin: 12px 0; }
.infra-download-note { color: var(--muted); line-height: 1.7; overflow-wrap: anywhere; }
.infra-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 0 18px; }
.infra-grid .el-select, .infra-grid .el-input-number, .infra-dialog .el-select { width: 100%; }
.infra-feature-list { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 24px; width: 100%; }
.infra-feature-list label { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.infra-switches { display: flex; flex-wrap: wrap; gap: 0 30px; }
.infra-row-actions, .infra-tags, .infra-service-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.infra-row-actions .el-button + .el-button, .infra-service-actions .el-button + .el-button { margin-left: 0; }
.el-table small { display: block; margin-top: 4px; color: var(--el-text-color-secondary); }
.infra-filter { display: flex; justify-content: flex-end; margin: 4px 0 18px; }
.infra-filter .el-select { width: 200px; }
.infra-subtitle { font-size: 16px; margin: 28px 0 16px; }
.infra-detail { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 12px; line-height: 1.7; margin: 0; }
:global(.infra-dialog) { max-width: calc(100vw - 24px); display: flex; flex-direction: column; max-height: calc(100dvh - 32px); margin-top: 16px; }
:global(.infra-dialog .el-dialog__body) { overflow-y: auto; min-height: 0; }
:global(.infra-dialog .el-dialog__footer) { flex-shrink: 0; }
@media (max-width: 600px) { .infra-grid, .infra-feature-list { grid-template-columns: minmax(0, 1fr); } .infra-switches { gap: 0 22px; } }
</style>
