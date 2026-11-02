function parseLogValue(value) {
  if (typeof value !== 'string') return value ?? null
  try {
    const parsed = JSON.parse(value)
    if (parsed && typeof parsed === 'object' && Object.keys(parsed).length === 1) {
      if (parsed.__redacted === 'size_limit') return '日志正文超过安全读取上限，已隐藏；原始记录未删除。'
      if (parsed.__redacted === 'invalid_json') return '日志正文格式不完整，无法安全脱敏，已隐藏；原始记录未删除。'
    }
    return parsed
  } catch { return value }
}

export function normalizeSystemLog(item) {
  return {
    id: item.id,
    title: item.title || '',
    level: String(item.level || 'info').toLowerCase(),
    method: item.method || '--',
    uri: item.uri || '',
    ip: item.ip || '--',
    requestData: parseLogValue(item.data),
    context: parseLogValue(item.context),
    createdAt: item.created_at || 0,
  }
}
