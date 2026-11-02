import { buildCommonApiUrl, requestDashboardApi } from './api'
import { hasFreshMachineTraffic } from '../utils/machineTrafficSample.js'

export async function fetchManagedMachineStatuses(options = {}) {
  const payload = await requestDashboardApi(buildCommonApiUrl('admin/machine/status'), { signal: options.signal, timeoutMs: 15000 })
  if (!Array.isArray(payload?.data)) throw new Error('机器状态返回格式错误')
  return payload.data
}

export function summarizeMachineStatuses(machines) {
  const online = machines.filter(machine => Number(machine.is_online) === 1)
  const now = Date.now() / 1000
  function sumRates(keys) {
    const rates = online.map(machine => {
      const status = machine.status_data || {}
      if (!hasFreshMachineTraffic(status, now)) return NaN
      const key = keys.find(key => status[key] !== null && status[key] !== undefined && status[key] !== '')
      return key ? Number(status[key]) : NaN
    }).filter(value => Number.isFinite(value) && value >= 0)
    return rates.length || !online.length ? rates.reduce((sum, rate) => sum + rate, 0) : null
  }
  function averagePercent(keys) {
    const values = online.map(machine => {
      const status = machine.status_data || {}
      const key = keys.find(key => status[key] !== null && status[key] !== undefined && status[key] !== '')
      return key ? Number(status[key]) : NaN
    }).filter(value => Number.isFinite(value) && value >= 0 && value <= 100)
    return values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : null
  }
  const failedMachines = online.flatMap(machine => {
    const status = machine.status_data || {}
    const health = [...(Array.isArray(status.node_health) ? status.node_health : []), ...(Array.isArray(status.gost_rule_health) ? status.gost_rule_health : [])]
    const failed = health.find(record => record.status === 'failed' && Number(record.checked_at) > now - 180)
    if (status.gost_status !== 'failed' && !failed) return []
    return [{ id: machine.id, name: status.hostname || `机器 #${machine.id}`, message: failed?.message || status.gost_error || '服务启动异常' }]
  })
  return {
    total: machines.length,
    online: online.length,
    offline: machines.length - online.length,
    upload: sumRates(['net_out_rate', 'tx_rate', 'upload_rate', 'up_rate']),
    download: sumRates(['net_in_rate', 'rx_rate', 'download_rate', 'down_rate']),
    cpu: averagePercent(['cpu', 'cpu_usage', 'cpu_percent']),
    memory: averagePercent(['mem', 'memory', 'memory_usage', 'ram']),
    failedServices: failedMachines.length,
    failedMachines,
    trafficAlerts: machines.filter(machine => machine.traffic_usage?.alerted === true || Number(machine.traffic_usage?.alerted) === 1).length,
  }
}
