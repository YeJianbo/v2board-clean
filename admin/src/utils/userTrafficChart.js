export function trafficNodeColor(key) {
  let hash = 0
  for (const char of String(key)) hash = (hash * 31 + char.charCodeAt(0)) >>> 0
  return `hsl(${Math.round((hash * 137.508) % 360)} 52% 43%)`
}

export function userTrafficChartSeries(series, selected = ['ALL']) {
  const all = selected.includes('ALL') || !selected.length
  const selectedKeys = all ? null : new Set(selected)
  const nodes = series.filter(item => item.key !== 'total' && (all || selectedKeys.has(item.key)))
  return nodes.map(item => ({
    ...item,
    color: trafficNodeColor(item.key),
    points: (item.points || []).map(point => ({ ...point, total: Number(point.cost ?? point.total ?? 0) })),
  })).filter(item => item.points.some(point => point.total > 0))
}

export function stackedTrafficValues(series) {
  const totals = []
  return series.map(item => (item.points || []).map((point, index) => {
    const value = Math.max(0, Number(point.total) || 0)
    const bottom = totals[index] || 0
    totals[index] = bottom + value
    return { value, bottom, top: totals[index] }
  }))
}
