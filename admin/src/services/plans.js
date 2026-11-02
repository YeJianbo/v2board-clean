import {
  buildSecureV2ApiUrl,
  requestDashboardApi,
  requestDashboardMutation,
} from './api'
import { createTimedRequestCache } from '../utils/timedRequestCache'
import { readStoredAuth } from './auth'

const managedPlansCache = createTimedRequestCache(15_000, () => readStoredAuth()?.sessionKey)
const managedPlanOptionsCache = createTimedRequestCache(15_000, () => readStoredAuth()?.sessionKey)

export function createEmptyManagedPlans() {
  return []
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

const PERIOD_LABELS = {
  month_price: '月付',
  quarter_price: '季付',
  half_year_price: '半年付',
  year_price: '年付',
  two_year_price: '两年付',
  three_year_price: '三年付',
  onetime_price: '一次性',
  reset_price: '重置包',
}

function normalizePlan(plan) {
  const prices = {}
  const priceTexts = []

  Object.keys(PERIOD_LABELS).forEach(function mapPeriod(priceKey) {
    const priceValue = plan?.[priceKey]

    if (priceValue !== null && priceValue !== undefined && priceValue !== '' && Number.isFinite(Number(priceValue)) && Number(priceValue) >= 0) {
      const amount = Number(priceValue) / 100
      prices[priceKey] = amount
      priceTexts.push(`${PERIOD_LABELS[priceKey]}: ¥${amount.toFixed(2)}`)
    } else {
      prices[priceKey] = null
    }
  })

  const transferEnable = Number(plan?.transfer_enable || 0)
  const groupName = plan?.group?.name || '--'

  return {
    id: Number(plan?.id || 0),
    name: String(plan?.name || '--'),
    groupId: plan?.group_id != null ? String(plan.group_id) : null,
    groupName,
    transferEnable: transferEnable,
    transferEnableText: formatBytes(transferEnable * 1073741824),
    transferEnableGB: transferEnable,
    speedLimit: plan?.speed_limit ?? null,
    deviceLimit: plan?.device_limit ?? null,
    prices,
    priceTexts,
    show: plan?.show === true || plan?.show === 'true' || Number(plan?.show) === 1,
    renew: plan?.renew === true || plan?.renew === 'true' || Number(plan?.renew) === 1,
    resetTrafficMethod: plan?.reset_traffic_method ?? -1,
    sort: plan?.sort || null,
    content: plan?.content || '',
    usersCount: Number(plan?.users_count || 0),
    activeUsersCount: Number(plan?.active_users_count || 0),
    capacityLimit: plan?.capacity_limit ?? null,
    forceUpdate: false,
  }
}

function readPlanList(payload) {
  const rawData = payload?.data
  const list = Array.isArray(rawData?.data) ? rawData.data : rawData
  if (!Array.isArray(list) || list.some(plan => !plan || !Number.isSafeInteger(Number(plan.id)) || Number(plan.id) <= 0)) {
    throw new Error('套餐列表数据格式异常，请刷新重试')
  }
  return list
}

export async function fetchManagedPlanOptions(options = {}) {
  const list = await managedPlanOptionsCache.get(async function requestPlanOptions({ signal } = {}) {
    const apiUrl = buildSecureV2ApiUrl('plan/fetch', [['options', 1]])
    const payload = await requestDashboardApi(apiUrl, { signal })
    return readPlanList(payload).map(plan => {
      const { id, name, prices } = normalizePlan(plan)
      return { id, name, prices }
    })
  }, options)
  return list.map(plan => ({ ...plan, prices: { ...plan.prices } }))
}

export async function fetchManagedPlans(options = {}) {
  const list = await managedPlansCache.get(async function requestPlans({ signal } = {}) {
    const apiUrl = buildSecureV2ApiUrl('plan/fetch')
    const payload = await requestDashboardApi(apiUrl, { signal })
    return readPlanList(payload)
  }, options)

  return list.map(function mapPlan(plan) {
    return normalizePlan(plan)
  })
}

export async function saveManagedPlan(data) {
  const apiUrl = buildSecureV2ApiUrl('plan/save')
  const rawCapacity = data.capacityLimit
  const capacityLimit = rawCapacity == null || (typeof rawCapacity === 'string' && rawCapacity.trim() === '')
    ? null : Number(rawCapacity)
  if (capacityLimit !== null && (!Number.isInteger(capacityLimit) || capacityLimit < 0 || capacityLimit > 2147483647)) {
    throw new Error('容量限制须为 0 至 2147483647 的整数，留空表示不限制')
  }

  const requestBody = {
    name: String(data.name || '').trim(),
    group_id: data.groupId != null ? Number(data.groupId) : null,
    transfer_enable: Number(data.transferEnableGB || 0),
    speed_limit: data.speedLimit == null || data.speedLimit === '' ? null : Number(data.speedLimit),
    device_limit: data.deviceLimit == null || data.deviceLimit === '' ? null : Number(data.deviceLimit),
    content: data.content || null,
    reset_traffic_method: data.resetTrafficMethod === -1 ? null : (data.resetTrafficMethod ?? null),
    capacity_limit: capacityLimit,
    show: data.show ? 1 : 0,
    renew: data.renew ? 1 : 0,
  }

  Object.keys(PERIOD_LABELS).forEach(function mapPeriod(priceKey) {
    const value = data.prices?.[priceKey]
    if (value === null || value === undefined || value === '') {
      requestBody[priceKey] = null
      return
    }
    const amount = Number(value)
    const cents = Math.round(amount * 100)
    if (!Number.isFinite(amount) || amount < 0 || !Number.isSafeInteger(cents) || cents > 2147483647) {
      throw new Error(`${PERIOD_LABELS[priceKey]}价格须为 0 至 21474836.47 元的有效金额`)
    }
    requestBody[priceKey] = cents
  })

  if (data.id) {
    requestBody.id = Number(data.id)
  }

  if (data.forceUpdate) {
    requestBody.force_update = 1
  }

  const result = await requestDashboardMutation(apiUrl, requestBody)
  managedPlansCache.clear()
  managedPlanOptionsCache.clear()
  return result
}

export async function deleteManagedPlan(id) {
  const apiUrl = buildSecureV2ApiUrl('plan/drop')
  const result = await requestDashboardMutation(apiUrl, { id: Number(id) })
  managedPlansCache.clear()
  managedPlanOptionsCache.clear()
  return result
}

export async function updateManagedPlan(id, updateData) {
  const apiUrl = buildSecureV2ApiUrl('plan/update')
  const body = { id: Number(id) }

  if (updateData.show !== undefined) {
    body.show = updateData.show ? 1 : 0
  }

  if (updateData.renew !== undefined) {
    body.renew = updateData.renew ? 1 : 0
  }

  const result = await requestDashboardMutation(apiUrl, body)
  managedPlansCache.clear()
  managedPlanOptionsCache.clear()
  return result
}

export async function sortManagedPlans(ids) {
  const apiUrl = buildSecureV2ApiUrl('plan/sort')
  const result = await requestDashboardMutation(apiUrl, {
    ids: ids.map(function mapId(id) {
      return Number(id)
    }),
  })
  managedPlansCache.clear()
  managedPlanOptionsCache.clear()
  return result
}

export { PERIOD_LABELS }
