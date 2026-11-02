import { fetchManagedNodeRoutes, saveManagedNodeRoute } from './nodes'
import { chinaBlockRules } from '../utils/nodeRoutePresets'

let pending
export function ensureChinaBlockRoutes() {
  if (pending) return pending
  pending = (async () => {
    const routes = await fetchManagedNodeRoutes()
    for (const rule of chinaBlockRules) {
      if (!routes.some(route => route.action === rule.action &&
        route.match.length === 1 && route.match[0] === rule.match[0])) {
        await saveManagedNodeRoute(rule)
      }
    }
    return fetchManagedNodeRoutes()
  })().finally(() => { pending = null })
  return pending
}
