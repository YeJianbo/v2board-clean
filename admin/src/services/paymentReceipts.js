import { buildDashboardApiUrl, getDashboardApiHeaders, requestDashboardApi } from './api'
import { readStoredAuth, signalAuthExpired } from './auth'
import { readDashboardResponse } from './dashboardResponse'
import { withReadDeadline } from '../utils/readRequestDeadline'

function tradeContext(tradeNo) {
  if (typeof tradeNo !== 'string' || tradeNo.length === 0) throw new Error('缺少订单号，请重新打开订单详情')
  return tradeNo
}

function receiptId(value) {
  if ((typeof value === 'number' && Number.isSafeInteger(value) && value > 0)
    || (typeof value === 'string' && /^[1-9]\d*$/.test(value))) return String(value)
  throw new Error('支付回调原件编号无效，请重新加载')
}

function integer(value, minimum = 0) {
  return (typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value)))
    && Number.isSafeInteger(Number(value)) && Number(value) >= minimum
}

function sessionIdentity() {
  const session = readStoredAuth()
  if (!session?.sessionKey || !session.csrfToken) throw new Error('登录已过期，请重新登录')
  return { key: session.sessionKey, csrf: session.csrfToken }
}

function assertCurrentSession(identity, signal) {
  signal?.throwIfAborted()
  const current = readStoredAuth()
  if (current?.sessionKey !== identity.key || current?.csrfToken !== identity.csrf) {
    throw new DOMException('管理会话已变更', 'AbortError')
  }
}

export async function fetchPaymentReceipts(tradeNo, { page = 1, pageSize = 10, signal } = {}) {
  tradeContext(tradeNo)
  if (!integer(page, 1) || !integer(pageSize, 1)) throw new Error('原件分页参数无效')
  const identity = sessionIdentity()
  const payload = await requestDashboardApi(buildDashboardApiUrl('payment-receipt/fetch', [
    ['trade_no', tradeNo], ['current', page], ['pageSize', pageSize],
  ]), { signal })
  assertCurrentSession(identity, signal)
  if (!Array.isArray(payload?.data) || !integer(payload.total) || !integer(payload.current_page, 1)
    || !integer(payload.per_page, 1) || !integer(payload.last_page, 1)) {
    throw new Error('支付回调原件列表格式错误，请重新加载')
  }
  const list = payload.data.map(row => {
    if (!row || row.trade_no !== tradeNo || (row.callback_no !== null && typeof row.callback_no !== 'string')
      || !integer(row.body_bytes) || !integer(row.first_seen_at) || !integer(row.last_seen_at) || !integer(row.seen_count, 1)) {
      throw new Error('支付回调原件记录格式错误，请重新加载')
    }
    return {
      id: receiptId(row.id),
      callbackNo: row.callback_no ?? (typeof row.callback_no_base64 === 'string' ? `Base64: ${row.callback_no_base64}` : null),
      bodyBytes: Number(row.body_bytes),
      firstSeenAt: Number(row.first_seen_at), lastSeenAt: Number(row.last_seen_at), seenCount: Number(row.seen_count),
    }
  })
  return { list, page: Number(payload.current_page), pageSize: Number(payload.per_page), total: Number(payload.total) }
}

export async function downloadPaymentReceipt(id, tradeNo, { signal } = {}) {
  id = receiptId(id)
  tradeContext(tradeNo)
  const identity = sessionIdentity()
  const headers = getDashboardApiHeaders()
  const blob = await withReadDeadline(async requestSignal => {
    const response = await fetch(buildDashboardApiUrl('payment-receipt/download', [['id', id], ['trade_no', tradeNo]]), {
      headers, signal: requestSignal, cache: 'no-store', redirect: 'error',
    })
    assertCurrentSession(identity, requestSignal)
    const onExpired = () => signalAuthExpired(`payment-receipt:${response.status}`, headers['X-Admin-CSRF'])
    if (!response.ok) await readDashboardResponse(response, onExpired)
    const json = /^application\/(?:json|[\w.+-]+\+json)(?:\s*;|$)/i.test(response.headers.get('content-type') || '')
    const attachment = /^\s*attachment(?:\s*;|\s*$)/i.test(response.headers.get('content-disposition') || '')
    if (!json || !attachment) {
      if (json) await readDashboardResponse(response, onExpired)
      throw new Error('下载失败：接口未返回支付回调原件附件，请重试')
    }
    const result = await response.blob()
    assertCurrentSession(identity, requestSignal)
    if (!result.size) throw new Error('下载失败：原件文件为空，请重试')
    return result
  }, 60000, signal)
  assertCurrentSession(identity, signal)
  const url = URL.createObjectURL(blob)
  let anchor
  try {
    anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `receipt-${id}.json`
    document.body.appendChild(anchor)
    assertCurrentSession(identity, signal)
    anchor.click()
  } finally {
    try { anchor?.remove() } finally { URL.revokeObjectURL(url) }
  }
}
