import {
  buildDashboardApiUrl,
  getDashboardApiHeaders,
  requestDashboardApi,
  requestDashboardMutation,
} from './api'
import { signalAuthExpired } from './auth'
import { readDashboardResponse } from './dashboardResponse'

export function giftCardBalanceCents(value) {
  const amount = Number(value)
  const cents = Math.round(amount * 100)
  if (!['number', 'string'].includes(typeof value) || String(value).trim() === '' || !Number.isFinite(amount) || amount <= 0 || !Number.isSafeInteger(cents) || cents < 1 || cents > 2147483647) {
    throw new Error('充值金额须为 0.01-21474836.47 元')
  }
  return cents
}

function normalizeTemplate(raw) {
  return {
    id: raw.id,
    name: raw.name || '',
    planId: raw.plan_id ?? null,
    planName: raw.plan_name || '',
    period: raw.period || '',
    price: raw.price ?? 0,
    codesCount: raw.codes_count ?? 0,
    usedCount: raw.used_count ?? 0,
    createdAt: raw.created_at ?? null,
  }
}

function normalizeCode(raw) {
  const states = {
    available: ['可用', 'success'], used: ['已用尽', 'info'],
    disabled: ['已禁用', 'info'], expired: ['已过期', 'warning'], not_started: ['未生效', 'warning'],
  }
  const reason = raw.availability_reason || (Number(raw.status) === 0 ? 'available' : 'unavailable')
  const [statusText, statusType] = states[reason] || ['不可用', 'info']
  return {
    id: raw.id,
    templateId: raw.template_id,
    code: raw.code || '',
    status: Number(raw.status ?? 0),
    statusText,
    statusType,
    usedAt: raw.used_at ?? null,
    usedByUserId: raw.used_by_user_id ?? null,
    createdAt: raw.created_at ?? null,
  }
}

function readGiftCardList(payload) {
  const raw = payload?.data
  const list = Array.isArray(raw) ? raw : (Array.isArray(raw?.data) ? raw.data : null)
  if (!list || list.some(item => !item || typeof item !== 'object' || Array.isArray(item))) {
    throw new Error('礼品卡列表返回格式错误，请重试')
  }
  return { raw, list }
}

export async function fetchGiftCardTemplates({ page = 1, pageSize = 15, signal } = {}) {
  const apiUrl = buildDashboardApiUrl('gift-card/templates', [['page', page], ['per_page', pageSize]])
  const payload = await requestDashboardApi(apiUrl, { signal })
  const { raw, list } = readGiftCardList(payload)
  return {
    list: list.map(normalizeTemplate),
    pagination: {
      page: Number(raw.current_page ?? payload?.current_page ?? page),
      pageSize: Number(raw.per_page ?? payload?.per_page ?? pageSize),
      total: Number(raw.total ?? payload?.total ?? list.length),
    },
  }
}

export async function createGiftCardTemplate(formData) {
  const apiUrl = buildDashboardApiUrl('gift-card/create-template')
  return requestDashboardMutation(apiUrl, {
    name: formData.name.trim(),
    plan_id: formData.kind === 'balance' ? null : formData.planId,
    period: formData.kind === 'balance' ? null : formData.period,
    price: formData.kind === 'balance' ? giftCardBalanceCents(formData.price) : 0,
  })
}

export async function generateGiftCardCodes(templateId, count = 1) {
  const apiUrl = buildDashboardApiUrl('gift-card/generate-codes')
  return requestDashboardMutation(apiUrl, {
    template_id: templateId,
    count,
  })
}

export async function fetchGiftCardCodes(templateId, { page = 1, pageSize = 15, signal } = {}) {
  const queryEntries = [
    ['template_id', templateId],
    ['page', page],
    ['page_size', pageSize],
  ]
  const apiUrl = buildDashboardApiUrl('gift-card/codes', queryEntries)
  const payload = await requestDashboardApi(apiUrl, { signal })
  const { raw: rawData, list: listSource } = readGiftCardList(payload)

  // 兼容 total: 0 的真实零结果（旧代码用 || 会被当成 missing）
  const total = Number(rawData?.total ?? payload?.total ?? listSource.length)
  const currentPage = Number(rawData?.current_page ?? payload?.current_page ?? page)
  const perPage = Number(rawData?.per_page ?? payload?.per_page ?? pageSize)

  return {
    list: listSource.map(normalizeCode),
    pagination: {
      page: currentPage,
      pageSize: perPage,
      total: total,
    },
  }
}

export async function exportGiftCardCodes(templateId, options = {}) {
  const apiUrl = buildDashboardApiUrl('gift-card/export-codes', [['template_id', templateId]])
  const headers = getDashboardApiHeaders()
  const response = await fetch(apiUrl, {
    headers,
    signal: options.signal,
  })

  if (!response.ok) {
    await readDashboardResponse(response, () => signalAuthExpired(`blob:${response.status}`, headers['X-Admin-CSRF']))
  }

  const contentType = response.headers.get('content-type') || ''
  if (/json/i.test(contentType)) {
    const error = await response.json().catch(() => null)
    throw new Error(error?.message || '导出失败：接口未返回兑换码文件')
  }
  if (/html/i.test(contentType)) throw new Error('导出失败：服务器返回了网页，请重试')
  const blob = await response.blob()
  options.signal?.throwIfAborted()
  if (!blob.size) throw new Error('没有可导出的兑换码')
  return blob
}
