export function captureUserFilterQuery(keyword = '', conditions = []) {
  return { keyword, conditions: conditions.map(item => ({ ...item })) }
}

export function buildUserFilters(query, fields) {
  const filters = []
  if (query.keyword.trim()) filters.push({ id: 'email', value: query.keyword.trim() })
  const prefixes = { '大于': 'gt:', '晚于': 'gt:', '小于': 'lt:', '早于': 'lt:', '等于': 'eq:', '精确': 'eq:' }
  for (const condition of query.conditions) {
    if (!fields.some(field => field.id === condition.field) || condition.value === '' || condition.value == null) continue
    filters.push({ id: condition.field, value: `${prefixes[condition.operator] || ''}${condition.value}` })
  }
  return filters
}

export function userFilterLabel(filter, fields, formatBytes) {
  const field = fields.find(item => item.id === filter.id)
  const label = field?.label || filter.id
  if (['token', 'uuid'].includes(filter.id)) return `${label}：已设置`
  const match = String(filter.value).match(/^(eq|gt|lt):(.*)$/s)
  let value = match ? match[2] : String(filter.value)
  let operator = match ? { eq: '等于', gt: '大于', lt: '小于' }[match[1]] : '包含'
  if (field?.type === 'select') {
    value = field.selectOptions?.find(item => String(item.value) === value)?.label || value
  } else if (field?.type === 'date') {
    const date = new Date(Number(value) * 1000)
    if (Number.isFinite(date.getTime())) value = new Intl.DateTimeFormat('zh-CN', { dateStyle: 'short', timeStyle: 'short', hour12: false }).format(date)
    operator = match?.[1] === 'gt' ? '晚于' : match?.[1] === 'lt' ? '早于' : '等于'
  } else if (['transfer_enable', 'd'].includes(filter.id) && Number.isFinite(Number(value))) {
    value = formatBytes(Number(value))
  }
  return `${label} ${operator} ${value}`
}
