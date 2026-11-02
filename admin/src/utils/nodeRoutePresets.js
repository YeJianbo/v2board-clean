export const chinaBlockRules = [
  { remarks: '禁止回国 · 域名', action: 'block', match: ['geosite:cn'], action_value: '' },
  { remarks: '禁止回国 · IP', action: 'block_ip', match: ['geoip:cn'], action_value: '' },
]

export function chinaBlockRouteIds(routes) {
  return chinaBlockRules.map(rule => routes.find(route =>
    route.action === rule.action && Array.isArray(route.match) &&
    route.match.length === 1 && route.match[0] === rule.match[0]
  )?.id).filter(id => id !== undefined).map(String)
}

export function applyChinaBlockRoutes(selected, presetIds, enabled) {
  const rest = selected.map(String).filter(id => !presetIds.includes(id))
  return enabled ? [...presetIds, ...rest] : rest
}
