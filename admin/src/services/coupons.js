import {
  buildDashboardApiUrl,
  requestDashboardApi,
  requestDashboardMutation,
} from './api'

const AMOUNT_COUPON_TYPE = 1
const MAX_COUPON_VALUE = 2147483647

export function couponToForm(coupon) {
  return {
    id: coupon.id,
    name: coupon.name,
    code: coupon.code,
    type: Number(coupon.type),
    value: Number(coupon.type) === AMOUNT_COUPON_TYPE ? coupon.value / 100 : coupon.value,
    show: coupon.show,
    generateCount: 1,
    limitUse: coupon.limitUse,
    initialLimitUse: coupon.limitUse,
    limitUseWithUser: coupon.limitUseWithUser,
    limitPlanIds: Array.isArray(coupon.limitPlanIds) ? coupon.limitPlanIds.map(Number) : [],
    limitPeriod: Array.isArray(coupon.limitPeriod) ? [...coupon.limitPeriod] : [],
    dateRange: coupon.startedAt && coupon.endedAt
      ? [new Date(coupon.startedAt * 1000), new Date(coupon.endedAt * 1000)]
      : null,
  }
}

export function createEmptyCouponsPagination() {
  return { page: 1, pageSize: 15, total: 0 }
}

export function createEmptyCouponsFilters() {
  return { keyword: '' }
}

function normalizeCoupon(raw) {
  return {
    id: raw.id,
    name: raw.name || '',
    code: raw.code || '',
    type: Number(raw.type ?? 1),
    value: raw.value ?? 0,
    limitUse: raw.limit_use ?? null,
    limitUseWithUser: raw.limit_use_with_user ?? null,
    limitPlanIds: Array.isArray(raw.limit_plan_ids) ? raw.limit_plan_ids : [],
    limitPeriod: Array.isArray(raw.limit_period) ? raw.limit_period : [],
    startedAt: raw.started_at ?? null,
    endedAt: raw.ended_at ?? null,
    show: raw.show === true || Number(raw.show) === 1,
    createdAt: raw.created_at ?? null,
    updatedAt: raw.updated_at ?? null,
  }
}

function readCouponList(payload) {
  const rawData = payload?.data
  const list = Array.isArray(rawData?.data) ? rawData.data : (Array.isArray(rawData) ? rawData : null)
  if (!list || list.some(item => !item || typeof item !== 'object' || Array.isArray(item))) {
    throw new Error('优惠券列表返回格式错误，请重试')
  }
  return { rawData, list }
}

export async function fetchCouponById(id, options = {}) {
  const couponId = Number(id || 0)
  if (!Number.isSafeInteger(couponId) || couponId <= 0) return null

  const url = buildDashboardApiUrl('coupon/fetch', [
    ['current', 1],
    ['pageSize', 1],
    ['filter[0][id]', 'id'],
    // Array filters use SQL IN, preserving an indexed exact ID lookup on older panels too.
    ['filter[0][value][0]', couponId],
  ])
  const { list } = readCouponList(await requestDashboardApi(url, { signal: options.signal }))
  if (!list.length) return null
  if (list.length !== 1 || Number(list[0].id) !== couponId) throw new Error('优惠券详情返回不匹配，请重试')
  return normalizeCoupon(list[0])
}

export async function fetchManagedCoupons({ page = 1, pageSize = 15, filters = {}, signal } = {}) {
  const queryEntries = [
    ['current', page],
    ['pageSize', pageSize],
  ]
  if (filters.keyword) {
    queryEntries.push(['keyword', filters.keyword])
  }
  const apiUrl = buildDashboardApiUrl('coupon/fetch', queryEntries)
  const payload = await requestDashboardApi(apiUrl, { signal })
  const { rawData, list: listSource } = readCouponList(payload)

  const total = Number(rawData?.total ?? payload?.total ?? listSource.length)
  const currentPage = Number(rawData?.current_page ?? payload?.current_page ?? page)
  const perPage = Number(rawData?.per_page ?? payload?.per_page ?? pageSize)

  return {
    list: listSource.map(normalizeCoupon),
    pagination: {
      page: currentPage,
      pageSize: perPage,
      total: total,
    },
  }
}

export function normalizeCouponValueForApi(type, value) {
  const numberValue = Number(value)
  const isAmount = Number(type) === AMOUNT_COUPON_TYPE
  const storedValue = isAmount ? Math.round(numberValue * 100) : numberValue
  if (!['number', 'string'].includes(typeof value) || String(value).trim() === '' || !Number.isFinite(numberValue) || numberValue <= 0) {
    throw new Error('请输入有效的优惠金额或比例')
  }
  if (!Number.isSafeInteger(storedValue) || storedValue < 1 || storedValue > MAX_COUPON_VALUE) {
    throw new Error(isAmount ? '优惠金额须为 0.01-21474836.47 元' : '比例优惠须为 1-2147483647 之间的整数')
  }
  return storedValue
}

function getResponseErrorMessage(result, fallback) {
  if (typeof result?.message === 'string' && result.message) {
    return result.message
  }

  const firstError = Object.values(result?.errors || {})
    .flat()
    .find(Boolean)

  return firstError || fallback
}

export async function generateCoupons(formData) {
  const apiUrl = buildDashboardApiUrl('coupon/generate')
  const body = {
    name: formData.name,
    type: formData.type,
    value: normalizeCouponValueForApi(formData.type, formData.value),
    limit_use_with_user: formData.limitUseWithUser == null || formData.limitUseWithUser === '' ? null : formData.limitUseWithUser,
    limit_plan_ids: formData.limitPlanIds || [],
    limit_period: formData.limitPeriod || [],
    started_at: formData.startedAt || null,
    ended_at: formData.endedAt || null,
  }
  if (!formData.id || Object.prototype.hasOwnProperty.call(formData, 'limitUse')) {
    body.limit_use = formData.limitUse == null || formData.limitUse === '' ? null : formData.limitUse
  }
  if (formData.id) {
    body.id = formData.id
    // Retain the existing visibility for compatibility with older backends.
    body.show = formData.show === false || formData.show === 0 ? 0 : 1
  }
  if (formData.code && !formData.generateCount) {
    body.code = formData.code
  }
  if (!formData.id && formData.generateCount) {
    body.generate_count = formData.generateCount
  }
  return requestDashboardMutation(apiUrl, body)
}

export async function deleteCoupon(id) {
  const apiUrl = buildDashboardApiUrl('coupon/drop')
  return requestDashboardMutation(apiUrl, { id })
}

export async function toggleCouponShow(id) {
  const apiUrl = buildDashboardApiUrl('coupon/show')
  return requestDashboardMutation(apiUrl, { id })
}
