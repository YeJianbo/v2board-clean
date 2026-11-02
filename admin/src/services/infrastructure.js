import { buildCommonApiUrl, getDashboardApiHeaders, requestDashboardApi, requestDashboardMutation } from './api'
import { signalAuthExpired } from './auth'
import { readDashboardResponse } from './dashboardResponse'

const reads = { fetch: 'fetch', tasks: 'tasks', taskDetail: 'tasks', backups: 'backups', events: 'events', meshInspect: 'mesh/inspect' }
const mutations = {
  saveHost: 'host/save', bootstrap: 'host/bootstrap', saveCertificate: 'certificate/save',
  saveCluster: 'cluster/save', saveMesh: 'mesh/save', createTask: 'task/create',
  resolveTask: 'task/resolve', drop: 'drop', meshEnroll: 'mesh/enroll', meshApply: 'mesh/apply',
  migrationCommand: 'migration/command', migrationPassword: 'migration/password',
}

function endpoint(path, params = {}) {
  return buildCommonApiUrl(`admin/infrastructure/${path}`, Object.entries(params).filter(([, value]) => value !== null && value !== undefined && value !== ''))
}

export async function readInfrastructure(action, params = {}, options = {}) {
  if (!reads[action]) throw new Error('无效的基础设施读取操作')
  const reply = await requestDashboardApi(endpoint(reads[action], params), options)
  if (!reply || reply.data === undefined || reply.data === null) throw new Error('基础设施响应无效')
  if (['tasks', 'backups', 'events'].includes(action) && !Array.isArray(reply.data)) throw new Error('基础设施列表响应无效')
  if (action === 'taskDetail' && (typeof reply.data !== 'object' || Array.isArray(reply.data) || reply.data.id !== params.task_id)) {
    throw new Error('任务详情响应无效')
  }
  if (action === 'fetch' && ['hosts', 'clusters', 'certificates', 'mesh_groups'].some(key => !Array.isArray(reply.data[key]))) throw new Error('基础设施配置响应无效')
  return reply.data
}

export async function mutateInfrastructure(action, payload) {
  if (!mutations[action]) throw new Error('无效的基础设施写入操作')
  const reply = await requestDashboardMutation(endpoint(mutations[action]), payload)
  if (reply?.data === undefined) throw new Error('基础设施操作响应无效')
  return reply.data
}

export async function downloadMigrationPackage(payload, { signal } = {}) {
  const headers = getDashboardApiHeaders()
  const response = await fetch(endpoint('migration/package', payload), { headers, signal, cache: 'no-store' })
  signal?.throwIfAborted()
  if (!response.ok) {
    await readDashboardResponse(response, () => signalAuthExpired(`migration-download:${response.status}`, headers['X-Admin-CSRF']))
  }
  const contentType = response.headers.get('content-type') || ''
  if (/json/i.test(contentType)) {
    const reply = await response.json().catch(() => null)
    throw new Error(reply?.message || '迁移包下载失败：接口未返回数据包')
  }
  if (/html/i.test(contentType)) throw new Error('迁移包下载失败：服务器返回了网页，请重试')
  const blob = await response.blob()
  signal?.throwIfAborted()
  if (!blob.size) throw new Error('迁移包下载失败：文件为空')
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  try {
    anchor.href = url
    anchor.download = `panelcore-migration-${payload.backup_id}.tar.gz`
    document.body.appendChild(anchor)
    anchor.click()
  } finally {
    anchor.remove()
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  }
}
