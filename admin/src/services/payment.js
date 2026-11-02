import {
  buildDashboardApiUrl,
  requestDashboardApi,
  requestDashboardReadPost,
  requestDashboardMutation,
} from './api'
import { createTimedRequestCache } from '../utils/timedRequestCache'
import { readStoredAuth } from './auth'

const paymentsCache = createTimedRequestCache(15_000, () => readStoredAuth()?.sessionKey)

function normalizePayment(raw) {
  return {
    id: raw.id,
    name: raw.name || '',
    payment: raw.payment || '',
    icon: raw.icon || '',
    enable: Number(raw.enable ?? 0) === 1,
    sort: raw.sort ?? 0,
    notifyDomain: raw.notify_domain || '',
    notifyUrl: raw.notify_url || '',
    handlingFeePercent: raw.handling_fee_percent ?? null,
    handlingFeeFixed: raw.handling_fee_fixed ?? null,
    uuid: raw.uuid || '',
    createdAt: raw.created_at ?? null,
    updatedAt: raw.updated_at ?? null,
  }
}

export async function fetchPayments(options = {}) {
  const list = await paymentsCache.get(async function requestPayments({ signal }) {
    const apiUrl = buildDashboardApiUrl('payment/fetch', [['summary', 1]])
    const payload = await requestDashboardApi(apiUrl, { signal })
    if (!Array.isArray(payload?.data)) throw new Error('支付方式列表格式异常，请刷新重试')
    return payload.data.map(({ config, ...payment }) => payment)
  }, options)
  return list.map(normalizePayment)
}

export async function fetchPaymentMethods(options = {}) {
  const apiUrl = buildDashboardApiUrl('payment/getPaymentMethods')
  const payload = await requestDashboardApi(apiUrl, options)
  if (!Array.isArray(payload?.data)) throw new Error('支付接口列表格式异常，请刷新重试')
  return payload.data
}

export async function fetchPaymentDetail(id, options = {}) {
  const paymentId = Number(id)
  if (!Number.isSafeInteger(paymentId) || paymentId < 1 || paymentId > 2147483647) throw new Error('支付方式 ID 无效')
  const apiUrl = buildDashboardApiUrl('payment/fetch', [['id', paymentId]])
  const payload = await requestDashboardApi(apiUrl, options)
  const raw = payload?.data
  const config = raw?.config
  if (!raw || Number(raw.id) !== paymentId || !config || typeof config !== 'object' || (Array.isArray(config) && config.length > 0)) {
    throw new Error('支付配置详情格式异常，请重新加载')
  }
  return { ...normalizePayment(raw), config: Array.isArray(config) ? {} : config }
}

export async function fetchPaymentForm(payment, id = null, options = {}) {
  const apiUrl = buildDashboardApiUrl('payment/getPaymentForm')
  const payload = await requestDashboardReadPost(apiUrl, { payment, id }, options)

  // 兼容多种响应形态
  let fields = payload?.data ?? payload

  if (fields && typeof fields === 'object' && !Array.isArray(fields)) {
    fields = Object.entries(fields).map(([key, val]) => {
      if (val && typeof val === 'object' && (val.label || val.field)) {
        return {
          ...val,
          field: val.field || key,
          label: val.label || key,
          tips: val.tips || val.description || '',
          placeholder: val.placeholder || '',
        }
      }
      return { field: key, label: key, tips: '', placeholder: String(val ?? '') }
    })
  }

  return Array.isArray(fields) ? fields : []
}

export async function savePayment(formData) {
  const apiUrl = buildDashboardApiUrl('payment/save')
  const result = await requestDashboardMutation(apiUrl, {
    id: formData.id || null,
    name: formData.name,
    icon: formData.icon || null,
    payment: formData.payment,
    config: formData.config || {},
    enable: formData.enable ? 1 : 0,
    notify_domain: formData.notifyDomain || '',
    handling_fee_percent: formData.handlingFeePercent ?? null,
    handling_fee_fixed: formData.handlingFeeFixed ?? null,
  })
  paymentsCache.clear()
  return result
}

export async function deletePayment(id) {
  const apiUrl = buildDashboardApiUrl('payment/drop')
  const result = await requestDashboardMutation(apiUrl, { id })
  paymentsCache.clear()
  return result
}

export async function togglePaymentShow(id) {
  // 注意：后端端点名是 payment/show，但行为是切换 enable 而非 show。
  // 保留旧端点以兼容部署，仅集中走标准 mutation 层（自动 401 处理）。
  const apiUrl = buildDashboardApiUrl('payment/show')
  const result = await requestDashboardMutation(apiUrl, { id })
  paymentsCache.clear()
  return result
}

export async function sortPayments(ids) {
  const apiUrl = buildDashboardApiUrl('payment/sort')
  const result = await requestDashboardMutation(apiUrl, { ids })
  paymentsCache.clear()
  return result
}
