let channel

function sessionChannel() {
  if (typeof window === 'undefined' || typeof window.BroadcastChannel !== 'function') return null
  try {
    channel ??= new window.BroadcastChannel('panelcore-admin-session')
    return channel
  } catch { return null }
}

export function notifyAdminAuthChange() {
  try { sessionChannel()?.postMessage('session-changed') } catch { /* Authentication does not depend on notifications. */ }
}

export function onExternalAdminAuthChange(handler) {
  const target = sessionChannel()
  const listener = event => { if (event.data === 'session-changed') handler() }
  target?.addEventListener('message', listener)
  return () => target?.removeEventListener('message', listener)
}

export function bindAdminAuthBoundary(router, target = window) {
  const stop = onExternalAdminAuthChange(() => {
    if (router.currentRoute.value?.name === 'login') {
      target.location.replace(router.resolve({ name: 'dashboard' }).href)
    } else target.location.reload()
  })
  const hide = () => stop()
  const show = event => { if (event.persisted) target.location.reload() }
  target.addEventListener('pagehide', hide)
  target.addEventListener('pageshow', show)
  return () => {
    stop()
    target.removeEventListener('pagehide', hide)
    target.removeEventListener('pageshow', show)
  }
}

export function requiresAdminReauthentication(router, location = window.location) {
  return location.pathname === router.resolve({ name: 'login' }).href
    && new URL(location.href).searchParams.get('reason') === 'expired'
}

export function leaveAdminSession(router, { expired = false, location = window.location } = {}) {
  const current = router.currentRoute.value
  const query = expired ? { reason: 'expired' } : {}
  if (expired && current?.name !== 'login') query.redirect = current.fullPath
  // A new document discards account-scoped stores, sensitive drafts and late callbacks together.
  location.replace(router.resolve({ name: 'login', query }).href)
}
