export const relayProtocols = {
  openai: 'OpenAI Chat', responses: 'OpenAI Responses', anthropic: 'Anthropic Messages', gemini: 'Gemini', responses_ws: 'Responses WebSocket',
}
export const qualityStates = {
  untested: '未检测', healthy: '正常', watch: '待复测', below_threshold: '低于阈值', degraded: '疑似质量下降', unavailable: '检测未完成',
}
export const qualityAlert = state => ['degraded', 'below_threshold', 'watch', 'unavailable'].includes(state)
export const relayCost = value => value == null ? '未确认' : `$${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 10 })}`
export const relayNeedsAttention = row => Number(row.remaining) <= 0 || Number(row.uncertain) > 0 || Number(row.cost_unconfirmed) > 0
export const relayPeriodCost = row => Number(row.requests) > 0 && Number(row.cost_unconfirmed) >= Number(row.requests) ? '待确认' : relayCost(row.cost ?? '0')
export function filterRelayKeys(keys, search, status = 'all') {
  const query = search.trim().toLowerCase()
  return keys.filter(row => (!query || `${row.machine_name || ''} ${row.machine_id}`.toLowerCase().includes(query))
    && (status === 'all' || (status === 'enabled' && !!row.enabled) || (status === 'disabled' && !row.enabled) || (status === 'attention' && relayNeedsAttention(row))))
}
export const relayCostStates = { confirmed: '已确认', estimated: '缓存明细不完整，费用估算', unknown: '用量未确认', unpriced: '未配置单价', inconsistent: '缓存计数异常' }
