export function normalizedRelayHost(host) {
  const text = String(host || '').trim().replace(/^\[|\]$/g, '').toLowerCase()
  if (text.includes(':')) { try { return new URL(`http://[${text}]/`).hostname.replace(/^\[|\]$/g, '') } catch { return text } }
  return text.replace(/\.$/, '')
}

export function relayLinkQuality(rule, records, now = Date.now() / 1000) {
  const host = normalizedRelayHost(rule.targetHost)
  const matches = records.filter(r => normalizedRelayHost(r.targetHost) === host &&
    (r.probeType === 'icmp' || (r.probeType === 'tcp' && Number(r.targetPort) === Number(rule.targetPort) && rule.protocols?.includes('tcp'))))
  const latest = matches.sort((a,b) => b.recordedAt - a.recordedAt || (a.probeType === 'tcp' ? -1 : 1))[0]
  if (!latest) return null
  return { ...latest, stale: now - latest.recordedAt > 900,
    failureRate: latest.sent > 0 ? Math.max(0, Math.min(100, 100 * (latest.sent - latest.received) / latest.sent)) : null }
}

export function relayLinkLabel(quality) {
  if (!quality) return '暂无入口到目标的探测数据'
  const kind = quality.probeType === 'tcp' ? 'TCP' : 'ICMP'
  const latency = quality.received > 0 && Number.isFinite(quality.latencyAvg) ? `${quality.latencyAvg.toFixed(1)} ms` : '无响应'
  const loss = quality.failureRate == null ? '' : ` · ${quality.probeType === 'tcp' ? '连接失败率' : '丢包'} ${quality.failureRate.toFixed(1)}%`
  return `${kind} ${latency}${loss}${quality.stale ? ' · 历史数据' : ''}`
}
