import { buildDashboardApiUrl, requestDashboardApi } from './api'

export const dashboardDetailTitles = Object.freeze({
  users: '总用户',
  'online-users': '在线用户',
  'online-devices': '在线设备',
  'online-nodes': '在线节点',
})

export async function fetchDashboardDetails({ metric, page = 1, pageSize = 10, search = '', signal }) {
  if (!Object.hasOwn(dashboardDetailTitles, metric)) throw new Error('不支持的统计明细')
  const payload = await requestDashboardApi(buildDashboardApiUrl('stat/getDetails', [
    ['metric', metric], ['page', page], ['page_size', pageSize], ['search', search.trim()],
  ]), { signal })
  if (!Array.isArray(payload?.data) || !Number.isFinite(Number(payload?.total))) {
    throw new Error('统计明细返回格式错误')
  }
  return {
    rows: payload.data,
    total: Number(payload.total),
    page: Number(payload.current_page ?? page),
    pageSize: Number(payload.per_page ?? pageSize),
    meta: payload.meta || {},
  }
}
