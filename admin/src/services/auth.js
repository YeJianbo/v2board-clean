import { buildCommonApiUrl } from './api'
import { withReadDeadline } from '../utils/readRequestDeadline'
import { notifyAdminAuthChange } from '../utils/authBoundary'

const AUTH_STORAGE_KEY = 'ltc-admin-auth'
let session = null
let authQueue = Promise.resolve()
let authExpiredHandler = null
let lastExpiredKey = null

export function readStoredAuth() { return session }

export function persistAuthSession(value) {
  session = value
  lastExpiredKey = null
  localStorage.removeItem(AUTH_STORAGE_KEY)
}

export function clearAuthSession() {
  session = null
  localStorage.removeItem(AUTH_STORAGE_KEY)
}

export function hasStoredAuthSession() { return Boolean(session?.sessionKey) }

export function setAuthExpiredHandler(handler) {
  authExpiredHandler = typeof handler === 'function' ? handler : null
}

export function signalAuthExpired(reason = 'unauthorized', expectedSessionKey) {
  if (arguments.length > 1 && expectedSessionKey !== session?.csrfToken) return
  if (!session || lastExpiredKey === session.sessionKey) return
  lastExpiredKey = session.sessionKey
  authExpiredHandler?.(reason)
}

function serializeAuth(operation, { signal } = {}) {
  const run = () => {
    if (signal?.aborted) throw signal.reason || new DOMException('Request cancelled', 'AbortError')
    return typeof navigator !== 'undefined' && navigator.locks
      ? navigator.locks.request('panelcore-admin-session', { ...(signal ? { signal } : {}) }, operation)
      : operation()
  }
  const result = authQueue.then(run, run)
  authQueue = result.catch(() => {})
  if (!signal) return result
  // A suspended tab or an earlier auth request must not block startup indefinitely.
  return new Promise((resolve, reject) => {
    const cancel = () => reject(signal.reason || new DOMException('Request cancelled', 'AbortError'))
    signal.addEventListener('abort', cancel, { once: true })
    if (signal.aborted) cancel()
    result.then(resolve, reject).finally(() => signal.removeEventListener('abort', cancel))
  })
}

async function requestAuth(endpoint, { method = 'GET', body, headers = {}, signal } = {}) {
  const url = new URL(buildCommonApiUrl(`passport/auth/${endpoint}`))
  if (url.origin !== window.location.origin) throw new Error('后台 Cookie 会话只支持同源 API')
  const response = await fetch(url.toString(), {
    method,
    signal,
    credentials: 'same-origin',
    cache: 'no-store',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Admin-Request': '1', ...headers },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const result = await response.json().catch(() => null)
  if (signal?.aborted) throw signal.reason || new DOMException('Request cancelled', 'AbortError')
  if (!response.ok || !result?.data) {
    const error = new Error(result?.message || '后台会话请求失败，请重试')
    error.httpStatus = response.status
    throw error
  }
  return result.data
}

function parseSession(data) {
  if (data?.is_admin !== true && data?.is_admin !== 1 && data?.is_admin !== '1') throw new Error('该账号没有管理后台访问权限')
  if (typeof data.csrf_token !== 'string' || !data.csrf_token || typeof data.session_key !== 'string' || !data.session_key) {
    throw new Error('登录接口返回了无效的会话数据')
  }
  return { isAdmin: true, csrfToken: data.csrf_token, sessionKey: data.session_key }
}

function legacyAuthorization() {
  const raw = localStorage.getItem(AUTH_STORAGE_KEY)
  if (!raw) return null
  try {
    const value = JSON.parse(raw)
    return typeof value?.authData === 'string' && value.authData ? value.authData : null
  } catch { return null }
}

export function bootstrapAuthSession() {
  return withReadDeadline(signal => serializeAuth(async () => {
    let next
    try {
      next = parseSession(await requestAuth('admin-session', { signal }))
    } catch (error) {
      if (error.httpStatus !== 401) throw error
      const authorization = legacyAuthorization()
      if (!authorization) { clearAuthSession(); return null }
      try {
        next = parseSession(await requestAuth('admin-session/migrate', {
          method: 'POST', headers: { Authorization: authorization }, signal,
        }))
      } catch (migrationError) {
        if (migrationError.httpStatus === 401) { clearAuthSession(); return null }
        throw migrationError
      }
    }
    if (signal?.aborted) throw signal.reason || new DOMException('Request cancelled', 'AbortError')
    persistAuthSession(next)
    return next
  }, { signal }), 15000)
}

async function revokeSession(value) {
  if (!value?.sessionKey) return
  try {
    await requestAuth('admin-logout', {
      method: 'POST', headers: { 'X-Admin-CSRF': value.csrfToken }, body: { session_key: value.sessionKey },
    })
  } catch (error) {
    if (error.httpStatus !== 401) throw error
  }
}

export function loginWithPassword(payload, { isCurrent = () => true } = {}) {
  return serializeAuth(async () => {
    if (!isCurrent()) throw new DOMException('登录操作已取消', 'AbortError')
    const next = parseSession(await requestAuth('admin-login', {
      method: 'POST', body: { email: payload.email, password: payload.password },
    }))
    if (!isCurrent()) {
      await revokeSession(next)
      notifyAdminAuthChange()
      throw new DOMException('登录操作已取消', 'AbortError')
    }
    persistAuthSession(next)
    notifyAdminAuthChange()
    return next
  })
}

export function logoutBrowserSession(value = session) {
  return serializeAuth(async () => {
    await revokeSession(value)
    if (session?.sessionKey === value?.sessionKey) clearAuthSession()
    if (value?.sessionKey) notifyAdminAuthChange()
  })
}
