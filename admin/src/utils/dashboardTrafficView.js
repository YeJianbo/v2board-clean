export function trafficPage(rows, page, size = 31) {
  const list = Array.isArray(rows) ? rows : []
  const last = Math.max(1, Math.ceil(list.length / size))
  const current = Math.max(1, Math.min(last, Number(page) || 1))
  return { page: current, rows: list.slice((current - 1) * size, current * size) }
}

export function trafficGroupItems(data, group) {
  const items = group === 'user' ? data?.users : data?.nodes
  return Array.isArray(items) ? items : []
}

export function trafficTotalsInRange(items, dates) {
  if (!Array.isArray(dates) || dates.length !== 2) return Array.isArray(items) ? items : []
  return (Array.isArray(items) ? items : []).map(item => {
    let upload = 0, download = 0
    for (const point of item.points || []) {
      const day = point.date.slice(0, 10)
      if (day < dates[0] || day > dates[1]) continue
      upload += Number(point.upload || 0)
      download += Number(point.download || 0)
    }
    return { ...item, upload, download }
  })
}

export function filterTrafficNodes(rows, search = '', includeUnassigned = false) {
  const query = search.trim().toLocaleLowerCase()
  return (Array.isArray(rows) ? rows : []).filter(row => (row.id || includeUnassigned) && Number(row.upload) + Number(row.download) > 0
    && (!query || `${row.name || ''} ${row.key || ''}`.toLocaleLowerCase().includes(query)))
}

export function filterTrafficUsers(rows, search = '') {
  const query = search.trim().toLocaleLowerCase()
  return (Array.isArray(rows) ? rows : []).filter(row => Number(row.id) > 0 && Number(row.upload) + Number(row.download) > 0
    && (!query || `${row.name || ''} ${row.id || ''}`.toLocaleLowerCase().includes(query)))
}
