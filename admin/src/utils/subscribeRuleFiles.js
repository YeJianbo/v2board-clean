import { parse } from 'yaml'
export function referencedRuleFiles(source, origin, fallback = []) {
  let document
  try { document = parse(source) } catch { return fallback }
  const providers = document?.['rule-providers']
  if (!providers || typeof providers !== 'object') return []
  const files = new Map()
  let baseUrl
  try { baseUrl = new URL(origin) } catch { return [] }
  for (const [label, provider] of Object.entries(providers)) {
    if (!provider || typeof provider.url !== 'string') continue
    try {
      const url = new URL(provider.url, baseUrl)
      if (url.origin !== baseUrl.origin) continue
      const match = url.pathname.match(/^\/rules\/clash\/([a-zA-Z0-9_-]+)\.yaml$/)
      if (match) files.set(match[1], {key:match[1],label:`${label} · ${match[1]}.yaml`})
    } catch { /* Ignore incomplete URLs while editing. */ }
  }
  return [...files.values()]
}
