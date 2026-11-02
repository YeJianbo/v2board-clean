function host(value) {
  const text = String(value || '').trim().replace(/^\[|\]$/g, '').toLowerCase()
  if (text.includes(':')) { try { return new URL(`http://[${text}]/`).hostname.replace(/^\[|\]$/g, '') } catch {} }
  return text.replace(/\.$/, '')
}

export function relayConnectionQuality(rule) {
  const h = rule?.health
  if (!h || h.stale || h.targetStale) return { tone: 'info', label: '连接质量未知或已过期' }
  if (h.targetHost && (host(h.targetHost) !== host(rule.targetHost) || Number(h.targetPort) !== Number(rule.targetPort))) return { tone: 'info', label: '目标已改变，等待新数据' }
  if (h.targetStatus === 'unreachable') return { tone: 'danger', label: '入口 → 落地：连接失败' }
  if (h.status !== 'running' || !h.listenOk) return { tone: 'info', label: '转发未就绪，暂无链路质量' }
  if (h.targetStatus !== 'reachable') return { tone: 'info', label: '尚无入口到落地的连接结果' }
  if (h.targetLatencyMs == null || !Number.isFinite(Number(h.targetLatencyMs)) || Number(h.targetLatencyMs) < 0) return { tone: 'info', label: '目标可达，尚未上报延迟' }
  const ms = Number(h.targetLatencyMs)
  return { tone: ms < 100 ? 'success' : ms < 250 ? 'warning' : 'danger', label: `入口 → 落地 ${ms.toFixed(0)} ms · ${ms < 100 ? '良好' : ms < 250 ? '一般' : '较慢'}` }
}

export function relayActivitySeries(rule) {
  const h = rule?.health
  if (!h?.targetHost || host(h.targetHost) !== host(rule.targetHost) || Number(h.targetPort) !== Number(rule.targetPort)) return []
  const points = (Array.isArray(h.activityHistory) ? h.activityHistory : []).filter(p =>
    Number(p.bytes_delta) > 0 && Number(p.recorded_at) > 0 && p.latency_ms != null && Number.isFinite(Number(p.latency_ms)) && Number(p.latency_ms) >= 0
  ).map(p => ({ timestamp: Number(p.recorded_at), value: Number(p.latency_ms) })).sort((a,b) => a.timestamp-b.timestamp)
  if (!points.length) return []
  return [{ key: 'relay', name: '连接延迟', color: '#72996b', points }]
}
