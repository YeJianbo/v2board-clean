import {
  buildSecureV2ApiUrl,
  getDashboardApiHeaders,
  requestDashboardApi,
  requestDashboardReadPost,
  requestDashboardMutation,
} from './api'
import { signalAuthExpired } from './auth'
import { readDashboardResponse } from './dashboardResponse'

export function createEmptyManagedUsers() {
  return []
}

export function createEmptyManagedUsersPagination() {
  return {
    page: 1,
    pageSize: 10,
    total: 0,
  }
}

export function createEmptyManagedUsersFilters() {
  return {
    filter: [],
    sort: [],
  }
}

function formatTimestamp(value) {
  const timestamp = Number(value || 0)

  if (!timestamp) {
    return '--'
  }

  const date = new Date(timestamp * 1000)

  if (Number.isNaN(date.getTime())) {
    return '--'
  }

  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')

  return `${year}-${month}-${day} ${hours}:${minutes}`
}

function formatBytes(value) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  let size = Number(value || 0)
  let unitIndex = 0

  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024
    unitIndex += 1
  }

  return `${size.toFixed(2)} ${units[unitIndex]}`
}

function formatRate(value) {
  const raw = String(value ?? '').trim()
  if (!raw) return '1x'
  const numeric = Number(raw)
  if (!Number.isFinite(numeric)) return raw.endsWith('x') ? raw : `${raw}x`
  return `${Number.isInteger(numeric) ? numeric.toFixed(0) : numeric.toFixed(2).replace(/0+$/, '').replace(/\.$/, '')}x`
}

function formatProtocolLabel(value) {
  const raw = String(value || '--').trim()
  const key = raw.toLowerCase()
  const labels = {
    anytls: 'AnyTLS',
    hysteria: 'Hysteria',
    hysteria2: 'Hysteria2',
    shadowrocket: 'Shadowrocket',
    shadowsocks: 'Shadowsocks',
    ss: 'SS',
    trojan: 'Trojan',
    tuic: 'TUIC',
    vless: 'VLESS',
    vmess: 'VMess',
    v2node: 'V2Node',
  }

  return labels[key] || raw
}

function normalizeUser(user) {
  const isBanned = Number(user?.banned ?? 0) === 1
  const planName = user?.plan?.name || '--'
  const groupName = user?.group?.name || '--'
  const inviteEmail = user?.invite_user?.email || '--'
  const totalUsed = Number(user?.u || 0) + Number(user?.d || 0)
  const transferEnable = Number(user?.transfer_enable || 0)
  const remainTraffic = Math.max(transferEnable - totalUsed, 0)

  return {
    id: Number(user?.id || 0),
    email: String(user?.email || '--'),
    planId: user?.plan_id || null,
    planName,
    groupId: user?.group_id || null,
    groupName,
    inviteUserId: user?.invite_user_id || null,
    inviteUserEmail: inviteEmail,
    // 余额/佣金统一以「元」处理：user/fetch 由后端 transformUserData 已 ÷100 返回「元」；
    // user/getUserInfoById 返回原始「分」，故在该接口 wrapper 里单独 ÷100（见下方 getUserInfoById）。
    // 此处 normalizeUser 一律不再除，避免对列表口径重复除导致 100 倍偏小。
    balance: Number(user?.balance || 0).toFixed(2),
    commissionBalance: Number(user?.commission_balance || 0).toFixed(2),
    transferEnable: formatBytes(transferEnable),
    transferEnableRaw: transferEnable,
    totalUsed: formatBytes(totalUsed),
    totalUsedRaw: totalUsed,
    remainTraffic: formatBytes(remainTraffic),
    upload: formatBytes(user?.u),
    uploadRaw: Number(user?.u || 0),
    download: formatBytes(user?.d),
    downloadRaw: Number(user?.d || 0),
    speedLimit: user?.speed_limit || null,
    deviceLimit: user?.device_limit || null,
    onlineCount: Number(user?.online_count ?? user?.alive_ip ?? 0),
    expiredAt: user?.expired_at == null ? '长期有效' : Number(user.expired_at) === 0 ? '已到期' : formatTimestamp(user.expired_at),
    expiredAtRaw: user?.expired_at ?? null,
    nextResetAt: formatTimestamp(user?.next_reset_at),
    nextResetAtRaw: user?.next_reset_at || null,
    lastResetAt: formatTimestamp(user?.last_reset_at),
    lastResetAtRaw: user?.last_reset_at || null,
    createdAt: formatTimestamp(user?.created_at),
    lastLoginAt: formatTimestamp(user?.last_login_at),
    isBanned,
    banned: isBanned ? 1 : 0,
    statusText: isBanned ? '已封禁' : '正常',
    statusType: isBanned ? 'danger' : 'success',
    uuid: user?.uuid || '--',
    token: user?.token || '',
    subscribeUrl: user?.subscribe_url || '',
    remindExpire: Number(user?.remind_expire ?? 0) === 1,
    remindTraffic: Number(user?.remind_traffic ?? 0) === 1,
    commissionRate: user?.commission_rate ?? null,
    commissionType: user?.commission_type ?? 0,
    discount: user?.discount ?? null,
    isAdmin: Number(user?.is_admin ?? 0) === 1,
    isStaff: Number(user?.is_staff ?? 0) === 1,
    remarks: user?.remarks || '',
  }
}

export async function fetchManagedUsers(options = {}) {
  const current = Number(options.page || 1)
  const pageSize = Number(options.pageSize || 10)
  const queryEntries = [
    ['current', current],
    ['pageSize', pageSize],
  ]

  const apiUrl = buildSecureV2ApiUrl('user/fetch', queryEntries)
  const body = {}

  if (Array.isArray(options.filter) && options.filter.length > 0) {
    body.filter = options.filter
  }

  if (Array.isArray(options.sort) && options.sort.length > 0) {
    body.sort = options.sort
  }

  const payload = await requestDashboardReadPost(apiUrl, body, { signal: options.signal })
  const rawData = payload?.data ?? {}
  const listSource = Array.isArray(rawData?.data) ? rawData.data : (Array.isArray(rawData) ? rawData : [])

  // Xboard API can return pagination at different nesting levels。
  // 用 ?? 而不是 ||：当列表为空（total: 0）时不要把它当成 missing 而错当后备值。
  const total = Number(
    rawData?.total
    ?? payload?.data?.total
    ?? payload?.total
    ?? 0
  )
  const currentPage = Number(
    rawData?.current_page
    ?? payload?.data?.current_page
    ?? payload?.current_page
    ?? current
  )
  const perPage = Number(
    rawData?.per_page
    ?? payload?.data?.per_page
    ?? payload?.per_page
    ?? pageSize
  )


  return {
    list: listSource.map(function mapUser(user) {
      return normalizeUser(user)
    }),
    pagination: {
      page: currentPage,
      pageSize: perPage,
      total: total,
    },
  }
}

export async function getUserInfoById(id, options = {}) {
  const userId = Number(id)
  if (!Number.isSafeInteger(userId) || userId < 1 || userId > 2147483647) throw new Error('用户 ID 无效')
  const apiUrl = buildSecureV2ApiUrl('user/getUserInfoById', [['id', userId]])
  const payload = await requestDashboardApi(apiUrl, options)
  const user = payload?.data
  if (!user || typeof user !== 'object' || Array.isArray(user) || Number(user.id) !== userId || typeof user.email !== 'string') {
    throw new Error('用户详情返回格式错误，请重新加载')
  }
  // 与 user/fetch 不同：后端 getUserInfoById 直接返回原始模型，balance/commission_balance
  // 仍是「分」，没像列表那样 ÷100。这里补偿成「元」，使其与列表口径一致后再交给 normalizeUser。
  return normalizeUser({
    ...user,
    balance: user.balance == null ? user.balance : Number(user.balance) / 100,
    commission_balance: user.commission_balance == null ? user.commission_balance : Number(user.commission_balance) / 100,
  })
}

export async function generateManagedUserLoginUrl(id) {
  const userId = Number(id)
  if (!Number.isSafeInteger(userId) || userId < 1 || userId > 2147483647) throw new Error('用户 ID 无效')
  const payload = await requestDashboardMutation(buildSecureV2ApiUrl('user/getQuickLoginUrl'), { id: userId })
  const value = payload?.data
  let url
  try { url = typeof value === 'string' ? new URL(value) : null } catch { /* Invalid URL is rejected below. */ }
  if (!url || !['http:', 'https:'].includes(url.protocol) || !url.hash.startsWith('#/login?')
    || !new URLSearchParams(url.hash.slice(8)).get('verify')) {
    throw new Error('登录链接返回格式错误，请重新生成')
  }
  return value
}

export async function updateManagedUser(userData) {
  const apiUrl = buildSecureV2ApiUrl('user/update')
  return requestDashboardMutation(apiUrl, userData)
}

export async function generateManagedUser(data) {
  const apiUrl = buildSecureV2ApiUrl('user/generate')
  return requestDashboardMutation(apiUrl, data)
}

export async function fetchUserGenerationStatus(options = {}) {
  const entries = options.batchId ? [['batch_id', options.batchId]] : []
  const payload = await requestDashboardApi(buildSecureV2ApiUrl('user/generation-status', entries), { signal: options.signal })
  return payload?.data ?? null
}

export async function downloadGeneratedUsers(batchId, options = {}) {
  const headers = getDashboardApiHeaders()
  const response = await fetch(buildSecureV2ApiUrl('user/generation-download', [['batch_id', batchId]]), { headers, signal: options.signal })
  return downloadUsersResponse(response, headers, options)
}

export async function banManagedUsers(data) {
  const apiUrl = buildSecureV2ApiUrl('user/ban')
  return requestDashboardMutation(apiUrl, data)
}

export async function resetManagedUserSecret(id, mode = 'grace') {
  const apiUrl = buildSecureV2ApiUrl('user/resetSecret')
  return requestDashboardMutation(apiUrl, { id: Number(id), mode })
}

export async function sendMailToUsers(data) {
  const apiUrl = buildSecureV2ApiUrl('user/sendMail')
  return requestDashboardMutation(apiUrl, data)
}

export async function dumpUsersCSV(data, options = {}) {
  const apiUrl = buildSecureV2ApiUrl('user/dumpCSV')
  // Successful exports are binary; errors use the shared session and permission handling.
  const headers = {
    ...getDashboardApiHeaders(),
    'Content-Type': 'application/json',
  }
  const response = await fetch(apiUrl, {
    method: 'POST',
    headers,
    body: JSON.stringify(data),
    signal: options.signal,
  })

  return downloadUsersResponse(response, headers, options)
}

async function downloadUsersResponse(response, headers, options = {}) {
  options.signal?.throwIfAborted()
  if (!response.ok) {
    await readDashboardResponse(response, () => signalAuthExpired(`csv:${response.status}`, headers['X-Admin-CSRF']))
  }

  const contentType = response.headers.get('content-type') || ''
  if (/json/i.test(contentType)) {
    const error = await response.json().catch(() => null)
    throw new Error(error?.message || '导出失败：接口未返回 CSV 文件')
  }
  if (/html/i.test(contentType)) {
    throw new Error('导出失败：服务器返回了网页，请重试')
  }

  const blob = await response.blob()
  options.signal?.throwIfAborted()
  if (!blob.size) throw new Error('导出失败：文件为空')
  const a = document.createElement('a')
  const url = window.URL.createObjectURL(blob)
  let appended = false
  try {
    a.href = url
    a.download = `users_${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(a)
    appended = true
    a.click()
  } finally {
    try {
      if (appended) document.body.removeChild(a)
    } finally {
      window.URL.revokeObjectURL(url)
    }
  }
}

export async function destroyManagedUser(id) {
  const apiUrl = buildSecureV2ApiUrl('user/destroy')
  return requestDashboardMutation(apiUrl, { id: Number(id) })
}

export async function setInviteUser(id, inviteUserEmail) {
  const apiUrl = buildSecureV2ApiUrl('user/setInviteUser')
  return requestDashboardMutation(apiUrl, {
    id: Number(id),
    invite_user_email: inviteUserEmail,
  })
}

export async function fetchUserTrafficStats(userId, options = {}) {
  const current = Number(options.page || 1)
  const pageSize = Number(options.pageSize || 10)
  const queryEntries = [
    ['user_id', userId],
    ['current', current],
    ['pageSize', pageSize],
    ['start_time', options.startTime || ''],
    ['end_time', options.endTime || ''],
  ]
  const apiUrl = buildSecureV2ApiUrl('stat/getStatUser', queryEntries)
  const payload = await requestDashboardApi(apiUrl, { signal: options.signal })
  if (!Array.isArray(payload?.data) || payload.data.some(item => !item || typeof item !== 'object' || Array.isArray(item))) {
    throw new Error('用户流量记录返回格式错误，请重试')
  }
  const list = payload.data
  const total = Number(payload?.total || 0)

  return {
    list: list.map(item => {
      const u = Number(item.u || 0)
      const d = Number(item.d || 0)
      
      const formatBytes = (val) => {
        const units = ["B", "KB", "MB", "GB", "TB", "PB"]
        let size = Number(val || 0)
        let index = 0
        while (size >= 1024 && index < units.length - 1) {
          size /= 1024
          index++
        }
        return `${size.toFixed(2)} ${units[index]}`
      }

      const date = new Date((item.record_at || 0) * 1000)
      const dateStr = date.getTime() ? `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}` : '--'

      return {
        id: item.id,
        recordAt: item.record_at,
        date: dateStr,
        serverRate: item.server_rate ?? '1.0',
        serverRateText: formatRate(item.server_rate ?? '1.0'),
        u: u,
        d: d,
        total: u + d,
        uText: formatBytes(u),
        dText: formatBytes(d),
        totalText: formatBytes(u + d)
      }
    }),
    pagination: {
      page: current,
      pageSize,
      total,
    }
  }
}

export async function fetchUserNodeTrafficStats(userId, options = {}) {
  const queryEntries = [
    ['user_id', userId],
    ['start_time', options.startTime || ''],
    ['end_time', options.endTime || ''],
  ]
  const apiUrl = buildSecureV2ApiUrl('stat/getUserNodeTraffic', queryEntries)
  const payload = await requestDashboardApi(apiUrl, { signal: options.signal })
  if (!Array.isArray(payload?.data) || payload.data.some(item => !item || typeof item !== 'object' || Array.isArray(item))) {
    throw new Error('用户节点流量返回格式错误，请重试')
  }
  const list = payload.data
  const meta = payload?.meta || {}

  return {
    list: list.map(item => {
      const u = Number(item.u || 0)
      const d = Number(item.d || 0)
      const total = Number(item.total || u + d)
      const serverType = String(item.server_type || '').trim().toLowerCase()
      const rawType = String(item.type || serverType || '--').toLowerCase()
      const nodeKey = String(item.key || `${serverType || rawType}:${Number(item.id || 0)}`)

      return {
        id: item.id,
        key: nodeKey,
        serverType,
        rawType,
        type: formatProtocolLabel(item.type),
        name: item.name || `Node ${item.id}`,
        rate: item.rate ?? '1.0',
        rateText: formatRate(item.rate ?? '1.0'),
        u,
        d,
        total,
        cost: Number(item.cost ?? total),
        uText: formatBytes(u),
        dText: formatBytes(d),
        totalText: formatBytes(Number(item.cost ?? total)),
        actualTotalText: formatBytes(total),
      }
    }),
    meta: {
      startAt: meta.start_at || null,
      endAt: meta.end_at || null,
      userTotal: Number(meta.user_total || 0),
      userTotalText: formatBytes(meta.user_total || 0),
      note: meta.note || '',
    },
  }
}

export async function fetchUserNodeTrafficSeriesStats(userId, options = {}) {
  const apiUrl = new URL(buildSecureV2ApiUrl('stat/getUserNodeTrafficSeries'))
  apiUrl.searchParams.set('user_id', String(Number(userId || 0)))
  apiUrl.searchParams.set('granularity', String(options.granularity || 'day'))

  if (options.startTime) {
    apiUrl.searchParams.set('start_time', String(options.startTime))
  }
  if (options.endTime) {
    apiUrl.searchParams.set('end_time', String(options.endTime))
  }

  const nodeKeys = Array.isArray(options.nodeKeys) ? options.nodeKeys : []
  nodeKeys.forEach(function appendNodeKey(nodeKey) {
    if (nodeKey && nodeKey !== 'ALL') {
      apiUrl.searchParams.append('node_keys[]', String(nodeKey))
    }
  })

  apiUrl.searchParams.set('include_total', options.includeTotal === false ? '0' : '1')

  const payload = await requestDashboardApi(apiUrl.toString(), { signal: options.signal })
  const data = payload?.data || {}
  const labels = Array.isArray(data?.labels) ? data.labels : []
  const rawSeries = Array.isArray(data?.series) ? data.series : []
  const meta = payload?.meta || {}

  return {
    labels: labels.map(function mapLabel(item, index) {
      if (item && typeof item === 'object') {
        return {
          timestamp: Number(item.timestamp || index),
          label: String(item.label || '--'),
        }
      }

      return {
        timestamp: index,
        label: String(item || '--'),
      }
    }),
    series: rawSeries.map(function mapSeries(item) {
      const points = Array.isArray(item?.points) ? item.points : []
      const total = Number(item?.total || 0)
      const cost = Number(item?.cost ?? total)

      return {
        key: String(item?.key || ''),
        id: Number(item?.id || 0),
        type: formatProtocolLabel(item?.type),
        name: String(item?.name || '--'),
        total,
        cost,
        totalText: formatBytes(cost),
        actualTotalText: formatBytes(total),
        points: points.map(function mapPoint(point) {
          const u = Number(point?.u || 0)
          const d = Number(point?.d || 0)
          const pointTotal = Number(point?.total || (u + d))
          const pointCost = Number(point?.cost ?? pointTotal)
          return {
            timestamp: Number(point?.timestamp || 0),
            label: String(point?.label || '--'),
            u,
            d,
            total: pointTotal,
            cost: pointCost,
            uText: formatBytes(u),
            dText: formatBytes(d),
            totalText: formatBytes(pointCost),
            actualTotalText: formatBytes(pointTotal),
          }
        }),
      }
    }),
    meta: {
      granularity: String(meta?.granularity || options.granularity || 'day'),
      startAt: meta?.start_at || null,
      endAt: meta?.end_at || null,
      note: String(meta?.note || ''),
    },
  }
}
